# Symphony Staging Pilot Runbook

## Purpose

Run the Symphony pilot in staging with bounded concurrency (`1-2`) and enforce
a 24h soak gate before any production release decision.

## Preconditions

1. Staging environment is reachable.
2. A pilot env file exists (`.env.symphony.pilot`) with:
   - `SYMPHONY_LINEAR_API_KEY`
   - `SYMPHONY_LINEAR_PROJECT_SLUG`
   - `SYMPHONY_CODEX_COMMAND`
   - either `server.port` in `WORKFLOW.md`, or `--port <port>` on the
     Symphony CLI, or the legacy fallback `SYMPHONY_STATE_API_ENABLED=1`
   - For local pilot runs, set `SYMPHONY_WORKTREE_BASE_REF=origin/main` so
     per-issue worktrees stay isolated from the operator's local `HEAD`.
3. `WORKFLOW.md` uses pilot-safe settings (`max_concurrent: 1` or `2`).
4. Rollback owner and incident owner are named before start.
5. For local terminal-first runs with `--tui`, use an interactive TTY.

## Deterministic Baseline vs Live Boot

These commands answer different questions and should not be conflated:

```bash
# Deterministic Symphony pilot readiness baseline
bash ./scripts/ci/run_symphony_pilot_checks.sh

# Optional: add the full repo review/release gate on top
bash ./scripts/ci/run_symphony_pilot_checks.sh --with-full-gate

# Live pilot boot smoke with real env + local dependencies
bash ./scripts/symphony/start_pilot.sh
```

Interpretation:

- `run_symphony_pilot_checks.sh` proves Symphony-local pilot readiness:
  build, conformance, workflow preflight, and a sample-backed soak-gate path.
- `start_pilot.sh` proves bootability of the live pilot path with real
  operator env and local services.
- repo-wide PHPUnit or other non-Symphony checks belong to the optional
  `--with-full-gate` path, not to the deterministic Symphony baseline.

## Start Pilot

```bash
# From repo root
bash ./scripts/symphony/start_pilot.sh
```

The service exposes optional state endpoints when enabled:

- `GET /`
- `GET /api/v1/state`
- `GET /api/v1/<issue_identifier>`
- `POST /api/v1/refresh`

Binding precedence for the optional HTTP surface:

1. `--port <port>` on the Symphony CLI
2. `server.port` in `WORKFLOW.md`
3. legacy `SYMPHONY_STATE_API_ENABLED` / `SYMPHONY_STATE_API_PORT`

Default binding remains `127.0.0.1`, and changing host/port settings requires
restarting Symphony.

Optional local operator route:

```bash
npm --prefix tools/symphony run dev -- --tui --port 8787
```

In `--tui` mode, info logs are suppressed to preserve readability of the live
terminal surface; warn/error events still emit as structured `stderr` lines.

## Quick Local Health Check

Use this quick check to confirm the operator surface is reachable, exposes
health and recent events, and can refresh once on demand:

```bash
curl -fsS http://127.0.0.1:8787/api/v1/state \
  | jq '{health: .health, recent_events: .recent_events[:5], counts: .counts}'
ISSUE_IDENTIFIER=ROB-123
curl -fsS "http://127.0.0.1:8787/api/v1/${ISSUE_IDENTIFIER}" \
  | jq '{status, health: .health, recent_events: .recent_events[:5]}'
curl -fsS -X POST http://127.0.0.1:8787/api/v1/refresh
```

Also visit `http://127.0.0.1:8787/` in a browser during pilot bring-up.

Manual `--tui` smoke path:

1. Start Symphony with `--tui` in a real terminal.
2. Confirm the screen shows `SYMPHONY STATUS`, `RUNNING`, and `BACKOFF QUEUE`.
3. Trigger one refresh via `POST /api/v1/refresh` and verify the view redraws.
4. Trigger one synthetic warning/error path (or observe an existing one) and
   confirm the warning/error is visible without permanently corrupting the TUI
   layout on the next refresh.
5. Stop with `Ctrl+C`.

Expected operator signals:

- `/` shows overall health, rate limits, running/retrying counts, and recent
  events without requiring JSON inspection.
- `/api/v1/state` returns top-level `health` and `recent_events`.
- `/api/v1/<issue_identifier>` returns issue-local `health` and
  `recent_events` for the selected issue.
- The status surface remains observability-only; if it disagrees with behavior,
  debug the underlying orchestration state instead of treating the dashboard as
  a source of truth.

## 24h Soak Gate

Run the soak gate against the state endpoint:

```bash
python3 ./scripts/symphony/run_soak_gate.py \
  --state-url http://127.0.0.1:8787/api/v1/state \
  --duration-seconds 86400 \
  --poll-seconds 60 \
  --stuck-threshold-polls 30 \
  --max-running 2 \
  --max-retrying 50 \
  --output-json storage/logs/symphony/soak-gate-staging-<UTC>.json
```

## Exit Criteria (Go)

- Soak report verdict is `pass`.
- No stuck sessions detected.
- No runaway retry queue (`maxRetrying` within threshold).
- No critical incident in staging during soak window.

## Rollback / No-Go Criteria

Trigger immediate rollback to pre-pilot operation if one of these occurs:

- soak verdict `fail`
- repeated `turn_timeout` / `response_timeout` spikes
- stuck sessions above threshold
- retry queue growth that does not converge
- unsafe behavior or unclear operator state

Rollback actions:

1. Stop pilot:
   ```bash
   bash ./scripts/symphony/stop_pilot.sh
   ```
2. Keep Symphony disabled for deployment decision.
3. Open follow-up issues with logs/report links.

## Incident Triage Checklist

1. Capture current state:
   ```bash
   curl -fsS http://127.0.0.1:8787/api/v1/state | jq
   ```
2. Inspect the human-readable dashboard and the relevant issue payload:
   ```bash
   ISSUE_IDENTIFIER=ROB-123
   curl -fsS "http://127.0.0.1:8787/api/v1/${ISSUE_IDENTIFIER}" | jq
   ```
   Also inspect `http://127.0.0.1:8787/` in a browser.
3. Trigger one manual refresh:
   ```bash
   curl -fsS -X POST http://127.0.0.1:8787/api/v1/refresh
   ```
4. Collect latest soak report + Symphony logs.
5. Classify incident:
   - tracker/API issue
   - Codex runtime issue
   - workspace/hook issue
   - orchestration/retry issue
6. Decide: continue pilot, rollback, or pause for fix.
