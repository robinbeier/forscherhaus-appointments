#!/usr/bin/env bash
set -euo pipefail

PORT="${1:-8080}"
READINESS_PATH="${2:-index.php/login}"
MAX_ATTEMPTS="${EA_HTTP_READY_ATTEMPTS:-45}"
LOG_PATH="/tmp/ci-php-http-${PORT}.log"
BASE_URL="http://127.0.0.1:${PORT}/${READINESS_PATH}"
COMPOSE_CMD=()

require_cmd() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "Missing required command: $1" >&2
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
        echo "docker compose command not found." >&2
        exit 1
    fi
}

run_compose() {
    ensure_docker_compose
    "${COMPOSE_CMD[@]}" "$@"
}

if run_compose exec -T php-fpm php -r '$url=$argv[1];$ch=curl_init($url);curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);curl_setopt($ch,CURLOPT_TIMEOUT,5);curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);exit($code===200?0:1);' "$BASE_URL"; then
    exit 0
fi

run_compose exec -T -d php-fpm sh -lc "php -S 0.0.0.0:${PORT} -t /var/www/html >${LOG_PATH} 2>&1"

attempt=1
until run_compose exec -T php-fpm php -r '$url=$argv[1];$ch=curl_init($url);curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);curl_setopt($ch,CURLOPT_TIMEOUT,5);curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);exit($code===200?0:1);' "$BASE_URL"; do
    if [[ "$attempt" -ge "$MAX_ATTEMPTS" ]]; then
        echo "::error::Application HTTP readiness timed out after ${MAX_ATTEMPTS} attempts (${BASE_URL})." >&2
        run_compose exec -T php-fpm sh -lc "cat ${LOG_PATH} || true" >&2 || true
        exit 1
    fi
    attempt=$((attempt + 1))
    sleep 2
done
