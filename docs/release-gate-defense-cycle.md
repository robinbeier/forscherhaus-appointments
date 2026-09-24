# Defense-cycle evidence

This gate adds ordinary positive application checks for the deployment-first
security cycle. It does not discover vulnerabilities or certify the whole repo.
Merge, isolated testing, deployment provenance and production verification remain
separate evidence levels.

## Run the isolated checks

```bash
bash scripts/ci/run_defense_cycle.sh
```

The runner claims a fresh Docker project and a fresh MySQL data directory using
the existing CI lifecycle helpers. It accepts no caller-owned Compose project,
data directory, external target or database dump. Before installing the demo seed,
it requires the exact Docker sample database connection. Caller Docker endpoint
selectors are rejected; the current context must resolve to a local Unix/npipe
socket, which is pinned for the whole owned lifecycle. Unavailable or malformed
context evidence fails before resource mutation. It removes its containers,
network and MySQL data on normal exit, failure and handled interruption; a cleanup
failure makes the run fail. A hard host/process termination still requires the
operator to inspect and remove that exact owned project using the existing CI
lifecycle procedure. Never adopt or delete another project's resources.

The fixture also requires the explicit isolated-run flag, Docker, testing mode
and sample database connection. It creates its own ordinary administrator,
provider, customer and service; it selects no existing provider/service pair and
uses no canary authentication override. Temporary seed settings are restored.
HTTP requests use a loopback PHP server running the actual application. Own
provider integrations and notifications are disabled; the fresh seed has no
external integration credentials, and the HTTP server's mail transport is inert.
Fixture records are removed by exact identity, with relationship checks and
baseline table-count checks. The entire owned database is disposed even if row
cleanup fails. Temporary server/session files are removed as well.

`pre_pr_full.sh` runs this gate in a subshell that clears its six inherited
Compose/project/data overrides while retaining them for the parent. Explicit
Docker endpoint selectors remain visible and are rejected, never silently
redirected to another daemon. The dedicated required workflow job in
[ci.yml](../.github/workflows/ci.yml) runs the same command. Ordinary PHPUnit
coverage runs skip these tests unless the isolated flag is present; those skips
are not evidence that the gate passed.

## Phase and testcase receipts

Each runner invocation writes a unique `storage/logs/ci/defense-cycle/*.summary.json`
receipt with its source commit, tracked dirty state, isolated environment,
monotonic durations for startup/readiness/seed/PHPUnit/cleanup, and per-test
class, method, assertion count, duration and result. Skipped, failed and errored
cases remain distinct. Missing phases or unavailable JUnit data cannot yield a
complete passing receipt. The cleanup field describes the owned Docker stack;
it does not substitute for a test's own row-level cleanup assertions or establish
production cleanup.

The CI artifact `defense-cycle-receipts-defense-cycle-ordinary-flows` contains
only these JSON receipts. Private raw JUnit XML and event files remain local;
failure text, stdout and testcase properties are excluded from the summary.
Diagnostics never replace the original test exit code or prevent stack cleanup.
A missing receipt is an evidence gap even if the gate itself passed. Compare
phase durations across ordinary runs before changing readiness or test ordering.

## What the tests establish

The bounded [Provider HTTP authentication regressions](provider-api-regression-followups.md#bounded-http-authentication-regression)
also exercise list/detail reads through the actual local API authentication boundary,
using synthetic Basic/Bearer credentials and the existing isolated lifecycle.
The bounded [Provider HTTP write regressions](provider-api-regression-followups.md#bounded-http-write-regression)
add ordinary JSON POST/PUT requests, direct persistence checks, denial snapshots
and response-independent registration for cleanup in the same owned environment.

- Normal login, protected own-account save, customer read/update, and calendar
  appointment creation/update through HTTP, with persisted-value assertions.
- New appointment format from the real insert path, linked to the separately
  reviewed `random_bytes(32)` source. Format alone is not an entropy proof.
- A stored synthetic legacy-format parent link, its actual response's complete
  provider/customer field sets against independent test constants, and an ordinary
  parent update retaining that link. This is format compatibility, not proof of
  any real historical customer's link.
- An actual authenticated HTTP session, fresh account access, then elapsed
  inactivity. The isolated expiration is five seconds, file GC probability is
  zero, and the original session file must still exist immediately before the
  expired request. That request retains the test's own cookie so browser expiry
  cannot impersonate server enforcement. Redirect and session rotation are checked.
  Production expiration and the production PHP runtime are not changed or proven
  by this isolated run.
- Repeated row cleanup following an interrupted ordinary operation, plus owned
  runtime teardown by the shell runner.

The isolated suite now also exercises the safe verification modules described
below: the full account method/CSRF matrix, customer-path denials against owned
synthetic staff rows, and one deterministic calendar responsibility-change
schedule. Isolated results remain local evidence and cannot be promoted to
production evidence. Existing fixed-commit defensive reviews and historical
results must be retained separately; a green ordinary calendar save is not a
concurrency guarantee.

### Staff, settings and backoffice regression evidence

`StaffSettingsApiHttpTest` exercises ordinary Admin/Secretary API writes with
Basic and Bearer authentication, password omission, relationship clearing and
owned Settings PUT persistence. `BackofficeWriteHttpTest` covers authenticated
Customer and Blocked-period CRUD plus cleanup before destroy requests.
`UnavailabilitiesApiHttpWriteTest` covers the authenticated POST/PUT/DELETE
boundary through actual local HTTP: URL/body ID binding, ordinary appointment
and generated-buffer rejection, direct alias method guards and owned-row
nonmutation. The companion model test exercises direct lookup, update and
delete against an owned ordinary appointment and its buffers. These are
isolated regression results, not production evidence.
`BlockedPeriodsApiHttpWriteTest` separately covers the global Blocked Periods
API v1 through real isolated HTTP: Admin and configured global Bearer writes,
rejected Provider/invalid Bearer,
URL/body ID binding, positive POST/PUT/DELETE controls, time-window rejection,
direct alias method guards and complete owned-row nonmutation. Backoffice
blocked-period tests do not establish this API-v1 behavior.
`ServiceCategoriesApiHttpWriteTest` covers Service Categories API v1 with
separate owned A/B categories and an owned linked service: Admin/Bearer writes,
Provider/invalid/absent credential denials, URL/body ID binding, direct-alias
method guards, POST/PUT/DELETE controls, and the FK-defined category deletion
effect that keeps the service while clearing its category. These are isolated
HTTP/database results, not a production booking or deployment claim.
`BackofficeHttpContractTest` checks GET/HEAD method rejection on the four
backoffice controllers, missing-CSRF store/settings/updater requests, and the
specified provider permission denials. Rejected requests compare deterministic
hashes of the relevant synthetic database tables. General Settings retains its
existing permission-error response (500 with an explicit failure message);
this is distinct from the staff controllers' 403 response.

The ordinary updater tests cover confirmation GET/HEAD and a valid POST on an
already current disposable installation. They do not execute a pending migration
or establish rollback safety. These cases are a bounded matrix, not every role,
method, credential or update/destroy payload combination.

The Admins/Secretaries atomic-write, Staff-delete and Settings-batch model tests
run in the normal database-backed Unit suite. Their cleanup asserts owned row
absence; partial-fixture tests register identities before writes and preserve
unowned sentinels. Settings also verifies rollback of an uncommitted sentinel
change. Last-admin concurrency and real driver/commit faults retain the limits
of the existing test doubles. Keep individual test receipts, exact source
binding and outer-stack cleanup outcome in the private cycle report; a source
mapping or aggregate green count is not an individual execution receipt.

## Report contract and release decision

`ReleaseGate\DefenseCycleEvidence` stores append-only observations for ROB-538,
ROB-548, ROB-549, ROB-550, ROB-551 and ROB-552. Every record carries the exact
40-character commit, environment (`source`, `isolated`, `production`), method,
expected and observed result, status, coverage, remaining gap and cleanup result.
Store only descriptions, field names and booleans; never passwords, usable links,
session contents or personal data.

The machine statuses `verified`, `failed`, `not_safely_testable` are presented to
operators as `verifiziert`, `fehlgeschlagen`, `nicht sicher prüfbar`. Missing
coverage defaults to partial. Supplemental source/isolated evidence cannot satisfy
production coverage. A wrong commit, incomplete cleanup, skipped/missing result,
remaining gap or earlier failed/unsafe observation prevents a green cycle.
Historical failed runs stay in the report; they must not be silently replaced by
a later success. The class validates evidence structure, not the truth of an
operator's observation; independent review is still necessary.

This test-only change needs no database migration or production configuration
change. It does not introduce a release path. Any required production release
uses [the existing operations procedure](ops/agent-operations.md), with immutable
commit/archive provenance, normal gates and the existing rollback. Do not rerun
this isolated fixture against production. Live tests require already verified,
owned synthetic fixtures and complete cleanup; specialized canary results must
not be reported as ordinary authorization proofs.

When a required invariant remains unproven, record its concrete method/runtime
limitation in the existing Linear workpad. Do not start another discovery round
or claim complete remediation. New product repairs require a separate proposal
and operator approval. ROB-561 remains the separate documentation-drift Todo.

## Ordinary live identity and operator probe

Each operator probe uses one random, private provider or administrator, selected
by the fixed action. Before supplemental rows exist, that actor has no services,
appointments, customers, secretary links or external integrations. It logs in
through the normal application route. The harness adds no reserved identity,
authorization exception, global setting or application behavior change. Its
root-only lifecycle journal binds the exact username, email, role, marker and user
ID before any request; credential or role drift is rejected before login.

The reviewed operator bundle includes `deploy_ea.sh`, `scripts/ops/run_ordinary_live_probe.sh`,
`scripts/ops/ordinary_live_probe.php` and their release-gate libraries. Run them only from a root-controlled copy
of the reviewed tools outside the replaceable application release, against the
verified installed release. Stage and retain each bundle *archive* and
its matching `.provenance` file under `/root/fh-ordinary-probe-bundles`, a
root-owned `0700` directory whose artifacts are root-owned `0600`;
`/root/releases` remains reserved for application release archive pairs and
its legacy holds. This path rule retains
the existing bundle evidence; it does not authorize moving or deleting
production files or enabling a retention timer. The wrapper pins the tool inode
and resolves the original application directory by inode before each call and
independent cleanup.
Do not rename, replace or delete this private operator bundle during a run. These are operator
probes, not an alternative application release mechanism. If an application
release is required, use the existing controlled deployment procedure.
Before running a probe, install the reviewed `deploy_ea.sh` at the existing
root-controlled `/root/deploy_ea.sh` path using the authorized operator update;
retain the previous file and its hash for rollback. The wrapper requires exact
byte equality with its bundled deploy script and refuses an older uncoordinated
deployment entry point.

Both callers acquire the existing
`/var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock`, validating its
root ownership, private mode, canonical ancestors and unchanged inode. Normal
deployment holds it before staging/storage copying through completion or rollback;
the probe holds it before activation through verified cleanup. Conflicting
deployment, backup and retention work must wait or retry; the ordinary two-hour
session test therefore occupies this operations window. No runtime TTL changes.

Before activation the probe durably creates `/var/lib/fh-defense-ordinary/run.pending`.
Only a fully successful foreground run with verified data/session cleanup removes
it. Failure, signal or independent timer cleanup retains it, even when known
database/session objects were removed. New deployments and probes reject this
marker and remaining fixture/journal artifacts. Explicit recovery must account for
the interrupted run and any response not durably journaled; an empty database or
successful timer alone does not authorize deleting the marker. Never restore an
older uncoordinated deploy script while a run or its recovery marker is pending.

```bash
bash scripts/ops/run_ordinary_live_probe.sh preflight EXPECTED_RELEASE
bash scripts/ops/run_ordinary_live_probe.sh account EXPECTED_RELEASE
bash scripts/ops/run_ordinary_live_probe.sh methods EXPECTED_RELEASE
bash scripts/ops/run_ordinary_live_probe.sh customer-boundary EXPECTED_RELEASE
bash scripts/ops/run_ordinary_live_probe.sh customers-api EXPECTED_RELEASE
bash scripts/ops/run_ordinary_live_probe.sh staff-api EXPECTED_RELEASE
bash scripts/ops/run_ordinary_live_probe.sh services-api EXPECTED_RELEASE
bash scripts/ops/run_ordinary_live_probe.sh unavailabilities-api EXPECTED_RELEASE
bash scripts/ops/run_ordinary_live_probe.sh blocked-periods-api EXPECTED_RELEASE
bash scripts/ops/run_ordinary_live_probe.sh service-categories-api EXPECTED_RELEASE
bash scripts/ops/run_ordinary_live_probe.sh calendar-race EXPECTED_RELEASE
bash scripts/ops/run_ordinary_live_probe.sh appointments-api EXPECTED_RELEASE
bash scripts/ops/run_ordinary_live_probe.sh appointments-api-overlap EXPECTED_RELEASE
bash scripts/ops/run_ordinary_live_probe.sh session EXPECTED_RELEASE
bash scripts/ops/run_ordinary_live_probe.sh cleanup EXPECTED_RELEASE
```

### Staff API v1 live evidence boundary

The `staff-api` action performs one release-bound ordinary probe with the
existing root-controlled wrapper, shared production lock, independent cleanup
timer and persistent recovery marker. Its journaled `customer_boundary`
profile supplies only its own administrator actor, provider target, administrator
target and customer rows; no existing account is selected.

The probe requires provider URL A with the administrator's body ID B and
administrator URL B with the provider's body ID A to return 400 without changing
either complete user row, settings or relevant relationships. A direct GET to
the provider update alias must return 405. A matching provider PUT must return
200 and change only the owned provider's first name. Evidence contains classified
phase outcomes and status classes, not credentials, response bodies or private
identifiers. The local isolated HTTP/database tests cover the equivalent
same-role cases and Secretary writes; the productive probe does not claim those
paths were exercised live.

Only `verified` with all phases passed, identity-bound fixture and session
cleanup, removed transient timer and recovery marker, unchanged active release
and healthy production is terminal success. An unknown response, snapshot drift,
interruption or incomplete cleanup retains recovery evidence and stops. Changes
to the handler, probe, wrapper, fixture or evidence phase contract require fresh
local tests and review before this result can be reused.

### Unavailabilities API v1 live evidence boundary

The `unavailabilities-api` action is one planned run against the bound active
release. It uses the existing root-controlled ordinary-probe wrapper, shared
production lock, independent cleanup timer and persistent recovery marker.
Its dedicated profile creates one synthetic administrator, provider, customer,
service, two manual unavailabilities and one ordinary appointment with exactly
two generated buffers. Every identity is journaled before or immediately after
creation and checked before cleanup. It never selects an existing appointment
as a target.

The required property is that a PUT to URL A with body ID B rejects with 400
without changing A, B, the ordinary appointment or its buffers. A matching PUT
must change only A's time window and return 200. A GET to the direct destroy
alias must return 405 without deletion. The probe records only classified
phase outcomes and row comparisons; it never stores credentials, raw hashes or
response bodies in its evidence. The runtime is the real application HTTP path
under the installed release, with a root-only journal and a bounded synthetic
graph. Local isolated tests cover POST/DELETE and further denials separately;
the productive probe does not claim those methods were exercised live.

The only successful terminal result is `verified` with all three phases passed,
exact identity-bound fixture and session cleanup, removed transient cleanup
timer, absent recovery marker, unchanged active release and healthy production.
A failed phase, incomplete cleanup, identity drift, unknown response or lost
release binding stops the run and retains recovery evidence. No automatic retry
or deletion of unowned rows is authorized. A change to the API handler, model,
probe, fixture, wrapper, evidence phase allowlist or cleanup contract requires
fresh local regression and review before this live result can be reused.

### Blocked Periods API v1 live evidence boundary

The `blocked-periods-api` action is one planned run against the bound active
release. Its dedicated profile creates one synthetic administrator and exactly
two owned global blocked periods A/B, journaled before insertion. Their time
windows are about 40 days in the past, so the probe cannot close a current or
future booking slot. The probe refuses either period unless its complete
window ends more than 30 days before the run. No existing period is selected as
a target.

The required property is that PUT to URL A with body ID B returns 400 and
changes neither complete row; matching PUT returns 200 and shifts only A by ten
minutes; GET on the direct destroy alias returns 405 without deletion. The
actual runtime is the installed application over HTTP, under the existing
root-controlled wrapper, shared production lock, independent cleanup timer and
persistent recovery marker. Evidence records only the three classified phase
results and row comparisons, never credentials, raw responses or private
identifiers. Local isolated HTTP tests separately cover POST, DELETE, Basic and
Bearer authorization, invalid intervals and additional alias methods; those
are not claims of productive coverage.

Only `verified` with all phases passed, identity-bound A/B and actor cleanup,
removed transient timer and marker, unchanged active release and healthy
production is a successful terminal result. Cleanup locks both period rows in
stable ID order and checks their journaled identity and allowed time windows
before deleting either. After interrupted activation, it reconstructs only
rows matching durable intents and locks the rows actually inserted. Identity
drift, an unknown HTTP result, lost release binding or incomplete cleanup
retains recovery evidence and blocks retry. A change to the
API handler, model, probe, fixture, wrapper, evidence phase allowlist or cleanup
contract requires fresh local regression and review before reuse.

### Service Categories API v1 live evidence boundary

The `service-categories-api` action permits one planned run against the bound
active release. Its dedicated fixture creates one synthetic administrator and
two independently owned categories A/B, with durable intents and exact row
identities. It does not attach a service or select an existing category as a
target. The normal root-controlled wrapper, shared production lock, independent
cleanup timer and persistent recovery marker apply throughout the run.

A PUT to URL A with body ID B must return 400 and leave both complete category
rows unchanged. A matching PUT must return 200 and change only A's name and
description. A GET to the direct destroy alias must return 405 and leave both
rows unchanged. The probe observes actual HTTP behavior and database snapshots
on the installed release, recording only classified phase outcomes. Isolated
local tests establish the additional POST/DELETE, authorization and linked
service/availability behavior; the live run makes no claim to have exercised
those paths or a real service association.

Only `verified` with all three phases passed, exact identity-bound fixture and
actor cleanup, removed transient timer and marker, unchanged active release
and healthy production is successful. Interrupted activation may reconstruct
only rows matching journaled intents. Cleanup locks the owned categories and
refuses to delete either while any service references it, preventing an
unintended `ON DELETE SET NULL` effect. The isolated test proves refusal for an
already linked service; a forced concurrent link/cleanup interleaving is not
covered. Identity drift, unknown HTTP completion,
lost release binding or incomplete cleanup retain recovery evidence and stop
further probe/deployment work. A change to the handler, probe, fixture, wrapper,
evidence classes or cleanup contract requires fresh local tests and review.

### Shared ordinary-probe lifecycle

Replace `EXPECTED_RELEASE` with the previously verified release marker. Default
application root is `/var/www/html/easyappointments`; changing `APP_ROOT` requires
the same root-controlled, canonical application directory and release checks.
The preflight may initialize its private root state directory; it creates no user.
Any existing session journal or incomplete journal write blocks a fresh run before
activation. Finish the previous run's controlled cleanup first; preflight never
deletes prior session evidence to make a new run appear clean.
The active application path must retain its pinned directory identity and release
marker throughout evidence collection, including the inactivity wait. A deployment
aborts evidence collection; cleanup still resolves the original directory by inode.
All mutating probe actions arm an independent three-hour cleanup timer before
inserting one identity. They retain that timer if compensation fails. A callback that cannot
acquire the shared lock within five minutes exits unsuccessfully; its service
retries after 60 seconds without a start limit, including after the foreground
owner eventually releases the lock. Successful foreground compensation stops both
the timer and any pending cleanup service. A permanently stuck owner still needs
operator intervention; the persistent marker continues blocking deployments.
The actor fixture lifetime is three hours. Supplemental staff, customer, service
and appointment rows have a separate ten-minute validity window and exact durable
intent journal. Their cleanup runs before actor revocation. There is no new
authorization exemption or increased application TTL.

`account` checks normal login, exact own-account identity, a GET receiving
405/Allow POST with no persisted change, a protected own POST matching the
browser's omitted-empty-password behavior, persistence of only the intended
first-name change and ordinary logout. It is partial evidence for ROB-552: other
methods and the complete CSRF contract remain separate, unverified requirements.

`methods` uses a fresh owned session for every case. `GET`, `HEAD`, `PUT`,
`PATCH` and `DELETE` must return `405` with `Allow: POST` and leave the complete
owned user/settings snapshot unchanged. The application's global `OPTIONS`
preflight must return `200` but remain equally inert. Missing and invalid CSRF
POSTs must return `403` without mutation; one valid CSRF POST is the positive
control and may change only the intended first name. `TRACE`, `CONNECT` and
unknown methods are rejected by the operator client before a network request.

`customer-boundary` uses an owned synthetic administrator plus separate owned
synthetic provider, administrator and customer targets sharing one unique marker.
Customer search must return exactly the two customer-role targets. Provider and
administrator targets must be absent from search and return `403` for customer
find, update and destroy, with an exact database snapshot comparison after every
request. Positive customer find and update controls must succeed. The live probe
intentionally omits a destructive positive customer control: an unrelated
back-office request could add a dependent row between fixture activation and the
HTTP request, and the ordinary customer delete path would legitimately cascade
that unjournaled row. Fixture cleanup instead locks and verifies complete
dependency sets before removing its owned customer rows.

`customers-api` reuses the customer-boundary administrator and its two owned
customer rows. Under real localhost HTTP Basic authentication, it sends a JSON
`PUT` to customer A with an otherwise valid payload carrying customer B's ID and
B's matching fields; the response must be `400`, and complete `users` rows A and
B must remain unchanged. It then sends a matching-ID `PUT` to A, requiring
`200`, the returned A ID and persistence of only the intended A first-name
change, with B unchanged. The probe records fixed status/outcome classes and
never emits raw rows, credentials or HTTP bodies. It performs no delete or
non-fixture write; the existing shared lock, pending marker, three-hour cleanup
timer, release/inode checks, private journal and fail-closed cleanup remain the
wrapper's responsibility.

`services-api` uses one owned synthetic administrator and two separately
journaled synthetic services A and B. Under real localhost HTTP Basic
authentication it requires a PUT to URL A with body ID B to return `400`
without changing either service or its owned relationships. A matching PUT
must return `200` and change only the intended field of A. A direct wrong-verb
destroy alias must return `405` without deletion. Invalid duration and
attendant values must return `400` without mutation. The probe compares exact
owned snapshots after each request and relies on the wrapper's release/inode
checks, shared lock, pending marker and independent cleanup timer. It emits
only classified results; full owned-row and journal cleanup must be verified
before the run can close. This is one bounded live API write contract check,
not evidence for arbitrary users, concurrent writers or public booking.

Fixture service cleanup follows the application lock order: synthetic user
parents, then the service, its appointment parent and generated buffer children.
Every child whose `id_parent_appointment` references the owned appointment is
locked and must be absent. An unexpected generated child is left untouched and
blocks parent and service removal until a separately controlled recovery proves
it safe to continue. If a prepared journal has no parent ID, cleanup also locks
the production buffer shape attributable to the synthetic provider: an
unavailability with a non-null parent link and null customer/service references.
If a durable appointment intent cannot be reconstructed to one exact parent ID,
cleanup remains blocked: absence cannot distinguish an insert that never
committed from a deleted parent with an orphaned buffer. A retry requires
separate operator-controlled proof and, when a parent existed, restoration of
the exact intended parent before cleanup continues.

`calendar-race` creates only an owned synthetic service, customer, foreign
provider and appointment. One transaction holds the request-specific synthetic
customer parent while the real authenticated calendar request begins. Before the
request starts, the harness records the existing database process IDs. It then
accepts only one newly created process whose complete normalized user-parent lock
query contains exactly the synthetic provider/customer pair; an actor-only query,
a different customer or multiple candidates fail closed. A separate database
transaction then commits the appointment's provider reassignment; after the lock
is released, the request must return `403` and must not produce any appointment
change beyond that administrative reassignment. The appointment is restored to
its exact prior snapshot before wrapper cleanup only after a transaction locks
its parents and full row and confirms that no field other than the expected
provider reassignment changed; restoration updates only the provider field. Any
other drift remains untouched and fails closed. This is direct evidence for one
specified responsibility-change schedule, not proof of all possible interleavings.
If an error occurs while the HTTP request is still active, the harness identifies
its exact synthetic parent-lock query when possible and attempts to terminate that
database connection before releasing the held parent lock. Restoration is allowed
only after either a normal HTTP completion with a valid response status or both
connection disappearance and HTTP termination have been confirmed. If attribution
or termination cannot be confirmed, it does not restore the appointment or log
out. Instead it first creates the separate
root-only `request-unconfirmed` recovery marker and also marks the fixture
`recovery_required`. Exit code `86` independently forces the wrapper to establish
that marker and skip cleanup; if the marker cannot be established, the wrapper
stops the cleanup timer rather than permitting a later automatic deactivation.
Foreground and timer cleanup reject a valid marker, and the persistent run marker
continues blocking deployments and probes. No ordinary command clears either
state. An operator must first establish externally that the request has ended,
document the remaining exact synthetic rows, and approve a separate recovery
procedure.

`appointments-api` reuses the owned `calendar-race` provider, customer,
service and unchanged direct-model appointment as its sentinel. It adds one
synthetic administrator for Basic authentication and exactly one durably
journaled API-created appointment for each of Basic and Bearer authentication.
An existing nonempty global Bearer token remains memory-only and unchanged. If
the single `api_token` setting is exactly empty, the fixture first journals only
its row ID, the empty starting state and a SHA-256 digest of a random candidate,
then sets the candidate under a row lock through a native `mysqli` prepared
statement so it never enters CodeIgniter-rendered SQL or its query cache. This
path requires the configured native `mysqli` connection and stops after the
durable intent but before mutation for any other driver or connection shape.
Cleanup accepts only the same empty row or the digest-identical candidate and restores the empty value under lock;
after the journal enters `cleaning`, this restore commits and is re-read before
the remaining fixture cleanup starts in a separate transaction. A later fixture
guard failure therefore leaves the token empty while preserving the journal and
remaining rows for retry. Missing, duplicate or drifted rows stop fail-closed.
Preparation, guarded API deletion and deactivation also stop before waiting for
the lifecycle lock or changing journal/database state when the shared connection
already has an active transaction. The same check repeats under the lock, so
every token change and restore remains independently commit-visible. Token
values never enter the journal or probe evidence.
Each API row has exact create, update and delete intent before the corresponding
localhost HTTP mutation. The probe checks persistence, URI target binding,
server-generated hash continuity and repeated DELETE. Before each first DELETE,
one fixture transaction locks the synthetic users in ascending order, then the
service. It then re-reads the exact target payload and hash before locking the
empty child range while the independent localhost request runs; the target
appointment itself remains unlocked. Target drift, any child present before the
request, or any child inserted before the transaction commits fails closed. The
complete redacted sentinel row is captured before the first POST and must remain
exactly equal after every successful POST, PUT and DELETE and at the final
postcondition.
The probe also covers the bounded invalid method, natural-route alias,
content-type, JSON-shape and unsupported-field matrix. Negative payload checks
use the owned PUT target, so a failed contract cannot create an unjournaled row.
Cleanup removes the API rows before the sentinel and then removes relationships
and parents; any foreign relationship or generated child fails closed.

`appointments-api-overlap` uses the same root-controlled fixture and recovery
boundary as `appointments-api`, but leaves both API-created rows for wrapper
cleanup instead of deleting them in the probe. Basic creates the first row and
Bearer creates a directly adjacent row. Each authentication mode then attempts
an overlapping update of the other owned row and must receive `409` without
changing it. Finally, two localhost PUT requests are dispatched in parallel from the
client to move the two owned rows to the same unused interval; exactly one must
return `200` and persist, while the other returns `409`. Every possible target
is durably journaled before the request, the unchanged and winning outcomes are
verified against the exact owned rows, and the complete redacted sentinel
remains equal. The action touches no non-fixture appointment and does not
broaden cleanup authority. This production result proves the Basic/Bearer and
adjacency contract plus one parallel client-dispatch outcome. It does not claim
that both server-side database sessions overlapped. The deterministic local
HTTP regression separately observes an API request waiting on the provider
`FOR UPDATE` lock before the peer overlap commits; neither result proves every
database interleaving.

`session` first runs the account probe, then logs in afresh and waits for the
configured inactivity duration plus two seconds without requests or session
writes. Production expiration is never shortened or backdated. A retained own
cookie prevents client cookie expiration from impersonating server enforcement.
The original authenticated session file and unchanged activity marker must still
exist before the expired request. If file cleanup runs first, this probe is not
verified. CLI configuration is recorded separately from the actual web request.

Only exact session cookies obtained from this probe's responses are journaled
privately. Cleanup checks the recorded file inodes; it never enumerates or deletes
other production session files. Incomplete journals, changed inodes, identity or
relationship drift fail closed and retain private state for explicit recovery.
The fixed `defense-verification.json.tmp` name is itself a blocking recovery
artifact; preflight, deployment coordination and successful finish all reject it.
Journal file contents and their renamed directory entries are synchronized before
activation or further requests proceed. Cleanup synchronizes owned session
removals before retiring the private journal. A synchronization failure preserves
recovery state and prevents a successful result; the isolated gate injects this
failure before DB insertion and before session-journal retirement.
A hard interruption between an HTTP response and journal persistence can leave
an unrecorded anonymous session: such an interruption must not be reported as
complete cleanup or successful verification. Do not copy the private journal,
credentials or session contents into reports or Linear.

The isolated gate exercises the same fixture and probe classes with a separate
short-expiration HTTP server and disposable database. That short local session
result remains isolated evidence. It does not establish production expiration.
Complete cleanup, installed commit checks and independent observation review are
required before promoting any live result into the six-invariant evidence ledger.

Adding or locally passing this framework does not verify production. ROB-550,
ROB-551 and ROB-552 remain open until their matching action runs against the
pinned installed release, produces complete cleanup evidence, and the result is
independently reviewed. No new discovery or product repair is part of these
operator probes.

### Ordinary probe diagnostics and cleanup receipts

Include `scripts/release-gate/lib/OrdinaryProbeEvidence.php` in the immutable
operator bundle. Each activation starts a root-only `last-evidence.json` containing
the release, fixed step/outcome codes, timestamps and aggregate cleanup counters.
The receipt contains no account fields, cookies, credentials or raw exceptions.
The own-account probe records the failing step before attempting logout, so a
cleanup step cannot hide an earlier persistence assertion failure. Activation
postconditions, probe context/deadline checks and final active-release checks are
recorded as verify events. Standalone preflight/verify commands keep the receipt
read-only.

Supplemental dependent fixtures are removed first, then the owned actor is
revoked. Session cleanup checks every journaled path
is absent, synchronizes deletions, and durably publishes the aggregate receipt
before retiring the private session journal. Publication failure retains the
journal for retry. A handled publication failure removes only its own unpublished
temporary inode, preserving the prior receipt; pre-existing or replaced temporary
files remain blocked for explicit recovery. Repeated compensation preserves the
first completed cleanup receipt, including retries before journal retirement.
Diagnostic I/O failure cannot prevent the cleanup path from attempting identity
revocation; a failed revocation retains the private journal and recovery marker.
After 64 diagnostic events, prior events remain unchanged and further cleanup
events are marked as omitted. Successful cleanup can still publish its receipt
and finish; saturated history continues to block new verification events.
Archive the non-secret receipt with the run evidence before another activation.
A receipt covers known journaled objects only: it never authorizes clearing a
hard-interruption marker or silently treating unobserved requests as passed.

The HTTP client honors scoped cookie deletion, Max-Age precedence and expiry.
Logout deletion cookies must not be sent onward or interpreted as session IDs.
Local cookie tests are synthetic; production logout and actual elapsed inactivity
remain distinct evidence requirements.
