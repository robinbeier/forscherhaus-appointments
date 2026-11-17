#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
SEED_DIR="${REPO_ROOT}/docker/ldap/seed"

LDAP_ADMIN_DN="${LDAP_ADMIN_DN:-cn=admin,dc=example,dc=org}"
LDAP_ADMIN_PASSWORD="${LDAP_ADMIN_PASSWORD:-admin}"
LDAP_URI="${LDAP_URI:-ldap://openldap:389}"
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
    local services
    local services_stderr
    local exit_code

    services_stderr="$(mktemp)"

    if services="$(compose config --services 2>"${services_stderr}")"; then
        rm -f "${services_stderr}"
    else
        exit_code=$?
        echo "Failed to inspect compose services:" >&2
        cat "${services_stderr}" >&2
        rm -f "${services_stderr}"
        return "${exit_code}"
    fi

    if ! grep -Fxq "${LDAP_SERVICE_NAME}" <<<"${services}"; then
        echo "Unknown LDAP service: ${LDAP_SERVICE_NAME}" >&2
        echo "Available services:" >&2
        printf '%s\n' "${services}" >&2
        return 1
    fi
}

default_database_dir() {
    echo "${REPO_ROOT}/docker/openldap/var"
}

default_config_dir() {
    echo "${REPO_ROOT}/docker/openldap/etc"
}

default_skip_seed_apply() {
    echo "1"
}

filesystem_device() {
    case "$(uname -s)" in
        Darwin)
            stat -f '%d' "$1"
            ;;
        *)
            stat -c '%d' -- "$1"
            ;;
    esac
}

mount_targets() {
    case "$(uname -s)" in
        Darwin)
            command -v mount >/dev/null 2>&1 || {
                echo "Cannot validate LDAP mount boundaries: mount is unavailable." >&2
                return 1
            }
            mount | awk -F ' on ' 'NF > 1 { target=$2; sub(/ \(.*/, "", target); print target }'
            ;;
        *)
            command -v findmnt >/dev/null 2>&1 || {
                echo "Cannot validate LDAP mount boundaries: findmnt is unavailable." >&2
                return 1
            }
            findmnt -rn -o TARGET 2>/dev/null || {
                echo "Cannot validate LDAP mount boundaries: findmnt failed." >&2
                return 1
            }
            ;;
    esac
}

configure_ldap_runtime_state() {
    if [[ "${LDAP_SERVICE_NAME}" != "openldap" ]]; then
        if [[ -z "${LDAP_DATABASE_DIR:-}" || -z "${LDAP_CONFIG_DIR:-}" || -z "${LDAP_SKIP_SEED_APPLY:-}" ]]; then
            echo "Unsupported LDAP service defaults: ${LDAP_SERVICE_NAME}" >&2
            echo "Set LDAP_DATABASE_DIR, LDAP_CONFIG_DIR, and LDAP_SKIP_SEED_APPLY explicitly for non-default services." >&2
            exit 1
        fi
    fi

    LDAP_DATABASE_DIR="${LDAP_DATABASE_DIR:-$(default_database_dir)}"
    LDAP_CONFIG_DIR="${LDAP_CONFIG_DIR:-$(default_config_dir)}"
    LDAP_SKIP_SEED_APPLY="${LDAP_SKIP_SEED_APPLY:-$(default_skip_seed_apply)}"
}

validate_runtime_state_dir() {
    local path="$1" label="$2" expected_root="$3" parent canonical

    [[ -n "$path" && "$path" == /* && "$path" != *[[:cntrl:]]* ]] || {
        echo "Unsafe LDAP ${label} directory: path must be an absolute nonempty path." >&2
        return 1
    }
    [[ "$path" != */ && "$path" != *//* && "$path" != */./* && "$path" != */. && "$path" != */../* && "$path" != */.. ]] || {
        echo "Unsafe LDAP ${label} directory: ambiguous traversal path ${path}." >&2
        return 1
    }

    # Check every existing component, including the default bind-mount path.
    # A symlink in an ancestor would otherwise redirect the later recursive
    # deletion even when the final string equals the expected path.
    local probe="$path"
    while [[ "$probe" != "/" ]]; do
        if [[ -L "$probe" ]]; then
            echo "Unsafe LDAP ${label} directory: symlinked path component ${probe}." >&2
            return 1
        fi
        probe="$(dirname -- "$probe")"
    done
    [[ "$path" != "/" && "$path" != "$REPO_ROOT" && ( -z "${HOME:-}" || "$path" != "$HOME" ) ]] || {
        echo "Unsafe LDAP ${label} directory: refusing broad or repository-root target ${path}." >&2
        return 1
    }

    parent="$(dirname -- "$path")"
    [[ -d "$parent" && ! -L "$parent" ]] || {
        echo "Unsafe LDAP ${label} directory parent: ${parent}" >&2
        return 1
    }
    canonical="$(cd -- "$parent" && pwd -P)/$(basename -- "$path")"

    # Mount inspection is a mandatory precondition. A reset must never recurse
    # into a mounted target or a child mount, and silently skipping this check
    # would turn the deletion scope into an environment-dependent guess.
    local mount_targets
    if ! mount_targets="$(mount_targets)"; then
        echo "Cannot validate LDAP ${label} mount boundaries: mount inspection failed." >&2
        return 1
    fi
    local mount_root="$canonical"
    if [[ "$(uname -s)" != "Darwin" ]]; then
        mount_root="${mount_root//\\/\\x5c}"
        mount_root="${mount_root// /\\x20}"
    fi
    local mount_target
    while IFS= read -r mount_target; do
        if [[ "$mount_target" == "$mount_root" || "$mount_target" == "$mount_root/"* ]]; then
            echo "Unsafe LDAP ${label} directory: mount target ${mount_target} is within ${canonical}." >&2
            return 1
        fi
    done <<< "$mount_targets"

    # The default service is intentionally bound to the two compose bind mounts.
    # Ambient overrides are never an authority to widen a destructive reset.
    if [[ "$LDAP_SERVICE_NAME" == "openldap" ]]; then
        [[ "$path" == "$expected_root" ]] || {
            echo "Unsafe LDAP ${label} override for openldap; use the repository bind mount ${expected_root}." >&2
            return 1
        }
        return 0
    fi

    # Custom services may opt into a repository-local runtime directory, but
    # never an arbitrary host path. Existing symlinked paths are rejected.
    [[ "$canonical" == "$REPO_ROOT/docker/ldap-runtime/"* ]] || {
        echo "Unsafe LDAP ${label} directory: custom paths must be below ${REPO_ROOT}/docker/ldap-runtime." >&2
        return 1
    }
    [[ ! -e "$path" || ! -L "$path" ]] || {
        echo "Unsafe LDAP ${label} directory: symlink target refused." >&2
        return 1
    }
    [[ "$(filesystem_device "$parent")" == "$(filesystem_device "$REPO_ROOT")" ]] || {
        echo "Unsafe LDAP ${label} directory: mount boundary refused." >&2
        return 1
    }

}

validate_runtime_state_paths() {
    validate_runtime_state_dir "${LDAP_DATABASE_DIR}" database "$(default_database_dir)" || return
    validate_runtime_state_dir "${LDAP_CONFIG_DIR}" config "$(default_config_dir)" || return
    [[ "${LDAP_DATABASE_DIR}" != "${LDAP_CONFIG_DIR}" ]] || {
        echo "Unsafe LDAP runtime directories: database and config paths must differ." >&2
        return 1
    }
    case "${LDAP_DATABASE_DIR}/" in
        "${LDAP_CONFIG_DIR}/"*|"${LDAP_CONFIG_DIR}"/) echo "Unsafe LDAP runtime directories: paths overlap." >&2; return 1;;
    esac
    case "${LDAP_CONFIG_DIR}/" in
        "${LDAP_DATABASE_DIR}/"*|"${LDAP_DATABASE_DIR}"/) echo "Unsafe LDAP runtime directories: paths overlap." >&2; return 1;;
    esac
}

wait_for_openldap() {
    local attempt

    for attempt in $(seq 1 60); do
        if compose exec -T "${LDAP_SERVICE_NAME}" ldapwhoami -x -ZZ -H "${LDAP_URI}" -D "${LDAP_ADMIN_DN}" -w "${LDAP_ADMIN_PASSWORD}" >/dev/null 2>&1; then
            # Some images expose a temporary init slapd before their final runtime restart.
            sleep 2

            if compose exec -T "${LDAP_SERVICE_NAME}" ldapwhoami -x -ZZ -H "${LDAP_URI}" -D "${LDAP_ADMIN_DN}" -w "${LDAP_ADMIN_PASSWORD}" >/dev/null 2>&1; then
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
        compose exec -T "${LDAP_SERVICE_NAME}" ldapadd -x -ZZ -H "${LDAP_URI}" -D "${LDAP_ADMIN_DN}" -w "${LDAP_ADMIN_PASSWORD}" < "${seed_file}"
    done
}

main() {
    if [[ ! -d "${SEED_DIR}" ]]; then
        echo "Missing seed directory: ${SEED_DIR}" >&2
        exit 1
    fi

    ensure_ldap_service_exists
    configure_ldap_runtime_state
    validate_runtime_state_paths

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
