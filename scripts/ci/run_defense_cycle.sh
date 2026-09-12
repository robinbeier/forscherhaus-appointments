#!/usr/bin/env bash
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
source scripts/ci/docker_compose_helpers.sh
# No existing database, external target, dump, project or data path can be adopted.
if [[ -n "${CI_DOCKER_COMPOSE_PROJECT_NAME:-}${COMPOSE_PROJECT_NAME:-}${COMPOSE_FILE:-}${EA_MYSQL_DATA_PATH:-}${EA_LOCAL_CI_COMPOSE_OVERRIDE_PATH:-}${DOCKER_HOST:-}${DOCKER_CONTEXT:-}" || "${EA_LOCAL_CI_PORTLESS_COMPOSE:-1}" != "1" ]]; then
    echo 'Refusing caller-owned Docker runtime configuration.' >&2
    exit 1
fi

# Resolve and pin the currently selected daemon before any lifecycle command.
# A persisted Docker context may still point at a remote daemon, so inspect it
# explicitly and fail closed unless its endpoint is a local Unix/npipe socket.
docker_endpoint_json=""
if ! docker_endpoint_json="$(docker context inspect --format '{{json .Endpoints.docker.Host}}' 2>/dev/null)"; then
    echo 'Refusing unavailable Docker context endpoint.' >&2
    exit 1
fi
if ! docker_endpoint="$(DOCKER_ENDPOINT_JSON="$docker_endpoint_json" python3 -c '
import json
import os
import sys

try:
    endpoint = json.loads(os.environ["DOCKER_ENDPOINT_JSON"])
except (KeyError, json.JSONDecodeError, TypeError):
    sys.exit(1)
if not isinstance(endpoint, str) or not endpoint.startswith(("unix://", "npipe://")):
    sys.exit(1)
print(endpoint)
')"; then
    echo 'Refusing non-local Docker context endpoint.' >&2
    exit 1
fi
export DOCKER_HOST="$docker_endpoint"

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
