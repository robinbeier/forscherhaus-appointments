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

invalidate_stale_output() {
    [[ -n "${OUTPUT_JSON:-}" ]] || return 0
    command -v "${PHP_BIN}" >/dev/null 2>&1 || return 0
    "${PHP_BIN}" -r '
        require $argv[1];
        trafficGateInvalidateStaleOutputs(["traffic_gate_v1.php", "--output-json", $argv[2]]);
    ' "${SCRIPT_DIR}/traffic_gate_v1.php" "${OUTPUT_JSON}" >/dev/null 2>&1 || true
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

[[ "${PURPOSE}" == 'customers-ui-smoke' || "${PURPOSE}" == 'deploy' ]] || die_invocation
[[ "${MODE}" == 'normal' || "${MODE}" == 'no-business-traffic' ]] || die_invocation
[[ "${WINDOW_SECONDS}" =~ ^[1-9][0-9]*$ ]] || die_invocation
[[ -n "${OUTPUT_JSON}" ]] || die_invocation
[[ "${EUID}" -eq 0 ]] || die_invocation
command -v "${PHP_BIN}" >/dev/null 2>&1 || die_invocation

exec "${PHP_BIN}" "${SCRIPT_DIR}/traffic_gate_v1.php" \
    "--purpose=${PURPOSE}" \
    "--mode=${MODE}" \
    "--window-seconds=${WINDOW_SECONDS}" \
    "--output-json=${OUTPUT_JSON}" \
    "--log-dir=${LOG_DIR}" \
    "--catalog=${CATALOG_PATH}" \
    "--monitor-sources=${MONITOR_SOURCES_PATH}"
