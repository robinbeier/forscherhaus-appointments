#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(git rev-parse --show-toplevel)"
cd "$ROOT_DIR"

BASE_REF="${PRE_PR_BASE_REF:-main}"
COMPOSE_CMD=()

require_cmd() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "[pre-pr-quick] Missing required command: $1" >&2
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
        echo "[pre-pr-quick] docker compose command not found." >&2
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
            echo "[pre-pr-quick] MySQL root readiness timed out after ${max_attempts} attempts." >&2
            return 1
        fi
        attempt=$((attempt + 1))
        sleep 2
    done

    attempt=1
    until run_compose exec -T mysql mysql -uuser -ppassword -e "USE easyappointments; SELECT 1;" >/dev/null 2>&1; do
        if [[ "$attempt" -ge "$max_attempts" ]]; then
            echo "[pre-pr-quick] MySQL app-user readiness timed out after ${max_attempts} attempts." >&2
            return 1
        fi
        attempt=$((attempt + 1))
        sleep 2
    done

    return 0
}

install_seed_instance() {
    local attempt
    for attempt in 1 2 3; do
        if run_compose run --rm php-fpm php index.php console install; then
            return 0
        fi
        echo "[pre-pr-quick] console install failed on attempt ${attempt}; retrying in 3s." >&2
        sleep 3
    done

    echo "[pre-pr-quick] console install failed after 3 attempts." >&2
    return 1
}

cleanup_stack() {
    run_compose down -v --remove-orphans >/dev/null 2>&1 || true
}

if [[ "${SKIP_LOCAL_DEPS_BOOTSTRAP:-0}" != "1" ]]; then
    bash ./scripts/ci/ensure_local_deps.sh
fi

require_cmd git
require_cmd python3
require_cmd npm

# Keep changed-file checks deterministic against current base branch state.
git fetch --no-tags --no-write-fetch-head origin "$BASE_REF" >/dev/null 2>&1 || true

echo_section "Changed-file JS lint"
GITHUB_EVENT_NAME=pull_request GITHUB_BASE_REF="$BASE_REF" ./scripts/ci/js-lint-changed.sh

echo_section "Start quick gate database service"
trap cleanup_stack EXIT
run_compose up -d mysql
wait_for_mysql_readiness
install_seed_instance

echo_section "PHPUnit"
run_compose run --rm php-fpm composer test

echo_section "PHPStan application"
run_compose run --rm php-fpm composer phpstan:application

echo_section "Typed request-dto gate"
run_compose run --rm php-fpm composer phpstan:request-dto
run_compose run --rm php-fpm composer test:request-dto
run_compose run --rm php-fpm php scripts/ci/check_request_dto_adoption.php

echo_section "Architecture ownership gate"
python3 scripts/docs/generate_architecture_ownership_docs.py --check
GITHUB_EVENT_NAME=pull_request GITHUB_BASE_REF="$BASE_REF" python3 scripts/ci/check_architecture_ownership_map.py

cleanup_stack
trap - EXIT

echo
echo "[pre-pr-quick] All checks passed."
