# CI Performance Baseline

Canonical measurement contract and provisional ROB-444 cohort. This document
describes elapsed CI time only; it does not relax a gate, optimize a job, define
an enforcement goal, or claim an improvement.

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
- Selection is fail-closed: a run is comparable only when its complete profile
  fingerprint equals the policy fingerprint. Missing jobs, job-log fields, or
  later Jobs API pages therefore cannot silently produce a match.

The executable definition is
`scripts/ci/config/ci_performance_baseline_policy.php`. The advisory
`scripts/ci/measure_ci_performance_baseline.php` report repeats the method,
profile, selected run IDs, exclusions, raw samples, median, p75, critical path,
and fully observed phase rankings in
`ci-performance-baseline-latest.json`.

## Profile Fingerprint

Profile version 1 has fingerprint
`sha256:e0b633bf7a177491bc473807cc8a4fcbebad5daa8183e66b20caba6185e05c06`.
The hash uses canonical JSON with sorted object keys and ordered suite lists.
Baseline and after-runs must have this exact fingerprint.

Its inputs are:

- Consumer conclusions `success`: `typed-request-contracts`,
  `deep-check-bootstrap`, `deep-check-seed-snapshot`, `deep-runtime-suite`,
  `coverage-shard-unit`, `coverage-shard-integration`, `coverage-delta`,
  `api-contract-openapi`, `write-contract-booking`, `write-contract-api`,
  `booking-controller-flows`, `integration-smoke`, and
  `pdf-renderer-latency`.
- Consumer conclusion `skipped`: `heavy-job-duration-trends`, as expected on a
  pull request.
- Requested suites, in order: `api-contract-openapi`,
  `write-contract-booking`, `write-contract-api`,
  `booking-controller-flows`, `integration-smoke`.
- Active change flags: request contracts, deep bootstrap, deep runtime asset
  build, coverage, API contract, both write contracts, booking flows,
  integration smoke, LDAP guardrail, and PDF renderer latency; heavy-job
  trends are inactive on pull requests.
- Mode flags: `event=pull_request`, `run_attempt=1`, LDAP included,
  browser-bootstrap timeout `900`, and browser evidence `on-failure`.

Consumer conclusions and the successful `Build runtime JS assets` step come
from the Jobs API. Requested suites and runtime mode flags come from the
`deep-runtime-suite` job log. The Jobs API is read page by page at 100 jobs per
page before the profile is evaluated.

## Provisional Homogeneous Cohort

Captured on 2026-08-08 from GitHub Actions. Only four runs in the inspected,
unchanged workflow period are currently proven to match the complete profile.
All ended with `coverage-delta` as the latest job.

| Run | Created (UTC) | Branch | End-to-end | Initial queue |
| --- | --- | --- | ---: | ---: |
| [31224348182](https://github.com/robinbeier/forscherhaus-appointments/actions/runs/31224348182) | 2026-08-07 22:35 | `codex/rob-442-harden-config-permissions` | 8m 39s | 4s |
| [31223736063](https://github.com/robinbeier/forscherhaus-appointments/actions/runs/31223736063) | 2026-08-07 22:25 | `codex/rob-442-harden-config-permissions` | 10m 14s | 2s |
| [31222354173](https://github.com/robinbeier/forscherhaus-appointments/actions/runs/31222354173) | 2026-08-07 22:02 | `codex/rob-442-harden-config-permissions` | 8m 53s | 3s |
| [31220381778](https://github.com/robinbeier/forscherhaus-appointments/actions/runs/31220381778) | 2026-08-07 21:31 | `codex/rob-442-harden-config-permissions` | 8m 34s | 2s |

The three previously mixed `codex/provider-ui-smoke` runs are excluded: their
`api-contract-openapi` and `pdf-renderer-latency` consumers were skipped, so
their fingerprints differ. Draft, partial, cancelled, failed, and retried runs
are also ineligible. The cohort remains `insufficient_data`; it is not a final
baseline.

## Provisional Descriptive Values

These values describe the four homogeneous samples but are not an activatable
baseline until the minimum of five matching runs is reached.

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

## Critical Path And Largest Shares

The dependency graph and all four terminal observations identify:

`changes -> deep-check-bootstrap -> deep-check-seed-snapshot -> coverage-shard-integration -> coverage-delta`

`deep-runtime-suite` runs in parallel after `deep-check-bootstrap`; it is a
large job but did not terminate these runs.

The three largest fully observed phases all have a provisional median of
183.5s (34.9% of 526s):

| Phase | Median | p75 |
| --- | ---: | ---: |
| `deep-runtime-suite :: Start deep runtime services` | 3m 03.5s | 3m 04s |
| `coverage-shard-integration :: Start coverage shard services` | 3m 03.5s | 3m 10s |
| `deep-check-seed-snapshot :: Start seed snapshot services` | 3m 03.5s | 3m 05s |

These shares overlap because jobs run in parallel and must not be added. On the
critical path, the seed and integration service starts are the two dominant
serial phases.

## Queue And Cache Conditions

- npm cache: every provisional cohort run reported a primary-key hit in all six
  Node jobs.
- Composer download cache: every provisional cohort run reported a miss in
  `deep-check-bootstrap`.
- GitHub-hosted jobs start with fresh runner filesystems and Docker services.
  The dominant service-start phases therefore reflect a cold-runner condition,
  not a warm local stack.

A later comparison must report these conditions and may not discard slow or
cold-cache samples merely to improve a number.

## ROB-446 Activation Boundary

ROB-446 remains blocked in Backlog. Its goal cannot be activated from four
samples or from the earlier mixed cohort. After ROB-444 re-review, a fifth
representative full-profile PR run can satisfy the minimum; only then may the
homogeneous median and p75 be finalized. This document neither starts a goal
nor claims a 15% improvement.
