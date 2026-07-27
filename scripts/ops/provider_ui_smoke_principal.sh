#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 077

readonly SMOKE_USERNAME='__ea_provider_ui_smoke_v1'

APP_ROOT='/var/www/html/easyappointments'
CREDENTIALS_FILE='/etc/fh/release-gate-provider-ui-smoke.env'
STATE_DIR='/var/lib/fh-provider-ui-smoke'
STATE_FILE=''
REMOVE_CREDENTIALS=0
ACTION=''
ACTIVE_TEMP_FILE=''

cleanup_temp_file() {
    if [[ -n "${ACTIVE_TEMP_FILE}" ]]; then
        rm -f -- "${ACTIVE_TEMP_FILE}"
    fi
}

trap cleanup_temp_file EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

usage() {
    cat <<'USAGE'
Usage:
  bash scripts/ops/provider_ui_smoke_principal.sh ACTION [options]

Server-local, root-only lifecycle wrapper for the production Provider UI smoke.

Actions:
  install       Create the root-only credential when absent and install the
                permanent dormant principal.
  verify        Verify that the principal is dormant and the fixture is clean.
  activate      Start the short provider lease and create the synthetic fixture.
  deactivate    Remove the synthetic fixture and return the principal to dormant.
  remove        Remove the principal and all owned smoke state.

Options:
  --app-root PATH             Active production app root.
  --credentials-file PATH    Root-only INI credential file.
  --state-dir PATH            Root-only lease state directory.
  --remove-credentials        With remove only: move the credential into a
                              root-only retired directory after verified removal.
  -h, --help                  Show this help.

The wrapper never prints credential contents, fixture IDs, or lifecycle output.
USAGE
}

die() {
    printf 'ERROR: provider UI smoke principal operation failed: %s\n' "$1" >&2
    exit "${2:-1}"
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || die "required command is unavailable" 69
}

validate_absolute_path() {
    local path="$1"

    [[ "${path}" =~ ^/[A-Za-z0-9._/-]+$ ]] || die "a configured path is invalid" 64
    [[ "${path}" != '/' ]] || die "a configured path is too broad" 64
    [[ "${path}" != */ ]] || die "a configured path is not normalized" 64
    [[ "${path}" != *'//'* ]] || die "a configured path is not normalized" 64
    [[ "${path}" != *'/./'* && "${path}" != */. ]] || die "a configured path is not normalized" 64
    [[ "${path}" != *'/../'* && "${path}" != */.. ]] || die "a configured path is not normalized" 64
}

stat_owner_group() {
    stat -Lc '%U:%G' "$1"
}

stat_mode() {
    stat -Lc '%a' "$1"
}

assert_secure_parent_directory() {
    local path="$1"

    [[ -d "${path}" && ! -L "${path}" ]] || die "credential parent directory is unavailable or unsafe"
    [[ "$(stat_owner_group "${path}")" == 'root:root' ]] || die "credential parent directory ownership is unsafe"

    local mode
    mode="$(stat_mode "${path}")"
    (( (8#${mode} & 8#022) == 0 )) || die "credential parent directory permissions are unsafe"
}

assert_state_directory() {
    [[ -d "${STATE_DIR}" && ! -L "${STATE_DIR}" ]] || die "state directory is unavailable or unsafe"
    [[ "$(stat_owner_group "${STATE_DIR}")" == 'root:root' ]] || die "state directory ownership is unsafe"
    [[ "$(stat_mode "${STATE_DIR}")" == '700' ]] || die "state directory permissions are unsafe"
}

ensure_state_directory() {
    if [[ -e "${STATE_DIR}" || -L "${STATE_DIR}" ]]; then
        assert_state_directory
        return
    fi

    install -d -m 0700 -o root -g root "${STATE_DIR}"
    assert_state_directory
}

assert_state_file_if_present() {
    local size

    if [[ ! -e "${STATE_FILE}" && ! -L "${STATE_FILE}" ]]; then
        return
    fi

    [[ -f "${STATE_FILE}" && ! -L "${STATE_FILE}" ]] || die "lease state file is unsafe"
    [[ "$(stat -Lc '%h' "${STATE_FILE}")" == '1' ]] || die "lease state file link count is unsafe"
    [[ "$(stat_owner_group "${STATE_FILE}")" == 'root:root' ]] || die "lease state file ownership is unsafe"
    [[ "$(stat_mode "${STATE_FILE}")" == '600' ]] || die "lease state file permissions are unsafe"
    size="$(stat -Lc '%s' "${STATE_FILE}")"
    [[ "${size}" =~ ^[0-9]+$ ]] && (( size > 0 && size <= 65536 )) \
        || die "lease state file size is unsafe"
}

assert_credentials_file() {
    local size

    [[ -f "${CREDENTIALS_FILE}" && ! -L "${CREDENTIALS_FILE}" ]] || die "credential file is unavailable or unsafe"
    [[ "$(stat -Lc '%h' "${CREDENTIALS_FILE}")" == '1' ]] || die "credential file link count is unsafe"
    [[ "$(stat_owner_group "${CREDENTIALS_FILE}")" == 'root:root' ]] || die "credential file ownership is unsafe"
    [[ "$(stat_mode "${CREDENTIALS_FILE}")" == '600' ]] || die "credential file permissions are unsafe"
    [[ -r "${CREDENTIALS_FILE}" ]] || die "credential file is unreadable"
    size="$(stat -Lc '%s' "${CREDENTIALS_FILE}")"
    [[ "${size}" =~ ^[0-9]+$ ]] && (( size > 0 && size <= 512 )) \
        || die "credential file size is unsafe"

    php -r '
        $values = parse_ini_file($argv[1], false, INI_SCANNER_RAW);
        $valid = is_array($values)
            && count($values) === 2
            && ($values["PROVIDER_UI_SMOKE_USERNAME"] ?? null) === "__ea_provider_ui_smoke_v1"
            && is_string($values["PROVIDER_UI_SMOKE_PASSWORD"] ?? null)
            && preg_match("/\\A[a-f0-9]{64}\\z/D", $values["PROVIDER_UI_SMOKE_PASSWORD"]) === 1;
        exit($valid ? 0 : 1);
    ' "${CREDENTIALS_FILE}" >/dev/null 2>&1 || die "credential file shape is invalid"
}

create_credentials_file() {
    local parent_dir
    local password

    parent_dir="$(dirname "${CREDENTIALS_FILE}")"
    if [[ ! -e "${parent_dir}" && ! -L "${parent_dir}" ]]; then
        install -d -m 0700 -o root -g root "${parent_dir}"
    fi
    assert_secure_parent_directory "${parent_dir}"

    if [[ -e "${CREDENTIALS_FILE}" || -L "${CREDENTIALS_FILE}" ]]; then
        assert_credentials_file
        printf 'credential_state=retained\n'
        return
    fi

    password="$(openssl rand -hex 32)"
    [[ "${password}" =~ ^[a-f0-9]{64}$ ]] || die "credential generation failed"

    ACTIVE_TEMP_FILE="$(mktemp "${parent_dir}/.provider-ui-smoke.env.XXXXXX")"
    printf 'PROVIDER_UI_SMOKE_USERNAME=%s\nPROVIDER_UI_SMOKE_PASSWORD=%s\n' \
        "${SMOKE_USERNAME}" "${password}" >"${ACTIVE_TEMP_FILE}"
    unset password
    chown root:root "${ACTIVE_TEMP_FILE}"
    chmod 0600 "${ACTIVE_TEMP_FILE}"
    mv -T -- "${ACTIVE_TEMP_FILE}" "${CREDENTIALS_FILE}"
    ACTIVE_TEMP_FILE=''

    assert_credentials_file
    printf 'credential_state=created\n'
}

run_lifecycle() {
    local lifecycle_action="$1"
    local expected_state="$2"
    local status
    local expected_line

    ACTIVE_TEMP_FILE="$(mktemp "${STATE_DIR}/.lifecycle-output.XXXXXX")"
    expected_line="provider_ui_smoke action=${lifecycle_action} state=${expected_state} result=ok"

    set +e
    (
        cd "${APP_ROOT}"
        php index.php console provider_ui_smoke \
            "${lifecycle_action}" \
            "${CREDENTIALS_FILE}" \
            "${STATE_FILE}"
    ) >"${ACTIVE_TEMP_FILE}" 2>&1
    status=$?
    set -e

    if [[ "${status}" -ne 0 ]]; then
        die "application lifecycle command returned a failure" "${status}"
    fi

    if [[ "$(wc -l <"${ACTIVE_TEMP_FILE}" | tr -d '[:space:]')" != '1' ]] \
        || ! grep -Fqx -- "${expected_line}" "${ACTIVE_TEMP_FILE}"; then
        die "application lifecycle command returned an unexpected result"
    fi

    rm -f -- "${ACTIVE_TEMP_FILE}"
    ACTIVE_TEMP_FILE=''
}

verify_dormant() {
    run_lifecycle verify dormant
    assert_state_file_if_present
}

retire_credentials_file() {
    local retired_dir
    local retired_path

    [[ -f "${CREDENTIALS_FILE}" && ! -L "${CREDENTIALS_FILE}" ]] || {
        printf 'credential_state=absent\n'
        return
    }
    assert_credentials_file

    retired_dir="$(dirname "${CREDENTIALS_FILE}")/retired-provider-ui-smoke"
    if [[ -e "${retired_dir}" || -L "${retired_dir}" ]]; then
        [[ -d "${retired_dir}" && ! -L "${retired_dir}" ]] || die "retired credential directory is unsafe"
        [[ "$(stat_owner_group "${retired_dir}")" == 'root:root' ]] || die "retired credential directory ownership is unsafe"
        [[ "$(stat_mode "${retired_dir}")" == '700' ]] || die "retired credential directory permissions are unsafe"
    else
        install -d -m 0700 -o root -g root "${retired_dir}"
    fi

    retired_path="${retired_dir}/provider-ui-smoke.$(date -u +%Y%m%dT%H%M%SZ).$$.env"
    [[ ! -e "${retired_path}" && ! -L "${retired_path}" ]] || die "retired credential target already exists"
    mv -T -- "${CREDENTIALS_FILE}" "${retired_path}"
    chown root:root "${retired_path}"
    chmod 0600 "${retired_path}"
    printf 'credential_state=retired\n'
}

parse_args() {
    [[ $# -gt 0 ]] || {
        usage >&2
        exit 64
    }

    if [[ "$1" == '-h' || "$1" == '--help' ]]; then
        usage
        exit 0
    fi

    ACTION="$1"
    shift

    while [[ $# -gt 0 ]]; do
        case "$1" in
            --app-root)
                [[ $# -ge 2 ]] || die "missing value for --app-root" 64
                APP_ROOT="$2"
                shift 2
                ;;
            --credentials-file)
                [[ $# -ge 2 ]] || die "missing value for --credentials-file" 64
                CREDENTIALS_FILE="$2"
                shift 2
                ;;
            --state-dir)
                [[ $# -ge 2 ]] || die "missing value for --state-dir" 64
                STATE_DIR="$2"
                shift 2
                ;;
            --remove-credentials)
                REMOVE_CREDENTIALS=1
                shift
                ;;
            -h|--help)
                usage
                exit 0
                ;;
            *)
                die "unknown option" 64
                ;;
        esac
    done

    case "${ACTION}" in
        install|verify|activate|deactivate|remove)
            ;;
        *)
            die "unknown action" 64
            ;;
    esac

    if [[ "${REMOVE_CREDENTIALS}" == '1' && "${ACTION}" != 'remove' ]]; then
        die "--remove-credentials is valid only with remove" 64
    fi
}

main() {
    parse_args "$@"

    [[ "${EUID}" -eq 0 ]] || die "root privileges are required" 77

    require_command php
    require_command stat
    require_command install
    require_command mktemp
    require_command grep
    require_command wc
    require_command tr

    validate_absolute_path "${APP_ROOT}"
    validate_absolute_path "${CREDENTIALS_FILE}"
    validate_absolute_path "${STATE_DIR}"
    STATE_FILE="${STATE_DIR}/active.json"

    [[ -d "${APP_ROOT}" && ! -L "${APP_ROOT}/index.php" && -f "${APP_ROOT}/index.php" ]] \
        || die "active application root is unavailable or unsafe"

    case "${ACTION}" in
        verify)
            assert_state_directory
            assert_credentials_file
            assert_state_file_if_present
            verify_dormant
            ;;
        install)
            require_command openssl
            ensure_state_directory
            create_credentials_file
            run_lifecycle install dormant
            verify_dormant
            ;;
        activate)
            ensure_state_directory
            assert_credentials_file
            assert_state_file_if_present
            run_lifecycle activate active
            assert_state_file_if_present
            [[ -f "${STATE_FILE}" && ! -L "${STATE_FILE}" ]] || die "active lease state was not created"
            ;;
        deactivate)
            ensure_state_directory
            assert_state_file_if_present
            run_lifecycle deactivate dormant
            verify_dormant
            ;;
        remove)
            ensure_state_directory
            assert_state_file_if_present
            run_lifecycle remove removed
            # A second idempotent application transaction is the post-removal
            # verifier. It rechecks exact marker absence before any credential
            # retirement can happen.
            run_lifecycle remove removed
            assert_state_file_if_present
            if [[ "${REMOVE_CREDENTIALS}" == '1' ]]; then
                retire_credentials_file
            else
                printf 'credential_state=retained\n'
            fi
            ;;
    esac

    printf 'provider_ui_smoke_wrapper action=%s result=ok\n' "${ACTION}"
}

main "$@"
