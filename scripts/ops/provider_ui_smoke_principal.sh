#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 077

readonly SMOKE_USERNAME='__ea_provider_ui_smoke_v1'
readonly CLEANUP_UNIT='fh-provider-ui-smoke-cleanup'

APP_ROOT='/var/www/html/easyappointments'
CREDENTIALS_FILE='/etc/fh/release-gate-provider-ui-smoke.env'
STATE_DIR='/var/lib/fh-provider-ui-smoke'
STATE_FILE=''
REMOVE_CREDENTIALS=0
ACTION=''
ACTIVE_TEMP_FILE=''
ADMISSION_RUN_ID=''
ADMISSION_OPERATION=''
ADMISSION_COORDINATOR=''

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
  --admission-run-id ID       Existing maintenance admission run for activate/deactivate.
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

admission_begin() {
    ADMISSION_OPERATION="$1"
    ADMISSION_RUN_ID="$(python3 "${ADMISSION_COORDINATOR}" begin \
        --operation "${ADMISSION_OPERATION}" \
        --app-root "${APP_ROOT}" \
        --credentials-file "${CREDENTIALS_FILE}" \
        --state-file "${STATE_FILE}" \
        --cleanup-unit "${CLEANUP_UNIT}")" || die "maintenance admission registration failed" 75
    [[ "${ADMISSION_RUN_ID}" =~ ^[a-f0-9]{32}$ ]] || die "maintenance admission run identity is invalid" 75
}

admission_settle() {
    python3 "${ADMISSION_COORDINATOR}" settle \
        --run-id "${ADMISSION_RUN_ID}" \
        --operation "${ADMISSION_OPERATION}" \
        --app-root "${APP_ROOT}" \
        --credentials-file "${CREDENTIALS_FILE}" \
        --state-file "${STATE_FILE}" \
        --cleanup-unit "${CLEANUP_UNIT}" >/dev/null \
        || die "maintenance admission terminal proof failed" 75
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
    local grandparent_dir
    local parent_created=0
    local password

    parent_dir="$(dirname "${CREDENTIALS_FILE}")"
    if [[ ! -e "${parent_dir}" && ! -L "${parent_dir}" ]]; then
        grandparent_dir="$(dirname "${parent_dir}")"
        [[ -d "${grandparent_dir}" && ! -L "${grandparent_dir}" ]] \
            || die "credential parent ancestry is unavailable or unsafe"
        assert_secure_parent_directory "${grandparent_dir}"
        install -d -m 0700 -o root -g root "${parent_dir}"
        parent_created=1
    fi
    assert_secure_parent_directory "${parent_dir}"
    if (( parent_created )); then
        python3 -c 'import os,sys; fd=os.open(sys.argv[1], os.O_RDONLY|os.O_DIRECTORY|os.O_CLOEXEC|os.O_NOFOLLOW); os.fsync(fd); os.close(fd)' \
            "$(dirname "${parent_dir}")" || die "credential parent durability sync failed"
    fi

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
    python3 -c 'import os,sys; fd=os.open(sys.argv[1], os.O_RDONLY|os.O_CLOEXEC|os.O_NOFOLLOW); os.fsync(fd); os.close(fd)' \
        "${ACTIVE_TEMP_FILE}" || die "credential temp durability sync failed"
    mv -T -- "${ACTIVE_TEMP_FILE}" "${CREDENTIALS_FILE}"
    ACTIVE_TEMP_FILE=''
    python3 -c 'import os,sys; fd=os.open(sys.argv[1], os.O_RDONLY|os.O_DIRECTORY|os.O_CLOEXEC|os.O_NOFOLLOW); os.fsync(fd); os.close(fd)' \
        "${parent_dir}" || die "credential directory durability sync failed"

    assert_credentials_file
    printf 'credential_state=created\n'
}

preflight_credentials_before_admission() {
    local parent_dir

    parent_dir="$(dirname "${CREDENTIALS_FILE}")"
    if [[ -e "${parent_dir}" || -L "${parent_dir}" ]]; then
        assert_secure_parent_directory "${parent_dir}"
    fi

    if [[ -e "${CREDENTIALS_FILE}" || -L "${CREDENTIALS_FILE}" ]]; then
        assert_credentials_file
    fi
}

run_lifecycle() {
    local lifecycle_action="$1"
    local expected_state="$2"
    local status
    local expected_line
    local output

    expected_line="provider_ui_smoke action=${lifecycle_action} state=${expected_state} result=ok"$'\n'

    if [[ "${lifecycle_action}" != 'verify' ]]; then
        [[ "${ADMISSION_RUN_ID}" =~ ^[a-f0-9]{32}$ ]] \
            || die "maintenance admission run identity is required" 75
    fi

    set +e
    output="$(
        if cd "${APP_ROOT}"; then
            FH_PROVIDER_UI_SMOKE_RUN_ID="${ADMISSION_RUN_ID}" php index.php console provider_ui_smoke \
                "${lifecycle_action}" \
                "${CREDENTIALS_FILE}" \
                "${STATE_FILE}" 2>&1
            printf '\037%s' "$?"
        else
            printf '\037%s' '2'
        fi
    )"
    set -e
    status="${output##*$'\037'}"
    output="${output%$'\037'*}"

    if [[ ! "${status}" =~ ^[0-9]+$ ]]; then
        die "application lifecycle command returned an invalid status" 75
    fi
    if [[ "${status}" -ne 0 ]]; then
        die "application lifecycle command returned a failure" "${status}"
    fi

    if [[ "${output}" != "${expected_line}" ]]; then
        die "application lifecycle command returned an unexpected result"
    fi
}

verify_dormant() {
    run_lifecycle verify dormant
    assert_state_file_if_present
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
            --admission-run-id)
                [[ $# -ge 2 ]] || die "missing value for --admission-run-id" 64
                ADMISSION_RUN_ID="$2"
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
    require_command python3

    validate_absolute_path "${APP_ROOT}"
    validate_absolute_path "${CREDENTIALS_FILE}"
    validate_absolute_path "${STATE_DIR}"
    STATE_FILE="${STATE_DIR}/active.json"
    ADMISSION_COORDINATOR="${APP_ROOT}/scripts/ops/libexec/provider_ui_smoke_admission_v1.py"

    [[ -f "${ADMISSION_COORDINATOR}" && ! -L "${ADMISSION_COORDINATOR}" ]] \
        || die "maintenance admission coordinator is unavailable"

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
            preflight_credentials_before_admission
            admission_begin install
            ensure_state_directory
            create_credentials_file
            run_lifecycle install dormant
            verify_dormant
            admission_settle
            ;;
        activate)
            [[ "${ADMISSION_RUN_ID}" =~ ^[a-f0-9]{32}$ ]] \
                || die "admission run identity is required for activation" 75
            ADMISSION_OPERATION='smoke'
            assert_state_directory
            assert_credentials_file
            assert_state_file_if_present
            run_lifecycle activate active
            assert_state_file_if_present
            [[ -f "${STATE_FILE}" && ! -L "${STATE_FILE}" ]] || die "active lease state was not created"
            ;;
        deactivate)
            [[ "${ADMISSION_RUN_ID}" =~ ^[a-f0-9]{32}$ ]] \
                || die "admission run identity is required for deactivation" 75
            ADMISSION_OPERATION='smoke'
            assert_state_directory
            assert_state_file_if_present
            run_lifecycle deactivate dormant
            verify_dormant
            ;;
        remove)
            [[ "${REMOVE_CREDENTIALS}" == '0' ]] \
                || die "credential retirement is not enrolled in maintenance admission" 75
            assert_credentials_file
            admission_begin remove
            ensure_state_directory
            assert_state_file_if_present
            run_lifecycle remove removed
            # A second idempotent application transaction is the post-removal
            # verifier. It rechecks exact marker absence before any credential
            # retirement can happen.
            run_lifecycle remove removed
            assert_state_file_if_present
            admission_settle
            # Credential retirement is deliberately refused above.  The
            # admission remains settled only after the retained credentials
            # have been re-checked by the lifecycle verifier.
            printf 'credential_state=retained\n'
            ;;
    esac

    printf 'provider_ui_smoke_wrapper action=%s result=ok\n' "${ACTION}"
}

main "$@"
