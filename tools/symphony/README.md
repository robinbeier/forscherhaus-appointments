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
# SYMPHONY_CODEX_COMMAND="codex --app-server" npm run dev -- --check
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
```

Pilot guardrails im Startskript:

-   `SYMPHONY_PILOT_APPROVAL_POLICY` default: `on-request`
-   `SYMPHONY_PILOT_SANDBOX_MODE` default: `workspace-write`
-   `approval_policy=never` oder `sandbox_mode=danger-full-access` werden fuer
    den Pilot abgelehnt.
-   Beim Start wird `SYMPHONY_CODEX_COMMAND` mit
    `CODEX_APPROVAL_POLICY`/`CODEX_SANDBOX_MODE` aus den Pilot-Policies
    gepraefixt, damit Guardrails im realen Launch-Command anliegen.
-   Optionale Observability API per `.env.symphony.pilot`:
    -   `SYMPHONY_STATE_API_ENABLED=1`
    -   `SYMPHONY_STATE_API_HOST=127.0.0.1`
    -   `SYMPHONY_STATE_API_PORT=8787`

## CLI options

-   `--workflow <path>` or `--workflow=<path>`: custom workflow file path.
-   `--check`: validate bootstrap/configuration and exit.

When no workflow path is supplied, the CLI defaults to:

`<repo-root>/WORKFLOW.md`

## Workflow config contract (`WORKFLOW.md`)

The loader parses YAML front matter and a prompt body:

```yaml
---
tracker:
    provider: linear
    api_key: $SYMPHONY_LINEAR_API_KEY
    project_slug: $SYMPHONY_LINEAR_PROJECT_SLUG
polling:
    interval_ms: 60000
workspace:
    root: ~/.symphony/workspaces
hooks:
    timeout_ms: 30000
agent:
    max_concurrent: 1
codex:
    command: $SYMPHONY_CODEX_COMMAND
---
Issue {{issue.identifier}} (attempt {{attempt}})
```

Supported sections: `tracker`, `polling`, `workspace`, `hooks`, `agent`,
`codex`.

Key behavior:

-   `$VAR` environment resolution in front matter values
-   `~` home expansion in path values
-   strict prompt template roots (`issue`, `attempt`)
-   preflight checks for `tracker.api_key`, `tracker.project_slug`,
    `codex.command`
-   dynamic reload on file change with last-known-good fallback when a reload is
    invalid
-   orchestrator tick loop with bounded concurrency, Todo-blocker filtering,
    and in-memory retry queue (`1s` continuation retry + exponential backoff)
-   structured issue logs include `issue_id`, `issue_identifier`, `session_id`
-   runtime snapshot model includes `running`, `retrying`, `codex_totals`,
    `rate_limits`
-   optional state endpoints:
    -   `GET /api/v1/state`
    -   `POST /api/v1/refresh`

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
    linear-tracker.ts  # Linear GraphQL adapter (read-only)
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
