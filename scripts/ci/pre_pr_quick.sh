#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(git rev-parse --show-toplevel)"
cd "$ROOT_DIR"
source ./scripts/ci/git_helpers.sh

BASE_REF="${PRE_PR_BASE_REF:-main}"
COMPOSE_CMD=()
# Keep the quick gate aligned with the repo's frontend tooling baseline.
ROOT_NODE_MINIMUM_MAJOR=18
LOCAL_CI_COMPOSE_OVERRIDE="docker/compose.ci-local.yml"

require_cmd() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "[pre-pr-quick] Missing required command: $1" >&2
        exit 1
    fi
}

require_minimum_node_major() {
    local required_major="$1"
    local node_major

    node_major="$(node -p "process.versions.node.split('.')[0]")"

    if [[ "$node_major" -lt "$required_major" ]]; then
        echo "[pre-pr-quick] Node.js >=${required_major} is required (found $(node --version))." >&2
        exit 1
    fi
}

ensure_docker_compose() {
    if [[ "${#COMPOSE_CMD[@]}" -gt 0 ]]; then
        return
    fi

    require_cmd docker
    local compose_files=()
    if [[ "${EA_LOCAL_CI_PORTLESS_COMPOSE:-1}" == "1" && -f "$LOCAL_CI_COMPOSE_OVERRIDE" ]]; then
        compose_files=(-f docker-compose.yml -f "$LOCAL_CI_COMPOSE_OVERRIDE")
    fi

    if docker compose version >/dev/null 2>&1; then
        COMPOSE_CMD=(docker compose "${compose_files[@]}")
    elif command -v docker-compose >/dev/null 2>&1; then
        COMPOSE_CMD=(docker-compose "${compose_files[@]}")
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

ensure_local_config() {
    if [[ -f config.php ]]; then
        return
    fi

    cp config-sample.php config.php
}

if [[ "${SKIP_LOCAL_DEPS_BOOTSTRAP:-0}" != "1" ]]; then
    bash ./scripts/ci/ensure_local_deps.sh
fi

require_cmd git
require_cmd python3
require_cmd npm
require_cmd node
require_minimum_node_major "$ROOT_NODE_MINIMUM_MAJOR"
ensure_local_config

# Keep changed-file checks deterministic against current base branch state.
git_ci_refresh_base_ref_if_safe "$BASE_REF" "pre-pr-quick"

echo_section "Changed-file JS lint"
GITHUB_EVENT_NAME=pull_request GITHUB_BASE_REF="$BASE_REF" ./scripts/ci/js-lint-changed.sh

# Frontend dependency bumps, including chartjs-chart-matrix@3 and the booking
# confirmation PDF's jspdf@4 refresh, can change generated bundles or the
# resolved lockfile without touching app source files.
echo_section "Frontend lockfile sync"
npm install --package-lock-only --ignore-scripts --no-audit --no-fund
git diff --quiet --exit-code -- package-lock.json || {
    echo "[pre-pr-quick] Frontend dependency sync produced uncommitted changes in package-lock.json." >&2
    git status --short -- package.json package-lock.json >&2 || true
    exit 1
}

echo_section "Frontend vendor assets refresh"
npm run assets:refresh
git diff --quiet --exit-code -- assets/vendor build || {
    echo "[pre-pr-quick] Frontend dependency refresh produced uncommitted changes in assets/vendor or build." >&2
    git status --short -- assets/vendor build >&2 || true
    exit 1
}

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
