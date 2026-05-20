# ROB-381 Long-Horizon Monitoring Implementation Plan

## Operating Model

Work milestone by milestone. Each milestone must end with:

- scoped diff review;
- narrow validation;
- `Documentation.md` update;
- Linear issue status/comment update where appropriate;
- one separate PR watched with `babysit-pr` until merged, ready-to-merge, or
  explicitly blocked, when that milestone creates code/docs changes for review;
- explicit stop/continue decision.

Do not start the next milestone when the current milestone has an unresolved
red validation unless the failure is unrelated, documented, and explicitly
accepted as a blocker.

## Milestone 0 - Preflight And Roadmap Alignment

### Scope

- Confirm branch, worktree, and current diff.
- Confirm Docker is available for local checks.
- Confirm `gh auth status` if PR work is expected.
- Confirm Linear issues exist for the roadmap.
- Confirm Sentry live access is represented as a gated dependency, not a plain
  text token.
- Confirm production read-only scripts still work before any live gate.

### Validation

- `git status --short`
- `docker compose config --services`
- `gh auth status`
- Read-only only if needed: `bash scripts/ops/prod_doctor.sh`

### Stop Conditions

- Dirty unrelated user changes overlap with planned edit files.
- Docker cannot start and the milestone requires local integration checks.
- Linear or GitHub access is required for the milestone but unavailable.
- Sentry token would need to be pasted or stored unsafely.

## Milestone 1 - ROB-382 App-Log Noise Classification

Status: completed by PR #280 before this long-horizon run starts. Keep this
milestone as shipped context and do not implement it again.

### Scope

- Keep ROB-382 focused on scanner/proxy noise and rate-limit cache warning
  classification.
- Harden `kuma_push_app_logs.sh`, `prod_logs_summary.sh`, and
  `prod_validate_after_change.sh` only where needed.
- Add targeted regression tests or fixtures for known scanner/proxy noise and
  genuine application errors.
- Update `scripts/ops/README.md`, `docs/observability.md`, and
  `docs/uptime-kuma.md` if semantics change.

### Acceptance Criteria

- Known scanner/proxy 404 noise does not mark app availability down.
- Real unclassified `ERROR - ` lines still fail the relevant gate.
- Post-change validation no longer stays red only because known noise appeared.
- App-health and Kuma availability semantics remain separate from log
  classification.

### Validation

- Narrow shell/PHP tests covering log classification if available.
- `bash ./scripts/ci/pre_pr_quick.sh`
- If ops scripts changed materially, read-only production summary may be used to
  compare classification output without changing production.

### Stop Conditions

- Filter would hide broad classes of real CodeIgniter errors.
- Filter depends on raw IP addresses or brittle one-off values.
- Production log samples would need to be copied into repo.

## Milestone 1.5 - Current Start Gate

### Scope

- Start the next autonomous repo-only implementation at ROB-383.
- Confirm the branch is based on a `main` that contains PR #280.
- Confirm ROB-382 is `Done` in Linear before editing Sentry code.

### Validation

- `git log --oneline --grep "Harden app log monitor noise classification"`
- `git status --short`

### Stop Conditions

- ROB-382 is not present in the branch base.
- The active branch still contains uncommitted ROB-381 planning edits.

## Milestone 2 - Sentry Redaction And Event Context Hardening

### Scope

- Remove raw `appointment_hash` or other bearer-like identifiers from Sentry
  extras.
- Add safe tags/contexts for area, operation, status class, release,
  environment, and high-level role/flow where useful.
- Add a scrubber before expanding captured error scope.
- Keep expected 4xx/business conflicts out of Sentry.
- Prepare a safe Sentry delivery smoke path, gated from live configuration.

### Acceptance Criteria

- No Sentry capture path sends raw appointment hash, token, DSN, Push URL, DB
  row, raw request body, customer email, or sensitive config.
- Sentry captures remain actionable through release/environment/area/operation
  tags.
- Expected conflicts continue to return correct HTTP status without Sentry
  capture.

### Validation

- Narrow PHPUnit tests for Sentry context/scrubbing helpers.
- Existing PDF/export/booking confirmation tests where touched.
- `bash ./scripts/ci/pre_pr_quick.sh`

### Live Sentry Gate

Live Sentry verification requires a secure local token path. Acceptable options:

- connector-provided Sentry access;
- host-local environment variables configured outside the repo;
- operator-run verification with only sanitized results reported back.

Never paste the token into chat, Linear, docs, git, or visible terminal output.

Live Sentry write/config changes are out of scope for the repo-only run. If a
repo-only Sentry milestone reaches the point where live verification would add
confidence, record the gate and stop before using a token.

## Milestone 3 - Deep Health And Kuma Secret Boundary Cleanup

### Scope

- Align docs/templates with the actual `/health`, `/index.php/healthz`, and
  `X-Health-Token` boundary.
- Document that the token belongs in Kuma/host-local config only.
- Ensure agent runbooks say how to diagnose deep-health failures without
  printing secrets.
- Verify desired-state docs remain consistent with
  `scripts/ops/uptime-kuma.monitors.yml`.

### Validation

- Documentation review with `rg` checks for leaked tokens/Push URLs.
- `git diff --check`
- Optional read-only production check: public `/health` only, no token output.

### Stop Conditions

- Live Kuma header audit requires revealing a token.
- Desired-state docs drift from the active script/template source of truth.

## Milestone 4 - Backup Creation Vs Restore-Verify Freshness

### Scope

- Decide whether the current marker proves only restore verification freshness
  or also backup creation freshness.
- If needed, add or document a separate backup creation freshness signal.
- Keep Push messages agent-legible and free of sensitive filenames, paths beyond
  approved marker paths, or backup contents.

### Acceptance Criteria

- Operator can distinguish stale restore verification from stale backup
  creation.
- Kuma signal tells Robin which action to take.
- Docs explain the marker(s), thresholds, owner action, and secret boundary.

### Validation

- Narrow script tests or shellcheck-style validation where available.
- `bash ./scripts/ci/pre_pr_quick.sh` if repo scripts changed.
- Read-only production marker inspection only if needed.

### Stop Conditions

- Any command would list backup contents, DB dumps, customer data, or secrets.
- Backup deletion, rotation, upload, or restore would be required.

## Milestone 5 - Runtime Monitor Naming Drift Cleanup

### Scope

- Update repo docs and desired-state files where monitor names still mention
  obsolete runtime names such as `php8.3` while production runs a newer unit.
- If live Kuma rename is desired, treat it as a Kuma write gate.
- Keep historical notes where useful, but make current operator-facing names
  accurate.

### Validation

- `rg -n "php8\\.3|php8\\.5" docs scripts`
- `git diff --check`
- Optional read-only production service status check.

### Stop Conditions

- Live Kuma changes are required but not approved.
- Unit names differ between repo assumptions and production reality.

## Milestone 6 - Optional Booking Confirmation PDF Synthetic Decision

### Scope

- Decide whether parent booking-confirmation PDF monitoring is worth the privacy
  and maintenance cost.
- Prefer a documented no-go unless a stable privacy-safe synthetic confirmation
  can exist without real family data or reusable bearer hashes.
- If go, design only first; implementation is a separate gated issue.

### Validation

- Decision recorded in `Documentation.md` and related docs.
- No real confirmation hash or customer data used.

### Stop Conditions

- Only available test path uses real production family/customer data.
- Monitoring would require storing a live bearer-like link in Kuma or repo.

## Milestone 7 - ROB-367 Observation Integration

### Scope

- Feed final monitoring findings into ROB-367 or its successor.
- Summarize what remained green, what was noisy, what was changed, and what
  still requires manual live verification.
- Keep this read-only unless a separate production change gate is explicitly
  reached.

### Validation

- `Documentation.md` final state updated.
- Linear references updated.
- Secret scan over all changed docs.

## Milestone 8 - Final Review Package

### Scope

- Ensure docs, tests, and Linear are synchronized.
- Prepare PR summary with validation and residual live gates.
- Keep PR scoped to completed milestones. If later milestones remain gated,
  document them rather than hiding them in partial code.

### Validation

- `git diff --check`
- Secret/PII grep over changed files.
- `bash ./scripts/ci/pre_pr_quick.sh`
- `PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh` before review-ready
  merge, unless blocked and explicitly documented.
