-   Goal (incl. success criteria):

    -   Introduce deterministic Unit-suite PHP coverage reporting, publish machine-readable artifacts, and add CI gate `coverage-delta` enforcing minimum allowed coverage drop and absolute floor.
    -   Success criteria: composer coverage commands exist and run; deterministic artifacts are produced under `storage/logs/ci/`; gate logic + tests pass; CI job exists as warn-only rollout and is switchable to blocking after 7 green PR runs.

-   Constraints/Assumptions:

    -   Scope limited to Unit suite (`tests/Unit`) and `application/` include coverage scope.
    -   Clover line coverage metric (`coveredstatements / statements`) is source metric.
    -   Policy source of truth is in-repo versioned config (no external baseline service).
    -   Rollout must follow existing warn-only then blocking-after-7-green pattern.
    -   `system/` must not be modified.
    -   Current baseline assumption is acceptable for initial non-regression gate: 4.19% line coverage.
    -   UNCONFIRMED: Branch protection/ruleset enforcement availability remains unknown; workflow-level enforcement is used.

-   Key decisions:

    -   Add Composer commands:
        -   `composer test:coverage:unit`
        -   `composer check:coverage:delta`
    -   Add CI check/job name: `coverage-delta` (stable name, unchanged across rollout phases).
    -   Add policy thresholds:
        -   `baseline_line_coverage_pct = 4.19`
        -   `max_drop_pct_points = 0.20`
        -   `absolute_min_line_coverage_pct = 3.99`
        -   `epsilon_pct_points = 0.02`
    -   Gate fail conditions:
        -   `line_pct + epsilon < absolute_min_line_coverage_pct`
        -   `delta_pct_points + epsilon < -max_drop_pct_points`
    -   Phase 1 CI mode: `continue-on-error: true`; Phase 2 switch removes only this flag.

-   State:

    -   Sprint implementation completed on 2026-03-03 (local validation complete).
    -   Handoff state switched to VCS packaging on 2026-03-03 (new branch + commit requested).
    -   Rollout state: CI `coverage-delta` remains warn-only until 7 consecutive green PR runs.

-   Done:

    -   Verified baseline repo state:
        -   `.github/workflows/ci.yml` has rollout jobs but no `coverage-delta`.
        -   `composer.json` has no coverage scripts yet.
        -   `phpunit.xml` has Unit suite only, no coverage filter config.
        -   Docker PHP image includes Xdebug extension support.
    -   Verified target files for sprint are currently missing:
        -   `phpunit.coverage.xml`
        -   `scripts/ci/check_coverage_delta.php`
        -   `scripts/ci/config/coverage_delta_policy.php`
        -   `tests/Unit/Scripts/CoverageDeltaGateTest.php`
        -   coverage Clover fixtures.
    -   Created canonical continuity ledger at `/Users/robinbeier/Developers/forscherhaus-appointments/CONTINUITY.md`.
    -   Added coverage execution wiring:
        -   `phpunit.coverage.xml` with Unit suite and `application/` include scope.
        -   `composer test:coverage:unit` in `composer.json`.
    -   Added coverage delta gate implementation:
        -   `scripts/ci/config/coverage_delta_policy.php`.
        -   `scripts/ci/check_coverage_delta.php` with Clover parsing, policy evaluation, and JSON report output.
        -   `composer check:coverage:delta` in `composer.json`.
    -   Added unit test coverage gate suite and fixtures:
        -   `tests/Unit/Scripts/CoverageDeltaGateTest.php`.
        -   `tests/Unit/Scripts/fixtures/coverage/clover-high.xml`.
        -   `tests/Unit/Scripts/fixtures/coverage/clover-low.xml`.
    -   Added CI quality gate job:
        -   `.github/workflows/ci.yml` now includes `coverage-delta` with `continue-on-error: true`.
        -   Job uploads `coverage-delta-artifacts` and emits JSON diagnostics on failure.
    -   Updated contributor/runtime documentation:
        -   `README.md` includes local coverage + delta commands and coverage CI notes.
        -   `AGENTS.md` includes local commands, rollout streak command, artifact paths, and rollback guidance.
    -   Executed local validation commands successfully:
        -   `docker compose run --rm php-fpm composer test:coverage:unit` (passes; writes Clover report).
        -   `docker compose run --rm php-fpm composer check:coverage:delta` (passes; writes JSON report).
        -   `docker compose run --rm php-fpm sh -lc 'APP_ENV=testing php vendor/bin/phpunit --filter CoverageDeltaGateTest'` (passes all new gate tests).
    -   Hardened implementation details after initial pass:
        -   Clover XML parser now surfaces libxml parse details in runtime errors.
        -   CI artifact upload step for `coverage-delta` now runs with `if: always()` for failure triage.
    -   Verified generated artifacts:
        -   `storage/logs/ci/coverage-unit-clover.xml`
        -   `storage/logs/ci/coverage-delta-latest.json`
    -   Created implementation branch and commit:
        -   Branch: `codex/coverage-delta-sprint`
        -   Commit: `f0ad4210` (`Add coverage delta reporting and CI gate`)

-   Now:

    -   Keep continuity ledger aligned with post-commit VCS state.

-   Next:

    -   Observe first PR runs with `coverage-delta` and collect streak results.
    -   Remove only `continue-on-error: true` after 7 non-cancelled green PR runs.

-   Open questions (UNCONFIRMED if needed):

    -   UNCONFIRMED: Exact CI wall-clock runtime impact of adding coverage job on current GitHub runners.

-   Working set (files/ids/commands):
    -   Files:
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/composer.json`
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/phpunit.coverage.xml`
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/scripts/ci/config/coverage_delta_policy.php`
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/scripts/ci/check_coverage_delta.php`
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/tests/Unit/Scripts/CoverageDeltaGateTest.php`
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/tests/Unit/Scripts/fixtures/coverage/clover-high.xml`
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/tests/Unit/Scripts/fixtures/coverage/clover-low.xml`
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/.github/workflows/ci.yml`
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/README.md`
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/AGENTS.md`
    -   Commands:
        -   `composer test:coverage:unit`
        -   `composer check:coverage:delta`
        -   `docker compose run --rm php-fpm composer test:coverage:unit`
        -   `docker compose run --rm php-fpm composer check:coverage:delta`
        -   `docker compose run --rm php-fpm composer test`
        -   `for run_id in $(gh run list --workflow CI --event pull_request --limit 40 --json databaseId -q '.[].databaseId'); do gh run view "$run_id" --json jobs -q '.jobs[] | select(.name=="coverage-delta") | .conclusion'; done | awk '$1 != "cancelled"' | head -n 7`
