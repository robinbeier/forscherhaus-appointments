#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
SEED_DIR="${REPO_ROOT}/docker/ldap/seed"

LDAP_ADMIN_DN="${LDAP_ADMIN_DN:-cn=admin,dc=example,dc=org}"
LDAP_ADMIN_PASSWORD="${LDAP_ADMIN_PASSWORD:-admin}"
LDAP_URI="${LDAP_URI:-ldap://localhost:389}"
LDAP_COMPOSE_PROJECT_NAME="${LDAP_COMPOSE_PROJECT_NAME:-}"
LDAP_SERVICE_NAME="${LDAP_SERVICE_NAME:-openldap}"

compose_cmd=(docker compose)

if [[ -n "${LDAP_COMPOSE_PROJECT_NAME}" ]]; then
    compose_cmd+=(-p "${LDAP_COMPOSE_PROJECT_NAME}")
fi

compose() {
    (
        cd "${REPO_ROOT}"
        "${compose_cmd[@]}" "$@"
    )
}

ensure_ldap_service_exists() {
    if ! compose config --services | grep -Fxq "${LDAP_SERVICE_NAME}"; then
        echo "Unknown LDAP service: ${LDAP_SERVICE_NAME}" >&2
        echo "Available services:" >&2
        compose config --services >&2
        return 1
    fi
}

default_database_dir() {
    case "${LDAP_SERVICE_NAME}" in
        openldap)
            echo "${REPO_ROOT}/docker/openldap/var"
            ;;
        openldap-legacy)
            echo "${REPO_ROOT}/docker/openldap-legacy/slapd/database"
            ;;
    esac
}

default_config_dir() {
    case "${LDAP_SERVICE_NAME}" in
        openldap)
            echo "${REPO_ROOT}/docker/openldap/etc"
            ;;
        openldap-legacy)
            echo "${REPO_ROOT}/docker/openldap-legacy/slapd/config"
            ;;
    esac
}

default_skip_seed_apply() {
    case "${LDAP_SERVICE_NAME}" in
        openldap)
            echo "1"
            ;;
        openldap-legacy)
            echo "0"
            ;;
    esac
}

configure_ldap_runtime_state() {
    LDAP_DATABASE_DIR="${LDAP_DATABASE_DIR:-$(default_database_dir)}"
    LDAP_CONFIG_DIR="${LDAP_CONFIG_DIR:-$(default_config_dir)}"
    LDAP_SKIP_SEED_APPLY="${LDAP_SKIP_SEED_APPLY:-$(default_skip_seed_apply)}"
}

wait_for_openldap() {
    local attempt

    for attempt in $(seq 1 60); do
        if compose exec -T "${LDAP_SERVICE_NAME}" ldapwhoami -x -H "${LDAP_URI}" -D "${LDAP_ADMIN_DN}" -w "${LDAP_ADMIN_PASSWORD}" >/dev/null 2>&1; then
            # Some images expose a temporary init slapd before their final runtime restart.
            sleep 2

            if compose exec -T "${LDAP_SERVICE_NAME}" ldapwhoami -x -H "${LDAP_URI}" -D "${LDAP_ADMIN_DN}" -w "${LDAP_ADMIN_PASSWORD}" >/dev/null 2>&1; then
                return 0
            fi
        fi

        sleep 1
    done

    echo "LDAP service ${LDAP_SERVICE_NAME} did not become ready after 60 seconds." >&2

    return 1
}

reset_runtime_state() {
    mkdir -p "${LDAP_DATABASE_DIR}" "${LDAP_CONFIG_DIR}"

    find "${LDAP_DATABASE_DIR}" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
    find "${LDAP_CONFIG_DIR}" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
}

apply_seed_files() {
    local seed_file
    local -a seed_files=()

    while IFS= read -r seed_file; do
        seed_files+=("${seed_file}")
    done < <(find "${SEED_DIR}" -maxdepth 1 -type f -name '*.ldif' | sort)

    if [[ ${#seed_files[@]} -eq 0 ]]; then
        echo "No LDAP seed files found in ${SEED_DIR}." >&2
        return 1
    fi

    for seed_file in "${seed_files[@]}"; do
        echo "Applying $(basename "${seed_file}")"
        compose exec -T "${LDAP_SERVICE_NAME}" ldapadd -x -H "${LDAP_URI}" -D "${LDAP_ADMIN_DN}" -w "${LDAP_ADMIN_PASSWORD}" < "${seed_file}"
    done
}

main() {
    if [[ ! -d "${SEED_DIR}" ]]; then
        echo "Missing seed directory: ${SEED_DIR}" >&2
        exit 1
    fi

    ensure_ldap_service_exists
    configure_ldap_runtime_state

    compose stop "${LDAP_SERVICE_NAME}" >/dev/null 2>&1 || true
    compose rm -f -s "${LDAP_SERVICE_NAME}" >/dev/null 2>&1 || true

    reset_runtime_state

    compose up -d "${LDAP_SERVICE_NAME}"
    wait_for_openldap

    if [[ "${LDAP_SKIP_SEED_APPLY}" != "1" ]]; then
        apply_seed_files
    fi

    echo
    echo "LDAP reset complete."
    echo "Next: bash ./scripts/ldap/smoke.sh"
}

main "$@"
