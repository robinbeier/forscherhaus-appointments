#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(git rev-parse --show-toplevel)"
cd "$ROOT_DIR"
source ./scripts/ci/docker_compose_helpers.sh

RUN_FULL_GATE=0
CI_DOCKER_LOG_PREFIX="symphony-pilot-checks"

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

echo_section() {
    echo
    echo "== $*"
}

cleanup_stack() {
    ci_docker_cleanup_stack
}

echo_section "Symphony pilot baseline checks"
echo "[symphony-pilot-checks] deterministic order: composer test -> optional full pre-pr gate"

trap cleanup_stack EXIT

echo_section "Start MySQL service"
ci_docker_compose up -d mysql
ci_docker_wait_for_mysql_readiness "symphony-pilot-checks"

echo_section "Composer test (CI parity)"
ci_docker_compose run --rm php-fpm composer test

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
