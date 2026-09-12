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
it requires the exact Docker sample database connection. It removes its containers,
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

`pre_pr_full.sh` runs this gate. The dedicated required workflow job in
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
