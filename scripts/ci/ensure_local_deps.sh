#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
cd "$ROOT_DIR"

FORCE_INSTALL=0
ROOT_NODE_MINIMUM_VERSION=20.19.0

case "${1:-}" in
--force)
    FORCE_INSTALL=1
    ;;
"")
    ;;
*)
    echo "[deps] Unknown option: $1" >&2
    exit 1
    ;;
esac

require_cmd() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "[deps] Missing required command: $1" >&2
        exit 1
    fi
}

run_composer_install() {
    require_cmd composer
    echo "[deps] Running composer install ..."
    composer install --no-interaction --prefer-dist
}

run_npm_install() {
    require_cmd node
    require_cmd npm
    bash ./scripts/ci/require_node_minimum.sh "$ROOT_NODE_MINIMUM_VERSION" "deps"
    echo "[deps] Running npm install ..."

    if [[ -f package-lock.json ]]; then
        npm ci --no-audit --no-fund
    else
        npm install --no-audit --no-fund
    fi
}

if [[ "$FORCE_INSTALL" -eq 1 ]]; then
    run_composer_install
    run_npm_install
    echo "[deps] Local dependencies bootstrapped (forced)."
    exit 0
fi

installed_any=0

if [[ ! -d vendor ]]; then
    echo "[deps] Missing vendor/; bootstrapping ..."
    run_composer_install
    installed_any=1
fi

if [[ ! -d node_modules ]]; then
    echo "[deps] Missing node_modules/; bootstrapping ..."
    run_npm_install
    installed_any=1
fi

if [[ "$installed_any" -eq 1 ]]; then
    echo "[deps] Local dependencies bootstrapped."
fi
