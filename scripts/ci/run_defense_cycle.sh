#!/usr/bin/env bash
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
source scripts/ci/docker_compose_helpers.sh
# No existing database, external target, dump, project or data path can be adopted.
if [[ -n "${CI_DOCKER_COMPOSE_PROJECT_NAME:-}${COMPOSE_PROJECT_NAME:-}${COMPOSE_FILE:-}${EA_MYSQL_DATA_PATH:-}" ]]; then
    echo 'Refusing caller-owned Docker runtime configuration.' >&2
    exit 1
fi
CI_DOCKER_COMPOSE_PROJECT_NAME="fh-defense-$(python3 -c 'import uuid; print(uuid.uuid4().hex[:16])')"
export CI_DOCKER_COMPOSE_PROJECT_NAME
export EA_SKIP_NPM_BOOTSTRAP=1 EA_SKIP_ASSET_BUILD_BOOTSTRAP=1
CI_DOCKER_LOG_PREFIX=defense-cycle
ci_docker_claim_fresh_project
cleanup() {
    local result=$?
    local cleanup_result=0
    ci_docker_cleanup_stack || cleanup_result=$?
    if [[ "$result" -ne 0 ]]; then exit "$result"; fi
    exit "$cleanup_result"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM
ci_docker_compose up -d mysql php-fpm
ci_docker_wait_for_service_exec php-fpm defense-cycle php -v
ci_docker_compose exec -T php-fpm php -r '
require "config.php";
if (Config::DB_HOST !== "mysql" || Config::DB_NAME !== "easyappointments" ||
    Config::DB_USERNAME !== "user" || Config::DB_PASSWORD !== "password") {
    fwrite(STDERR, "Refusing non-synthetic database configuration.\n"); exit(1);
}'
ci_docker_wait_for_mysql_readiness defense-cycle
ci_docker_wait_for_service_exec php-fpm defense-cycle php -v
ci_docker_wait_for_easyappointments_mysql_connectivity defense-cycle
ci_docker_install_seed_instance defense-cycle exec -T php-fpm php index.php console install
ci_docker_compose exec -T php-fpm env FH_DEFENSE_ISOLATED=1 APP_ENV=testing \
    php vendor/bin/phpunit --configuration phpunit.defense-cycle.xml
