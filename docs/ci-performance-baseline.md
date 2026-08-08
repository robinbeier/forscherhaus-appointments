# CI Performance Baseline

Canonical measurement contract and initial baseline for ROB-444. This document
describes elapsed CI time only; it does not relax a gate, optimize a job, or
define an enforcement goal.

## Comparison Contract

- Workflow: `.github/workflows/ci.yml`, event `pull_request`.
- Cohort: the seven most recent successful runs whose full-gate profile
  completed `changes`, deep bootstrap and seed, deep runtime, both coverage
  shards, and `coverage-delta` successfully.
- Exclusions: cancelled or failed runs, retries after attempt 1, and draft or
  changed-file profiles that skipped any required full-gate job.
- End-to-end: workflow `created_at` through the latest non-skipped job's
  `completed_at`.
- Initial queue: workflow `created_at` through the earliest non-skipped job's
  `started_at`. Per-job queue is `job.started_at - job.created_at`.
- Job and phase duration: GitHub Actions job or successful step `started_at`
  through `completed_at`.
- Statistics: median and nearest-rank p75. A comparable cohort needs at least
  five samples; the standard window contains seven.

The executable definition is
`scripts/ci/config/ci_performance_baseline_policy.php`. The advisory
`scripts/ci/measure_ci_performance_baseline.php` report repeats the method,
selected run IDs, exclusions, raw sample durations, median, p75, critical path,
and fully observed phase rankings in
`ci-performance-baseline-latest.json`.

## Initial Cohort

Captured on 2026-08-08 from GitHub Actions. All seven runs were attempt 1 and
ended with `coverage-delta` as the latest job.

| Run | Created (UTC) | Branch | End-to-end | Initial queue |
| --- | --- | --- | ---: | ---: |
| [31224348182](https://github.com/robinbeier/forscherhaus-appointments/actions/runs/31224348182) | 2026-08-07 22:35 | `codex/rob-442-harden-config-permissions` | 8m 39s | 4s |
| [31223736063](https://github.com/robinbeier/forscherhaus-appointments/actions/runs/31223736063) | 2026-08-07 22:25 | `codex/rob-442-harden-config-permissions` | 10m 14s | 2s |
| [31222354173](https://github.com/robinbeier/forscherhaus-appointments/actions/runs/31222354173) | 2026-08-07 22:02 | `codex/rob-442-harden-config-permissions` | 8m 53s | 3s |
| [31220381778](https://github.com/robinbeier/forscherhaus-appointments/actions/runs/31220381778) | 2026-08-07 21:31 | `codex/rob-442-harden-config-permissions` | 8m 34s | 2s |
| [30268089360](https://github.com/robinbeier/forscherhaus-appointments/actions/runs/30268089360) | 2026-07-27 12:57 | `codex/provider-ui-smoke` | 8m 41s | 3s |
| [30265064341](https://github.com/robinbeier/forscherhaus-appointments/actions/runs/30265064341) | 2026-07-27 12:13 | `codex/provider-ui-smoke` | 8m 27s | 2s |
| [30262227647](https://github.com/robinbeier/forscherhaus-appointments/actions/runs/30262227647) | 2026-07-27 11:30 | `codex/provider-ui-smoke` | 9m 04s | 33s |

Draft/core-only runs `31214444378` and the partial deep profile
`31218371684` were inspected but excluded. Cancelled or failed runs are not
eligible. Keeping these profiles separate prevents trigger or payload changes
from appearing as performance improvements.

## Baseline Values

| Metric | Median | p75 | Observed range |
| --- | ---: | ---: | ---: |
| Full-gate PR end-to-end | **8m 41s (521s)** | **9m 04s (544s)** | 8m 27s–10m 14s |
| Initial workflow queue | 3s | 4s | 2s–33s |
| Maximum ready-job queue within a run | 9s | 9s | 4s–11s |
| `deep-runtime-suite` | 4m 27s | 4m 31s | 4m 20s–5m 00s |
| `coverage-shard-integration` | 3m 37s | 3m 49s | 3m 23s–4m 59s |
| `deep-check-seed-snapshot` | 3m 35s | 3m 37s | 3m 26s–3m 37s |
| `deep-check-bootstrap` | 43s | 53s | 42s–54s |
| `coverage-shard-unit` | 29s | 32s | 28s–48s |
| `coverage-delta` | 14s | 20s | 11s–22s |

The 10m 14s maximum is not a queue outlier: its initial queue was 2s while
`coverage-shard-integration` took 4m 59s. The 33s initial-queue outlier ended
at the p75 value of 9m 04s. Queue is therefore visible but is not the dominant
baseline driver.

## Critical Path And Largest Shares

The workflow dependency graph and all seven terminal observations identify:

`changes -> deep-check-bootstrap -> deep-check-seed-snapshot -> coverage-shard-integration -> coverage-delta`

`deep-runtime-suite` runs in parallel after `deep-check-bootstrap`; it is a
large job but did not terminate any baseline run.

The three largest fully observed phases were:

| Phase | Median | p75 | Share of 521s median |
| --- | ---: | ---: | ---: |
| `deep-runtime-suite :: Start deep runtime services` | 3m 07s | 3m 13s | 35.9% |
| `coverage-shard-integration :: Start coverage shard services` | 3m 04s | 3m 12s | 35.3% |
| `deep-check-seed-snapshot :: Start seed snapshot services` | 3m 02s | 3m 05s | 34.9% |

These shares overlap because jobs run in parallel and must not be added. On the
critical path, the seed and integration service starts are the two dominant
serial phases.

## Queue And Cache Conditions

- npm cache: every cohort run reported a primary-key hit in all six Node jobs.
- Composer download cache: every cohort run reported a miss in
  `deep-check-bootstrap`.
- Runner filesystem and Docker services: GitHub-hosted jobs start fresh. The
  three dominant service-start phases above therefore represent the observed
  cold-runner condition rather than a warm local stack.

This cache mix is part of the baseline. A later comparison must report cache
conditions and may not discard slow or cold-cache samples merely to improve a
number.

## ROB-446 Activation Boundary

The measurement inputs for ROB-446 are now concrete: compare at least five
full-gate after-runs against median 521s and p75 544s under this same contract.
ROB-446 nevertheless remains in Backlog until ROB-444 is merged and completed;
this baseline does not start a goal or claim a 15% improvement.
