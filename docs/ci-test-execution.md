# CI Test Execution

`.github/workflows/ci.yml` defines job triggers and blocking behavior.

For booking journeys and their concrete protection, see the
[booking test map](booking-test-map.md).

## Main tests and coverage

- `phpunit.xml` runs the main unit suite, including CI and operations tooling
  tests in `tests/Unit/Scripts`.
- `phpunit.coverage.unit.xml` measures the selected request-data library
  test files. Tooling tests are not repeated here: the coverage source is
  application code, not the tooling scripts.
- `phpunit.coverage.integration.xml` retains application and integration tests.
  The main suite remains necessary because not every application unit test is
  selected by the coverage configurations.
- Coverage thresholds are defined by the
  [coverage-delta policy](../scripts/ci/config/coverage_delta_policy.php).

Useful workflow checks live in `tests/Unit/Scripts/CiWorkflowContractTest.php`:
main-suite failure handling, database setup and cleanup, root deployment
checks, and explicit deterministic integration-test settings. Path selection
is tested by `tests/Unit/Scripts/CiPathFilterMatrixTest.php`.

## Independent general and root tests

In GitHub CI, `build-test` excludes the `root-deployment` group. The independent
`root-deployment-tests` job executes those classes through the existing
root regression script, plus the retained Python scanner tests. Both jobs are
blocking; the root job waits for the changed-path classification and is skipped
only when every changed path is Markdown under `docs/`. `build-test` remains
unconditional and still consumes the Markdown files it tests. The root job
installs only Composer dependencies; it needs no Node, application database, or
application configuration. Its database-restore fixtures use their own pinned
MariaDB image.

The group prevents ordinary tests inside those classes from running twice and
avoids rediscovering root-only tests in the general CI run. Local `phpunit.xml`
and coverage selection are unchanged; running the normal local command still
discovers all tests and applies their existing platform prerequisites.

## PHP-only job preparation

The application PHPStan, request DTO, and request-contract jobs install only
Composer dependencies. Their analysis, unit tests, and adoption checks do not
consume generated frontend assets. `build-test` installs its JavaScript test
dependencies without building assets. Only browser checks prepare runtime assets.

The changed-JavaScript job uses a [shared selector](../scripts/ci/js-lint-changed.sh)
before setting up Node or installing packages. It selects changed, existing
non-minified files under `assets/js/` for ESLint. Frontend build-tooling and
compiler-test changes can also require Node even when no application JavaScript
changed. Whenever Node is needed, the job runs the compiler regression tests;
ESLint runs only on the selected source files. `npm ci --ignore-scripts` installs
their dependencies without building application assets. A failed diff check
fails the job instead of reporting no changes.

## Integration coverage preparation

The integration coverage shard runs PHP with Xdebug directly on the GitHub
runner, as the main test job already does. Only MySQL runs in Compose. Its
local `config.php` points to `127.0.0.1`; the repository sample remains unchanged
for normal Docker development.

After database readiness, `php index.php console install` creates the seeded
instance using the existing bounded retry loop. Installation failure stops
the job before coverage runs. Diagnostics and unconditional Compose cleanup
remain in place. The coverage shards install their own locked Composer
dependencies after setting up PHP. The standalone `coverage-delta` job uses
PHP to merge the downloaded Clover/XML reports and evaluate the policy; it does
not need a Composer vendor tree. Coverage result artifacts still pass between
the coverage shards and their merge job.

The selected suite needs neither a PHP-FPM web server nor a browser. PDF and
health controller unit tests use test doubles for network calls. The separate
deep runtime and browser checks use the host PHP test server described in
[the Docker guide](docker.md#github-integration-runtime).

Use actual GitHub runs to compare elapsed time and covered statement lines;
local full validation still uses Docker.

## Local quick and full checks

`pre_pr_full.sh` runs `pre_pr_quick.sh` first and stops if it fails. The quick
check owns the application PHPStan run, so the full check does not repeat it.

| Check | Quick alone | Full |
| --- | --- | --- |
| Application PHPStan | Once | Once, through quick |
| Request DTO checks | Included | Included through quick |
| Broader request-contract checks | Not included | Included after quick |
| Deep integration and optional coverage | Not included | Included |

Request DTO and request-contract suites overlap but have different scopes.
The local full check runs the complete Deptrac analysis once in Docker. A
successful full analysis already rules out violations in changed files, so
the local check does not repeat the narrower changed-file analysis on the
host. GitHub CI retains its changed-file gate and report. CODEOWNERS and
component-boundary checks remain separate. Each stage keeps its existing
Docker cleanup, including failure handling.

## Comparing CI duration

Use GitHub Actions job and step timestamps for a specific before/after
comparison. Record both run links and the changed workload. A shorter parallel
job does not necessarily shorten the overall workflow by the same amount.
Do not describe two runs as an established statistical baseline.
