#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(git rev-parse --show-toplevel)"
cd "$ROOT_DIR"
source ./scripts/ci/git_helpers.sh
source ./scripts/ci/docker_compose_helpers.sh

BASE_REF="${PRE_PR_BASE_REF:-main}"
# Keep quick-gate static analysis configurable for toolchain upgrade branches.
PHPSTAN_APPLICATION_SCRIPT="${PRE_PR_PHPSTAN_APPLICATION_SCRIPT:-phpstan:application}"
# Keep the quick gate aligned with the repo's frontend tooling baseline.
ROOT_NODE_MINIMUM_VERSION=20.19.0
CI_DOCKER_LOG_PREFIX="pre-pr-quick"

require_cmd() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "[pre-pr-quick] Missing required command: $1" >&2
        exit 1
    fi
}

echo_section() {
    echo
    echo "== $*"
}

cleanup_stack() {
    ci_docker_cleanup_stack
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
bash ./scripts/ci/require_node_minimum.sh "$ROOT_NODE_MINIMUM_VERSION" "pre-pr-quick"
ensure_local_config

# Keep changed-file checks deterministic against current base branch state.
git_ci_refresh_base_ref_if_safe "$BASE_REF" "pre-pr-quick"
ci_docker_build_php_fpm_if_inputs_changed "$BASE_REF" "pre-pr-quick"

echo_section "Changed-file JS lint"
GITHUB_EVENT_NAME=pull_request GITHUB_BASE_REF="$BASE_REF" ./scripts/ci/js-lint-changed.sh

# Frontend dependency spikes, including jquery@4 trials and prior package
# bumps, can change generated bundles or the resolved lockfile without
# touching app source files.
echo_section "Frontend lockfile sync"
# Run dependency spikes from a committed baseline so the quick gate only flags
# fresh drift introduced by the lockfile refresh itself.
git diff --quiet --exit-code -- package.json package-lock.json || {
    echo "[pre-pr-quick] Frontend dependency files are already dirty." >&2
    echo "[pre-pr-quick] Commit the refreshed package.json/package-lock.json baseline before rerunning this gate for dependency spikes." >&2
    git status --short -- package.json package-lock.json >&2 || true
    exit 1
}
npm install --package-lock-only --ignore-scripts --no-audit --no-fund
git diff --quiet --exit-code -- package.json package-lock.json || {
    echo "[pre-pr-quick] Frontend dependency sync produced uncommitted changes in package.json/package-lock.json." >&2
    echo "[pre-pr-quick] Commit the refreshed lockfile baseline before rerunning this gate for dependency spikes." >&2
    git status --short -- package.json package-lock.json >&2 || true
    exit 1
}

echo_section "Frontend vendor assets refresh"
# Keep dependency-driven frontend artifact drift, including jquery@4 spikes,
# visible in the quick gate.
npm run assets:refresh
git diff --quiet --exit-code -- assets/vendor build || {
    echo "[pre-pr-quick] Frontend dependency refresh produced uncommitted changes in assets/vendor or build." >&2
    echo "[pre-pr-quick] Commit dependency-driven asset refreshes before rerunning this gate for frontend upgrade spikes." >&2
    git status --short -- assets/vendor build >&2 || true
    exit 1
}

echo_section "Start quick gate database service"
trap cleanup_stack EXIT
ci_docker_compose up -d mysql
ci_docker_wait_for_mysql_readiness "pre-pr-quick"
ci_docker_install_seed_instance "pre-pr-quick" run --rm php-fpm php index.php console install

echo_section "PHPUnit"
ci_docker_compose run --rm php-fpm composer test

echo_section "PHPStan application"
ci_docker_compose run --rm php-fpm composer "$PHPSTAN_APPLICATION_SCRIPT"

echo_section "Typed request-dto gate"
ci_docker_compose run --rm php-fpm composer phpstan:request-dto
ci_docker_compose run --rm php-fpm composer test:request-dto
ci_docker_compose run --rm php-fpm php scripts/ci/check_request_dto_adoption.php

echo_section "Architecture ownership gate"
python3 scripts/docs/generate_architecture_ownership_docs.py --check
GITHUB_EVENT_NAME=pull_request GITHUB_BASE_REF="$BASE_REF" python3 scripts/ci/check_architecture_ownership_map.py

cleanup_stack
trap - EXIT

echo
echo "[pre-pr-quick] All checks passed."
