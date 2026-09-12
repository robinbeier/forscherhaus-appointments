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

These are positive functional and lifecycle checks. They do not independently
establish all staff-role denials, all HTTP-method/CSRF negatives, or authorization
under concurrent responsibility changes. Existing fixed-commit defensive reviews
and regression results for those properties must be retained separately. A green
ordinary calendar save is not a concurrency guarantee.

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

The next operator probe uses one random, private provider with no services,
appointments, customers, secretary links or external integrations. It logs in
through the normal application route. It adds no reserved identity, authorization
exception, global setting or application behavior change. Its root-only lifecycle
journal binds the exact username, email, marker and user ID before any request;
credential drift is rejected before a login could fall back to LDAP.

The reviewed operator bundle includes `deploy_ea.sh`, `scripts/ops/run_ordinary_live_probe.sh`,
`scripts/ops/ordinary_live_probe.php` and their six release-gate libraries. Run them only from a root-controlled copy
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
Account/session runs arm an independent three-hour cleanup timer before inserting
one identity. They retain that timer if compensation fails. A callback that cannot
acquire the shared lock within five minutes exits unsuccessfully; its service
retries after 60 seconds without a start limit, including after the foreground
owner eventually releases the lock. Successful foreground compensation stops both
the timer and any pending cleanup service. A permanently stuck owner still needs
operator intervention; the persistent marker continues blocking deployments.
The fixture lifetime
is three hours; there is no new authorization exemption or increased global TTL.

`account` checks normal login, exact own-account identity, a GET receiving
405/Allow POST with no persisted change, a protected own POST matching the
browser's omitted-empty-password behavior, persistence of only the intended
first-name change and ordinary logout. It is partial evidence for ROB-552: other
methods and the complete CSRF contract remain separate, unverified requirements.

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

This addition does not verify the full staff/customer read-write-delete boundary
or calendar authorization during a concurrent responsibility change. Those
production evidence gaps remain explicit in ROB-551 and ROB-550. No new discovery
or product repair is part of the operator probe.

### Ordinary probe diagnostics and cleanup receipts

Include `scripts/release-gate/lib/OrdinaryProbeEvidence.php` in the immutable
operator bundle. Each activation starts a root-only `last-evidence.json` containing
the release, fixed step/outcome codes, timestamps and aggregate cleanup counters.
The receipt contains no account fields, cookies, credentials or raw exceptions.
The own-account probe records the failing step before attempting logout, so a
cleanup step cannot hide an earlier persistence assertion failure.

The owned fixture is revoked first. Session cleanup checks every journaled path
is absent, synchronizes deletions, and durably publishes the aggregate receipt
before retiring the private session journal. Publication failure retains the
journal for retry. A handled publication failure removes only its own unpublished
temporary inode, preserving the prior receipt; pre-existing or replaced temporary
files remain blocked for explicit recovery. Repeated empty compensation preserves
the original receipt.
Archive the non-secret receipt with the run evidence before another activation.
A receipt covers known journaled objects only: it never authorizes clearing a
hard-interruption marker or silently treating unobserved requests as passed.

The HTTP client honors scoped cookie deletion, Max-Age precedence and expiry.
Logout deletion cookies must not be sent onward or interpreted as session IDs.
Local cookie tests are synthetic; production logout and actual elapsed inactivity
remain distinct evidence requirements.
