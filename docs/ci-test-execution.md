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

## Local quick and full checks

`pre_pr_full.sh` runs `pre_pr_quick.sh` first and stops if it fails. The quick
check owns the application PHPStan run, so the full check does not repeat it.
`PRE_PR_PHPSTAN_APPLICATION_SCRIPT` still selects the command for both entry
points; the full check forwards it to the quick check.

| Check | Quick alone | Full |
| --- | --- | --- |
| Application PHPStan | Once | Once, through quick |
| Request DTO checks | Included | Included through quick |
| Broader request-contract checks | Not included | Included after quick |
| Deep integration and optional coverage | Not included | Included |

Request DTO and request-contract suites overlap but have different scopes.
The full and changed-file architecture checks also have distinct reporting
and scope semantics; they remain separate. Each stage keeps its existing
Docker cleanup, including failure handling.

## Comparing CI duration

Use GitHub Actions job and step timestamps for a specific before/after
comparison. Record both run links and the changed workload. A shorter parallel
job does not necessarily shorten the overall workflow by the same amount.
Do not describe two runs as an established statistical baseline.

Automatic timing-cohort and heavy-job duration trend reports have been removed.
CI changes require no timing epoch, all-job timing fingerprint update or
maintained duration thresholds. Execution checks remain in place.

For the coverage-selection change in PR #400, the unit coverage step took
6m45s in [run 33961076120](https://github.com/robinbeier/forscherhaus-appointments/actions/runs/33961076120)
and 5s in [run 33962119628](https://github.com/robinbeier/forscherhaus-appointments/actions/runs/33962119628).
Both GitHub Clover artifacts contained exactly the same 411 covered
application statement lines, with none lost or added. This is evidence for
that change, not a promise about future total CI duration.
