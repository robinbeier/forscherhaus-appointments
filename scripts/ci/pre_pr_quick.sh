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

[[ -d vendor ]] || {
    echo "[pre-pr-quick] Missing vendor/. Run composer install first." >&2
    exit 1
}
[[ -d node_modules ]] || {
    echo "[pre-pr-quick] Missing node_modules/. Run npm install first." >&2
    exit 1
}

require_cmd git
require_cmd python3

# Keep changed-file checks deterministic against current base branch state.
git fetch --no-tags origin "$BASE_REF" >/dev/null 2>&1 || true

echo_section "Changed-file JS lint"
GITHUB_EVENT_NAME=pull_request GITHUB_BASE_REF="$BASE_REF" ./scripts/ci/js-lint-changed.sh

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

echo
echo "[pre-pr-quick] All checks passed."
