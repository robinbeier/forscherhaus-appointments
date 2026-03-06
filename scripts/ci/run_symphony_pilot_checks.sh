#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(git rev-parse --show-toplevel)"
cd "$ROOT_DIR"

RUN_FULL_GATE=0
COMPOSE_CMD=()

usage() {
    cat <<'USAGE'
Usage: bash ./scripts/ci/run_symphony_pilot_checks.sh [--with-full-gate]

Runs deterministic local baseline checks for Symphony pilot issues.

Options:
  --with-full-gate   Also run PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh
  -h, --help         Show this help text
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --with-full-gate)
            RUN_FULL_GATE=1
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "[symphony-pilot-checks] Unknown option: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

require_cmd() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "[symphony-pilot-checks] Missing required command: $1" >&2
        exit 1
    fi
}

ensure_docker_compose() {
    if [[ "${#COMPOSE_CMD[@]}" -gt 0 ]]; then
        return
    fi

    require_cmd docker

    if docker compose version >/dev/null 2>&1; then
        COMPOSE_CMD=(docker compose)
    elif command -v docker-compose >/dev/null 2>&1; then
        COMPOSE_CMD=(docker-compose)
    else
        echo "[symphony-pilot-checks] docker compose command not found." >&2
        exit 1
    fi
}

run_compose() {
    ensure_docker_compose
    "${COMPOSE_CMD[@]}" "$@"
}

echo_section() {
    echo
    echo "== $*"
}

wait_for_mysql_readiness() {
    local max_attempts=60
    local attempt=1

    until run_compose exec -T mysql mysqladmin ping -h localhost -uroot -psecret --silent; do
        if [[ "$attempt" -ge "$max_attempts" ]]; then
            echo "[symphony-pilot-checks] MySQL root readiness timed out after ${max_attempts} attempts." >&2
            return 1
        fi
        attempt=$((attempt + 1))
        sleep 2
    done

    attempt=1
    until run_compose exec -T mysql mysql -uuser -ppassword -e "USE easyappointments; SELECT 1;" >/dev/null 2>&1; do
        if [[ "$attempt" -ge "$max_attempts" ]]; then
            echo "[symphony-pilot-checks] MySQL app-user readiness timed out after ${max_attempts} attempts." >&2
            return 1
        fi
        attempt=$((attempt + 1))
        sleep 2
    done
}

cleanup_stack() {
    run_compose down -v --remove-orphans >/dev/null 2>&1 || true
}

echo_section "Symphony pilot baseline checks"
echo "[symphony-pilot-checks] deterministic order: composer test -> optional full pre-pr gate"

trap cleanup_stack EXIT

echo_section "Start MySQL service"
run_compose up -d mysql
wait_for_mysql_readiness

echo_section "Composer test (CI parity)"
run_compose run --rm php-fpm composer test

if [[ "$RUN_FULL_GATE" -eq 1 ]]; then
    echo_section "Full pre-PR gate (coverage enabled)"
    PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh
else
    echo
    echo "[symphony-pilot-checks] Skipping full pre-pr gate. Use --with-full-gate to include it."
fi

cleanup_stack
trap - EXIT

echo
echo "[symphony-pilot-checks] All requested checks passed."
