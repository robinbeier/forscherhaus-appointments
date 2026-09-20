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
STATUS_SCRIPT="${CSP_PILOT_STATUS_SCRIPT:-${SCRIPT_DIR}/prod_csp_report_only_status.sh}"
ACTIVATION_VALIDATOR="${SCRIPT_DIR}/csp_report_only_activation_validate_receipt.php"
PHASE=''
ACTIVATION_MAY_BE_PRESENT=0
ROLLBACK_ATTEMPTED=0
EXPECTED_RELEASE_BINDING=''
RUN_ID=''

usage() {
    cat <<'USAGE'
Usage:
  bash scripts/ops/prod_csp_report_only_pilot.sh --phase preflight|pilot [options]

`preflight` is fully read-only. `pilot` performs exactly one activation attempt,
collects evidence at 0, 15 and 60 minutes, and removes the activation file at
the end or after the first failed, unknown or contradictory result. It never
retries activation or evidence automatically.

Production execution of `pilot` requires a separate, concrete approval.

USAGE
    prod_usage_common
}

parse_args() {
    local phase_seen=0
    local target_seen=0
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --phase)
                (( phase_seen == 0 )) || { printf 'ERROR: --phase supplied more than once.\n' >&2; exit 1; }
                [[ $# -ge 2 ]] || { printf 'ERROR: --phase requires a value.\n' >&2; exit 1; }
                phase_seen=1
                PHASE="$2"
                shift 2
                ;;
            --prod-ssh-target)
                (( target_seen == 0 )) || { printf 'ERROR: --prod-ssh-target supplied more than once.\n' >&2; exit 1; }
                [[ $# -ge 2 ]] || { printf 'ERROR: --prod-ssh-target requires a value.\n' >&2; exit 1; }
                target_seen=1
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
    [[ "$PHASE" == 'preflight' || "$PHASE" == 'pilot' ]] || {
        printf 'ERROR: --phase must be preflight or pilot.\n' >&2
        exit 1
    }
}

run_activation_action() {
    local action="$1"
    local remote_output=''
    local remote_exit=0
    local validated_output=''
    local validator_exit=0
    local remote_command=''

    remote_command="/usr/bin/php /var/www/html/easyappointments/scripts/ops/csp_report_only_activation.php --action=${action} --expected-release-binding=${EXPECTED_RELEASE_BINDING} --run-id=${RUN_ID}"
    remote_output="$(
        ssh "${SSH_OPTIONS[@]}" "$PROD_SSH_TARGET" \
            "$remote_command"
    )" || remote_exit=$?
    if (( remote_exit != 0 && remote_exit != 1 && remote_exit != 2 )); then
        printf 'csp_pilot.activation.status=unknown\n'
        printf 'csp_pilot.activation.result_class=activation_remote_unavailable\n'
        return 1
    fi

    set +e
    validated_output="$(
        printf '%s' "$remote_output" |
            php "$ACTIVATION_VALIDATOR" "--action=${action}" \
                "--expected-release-binding=${EXPECTED_RELEASE_BINDING}" "--run-id=${RUN_ID}"
    )"
    validator_exit=$?
    set -e
    if (( validator_exit == 0 && remote_exit == 0 )); then
        printf '%s\n' "$validated_output"
        printf 'csp_pilot.activation.status=passed\n'
        printf 'csp_pilot.activation.result_class=activation_%s_verified\n' "$action"
        return 0
    fi
    if (( validator_exit == 3 && (remote_exit == 1 || remote_exit == 2) )); then
        printf '%s\n' "$validated_output"
        printf 'csp_pilot.activation.status=failed\n'
        printf 'csp_pilot.activation.result_class=activation_reported_failure\n'
        return 1
    fi
    printf 'csp_pilot.activation.status=unknown\n'
    printf 'csp_pilot.activation.result_class=activation_receipt_contradictory\n'
    return 1
}

run_preflight() {
    local output=''
    local args=(--phase preflight --prod-ssh-target "$PROD_SSH_TARGET")
    local binding=''
    if [[ -n "$EXPECTED_RELEASE_BINDING" ]]; then
        args+=(--expected-release-binding "$EXPECTED_RELEASE_BINDING")
    fi
    if ! output="$(bash "$STATUS_SCRIPT" "${args[@]}")"; then
        printf '%s\n' "$output"
        return 1
    fi
    printf '%s\n' "$output"
    binding="$(
        printf '%s\n' "$output" |
            sed -n 's/^csp_evidence\.release_binding=\([a-f0-9]\{64\}\)$/\1/p'
    )"
    if [[ ! "$binding" =~ ^[a-f0-9]{64}$ ]]; then
        printf 'csp_pilot.preflight.status=failed\n'
        printf 'csp_pilot.preflight.result_class=release_binding_missing\n'
        return 1
    fi
    if [[ -z "$EXPECTED_RELEASE_BINDING" ]]; then
        EXPECTED_RELEASE_BINDING="$binding"
    elif [[ "$binding" != "$EXPECTED_RELEASE_BINDING" ]]; then
        printf 'csp_pilot.preflight.status=failed\n'
        printf 'csp_pilot.preflight.result_class=release_binding_changed\n'
        return 1
    fi
}

run_active_evidence() {
    local observation="$1"
    printf 'csp_pilot.observation=%s\n' "$observation"
    bash "$STATUS_SCRIPT" --phase active --prod-ssh-target "$PROD_SSH_TARGET" \
        --expected-release-binding "$EXPECTED_RELEASE_BINDING"
}

rollback_once() {
    local original_status="${1:-1}"
    if (( ACTIVATION_MAY_BE_PRESENT == 0 || ROLLBACK_ATTEMPTED == 1 )); then
        return "$original_status"
    fi
    ROLLBACK_ATTEMPTED=1
    printf 'csp_pilot.rollback.status=started\n'
    if run_activation_action remove; then
        ACTIVATION_MAY_BE_PRESENT=0
        printf 'csp_pilot.rollback.status=passed\n'
    else
        printf 'csp_pilot.rollback.status=failed\n'
        return 1
    fi
    return "$original_status"
}

on_exit() {
    local status=$?
    trap - EXIT
    rollback_once "$status" || status=$?
    exit "$status"
}

run_pilot() {
    printf 'csp_pilot.schema=csp_report_only_pilot.v1\n'
    printf 'csp_pilot.phase=%s\n' "$PHASE"
    run_preflight
    if [[ "$PHASE" == 'preflight' ]]; then
        printf 'csp_pilot.status=passed\n'
        return 0
    fi

    RUN_ID="$(php -r 'echo bin2hex(random_bytes(16));')"
    [[ "$RUN_ID" =~ ^[a-f0-9]{32}$ ]] || {
        printf 'csp_pilot.status=failed\n'
        printf 'csp_pilot.result_class=run_id_generation_failed\n'
        return 1
    }
    ACTIVATION_MAY_BE_PRESENT=1
    trap on_exit EXIT
    run_activation_action install
    run_active_evidence '0m'
    sleep 900
    run_active_evidence '15m'
    sleep 2700
    run_active_evidence '60m'

    rollback_once 0
    run_preflight
    trap - EXIT
    printf 'csp_pilot.status=passed\n'
}

main() {
    parse_args "$@"
    prod_require_cmd bash
    prod_require_cmd php
    prod_require_cmd ssh
    prod_require_cmd sleep
    prod_print_plan 'prod-csp-report-only-pilot' "$PROD_SSH_TARGET" \
        "$([[ "$PHASE" == 'preflight' ]] && printf 'fully read-only preactivation gate' || printf 'single supervised activation with automatic fail-closed removal')"
    run_pilot
}

main "$@"
