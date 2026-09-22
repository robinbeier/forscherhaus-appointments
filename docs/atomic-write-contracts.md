# Atomic write contracts

This bounded inventory covers appointment, customer, service, generated-buffer,
and adjacent account writes. Use it with [the lock hierarchy](database-lock-order.md).
Function names are the source references; this is not a repository-wide audit.

## Ownership and errors

The outer business operation owns the final commit and rollback. Standalone
model operations finish their own transaction before returning success. When a
model joins an existing transaction, its successful return is not a durable
commit: the caller must propagate any exception, roll back the whole business
operation, and send effects only after the outer commit succeeds.

CodeIgniter transaction nesting only tracks depth; it provides no savepoints
(`system/database/DB_driver.php`, `trans_begin/commit/rollback`). Catching an inner
failure and then committing the outer transaction is unsupported. Never use a
nested rollback as proof that a partial operation was independently undone.
Controller exceptions serialize errors through `json_exception`; model exceptions
must reach the transaction owner first. There is no automatic retry after an
uncertain commit result.

## Entry points and dependent writes

| Entry and request classification | Authority and current-state decision | Locks, mutation, owner and effects |
| --- | --- | --- |
| `Calendar::save_appointment`: create or edit based on appointment ID; optional customer write | Provider/customer access is checked before the transaction. Editing locks current and requested parents and the appointment, then rechecks authority and parent drift before any mutation. Customer/appointment add/edit permissions and field allowlists still apply. | Calendar owns the transaction around customer save, appointment save and generated buffers. Begin, transaction status and commit must succeed; any failure rolls back before the success response. |
| `Booking::register`: public create or reschedule | Booking/reschedule authority and availability are checked against locked targets through `Reschedule_authority` before mutation. | The controller owns customer, consent, appointment and buffer writes. Model transactions join it; the controller checks commit and rolls back errors before returning. |
| `Appointments_api_v1::store/update/destroy`: authenticated API CRUD | Constructor API authentication and strict DTO decoding; public booking authority is a separate route contract. Sparse update validation runs inside `Appointments_model::update_api()`, first against the discovered candidate and then against the final locked rebase. | Create uses `Appointments_model::save`. Sparse update discovers and locks current/requested user parents, service parents, and the target in canonical order, revalidates provider/customer roles, applies only supplied fields, and couples any buffer replacement to the same transaction. Static invalid relationships/scalars return 400, a vanished target returns 404, relevant drift returns 409, and database failures return 500. Delete locks the appointment and removes its buffers before the parent. |
| `Customers::store/update/destroy` and `Customers_api_v1` | Backoffice checks customer add/edit/delete permissions and access for update/delete; API authenticates. Model updates/deletes retain role-scoped conditions. | Customer insert/update write a single `users` row and need no dependent follow-up. In booking/calendar they participate in the outer transaction. `Customers_model::delete` owns or nests a transaction around parent locks, buffer cleanup and role-scoped deletion, checks affected rows and commit, and propagates errors. |
| `Services::store` / API store | Backoffice add permission or API authentication, DTO fields and validation. | New service insert is one row and has no dependent appointment buffers. |
| `Services::update` / API update | Backoffice edit permission or API authentication; the original buffer values are passed as expectations, not an authoritative change flag. | Both call `Services_model::save` directly. Its update owns a transaction when none exists. Buffer changes lock provider users, service and appointment parents; current locked buffer values and provider membership are checked before writing. Service and regenerated buffers commit together. No separate caller resync is required or permitted by the contract. |
| `Services_model::delete` | Route permission/authentication precedes this operation. | Locks service and ordinary appointment parents, removes generated children and deletes the service inside one transaction; checked commit and propagated errors. |
| `Appointments_model::sync_service_buffer_unavailabilities` | Dependent internal model work, not a separate user authorization endpoint. | Opens/nests a transaction and locks provider/service/appointment parents before replacing children. Service save calls it before committing. Direct calls finish their own transaction. |
| `Account::save` -> `Users_model::save` | POST-only, user-settings edit permission, session-bound user ID and field allowlist. | User row and all settings form one model operation. Standalone save owns begin/commit/rollback; an outer caller retains ownership when present. A settings failure propagates. Account session updates follow successful save. |
| `Secretaries::store/update` and `Secretaries_api_v1::store/update` -> `Secretaries_model::save` | Backoffice users permission or API authentication; existing targets must remain secretary users and assignments must reference existing providers. Integer IDs and the UI's positive decimal strings are normalized as a set; malformed or out-of-range IDs fail before mutation. | The model locks the secretary and requested provider users in ascending order before user/settings/assignment writes. Standalone save owns the transaction; an outer caller retains it. Settings and assignment writes, transaction status and commit are checked. The public `set_provider_ids` operation has the same standalone/outer ownership and parent validation for direct use. |

### Admin account persistence

`Admins::store/update`, `Admins_api_v1::store/update` and CLI `Instance::seed` share
`Admins_model::save`. Existing route permissions, authentication and field filters
remain responsible for authority. Save couples the user and its settings in one
owned transaction, or joins an existing outer transaction without finishing it.
Settings-row initialization, transaction status and owned commit are checked;
exceptions propagate to the owner. This does not make the entire CLI seed atomic.
`AdminsModelAtomicWriteTest` covers ordinary synthetic writes and dependent-failure
rollback; HTTP behavior and complete concurrent schedules remain separate evidence.

### API settings batches

`Api_settings::save` checks system-settings edit permission and POST before
handing the complete batch to `Settings_model::save_batch`. Stateless payload
validation precedes writes; target IDs are resolved afresh by name in input
order inside the transaction. Repeated names, supplied-ID fallback and color
normalization retain their existing semantics. Standalone batches check begin,
transaction status and commit and roll back failures; joined batches retain the
outer owner's rollback obligation described above. Empty batches are no-ops.
This does not make other settings controllers atomic or serialize concurrent
name upserts. Local model regressions prove ordinary rollback/ownership cases;
controller doubles separately prove the batch handoff, not real HTTP behavior.

### Backoffice settings batches

Legal, LDAP, Matomo, Google Analytics, Business and Booking settings use the same
ordered `Settings_model::save_batch` contract. Business and Booking retain their
`only(id, name, value)` then `optional()` field preparation before the single
batch handoff. No new setting-name allowlist is introduced. The shared model
regressions prove its database rollback behavior; controller doubles separately
prove the complete handoff, preserved field filtering and failure response.

General settings retains its different existing semantics: it resolves IDs and
validates every record before starting writes. Prepared records are then saved in
order without resolving names again. This preserves initially absent duplicate
names as separate prepared inserts and preserves pre-write ID selection across
rename/order combinations. Its local transaction checks owned begin, status and
owned commit, and rolls back only transactions it owns. An empty batch performs
no transaction work.

General's real synthetic database regressions cover rollback after a later actual
write, full row restoration, caller-owned transaction rollback including prior
caller writes, prepared-name/ID semantics and validation before any save call.
The controller retains its existing JSON error response rather than rethrowing to
a PHP caller. A caller with an outer transaction retains ownership and must detect
the failed response and roll back; joined partial writes are not independently
undone. These checks establish no concurrent upsert, actual HTTP/CSRF or production
verification.

### Global working plan application

`Business_settings::apply_global_working_plan` requires system-settings edit
permission followed by POST before request or provider access. It applies the
current editor payload to the server-selected providers; it does not save the
company working plan first. Existing request normalization, descending key sorting
and empty-plan `{}` encoding remain unchanged. An empty provider list succeeds
without transaction work.

The provider write loop uses a checked owned or joined transaction and changes
only `working_plan`. Unchanged values may legitimately affect zero rows. Owned
failures roll back before the existing JSON error response; an outer caller retains
transaction ownership and must detect the error response and roll back itself.
Success is emitted only after an owned commit succeeds. Provider selection precedes
the transaction; this is not a claim that concurrently added providers are included.

The controller subprocess tests use framework/DTO/model/transaction doubles. The
separate synthetic database tests create their own providers, use real model writes
and verify rollback after a later write, full settings snapshots, idempotent
application and caller-owned rollback including an earlier caller change. Their
provider query is restricted to owned IDs, so they do not prove global target
selection or real HTTP/CSRF/production behavior.

## Narrow updates and drift

`Services_model::update` locks only the service for a buffer-neutral update.
Omitted buffer fields retain current locked values. A requested buffer change
requires expected values; stale expectations fail before the service write.
When expected/requested values indicate a buffer change, provider/service/appointment
locks are acquired in the canonical order, then current values decide whether
regeneration is required. A caller-supplied boolean cannot skip it.

Appointment writes keep buffer creation, replacement and removal inside the
model operation. Calendar/booking own their broader authority and multi-model
transactions. Do not replace their locked authority checks with a stale full
record.

Authenticated appointment PUT is deliberately sparse and has no ETag contract.
Its pre-lock candidate must be valid. After the canonical parent and appointment
locks, omitted values come from the locked row. A concurrent change to an omitted
field is retained when the rebased result remains valid; requested-field, parent,
role, or invalid-rebase drift returns 409 without mutation.

## Evidence and limits

- `ServicesModelUpdateTest`: standalone updates, omitted values, normalization,
  missing expectations and stale expected values with no unrelated persisted change.
- `ServicesModelLockOrderTest`: narrow neutral path, ordered parent locks, drift
  before mutation and standalone commit after synchronization.
- `AppointmentsModelBufferBlockTest`: real buffer generation and injected failure
  after regeneration, proving the service and dependent rows roll back together.
- `AppointmentsModelLockOrderTest`: ordered appointment/buffer mutation and commit
  failure propagation for insert, update and delete.
- `CalendarAtomicSaveTest`: customer rollback when appointment save fails, and
  failed transaction start/commit suppressing the success response.
- `UsersModelAtomicWriteTest`: coupled user/settings success, failure rollback
  and explicit outer ownership.
- `SecretariesModelAtomicWriteTest`: coupled secretary/settings/assignment writes,
  ordinary string-ID and empty-assignment behavior, rejected relationships,
  failure rollback and explicit outer ownership. Model-level checks do not
  establish HTTP authentication or all concurrent schedules.
- Existing `TwoConnectionWriteContractTest` and authorization suites cover their
  documented current-state checks; see [the harness scope](two-connection-test-harness.md).

Single-row customer save is atomic at the SQL mutation boundary; this inventory
does not claim that its email read-before-write uniqueness check serializes
concurrent inserts. Application email is not a booking side effect. Nested
composition requires the outer rollback contract above. These boundaries must
not be misrepresented as broader concurrency guarantees.

## Staff deletion

`Admins_model::delete` and `Secretaries_model::delete` scope the actual deletion
to the expected role and require one affected user row. Database failure or a
missing/wrong-role target propagates as an exception; backoffice destroy delegates
to this model boundary without an unused, role-neutral preliminary read. Existing
route permissions and API authentication remain unchanged.

Admin deletion owns or joins a transaction. A current locking read selects all
Admin user IDs in ascending order, validates the target and preserves the existing
at-least-one-Admin rule before deletion. Status and owned commit are checked;
outer owners must roll back exceptions. Secretary deletion is one checked InnoDB
statement with existing settings/relation cascades, not a new multi-step workflow.
The lock contract covers these delete operations; it does not establish all possible
role-changing callers or concurrent schedules. `StaffModelDeleteTest` provides
ordinary model/fixture evidence and a synthetic last-Admin guard test.
