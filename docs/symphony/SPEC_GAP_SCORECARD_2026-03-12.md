# Symphony SPEC Gap Scorecard (2026-03-12)

Purpose: record the Symphony SPEC gap matrix that guided closeout against the
upstream `SPEC.md`, document which older audit findings are now stale, and
capture the final closure criteria and outcome for a defensible `9.5/10`
implementation score.

Sources:

- Upstream SPEC: <https://github.com/openai/symphony/blob/main/SPEC.md>
- Checklist: `docs/symphony/SPEC_AUDIT_CHECKLIST.md`
- Historical audit: `docs/symphony/SPEC_AUDIT_REPORT_2026-03-07.md`
- Current implementation: `tools/symphony/`, `scripts/symphony/`, `WORKFLOW.md`

## Executive Summary

This scorecard was originally frozen before the last closeout PRs landed. It is
now updated to reflect the current post-closeout state.

Current scoring rule:

- `9.5/10` is achieved.
- The previously must-close gaps from `ROB-122`, `ROB-124`, and `ROB-125` are
  now closed with green evidence.
- The remaining status-surface work stays valuable, but does not block
  `9.5/10`, because upstream `3.1.7 Status Surface` is explicitly optional and
  Point 2 only requires operator-visible observability with structured logs as a
  minimum.

## Evidence Refresh

Evidence refreshed on 2026-03-12:

- `npm --prefix tools/symphony run build`
- `npm --prefix tools/symphony run test:conformance`
- `bash ./scripts/ci/run_symphony_pilot_checks.sh`
- `PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh`
- `SYMPHONY_LINEAR_API_KEY=fake SYMPHONY_LINEAR_PROJECT_SLUG=fake SYMPHONY_CODEX_COMMAND='codex app-server' npm --prefix tools/symphony run dev -- --check --workflow "$(git rev-parse --show-toplevel)/WORKFLOW.md"`

## Historical Findings That Are Now Stale

The following 2026-03-07 conclusions should no longer be treated as current:

| Historical finding | Current status |
| --- | --- |
| Worker is single-turn only | Closed. Same-thread continuation turns and `agent.max_turns` are implemented and covered by `src/orchestrator.test.ts`. |
| Reconciliation does not actively stop runs | Closed. Running issues are actively stopped on terminal, review-handoff, non-active, and stall cases. |
| No startup terminal workspace cleanup | Closed. Startup cleanup runs before polling begins. |
| `before_remove` is fatal | Closed. `before_remove` is now best-effort; only `before_run` remains fatal. |
| No positional workflow path / wrong default path behavior | Closed. Positional workflow path and `cwd/WORKFLOW.md` default are implemented. |
| State API lacks per-issue endpoint and `405` semantics | Closed. `GET /api/v1/<issue_identifier>` and method guards now exist. |

## Closed Gaps

| Order | Gap | Current status | Evidence | Follow-up |
| --- | --- | --- | --- | --- |
| 1 | HTTP extension config parity | Closed | `--port`, `server.port`, precedence, loopback default, docs and tests landed via `ROB-122`. | None for score closeout. |
| 2 | Snapshot aggregate contract | Closed | Snapshot now exposes `generated_at`, `counts`, and token/runtime `codex_totals` with double-counting protection via `ROB-124`. | None for score closeout. |
| 3 | Operational readiness proof | Closed | `run_symphony_pilot_checks.sh` is now a green, deterministic Symphony-readiness baseline, with `--with-full-gate` preserving the broader repo gate via `ROB-125`. | None for score closeout. |

## Can-Close Later Without Blocking 9.5/10

| Gap | Why it is not a 9.5 blocker today | Suggested follow-up shape |
| --- | --- | --- |
| Human-readable status surface at `/` | Upstream `3.1.7 Status Surface` is optional; structured logs plus the JSON state API already satisfy Point 2's minimum observability intent. | Deliver through `ROB-127` to `ROB-131` as operator-UX improvements. |
| Richer status-surface debugging depth | Per-issue JSON debug data already exists; richer health/event presentation is useful but not required for core conformance. | Add health indicators, recent-event views, and `/` dashboard polish later. |
| Multi-sink logging / sink-failure fallback | Structured operator-visible logging already exists; we are not yet running configurable multi-sink logging. | Add only if operator needs clearly outweigh the extra complexity. |
| Richer humanized event presentation | Helpful for operations, but not required for correctness if dashboard/API remain clear. | Fold into later UX polish after core closeout. |
| Broader template filter support beyond current strict roots | Worth documenting, but not currently the highest-value operator or conformance gap in this repo. | Revisit only if upstream skills or prompts require it. |

## Acceptance Rule For The Re-Score

The implementation is now defensibly re-scored at `9.5/10` because all of the
following are true:

1. The closeout gaps from `ROB-122`, `ROB-124`, and `ROB-125` are complete.
2. `tools/symphony` build, conformance, and workflow-check evidence are green.
3. The pilot baseline is green under its now explicit Symphony-readiness
   contract.
4. The refreshed audit in `docs/symphony/SPEC_AUDIT_REPORT_2026-03-12.md`
   replaces the stale 2026-03-07 conclusions with current evidence.

## Recommended Execution Order

Completed closeout sequence:

1. `ROB-123` froze the intermediate gap scorecard and marked the old audit as historical.
2. `ROB-122` closed the HTTP extension config parity gap.
3. `ROB-124` closed the snapshot aggregate contract gap.
4. `ROB-125` closed the bootability-vs-readiness gap.
5. `ROB-126` publishes the refreshed audit and final score.

Remaining optional follow-up sequence:

1. `ROB-127` to `ROB-131` deliver the human-readable status surface in small PR slices.
