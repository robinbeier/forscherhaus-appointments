# Agent Harness Index

Purpose: route humans and agents to the right steering source without repeating
the full command and policy matrix in every top-level document.

This file stays intentionally short. It is a map, not a second runbook.

## Start Here

- If you need local setup or service endpoints: read `README.md`.
- If you are an agent executing an issue end-to-end: read `WORKFLOW.md`.
- If you need the exhaustive local/CI command matrix and repo guardrails: read
  `AGENTS.md`.
- If you need architecture and ownership scope: read `docs/architecture-map.md`
  and `docs/ownership-map.md`.
- If you need Symphony runtime and pilot behavior: read
  `tools/symphony/README.md` and `docs/symphony/STAGING_PILOT_RUNBOOK.md`.

## Canonical Sources By Topic

| Topic | Canonical source | Why |
| --- | --- | --- |
| Local onboarding and quickstart | `README.md` | Operator-first entry point. |
| Agent runtime and issue-to-merge state model | `WORKFLOW.md` | Single source for active agent behavior. |
| Full local command matrix | `AGENTS.md` | Exhaustive commands, guardrails, and repo conventions. |
| Core pre-PR path | `scripts/ci/pre_pr_quick.sh`, `scripts/ci/pre_pr_full.sh` | Actual executable gate logic. |
| CI gate semantics and job wiring | `.github/workflows/ci.yml` | Ground truth for job triggers, blocking status, and artifacts. |
| Architecture boundaries | `docs/architecture-map.md` | Generated view of component boundaries. |
| Ownership scope | `docs/ownership-map.md` | Generated view of ownership and key paths. |
| Canonical architecture/ownership map source | `docs/maps/component_ownership_map.json` | Machine-readable source of truth. |
| Write-path contract harness | `docs/ci-write-contracts.md` | Focused contract-smoke reference. |
| Release gates | `docs/release-gate-zero-surprise.md`, `docs/release-gate-dashboard.md`, `docs/release-gate-booking-confirmation-pdf.md` | Dedicated gate behavior and usage. |
| Symphony pilot and state API | `tools/symphony/README.md`, `docs/symphony/STAGING_PILOT_RUNBOOK.md` | Runtime and operational guidance. |

## Validation Routing

- Small local confidence check:
  - `docker compose run --rm php-fpm composer test`
- Fast pre-push gate:
  - `bash ./scripts/ci/pre_pr_quick.sh`
- Full review-ready gate:
  - `PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh`
- Harness readiness score:
  - `composer check:agent-harness-readiness`
- Report date sanity:
  - `composer check:harness-report-dates`
- Scope-specific checks:
  - write-path contracts: `docs/ci-write-contracts.md`
  - architecture boundaries: `AGENTS.md`
  - Symphony pilot checks: `AGENTS.md`, `tools/symphony/README.md`

## Scheduled Hygiene

- Scheduled lightweight hygiene lives in `.github/workflows/hygiene.yml`.
- `agent-harness-readiness-latest.json` is the machine-readable scorecard; use it
  for the current readiness snapshot, not older narrative docs alone.
- `harness-report-date-sanity-latest.json` verifies that dated readiness/audit
  artifacts are not future-dated or internally mismatched.
- Reaction model:
  - `pass`: the harness signals and supporting docs are internally consistent.
  - `fail`: fix the listed drift or date violations before trusting the score as
    the current repo state.

## Editing Rules

- Change `README.md` when operator onboarding, quickstart, or local service
  usage changes.
- Change `WORKFLOW.md` when the agent state machine, workpad policy, or
  ticket-to-merge behavior changes.
- Change `AGENTS.md` when the repo guardrails or exhaustive command matrix
  changes.
- Change `.github/workflows/ci.yml` when CI truth changes; then update
  summaries in `README.md` or `AGENTS.md` only as needed.
- Change `docs/maps/component_ownership_map.json` when architecture or
  ownership scope changes; generated docs must follow from that source.

## Anti-Drift Rule

When the same command or policy appears in multiple top-level docs, keep only
one document as the canonical source and reduce the others to a short summary
plus link.
