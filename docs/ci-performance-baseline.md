# CI Performance Baseline

Canonical measurement contract and legacy ROB-444 diagnostic evidence. There
is currently no established post-change baseline. This document describes
elapsed CI time only; it does not relax a gate, optimize a job, define an
enforcement goal, or claim an improvement.

## Comparison Contract

- Workflow: `.github/workflows/ci.yml`, event `pull_request`, attempt 1.
- End-to-end: workflow `created_at` through the latest non-skipped job's
  `completed_at`.
- Initial queue: workflow `created_at` through the earliest non-skipped job's
  `started_at`. Per-job queue is `job.started_at - job.created_at`.
- Job and phase duration: GitHub Actions job or successful step `started_at`
  through `completed_at`.
- Statistics: median and nearest-rank p75. A comparable baseline needs at least
  five samples; the standard window contains seven.
- Workload contract v16 starts at cohort epoch `2026-09-05T10:52:45Z`, when the
  unit coverage shard stopped repeating the 133 tooling test files already
  executed by the main PHPUnit suite.
  Runs before this instant are excluded as `workload_contract_mismatch` even
  when their deep-runtime flags happen to match.
- Selection is fail-closed: a run is comparable only when its complete profile
  fingerprint equals the policy fingerprint and it is on or after the workload
  cohort epoch.
  Missing jobs, job-log fields, or later API pages therefore cannot
  silently produce a match.
- The terminal job is recorded per sample but is not an eligibility condition.
  Normal timing variance may make a different successful job end last without
  excluding the run.

The executable definition is
`scripts/ci/config/ci_performance_baseline_policy.php`. The advisory
`scripts/ci/measure_ci_performance_baseline.php` report repeats the method,
profile, selected run IDs, exclusions, raw samples, median, p75, critical path,
and fully observed phase rankings in
`ci-performance-baseline-latest.json`.

## Versioned Workload Contract

Workload contract v16 pins the canonicalized definitions of every job in
`.github/workflows/ci.yml` to
`sha256:2c964c74d39c48a61b6adab057aca3c27c19509fc152e4b40de7b04484ae7771`.
The workflow contract test also requires every job to have an explicit expected
`success` or `skipped` conclusion in the comparison profile. This covers the
always-active `build-test`, JavaScript lint, PHPStan, typed request DTO, and both
architecture jobs as well as the deep, coverage, contract, write-path, browser,
and diagnostic jobs.

Any future change to a measured active job definition makes the runtime check
and workflow contract test fail closed. The maintainer must review the complete
workload and deliberately increment `workload_contract.version`, move
`cohort_epoch_utc`, and update `workflow_jobs_sha256`. Moving the epoch restarts
the cohort; values from an earlier workload contract remain diagnostic only.

## Coverage Execution Comparison

Workload contract v16 removes 133 repeated `tests/Unit/Scripts` files from the
unit coverage shard. The general PHPUnit run still executes all main tests, and
the application coverage source and covered statement set remain unchanged.

| Suite | Before | After | Notes |
| --- | ---: | ---: | --- |
| Main PHPUnit (`phpunit.xml`) | 199 files | 199 files | all retained |
| Unit coverage shard | 142 files | 9 files | request DTO tests retained |
| Integration coverage shard | 50 files | 50 files | includes 5 integration tests |
| Main tests absent from coverage shards | 12 files | 12 files | retained for the general suite |

The GitHub artifact comparison for run
[33961076120](https://github.com/robinbeier/forscherhaus-appointments/actions/runs/33961076120)
and the Docker candidate both report 411 covered application statements, with
zero statements lost or added. The candidate executed 55 tests and 155
assertions in 0.164s; this is local evidence only and is not a cross-machine
speed claim. The workflow definition hash is unchanged.

## Profile Fingerprint

Profile version 2 has fingerprint
`sha256:ae9650c696c293d7663d8a39f59c18a5ec334737af17c3300d6b48c913f0d76d`.
The hash uses canonical JSON with sorted object keys and ordered suite lists.
Baseline and after-runs must have this exact fingerprint and workload-contract
version.

Its inputs are:

- Consumer conclusions `success`: every workflow job except
  `heavy-job-duration-trends`, including `changes`, `build-test`,
  `js-lint-changed`, `phpstan-application`, `typed-request-dto`, both
  architecture jobs, and all deep-runtime, coverage, contract, write-path,
  browser, and PDF jobs.
- Consumer conclusion `skipped`: `heavy-job-duration-trends`, as expected on a
  pull request.
- Requested suites, in order: `api-contract-openapi`,
  `write-contract-booking`, `write-contract-api`,
  `booking-controller-flows`, `integration-smoke`.
- Active change flags: request contracts, deep bootstrap, deep runtime asset
  build, coverage, API contract, both write contracts, booking flows,
  integration smoke, LDAP guardrail, and PDF renderer latency; heavy-job
  trends are inactive on pull requests.
- Mode flags: `event=pull_request`, `run_attempt=1`, booking search horizon
  `14`, retry count `1`, date window `2026-01-01` through `2026-01-31`, LDAP
  included, browser-bootstrap timeout `900`, browser evidence `on-failure`,
  and Playwright runtime package
  `playwright@1.59.0-alpha-1771104257000`.

Consumer conclusions and the successful `Build runtime JS assets` step come
from the Jobs API. Requested suites and runtime mode flags come from the
`deep-runtime-suite` job log. The Jobs API is read page by page at 100 jobs per
page before the profile is evaluated. Workflow-run pages accept at most
GitHub's API maximum of 100; larger `--per-page` values fail before fetching so
a capped 100-result page cannot be mistaken for the final page.

## Current Post-Epoch Cohort

There are currently **0 valid post-epoch baseline samples out of the required
5**. Run
[31232249260](https://github.com/robinbeier/forscherhaus-appointments/actions/runs/31232249260)
predates the current workload contract and was also a draft-PR run
whose full-profile jobs were intentionally skipped. It is not a baseline
sample. No baseline median or p75 is established.

## Legacy Diagnostic Runs (Not A Baseline)

Captured on 2026-08-08 from GitHub Actions. These four runs were homogeneous
under the earlier deep-profile definition, but all predate workload contract
v1 and its expanded `build-test` workload. The selector now excludes every one
with `workload_contract_mismatch`. Their values are retained only to explain
earlier diagnosis; they are not ROB-446 baseline samples.

| Run | Created (UTC) | Branch | End-to-end | Initial queue |
| --- | --- | --- | ---: | ---: |
| [31224348182](https://github.com/robinbeier/forscherhaus-appointments/actions/runs/31224348182) | 2026-08-07 22:35 | `codex/rob-442-harden-config-permissions` | 8m 39s | 4s |
| [31223736063](https://github.com/robinbeier/forscherhaus-appointments/actions/runs/31223736063) | 2026-08-07 22:25 | `codex/rob-442-harden-config-permissions` | 10m 14s | 2s |
| [31222354173](https://github.com/robinbeier/forscherhaus-appointments/actions/runs/31222354173) | 2026-08-07 22:02 | `codex/rob-442-harden-config-permissions` | 8m 53s | 3s |
| [31220381778](https://github.com/robinbeier/forscherhaus-appointments/actions/runs/31220381778) | 2026-08-07 21:31 | `codex/rob-442-harden-config-permissions` | 8m 34s | 2s |

All four happened to end with `coverage-delta`; that legacy observation does
not constrain future post-epoch samples. The three previously mixed
`codex/provider-ui-smoke` runs are also excluded: their
`api-contract-openapi` and `pdf-renderer-latency` consumers were skipped, so
their fingerprints differ. Draft, partial, cancelled, failed, and retried runs
are ineligible.

## Legacy Diagnostic Values

These values describe only the four pre-epoch runs. They must not be used as an
activatable baseline or combined with future post-epoch runs.

| Metric | Median | p75 | Observed range |
| --- | ---: | ---: | ---: |
| Full-gate PR end-to-end | 8m 46s (526s) | 8m 53s (533s) | 8m 34s–10m 14s |
| Initial workflow queue | 2.5s | 3s | 2s–4s |
| Maximum ready-job queue within a run | 9s | 9s | 9s |
| `deep-runtime-suite` | 4m 25s | 4m 27s | 4m 20s–4m 31s |
| `coverage-shard-integration` | 3m 43s | 3m 49s | 3m 23s–4m 59s |
| `deep-check-seed-snapshot` | 3m 35s | 3m 37s | 3m 28s–3m 37s |
| `deep-check-bootstrap` | 48s | 53s | 43s–53s |
| `coverage-shard-unit` | 31.5s | 32s | 29s–48s |
| `coverage-delta` | 17s | 20s | 11s–22s |

The 10m 14s maximum had only 2s initial queue while
`coverage-shard-integration` took 4m 59s. Queue is visible but is not the
dominant observed driver.

## Legacy Observed Critical Path And Largest Shares

The earlier dependency graph and all four legacy terminal observations identify:

`changes -> deep-check-bootstrap -> deep-check-seed-snapshot -> coverage-shard-integration -> coverage-delta`

`deep-runtime-suite` runs in parallel after `deep-check-bootstrap`; it is a
large job but did not terminate these runs. The report records terminal-job
counts from the selected samples. If normal variation makes
`deep-runtime-suite` finish after `coverage-delta`, the run remains eligible and
the observed terminal path changes instead of silently biasing the cohort.

The three largest fully observed legacy phases all have a diagnostic median of
183.5s (34.9% of 526s):

| Phase | Median | p75 |
| --- | ---: | ---: |
| `deep-runtime-suite :: Start deep runtime services` | 3m 03.5s | 3m 04s |
| `coverage-shard-integration :: Start coverage shard services` | 3m 03.5s | 3m 10s |
| `deep-check-seed-snapshot :: Start seed snapshot services` | 3m 03.5s | 3m 05s |

These shares overlap because jobs run in parallel and must not be added. On the
critical path, the seed and integration service starts are the two dominant
serial phases.

## Legacy Queue And Cache Conditions

- npm cache: every legacy cohort run reported a primary-key hit in all six
  Node jobs.
- Composer download cache: every legacy cohort run reported a miss in
  `deep-check-bootstrap`.
- GitHub-hosted jobs start with fresh runner filesystems and Docker services.
  The dominant service-start phases therefore reflect a cold-runner condition,
  not a warm local stack.

A post-epoch comparison must report these conditions afresh and may not import
these values or discard slow/cold-cache samples merely to improve a number.

## ROB-446 Activation Boundary

ROB-446 remains blocked in Backlog. Its goal cannot be activated from the four
pre-epoch runs, the earlier mixed cohort, or the draft run. At least five
representative full-profile PR runs created on or after workload-contract epoch
v1 are required before a baseline median and p75 may be finalized. The current
count is 0/5. This document neither starts a goal nor claims a 15% improvement.
