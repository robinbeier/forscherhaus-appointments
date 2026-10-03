#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
cd "$ROOT_DIR"

[[ $# -le 1 && ( $# -eq 0 || "$1" == '--config-only' ) ]] || {
    echo 'Usage: private_local_sessions_smoke.sh [--config-only]' >&2
    exit 2
}
mode="${1:-runtime}"

project_one="fh-session-smoke-${RANDOM}-$$"
project_two="fh-session-smoke-${RANDOM}-$$-b"
override_file="$(mktemp "${TMPDIR:-/tmp}/fh-session-smoke.XXXXXX.yml")"
project_one_owned=0
project_two_owned=0

cleanup() {
    set +e
    if (( project_two_owned )); then
        docker compose -p "$project_two" -f docker-compose.yml -f "$override_file" down -v --remove-orphans >/dev/null 2>&1
    fi
    if (( project_one_owned )); then
        docker compose -p "$project_one" -f docker-compose.yml -f "$override_file" down -v --remove-orphans >/dev/null 2>&1
    fi
    rm -f -- "$override_file"
}
trap cleanup EXIT

assert_project_fresh() {
    local project="$1"
    local session_volume="${project}_php_fpm_sessions"
    if [[ -n "$(docker container ls -aq --filter "label=com.docker.compose.project=${project}")" ]]; then
        echo "Compose project already has containers: $project" >&2
        exit 1
    fi
    if [[ -n "$(docker network ls -q --filter "label=com.docker.compose.project=${project}")" ]]; then
        echo "Compose project already has networks: $project" >&2
        exit 1
    fi
    if docker volume inspect "$session_volume" >/dev/null 2>&1; then
        echo "Compose project already has its session volume: $session_volume" >&2
        exit 1
    fi
}

cat >"$override_file" <<'YAML'
services:
  php-fpm:
    environment:
      GIT_DIR: /nonexistent
  nginx:
    ports: !override
      - '127.0.0.1::80'
YAML

compose_config="$(docker compose -p "$project_one" -f docker-compose.yml config --format json)"
COMPOSE_CONFIG_JSON="$compose_config" python3 - <<'PY'
import json
import os
import sys

model = json.loads(os.environ["COMPOSE_CONFIG_JSON"])
services = model["services"]
mounts = services["php-fpm"]["volumes"]
session_mounts = [mount for mount in mounts if mount.get("target") == "/var/www/html/storage/sessions"]
if len(session_mounts) != 1:
    raise SystemExit("expected exactly one PHP-FPM session mount")
mount = session_mounts[0]
if mount.get("type") != "volume" or mount.get("source") != "php_fpm_sessions":
    raise SystemExit("PHP-FPM sessions must use the project-scoped named volume")
if mount.get("volume", {}).get("nocopy") is not True:
    raise SystemExit("PHP-FPM session volume must set nocopy=true")
if any(mount.get("target") == "/var/www/html/storage/sessions" for mount in services["nginx"]["volumes"]):
    raise SystemExit("Nginx must not mount the private PHP-FPM session volume")
print("private_local_sessions_config=passed")
PY

if [[ "$mode" == '--config-only' ]]; then
    exit 0
fi

assert_project_fresh "$project_one"
assert_project_fresh "$project_two"

runtime_config="$(docker compose -p "$project_one" -f docker-compose.yml -f "$override_file" config --format json)"
RUNTIME_CONFIG_JSON="$runtime_config" python3 - <<'PY'
import json
import os

ports = json.loads(os.environ["RUNTIME_CONFIG_JSON"])["services"]["nginx"]["ports"]
if len(ports) != 1 or ports[0].get("host_ip") != "127.0.0.1" or ports[0].get("published") is not None:
    raise SystemExit("runtime smoke must use exactly one loopback ephemeral Nginx port")
print("private_local_sessions_runtime_config=passed")
PY

project_one_owned=1
docker compose -p "$project_one" -f docker-compose.yml -f "$override_file" up -d php-fpm nginx >/dev/null
docker compose -p "$project_one" -f docker-compose.yml -f "$override_file" exec -T php-fpm \
    sh -lc 'test "$(stat -c %a storage)" = "755" && test "$(stat -c %u storage/sessions)" = "33" && test "$(stat -c %a storage/sessions)" = "700" && su -s /bin/sh www-data -c "printf synthetic-session-one > storage/sessions/ea_session_smoke"'

mount_source="$(docker inspect "${project_one}-php-fpm-1" --format '{{range .Mounts}}{{if eq .Destination "/var/www/html/storage/sessions"}}{{.Source}}{{end}}{{end}}')"
[[ -n "$mount_source" && "$mount_source" != "$ROOT_DIR/storage/sessions" ]] || {
    echo "PHP-FPM session mount is not separated from the host path." >&2
    exit 1
}

docker compose -p "$project_one" -f docker-compose.yml -f "$override_file" restart php-fpm >/dev/null
docker compose -p "$project_one" -f docker-compose.yml -f "$override_file" exec -T php-fpm \
    sh -lc 'test "$(cat storage/sessions/ea_session_smoke)" = synthetic-session-one'

nginx_port="$(docker compose -p "$project_one" -f docker-compose.yml -f "$override_file" port nginx 80 | sed -n 's/.*://p')"
[[ "$nginx_port" =~ ^[0-9]+$ ]] || { echo "Could not resolve Nginx port." >&2; exit 1; }
status="$(curl --noproxy '*' --silent --output /dev/null --write-out '%{http_code}' "http://127.0.0.1:${nginx_port}/storage/sessions/ea_session_smoke")"
case "$status" in
    403|404) ;;
    *) echo "Nginx exposed the session path with HTTP $status." >&2; exit 1 ;;
esac
host_fixture_status="$(curl --noproxy '*' --silent --output /dev/null --write-out '%{http_code}' "http://127.0.0.1:${nginx_port}/storage/sessions/index.html")"
[[ "$host_fixture_status" == 403 ]] || {
    echo "Nginx did not deny the existing session-directory fixture (HTTP $host_fixture_status)." >&2
    exit 1
}

project_two_owned=1
docker compose -p "$project_two" -f docker-compose.yml -f "$override_file" up -d php-fpm >/dev/null
docker compose -p "$project_two" -f docker-compose.yml -f "$override_file" exec -T php-fpm \
    sh -lc 'su -s /bin/sh www-data -c "printf synthetic-session-two > storage/sessions/ea_session_smoke"'
if docker compose -p "$project_one" -f docker-compose.yml -f "$override_file" exec -T php-fpm \
    sh -lc 'test "$(cat storage/sessions/ea_session_smoke)" = synthetic-session-one' >/dev/null; then
    printf 'private_local_sessions_smoke=passed persistence=1 nginx_denied=1 project_isolation=1\n'
else
    echo "Session volumes for separate Compose projects were not isolated." >&2
    exit 1
fi
