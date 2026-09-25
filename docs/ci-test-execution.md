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
blocking when selected. General tests, root tests, application PHPStan, and
request DTO checks use one conservative changed-path filter: ordinary Markdown
under `docs/` skips their runtime preparation and execution. Docs consumed by
existing contract tests are explicitly included. Every other path, including
root-level agent instructions, configuration, unknown paths, and mixed code/doc
diffs, keeps those checks. The workflow itself still runs on documentation
changes. The root job
installs only Composer dependencies; it needs no Node, application database, or
application configuration. Its database-restore fixtures use their own pinned
MariaDB image.

The group prevents ordinary tests inside those classes from running twice and
avoids rediscovering root-only tests in the general CI run. Local `phpunit.xml`
and coverage selection are unchanged; running the normal local command still
discovers all tests and applies their existing platform prerequisites.

The `calendar-canary-regressions` job runs the calendar authorization and
zero-surprise canary fixture tests against one fresh, isolated Docker database.
It requires the canary's root/testing runtime, fails on skipped tests, and
uploads separate per-test JUnit receipts before removing its owned stack. The
worktree inventory Python tests run in their own parallel job. A lightweight
`test-routing` check flags newly added test files without a reviewed CI route;
the hosted receipts, rather than this static check, establish actual execution.

Architecture/ownership documentation and CODEOWNERS checks continue to run.
The architecture-boundaries job skips PHP setup and Composer installation for
ordinary docs; its existing Deptrac selector still produces the normal skipped
report, and the Python component-boundary check still runs.

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

## Deep runtime failures and reruns

The shared deep runtime job prepares the environment once, runs every selected
suite, and saves the complete manifest before checking its results. A failed
suite also fails this executing job. GitHub's **Re-run failed jobs** therefore
reruns the tests, rather than only rereading a failed report. Each executing
attempt publishes its own named artifact, which the verdict jobs receive through
the producer's output. Missing reports fail closed instead of falling back to an
older attempt. Upload and cleanup still run on failure.

The separate verdict jobs retain the individual blocking check names and read
that manifest. They do not execute tests. If retrying one job manually, choose
`deep-runtime-suite`, not an individual verdict job. Locally, the full gate uses
the executing command's exit code and no longer repeats each verdict separately.

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

## Defense job selection and build timing

`defense-cycle-ordinary-flows` depends on `changes` and reuses its existing
`runtime_checks_required` output without a separate allowlist or draft exception.
Ordinary Markdown under `docs/` skips the complete job; runtime, contract,
unknown and mixed changes select it. The same filter includes both rename paths
and deleted files. A scope like PR #571 remains selected.

Workflow triggers stay unchanged: pull requests compare against their base,
while pushes to `main` compare against the preceding push through
[paths-filter's existing defaults](https://github.com/dorny/paths-filter/tree/v3).
The workflow still starts for prose changes, so its job-level skip can finish
without leaving a path-filtered workflow pending. GitHub documents this
[job-condition behavior](https://docs.github.com/en/actions/how-tos/write-workflows/choose-when-workflows-run/control-jobs-with-conditions).
A skip is not evidence that tests passed. Failed, pending or missing required
checks still block landing; a failed `changes` job must not be mistaken for a
legitimate prose skip.

Measure the `changes` dependency and the complete workflow as well as the
selected Defense job. A local selector matrix verifies routing, not a real
hosted prose skip: retain that evidence gap until a regular prose change occurs.

The Defense job retains its complete isolated application/session suite and
fresh Docker data lifecycle. Only its PHP image preparation uses the
[bounded layer cache](docker.md#defense-ci-php-layer-cache). Its additional
`php-build-defense-cycle-ordinary-flows` artifact contains the cache state,
recipe key, loaded image ID and measured phase times. Existing gate evidence
and cleanup failure handling are unchanged.

The job also checks the actual ordinary production CLI entrypoint with PHPStan.
Its bootstrap loads only the libraries explicitly required by that entrypoint
and rejects imports without a corresponding runtime class; PHPStan catches
unresolved source references before a production probe starts. The existing
isolated tests continue to cover fixture recovery and interruption cleanup.
The summary records this as
`ordinary_operator_entrypoint` so its time cost remains visible.
The signal regression covers TERM delivered while the account child is active
and cleanup after that child returns. It does not establish bounded recovery
from a permanently hung child; the retained marker and independent timer still
require operator-controlled recovery if the shared lock never becomes free.

The pre-change observation was 182 seconds for the job, including 111.9 seconds
for PHP build and 22.2 seconds for 97 tests / 668 assertions. This single run is
not a statistical baseline. The provisional warm-job target is at most 100
seconds, including transfers and setup; implementation and contract tests do
not prove that target. Use regular PR/main runs only. Record cold, warm and
unavailable-cache runs separately; if ROB-564 has no natural warm observation,
carry that measurement gap into ROB-565. Report the job and complete workflow
separately rather than treating their durations as interchangeable.


### PHP cache reliability follow-up (2026-09-16)

Natural hosted runs showed a real earlier cache hit, followed by repeated
missing-layer failures with the same Buildx 0.37.0 / BuildKit 0.32.2 versions.
These are observations, not a controlled benchmark:

| Run | Event | PHP helper total | Cache attempt | Recovery build | Export |
| --- | --- | ---: | ---: | ---: | ---: |
| [34758769174](https://github.com/robinbeier/forscherhaus-appointments/actions/runs/34758769174) | PR #572 | 24.809 s | 24.550 s, hit | — | — |
| [34801289293](https://github.com/robinbeier/forscherhaus-appointments/actions/runs/34801289293) | PR #599 | 180.743 s | 45.272 s, timeout | 124.632 s | 10.651 s |
| [34801868088](https://github.com/robinbeier/forscherhaus-appointments/actions/runs/34801868088) | main | 182.916 s | 45.277 s, timeout | 127.809 s | 9.575 s |
| [34802719894](https://github.com/robinbeier/forscherhaus-appointments/actions/runs/34802719894) | main | 166.801 s | 45.245 s, timeout | 108.696 s | 12.551 s |
| [34936824102](https://github.com/robinbeier/forscherhaus-appointments/actions/runs/34936824102) | PR #601 | 175.742 s | 45.248 s, timeout | 118.863 s | 11.272 s |
| [34937299471](https://github.com/robinbeier/forscherhaus-appointments/actions/runs/34937299471) | main | 192.876 s | 45.273 s, timeout | 127.234 s | 20.023 s, timeout |

Run 34936824102 took 302 seconds for the Defense job, including 176 seconds
for the build step and 65 seconds for the test step. The historical 122-second
job is not directly comparable because suite and setup costs have changed.
The repeated 45-second attempts followed cached-vertex missing-blob errors;
successful optional exports did not establish reuse in the next run.

The archive transport change must be measured on its ordinary PR run and later
natural main/PR runs. Record restore/save step durations alongside helper phases,
cache outcome, exact SHA and total Defense/workflow duration. The first archive
miss is a cold observation, not warm-cache proof. Acceptance requires repeated
natural warm reuse without missing-blob recovery and lower total PHP preparation
including transport. Keep this evidence gap open until those runs exist; do not
create no-op changes or rerun CI solely to generate measurements. An unavailable
cache must still permit the normal build, tests and cleanup to finish.
