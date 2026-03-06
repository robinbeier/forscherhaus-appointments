#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(git rev-parse --show-toplevel)"
cd "$ROOT_DIR"

echo "[symphony-pilot] Stopping docker compose dependencies ..."
docker compose down --remove-orphans
