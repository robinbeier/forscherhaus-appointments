#!/usr/bin/env bash

CI_DOCKER_COMPOSE_CMD=()
CI_DOCKER_EPHEMERAL_MYSQL_DATA_PATH=""
CI_DOCKER_STACK_STARTED=0
CI_DOCKER_PROJECT_OWNED=0
CI_DOCKER_MYSQL_DATA_CREATED=0
CI_DOCKER_MYSQL_CLEANUP_IMAGE_ID=""

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

# Only opt-in lifecycle callers claim a fresh project. Existing resources are
# never adopted for teardown, including resources of stopped containers.
ci_docker_claim_fresh_project() {
    ci_docker_ensure_compose_project_name
    local resource_kind existing
    if [[ ! "$CI_DOCKER_COMPOSE_PROJECT_NAME" =~ ^[a-z0-9][a-z0-9_-]*$ ]]; then
        echo "[${CI_DOCKER_LOG_PREFIX:-ci-docker}] Invalid temporary project identity." >&2
        return 1
    fi
    for resource_kind in container network volume; do
        if [[ "$resource_kind" == "container" ]]; then
            existing="$(docker container ls -aq --filter "label=com.docker.compose.project=${CI_DOCKER_COMPOSE_PROJECT_NAME}")" || return 1
        else
            existing="$(docker "$resource_kind" ls -q --filter "label=com.docker.compose.project=${CI_DOCKER_COMPOSE_PROJECT_NAME}")" || return 1
        fi
        if [[ -n "$existing" ]]; then
            echo "[${CI_DOCKER_LOG_PREFIX:-ci-docker}] Refusing to adopt an existing Compose project for cleanup." >&2
            return 1
        fi
    done
    # Also protect resources with a matching generated name but missing labels.
    existing="$(docker container ls -aq --filter "name=^/${CI_DOCKER_COMPOSE_PROJECT_NAME}[-_]")" || return 1
    [[ -z "$existing" ]] || return 1
    existing="$(docker network ls -q --filter "name=^${CI_DOCKER_COMPOSE_PROJECT_NAME}_")" || return 1
    [[ -z "$existing" ]] || return 1
    existing="$(docker volume ls -q --filter "name=^${CI_DOCKER_COMPOSE_PROJECT_NAME}_")" || return 1
    [[ -z "$existing" ]] || return 1
    local expected_data_path
    expected_data_path="$(ci_docker_repo_root)/docker/.ci-mysql/$(ci_docker_slugify "$CI_DOCKER_COMPOSE_PROJECT_NAME")"
    if [[ -n "${EA_MYSQL_DATA_PATH:-}" && "$CI_DOCKER_EPHEMERAL_MYSQL_DATA_PATH" != "$expected_data_path" ]] ||
        { [[ -e "$expected_data_path" || -L "$expected_data_path" ]] &&
          [[ "$CI_DOCKER_MYSQL_DATA_CREATED" != "1" || "$CI_DOCKER_EPHEMERAL_MYSQL_DATA_PATH" != "$expected_data_path" ]]; }; then
        echo "[${CI_DOCKER_LOG_PREFIX:-ci-docker}] Refusing to adopt existing or caller-supplied MySQL data." >&2
        return 1
    fi
    CI_DOCKER_PROJECT_OWNED=1
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

    mkdir -p "${repo_root}/docker/.ci-mysql" || return
    if mkdir "${project_data_dir}" 2>/dev/null; then
        CI_DOCKER_MYSQL_DATA_CREATED=1
    elif [[ "$CI_DOCKER_PROJECT_OWNED" == "1" ]]; then
        echo "[${CI_DOCKER_LOG_PREFIX:-ci-docker}] Refusing data directory that appeared after the fresh-project check." >&2
        return 1
    fi

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
    ci_docker_prepare_runtime || return

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

    # Share only a supported local build, never a caller-supplied image.
    # Compose v1 retains its existing project-scoped image behavior.
    CI_DOCKER_PHP_FPM_IMAGE=""
    if [[ "${CI_DOCKER_COMPOSE_CMD[1]}" == "compose" && "${EA_LOCAL_CI_PORTLESS_COMPOSE:-1}" == "1" && -f "$local_ci_compose_override" ]]; then
        local image_platform
        ci_docker_require_cmd python3 "$log_prefix"
        image_platform="${DOCKER_DEFAULT_PLATFORM:-}"
        if [[ -z "$image_platform" ]]; then
            image_platform="$(docker info --format '{{.OSType}}/{{.Architecture}}')" || return
        fi
        CI_DOCKER_PHP_FPM_IMAGE="$("${CI_DOCKER_COMPOSE_CMD[@]}" config --format json | \
            python3 scripts/ci/local_php_image_key.py --platform "$image_platform")" || return
        export CI_DOCKER_PHP_FPM_IMAGE
        if [[ -n "$CI_DOCKER_PHP_FPM_IMAGE" ]]; then
            CI_DOCKER_COMPOSE_CMD+=(-f docker/compose.ci-image.yml)
        fi
    fi
}

ci_docker_compose() {
    local log_prefix="${CI_DOCKER_LOG_PREFIX:-ci-docker}"
    ci_docker_init_compose "$log_prefix" || return
    if [[ "${1:-}" == "build" && -n "${CI_DOCKER_PHP_FPM_IMAGE:-}" ]]; then
        local option
        for option in "$@"; do
            case "$option" in
                --build-arg|--build-arg=*|--ssh|--ssh=*)
                    # Ad-hoc build inputs are not part of the resolved Compose key.
                    # Build under the project name rather than overwrite a shared tag.
                    CI_DOCKER_COMPOSE_CMD=("${CI_DOCKER_COMPOSE_CMD[@]:0:${#CI_DOCKER_COMPOSE_CMD[@]}-2}")
                    CI_DOCKER_PHP_FPM_IMAGE=""
                    "${CI_DOCKER_COMPOSE_CMD[@]}" "$@"
                    return
                    ;;
            esac
        done
    fi
    local compose_action="${1:-}"
    case "$compose_action" in
        create|exec|restart|run|start|up)
            # Mark the project before invoking Docker so a partial resource
            # creation is still eligible for teardown after a failed command.
            CI_DOCKER_STACK_STARTED=1
            ;;
    esac

    "${CI_DOCKER_COMPOSE_CMD[@]}" "$@"
}

ci_docker_build_php_fpm_if_inputs_changed() {
    local base_ref="${1:?base ref is required}"
    local log_prefix="${2:-ci-docker}"

    ci_docker_init_compose "$log_prefix" || return
    if [[ -n "${CI_DOCKER_PHP_FPM_IMAGE:-}" ]]; then
        if docker image inspect "$CI_DOCKER_PHP_FPM_IMAGE" >/dev/null 2>&1; then
            echo "[$log_prefix] Reusing local PHP image $CI_DOCKER_PHP_FPM_IMAGE."
            return 0
        fi
        echo "[$log_prefix] Building local PHP image for these runtime inputs."
        ci_docker_compose build php-fpm
        return
    fi

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

ci_docker_wait_for_easyappointments_mysql_connectivity() {
    local log_prefix="${1:-ci-docker}"
    local max_attempts=30
    local attempt=1
    local php_code

    # The console installer reads the repo-local app config inside php-fpm, so
    # readiness must prove that exact runtime can open a DB connection first.
    php_code='require getcwd() . "/config.php"; $mysqli = @new mysqli(Config::DB_HOST, Config::DB_USERNAME, Config::DB_PASSWORD, Config::DB_NAME); if ($mysqli->connect_errno) { fwrite(STDERR, (string) $mysqli->connect_errno); exit(1); } $mysqli->close();'

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
    local max_attempts="${CI_DOCKER_INSTALL_SEED_MAX_ATTEMPTS:-3}"
    shift

    local attempt
    for ((attempt = 1; attempt <= max_attempts; attempt++)); do
        if ci_docker_compose "$@"; then
            return 0
        fi
        echo "[$log_prefix] console install failed on attempt ${attempt}; retrying in 3s." >&2
        sleep 3
    done

    echo "[$log_prefix] console install failed after ${max_attempts} attempts." >&2
    return 1
}

ci_docker_remove_owned_mysql_data() {
    local data_path="${CI_DOCKER_EPHEMERAL_MYSQL_DATA_PATH:-}"
    [[ -n "$data_path" ]] || return 0
    if [[ "$CI_DOCKER_MYSQL_DATA_CREATED" != "1" || -L "$data_path" ]]; then
        echo "[${CI_DOCKER_LOG_PREFIX:-ci-docker}] Retaining MySQL data not created by this run." >&2
        return 1
    fi
    if rm -rf "$data_path" >/dev/null 2>&1; then
        return 0
    fi

    # Native rootful Docker can leave files owned by the container's MySQL UID.
    # Use the already-local MySQL image, no network, and only the exact owned
    # bind directory. This creates no Compose network and never pulls an image.
    local mysql_image_id="$CI_DOCKER_MYSQL_CLEANUP_IMAGE_ID"
    if [[ -z "$mysql_image_id" ]]; then
        echo "[${CI_DOCKER_LOG_PREFIX:-ci-docker}] No image ID from this run's MySQL container; retaining data." >&2
        return 1
    fi
    if ! docker run --rm --pull=never --network none --read-only --user 0 \
        --cap-drop ALL --cap-add DAC_OVERRIDE --cap-add FOWNER \
        --security-opt no-new-privileges \
        --mount "type=bind,source=${data_path},target=/cleanup" \
        --entrypoint /bin/sh "$mysql_image_id" \
        -c 'find /cleanup -xdev -mindepth 1 -delete' >/dev/null 2>&1; then
        return 1
    fi
    rmdir "$data_path"
}

ci_docker_cleanup_stack() {
    if [[ "${CI_DOCKER_STACK_STARTED:-0}" != "1" ]]; then
        # Image/config preparation can create our empty data directory before
        # any container command runs. Remove only that empty, owned directory.
        if [[ "$CI_DOCKER_PROJECT_OWNED" == "1" && "$CI_DOCKER_MYSQL_DATA_CREATED" == "1" && -n "$CI_DOCKER_EPHEMERAL_MYSQL_DATA_PATH" ]]; then
            if ! rmdir "$CI_DOCKER_EPHEMERAL_MYSQL_DATA_PATH" 2>/dev/null; then
                echo "[${CI_DOCKER_LOG_PREFIX:-ci-docker}] Could not remove empty runtime preparation directory; retaining it." >&2
                return 1
            fi
            CI_DOCKER_MYSQL_DATA_CREATED=0
        fi
        return 0
    fi

    if [[ "${CI_DOCKER_PROJECT_OWNED:-0}" != "1" ]]; then
        echo "[${CI_DOCKER_LOG_PREFIX:-ci-docker}] Refusing cleanup for a project not claimed by this run." >&2
        return 1
    fi

    local cleanup_status=0

    if [[ "${#CI_DOCKER_COMPOSE_CMD[@]}" -eq 0 ]]; then
        echo "[${CI_DOCKER_LOG_PREFIX:-ci-docker}] Cannot clean up: Compose command was not initialized." >&2
        return 1
    fi

    # Capture the immutable image before down removes the container. Engine
    # inspection works with both supported Compose implementations and includes
    # stopped containers. No configuration parsing or image pull is needed.
    local mysql_container_ids mysql_container_id
    CI_DOCKER_MYSQL_CLEANUP_IMAGE_ID=""
    if [[ "$CI_DOCKER_MYSQL_DATA_CREATED" == "1" ]]; then
        mysql_container_ids="$(docker container ls -aq \
            --filter "label=com.docker.compose.project=${CI_DOCKER_COMPOSE_PROJECT_NAME}" \
            --filter 'label=com.docker.compose.service=mysql' 2>/dev/null)" || mysql_container_ids=""
        while IFS= read -r mysql_container_id; do
            [[ -n "$mysql_container_id" ]] || continue
            CI_DOCKER_MYSQL_CLEANUP_IMAGE_ID="$(docker container inspect --format '{{.Image}}' "$mysql_container_id" 2>/dev/null)" || continue
            break
        done <<< "$mysql_container_ids"
    fi

    if ! "${CI_DOCKER_COMPOSE_CMD[@]}" down -v --remove-orphans >/dev/null 2>&1; then
        echo "[${CI_DOCKER_LOG_PREFIX:-ci-docker}] Compose stack cleanup failed." >&2
        cleanup_status=1
    elif ! ci_docker_remove_owned_mysql_data; then
        echo "[${CI_DOCKER_LOG_PREFIX:-ci-docker}] Temporary MySQL data cleanup failed; retaining remaining data." >&2
        cleanup_status=1
    fi

    CI_DOCKER_STACK_STARTED=0
    return "$cleanup_status"
}
