#!/usr/bin/env bash

CI_DOCKER_COMPOSE_CMD=()
CI_DOCKER_EPHEMERAL_MYSQL_DATA_PATH=""

ci_docker_require_cmd() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "[$2] Missing required command: $1" >&2
        exit 1
    fi
}

ci_docker_slugify() {
    printf '%s' "$1" | tr '[:upper:]' '[:lower:]' | tr -cs 'a-z0-9' '-'
}

ci_docker_repo_root() {
    if git rev-parse --show-toplevel >/dev/null 2>&1; then
        git rev-parse --show-toplevel
        return
    fi

    pwd
}

ci_docker_ensure_compose_project_name() {
    if [[ -n "${CI_DOCKER_COMPOSE_PROJECT_NAME:-}" ]]; then
        return
    fi

    local repo_root
    local repo_slug
    local git_identity_path
    local git_dir_checksum

    repo_root="$(ci_docker_repo_root)"
    repo_slug="$(ci_docker_slugify "$(basename "${repo_root}")")"
    git_identity_path="${repo_root}"

    if git rev-parse --absolute-git-dir >/dev/null 2>&1; then
        git_identity_path="$(git rev-parse --absolute-git-dir)"
    fi

    git_dir_checksum="$(printf '%s' "${git_identity_path}" | cksum | awk '{print $1}')"
    CI_DOCKER_COMPOSE_PROJECT_NAME="${repo_slug}-local-ci-${git_dir_checksum}"
    export CI_DOCKER_COMPOSE_PROJECT_NAME
}

ci_docker_configure_mysql_data_path() {
    if [[ -n "${EA_MYSQL_DATA_PATH:-}" ]]; then
        CI_DOCKER_EPHEMERAL_MYSQL_DATA_PATH=""
        return
    fi

    local repo_root
    local project_data_dir_key
    local project_data_dir

    repo_root="$(ci_docker_repo_root)"
    project_data_dir_key="$(ci_docker_slugify "${CI_DOCKER_COMPOSE_PROJECT_NAME}")"
    if [[ -z "${project_data_dir_key}" ]]; then
        project_data_dir_key="local-ci"
    fi

    project_data_dir="${repo_root}/docker/.ci-mysql/${project_data_dir_key}"

    mkdir -p "${project_data_dir}"

    EA_MYSQL_DATA_PATH="./docker/.ci-mysql/${project_data_dir_key}"
    export EA_MYSQL_DATA_PATH
    CI_DOCKER_EPHEMERAL_MYSQL_DATA_PATH="${project_data_dir}"
}

ci_docker_prepare_runtime() {
    ci_docker_ensure_compose_project_name
    ci_docker_configure_mysql_data_path
}

ci_docker_php_fpm_inputs_changed() {
    local base_ref="${1:?base ref is required}"
    local changed_paths

    changed_paths="$(git_ci_collect_changed_paths "$base_ref")"

    while IFS= read -r path; do
        case "$path" in
            docker-compose.yml|\
            docker/compose.ci-local.yml|\
            docker/php-fpm/*|\
            docker/php-fpm/*/*)
                return 0
                ;;
        esac
    done <<< "$changed_paths"

    return 1
}

ci_docker_init_compose() {
    local log_prefix="${1:-ci-docker}"
    local local_ci_compose_override="${EA_LOCAL_CI_COMPOSE_OVERRIDE_PATH:-docker/compose.ci-local.yml}"

    if [[ "${#CI_DOCKER_COMPOSE_CMD[@]}" -gt 0 ]]; then
        return
    fi

    ci_docker_require_cmd docker "$log_prefix"
    ci_docker_prepare_runtime

    if docker compose version >/dev/null 2>&1; then
        CI_DOCKER_COMPOSE_CMD=(docker compose)
    elif command -v docker-compose >/dev/null 2>&1; then
        CI_DOCKER_COMPOSE_CMD=(docker-compose)
    else
        echo "[$log_prefix] docker compose command not found." >&2
        exit 1
    fi

    if [[ -n "${CI_DOCKER_COMPOSE_PROJECT_NAME:-}" ]]; then
        CI_DOCKER_COMPOSE_CMD+=(-p "${CI_DOCKER_COMPOSE_PROJECT_NAME}")
    fi

    if [[ "${EA_LOCAL_CI_PORTLESS_COMPOSE:-1}" == "1" && -f "$local_ci_compose_override" ]]; then
        CI_DOCKER_COMPOSE_CMD+=(-f docker-compose.yml -f "$local_ci_compose_override")
    fi
}

ci_docker_compose() {
    local log_prefix="${CI_DOCKER_LOG_PREFIX:-ci-docker}"
    ci_docker_init_compose "$log_prefix"
    "${CI_DOCKER_COMPOSE_CMD[@]}" "$@"
}

ci_docker_build_php_fpm_if_inputs_changed() {
    local base_ref="${1:?base ref is required}"
    local log_prefix="${2:-ci-docker}"

    if ! ci_docker_php_fpm_inputs_changed "$base_ref"; then
        return 0
    fi

    echo "[$log_prefix] Rebuilding php-fpm image because Docker runtime inputs changed."
    ci_docker_compose build php-fpm
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

ci_docker_wait_for_service_exec() {
    local service="${1:?service is required}"
    local log_prefix="${2:-ci-docker}"
    shift 2

    if [[ "$#" -eq 0 ]]; then
        echo "[$log_prefix] ci_docker_wait_for_service_exec requires a command." >&2
        return 1
    fi

    local max_attempts=30
    local attempt=1

    until ci_docker_compose exec -T "$service" "$@" >/dev/null 2>&1; do
        if [[ "$attempt" -ge "$max_attempts" ]]; then
            echo "[$log_prefix] ${service} exec readiness timed out after ${max_attempts} attempts." >&2
            return 1
        fi
        attempt=$((attempt + 1))
        sleep 2
    done

    return 0
}

ci_docker_wait_for_php_mysql_connectivity() {
    local log_prefix="${1:-ci-docker}"
    local max_attempts=30
    local attempt=1
    local php_code

    php_code='$mysqli = @new mysqli("mysql", "user", "password", "easyappointments"); if ($mysqli->connect_errno) { fwrite(STDERR, (string) $mysqli->connect_errno); exit(1); } $mysqli->close();'

    until ci_docker_compose exec -T php-fpm php -r "$php_code" >/dev/null 2>&1; do
        if [[ "$attempt" -ge "$max_attempts" ]]; then
            echo "[$log_prefix] php-fpm could not reach MySQL after ${max_attempts} attempts." >&2
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
    for attempt in 1 2 3 4 5; do
        if ci_docker_compose "$@"; then
            return 0
        fi
        echo "[$log_prefix] console install failed on attempt ${attempt}; retrying in 3s." >&2
        sleep 3
    done

    echo "[$log_prefix] console install failed after 5 attempts." >&2
    return 1
}

ci_docker_cleanup_stack() {
    ci_docker_compose down -v --remove-orphans >/dev/null 2>&1 || true
    if [[ -n "${CI_DOCKER_EPHEMERAL_MYSQL_DATA_PATH:-}" ]]; then
        rm -rf "${CI_DOCKER_EPHEMERAL_MYSQL_DATA_PATH}" >/dev/null 2>&1 || true
    fi
}
