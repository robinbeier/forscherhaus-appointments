#!/usr/bin/env bash

CI_DOCKER_COMPOSE_CMD=()

ci_docker_require_cmd() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "[$2] Missing required command: $1" >&2
        exit 1
    fi
}

ci_docker_init_compose() {
    local log_prefix="${1:-ci-docker}"
    local local_ci_compose_override="${EA_LOCAL_CI_COMPOSE_OVERRIDE_PATH:-docker/compose.ci-local.yml}"

    if [[ "${#CI_DOCKER_COMPOSE_CMD[@]}" -gt 0 ]]; then
        return
    fi

    ci_docker_require_cmd docker "$log_prefix"

    local compose_files=()
    if [[ "${EA_LOCAL_CI_PORTLESS_COMPOSE:-1}" == "1" && -f "$local_ci_compose_override" ]]; then
        compose_files=(-f docker-compose.yml -f "$local_ci_compose_override")
    fi

    if docker compose version >/dev/null 2>&1; then
        CI_DOCKER_COMPOSE_CMD=(docker compose "${compose_files[@]}")
    elif command -v docker-compose >/dev/null 2>&1; then
        CI_DOCKER_COMPOSE_CMD=(docker-compose "${compose_files[@]}")
    else
        echo "[$log_prefix] docker compose command not found." >&2
        exit 1
    fi
}

ci_docker_compose() {
    local log_prefix="${CI_DOCKER_LOG_PREFIX:-ci-docker}"
    ci_docker_init_compose "$log_prefix"
    "${CI_DOCKER_COMPOSE_CMD[@]}" "$@"
}

ci_docker_service_running() {
    local service_name="$1"
    ci_docker_compose ps --status running --services | grep -Fxq "$service_name"
}

ci_docker_wait_for_mysql_readiness() {
    local log_prefix="${1:-ci-docker}"
    local max_attempts=60
    local attempt=1

    until ci_docker_compose exec -T mysql mysqladmin ping -h localhost -uroot -psecret --silent; do
        if [[ "$attempt" -ge "$max_attempts" ]]; then
            echo "[$log_prefix] MySQL root readiness timed out after ${max_attempts} attempts." >&2
            return 1
        fi
        attempt=$((attempt + 1))
        sleep 2
    done

    attempt=1
    until ci_docker_compose exec -T mysql mysql -uuser -ppassword -e "USE easyappointments; SELECT 1;" >/dev/null 2>&1; do
        if [[ "$attempt" -ge "$max_attempts" ]]; then
            echo "[$log_prefix] MySQL app-user readiness timed out after ${max_attempts} attempts." >&2
            return 1
        fi
        attempt=$((attempt + 1))
        sleep 2
    done

    return 0
}

ci_docker_install_seed_instance() {
    local log_prefix="${1:-ci-docker}"
    shift

    local attempt
    for attempt in 1 2 3; do
        if ci_docker_compose "$@"; then
            return 0
        fi
        echo "[$log_prefix] console install failed on attempt ${attempt}; retrying in 3s." >&2
        sleep 3
    done

    echo "[$log_prefix] console install failed after 3 attempts." >&2
    return 1
}

ci_docker_cleanup_stack() {
    ci_docker_compose down -v --remove-orphans >/dev/null 2>&1 || true
}

ci_docker_stop_services() {
    if [[ "$#" -eq 0 ]]; then
        return
    fi

    ci_docker_compose stop "$@" >/dev/null 2>&1 || true
}
