# Symphony Pilot Scaffold (`tools/symphony`)

This directory contains the Sprint-1 scaffold for a local Symphony sidecar
service.

## Prerequisites

-   Node.js 20+

## Install

```bash
cd tools/symphony
npm install
```

## Commands

```bash
# Compile TypeScript
npm run build

# Validate bootstrap + workflow path and exit
# Example:
# SYMPHONY_LINEAR_API_KEY=token SYMPHONY_LINEAR_PROJECT_SLUG=project \
# SYMPHONY_CODEX_COMMAND="codex app-server" npm run dev -- --check
npm run dev -- --check

# Start compiled service (runs until SIGINT/SIGTERM)
npm run build
npm run start

# Tests
npm test

# Core conformance matrix (SPEC 17.1 - 17.5)
npm run test:conformance
```

## Pilot Start/Stop (Repo Root)

```bash
# 1) Env file anlegen (keine Secrets committen)
cp .env.symphony.pilot.example .env.symphony.pilot

# 2) Werte setzen:
#    - SYMPHONY_LINEAR_API_KEY
#    - SYMPHONY_LINEAR_PROJECT_SLUG
#    - SYMPHONY_CODEX_COMMAND

# 3) Pilot starten (foreground, Ctrl+C beendet und raeumt Stack auf)
bash ./scripts/symphony/start_pilot.sh

# Optional: Stack getrennt stoppen
bash ./scripts/symphony/stop_pilot.sh

# Optional: Soak gate against state API (24h default in runbook)
python3 ./scripts/symphony/run_soak_gate.py \
  --state-url http://127.0.0.1:8787/api/v1/state \
  --duration-seconds 86400 --poll-seconds 60
```

Pilot guardrails im Startskript:

-   `SYMPHONY_PILOT_APPROVAL_POLICY` default: `on-request`
-   `SYMPHONY_PILOT_SANDBOX_MODE` default: `workspace-write`
-   `SYMPHONY_WORKTREE_BASE_REF` default: `origin/main`
-   `approval_policy=never` oder `sandbox_mode=danger-full-access` werden fuer
    den Pilot abgelehnt.
-   Beim Start wird `SYMPHONY_CODEX_COMMAND` mit
    `CODEX_APPROVAL_POLICY`/`CODEX_SANDBOX_MODE` aus den Pilot-Policies
    gepraefixt, damit Guardrails im realen Launch-Command anliegen.
-   Unabhaengig vom Startskript setzt Symphony im Runtime-Kern sichere
    Defaults, wenn `codex.approval_policy`, `codex.thread_sandbox` oder
    `codex.turn_sandbox_policy` im Workflow fehlen.
-   Optionale Observability API per `.env.symphony.pilot`:
    -   `SYMPHONY_STATE_API_ENABLED=1`
    -   `SYMPHONY_STATE_API_HOST=127.0.0.1`
    -   `SYMPHONY_STATE_API_PORT=8787`

## CLI options

-   `<path>`: positional custom workflow file path.
-   `--workflow <path>` or `--workflow=<path>`: custom workflow file path.
-   `--check`: validate bootstrap/configuration and exit.

When no workflow path is supplied, the CLI defaults to:

`<cwd>/WORKFLOW.md`

## Workflow config contract (`WORKFLOW.md`)

The loader parses YAML front matter and a prompt body:

```yaml
---
tracker:
    kind: linear
    endpoint: https://api.linear.app/graphql
    api_key: $SYMPHONY_LINEAR_API_KEY
    project_slug: $SYMPHONY_LINEAR_PROJECT_SLUG
    review_state_name: In Review
    merge_state_name: Ready to Merge
    active_states:
        - Todo
        - In Progress
        - Rework
        - Ready to Merge
    terminal_states:
        - Done
        - Closed
        - Cancelled
        - Canceled
        - Duplicate
polling:
    interval_ms: 5000
    max_candidates: 20
workspace:
    root: /tmp/symphony_workspaces
    keep_terminal_workspaces: false
hooks:
    timeout_ms: 30000
    before_run:
        - bash $SYMPHONY_REPO_ROOT/scripts/symphony/ensure_issue_worktree.sh
    before_remove:
        - bash $SYMPHONY_REPO_ROOT/scripts/symphony/remove_issue_worktree.sh
agent:
    max_concurrent_agents: 1
    max_attempts: 2
    max_turns: 20
    max_retry_backoff_ms: 300000
    max_concurrent_agents_by_state: {}
    commit_required_states:
        - Todo
        - In Progress
        - Rework
codex:
    command: $SYMPHONY_CODEX_COMMAND
    read_timeout_ms: 120000
    turn_timeout_ms: 3600000
    stall_timeout_ms: 300000
---
Issue {{issue.identifier}} (attempt {{attempt}})
```

Key front matter fields beyond the minimal scaffold:

-   `tracker.active_states`, `tracker.terminal_states`
-   `tracker.kind`, `tracker.endpoint`
-   `polling.interval_ms`, `polling.max_candidates`
-   `workspace.root`, `workspace.keep_terminal_workspaces`
-   `hooks.timeout_ms`, `hooks.after_create`, `hooks.before_run`,
    `hooks.after_run`, `hooks.before_remove`
-   `agent.max_concurrent_agents`, `agent.max_attempts`, `agent.max_turns`,
    `agent.max_retry_backoff_ms`, `agent.max_concurrent_agents_by_state`,
    `agent.commit_required_states`
-   `codex.command`, `codex.read_timeout_ms`, `codex.turn_timeout_ms`,
    `codex.stall_timeout_ms`, `codex.approval_policy`,
    `codex.thread_sandbox`, `codex.turn_sandbox_policy`

Key behavior:

-   `$VAR` environment resolution in front matter values
-   `~` home expansion in path values
-   strict prompt template roots (`issue`, `attempt`)
-   normalized prompt issue payload includes `description`, `branch_name`,
    `url`, structured `blocked_by`, `blocked_by_identifiers`, and compact
    derived prompt fields such as `description_or_default` and
    `workpad_comment_body_or_default`
-   keep the first-turn prompt lean; prefer durable instructions in skills and a
    compact workpad over repeating long issue snapshots in the prompt body
-   inject lightweight thread-start developer instructions so Codex starts with
    workpad-first, one-milestone-per-turn discipline even when repo skills and
    workflow text evolve
-   before the first Codex turn, the Linear tracker can deterministically
    prepare the issue for execution by moving `Todo` into `In Progress` and
    ensuring there is exactly one reusable `## Codex Workpad` anchor comment
-   first dispatch passes `attempt = null`; retries and continuation runs pass
    `attempt >= 1`
-   preflight checks for `tracker.api_key`, `tracker.project_slug`,
    `codex.command`
-   dynamic reload on file change with last-known-good fallback when a reload is
    invalid
-   orchestrator tick loop with bounded concurrency, Todo-blocker filtering,
    per-state concurrency caps, and in-memory retry queue (`1s` continuation
    retry + `10s * 2^(attempt-1)` failure backoff capped by
    `agent.max_retry_backoff_ms`)
-   one Symphony worker can execute multiple Codex turns on the same
    app-server thread before it yields to a continuation retry
-   continuation prompts explicitly bias toward resuming from the current
    workspace/workpad state and finishing validation/commit/publish work when
    the needed diff already exists locally
-   if codex policy fields are omitted, safe defaults are applied in-core:
    reject approval policy, `workspace-write` thread sandbox, and a
    `workspaceWrite` turn sandbox rooted at the current issue workspace with
    `readOnlyAccess=fullAccess`, `networkAccess=false`, and default tmp flags
-   structured issue logs include `issue_id`, `issue_identifier`, `session_id`
-   runtime snapshot model includes `running`, `retrying`, `codex_totals`,
    `rate_limits`, plus per-runner runtime seconds, idle seconds, last
    humanized activity, and context-window headroom derived from token updates
-   optional state endpoints:
    -   `GET /api/v1/state`
    -   `GET /api/v1/<issue_identifier>`
    -   `POST /api/v1/refresh`
-   startup cleanup removes local workspaces for issues already in configured
    terminal tracker states
-   reconciliation actively stops running sessions when issues leave active
    states or stall longer than `codex.stall_timeout_ms` plus a small grace
-   under safe defaults, app-server approval callbacks fail the run with
    `approval_required` instead of being silently auto-approved
-   command/file approvals and MCP tool approval prompts are auto-approved only
    when `codex.approval_policy` is explicitly set to `never`
-   `linear_graphql` dynamic tool calls are supported for Linear-backed
    sessions, advertised during thread startup, syntax-validated before
    transport, and unsupported or invalid tool calls return a tool failure
    response while the session continues instead of forcing `input_required`
-   `linear_graphql` accepts multi-operation documents and forwards them
    unchanged to Linear, which may still require an explicit operation name
-   `npm --prefix tools/symphony run pr-body-check -- --file <body.md>` lints a
    rendered PR body against the repo template before `gh pr create/update`
-   repo-specific pilots can prepare per-issue git worktrees via `before_run`
    hooks and remove registrations via `before_remove` hooks
-   per-issue worktree setup refreshes `origin`, defaults to `origin/main`, and
    recreates stale local issue branches when their previous PR was already
    closed or merged
-   completed runs require committed local progress only in configured
    `agent.commit_required_states`; review/merge runs in states such as
    `In Review`, `Ready to Merge`, or terminal states can complete without a new
    local commit as long as the workspace stays clean
-   `before_run` hook failures are fatal; `after_run` and `before_remove` are
    best effort and log errors without aborting cleanup
-   terminal issues are cleaned up automatically unless
    `workspace.keep_terminal_workspaces=true`; failed, stalled, timed-out, or
    continuation-pending runs preserve their worktree for debugging/continuation
-   hook entries are executed as shell commands; for repo scripts prefer
    `bash /absolute/path/to/script.sh` so the workflow does not depend on the
    executable bit being preserved
-   the intended full-agent workflow uses non-standard Linear states
    `In Review`, `Rework`, and `Ready to Merge`, plus repo-local skills under
    `.codex/skills/` for commit/pull/push/land/linear workpad operations
    and a single compact `## Codex Workpad` comment as the resumability anchor

## Structure

```text
tools/symphony/
  src/
    cli.ts        # Entry point, signal handling
    logger.ts     # Structured log output
    options.ts    # CLI option parsing
    service.ts    # Start/stop lifecycle + poll scheduler
    state-server.ts  # Optional /api/v1/state and /api/v1/refresh layer
    orchestrator.ts  # Poll/dispatch/reconcile/retry core
    linear-tracker.ts  # Linear GraphQL adapter + linear_graphql tool bridge
    workspace-manager.ts  # Workspace safety + hook lifecycle
    app-server-client.ts  # Codex app-server launch/stream client
    test-profiles.ts  # Deterministic fake Linear/Codex profiles for tests
    workflow.ts   # Workflow loader + typed config + reload
    template.ts   # Strict prompt template rendering
    options.test.ts
    orchestrator.test.ts
    app-server-client.test.ts
    state-server.test.ts
    test-profiles.test.ts
    linear-tracker.test.ts
    workspace-manager.test.ts
    template.test.ts
    workflow-config.test.ts
  package.json
  tsconfig.json
  CONFORMANCE_MATRIX.md
```
