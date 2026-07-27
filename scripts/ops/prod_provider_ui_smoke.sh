#!/usr/bin/env bash
set -Eeuo pipefail
set +x

if [[ "$(uname -s)" == "Darwin" ]]; then
    export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
    DEFAULT_BROWSER='chrome'
else
    DEFAULT_BROWSER='firefox'
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
# shellcheck source=scripts/ops/lib/prod_common.sh
source "${SCRIPT_DIR}/lib/prod_common.sh"

readonly CLEANUP_UNIT='fh-provider-ui-smoke-cleanup'
readonly CLEANUP_HARD_EXIT=90

SSH_OPTIONS=(-o StrictHostKeyChecking=accept-new)
PROD_SSH_TARGET="$(prod_default_ssh_target)"
BASE_URL='https://dasforscherhaus-leg.de'
INDEX_PAGE='index.php'
APP_ROOT='/var/www/html/easyappointments'
CREDENTIALS_FILE='/etc/fh/release-gate-provider-ui-smoke.env'
STATE_DIR='/var/lib/fh-provider-ui-smoke'
PWCLI_PATH="${REPO_ROOT}/scripts/release-gate/playwright/playwright_cli.sh"
GATE_PATH="${REPO_ROOT}/scripts/release-gate/provider_ui_smoke.php"
BROWSER="${PROVIDER_UI_SMOKE_BROWSER:-${DEFAULT_BROWSER}}"
OUTPUT_JSON=''
BOOTSTRAP_TIMEOUT='90'
OPEN_TIMEOUT='30'
DOWNLOAD_TIMEOUT='30'
MIN_PDF_BYTES='1024'
PHP_BIN="${PROVIDER_UI_SMOKE_PHP_BIN:-php}"
CURL_BIN="${PROVIDER_UI_SMOKE_CURL_BIN:-curl}"
NPX_BIN="${PROVIDER_UI_SMOKE_NPX_BIN:-npx}"
PDFINFO_BIN="${PROVIDER_UI_SMOKE_PDFINFO_BIN:-pdfinfo}"
PDFTOTEXT_BIN="${PROVIDER_UI_SMOKE_PDFTOTEXT_BIN:-pdftotext}"

REMOTE_CLEANUP_ARMED=0
REMOTE_CLEANUP_REQUIRED=0

usage() {
    cat <<'USAGE'
Usage:
  bash scripts/ops/prod_provider_ui_smoke.sh [options]

Run the on-demand production Provider UI smoke from the operator workstation.
The production host remains free of Node/npm. A ten-minute independent systemd
cleanup timer is armed before the short provider lease is activated.

Options:
  --prod-ssh-target TARGET   Production SSH target.
  --base-url URL             Production app base URL.
  --index-page PAGE          Front-controller segment (default: index.php).
  --app-root PATH            Active production app root.
  --credentials-file PATH    Remote root-only credential file.
  --state-dir PATH           Remote root-only lease state directory.
  --pwcli-path PATH          Local Playwright CLI wrapper.
  --browser NAME             Browser channel (default: chrome on macOS,
                             firefox elsewhere).
  --output-json PATH         Optional PII-free local JSON result.
  --bootstrap-timeout SEC    Playwright bootstrap timeout.
  --open-timeout SEC         Browser navigation timeout.
  --download-timeout SEC     PDF download timeout.
  --min-pdf-bytes BYTES      Minimum accepted PDF size.
  -h, --help                 Show this help.

Exit codes:
  0   Gate and cleanup passed.
  1   Gate behavior assertion failed.
  2   Gate runtime/configuration failed.
  20  Read-only preflight failed.
  21  Remote activation failed.
  22  Independent cleanup timer could not be armed.
  90  Hard stop: remote deactivate or dormant verification failed.
USAGE
}

die() {
    printf 'ERROR: %s\n' "$1" >&2
    exit "${2:-1}"
}

validate_remote_path() {
    local path="$1"

    [[ "${path}" =~ ^/[A-Za-z0-9._/-]+$ ]] || die "invalid remote path" 64
    [[ "${path}" != '/' ]] || die "remote path is too broad" 64
    [[ "${path}" != */ ]] || die "remote path is not normalized" 64
    [[ "${path}" != *'//'* ]] || die "remote path is not normalized" 64
    [[ "${path}" != *'/./'* && "${path}" != */. ]] || die "remote path is not normalized" 64
    [[ "${path}" != *'/../'* && "${path}" != */.. ]] || die "remote path is not normalized" 64
}

validate_uint() {
    local value="$1"
    local label="$2"

    [[ "${value}" =~ ^[1-9][0-9]*$ ]] || die "${label} must be a positive integer" 64
}

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --prod-ssh-target)
                [[ $# -ge 2 ]] || die "missing value for --prod-ssh-target" 64
                PROD_SSH_TARGET="$2"
                shift 2
                ;;
            --base-url)
                [[ $# -ge 2 ]] || die "missing value for --base-url" 64
                BASE_URL="$2"
                shift 2
                ;;
            --index-page)
                [[ $# -ge 2 ]] || die "missing value for --index-page" 64
                INDEX_PAGE="$2"
                shift 2
                ;;
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
            --pwcli-path)
                [[ $# -ge 2 ]] || die "missing value for --pwcli-path" 64
                PWCLI_PATH="$2"
                shift 2
                ;;
            --browser)
                [[ $# -ge 2 ]] || die "missing value for --browser" 64
                BROWSER="$2"
                shift 2
                ;;
            --output-json)
                [[ $# -ge 2 ]] || die "missing value for --output-json" 64
                OUTPUT_JSON="$2"
                shift 2
                ;;
            --bootstrap-timeout)
                [[ $# -ge 2 ]] || die "missing value for --bootstrap-timeout" 64
                BOOTSTRAP_TIMEOUT="$2"
                shift 2
                ;;
            --open-timeout)
                [[ $# -ge 2 ]] || die "missing value for --open-timeout" 64
                OPEN_TIMEOUT="$2"
                shift 2
                ;;
            --download-timeout)
                [[ $# -ge 2 ]] || die "missing value for --download-timeout" 64
                DOWNLOAD_TIMEOUT="$2"
                shift 2
                ;;
            --min-pdf-bytes)
                [[ $# -ge 2 ]] || die "missing value for --min-pdf-bytes" 64
                MIN_PDF_BYTES="$2"
                shift 2
                ;;
            -h|--help)
                usage
                exit 0
                ;;
            *)
                die "unknown option: $1" 64
                ;;
        esac
    done
}

validate_config() {
    [[ "${BASE_URL}" =~ ^https?://[^[:space:]/]+(:[0-9]+)?(/[^[:space:]]*)?$ ]] \
        || die "base URL is invalid" 64
    BASE_URL="${BASE_URL%/}"
    [[ "${INDEX_PAGE}" =~ ^[A-Za-z0-9._/-]+$ ]] || die "index page is invalid" 64

    validate_remote_path "${APP_ROOT}"
    validate_remote_path "${CREDENTIALS_FILE}"
    validate_remote_path "${STATE_DIR}"
    validate_uint "${BOOTSTRAP_TIMEOUT}" "bootstrap timeout"
    validate_uint "${OPEN_TIMEOUT}" "open timeout"
    validate_uint "${DOWNLOAD_TIMEOUT}" "download timeout"
    validate_uint "${MIN_PDF_BYTES}" "minimum PDF bytes"
    BROWSER="$(printf '%s' "${BROWSER}" | tr '[:upper:]' '[:lower:]')"
    case "${BROWSER}" in
        chrome|firefox|webkit|msedge) ;;
        *) die "browser must be chrome, firefox, webkit, or msedge" 64 ;;
    esac
    export PLAYWRIGHT_MCP_BROWSER="${BROWSER}"

    [[ -f "${GATE_PATH}" && ! -L "${GATE_PATH}" ]] || die "provider UI smoke gate is unavailable" 2
    [[ -f "${PWCLI_PATH}" && ! -L "${PWCLI_PATH}" && -r "${PWCLI_PATH}" ]] \
        || die "Playwright wrapper is unavailable or unreadable" 2
}

remote_wrapper_path() {
    printf '%s/scripts/ops/provider_ui_smoke_principal.sh' "${APP_ROOT}"
}

remote_principal() {
    local action="$1"
    local wrapper

    wrapper="$(remote_wrapper_path)"
    ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" \
        "exec bash '${wrapper}' '${action}' --app-root '${APP_ROOT}' --credentials-file '${CREDENTIALS_FILE}' --state-dir '${STATE_DIR}'"
}

remote_static_preflight() {
    local wrapper
    local state_file

    wrapper="$(remote_wrapper_path)"
    state_file="${STATE_DIR}/active.json"

    ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" "
set -euo pipefail
[[ \"\$(id -u)\" == '0' ]] || { printf 'ERROR: root SSH is required\n' >&2; exit 1; }
for required_command in bash php systemctl systemd-run stat cat flock; do
    command -v \"\${required_command}\" >/dev/null 2>&1 || {
        printf 'ERROR: a required remote command is unavailable\n' >&2
        exit 1
    }
done
if command -v node >/dev/null 2>&1 || command -v npm >/dev/null 2>&1; then
    printf 'ERROR: production host Node/npm absence invariant failed\n' >&2
    exit 1
fi
[[ -d '${APP_ROOT}' && -f '${APP_ROOT}/index.php' && ! -L '${APP_ROOT}/index.php' ]] || {
    printf 'ERROR: active application root is unavailable or unsafe\n' >&2
    exit 1
}
[[ -f '${wrapper}' && ! -L '${wrapper}' ]] || {
    printf 'ERROR: deployed principal wrapper is unavailable or unsafe\n' >&2
    exit 1
}
[[ \"\$(stat -Lc '%h' '${wrapper}')\" == '1' ]] || {
    printf 'ERROR: deployed principal wrapper link count is unsafe\n' >&2
    exit 1
}
wrapper_size=\"\$(stat -Lc '%s' '${wrapper}')\"
[[ \"\${wrapper_size}\" =~ ^[0-9]+$ ]] && (( wrapper_size > 0 && wrapper_size <= 131072 )) || {
    printf 'ERROR: deployed principal wrapper size is unsafe\n' >&2
    exit 1
}
[[ -f '${CREDENTIALS_FILE}' && ! -L '${CREDENTIALS_FILE}' ]] || {
    printf 'ERROR: credential file is unavailable or unsafe\n' >&2
    exit 1
}
[[ \"\$(stat -Lc '%h' '${CREDENTIALS_FILE}')\" == '1' ]] || {
    printf 'ERROR: credential link count is unsafe\n' >&2
    exit 1
}
[[ \"\$(stat -Lc '%U:%G' '${CREDENTIALS_FILE}')\" == 'root:root' ]] || {
    printf 'ERROR: credential ownership is unsafe\n' >&2
    exit 1
}
[[ \"\$(stat -Lc '%a' '${CREDENTIALS_FILE}')\" == '600' ]] || {
    printf 'ERROR: credential permissions are unsafe\n' >&2
    exit 1
}
credential_size=\"\$(stat -Lc '%s' '${CREDENTIALS_FILE}')\"
[[ \"\${credential_size}\" =~ ^[0-9]+$ ]] && (( credential_size > 0 && credential_size <= 512 )) || {
    printf 'ERROR: credential file size is unsafe\n' >&2
    exit 1
}
[[ -d '${STATE_DIR}' && ! -L '${STATE_DIR}' ]] || {
    printf 'ERROR: state directory is unavailable or unsafe\n' >&2
    exit 1
}
[[ \"\$(stat -Lc '%U:%G' '${STATE_DIR}')\" == 'root:root' ]] || {
    printf 'ERROR: state directory ownership is unsafe\n' >&2
    exit 1
}
[[ \"\$(stat -Lc '%a' '${STATE_DIR}')\" == '700' ]] || {
    printf 'ERROR: state directory permissions are unsafe\n' >&2
    exit 1
}
if [[ -e '${state_file}' || -L '${state_file}' ]]; then
    [[ -f '${state_file}' && ! -L '${state_file}' ]] || {
        printf 'ERROR: lease state is unsafe\n' >&2
        exit 1
    }
    [[ \"\$(stat -Lc '%h' '${state_file}')\" == '1' ]] || {
        printf 'ERROR: lease state link count is unsafe\n' >&2
        exit 1
    }
    [[ \"\$(stat -Lc '%U:%G' '${state_file}')\" == 'root:root' ]] || {
        printf 'ERROR: lease state ownership is unsafe\n' >&2
        exit 1
    }
    [[ \"\$(stat -Lc '%a' '${state_file}')\" == '600' ]] || {
        printf 'ERROR: lease state permissions are unsafe\n' >&2
        exit 1
    }
    state_size=\"\$(stat -Lc '%s' '${state_file}')\"
    [[ \"\${state_size}\" =~ ^[0-9]+$ ]] && (( state_size > 0 && state_size <= 65536 )) || {
        printf 'ERROR: lease state size is unsafe\n' >&2
        exit 1
    }
fi
if systemctl is-active --quiet '${CLEANUP_UNIT}.timer' \
    || systemctl is-active --quiet '${CLEANUP_UNIT}.service'; then
    printf 'ERROR: an existing Provider UI smoke cleanup lease is still active\n' >&2
    exit 1
fi
printf 'remote_preflight=passed host_node_npm=absent cleanup_lease=inactive\n'
"
}

local_preflight() {
    local required_command

    for required_command in ssh "${PHP_BIN}" "${NPX_BIN}" "${PDFINFO_BIN}" "${PDFTOTEXT_BIN}" "${CURL_BIN}"; do
        command -v "${required_command}" >/dev/null 2>&1 || {
            printf 'ERROR: missing required local command: %s\n' "${required_command}" >&2
            return 1
        }
    done

    "${PHP_BIN}" -l "${GATE_PATH}" >/dev/null
    bash -n "${PWCLI_PATH}"
    bash "${PWCLI_PATH}" install-browser
    "${CURL_BIN}" --fail --silent --show-error --output /dev/null --max-time 20 "${BASE_URL}/"
}

arm_remote_cleanup() {
    local wrapper

    wrapper="$(remote_wrapper_path)"
    ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" "
set -euo pipefail
exec 9>/run/lock/fh-provider-ui-smoke.lock
flock -n 9 || {
    printf 'ERROR: another Provider UI smoke lifecycle operation is in progress\n' >&2
    exit 75
}
if systemctl is-active --quiet '${CLEANUP_UNIT}.timer' \
    || systemctl is-active --quiet '${CLEANUP_UNIT}.service'; then
    printf 'ERROR: an existing Provider UI smoke cleanup lease is still active\n' >&2
    exit 75
fi
systemctl stop '${CLEANUP_UNIT}.timer' '${CLEANUP_UNIT}.service' >/dev/null 2>&1 || true
systemctl reset-failed '${CLEANUP_UNIT}.timer' '${CLEANUP_UNIT}.service' >/dev/null 2>&1 || true
if ! systemd-run \
        --quiet \
        --unit='${CLEANUP_UNIT}' \
        --on-active=10m \
        --timer-property=AccuracySec=1s \
        --property=Type=oneshot \
        /bin/bash '${wrapper}' deactivate \
            --app-root '${APP_ROOT}' \
            --credentials-file '${CREDENTIALS_FILE}' \
            --state-dir '${STATE_DIR}'; then
    if systemctl is-active --quiet '${CLEANUP_UNIT}.timer'; then
        systemctl stop '${CLEANUP_UNIT}.timer' '${CLEANUP_UNIT}.service' >/dev/null 2>&1 || true
        systemctl reset-failed '${CLEANUP_UNIT}.timer' '${CLEANUP_UNIT}.service' >/dev/null 2>&1 || true
    fi
    printf 'ERROR: independent cleanup timer could not be created\n' >&2
    exit 1
fi
if ! systemctl is-active --quiet '${CLEANUP_UNIT}.timer'; then
    systemctl stop '${CLEANUP_UNIT}.timer' '${CLEANUP_UNIT}.service' >/dev/null 2>&1 || true
    systemctl reset-failed '${CLEANUP_UNIT}.timer' '${CLEANUP_UNIT}.service' >/dev/null 2>&1 || true
    printf 'ERROR: independent cleanup timer did not become active\n' >&2
    exit 1
fi
printf 'cleanup_lease=armed duration=10m\n'
"
}

disarm_remote_cleanup() {
    ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" "
set -euo pipefail
exec 9>/run/lock/fh-provider-ui-smoke.lock
flock 9
systemctl stop '${CLEANUP_UNIT}.timer' '${CLEANUP_UNIT}.service' >/dev/null 2>&1 || true
systemctl reset-failed '${CLEANUP_UNIT}.timer' '${CLEANUP_UNIT}.service' >/dev/null 2>&1 || true
if systemctl is-active --quiet '${CLEANUP_UNIT}.timer' \
    || systemctl is-active --quiet '${CLEANUP_UNIT}.service'; then
    printf 'ERROR: independent cleanup timer remains active\n' >&2
    exit 1
fi
printf 'cleanup_lease=disarmed\n'
"
}

cleanup_remote_lease() {
    local original_status=$?
    local deactivate_status=0
    local verify_status=0
    local disarm_status=0

    trap - EXIT HUP INT TERM
    set +e

    if [[ "${REMOTE_CLEANUP_REQUIRED}" == '1' || "${REMOTE_CLEANUP_ARMED}" == '1' ]]; then
        remote_principal deactivate
        deactivate_status=$?

        if [[ "${deactivate_status}" == '0' ]]; then
            remote_principal verify
            verify_status=$?
        else
            verify_status=1
        fi

        if [[ "${deactivate_status}" == '0' && "${verify_status}" == '0' ]]; then
            disarm_remote_cleanup
            disarm_status=$?
        else
            disarm_status=1
        fi
    fi

    if [[ "${deactivate_status}" != '0' || "${verify_status}" != '0' || "${disarm_status}" != '0' ]]; then
        printf '%s\n' \
            'HARD STOP: Provider UI smoke cleanup or dormant verification failed.' \
            'Keep the guarded application release active. Do not roll back to an unguarded release.' \
            'The independent cleanup unit was intentionally not cleared; investigate server-locally without printing secrets.' >&2
        exit "${CLEANUP_HARD_EXIT}"
    fi

    if [[ "${original_status}" == '0' ]]; then
        printf 'provider_ui_smoke=passed remote_state=dormant fixture_state=clean\n'
    fi

    exit "${original_status}"
}

stream_credentials_to_gate() {
    local gate_args
    local pipeline_statuses
    local ssh_status
    local gate_status

    gate_args=(
        "${PHP_BIN}"
        "${GATE_PATH}"
        "--base-url=${BASE_URL}"
        "--index-page=${INDEX_PAGE}"
        "--credentials-file=-"
        "--pwcli-path=${PWCLI_PATH}"
        "--browser=${BROWSER}"
        "--bootstrap-timeout=${BOOTSTRAP_TIMEOUT}"
        "--open-timeout=${OPEN_TIMEOUT}"
        "--download-timeout=${DOWNLOAD_TIMEOUT}"
        "--min-pdf-bytes=${MIN_PDF_BYTES}"
    )
    if [[ -n "${OUTPUT_JSON}" ]]; then
        gate_args+=("--output-json=${OUTPUT_JSON}")
    fi

    set +e
    ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" \
        "set -euo pipefail
[[ -f '${CREDENTIALS_FILE}' && ! -L '${CREDENTIALS_FILE}' ]]
[[ \"\$(stat -Lc '%h' '${CREDENTIALS_FILE}')\" == '1' ]]
[[ \"\$(stat -Lc '%U:%G' '${CREDENTIALS_FILE}')\" == 'root:root' ]]
[[ \"\$(stat -Lc '%a' '${CREDENTIALS_FILE}')\" == '600' ]]
credential_size=\"\$(stat -Lc '%s' '${CREDENTIALS_FILE}')\"
[[ \"\${credential_size}\" =~ ^[0-9]+$ ]]
(( credential_size > 0 && credential_size <= 512 ))
exec cat -- '${CREDENTIALS_FILE}'" \
        | "${gate_args[@]}"
    pipeline_statuses=("${PIPESTATUS[@]}")
    set -e

    ssh_status="${pipeline_statuses[0]:-1}"
    gate_status="${pipeline_statuses[1]:-2}"

    if [[ "${ssh_status}" != '0' ]]; then
        printf 'ERROR: credential stream failed before or during the local gate\n' >&2
        return 2
    fi

    return "${gate_status}"
}

main() {
    local arm_status
    local gate_status

    parse_args "$@"
    validate_config

    prod_print_plan "prod-provider-ui-smoke" "${PROD_SSH_TARGET}" "write: temporary isolated lease"
    printf '  execution  : operator-side browser; production host Node/npm stays absent\n'
    printf '  cleanup    : shell finally + independent 10m systemd lease\n'

    if ! local_preflight; then
        die "local read-only/runtime preflight failed" 20
    fi
    if ! remote_static_preflight; then
        die "remote read-only preflight failed" 20
    fi
    if ! remote_principal verify; then
        die "remote principal is not dormant and clean" 20
    fi

    trap cleanup_remote_lease EXIT
    trap 'exit 129' HUP
    trap 'exit 130' INT
    trap 'exit 143' TERM

    REMOTE_CLEANUP_REQUIRED=1
    set +e
    arm_remote_cleanup
    arm_status=$?
    set -e
    if [[ "${arm_status}" != '0' ]]; then
        if [[ "${arm_status}" == '75' ]]; then
            # A separately owned active lease must never be torn down by this
            # invocation.
            REMOTE_CLEANUP_REQUIRED=0
        fi
        die "independent remote cleanup timer could not be armed" 22
    fi
    REMOTE_CLEANUP_ARMED=1

    if ! remote_principal activate; then
        die "remote smoke lease activation failed" 21
    fi

    set +e
    stream_credentials_to_gate
    gate_status=$?
    set -e

    return "${gate_status}"
}

main "$@"
