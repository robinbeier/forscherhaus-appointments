#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 077

APP_ROOT='/var/www/html/easyappointments'
CREDENTIALS_FILE='/etc/fh/release-gate-customers-ui-smoke.env'
STATE_DIR='/var/lib/fh-customers-ui-smoke'
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
  bash scripts/ops/customers_ui_smoke_principals.sh ACTION [options]

Server-local, root-only lifecycle wrapper for the Customers UI smoke roles.

Actions: install, verify, activate, deactivate, remove

Options:
  --app-root PATH
  --credentials-file PATH
  --state-dir PATH
  --remove-credentials        With remove only, retire the credential after two verified removals.
  -h, --help

The wrapper never prints credentials, principal IDs, state contents, or application output.
USAGE
}

die() {
    printf 'ERROR: Customers UI smoke principal operation failed: %s\n' "$1" >&2
    exit "${2:-1}"
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || die "required command is unavailable" 69
}

validate_absolute_path() {
    local path="$1"

    [[ "${path}" =~ ^/[A-Za-z0-9._/-]+$ ]] || die "a configured path is invalid" 64
    [[ "${path}" != '/' && "${path}" != */ && "${path}" != *'//'* ]] || die "a configured path is not normalized" 64
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
    local mode

    [[ -d "${path}" && ! -L "${path}" ]] || die "credential parent directory is unavailable or unsafe"
    [[ "$(stat_owner_group "${path}")" == 'root:root' ]] || die "credential parent directory ownership is unsafe"
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
    [[ "${size}" =~ ^[0-9]+$ ]] && (( size > 0 && size <= 65536 )) || die "lease state file size is unsafe"
}

assert_credentials_file() {
    local size

    [[ -f "${CREDENTIALS_FILE}" && ! -L "${CREDENTIALS_FILE}" ]] || die "credential file is unavailable or unsafe"
    [[ "$(stat -Lc '%h' "${CREDENTIALS_FILE}")" == '1' ]] || die "credential file link count is unsafe"
    [[ "$(stat_owner_group "${CREDENTIALS_FILE}")" == 'root:root' ]] || die "credential file ownership is unsafe"
    [[ "$(stat_mode "${CREDENTIALS_FILE}")" == '600' ]] || die "credential file permissions are unsafe"
    size="$(stat -Lc '%s' "${CREDENTIALS_FILE}")"
    [[ "${size}" =~ ^[0-9]+$ ]] && (( size > 0 && size <= 1024 )) || die "credential file size is unsafe"

    php -r '
        $v = parse_ini_file($argv[1], false, INI_SCANNER_RAW);
        $expected = [
            "CUSTOMERS_UI_SMOKE_ADMIN_USERNAME" => "__ea_customers_ui_smoke_admin_v1",
            "CUSTOMERS_UI_SMOKE_PROVIDER_USERNAME" => "__ea_customers_ui_smoke_provider_v1",
            "CUSTOMERS_UI_SMOKE_SECRETARY_USERNAME" => "__ea_customers_ui_smoke_secretary_v1",
            "CUSTOMERS_UI_SMOKE_CUSTOMER_USERNAME" => "__ea_customers_ui_smoke_customer_v1",
        ];
        $valid = is_array($v) && count($v) === 5;
        foreach ($expected as $key => $value) {
            $valid = $valid && ($v[$key] ?? null) === $value;
        }
        $valid = $valid && is_string($v["CUSTOMERS_UI_SMOKE_PASSWORD"] ?? null)
            && preg_match("/\\A[a-f0-9]{64}\\z/D", $v["CUSTOMERS_UI_SMOKE_PASSWORD"]) === 1;
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
    ACTIVE_TEMP_FILE="$(mktemp "${parent_dir}/.customers-ui-smoke.env.XXXXXX")"
    printf '%s\n' \
        'CUSTOMERS_UI_SMOKE_ADMIN_USERNAME=__ea_customers_ui_smoke_admin_v1' \
        'CUSTOMERS_UI_SMOKE_PROVIDER_USERNAME=__ea_customers_ui_smoke_provider_v1' \
        'CUSTOMERS_UI_SMOKE_SECRETARY_USERNAME=__ea_customers_ui_smoke_secretary_v1' \
        'CUSTOMERS_UI_SMOKE_CUSTOMER_USERNAME=__ea_customers_ui_smoke_customer_v1' \
        "CUSTOMERS_UI_SMOKE_PASSWORD=${password}" >"${ACTIVE_TEMP_FILE}"
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
    local expected_line
    local status

    ACTIVE_TEMP_FILE="$(mktemp "${STATE_DIR}/.lifecycle-output.XXXXXX")"
    expected_line="customers_ui_smoke action=${lifecycle_action} state=${expected_state} result=ok"
    set +e
    (
        cd "${APP_ROOT}"
        php index.php console customers_ui_smoke "${lifecycle_action}" "${CREDENTIALS_FILE}" "${STATE_FILE}"
    ) >"${ACTIVE_TEMP_FILE}" 2>&1
    status=$?
    set -e

    [[ "${status}" -eq 0 ]] || die "application lifecycle command returned a failure" "${status}"
    [[ "$(wc -l <"${ACTIVE_TEMP_FILE}" | tr -d '[:space:]')" == '1' ]] \
        && grep -Fqx -- "${expected_line}" "${ACTIVE_TEMP_FILE}" \
        || die "application lifecycle command returned an unexpected result"
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
    retired_dir="$(dirname "${CREDENTIALS_FILE}")/retired-customers-ui-smoke"

    if [[ -e "${retired_dir}" || -L "${retired_dir}" ]]; then
        [[ -d "${retired_dir}" && ! -L "${retired_dir}" ]] || die "retired credential directory is unsafe"
        [[ "$(stat_owner_group "${retired_dir}")" == 'root:root' ]] || die "retired credential directory ownership is unsafe"
        [[ "$(stat_mode "${retired_dir}")" == '700' ]] || die "retired credential directory permissions are unsafe"
    else
        install -d -m 0700 -o root -g root "${retired_dir}"
    fi

    retired_path="${retired_dir}/customers-ui-smoke.$(date -u +%Y%m%dT%H%M%SZ).$$.env"
    [[ ! -e "${retired_path}" && ! -L "${retired_path}" ]] || die "retired credential target already exists"
    mv -T -- "${CREDENTIALS_FILE}" "${retired_path}"
    chown root:root "${retired_path}"
    chmod 0600 "${retired_path}"
    printf 'credential_state=retired\n'
}

parse_args() {
    [[ $# -gt 0 ]] || { usage >&2; exit 64; }
    if [[ "$1" == '-h' || "$1" == '--help' ]]; then usage; exit 0; fi
    ACTION="$1"
    shift

    while [[ $# -gt 0 ]]; do
        case "$1" in
            --app-root) APP_ROOT="${2:-}"; shift 2 ;;
            --credentials-file) CREDENTIALS_FILE="${2:-}"; shift 2 ;;
            --state-dir) STATE_DIR="${2:-}"; shift 2 ;;
            --remove-credentials) REMOVE_CREDENTIALS=1; shift ;;
            -h|--help) usage; exit 0 ;;
            *) die "unknown option" 64 ;;
        esac
    done

    case "${ACTION}" in install|verify|activate|deactivate|remove) ;; *) die "unknown action" 64 ;; esac
    [[ "${REMOVE_CREDENTIALS}" == '0' || "${ACTION}" == 'remove' ]] \
        || die "--remove-credentials is valid only with remove" 64
}

main() {
    parse_args "$@"
    [[ "${EUID}" -eq 0 ]] || die "root privileges are required" 77

    for command in php stat install mktemp grep wc tr; do require_command "${command}"; done
    validate_absolute_path "${APP_ROOT}"
    validate_absolute_path "${CREDENTIALS_FILE}"
    validate_absolute_path "${STATE_DIR}"
    STATE_FILE="${STATE_DIR}/active.json"
    [[ -d "${APP_ROOT}" && -f "${APP_ROOT}/index.php" && ! -L "${APP_ROOT}/index.php" ]] \
        || die "active application root is unavailable or unsafe"

    case "${ACTION}" in
        install)
            require_command openssl
            ensure_state_directory
            create_credentials_file
            run_lifecycle install dormant
            verify_dormant
            ;;
        verify)
            assert_state_directory
            assert_credentials_file
            assert_state_file_if_present
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
            run_lifecycle remove removed
            assert_state_file_if_present
            if [[ "${REMOVE_CREDENTIALS}" == '1' ]]; then retire_credentials_file; else printf 'credential_state=retained\n'; fi
            ;;
    esac

    printf 'customers_ui_smoke_wrapper action=%s result=ok\n' "${ACTION}"
}

main "$@"
