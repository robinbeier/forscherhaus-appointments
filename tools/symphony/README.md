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

## Structure

```text
tools/symphony/
  src/
    cli.ts        # Entry point, signal handling
    logger.ts     # Structured log output
    options.ts    # CLI option parsing
    service.ts    # Start/stop lifecycle
    workflow.ts   # Workflow path resolution + file validation
    options.test.ts
  package.json
  tsconfig.json
```
