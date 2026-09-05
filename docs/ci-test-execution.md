# CI Test Execution

`.github/workflows/ci.yml` defines job triggers and blocking behavior.

## Main tests and coverage

- `phpunit.xml` runs the main unit suite, including CI and operations tooling
  tests in `tests/Unit/Scripts`.
- `phpunit.coverage.unit.xml` measures the nine selected request-data library
  test files. Tooling tests are not repeated here: the coverage source is
  application code, not the tooling scripts.
- `phpunit.coverage.integration.xml` retains application and integration tests.
  The main suite remains necessary because not every application unit test is
  selected by the coverage configurations.
- Coverage thresholds and report merging remain unchanged.

Useful workflow checks live in `tests/Unit/Scripts/CiWorkflowContractTest.php`:
main-suite failure handling, database setup and cleanup, root deployment
checks, and explicit deterministic integration-test settings. Path selection
is tested by `tests/Unit/Scripts/CiPathFilterMatrixTest.php`.

## Comparing CI duration

Use GitHub Actions job and step timestamps for a specific before/after
comparison. Record both run links and the changed workload. A shorter parallel
job does not necessarily shorten the overall workflow by the same amount.
Do not describe two runs as an established statistical baseline.

The automatic versioned timing-cohort report has been removed. CI changes no
longer require a timing epoch or an all-job timing fingerprint update. The
separate execution checks and advisory heavy-job duration report remain.

For the coverage-selection change in PR #400, the unit coverage step took
6m45s in [run 33961076120](https://github.com/robinbeier/forscherhaus-appointments/actions/runs/33961076120)
and 5s in [run 33962119628](https://github.com/robinbeier/forscherhaus-appointments/actions/runs/33962119628).
Both GitHub Clover artifacts contained exactly the same 411 covered
application statement lines, with none lost or added. This is evidence for
that change, not a promise about future total CI duration.
