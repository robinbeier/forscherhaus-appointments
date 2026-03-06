#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(git rev-parse --show-toplevel)"
cd "$ROOT_DIR"

ENV_FILE="$ROOT_DIR/.env.symphony.pilot"
WORKFLOW_PATH="$ROOT_DIR/WORKFLOW.md"
KEEP_STACK=0
SKIP_COMPOSE=0

usage() {
    cat <<'USAGE'
Usage: bash ./scripts/symphony/start_pilot.sh [options]

Starts Symphony pilot in a reproducible local mode.

Options:
  --env-file PATH    Env file to source (default: .env.symphony.pilot)
  --workflow PATH    Workflow file path (default: WORKFLOW.md)
  --keep-stack       Keep docker compose stack running after process exit
  --skip-compose     Do not start/stop docker compose dependencies
  -h, --help         Show this help text
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --env-file)
            if [[ $# -lt 2 ]]; then
                echo "[symphony-pilot] Missing value for --env-file" >&2
                exit 1
            fi
            ENV_FILE="$2"
            shift 2
            ;;
        --workflow)
            if [[ $# -lt 2 ]]; then
                echo "[symphony-pilot] Missing value for --workflow" >&2
                exit 1
            fi
            WORKFLOW_PATH="$2"
            shift 2
            ;;
        --keep-stack)
            KEEP_STACK=1
            shift
            ;;
        --skip-compose)
            SKIP_COMPOSE=1
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "[symphony-pilot] Unknown option: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

if [[ ! -f "$ENV_FILE" ]]; then
    echo "[symphony-pilot] Missing env file: $ENV_FILE" >&2
    echo "[symphony-pilot] Create it from .env.symphony.pilot.example first." >&2
    exit 1
fi

if [[ ! -f "$WORKFLOW_PATH" ]]; then
    echo "[symphony-pilot] Workflow file not found: $WORKFLOW_PATH" >&2
    exit 1
fi

set -a
source "$ENV_FILE"
set +a

require_env() {
    local name="$1"
    if [[ -z "${!name:-}" ]]; then
        echo "[symphony-pilot] Missing required env var: $name" >&2
        exit 1
    fi
}

require_env SYMPHONY_LINEAR_API_KEY
require_env SYMPHONY_LINEAR_PROJECT_SLUG
require_env SYMPHONY_CODEX_COMMAND

: "${SYMPHONY_PILOT_APPROVAL_POLICY:=on-request}"
: "${SYMPHONY_PILOT_SANDBOX_MODE:=workspace-write}"

if [[ "$SYMPHONY_PILOT_APPROVAL_POLICY" == "never" ]]; then
    echo "[symphony-pilot] Unsafe pilot policy: approval must not be 'never'." >&2
    exit 1
fi

if [[ "$SYMPHONY_PILOT_SANDBOX_MODE" == "danger-full-access" ]]; then
    echo "[symphony-pilot] Unsafe pilot policy: sandbox must not be 'danger-full-access'." >&2
    exit 1
fi

cleanup() {
    if [[ "$SKIP_COMPOSE" -eq 0 && "$KEEP_STACK" -eq 0 ]]; then
        docker compose down --remove-orphans >/dev/null 2>&1 || true
    fi
}

trap cleanup EXIT INT TERM

wait_for_mysql_readiness() {
    local max_attempts=60
    local attempt=1

    until docker compose exec -T mysql mysqladmin ping -h localhost -uroot -psecret --silent; do
        if [[ "$attempt" -ge "$max_attempts" ]]; then
            echo "[symphony-pilot] MySQL readiness timed out after ${max_attempts} attempts." >&2
            exit 1
        fi

        attempt=$((attempt + 1))
        sleep 2
    done
}

echo "[symphony-pilot] Workflow: $WORKFLOW_PATH"
echo "[symphony-pilot] Pilot approval policy: $SYMPHONY_PILOT_APPROVAL_POLICY"
echo "[symphony-pilot] Pilot sandbox mode: $SYMPHONY_PILOT_SANDBOX_MODE"

if [[ "$SKIP_COMPOSE" -eq 0 ]]; then
    echo "[symphony-pilot] Starting dependencies (mysql, php-fpm, nginx) ..."
    docker compose up -d mysql php-fpm nginx
    wait_for_mysql_readiness
fi

if [[ ! -d "$ROOT_DIR/tools/symphony/node_modules" ]]; then
    echo "[symphony-pilot] Installing tools/symphony dependencies ..."
    npm --prefix tools/symphony ci
fi

echo "[symphony-pilot] Validating workflow preflight ..."
npm --prefix tools/symphony run dev -- --check --workflow "$WORKFLOW_PATH"

echo "[symphony-pilot] Starting Symphony pilot service (Ctrl+C to stop) ..."
npm --prefix tools/symphony run dev -- --workflow "$WORKFLOW_PATH"
