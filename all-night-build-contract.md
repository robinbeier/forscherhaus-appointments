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

Now:

-   Share branch + commit for code review.
-   Prepare credential-backed full gate run once admin credentials are provided.

Next:

-   Validate:
    -   Share branch name + commit hash for review.
    -   Run gate with valid admin credentials and confirm full pass-path (`exit 0`) including export checks.
    -   Decide whether deploy-hook wiring in /Users/robinbeier/Documents/forscherhaus-appointments/deploy_ea.sh is in overnight scope or follow-up.

Open questions (UNCONFIRMED if needed):

-   UNCONFIRMED: Which admin credential pair will be reserved for gate execution in staging/prod?
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
