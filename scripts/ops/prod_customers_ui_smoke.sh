#!/usr/bin/env bash
set -Eeuo pipefail
set +x

if [[ "$(uname -s)" == 'Darwin' ]]; then
    export PATH="/opt/homebrew/bin:/usr/local/bin:${PATH}"
    DEFAULT_BROWSER='chrome'
else
    DEFAULT_BROWSER='firefox'
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
# shellcheck source=scripts/ops/lib/prod_common.sh
source "${SCRIPT_DIR}/lib/prod_common.sh"

readonly CLEANUP_UNIT='fh-customers-ui-smoke-cleanup'
readonly CLEANUP_HARD_EXIT=90
readonly CONTRACT_PATHS=(
    'application/controllers/Customers.php'
    'application/controllers/Console.php'
    'application/controllers/Login.php'
    'application/helpers/asset_helper.php'
    'application/core/Customers_ui_smoke_access_policy.php'
    'application/core/EA_Controller.php'
    'application/libraries/Customers_ui_smoke_fixture.php'
    'application/views/pages/customers.php'
    'assets/js/http/customers_http_client.js'
    'assets/js/http/customers_http_client.min.js'
    'assets/js/pages/customers.js'
    'assets/js/pages/customers.min.js'
    'scripts/ops/customers_ui_smoke_principals.sh'
    'scripts/ops/config/traffic_gate_catalog.v1.json'
    'scripts/ops/lib/TrafficGateV1.php'
    'scripts/ops/prod_traffic_gate.sh'
    'scripts/ops/traffic_gate_v1.php'
)

SSH_OPTIONS=(-o StrictHostKeyChecking=accept-new)
PROD_SSH_TARGET="$(prod_default_ssh_target)"
BASE_URL='https://dasforscherhaus-leg.de'
INDEX_PAGE='index.php'
APP_ROOT='/var/www/html/easyappointments'
CREDENTIALS_FILE='/etc/fh/release-gate-customers-ui-smoke.env'
STATE_DIR='/var/lib/fh-customers-ui-smoke'
PWCLI_PATH="${REPO_ROOT}/scripts/release-gate/playwright/playwright_cli.sh"
GATE_PATH="${REPO_ROOT}/scripts/release-gate/customers_ui_smoke.php"
BROWSER="${CUSTOMERS_UI_SMOKE_BROWSER:-${DEFAULT_BROWSER}}"
OUTPUT_JSON=''
BOOTSTRAP_TIMEOUT='90'
OPEN_TIMEOUT='30'
TRAFFIC_GATE_MODE='normal'
TRAFFIC_GATE_WINDOW_SECONDS='90'
TRAFFIC_GATE_REMOTE_OUTPUT='/var/lib/fh-traffic-gate/customers-ui-smoke-latest.json'
PHP_BIN="${CUSTOMERS_UI_SMOKE_PHP_BIN:-php}"
CURL_BIN="${CUSTOMERS_UI_SMOKE_CURL_BIN:-curl}"
NPX_BIN="${CUSTOMERS_UI_SMOKE_NPX_BIN:-npx}"
REMOTE_CLEANUP_ARMED=0
REMOTE_CLEANUP_REQUIRED=0
DEPLOYED_CONTRACT_SHA256=''

usage() {
    cat <<'USAGE'
Usage:
  bash scripts/ops/prod_customers_ui_smoke.sh [options]

Run the on-demand Customers UI smoke from the operator workstation. The remote
principals stay dormant outside a ten-minute lease; an independent systemd timer
and the local EXIT handler both run the same server-local cleanup.

Options:
  --prod-ssh-target TARGET
  --base-url URL
  --index-page PAGE
  --app-root PATH
  --credentials-file PATH
  --state-dir PATH
  --pwcli-path PATH
  --browser NAME
  --output-json PATH
  --bootstrap-timeout SEC
  --open-timeout SEC
  --traffic-mode normal|no-business-traffic
  --traffic-window-seconds SEC
  -h, --help

Exit codes: 0 pass, 1 assertion, 2 runtime, 20 preflight, 21 activation,
22 cleanup timer, 90 hard cleanup/dormant-verification stop.
USAGE
}

die() {
    printf 'ERROR: %s\n' "$1" >&2
    exit "${2:-1}"
}

validate_remote_path() {
    local path="$1"
    [[ "${path}" =~ ^/[A-Za-z0-9._/-]+$ ]] || die "invalid remote path" 64
    [[ "${path}" != '/' && "${path}" != */ && "${path}" != *'//'* ]] || die "remote path is not normalized" 64
    [[ "${path}" != *'/./'* && "${path}" != */. ]] || die "remote path is not normalized" 64
    [[ "${path}" != *'/../'* && "${path}" != */.. ]] || die "remote path is not normalized" 64
}

validate_uint() {
    [[ "$1" =~ ^[1-9][0-9]*$ ]] || die "$2 must be a positive integer" 64
}

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --prod-ssh-target) [[ $# -ge 2 ]] || die "missing SSH target" 64; PROD_SSH_TARGET="$2"; shift 2 ;;
            --base-url) [[ $# -ge 2 ]] || die "missing base URL" 64; BASE_URL="$2"; shift 2 ;;
            --index-page) [[ $# -ge 2 ]] || die "missing index page" 64; INDEX_PAGE="$2"; shift 2 ;;
            --app-root) [[ $# -ge 2 ]] || die "missing app root" 64; APP_ROOT="$2"; shift 2 ;;
            --credentials-file) [[ $# -ge 2 ]] || die "missing credential path" 64; CREDENTIALS_FILE="$2"; shift 2 ;;
            --state-dir) [[ $# -ge 2 ]] || die "missing state path" 64; STATE_DIR="$2"; shift 2 ;;
            --pwcli-path) [[ $# -ge 2 ]] || die "missing Playwright path" 64; PWCLI_PATH="$2"; shift 2 ;;
            --browser) [[ $# -ge 2 ]] || die "missing browser" 64; BROWSER="$2"; shift 2 ;;
            --output-json) [[ $# -ge 2 ]] || die "missing report path" 64; OUTPUT_JSON="$2"; shift 2 ;;
            --bootstrap-timeout) [[ $# -ge 2 ]] || die "missing bootstrap timeout" 64; BOOTSTRAP_TIMEOUT="$2"; shift 2 ;;
            --open-timeout) [[ $# -ge 2 ]] || die "missing open timeout" 64; OPEN_TIMEOUT="$2"; shift 2 ;;
            --traffic-mode) [[ $# -ge 2 ]] || die "missing traffic mode" 64; TRAFFIC_GATE_MODE="$2"; shift 2 ;;
            --traffic-window-seconds) [[ $# -ge 2 ]] || die "missing traffic window" 64; TRAFFIC_GATE_WINDOW_SECONDS="$2"; shift 2 ;;
            -h|--help) usage; exit 0 ;;
            *) die "unknown option" 64 ;;
        esac
    done
}

validate_config() {
    [[ "${BASE_URL}" =~ ^https?://[^[:space:]/]+(:[0-9]+)?(/[^[:space:]]*)?$ ]] || die "base URL is invalid" 64
    BASE_URL="${BASE_URL%/}"
    [[ "${INDEX_PAGE}" =~ ^[A-Za-z0-9._/-]*$ ]] || die "index page is invalid" 64
    validate_remote_path "${APP_ROOT}"
    validate_remote_path "${CREDENTIALS_FILE}"
    validate_remote_path "${STATE_DIR}"
    validate_uint "${BOOTSTRAP_TIMEOUT}" 'bootstrap timeout'
    validate_uint "${OPEN_TIMEOUT}" 'open timeout'
    validate_uint "${TRAFFIC_GATE_WINDOW_SECONDS}" 'traffic window'
    case "${TRAFFIC_GATE_MODE}" in normal|no-business-traffic) ;; *) die "unsupported traffic mode" 64 ;; esac
    validate_remote_path "${TRAFFIC_GATE_REMOTE_OUTPUT}"
    BROWSER="$(printf '%s' "${BROWSER}" | tr '[:upper:]' '[:lower:]')"
    case "${BROWSER}" in chrome|firefox|webkit|msedge) ;; *) die "unsupported browser" 64 ;; esac
    export PLAYWRIGHT_MCP_BROWSER="${BROWSER}"
    [[ -f "${GATE_PATH}" && ! -L "${GATE_PATH}" ]] || die "Customers UI smoke gate is unavailable" 2
    [[ -f "${PWCLI_PATH}" && ! -L "${PWCLI_PATH}" && -r "${PWCLI_PATH}" ]] || die "Playwright wrapper is unavailable" 2
}

remote_wrapper_path() {
    printf '%s/scripts/ops/customers_ui_smoke_principals.sh' "${APP_ROOT}"
}

remote_principal() {
    local action="$1"
    local wrapper
    wrapper="$(remote_wrapper_path)"
    ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" \
        "exec bash '${wrapper}' '${action}' --app-root '${APP_ROOT}' --credentials-file '${CREDENTIALS_FILE}' --state-dir '${STATE_DIR}'"
}

local_contract_sha256() {
    "${PHP_BIN}" -r '
        array_shift($argv);
        $root = array_shift($argv);
        $hashes = [];
        foreach ($argv as $relative) {
            $path = $root . "/" . $relative;
            if (!is_file($path) || is_link($path)) exit(1);
            $hashes[] = hash_file("sha256", $path);
        }
        echo hash("sha256", implode("\n", $hashes)), "\n";
    ' "${REPO_ROOT}" "${CONTRACT_PATHS[@]}"
}

remote_contract_sha256() {
    local quoted_paths=()
    local relative
    for relative in "${CONTRACT_PATHS[@]}"; do quoted_paths+=("'${relative}'"); done
    ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" \
        "exec php -r '\
            array_shift(\$argv);\
            \$root = array_shift(\$argv);\
            \$hashes = [];\
            foreach (\$argv as \$relative) {\
                \$path = \$root . \"/\" . \$relative;\
                if (!is_file(\$path) || is_link(\$path)) exit(1);\
                \$hashes[] = hash_file(\"sha256\", \$path);\
            }\
            echo hash(\"sha256\", implode(\"\\n\", \$hashes)), \"\\n\";\
        ' '${APP_ROOT}' ${quoted_paths[*]}"
}

remote_static_preflight() {
    local wrapper
    wrapper="$(remote_wrapper_path)"
    ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" "
set -euo pipefail
[[ \"\$(id -u)\" == '0' ]]
for required in bash php systemctl systemd-run stat cat flock; do command -v \"\${required}\" >/dev/null 2>&1; done
if command -v node >/dev/null 2>&1 || command -v npm >/dev/null 2>&1; then exit 1; fi
[[ -d '${APP_ROOT}' && -f '${APP_ROOT}/index.php' && ! -L '${APP_ROOT}/index.php' ]]
[[ -f '${wrapper}' && ! -L '${wrapper}' && \"\$(stat -Lc '%h' '${wrapper}')\" == '1' ]]
[[ -f '${CREDENTIALS_FILE}' && ! -L '${CREDENTIALS_FILE}' ]]
[[ \"\$(stat -Lc '%h' '${CREDENTIALS_FILE}')\" == '1' ]]
[[ \"\$(stat -Lc '%U:%G' '${CREDENTIALS_FILE}')\" == 'root:root' ]]
[[ \"\$(stat -Lc '%a' '${CREDENTIALS_FILE}')\" == '600' ]]
credential_size=\"\$(stat -Lc '%s' '${CREDENTIALS_FILE}')\"
[[ \"\${credential_size}\" =~ ^[0-9]+$ ]] && (( credential_size > 0 && credential_size <= 1024 ))
[[ -d '${STATE_DIR}' && ! -L '${STATE_DIR}' ]]
[[ \"\$(stat -Lc '%U:%G' '${STATE_DIR}')\" == 'root:root' && \"\$(stat -Lc '%a' '${STATE_DIR}')\" == '700' ]]
state_file='${STATE_DIR}/active.json'
if [[ -e \"\${state_file}\" || -L \"\${state_file}\" ]]; then
    [[ -f \"\${state_file}\" && ! -L \"\${state_file}\" ]]
    [[ \"\$(stat -Lc '%h' \"\${state_file}\")\" == '1' ]]
    [[ \"\$(stat -Lc '%U:%G' \"\${state_file}\")\" == 'root:root' ]]
    [[ \"\$(stat -Lc '%a' \"\${state_file}\")\" == '600' ]]
fi
if systemctl is-active --quiet '${CLEANUP_UNIT}.timer' || systemctl is-active --quiet '${CLEANUP_UNIT}.service'; then exit 1; fi
printf 'remote_preflight=passed host_node_npm=absent cleanup_lease=inactive\n'
"
}

local_runtime_preflight() {
    local required
    for required in ssh "${PHP_BIN}" "${NPX_BIN}" "${CURL_BIN}"; do command -v "${required}" >/dev/null 2>&1 || return 1; done
    "${PHP_BIN}" -l "${GATE_PATH}" >/dev/null
    "${PHP_BIN}" -l "${SCRIPT_DIR}/lib/TrafficGateV1.php" >/dev/null
    "${PHP_BIN}" -l "${SCRIPT_DIR}/traffic_gate_v1.php" >/dev/null
    bash -n "${PWCLI_PATH}"
    bash "${PWCLI_PATH}" install-browser
}

local_endpoint_preflight() {
    "${CURL_BIN}" --fail --silent --show-error --output /dev/null --max-time 20 "${BASE_URL}/"
}

remote_traffic_gate() {
    local report
    local wrapper="${APP_ROOT}/scripts/ops/prod_traffic_gate.sh"

    report="$(ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" \
        "exec bash '${wrapper}' --purpose customers-ui-smoke --mode '${TRAFFIC_GATE_MODE}' --window-seconds '${TRAFFIC_GATE_WINDOW_SECONDS}' --output-json '${TRAFFIC_GATE_REMOTE_OUTPUT}'")" \
        || return 1

    printf '%s' "${report}" | "${PHP_BIN}" -r '
        $raw = stream_get_contents(STDIN);
        try {
            $report = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            exit(1);
        }
        $required = [
            "schema", "producer_sha256", "policy_version", "catalog_version", "purpose", "mode",
            "window_start_epoch", "window_end_epoch", "window_seconds", "log_set_sha256",
            "rotation_complete", "parse_complete", "evidence_complete", "decision", "exit_code", "counts",
        ];
        if (!is_array($report) || array_is_list($report) || array_diff($required, array_keys($report)) !== []) exit(1);
        if (($report["schema"] ?? null) !== "traffic_gate.v1") exit(1);
        if (($report["purpose"] ?? null) !== "customers-ui-smoke") exit(1);
        if (($report["mode"] ?? null) !== $argv[1]) exit(1);
        if (!in_array(($report["decision"] ?? null), ["allow", "advisory"], true)) exit(1);
        if (($report["exit_code"] ?? null) !== 0 || ($report["evidence_complete"] ?? null) !== true) exit(1);
        if (preg_match("/^[a-f0-9]{64}$/", (string) ($report["producer_sha256"] ?? "")) !== 1) exit(1);
        if (preg_match("/^[a-f0-9]{64}$/", (string) ($report["log_set_sha256"] ?? "")) !== 1) exit(1);
    ' "${TRAFFIC_GATE_MODE}"
}

arm_remote_cleanup() {
    local wrapper
    wrapper="$(remote_wrapper_path)"
    ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" "
set -euo pipefail
exec 9>/run/lock/fh-customers-ui-smoke.lock
flock -n 9 || exit 75
if systemctl is-active --quiet '${CLEANUP_UNIT}.timer' || systemctl is-active --quiet '${CLEANUP_UNIT}.service'; then exit 75; fi
systemctl stop '${CLEANUP_UNIT}.timer' '${CLEANUP_UNIT}.service' >/dev/null 2>&1 || true
systemctl reset-failed '${CLEANUP_UNIT}.timer' '${CLEANUP_UNIT}.service' >/dev/null 2>&1 || true
systemd-run --quiet --unit='${CLEANUP_UNIT}' --on-active=10m --timer-property=AccuracySec=1s --property=Type=oneshot \
    /bin/bash '${wrapper}' deactivate --app-root '${APP_ROOT}' --credentials-file '${CREDENTIALS_FILE}' --state-dir '${STATE_DIR}'
systemctl is-active --quiet '${CLEANUP_UNIT}.timer'
printf 'cleanup_lease=armed duration=10m\n'
"
}

disarm_remote_cleanup() {
    ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" "
set -euo pipefail
exec 9>/run/lock/fh-customers-ui-smoke.lock
flock 9
systemctl stop '${CLEANUP_UNIT}.timer' '${CLEANUP_UNIT}.service' >/dev/null 2>&1 || true
systemctl reset-failed '${CLEANUP_UNIT}.timer' '${CLEANUP_UNIT}.service' >/dev/null 2>&1 || true
! systemctl is-active --quiet '${CLEANUP_UNIT}.timer'
! systemctl is-active --quiet '${CLEANUP_UNIT}.service'
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
        if [[ "${deactivate_status}" == '0' ]]; then remote_principal verify; verify_status=$?; else verify_status=1; fi
        if [[ "${deactivate_status}" == '0' && "${verify_status}" == '0' ]]; then disarm_remote_cleanup; disarm_status=$?; else disarm_status=1; fi
    fi

    if [[ "${deactivate_status}" != '0' || "${verify_status}" != '0' || "${disarm_status}" != '0' ]]; then
        printf '%s\n' \
            'HARD STOP: Customers UI smoke cleanup or dormant verification failed.' \
            'Keep the guarded release active; leave the independent cleanup unit in place.' >&2
        exit "${CLEANUP_HARD_EXIT}"
    fi

    if [[ "${original_status}" == '0' ]]; then
        printf 'customers_ui_smoke=passed remote_state=dormant fixture_state=clean\n'
    fi
    exit "${original_status}"
}

stream_credentials_to_gate() {
    local gate_args
    local statuses
    gate_args=(
        "${PHP_BIN}" "${GATE_PATH}"
        "--base-url=${BASE_URL}"
        "--index-page=${INDEX_PAGE}"
        '--credentials-file=-'
        "--pwcli-path=${PWCLI_PATH}"
        "--browser=${BROWSER}"
        "--bootstrap-timeout=${BOOTSTRAP_TIMEOUT}"
        "--open-timeout=${OPEN_TIMEOUT}"
    )
    if [[ -n "${OUTPUT_JSON}" ]]; then gate_args+=("--output-json=${OUTPUT_JSON}"); fi

    set +e
    ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" "
set -euo pipefail
[[ -f '${CREDENTIALS_FILE}' && ! -L '${CREDENTIALS_FILE}' ]]
[[ \"\$(stat -Lc '%h' '${CREDENTIALS_FILE}')\" == '1' ]]
[[ \"\$(stat -Lc '%U:%G' '${CREDENTIALS_FILE}')\" == 'root:root' ]]
[[ \"\$(stat -Lc '%a' '${CREDENTIALS_FILE}')\" == '600' ]]
exec cat -- '${CREDENTIALS_FILE}'
" | "${gate_args[@]}"
    statuses=("${PIPESTATUS[@]}")
    set -e

    [[ "${statuses[0]:-1}" == '0' ]] || return 2
    return "${statuses[1]:-2}"
}

main() {
    local arm_status
    local local_contract
    local remote_after
    local gate_status

    parse_args "$@"
    validate_config
    prod_print_plan 'prod-customers-ui-smoke' "${PROD_SSH_TARGET}" 'write: temporary isolated lease'
    printf '  execution  : operator-side browser; no real account or customer fixture\n'
    printf '  cleanup    : shell finally + independent 10m systemd lease\n'

    local_runtime_preflight || die "local read-only/runtime preflight failed" 20
    local_contract="$(local_contract_sha256)" || die "local contract bundle could not be hashed" 20
    DEPLOYED_CONTRACT_SHA256="$(remote_contract_sha256)" || die "deployed contract bundle could not be hashed" 20
    [[ "${local_contract}" =~ ^[a-f0-9]{64}$ && "${local_contract}" == "${DEPLOYED_CONTRACT_SHA256}" ]] \
        || die "deployed Customers contract does not match the operator checkout" 20
    remote_traffic_gate || die "passive traffic gate did not allow the smoke preflight" 20
    local_endpoint_preflight || die "public endpoint preflight failed" 20
    remote_static_preflight || die "remote read-only preflight failed" 20
    remote_principal verify || die "remote principals are not dormant and clean" 20

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
        [[ "${arm_status}" != '75' ]] || REMOTE_CLEANUP_REQUIRED=0
        die "independent cleanup timer could not be armed" 22
    fi
    REMOTE_CLEANUP_ARMED=1
    remote_principal activate || die "remote smoke lease activation failed" 21

    if stream_credentials_to_gate; then gate_status=0; else gate_status=$?; fi
    remote_after="$(remote_contract_sha256)" || gate_status=2
    [[ "${remote_after}" == "${DEPLOYED_CONTRACT_SHA256}" ]] || gate_status=2
    return "${gate_status}"
}

main "$@"
