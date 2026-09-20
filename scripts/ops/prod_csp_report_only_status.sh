#!/usr/bin/env bash
set -euo pipefail

if [[ "$(uname -s)" == "Darwin" ]]; then
    export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/ops/lib/prod_common.sh
source "${SCRIPT_DIR}/lib/prod_common.sh"

SSH_OPTIONS=(-o StrictHostKeyChecking=accept-new)
PROD_SSH_TARGET="$(prod_default_ssh_target)"
PROD_DOCTOR_SCRIPT="${CSP_REPORT_ONLY_DOCTOR_SCRIPT:-${SCRIPT_DIR}/prod_doctor.sh}"
RECEIPT_VALIDATOR_SCRIPT="${SCRIPT_DIR}/csp_report_only_validate_receipt.php"
EXPECT=''

usage() {
    cat <<'USAGE'
Usage:
  bash scripts/ops/prod_csp_report_only_status.sh --expect inactive|active [options]

Read the CSP header classes and the bounded aggregate status. This script never
sends a CSP report and never prints header values, report bodies, URLs, paths,
tokens, cookies, user agents, or configuration contents.

The first production invocation is a separate ROB-586 approval gate.

USAGE
    prod_usage_common
}

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --expect)
                [[ $# -ge 2 ]] || { printf 'ERROR: --expect requires a value.\n' >&2; exit 1; }
                EXPECT="$2"
                shift 2
                ;;
            --prod-ssh-target)
                [[ $# -ge 2 ]] || { printf 'ERROR: --prod-ssh-target requires a value.\n' >&2; exit 1; }
                PROD_SSH_TARGET="$2"
                shift 2
                ;;
            -h|--help)
                usage
                exit 0
                ;;
            *)
                printf 'ERROR: unknown option: %s\n' "$1" >&2
                exit 1
                ;;
        esac
    done

    [[ "$EXPECT" == 'inactive' || "$EXPECT" == 'active' ]] || {
        printf 'ERROR: --expect must be inactive or active.\n' >&2
        exit 1
    }
}

expect_line() {
    local output_file="$1"
    local line="$2"

    [[ "$(grep -Fxc -- "$line" "$output_file" || true)" == '1' ]]
}

verify_header_classes() {
    local output_file="$1"
    local expected_app_www='missing'
    local failures=0
    local surface

    if [[ "$EXPECT" == 'active' ]]; then
        expected_app_www='present'
    fi

    for surface in app_https www_https; do
        expect_line "$output_file" "posture_header.${surface}.csp=missing" || failures=$((failures + 1))
        expect_line "$output_file" "posture_header.${surface}.csp_report_only=${expected_app_www}" || failures=$((failures + 1))
    done

    expect_line "$output_file" 'posture_header.monitor_https.csp=missing' || failures=$((failures + 1))
    expect_line "$output_file" 'posture_header.monitor_https.csp_report_only=missing' || failures=$((failures + 1))

    printf 'csp_headers.expectation=%s\n' "$EXPECT"
    if [[ "$failures" == '0' ]]; then
        printf 'csp_headers.status=passed\n'
    else
        printf 'csp_headers.status=failed\n'
    fi
    printf 'csp_headers.app_report_only=%s\n' "$expected_app_www"
    printf 'csp_headers.www_report_only=%s\n' "$expected_app_www"
    printf 'csp_headers.monitor_report_only=missing\n'
    printf 'csp_headers.enforcement=missing\n'

    [[ "$failures" == '0' ]]
}

run_status() {
    local doctor_output
    local status_output=''
    local validated_output=''
    local header_status=0
    local remote_status=0
    local combined_status=0

    doctor_output="$(mktemp)"

    if ! bash "$PROD_DOCTOR_SCRIPT" --prod-ssh-target "$PROD_SSH_TARGET" >"$doctor_output"; then
        rm -f "$doctor_output"
        printf 'csp_headers.status=runtime_failed\n'
        return 2
    fi

    verify_header_classes "$doctor_output" || header_status=1

    status_output="$(
        ssh "${SSH_OPTIONS[@]}" "$PROD_SSH_TARGET" \
            "/usr/bin/php /var/www/html/easyappointments/scripts/ops/csp_report_only_status.php --expect=${EXPECT}"
    )" || remote_status=$?

    if (( remote_status == 0 )) && validated_output="$(printf '%s' "$status_output" | php "$RECEIPT_VALIDATOR_SCRIPT" "--expect=${EXPECT}")"; then
        printf '%s\n' "$validated_output"
    else
        printf '{"schema":"csp_report_only_status.v1","expectation":"unknown","status":"runtime_failed","config":{"status":"unknown","sha256":null},"aggregate":{"status":"unknown","summary":null}}\n'
        remote_status=1
    fi

    if (( header_status != 0 || remote_status != 0 )); then
        combined_status=1
    fi
    rm -f "$doctor_output"
    return "$combined_status"
}

main() {
    parse_args "$@"
    prod_require_cmd bash
    prod_require_cmd php
    prod_require_cmd ssh
    prod_print_plan 'prod-csp-report-only-status' "$PROD_SSH_TARGET" 'read-only plus bounded www-data readiness probe when active'
    run_status
}

main "$@"
