Goal (incl. success criteria):

-   Reduce deep-check compute minutes from 28m08s to <=22m without losing blocking gates.
-   Preserve current PR structure: implement PR-D1, PR-D2, PR-D3 sequentially; do PR-D4 only if post-D3 compute is still >22m.
-   For each implementation PR later: reviewer loop A/B to zero findings, full pre-PR gate including coverage delta green, ready-for-review PR opened, babysat, merged, then continue.

Constraints/Assumptions:

-   Repository is on `main` and worktree is currently clean.
-   No product-runtime changes in `application/`; optimization scope is CI, test topology, and supporting scripts/config.
-   Hard success metrics remain:
    -   deep-check compute minutes <=22m
    -   no gate loss (existing blocking gates stay blocking)
    -   flake rate no worse than baseline over 10 executed PR runs
-   `coverage-delta` stays the stable blocking gate name.
-   `deep-check-bootstrap`, `coverage-shard-unit`, `coverage-shard-integration`, `coverage-delta`, `api-contract-openapi`, `write-contract-booking`, `write-contract-api`, `booking-controller-flows`, `integration-smoke` exist in CI.
-   Full-fanout measurement means a PR run where all deep jobs actually execute, not `skipped`.
-   After each PR implementation later: Reviewer A = 0 findings, Reviewer B = 0 findings, `PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh` green, ready-for-review PR opened, babysat, merged, then continue.

Key decisions:

-   PR-D1: slim deep bootstrap artifact to PHP-only/vendor-only, and use CI-only `php-fpm` bootstrap skip flags so deep jobs do not rerun `npm install` or asset compilation when `node_modules/` is absent.
-   PR-D2: centralize deterministic seeded DB creation into one snapshot/import flow to eliminate repeated `console install` in deep jobs.
-   PR-D3: decouple `coverage-shard-unit` from Docker/MySQL/seed and run it as a lightweight runner-PHP job.
-   PR-D4 is continuity-only reserve work, to be implemented only if post-D3 deep compute is still >22m.
-   Do not prioritize further smoke/write sharding before D1-D3; naive sharding improves wall time more than aggregate compute.
-   `booking-controller-flows` can be treated as a PR-D4 optimization candidate for dropping `nginx`; current code inspection shows no live HTTP dependency in those tests.

State:

-   PR-D1 implementation is in progress on branch `codex/pr-d1-deep-bootstrap-vendor-only`.
-   `CONTINUITY.md` created because it was previously missing.
-   Current CI inspection confirms:
    -   `deep-check-bootstrap` still archives `vendor node_modules build`
    -   deep Docker jobs still repeat MySQL readiness + `console install`
    -   `coverage-shard-unit` still starts Docker/MySQL and seeds despite only running `tests/Unit`
    -   code inspection indicates `booking-controller-flows` does not need `nginx`; tests instantiate controllers directly and do not issue HTTP requests
-   Baseline and current measured deep-check compute:
    -   pre-PR-A baseline run `22722137309` = `30m33s`
    -   post-PR-A run `22726163633` = `25m23s`
    -   post-PR-B run `22730859252` = `28m51s`
    -   post-PR-C run `22732335696` = `28m08s`
-   Current gap to goal from latest state: `6m08s`

Done:

-   Verified repo status: `main`, clean working tree.
-   Reconstructed baseline and post-PR-A/B/C deep-check timings.
-   Established current result against hard metrics:
    -   compute target not met
    -   no gate loss met
    -   10-run flake proof not yet established
-   Read `.claude/napkin.md` and incorporated relevant recurring guidance.
-   Inspected current deep-job blocks in `.github/workflows/ci.yml`, coverage PHPUnit configs, `composer.json`, and `scripts/ci/pre_pr_full.sh`.
-   Inspected `phpunit.booking-flows.xml`, booking flow tests, `tests/TestCase.php`, and booking controllers for HTTP/nginx coupling.
-   Settled open question about booking flow `nginx` coupling: resolved as "not needed by current code path", reserve for PR-D4 only.
-   PR-D1 implementation edits applied to `ci.yml`, `docker-compose.yml`, `docker/php-fpm/start-container`, `README.md`, and `AGENTS.md`.
-   Verified `php-fpm` CI skip flags by starting the container with `EA_SKIP_NPM_BOOTSTRAP=1` and `EA_SKIP_ASSET_BUILD_BOOTSTRAP=1`; logs showed both skip branches active.
-   Fixed a latent local gate issue in `scripts/ci/pre_pr_quick.sh`: it now starts `mysql` and waits for readiness before DB-backed PHPUnit steps.
-   PR-D1 local validation passed: `PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh` completed green, including deep smokes and `coverage-delta` (`25.7585%` current line coverage).

Now:

-   Commit, push, open the ready-for-review PR for PR-D1, babysit it to merge, then continue to PR-D2.

Next:

-   Open the PR-D1 ready-for-review PR, babysit it to merge, and record the first post-merge full-fanout run.
-   After each merged PR, update this ledger with run IDs, compute totals, go/no-go for the next PR, and whether PR-D4 is still needed.
-   PR-specific target ladder:
    -   PR-D1 target: `<=26m45s`
    -   PR-D2 target: `<=23m30s`
    -   PR-D3 target: `<=22m00s`
    -   PR-D4: only if first clean post-D3 full-fanout run is still `>22m00s`
-   Post-D3 success proof:
    -   one clean full-fanout run at `<=22m00s`
    -   then 10 executed full-fanout PR runs without stability regression versus baseline

Open questions (UNCONFIRMED if needed):

-   UNCONFIRMED: exact post-D3 compute gain until measured on executed full-fanout PR runs.

Working set (files/ids/commands):

-   Files:
    -   `/Users/robinbeier/Developers/forscherhaus-appointments/.github/workflows/ci.yml`
    -   `/Users/robinbeier/Developers/forscherhaus-appointments/docker-compose.yml`
    -   `/Users/robinbeier/Developers/forscherhaus-appointments/docker/php-fpm/start-container`
    -   `/Users/robinbeier/Developers/forscherhaus-appointments/composer.json`
    -   `/Users/robinbeier/Developers/forscherhaus-appointments/phpunit.coverage.unit.xml`
    -   `/Users/robinbeier/Developers/forscherhaus-appointments/phpunit.coverage.integration.xml`
    -   `/Users/robinbeier/Developers/forscherhaus-appointments/phpunit.booking-flows.xml`
    -   `/Users/robinbeier/Developers/forscherhaus-appointments/scripts/ci/pre_pr_full.sh`
    -   `/Users/robinbeier/Developers/forscherhaus-appointments/tests/TestCase.php`
    -   `/Users/robinbeier/Developers/forscherhaus-appointments/tests/Integration/Controllers/BookingControllerFlowTest.php`
    -   `/Users/robinbeier/Developers/forscherhaus-appointments/tests/Integration/Controllers/BookingCancellationControllerFlowTest.php`
    -   `/Users/robinbeier/Developers/forscherhaus-appointments/tests/Integration/Controllers/BookingReadAvailabilityControllerFlowTest.php`
    -   `/Users/robinbeier/Developers/forscherhaus-appointments/README.md`
    -   `/Users/robinbeier/Developers/forscherhaus-appointments/AGENTS.md`
-   Planned PR slices:
    -   PR-D1: slim `deep-check-bootstrap` artifact + downstream restore steps
    -   PR-D2: add `deep-check-seed-snapshot` + import helpers + remove repeated `console install`
    -   PR-D3: runner-PHP `coverage-shard-unit` without Docker/MySQL/seed
    -   PR-D4: reserve only; likely `booking-controller-flows` without `nginx` plus minor remaining trim
-   PRs / runs:
    -   PR #110 run `22726163633`
    -   PR #111 run `22730859252`
    -   PR #112 run `22732335696`
    -   pre-PR-A baseline run `22722137309`
-   Commands:
    -   `PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh`
    -   `gh run view <run_id> --json jobs`
    -   `gh run list --workflow CI --event pull_request --limit 40 --json databaseId`
