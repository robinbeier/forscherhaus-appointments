#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(git rev-parse --show-toplevel)"
cd "$ROOT_DIR"

echo "[symphony-pilot] Stopping repo-local Symphony service processes ..."
pkill -f "node $ROOT_DIR/tools/symphony/node_modules/.bin/tsx src/cli.ts --workflow $ROOT_DIR/WORKFLOW.md" >/dev/null 2>&1 || true
pkill -f "^codex app-server$" >/dev/null 2>&1 || true

echo "[symphony-pilot] Stopping docker compose dependencies ..."
docker compose down --remove-orphans
