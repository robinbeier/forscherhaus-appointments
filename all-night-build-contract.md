# All-Night Build Contract

Goal (incl. success criteria):

-   Deliver an overnight MVP "Zero-Surprise" dashboard release gate for /Users/robinbeier/Documents/forscherhaus-appointments.
-   Success criteria:
    -   One CLI command performs: login -> dashboard metrics -> heatmap -> principal PDF -> teacher ZIP -> teacher PDF.
    -   Command exits with strict codes: 0 pass, 1 assertion failure, 2 configuration/runtime error.
    -   Writes JSON report to /Users/robinbeier/Documents/forscherhaus-appointments/storage/logs/release-gate/.
    -   No changes to production request handlers' behavior (gate is external script + assertions).
    -   Runtime target: <= 180 seconds on Docker-parity local stack.

Constraints/Assumptions:

-   Release horizon is 8 days; stability-first.
-   Overnight budget: one focused night (approx. 6-8h).
-   Keep risk low: read-mostly replay only; no booking create/reschedule/cancel mutations in MVP.
-   Auth is session + CSRF cookie based; script must maintain cookie jar and send csrf_token on POST.
-   Admin session is required for dashboard/export endpoints.
-   Use AGENTS.md guardrails (no edits under /Users/robinbeier/Documents/forscherhaus-appointments/system).
-   UNCONFIRMED: dedicated gate admin credentials are available in target environments.

Key decisions:

-   In scope (overnight):
    -   New release-gate script + lightweight helper libs.
    -   Assertion unit tests for gate logic.
    -   Composer alias and concise runbook doc.
-   Out of scope (post-deploy hardening):
    -   Booking mutation replay.
    -   Automatic rollback orchestration.
    -   GitHub Actions workflow redesign.
    -   Visual PDF diffing/content OCR checks.
-   Export validation strategy: binary/header integrity checks (%PDF, PK signatures), content-type, byte-size thresholds.
-   Data assertions are structural/invariant-based, not exact-count snapshots.

State:

-   Contract file exists and is the canonical session briefing for this work.
-   Release-gate MVP implementation is complete and validated in CI-parity Docker tests.
-   Full pass-path gate replay is blocked only by missing confirmed admin gate credentials.
-   Review packaging is complete on branch `codex/dashboard-release-gate-mvp` (commit `3d3a381fb12823dd109d53b96a49e236e53b1f9f`).
-   Follow-up request received to operationalize staging/prod admin gate credentials for a full gate run.
-   User confirmed code review is done and contract delivery is fulfilled.
-   Current request is a development-environment dry run guide before staging/prod credential rollout.
-   User provided two dev gate JSON reports and requested validation/next-step clarification.
-   User now asks whether realistic pre-prod testing should be done on restored dev data and/or after merging to `main`.
-   Active execution request: restore the usual dev/staging-like dump locally, then provide a step-by-step realistic gate test guide using admin username `gate.staging`.
-   Realistic dataset restore completed from `/Users/robinbeier/Backups/easyappointments/db/easyappointments_2026-02-19_021701Z.sql.gz` (after rejecting repo-local minimal dump as too sparse).
-   User executed the realistic strict gate run and reported success (`summary.exit_code=0`, `summary.failed=0`).
-   User noted the restored DB reflects current `main` usage and some optional data/config details (class size/buffer) are missing.
-   User requested a focused "ready-to-review" handoff message plus an exact copy/paste command block for rerunning the gate on `main`.
-   Ready-to-review PR requested explicitly by user and now opened against `main`.
-   User requested PR comment triage + yeet flow; active review thread identified on PR #57 for exit-code classification semantics.
-   PR thread fix is now pushed on branch and fresh automated review has been requested via PR comment.
-   New Codex review cycle produced two unresolved P2 threads on PR #57; both were validated as in-scope and technically correct for this release-gate contract.
-   Follow-up execution completed: both validated concerns were implemented, pushed, and threads resolved; new `@codex review` comment posted.
-   Automation run `find-test-gaps` (2026-02-25) identified missing unit coverage for release-gate CSRF config parsing and assertion-exit classification helper logic.

Done:

-   Created /Users/robinbeier/Documents/forscherhaus-appointments/all-night-build-contract.md with canonical contract content.
-   Confirmed current workspace status before implementation (`git status --short` shows only all-night-build-contract.md).
-   Implemented CLI runner: /Users/robinbeier/Documents/forscherhaus-appointments/scripts/release-gate/dashboard_release_gate.php
-   Implemented HTTP session/CSRF client: /Users/robinbeier/Documents/forscherhaus-appointments/scripts/release-gate/lib/GateHttpClient.php
-   Implemented invariant assertion library: /Users/robinbeier/Documents/forscherhaus-appointments/scripts/release-gate/lib/GateAssertions.php
-   Added unit tests for assertion logic: /Users/robinbeier/Documents/forscherhaus-appointments/tests/Unit/Scripts/DashboardReleaseGateAssertionsTest.php
-   Added operator docs: /Users/robinbeier/Documents/forscherhaus-appointments/docs/release-gate-dashboard.md
-   Added composer script alias `release:gate:dashboard`: /Users/robinbeier/Documents/forscherhaus-appointments/composer.json
-   Added release-gate MVP package to roadmap: /Users/robinbeier/Documents/forscherhaus-appointments/PLAN.md
-   Syntax checks passed:
    -   php -l /Users/robinbeier/Documents/forscherhaus-appointments/scripts/release-gate/dashboard_release_gate.php
    -   php -l /Users/robinbeier/Documents/forscherhaus-appointments/scripts/release-gate/lib/GateHttpClient.php
    -   php -l /Users/robinbeier/Documents/forscherhaus-appointments/scripts/release-gate/lib/GateAssertions.php
-   Composer help invocation passed:
    -   composer release:gate:dashboard -- --help
-   CI-parity test suite passed in Docker:
    -   docker compose run --rm php-fpm composer test
-   Local gate smoke run executed and produced JSON report:
    -   /Users/robinbeier/Documents/forscherhaus-appointments/storage/logs/release-gate/dashboard-gate-smoke.json
-   Fixed two implementation issues discovered during smoke run:
    -   Corrected `--require-nonempty-metrics` default handling (now false when omitted).
    -   Removed deprecated `curl_close()` calls for PHP 8.5+ compatibility.
-   Confirmed endpoint/auth behavior in code:
    -   /Users/robinbeier/Documents/forscherhaus-appointments/application/controllers/Login.php
    -   /Users/robinbeier/Documents/forscherhaus-appointments/application/controllers/Dashboard.php
    -   /Users/robinbeier/Documents/forscherhaus-appointments/application/controllers/Dashboard_export.php
    -   /Users/robinbeier/Documents/forscherhaus-appointments/application/core/EA_Security.php
-   Confirmed current CI/deploy gap:
    -   /Users/robinbeier/Documents/forscherhaus-appointments/.github/workflows/ci.yml runs PHPUnit only.
    -   /Users/robinbeier/Documents/forscherhaus-appointments/deploy_ea.sh performs only basic localhost curl check.
-   Decision-complete MVP scope drafted and accepted.
-   Received explicit request to create a review branch and commit current MVP changes before credentials follow-up.
-   Resolved pre-commit conflict for `/Users/robinbeier/Documents/forscherhaus-appointments/scripts/release-gate/dashboard_release_gate.php` (Prettier trailing newline vs `git diff --check`) by adding an explicit ignore entry in `/Users/robinbeier/Documents/forscherhaus-appointments/.prettierignore`.
-   Created review branch and committed MVP artifacts:
    -   Branch: `codex/dashboard-release-gate-mvp`
    -   Commit: `3d3a381fb12823dd109d53b96a49e236e53b1f9f`
    -   Pre-commit checks passed (Prettier, php -l, Docker PHPUnit).
-   Added follow-up commit updating contract review state:
    -   Branch: `codex/dashboard-release-gate-mvp`
    -   Commit: `8f1f2cb6`
-   Clarified that "staging UI" guidance can be executed in dev first; preparing concrete dev step-by-step runbook.
-   Validated both dev gate reports:
    -   `/Users/robinbeier/Documents/forscherhaus-appointments/storage/logs/release-gate/dev-20260224T180629Z.json` -> `summary.exit_code=0`, `failed=0`
    -   `/Users/robinbeier/Documents/forscherhaus-appointments/storage/logs/release-gate/dashboard-gate-20260224T180912Z.json` -> `summary.exit_code=0`, `failed=0`
-   Verified current local DB snapshot appears fresh/limited (expected for reset/install workflow):
    -   `ea_migrations.version=68`
    -   `providers_count=1`
    -   `appointments_count=1`
    -   `ea_user_settings` contains `administrator`, `janedoe`, `gate.dev`
-   Confirmed dev gate runs are fully successful and can be treated as baseline for realistic-data reruns.
-   Read and curated session runbook (`/Users/robinbeier/Documents/forscherhaus-appointments/.claude/napkin.md`) at turn start per always-on skill requirement.
-   Enumerated dump candidates and identified staging-like backups under `/Users/robinbeier/Backups/easyappointments/db/`.
-   Executed full destructive restore + migrate using:
    -   `docker compose down`
    -   `find docker/mysql -mindepth 1 -maxdepth 1 -exec rm -rf {} +`
    -   `docker compose up -d mysql php-fpm nginx`
    -   `gunzip -c /Users/robinbeier/Backups/easyappointments/db/easyappointments_2026-02-19_021701Z.sql.gz | docker compose exec -T mysql mysql -uroot -psecret easyappointments`
    -   `docker compose exec -T php-fpm php index.php console migrate`
-   Verified restored DB shape:
    -   `ea_migrations.version=68`
    -   `providers_count=14`
    -   `appointments_count=233`
    -   `admins_count=1`
    -   Sample `ea_user_settings` usernames include `beierrobin`, `daniel.anselm`, `lisa.skutella`, `sina.brand`.
-   Realistic strict gate run outcome confirmed by user command output:
    -   `summary.exit_code=0`
    -   `summary.failed=0`
-   Checked schema/settings around the class-size/buffer note:
    -   `ea_users.class_size_default` column exists.
    -   `ea_settings.dashboard_conflict_threshold` exists with value `0.90`.
    -   No `buffer_before` / `buffer_after` settings rows found in this dataset.
-   Pushed branch and created PR:
    -   Branch: `codex/dashboard-release-gate-mvp` (tracking `origin/codex/dashboard-release-gate-mvp`)
    -   PR: `https://github.com/robinbeier/forscherhaus-appointments/pull/57`
    -   Title: `Add dashboard release gate MVP`
    -   Body includes focused summary, rationale, and validation evidence.
-   Fetched PR comments/threads via skill script:
    -   `/Users/robinbeier/.codex/skills/gh-address-comments/scripts/fetch_comments.py`
    -   Found one unresolved review thread on `scripts/release-gate/dashboard_release_gate.php` about routing readiness/auth misconfig failures to exit code `2`.
-   Implemented fix for exit-code mapping:
    Implemented fix for exit-code mapping:
    -   Added `classifyAssertionExitCode()` and `isRuntimePreflightCheck()` in `scripts/release-gate/dashboard_release_gate.php`.
    -   `GateAssertionException` now maps readiness/auth check failures to `RELEASE_GATE_EXIT_RUNTIME_ERROR` (`2`) and keeps behavioral assertions on `1`.
-   Validation after fix:
    -   `php -l /Users/robinbeier/Documents/forscherhaus-appointments/scripts/release-gate/dashboard_release_gate.php`
    -   `docker compose run --rm php-fpm composer test` -> pass (`117 tests`, `345 assertions`).
-   Yeet flow completed for review fix:
    -   Commit: `84f3b9ca` (`Route preflight assertion failures to runtime exit code`)
    -   Push: `origin/codex/dashboard-release-gate-mvp`
    -   PR trigger comment: `@codex review` at `https://github.com/robinbeier/forscherhaus-appointments/pull/57#issuecomment-3954139641`
    -   Resolved addressed thread `PRRT_kwDOPw9iS85wfl2w` via GraphQL mutation.
    -   Post-push comment fetch confirms no unresolved review threads on PR #57.
-   Re-fetched comments after latest `@codex review`; found two unresolved threads:
    -   `PRRT_kwDOPw9iS85wfu8h`: CSRF cookie name must include `cookie_prefix`.
    -   `PRRT_kwDOPw9iS85wfu8k`: post-login HTTP status failures should map to runtime exit code `2`.
-   Applied both fixes in `/Users/robinbeier/Documents/forscherhaus-appointments/scripts/release-gate/dashboard_release_gate.php`:
    Applied both fixes in `/Users/robinbeier/Documents/forscherhaus-appointments/scripts/release-gate/dashboard_release_gate.php`:
    -   `resolveCsrfNamesFromConfig()` now reads `cookie_prefix` and prefixes default CSRF cookie name.
    -   `classifyAssertionExitCode()` now treats HTTP status assertion failures as runtime/config exit code `2` via `isHttpStatusAssertionFailure()`.
-   Validation after latest fixes:
    -   `php -l /Users/robinbeier/Documents/forscherhaus-appointments/scripts/release-gate/dashboard_release_gate.php`
    -   `docker compose run --rm php-fpm composer test` -> pass (`117 tests`, `345 assertions`).
-   Yeet flow for latest review fixes:
    -   Commit: `ef6d4c72` (`Handle cookie prefixes and HTTP runtime classification`)
    -   Push: `origin/codex/dashboard-release-gate-mvp`
    -   PR trigger comment: `@codex review` at `https://github.com/robinbeier/forscherhaus-appointments/pull/57#issuecomment-3954205653`
    -   Resolved threads:
        -   `PRRT_kwDOPw9iS85wfu8h`
        -   `PRRT_kwDOPw9iS85wfu8k`
    -   Latest comment fetch confirms all PR #57 review threads are resolved.
-   Extracted untested CLI helper logic from `scripts/release-gate/dashboard_release_gate.php` into `scripts/release-gate/lib/GateCliSupport.php`.
-   Added targeted unit tests in `tests/Unit/Scripts/GateCliSupportTest.php` for:
    -   runtime vs assertion exit-code classification
    -   CSRF defaults and `cookie_prefix` handling from CodeIgniter config
-   Validation for this run:
    -   `php -l scripts/release-gate/lib/GateCliSupport.php`
    -   `php -l scripts/release-gate/dashboard_release_gate.php`
    -   `php -l tests/Unit/Scripts/GateCliSupportTest.php`
    -   `php vendor/bin/phpunit ...` blocked locally (`vendor/bin/phpunit` missing)
    -   `docker compose run --rm php-fpm ...` blocked in sandbox (no Docker socket access)

Now:

-   Package current test-gap patch for draft PR creation once branch + remote are available.

Next:

-   Run PHPUnit suite in CI-parity environment to confirm new test file execution.
-   Create/push draft PR for the isolated test-gap patch from this automation run.

Open questions (UNCONFIRMED if needed):

-   UNCONFIRMED: Which admin credential pair will be reserved for gate execution in staging/prod?
-   UNCONFIRMED: Whether missing optional class size/buffer data points in current main-like DB should be covered by an additional data-seeded scenario (not required for current dashboard gate scope).
-   UNCONFIRMED: Should deploy hook wiring in /Users/robinbeier/Documents/forscherhaus-appointments/deploy_ea.sh be included in the same overnight implementation or left for follow-up (default: follow-up)?

Working set (files/ids/commands):

-   Files (existing):
    -   /Users/robinbeier/Documents/forscherhaus-appointments/application/controllers/Login.php
    -   /Users/robinbeier/Documents/forscherhaus-appointments/application/controllers/Dashboard.php
    -   /Users/robinbeier/Documents/forscherhaus-appointments/application/controllers/Dashboard_export.php
    -   /Users/robinbeier/Documents/forscherhaus-appointments/application/core/EA_Security.php
    -   /Users/robinbeier/Documents/forscherhaus-appointments/.github/workflows/ci.yml
    -   /Users/robinbeier/Documents/forscherhaus-appointments/deploy_ea.sh
-   Files (planned new/updated):
    -   /Users/robinbeier/Documents/forscherhaus-appointments/all-night-build-contract.md
    -   /Users/robinbeier/Documents/forscherhaus-appointments/scripts/release-gate/dashboard_release_gate.php
    -   /Users/robinbeier/Documents/forscherhaus-appointments/scripts/release-gate/lib/GateHttpClient.php
    -   /Users/robinbeier/Documents/forscherhaus-appointments/scripts/release-gate/lib/GateAssertions.php
    -   /Users/robinbeier/Documents/forscherhaus-appointments/tests/Unit/Scripts/DashboardReleaseGateAssertionsTest.php
    -   /Users/robinbeier/Documents/forscherhaus-appointments/docs/release-gate-dashboard.md
    -   /Users/robinbeier/Documents/forscherhaus-appointments/composer.json
    -   /Users/robinbeier/Documents/forscherhaus-appointments/PLAN.md
-   Commands:
    -   docker compose run --rm php-fpm composer test
    -   php /Users/robinbeier/Documents/forscherhaus-appointments/scripts/release-gate/dashboard_release_gate.php --base-url=http://localhost --index-page=index.php --username="$EA_GATE_USERNAME" --password="$EA_GATE_PASSWORD" --start-date=2026-02-01 --end-date=2026-03-03 --statuses=Booked --pdf-health-url=http://localhost:3003/healthz
