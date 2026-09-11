#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(git rev-parse --show-toplevel)"
cd "$ROOT_DIR"
if [[ "$#" -lt 2 ]]; then
    echo "Usage: $0 SERVICE COMMAND [ARG ...] (php-fpm or pdf-renderer; no dependencies)" >&2
    exit 2
fi
case "$1" in php-fpm|pdf-renderer) ;; *) echo "Unsupported focused-test service." >&2; exit 2 ;; esac
if [[ -n "${CI_DOCKER_COMPOSE_PROJECT_NAME:-}" || -n "${COMPOSE_PROJECT_NAME:-}" || -n "${COMPOSE_FILE:-}" || -n "${EA_MYSQL_DATA_PATH:-}" || -n "${EA_LOCAL_CI_COMPOSE_OVERRIDE_PATH:-}" || "${EA_LOCAL_CI_PORTLESS_COMPOSE:-1}" != "1" ]]; then
    echo "[focused-test] Refusing caller-supplied Compose project, configuration or data path." >&2
    exit 2
fi
source ./scripts/ci/docker_compose_helpers.sh
CI_DOCKER_LOG_PREFIX="focused-test"
# Each invocation owns a different project, even within the same worktree.
CI_DOCKER_COMPOSE_PROJECT_NAME="fh-focused-$(python3 -c 'import uuid; print(uuid.uuid4().hex)')"
export CI_DOCKER_COMPOSE_PROJECT_NAME
ci_docker_claim_fresh_project

child_pid=""
cleanup_on_exit() {
    local test_status=$? cleanup_status=0
    trap - EXIT HUP INT TERM
    if [[ -n "$child_pid" ]]; then
        kill -TERM "$child_pid" 2>/dev/null || true
        wait "$child_pid" 2>/dev/null || true
    fi
    ci_docker_cleanup_stack || cleanup_status=$?
    if [[ "$test_status" -ne 0 ]]; then exit "$test_status"; fi
    exit "$cleanup_status"
}
trap cleanup_on_exit EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

# Initialize in the parent so its EXIT handler retains the exact command and
# data path even when Docker fails during resource creation.
ci_docker_init_compose focused-test
CI_DOCKER_STACK_STARTED=1
"${CI_DOCKER_COMPOSE_CMD[@]}" run --rm --no-deps -T "$@" &
child_pid=$!
wait "$child_pid"
child_pid=""
