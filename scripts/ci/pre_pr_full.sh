#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(git rev-parse --show-toplevel)"
cd "$ROOT_DIR"

BASE_REF="${PRE_PR_BASE_REF:-main}"
RUN_COVERAGE="${PRE_PR_RUN_COVERAGE:-0}"
REQUEST_CONTRACTS_L2_BLOCKING="${PRE_PR_REQUEST_CONTRACTS_L2_BLOCKING:-1}"
REQUEST_CONTRACTS_L2_WARNED=0
COMPOSE_CMD=()

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

    if docker compose version >/dev/null 2>&1; then
        COMPOSE_CMD=(docker compose)
    elif command -v docker-compose >/dev/null 2>&1; then
        COMPOSE_CMD=(docker-compose)
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
git fetch --no-tags origin "$BASE_REF" >/dev/null 2>&1 || true

echo_section "Run quick pre-PR gate"
SKIP_LOCAL_DEPS_BOOTSTRAP=1 PRE_PR_BASE_REF="$BASE_REF" bash ./scripts/ci/pre_pr_quick.sh

echo_section "Typed request-contracts gate"
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

echo_section "Architecture boundaries gate"
python3 scripts/docs/generate_codeowners_from_map.py --check
GITHUB_EVENT_NAME=pull_request GITHUB_BASE_REF="$BASE_REF" bash scripts/ci/run_deptrac_changed_gate.sh
GITHUB_EVENT_NAME=pull_request GITHUB_BASE_REF="$BASE_REF" python3 scripts/ci/check_component_boundaries.py

echo_section "Start integration stack"
trap cleanup_stack EXIT
run_compose up -d mysql php-fpm nginx
wait_for_mysql_readiness
install_seed_instance

echo_section "API OpenAPI contract smoke"
run_compose exec -T php-fpm php scripts/ci/api_openapi_contract_smoke.php \
    --base-url=http://nginx --index-page=index.php \
    --openapi-spec=/var/www/html/openapi.yml \
    --username=administrator --password=administrator

echo_section "Booking write-path contract smoke"
run_compose exec -T php-fpm php scripts/ci/booking_write_contract_smoke.php \
    --base-url=http://nginx --index-page=index.php \
    --username=administrator --password=administrator \
    --booking-search-days=14 --retry-count=1 \
    --checks=booking_register_success_contract,booking_register_manage_update_contract,booking_register_unavailable_contract,booking_reschedule_manage_mode_contract,booking_cancel_success_contract,booking_cancel_unknown_hash_contract

echo_section "API OpenAPI write-path contract smoke"
run_compose exec -T php-fpm php scripts/ci/api_openapi_write_contract_smoke.php \
    --base-url=http://nginx --index-page=index.php \
    --openapi-spec=/var/www/html/openapi.yml \
    --username=administrator --password=administrator \
    --retry-count=1 --booking-search-days=14 \
    --checks=appointments_write_unauthorized_guard,customers_store_contract,appointments_store_contract,appointments_update_contract,appointments_destroy_contract,customers_destroy_contract

echo_section "Booking controller flow tests"
run_compose exec -T php-fpm composer test:booking-controller-flows

echo_section "Dashboard+booking+api integration smoke"
run_compose exec -T php-fpm php scripts/ci/dashboard_integration_smoke.php \
    --base-url=http://nginx --index-page=index.php \
    --username=administrator --password=administrator \
    --start-date=2026-01-01 --end-date=2026-01-31 \
    --checks=readiness_login_page,auth_login_validate,dashboard_metrics,booking_page_readiness,booking_extract_bootstrap,booking_available_hours,booking_unavailable_dates,api_unauthorized_guard,api_appointments_index,api_availabilities

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
