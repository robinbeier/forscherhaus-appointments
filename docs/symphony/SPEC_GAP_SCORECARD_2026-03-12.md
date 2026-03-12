# Symphony SPEC Gap Scorecard (2026-03-12)

Purpose: freeze the current Symphony SPEC gap matrix against the upstream
`SPEC.md`, document which older audit findings are now stale, and define the
minimum closure criteria for a defensible `9.5/10` implementation score.

Sources:

- Upstream SPEC: <https://github.com/openai/symphony/blob/main/SPEC.md>
- Checklist: `docs/symphony/SPEC_AUDIT_CHECKLIST.md`
- Historical audit: `docs/symphony/SPEC_AUDIT_REPORT_2026-03-07.md`
- Current implementation: `tools/symphony/`, `scripts/symphony/`, `WORKFLOW.md`

## Executive Summary

The 2026-03-07 audit is no longer an accurate snapshot of the current
implementation. Several previously critical gaps have already been closed in
code and tests, so the repo should now be evaluated against a narrower set of
remaining must-close items.

Working rule for the next scoring round:

- `9.5/10` is the target closeout score.
- That score is justified only when every item in the "Must-Close Gaps" table
  is complete and the updated audit evidence stays green.
- Items in the "Can-Close Later" table may remain open without blocking the
  `9.5/10` target, as long as they do not create a new critical or
  operator-facing regression.

## Evidence Refresh

Evidence refreshed on 2026-03-11 / 2026-03-12:

- `npm --prefix tools/symphony run build`
- `npm --prefix tools/symphony run test:conformance`
- `SYMPHONY_LINEAR_API_KEY=fake SYMPHONY_LINEAR_PROJECT_SLUG=fake SYMPHONY_CODEX_COMMAND='codex app-server' npm --prefix tools/symphony run dev -- --check --workflow "$(git rev-parse --show-toplevel)/WORKFLOW.md"`
- Deterministic fake tracker / fake codex orchestrator run (local ad-hoc check)
- `bash ./scripts/ci/run_symphony_pilot_checks.sh` currently red in the repo-wide
  PHPUnit path, which affects operational readiness but not the `tools/symphony`
  core-conformance test status

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

## Must-Close Gaps For 9.5/10

| Order | Gap | Why it still blocks `9.5/10` | Closure criteria | Follow-up |
| --- | --- | --- | --- | --- |
| 1 | HTTP extension config parity | The optional HTTP surface is not enabled in the SPEC-preferred way yet (`--port`, `server.port`, precedence, loopback default). | CLI `--port` works, `server.port` works, CLI wins over workflow config, listener behavior is documented and tested. | `ROB-122` |
| 2 | Snapshot aggregate contract | The current snapshot exposes useful per-run data, but aggregate `codex_totals` still behave like outcome counters instead of token/runtime totals. | Snapshot exposes `generated_at`, `counts`, `input_tokens`, `output_tokens`, `total_tokens`, `seconds_running`, with tests preventing double-counting. | `ROB-124` |
| 3 | Human-readable status surface | The current state API is machine-readable only; there is no operator-facing dashboard at `/`. | A dashboard or equivalent human-readable view exists at `/`, is driven from orchestrator state only, and is covered by smoke tests. | `ROB-121` / `ROB-127` to `ROB-131` |
| 4 | Status-surface debugging depth | The issue debug surface still needs explicit health/error indicators, recent-event visibility, and SPEC-aligned timeout/unavailable surfacing. | `/api/v1/<issue_identifier>` and the dashboard clearly surface health/error/recent-event context without affecting correctness. | `ROB-121` / `ROB-130` |
| 5 | Operational readiness proof | The service can boot, but the repo's current pilot baseline is not yet green, so Point 2 cannot be claimed as fully operational end-to-end. | The intended pilot baseline path is green or intentionally narrowed with explicit documented scope and rationale. | `ROB-125` |

## Can-Close Later Without Blocking 9.5/10

| Gap | Why it is not a 9.5 blocker today | Suggested follow-up shape |
| --- | --- | --- |
| Multi-sink logging / sink-failure fallback | Structured operator-visible logging already exists; we are not yet running configurable multi-sink logging. | Add only if operator needs clearly outweigh the extra complexity. |
| Richer humanized event presentation | Helpful for operations, but not required for correctness if dashboard/API remain clear. | Fold into later UX polish after core closeout. |
| Broader template filter support beyond current strict roots | Worth documenting, but not currently the highest-value operator or conformance gap in this repo. | Revisit only if upstream skills or prompts require it. |

## Acceptance Rule For The Re-Score

The implementation may be re-scored at `9.5/10` only when all of the following
are true:

1. Every must-close gap above is complete.
2. `tools/symphony` build, conformance, and workflow-check evidence are green.
3. The repo-operational pilot baseline is green or intentionally narrowed with
   written acceptance from the updated audit.
4. The refreshed audit replaces the stale 2026-03-07 conclusions with current
   evidence.

## Recommended Execution Order

Low risk / high reward first:

1. `ROB-123` freeze this scorecard and make the stale audit obviously historical.
2. `ROB-122` close the HTTP extension config parity gap.
3. `ROB-124` close the snapshot aggregate contract gap.
4. `ROB-125` close the bootability-vs-readiness gap.
5. `ROB-127` to `ROB-131` deliver the status surface in small PR slices.
6. `ROB-126` publish the refreshed audit and final score.
