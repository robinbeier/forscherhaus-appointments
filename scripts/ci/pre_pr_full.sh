#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(git rev-parse --show-toplevel)"
cd "$ROOT_DIR"
source ./scripts/ci/git_helpers.sh
source ./scripts/ci/docker_compose_helpers.sh

BASE_REF="${PRE_PR_BASE_REF:-main}"
RUN_COVERAGE="${PRE_PR_RUN_COVERAGE:-0}"
REQUEST_CONTRACTS_L2_BLOCKING="${PRE_PR_REQUEST_CONTRACTS_L2_BLOCKING:-1}"
REQUEST_CONTRACTS_L2_WARNED=0
# Allow toolchain-upgrade branches to override composer script targets without forking this gate.
PHPSTAN_APPLICATION_SCRIPT="${PRE_PR_PHPSTAN_APPLICATION_SCRIPT:-phpstan:application}"
PHPSTAN_REQUEST_CONTRACTS_L1_SCRIPT="${PRE_PR_PHPSTAN_REQUEST_CONTRACTS_L1_SCRIPT:-phpstan:request-contracts:l1}"
PHPSTAN_REQUEST_CONTRACTS_L2_SCRIPT="${PRE_PR_PHPSTAN_REQUEST_CONTRACTS_L2_SCRIPT:-phpstan:request-contracts:l2}"
DEPTRAC_ANALYZE_SCRIPT="${PRE_PR_DEPTRAC_ANALYZE_SCRIPT:-deptrac:analyze}"
CI_DOCKER_LOG_PREFIX="pre-pr-full"

require_cmd() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "[pre-pr-full] Missing required command: $1" >&2
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

pre_pr_full_should_include_ldap_guardrail() {
    local diff_range changed_paths

    if [[ "${PRE_PR_INCLUDE_LDAP_GUARDRAIL:-}" == "1" ]]; then
        return 0
    fi

    if [[ "${PRE_PR_INCLUDE_LDAP_GUARDRAIL:-}" == "0" ]]; then
        return 1
    fi

    diff_range="origin/${BASE_REF}...HEAD"
    if ! changed_paths="$(git diff --name-only "$diff_range" 2>/dev/null)"; then
        changed_paths="$(git diff --name-only HEAD~1...HEAD 2>/dev/null || true)"
    fi

    while IFS= read -r path; do
        case "$path" in
            application/controllers/Login.php|\
            application/controllers/Ldap_settings.php|\
            application/libraries/Ldap_client.php|\
            application/models/Admins_model.php|\
            application/models/Customers_model.php|\
            application/models/Providers_model.php|\
            application/models/Users_model.php|\
            application/views/pages/ldap_settings.php|\
            application/views/components/ldap_import_modal.php|\
            application/config/constants.php|\
            application/helpers/setting_helper.php|\
            application/migrations/057_add_ldap_rows_to_settings_table.php|\
            application/migrations/058_add_ldap_dn_column_to_users_table.php|\
            docker/ldap/*|\
            docker/ldap/*/*|\
            docker/ldap/*/*/*|\
            docker/openldap/*|\
            docker/openldap/*/*|\
            docker/openldap/*/*/*|\
            scripts/ldap/*|\
            scripts/ci/dashboard_integration_smoke.php|\
            scripts/ci/run_deep_runtime_suite.php|\
            scripts/ci/lib/CheckSelection.php|\
            tests/Unit/Scripts/DeepRuntimeSuiteTest.php|\
            tests/Unit/Scripts/CiPathFilterMatrixTest.php|\
            docker-compose.yml|\
            .github/workflows/ci.yml)
                return 0
                ;;
        esac
    done <<< "$changed_paths"

    return 1
}

bash ./scripts/ci/ensure_local_deps.sh

require_cmd git
require_cmd python3

# Keep changed-file checks deterministic against current base branch state.
git_ci_refresh_base_ref_if_safe "$BASE_REF" "pre-pr-full"

echo_section "Run quick pre-PR gate"
SKIP_LOCAL_DEPS_BOOTSTRAP=1 PRE_PR_BASE_REF="$BASE_REF" PRE_PR_PHPSTAN_APPLICATION_SCRIPT="$PHPSTAN_APPLICATION_SCRIPT" bash ./scripts/ci/pre_pr_quick.sh

echo_section "PHPStan static-analysis gate"
ci_docker_compose run --rm php-fpm composer "$PHPSTAN_APPLICATION_SCRIPT"
ci_docker_compose run --rm php-fpm composer "$PHPSTAN_REQUEST_CONTRACTS_L1_SCRIPT"
ci_docker_compose run --rm php-fpm composer test:request-contracts
ci_docker_compose run --rm php-fpm php scripts/ci/check_request_contract_adoption.php
if [[ "$REQUEST_CONTRACTS_L2_BLOCKING" == "1" ]]; then
    ci_docker_compose run --rm php-fpm composer "$PHPSTAN_REQUEST_CONTRACTS_L2_SCRIPT"
else
    if ! ci_docker_compose run --rm php-fpm composer "$PHPSTAN_REQUEST_CONTRACTS_L2_SCRIPT"; then
        REQUEST_CONTRACTS_L2_WARNED=1
        echo "[pre-pr-full] WARN: composer ${PHPSTAN_REQUEST_CONTRACTS_L2_SCRIPT} failed (advisory override mode)." >&2
        echo "[pre-pr-full] WARN: See storage/logs/ci/phpstan-request-contracts-l2.raw for details." >&2
        echo "[pre-pr-full] WARN: Remove PRE_PR_REQUEST_CONTRACTS_L2_BLOCKING=0 to restore strict local blocking." >&2
    fi
fi

echo_section "Deptrac architecture boundaries gate"
ci_docker_compose run --rm php-fpm composer "$DEPTRAC_ANALYZE_SCRIPT"
python3 scripts/docs/generate_codeowners_from_map.py --check
GITHUB_EVENT_NAME=pull_request GITHUB_BASE_REF="$BASE_REF" bash scripts/ci/run_deptrac_changed_gate.sh
GITHUB_EVENT_NAME=pull_request GITHUB_BASE_REF="$BASE_REF" python3 scripts/ci/check_component_boundaries.py

echo_section "Start integration stack"
trap cleanup_stack EXIT
INTEGRATION_SMOKE_INCLUDE_LDAP=0
STACK_SERVICES=(mysql php-fpm nginx)
if pre_pr_full_should_include_ldap_guardrail; then
    INTEGRATION_SMOKE_INCLUDE_LDAP=1
    STACK_SERVICES+=(openldap)
fi
echo "[pre-pr-full] LDAP guardrail checks enabled: ${INTEGRATION_SMOKE_INCLUDE_LDAP}"
ci_docker_compose up -d "${STACK_SERVICES[@]}"
ci_docker_wait_for_mysql_readiness "pre-pr-full"
ci_docker_install_seed_instance "pre-pr-full" exec -T php-fpm php index.php console install

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
ci_docker_compose exec -T php-fpm php scripts/ci/run_deep_runtime_suite.php \
    --suites="$(IFS=,; echo "${DEEP_RUNTIME_SUITES[*]}")" \
    --base-url=http://nginx --index-page=index.php \
    --openapi-spec=/var/www/html/openapi.yml \
    --username=administrator --password=administrator \
    --booking-search-days=14 --retry-count=1 \
    --start-date=2026-01-01 --end-date=2026-01-31 \
    --integration-smoke-include-ldap="${INTEGRATION_SMOKE_INCLUDE_LDAP}" \
    --report-dir=storage/logs/ci/deep-runtime-suite

echo_section "Deep runtime verdicts"
for suite in "${DEEP_RUNTIME_SUITES[@]}"; do
    ci_docker_compose exec -T php-fpm php scripts/ci/assert_deep_runtime_suite.php \
        --manifest="$DEEP_RUNTIME_MANIFEST" \
        --suite="$suite"
done

if [[ "$RUN_COVERAGE" == "1" ]]; then
    echo_section "Coverage shard + delta gate"
    ci_docker_compose exec -T php-fpm composer test:coverage:unit-shard
    ci_docker_compose exec -T php-fpm composer test:coverage:integration-shard
    ci_docker_compose exec -T php-fpm composer test:coverage:merge-shards
    ci_docker_compose exec -T php-fpm composer check:coverage:delta
fi

echo
if [[ "$RUN_COVERAGE" == "1" ]]; then
    if [[ "$REQUEST_CONTRACTS_L2_WARNED" == "1" ]]; then
        echo "[pre-pr-full] All blocking checks passed (including coverage); request-contracts:l2 reported advisory findings."
    else
        echo "[pre-pr-full] All checks passed (including coverage shard merge + delta gate)."
    fi
else
    if [[ "$REQUEST_CONTRACTS_L2_WARNED" == "1" ]]; then
        echo "[pre-pr-full] All blocking checks passed; request-contracts:l2 reported advisory findings."
        echo "[pre-pr-full] Set PRE_PR_RUN_COVERAGE=1 to include the coverage delta gate."
    else
        echo "[pre-pr-full] All checks passed. Set PRE_PR_RUN_COVERAGE=1 to include the coverage delta gate."
    fi
fi
