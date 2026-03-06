# ROB-17 Pilot Report (2026-03-06)

## Scope

- Issue: `ROB-17`
- Window: `2026-03-06` (prepared), `24h staging soak` pending execution
- Target: Symphony staging pilot gate decision (`Go` / `No-Go`)

## What Was Executed

1. Staging pilot runbook created:
   - [`docs/symphony/STAGING_PILOT_RUNBOOK.md`](./STAGING_PILOT_RUNBOOK.md)
2. Deterministic soak gate script added:
   - [`scripts/symphony/run_soak_gate.py`](/Users/robinbeier/Developers/forscherhaus-appointments/scripts/symphony/run_soak_gate.py)
3. Dry-run validation executed against sample snapshot fixture:
   - command:
     ```bash
     python3 scripts/symphony/run_soak_gate.py \
       --sample-file tools/symphony/fixtures/state-snapshot.sample.json \
       --duration-seconds 3 --poll-seconds 1 \
       --output-json storage/logs/symphony/soak-gate-sample-20260306.json
     ```
   - result: `PASS` (script operational)

## Findings

1. Tooling and runbook are ready for a real staging soak.
2. A real `24h` staging run was **not executed in this environment** on
   `2026-03-06` because staging endpoint/access context was not available here.

## Residual Risks

1. Runtime behavior under real staging traffic remains unverified until the
   24h soak is run with live staging connectivity.
2. Operational thresholds (`maxRetrying`, stuck threshold) may need calibration
   after first real soak.

## Decision Recommendation

- Current recommendation: **No-Go for production enablement** until the actual
  staging 24h soak has been executed and passes.
- Next step: execute the runbook soak command on staging and attach the
  resulting JSON report.

## Decision Template (for final staging run)

- Soak window start (UTC):
- Soak window end (UTC):
- Soak verdict (`pass` / `fail`):
- Max running observed:
- Max retrying observed:
- Stuck sessions detected (`yes`/`no`):
- Critical incidents (`yes`/`no` + links):
- Final recommendation (`Go` / `No-Go`):
