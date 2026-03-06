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
```

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

## Structure

```text
tools/symphony/
  src/
    cli.ts        # Entry point, signal handling
    logger.ts     # Structured log output
    options.ts    # CLI option parsing
    service.ts    # Start/stop lifecycle
    linear-tracker.ts  # Linear GraphQL adapter (read-only)
    workflow.ts   # Workflow loader + typed config + reload
    template.ts   # Strict prompt template rendering
    options.test.ts
    linear-tracker.test.ts
    template.test.ts
    workflow-config.test.ts
  package.json
  tsconfig.json
```
