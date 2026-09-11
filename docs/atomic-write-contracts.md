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
| `Calendar::save_appointment`: create or edit based on appointment ID; optional customer write | Provider/customer access is checked before the transaction. Editing locks current and requested parents and the appointment, then rechecks authority and parent drift before any mutation. Customer/appointment add/edit permissions and field allowlists still apply. | Calendar owns the transaction around customer save, appointment save and generated buffers. Begin, transaction status and commit must succeed. Any failure rolls back; notifications and the success response follow the outer commit. |
| `Booking::register`: public create or reschedule | Booking/reschedule authority and availability are checked against locked targets through `Reschedule_authority` before mutation. | The controller owns customer, consent, appointment and buffer writes. Model transactions join it. The controller checks commit and rolls back errors; notifications run after commit. |
| `Appointments_api_v1::store/update/destroy`: authenticated API CRUD | Constructor API authentication, DTO decoding and model validation; public booking authority is a separate route contract. | `Appointments_model::save` dispatches to insert/update; these lock foreign-key parents and couple appointment and buffer changes. Delete locks the appointment and removes its buffers before the parent. Standalone model commits are checked before API notifications. |
| `Customers::store/update/destroy` and `Customers_api_v1` | Backoffice checks customer add/edit/delete permissions and access for update/delete; API authenticates. Model updates/deletes retain role-scoped conditions. | Customer insert/update write a single `users` row and need no dependent follow-up. In booking/calendar they participate in the outer transaction. `Customers_model::delete` owns or nests a transaction around parent locks, buffer cleanup and role-scoped deletion, checks affected rows and commit, and propagates errors. |
| `Services::store` / API store | Backoffice add permission or API authentication, DTO fields and validation. | New service insert is one row and has no dependent appointment buffers. |
| `Services::update` / API update | Backoffice edit permission or API authentication; the original buffer values are passed as expectations, not an authoritative change flag. | Both call `Services_model::save` directly. Its update owns a transaction when none exists. Buffer changes lock provider users, service and appointment parents; current locked buffer values and provider membership are checked before writing. Service and regenerated buffers commit together. No separate caller resync is required or permitted by the contract. |
| `Services_model::delete` | Route permission/authentication precedes this operation. | Locks service and ordinary appointment parents, removes generated children and deletes the service inside one transaction; checked commit and propagated errors. |
| `Appointments_model::sync_service_buffer_unavailabilities` | Dependent internal model work, not a separate user authorization endpoint. | Opens/nests a transaction and locks provider/service/appointment parents before replacing children. Service save calls it before committing. Direct calls finish their own transaction. |
| `Account::save` -> `Users_model::save` | POST-only, user-settings edit permission, session-bound user ID and field allowlist. | User row and all settings form one model operation. Standalone save owns begin/commit/rollback; an outer caller retains ownership when present. A settings failure propagates. Account session updates follow successful save. |

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
record or move notifications into a model transaction.

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
  failed transaction start/commit suppressing success and notifications.
- `UsersModelAtomicWriteTest`: coupled user/settings success, failure rollback
  and explicit outer ownership.
- Existing `TwoConnectionWriteContractTest` and authorization suites cover their
  documented current-state checks; see [the harness scope](two-connection-test-harness.md).

Single-row customer save is atomic at the SQL mutation boundary; this inventory
does not claim that its email read-before-write uniqueness check serializes
concurrent inserts. Notifications are post-commit effects, not an exactly-once
outbox: a delivery failure does not undo a committed booking. Nested composition
requires the outer rollback contract above. These boundaries must not be
misrepresented as broader concurrency or delivery guarantees.
