#!/usr/bin/env bash
set -euo pipefail

COMPOSE_PROJECT="${COMPOSE_PROJECT:-fh-phpstan-auto}"
COMPOSE_SERVICE="${COMPOSE_SERVICE:-php-fpm}"
CONTAINER_WORKDIR="${CONTAINER_WORKDIR:-/var/www/html}"
CONTAINER_COMPOSER_CACHE="${CONTAINER_COMPOSER_CACHE:-/tmp/composer-cache}"
HOST_COMPOSER_CACHE="${HOST_COMPOSER_CACHE:-$HOME/Library/Caches/composer}"

log() {
    printf '[bootstrap] %s\n' "$*"
}

fail() {
    printf '[bootstrap] ERROR: %s\n' "$*" >&2
    exit 1
}

require_cmd() {
    command -v "$1" >/dev/null 2>&1 || fail "Missing required command: $1"
}

require_file() {
    [[ -f "$1" ]] || fail "Missing required file: $1"
}

compose() {
    docker compose -p "$COMPOSE_PROJECT" "$@"
}

container_id() {
    compose ps -q "$COMPOSE_SERVICE"
}

dir_has_entries() {
    local target="$1"
    [[ -d "$target" ]] || return 1
    [[ -n "$(find "$target" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]]
}

container_cache_is_populated() {
    compose exec -T "$COMPOSE_SERVICE" sh -lc '
        cache_dir="$1"
        [ -d "$cache_dir" ] && [ -n "$(find "$cache_dir" -mindepth 1 -maxdepth 1 -print -quit)" ]
    ' -- "$CONTAINER_COMPOSER_CACHE"
}

seed_container_cache_if_needed() {
    if container_cache_is_populated; then
        log "Container cache already populated at $CONTAINER_COMPOSER_CACHE."
        return 0
    fi

    if ! dir_has_entries "$HOST_COMPOSER_CACHE"; then
        log "Host cache $HOST_COMPOSER_CACHE is empty or missing; skip cache seed."
        return 0
    fi

    local cid
    cid="$(container_id)"
    [[ -n "$cid" ]] || fail "Cannot resolve container id for service $COMPOSE_SERVICE."

    log "Seeding container cache from host cache: $HOST_COMPOSER_CACHE -> $CONTAINER_COMPOSER_CACHE"
    compose exec -T "$COMPOSE_SERVICE" sh -lc 'mkdir -p "$1"' -- "$CONTAINER_COMPOSER_CACHE"
    docker cp "$HOST_COMPOSER_CACHE/." "$cid:$CONTAINER_COMPOSER_CACHE"
}

ensure_container_running() {
    if [[ -n "$(container_id)" ]]; then
        log "Service $COMPOSE_SERVICE already running."
        return 0
    fi

    log "Starting service $COMPOSE_SERVICE in compose project $COMPOSE_PROJECT."
    compose up -d "$COMPOSE_SERVICE"
    [[ -n "$(container_id)" ]] || fail "Service $COMPOSE_SERVICE did not start correctly."
}

prewarm_dependencies() {
    log "Running composer install in container."
    compose exec -T "$COMPOSE_SERVICE" sh -lc '
        cd "$1"
        COMPOSER_CACHE_DIR="$2" composer install --no-interaction --prefer-dist --no-progress
    ' -- "$CONTAINER_WORKDIR" "$CONTAINER_COMPOSER_CACHE"
}

verify_phpstan_binary() {
    log "Verifying vendor/bin/phpstan."
    compose exec -T "$COMPOSE_SERVICE" sh -lc '
        cd "$1"
        test -x vendor/bin/phpstan
    ' -- "$CONTAINER_WORKDIR"
}

verify_github_auth() {
    log "Verifying GitHub CLI authentication."
    gh auth status -h github.com >/dev/null
}

main() {
    require_cmd docker
    require_cmd gh
    require_file composer.json
    require_file composer.lock
    require_file phpstan.neon.dist

    ensure_container_running
    seed_container_cache_if_needed
    prewarm_dependencies
    verify_phpstan_binary
    verify_github_auth

    log "Bootstrap finished successfully."
    log "Compose project: $COMPOSE_PROJECT"
    log "Compose service: $COMPOSE_SERVICE"
}

main "$@"
