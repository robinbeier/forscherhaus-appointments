#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_BIN="${TRAFFIC_GATE_PHP_BIN:-php}"
LOG_DIR="${TRAFFIC_GATE_LOG_DIR:-/var/log/apache2}"
CATALOG_PATH="${TRAFFIC_GATE_CATALOG_PATH:-${SCRIPT_DIR}/config/traffic_gate_catalog.v1.json}"
MONITOR_SOURCES_PATH="${TRAFFIC_GATE_MONITOR_SOURCES_PATH:-/etc/fh/traffic-gate-monitor-sources.v1.json}"

usage() {
    cat <<'USAGE'
Usage:
  bash scripts/ops/prod_traffic_gate.sh --purpose customers-ui-smoke|deploy \
    --mode normal|no-business-traffic --window-seconds N --output-json PATH

Exit codes: 0 allow/advisory, 20 traffic hard stop, 21 invalid/incomplete
evidence, 64 invalid invocation.
USAGE
}

is_recognizable_output_report() {
    local path="$1"
    local whitespace='[[:space:]]*'
    local minimal_pair minimal_report full_pair full_report count_pair counts_object key

    minimal_pair="(\\\"schema\\\"${whitespace}:${whitespace}\\\"traffic_gate\\.v[0-9]+\\\"|\\\"decision\\\"${whitespace}:${whitespace}\\\"(allow|advisory|hard_stop|invalid)\\\"|\\\"exit_code\\\"${whitespace}:${whitespace}(0|20|21)|\\\"window_end_epoch\\\"${whitespace}:${whitespace}-?[0-9]+)"
    minimal_report="\\{${whitespace}(${minimal_pair}${whitespace},${whitespace}){3}${minimal_pair}${whitespace}\\}"
    if LC_ALL=C grep -azEq "^${whitespace}${minimal_report}${whitespace}$" -- "${path}"; then
        for key in schema decision exit_code window_end_epoch; do
            LC_ALL=C grep -azEq "\\\"${key}\\\"${whitespace}:" -- "${path}" || return 1
        done
        return 0
    fi

    count_pair="\\\"(documented_health|documented_periodic_ops|denied_external|public_read|business_or_authenticated|unclassified|total|lines_seen|lines_in_window|parse_errors|source_unknown|method_unknown|target_unknown|status_5xx|write|authenticated|customers_or_sensitive|scanner_success|pre_window_completion|rotation_errors)\\\"${whitespace}:${whitespace}-?[0-9]+"
    counts_object="\\{${whitespace}(${count_pair}${whitespace},${whitespace}){19}${count_pair}${whitespace}\\}"
    full_pair="(\\\"schema\\\"${whitespace}:${whitespace}\\\"traffic_gate\\.v[0-9]+\\\"|\\\"producer_sha256\\\"${whitespace}:${whitespace}\\\"[0-9a-f]{64}\\\"|\\\"policy_version\\\"${whitespace}:${whitespace}\\\"[^\\\"]+\\\"|\\\"catalog_version\\\"${whitespace}:${whitespace}\\\"[^\\\"]+\\\"|\\\"purpose\\\"${whitespace}:${whitespace}\\\"(customers-ui-smoke|deploy)\\\"|\\\"mode\\\"${whitespace}:${whitespace}\\\"(normal|no-business-traffic)\\\"|\\\"window_start_epoch\\\"${whitespace}:${whitespace}-?[0-9]+|\\\"window_end_epoch\\\"${whitespace}:${whitespace}-?[0-9]+|\\\"window_seconds\\\"${whitespace}:${whitespace}[0-9]+|\\\"log_set_sha256\\\"${whitespace}:${whitespace}\\\"[0-9a-f]{64}\\\"|\\\"rotation_complete\\\"${whitespace}:${whitespace}(true|false)|\\\"parse_complete\\\"${whitespace}:${whitespace}(true|false)|\\\"evidence_complete\\\"${whitespace}:${whitespace}(true|false)|\\\"decision\\\"${whitespace}:${whitespace}\\\"(allow|advisory|hard_stop|invalid)\\\"|\\\"exit_code\\\"${whitespace}:${whitespace}(0|20|21)|\\\"counts\\\"${whitespace}:${whitespace}${counts_object})"
    full_report="\\{${whitespace}(${full_pair}${whitespace},${whitespace}){15}${full_pair}${whitespace}\\}"
    LC_ALL=C grep -azEq "^${whitespace}${full_report}${whitespace}$" -- "${path}" || return 1
    for key in schema producer_sha256 policy_version catalog_version purpose mode window_start_epoch window_end_epoch window_seconds log_set_sha256 rotation_complete parse_complete evidence_complete decision exit_code counts; do
        LC_ALL=C grep -azEq "\\\"${key}\\\"${whitespace}:" -- "${path}" || return 1
    done
    for key in documented_health documented_periodic_ops denied_external public_read business_or_authenticated unclassified total lines_seen lines_in_window parse_errors source_unknown method_unknown target_unknown status_5xx write authenticated customers_or_sensitive scanner_success pre_window_completion rotation_errors; do
        LC_ALL=C grep -azEq "\\\"${key}\\\"${whitespace}:" -- "${path}" || return 1
    done
}

invalidate_output_candidate() {
    local path="$1"
    local directory metadata metadata_after device inode owner links size mode type temporary nul_free_size

    [[ "${path}" == /* && -f "${path}" && ! -L "${path}" && -r "${path}" ]] || return 0
    metadata="$(LC_ALL=C stat -c '%d:%i:%u:%h:%s:%a:%F' -- "${path}" 2>/dev/null)" || return 0
    IFS=: read -r device inode owner links size mode type <<<"${metadata}"
    [[ "${owner}" == "${EUID}" && "${links}" == '1' && "${size}" =~ ^[0-9]+$ && "${size}" -le 1000000 ]] || return 0
    [[ "${type}" == 'regular file' ]] || return 0
    if [[ "${size}" == '0' ]]; then
        [[ "${mode}" == '600' ]] || return 0
        return 0
    fi
    nul_free_size="$(LC_ALL=C tr -d '\000' <"${path}" | wc -c)" || return 0
    nul_free_size="${nul_free_size//[[:space:]]/}"
    [[ "${nul_free_size}" == "${size}" ]] || return 0
    is_recognizable_output_report "${path}" || return 0
    metadata_after="$(LC_ALL=C stat -c '%d:%i:%u:%h:%s:%a:%F' -- "${path}" 2>/dev/null)" || return 0
    [[ "${metadata_after}" == "${metadata}" ]] || return 0

    directory="${path%/*}"
    [[ -n "${directory}" ]] || directory='/'
    [[ -d "${directory}" && ! -L "${directory}" && -w "${directory}" ]] || return 0
    temporary="$(mktemp "${directory}/.traffic-gate-XXXXXX")" || return 0
    if ! chmod 0600 "${temporary}" || ! mv -fT -- "${temporary}" "${path}"; then
        rm -f -- "${temporary}"
    fi
}

invalidate_stale_output() {
    local index candidate

    for ((index = 0; index < ${#ORIGINAL_ARGS[@]}; index++)); do
        candidate=''
        if [[ "${ORIGINAL_ARGS[index]}" == '--output-json' && $((index + 1)) -lt ${#ORIGINAL_ARGS[@]} ]]; then
            candidate="${ORIGINAL_ARGS[index + 1]}"
            ((index += 1))
        elif [[ "${ORIGINAL_ARGS[index]}" == --output-json=* ]]; then
            candidate="${ORIGINAL_ARGS[index]#--output-json=}"
        fi
        [[ -n "${candidate}" ]] || continue
        invalidate_output_candidate "${candidate}" || true
    done
}

die_invocation() {
    invalidate_stale_output
    printf 'traffic_gate status=invalid reason=invocation\n' >&2
    exit 64
}

PURPOSE=''
MODE=''
WINDOW_SECONDS=''
OUTPUT_JSON=''
ORIGINAL_ARGS=("$@")

while [[ $# -gt 0 ]]; do
    case "$1" in
        --purpose) [[ $# -ge 2 ]] || die_invocation; PURPOSE="$2"; shift 2 ;;
        --mode) [[ $# -ge 2 ]] || die_invocation; MODE="$2"; shift 2 ;;
        --window-seconds) [[ $# -ge 2 ]] || die_invocation; WINDOW_SECONDS="$2"; shift 2 ;;
        --output-json) [[ $# -ge 2 ]] || die_invocation; OUTPUT_JSON="$2"; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) die_invocation ;;
    esac
done

invalidate_stale_output
[[ "${PURPOSE}" == 'customers-ui-smoke' || "${PURPOSE}" == 'deploy' ]] || die_invocation
[[ "${MODE}" == 'normal' || "${MODE}" == 'no-business-traffic' ]] || die_invocation
[[ "${WINDOW_SECONDS}" =~ ^[1-9][0-9]*$ ]] || die_invocation
[[ -n "${OUTPUT_JSON}" ]] || die_invocation
[[ "${EUID}" -eq 0 ]] || die_invocation
command -v "${PHP_BIN}" >/dev/null 2>&1 || die_invocation

set +e
"${PHP_BIN}" "${SCRIPT_DIR}/traffic_gate_v1.php" \
    "--purpose=${PURPOSE}" \
    "--mode=${MODE}" \
    "--window-seconds=${WINDOW_SECONDS}" \
    "--output-json=${OUTPUT_JSON}" \
    "--log-dir=${LOG_DIR}" \
    "--catalog=${CATALOG_PATH}" \
    "--monitor-sources=${MONITOR_SOURCES_PATH}"
status=$?
set -e

case "${status}" in
    0|20|21|64) exit "${status}" ;;
    *) printf 'traffic_gate status=invalid reason=evidence\n' >&2; exit 21 ;;
esac
