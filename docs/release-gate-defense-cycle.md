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

## What the tests establish

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
`scripts/ops/ordinary_live_probe.php` and their ten release-gate libraries. Run them only from a root-controlled copy
of the reviewed tools outside the replaceable application release, against the
verified installed release. The wrapper pins the tool inode and resolves the
original application directory by inode before each call and independent cleanup.
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
bash scripts/ops/run_ordinary_live_probe.sh calendar-race EXPECTED_RELEASE
bash scripts/ops/run_ordinary_live_probe.sh session EXPECTED_RELEASE
bash scripts/ops/run_ordinary_live_probe.sh cleanup EXPECTED_RELEASE
```

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
