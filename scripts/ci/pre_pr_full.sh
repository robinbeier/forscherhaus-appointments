#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(git rev-parse --show-toplevel)"
cd "$ROOT_DIR"
source ./scripts/ci/git_helpers.sh

BASE_REF="${PRE_PR_BASE_REF:-main}"
RUN_COVERAGE="${PRE_PR_RUN_COVERAGE:-0}"
REQUEST_CONTRACTS_L2_BLOCKING="${PRE_PR_REQUEST_CONTRACTS_L2_BLOCKING:-1}"
REQUEST_CONTRACTS_L2_WARNED=0
COMPOSE_CMD=()
LOCAL_CI_COMPOSE_OVERRIDE="docker/compose.ci-local.yml"

require_cmd() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "[pre-pr-full] Missing required command: $1" >&2
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
        echo "[pre-pr-full] docker compose command not found." >&2
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
            echo "[pre-pr-full] MySQL root readiness timed out after ${max_attempts} attempts." >&2
            return 1
        fi
        attempt=$((attempt + 1))
        sleep 2
    done

    attempt=1
    until run_compose exec -T mysql mysql -uuser -ppassword -e "USE easyappointments; SELECT 1;" >/dev/null 2>&1; do
        if [[ "$attempt" -ge "$max_attempts" ]]; then
            echo "[pre-pr-full] MySQL app-user readiness timed out after ${max_attempts} attempts." >&2
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
        if run_compose exec -T php-fpm php index.php console install; then
            return 0
        fi
        echo "[pre-pr-full] console install failed on attempt ${attempt}; retrying in 3s." >&2
        sleep 3
    done

    echo "[pre-pr-full] console install failed after 3 attempts." >&2
    return 1
}

cleanup_stack() {
    run_compose down -v --remove-orphans >/dev/null 2>&1 || true
}

bash ./scripts/ci/ensure_local_deps.sh

require_cmd git
require_cmd python3

# Keep changed-file checks deterministic against current base branch state.
git_ci_refresh_base_ref_if_safe "$BASE_REF" "pre-pr-full"

echo_section "Run quick pre-PR gate"
SKIP_LOCAL_DEPS_BOOTSTRAP=1 PRE_PR_BASE_REF="$BASE_REF" bash ./scripts/ci/pre_pr_quick.sh

echo_section "PHPStan request-contracts gate"
run_compose run --rm php-fpm composer phpstan:request-contracts:l1
run_compose run --rm php-fpm composer test:request-contracts
run_compose run --rm php-fpm php scripts/ci/check_request_contract_adoption.php
if [[ "$REQUEST_CONTRACTS_L2_BLOCKING" == "1" ]]; then
    run_compose run --rm php-fpm composer phpstan:request-contracts:l2
else
    if ! run_compose run --rm php-fpm composer phpstan:request-contracts:l2; then
        REQUEST_CONTRACTS_L2_WARNED=1
        echo "[pre-pr-full] WARN: composer phpstan:request-contracts:l2 failed (advisory override mode)." >&2
        echo "[pre-pr-full] WARN: See storage/logs/ci/phpstan-request-contracts-l2.raw for details." >&2
        echo "[pre-pr-full] WARN: Remove PRE_PR_REQUEST_CONTRACTS_L2_BLOCKING=0 to restore strict local blocking." >&2
    fi
fi

echo_section "Deptrac architecture boundaries gate"
python3 scripts/docs/generate_codeowners_from_map.py --check
GITHUB_EVENT_NAME=pull_request GITHUB_BASE_REF="$BASE_REF" bash scripts/ci/run_deptrac_changed_gate.sh
GITHUB_EVENT_NAME=pull_request GITHUB_BASE_REF="$BASE_REF" python3 scripts/ci/check_component_boundaries.py

echo_section "Start integration stack"
trap cleanup_stack EXIT
run_compose up -d mysql php-fpm nginx
wait_for_mysql_readiness
install_seed_instance

DEEP_RUNTIME_MANIFEST="storage/logs/ci/deep-runtime-suite/manifest.json"
# Keep runtime dependency upgrades, including Monolog, on the shared deep gate by default.
DEEP_RUNTIME_SUITES=(
    api-contract-openapi
    write-contract-booking
    write-contract-api
    booking-controller-flows
    integration-smoke
)

echo_section "Deep runtime suite"
rm -rf storage/logs/ci/deep-runtime-suite
mkdir -p storage/logs/ci/deep-runtime-suite
run_compose exec -T php-fpm php scripts/ci/run_deep_runtime_suite.php \
    --suites="$(IFS=,; echo "${DEEP_RUNTIME_SUITES[*]}")" \
    --base-url=http://nginx --index-page=index.php \
    --openapi-spec=/var/www/html/openapi.yml \
    --username=administrator --password=administrator \
    --booking-search-days=14 --retry-count=1 \
    --start-date=2026-01-01 --end-date=2026-01-31 \
    --report-dir=storage/logs/ci/deep-runtime-suite

echo_section "Deep runtime verdicts"
for suite in "${DEEP_RUNTIME_SUITES[@]}"; do
    run_compose exec -T php-fpm php scripts/ci/assert_deep_runtime_suite.php \
        --manifest="$DEEP_RUNTIME_MANIFEST" \
        --suite="$suite"
done

if [[ "$RUN_COVERAGE" == "1" ]]; then
    echo_section "Coverage delta gate"
    run_compose exec -T php-fpm composer test:coverage:unit
    run_compose exec -T php-fpm composer check:coverage:delta
fi

echo
if [[ "$RUN_COVERAGE" == "1" ]]; then
    if [[ "$REQUEST_CONTRACTS_L2_WARNED" == "1" ]]; then
        echo "[pre-pr-full] All blocking checks passed (including coverage); request-contracts:l2 reported advisory findings."
    else
        echo "[pre-pr-full] All checks passed (including coverage delta gate)."
    fi
else
    if [[ "$REQUEST_CONTRACTS_L2_WARNED" == "1" ]]; then
        echo "[pre-pr-full] All blocking checks passed; request-contracts:l2 reported advisory findings."
        echo "[pre-pr-full] Set PRE_PR_RUN_COVERAGE=1 to include the coverage delta gate."
    else
        echo "[pre-pr-full] All checks passed. Set PRE_PR_RUN_COVERAGE=1 to include the coverage delta gate."
    fi
fi
