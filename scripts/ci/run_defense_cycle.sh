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
DEFENSE_CYCLE_ID="$(python3 -c 'import uuid; print(uuid.uuid4().hex)')"
DEFENSE_CYCLE_DIR="$PWD/storage/logs/ci/defense-cycle"
DEFENSE_CYCLE_EVENTS="$DEFENSE_CYCLE_DIR/${DEFENSE_CYCLE_ID}.events"
DEFENSE_CYCLE_JUNIT="$DEFENSE_CYCLE_DIR/${DEFENSE_CYCLE_ID}.junit.xml"
DEFENSE_CYCLE_SUMMARY="$DEFENSE_CYCLE_DIR/${DEFENSE_CYCLE_ID}.summary.json"
mkdir -p "$DEFENSE_CYCLE_DIR" 2>/dev/null || true
: > "$DEFENSE_CYCLE_EVENTS" 2>/dev/null || true
DEFENSE_CYCLE_COMMIT="$(git rev-parse HEAD 2>/dev/null || printf unknown)"
if git diff --quiet HEAD -- 2>/dev/null; then DEFENSE_CYCLE_DIRTY=false; else DEFENSE_CYCLE_DIRTY=true; fi
DEFENSE_CYCLE_CURRENT_PHASE=""
defense_cycle_now() { python3 -c 'import time; print(time.monotonic_ns() // 1_000_000)' 2>/dev/null || printf 'unknown'; }
defense_cycle_begin() {
    DEFENSE_CYCLE_CURRENT_PHASE="$1"
    printf '%s|%s|started\n' "$1" "$(defense_cycle_now)" >> "$DEFENSE_CYCLE_EVENTS" 2>/dev/null || true
}
defense_cycle_end() {
    printf '%s|%s|passed\n' "$1" "$(defense_cycle_now)" >> "$DEFENSE_CYCLE_EVENTS" 2>/dev/null || true
    DEFENSE_CYCLE_CURRENT_PHASE=""
}
ci_docker_claim_fresh_project
cleanup() {
    local result=$?
    local cleanup_result=0
    if [[ -n "$DEFENSE_CYCLE_CURRENT_PHASE" ]]; then
        printf '%s|%s|failed\n' "$DEFENSE_CYCLE_CURRENT_PHASE" "$(defense_cycle_now)" >> "$DEFENSE_CYCLE_EVENTS" 2>/dev/null || true
    fi
    DEFENSE_CYCLE_CURRENT_PHASE=""
    defense_cycle_begin cleanup
    ci_docker_cleanup_stack || cleanup_result=$?
    if [[ "$cleanup_result" -eq 0 ]]; then
        defense_cycle_end cleanup
    else
        printf 'cleanup|%s|failed\n' "$(defense_cycle_now)" >> "$DEFENSE_CYCLE_EVENTS" 2>/dev/null || true
        DEFENSE_CYCLE_CURRENT_PHASE=""
    fi
    python3 scripts/ci/defense_cycle_report.py --events "$DEFENSE_CYCLE_EVENTS" --junit "$DEFENSE_CYCLE_JUNIT" --output "$DEFENSE_CYCLE_SUMMARY" --commit "$DEFENSE_CYCLE_COMMIT" --dirty "$DEFENSE_CYCLE_DIRTY" --runner-status "$result" --cleanup-status "$cleanup_result" >/dev/null 2>&1 || printf '[defense-cycle] summary report unavailable\n' >&2
    if [[ "$result" -ne 0 ]]; then exit "$result"; fi
    exit "$cleanup_result"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM
defense_cycle_begin compose_start
ci_docker_compose up -d mysql php-fpm
defense_cycle_end compose_start
defense_cycle_begin php_ready
ci_docker_wait_for_service_exec php-fpm defense-cycle php -v
defense_cycle_end php_ready
defense_cycle_begin synthetic_config
ci_docker_compose exec -T php-fpm php -r '
require "config.php";
if (Config::DB_HOST !== "mysql" || Config::DB_NAME !== "easyappointments" ||
    Config::DB_USERNAME !== "user" || Config::DB_PASSWORD !== "password") {
    fwrite(STDERR, "Refusing non-synthetic database configuration.\n"); exit(1);
}'
defense_cycle_end synthetic_config
defense_cycle_begin mysql_ready
ci_docker_wait_for_mysql_readiness defense-cycle
defense_cycle_end mysql_ready
defense_cycle_begin php_ready_after_mysql
ci_docker_wait_for_service_exec php-fpm defense-cycle php -v
defense_cycle_end php_ready_after_mysql
defense_cycle_begin app_db_ready
ci_docker_wait_for_easyappointments_mysql_connectivity defense-cycle
defense_cycle_end app_db_ready
defense_cycle_begin seed_install
ci_docker_install_seed_instance defense-cycle exec -T php-fpm php index.php console install
defense_cycle_end seed_install
# Do not enable an optional PHPUnit logger when its destination is unavailable.
DEFENSE_CYCLE_PHPUNIT=(php vendor/bin/phpunit --configuration phpunit.defense-cycle.xml)
if { : > "$DEFENSE_CYCLE_JUNIT"; } 2>/dev/null; then
    DEFENSE_CYCLE_PHPUNIT+=(--log-junit "/var/www/html/storage/logs/ci/defense-cycle/${DEFENSE_CYCLE_ID}.junit.xml")
else
    printf '[defense-cycle] JUnit receipt unavailable; running unchanged tests\n' >&2
fi
defense_cycle_begin phpunit
ci_docker_compose exec -T php-fpm env FH_DEFENSE_ISOLATED=1 APP_ENV=testing \
    "${DEFENSE_CYCLE_PHPUNIT[@]}"
defense_cycle_end phpunit
