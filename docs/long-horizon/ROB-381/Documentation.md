# ROB-381 Long-Horizon Monitoring Implementation Log

## Current Status

- Status: ROB-387 repo-only milestone in progress.
- Branch: `codex/rob-387-pdf-synthetic-decision`.
- Scope: autonomous implementation roadmap for the ROB-381 monitoring audit,
  initially repo-only.
- Live gates: Server/Kuma/Sentry writes are not approved by default.
- Current start point for new work: ROB-387 repo-only decision PR.

## Roadmap Issue Status

- ROB-381: audit and target concept source issue.
- ROB-382: completed by PR #280, app-log noise classification.
- ROB-383: completed by PR #281, Sentry redaction and event-context hardening.
- ROB-384: completed by PR #283, Kuma deep-health secret-boundary documentation
  and header audit.
- ROB-385: completed by PR #284, split backup creation freshness from restore
  verification signal.
- ROB-386: completed by PR #285, clean up production monitor runtime-name
  drift.
- ROB-387: in progress, decide privacy-safe parent booking confirmation PDF
  synthetic.
- ROB-388: Long-horizon implementation coordinator for the full roadmap.
- ROB-367: existing post-rebuild observation issue to receive final monitoring
  findings.
- New roadmap issues: created in Linear on 2026-05-20.

## Decisions

### 2026-05-20 - Long-Horizon File Location

Use `docs/long-horizon/ROB-381/` for the long-horizon task files instead of the
repo root. This keeps the repo root focused while preserving durable task
memory in versioned docs.

### 2026-05-20 - Initial Execution Boundary

Plan the implementation as repo-only first. Server, Kuma, and Sentry live write
changes are explicit gated milestones with stop conditions.

### 2026-05-20 - Sentry Token Handling

The operator has a Sentry Security/API token, but it must not be pasted into
chat, Linear, docs, or git. Live Sentry verification requires a secure local
environment or connector path. Without that, only repo-side Sentry hardening is
in scope.

### 2026-05-20 - Tooling Choice

Do not introduce a new monitoring product now. Uptime Kuma, Sentry, app health
endpoints, logs, release gates, and existing ops scripts remain the target
architecture unless a later milestone proves a high-leverage gap.

### 2026-05-20 - ROB-382 Shipped Before Long-Horizon Run

ROB-382 was implemented separately in PR #280 and moved to Done in Linear.
The shipped implementation keeps runtime rate limiting unchanged and narrows the
change to App-Log monitor classification, production summary/validation script
alignment, docs, and regression tests. The long-horizon implementation now
starts with ROB-383.

### 2026-05-20 - PR Babysitting Requirement

Every separate implementation PR in this roadmap must be watched with
`.codex/skills/babysit-pr/SKILL.md` until it is merged, closed,
ready-to-merge, or blocked on human input. Automated review comments must be
handled before continuing to the next milestone.

## Milestone Log

### Milestone 0 - Preflight And Roadmap Alignment

- Status: completed for the repo-only start.
- Done:
  - ROB-381 audit documents created.
  - Long-horizon task files created.
  - Sentry token handling boundary recorded.
  - Linear follow-up issues ROB-383 through ROB-388 created.
  - Branch fast-forwarded to `origin/main` after PR #280.
  - ROB-382 completion recorded as shipped context.
- Pending:
  - None for the repo-only ROB-383 start.

### Milestone 1 - ROB-382 App-Log Noise Classification

- Status: completed separately by PR #280.
- Result: shipped as narrow repo-side monitor classification; no runtime
  rate-limit bypass.
- Next: do not reopen unless new monitor evidence proves a regression.

### Milestone 2 - Sentry Redaction And Event Context Hardening

- Status: completed by PR #281.
- Done:
  - Added central Sentry extra/request/user scrubbing in `SentryBootstrap`.
  - Added safe digest helper for bearer-like correlation values.
  - Removed raw appointment hash from booking confirmation capture.
  - Replaced PDF renderer endpoint URL extras with endpoint-kind categories.
  - Added a dry-run-by-default Sentry delivery smoke script gated by
    `SENTRY_SMOKE_SEND=1`.
  - Documented the Sentry data policy in `docs/observability.md`.
  - Babysat PR #281 until GitHub reported 18/18 checks green and clean
    mergeability.
  - Merged PR #281 into `origin/main` at `900a39cd`.
- Gate: secure Sentry token/connector needed only for live verification, not for
  repo-side code hardening.

### Milestone 3 - Deep Health And Kuma Secret Boundary Cleanup

- Status: completed by PR #283.
- Done:
  - Corrected Kuma desired-state docs so `App - Health Deep` and
    `App - PDF Renderer` require `X-Health-Token` as a host/Kuma-local secret.
  - Added non-secret `secret_headers` placeholders to
    `scripts/ops/uptime-kuma.monitors.yml`.
  - Added agent diagnosis guidance for `401` vs `503` deep-health failures.
  - Updated the server-local agent README template to name the health token path
    as secret-bearing without exposing its contents.
  - Babysat PR #283 until GitHub reported 7/7 checks green and clean
    mergeability.
  - Merged PR #283 into `origin/main` at `cf99f70e`.

### Milestone 4 - Backup Creation Vs Restore-Verify Freshness

- Status: completed by PR #284.
- Done:
  - Confirmed `kuma_push_ops_jobs.sh` reads only
    `last_verify_success.utc`, so it proves restore-verification freshness,
    not backup creation freshness.
  - Renamed the desired-state monitor to `Ops - Restore Verify Freshness`.
  - Added a separate `kuma_push_backup_creation.sh` marker-based Push script
    for `last_backup_success.utc`.
  - Added the new Push env variable, cron template entry, desired-state monitor,
    docs, and unit coverage.
- Pending:
  - None for repo-only ROB-385.
- Result:
  - Babysat PR #284 until GitHub reported 7/7 checks green and clean
    mergeability.
  - Merged PR #284 into `origin/main` at `57cb8daa`.

### Milestone 5 - Runtime Monitor Naming Drift Cleanup

- Status: completed by PR #285.
- Done:
  - Read-only production check confirmed `php8.5-fpm` is active and
    `php8.3-fpm` is inactive/not listed.
  - Renamed repo desired-state PHP-FPM log monitor to
    `App - php8.5-fpm Log Errors`.
  - Updated operator-facing monitoring docs to point diagnosis at
    `php8.5-fpm`.
  - Marked old `php8.3-fpm` references as historical or gated live-Kuma drift.
  - Made the pre-wipe inventory helper use configurable service units with a
    `php8.5-fpm` default.
- Pending:
  - None for repo-only ROB-386.
- Result:
  - Babysat PR #285 until GitHub reported 7/7 checks green and clean
    mergeability.
  - Merged PR #285 into `origin/main` at `421536bd`.

### Milestone 6 - Optional Booking Confirmation PDF Synthetic Decision

- Status: in progress on branch `codex/rob-387-pdf-synthetic-decision`.
- Decision:
  - No live Kuma synthetic monitor for the parent booking confirmation PDF flow
    yet.
- Done:
  - Confirmed the existing booking confirmation PDF gate requires an existing
    confirmation hash or full confirmation URL.
  - Classified those values as bearer-like parent-facing access.
  - Added a dedicated decision document with future go criteria.
- Pending:
  - Final validation, PR creation, and babysitting.

### Milestone 7 - ROB-367 Observation Integration

- Status: not started.

### Milestone 8 - Final Review Package

- Status: not started.

## Validation Log

### 2026-05-20 - Repo-Only Start Preflight

- `git fetch --prune origin`
- `git pull --ff-only origin main`
- `git status --short --branch`
- `gh auth status`
- `docker compose config --services`
- `git diff --check`
- secret/PII grep over `docs/monitoring` and `docs/long-horizon/ROB-381`
  for Push URLs, tokens, raw IPs, email addresses, and key material
- `bash ./scripts/ci/pre_pr_quick.sh`

Result: passed. The only secret-scan text hit was an intentional documentation
warning that `config.php` must not be printed.

### 2026-05-20 - ROB-383 Focused Validation

- `php -l application/bootstrap/SentryBootstrap.php`
- `php -l application/controllers/Booking_confirmation.php`
- `php -l application/libraries/Pdf_renderer.php`
- `php -l tests/Unit/Bootstrap/SentryBootstrapTest.php`
- `php -l tests/Unit/Controllers/BookingConfirmationControllerTest.php`
- `php -l tests/Unit/Libraries/PdfRendererTest.php`
- `php -l scripts/ops/sentry_smoke.php`
- `php scripts/ops/sentry_smoke.php`
- `docker compose run --rm -e APP_ENV=testing php-fpm php vendor/bin/phpunit tests/Unit/Bootstrap/SentryBootstrapTest.php tests/Unit/Controllers/BookingConfirmationControllerTest.php tests/Unit/Libraries/PdfRendererTest.php`
- `bash ./scripts/ci/pre_pr_quick.sh`

Result: focused syntax and PHPUnit checks passed after tightening route
scrubbing for booking-confirmation URLs. Sentry smoke dry-run passed without
sending a live event. Full `pre_pr_quick` also passed.

### 2026-05-20 - ROB-383 PR Babysitting And Merge

- PR #281 watched with `.codex/skills/babysit-pr/SKILL.md`.
- GitHub checks reached 18/18 passed.
- PR mergeability reached `CLEAN`.
- No review comments were surfaced by the watcher.
- PR #281 merged into `origin/main` at `900a39cd`.

### 2026-05-20 - ROB-384 Focused Validation

- `ruby -e "require 'yaml'; data = YAML.load_file('scripts/ops/uptime-kuma.monitors.yml'); raise 'bad monitors' unless data['monitors'].is_a?(Array); puts \"monitors #{data['monitors'].length}\""`
- `git diff --check`
- secret/Push-URL grep over changed ROB-384 docs and desired-state files
- drift grep for deep-health/PDF monitors still documented as public-only
- `curl -fsS --max-time 10 https://dasforscherhaus-leg.de/health`
- `bash ./scripts/ci/pre_pr_quick.sh`

Result: desired-state YAML parsed with 12 monitors, no secret-pattern hits in
the changed files, no stale public-only wording for tokenized deep-health
monitors, public shallow health returned `OK`, and full `pre_pr_quick` passed.

### 2026-05-20 - ROB-384 PR Babysitting And Merge

- PR #283 watched with `.codex/skills/babysit-pr/SKILL.md`.
- GitHub checks reached 7/7 passed.
- PR mergeability reached `CLEAN`.
- No review comments were surfaced by the watcher.
- PR #283 merged into `origin/main` at `cf99f70e`.

### 2026-05-20 - ROB-385 Focused Validation

- `bash -n scripts/ops/kuma_push_ops_jobs.sh scripts/ops/kuma_push_backup_creation.sh`
- `php -l tests/Unit/Scripts/KumaPushScriptEnvLoadingTest.php`
- `ruby -e "require 'yaml'; data = YAML.load_file('scripts/ops/uptime-kuma.monitors.yml'); raise 'bad monitors' unless data['monitors'].is_a?(Array); puts \"monitors #{data['monitors'].length}\""`
- `docker compose run --rm -e APP_ENV=testing php-fpm php vendor/bin/phpunit tests/Unit/Scripts/KumaPushScriptEnvLoadingTest.php`
- `bash ./scripts/ci/pre_pr_quick.sh`

Result: shell/PHP syntax passed, desired-state YAML parsed with 13 monitors,
focused Kuma Push script PHPUnit coverage passed with 5 tests and 39
assertions, and the full quick pre-PR gate passed.

### 2026-05-20 - ROB-385 PR Babysitting And Merge

- PR #284 watched with `.codex/skills/babysit-pr/SKILL.md`.
- GitHub checks reached 7/7 passed.
- PR mergeability reached `CLEAN`.
- No review comments were surfaced by the watcher.
- PR #284 merged into `origin/main` at `57cb8daa`.

### 2026-05-20 - ROB-386 Runtime Name Evidence

- `ssh ... systemctl is-active php8.5-fpm`
- `ssh ... systemctl is-active php8.3-fpm`
- `ssh ... systemctl list-unit-files --type=service --no-pager php8.5-fpm.service php8.3-fpm.service`

Result: read-only production check showed `php8.5-fpm` active/enabled and
`php8.3-fpm` inactive/not listed. No configuration contents, secrets, DB rows,
or Kuma data were printed.

### 2026-05-20 - ROB-386 Focused Validation

- `bash -n scripts/ops/prepare_same_server_rebuild_backup.sh scripts/ops/kuma_push_php_fpm_logs.sh scripts/ops/kuma_push_host_services.sh`
- `ruby -e "require 'yaml'; data = YAML.load_file('scripts/ops/uptime-kuma.monitors.yml'); raise 'bad monitors' unless data['monitors'].is_a?(Array); puts \"monitors #{data['monitors'].length}\""`
- `rg -n "php8\\.3|php8\\.5" docs scripts README.md WORKFLOW.md AGENTS.md`
- `git diff --check`
- secret/Push-URL grep over changed ROB-386 docs and desired-state files
- `bash ./scripts/ci/pre_pr_quick.sh`

Result: shell syntax passed, desired-state YAML parsed with 13 monitors, runtime
grep showed current operator-facing monitor/script paths aligned to
`php8.5-fpm` with remaining `php8.3` references limited to historical or gated
drift context, no secret values or live Push URLs were found, whitespace checks
passed, and the full quick pre-PR gate passed.

### 2026-05-20 - ROB-386 PR Babysitting And Merge

- PR #285 watched with `.codex/skills/babysit-pr/SKILL.md`.
- GitHub checks reached 7/7 passed.
- PR mergeability reached `CLEAN`.
- No review comments were surfaced by the watcher.
- PR #285 merged into `origin/main` at `421536bd`.

### 2026-05-20 - ROB-387 Decision Evidence

- `docs/release-gate-booking-confirmation-pdf.md`
- `scripts/release-gate/booking_confirmation_pdf_gate.php`
- `docs/monitoring/target-concept.md`

Result: the existing booking confirmation PDF gate is suitable for release,
restore, and explicitly approved one-off checks, but not for a continuous live
Kuma monitor unless a privacy-safe synthetic confirmation target exists. No
production confirmation hashes, URLs, customer names, or PDF contents were read
or recorded.

### 2026-05-20 - ROB-387 Focused Validation

- `git diff --check`
- secret/hash grep over changed ROB-387 docs
- `bash ./scripts/ci/pre_pr_quick.sh`

Result: whitespace checks passed, grep hits were limited to placeholders,
public URLs, and documentation terms such as confirmation hash/URL, and the full
quick pre-PR gate passed.

## Known Risks

- Linear connector may be intermittently unavailable.
- Live Sentry audit previously returned unauthorized with the available local
  environment; this must be fixed through secure token configuration, not by
  pasting the token into the task.
- Sentry live verification remains gated on a secure token/connector path.

## Final Shipped State

ROB-383 shipped repo-only. Sentry live verification remains a later gated step
that needs a secure token or connector path; no Server, Kuma, or Sentry live
configuration was changed by this milestone.
