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
STATE_VALIDATOR_SCRIPT="${SCRIPT_DIR}/csp_report_only_validate_receipt.php"
RUNTIME_VALIDATOR_SCRIPT="${SCRIPT_DIR}/csp_report_only_runtime_validate_receipt.php"
ACTIVATION_VALIDATOR_SCRIPT="${SCRIPT_DIR}/csp_report_only_activation_validate_receipt.php"
PHASE=''
EXPECTED_RELEASE_BINDING=''
CURRENT_RELEASE_BINDING=''

usage() {
    cat <<'USAGE'
Usage:
  bash scripts/ops/prod_csp_report_only_status.sh --phase preflight|active [options]

`preflight` is fully read-only and must pass before the activation file is
installed. It also validates the deployed release, activation candidate,
canonical production lock, target directory, health token and local HTTP
client without changing production state. `active` adds one bounded
create/sync/delete readiness probe through the host-local token-protected web
endpoint. Both phases keep activation, public headers, runtime readiness, the
classified aggregate, and functional health as separate evidence classes.

USAGE
    prod_usage_common
}

parse_args() {
    local phase_seen=0
    local target_seen=0
    local binding_seen=0
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --phase)
                (( phase_seen == 0 )) || { printf 'ERROR: phase supplied more than once.\n' >&2; exit 1; }
                [[ $# -ge 2 ]] || { printf 'ERROR: --phase requires a value.\n' >&2; exit 1; }
                phase_seen=1
                PHASE="$2"
                shift 2
                ;;
            --expect)
                (( phase_seen == 0 )) || { printf 'ERROR: phase supplied more than once.\n' >&2; exit 1; }
                [[ $# -ge 2 ]] || { printf 'ERROR: --expect requires a value.\n' >&2; exit 1; }
                phase_seen=1
                case "$2" in
                    inactive) PHASE='preflight' ;;
                    active) PHASE='active' ;;
                    *) printf 'ERROR: --expect must be inactive or active.\n' >&2; exit 1 ;;
                esac
                shift 2
                ;;
            --expected-release-binding)
                (( binding_seen == 0 )) || { printf 'ERROR: --expected-release-binding supplied more than once.\n' >&2; exit 1; }
                [[ $# -ge 2 ]] || { printf 'ERROR: --expected-release-binding requires a value.\n' >&2; exit 1; }
                binding_seen=1
                EXPECTED_RELEASE_BINDING="$2"
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

    [[ "$PHASE" == 'preflight' || "$PHASE" == 'active' ]] || {
        printf 'ERROR: --phase must be preflight or active.\n' >&2
        exit 1
    }
    [[ -z "$EXPECTED_RELEASE_BINDING" || "$EXPECTED_RELEASE_BINDING" =~ ^[a-f0-9]{64}$ ]] || {
        printf 'ERROR: --expected-release-binding must be a lowercase SHA-256 value.\n' >&2
        exit 1
    }
}

extract_release_binding() {
    php -r '
        $receipt = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
        $binding = $receipt["release_binding"] ?? null;
        if (!is_string($binding) || preg_match("/\\A[a-f0-9]{64}\\z/", $binding) !== 1) {
            exit(1);
        }
        echo $binding;
    '
}

expect_line() {
    local output_file="$1"
    local line="$2"
    [[ "$(grep -Fxc -- "$line" "$output_file" || true)" == '1' ]]
}

expect_one_of_lines() {
    local output_file="$1"
    shift
    local matches=0
    local line
    for line in "$@"; do
        matches=$((matches + $(grep -Fxc -- "$line" "$output_file" || true)))
    done
    [[ "$matches" == '1' ]]
}

verify_header_classes() {
    local output_file="$1"
    local expected_app_www='missing'
    local failures=0
    local surface

    if [[ "$PHASE" == 'active' ]]; then
        expected_app_www='present'
    fi
    for surface in app_https www_https; do
        expect_line "$output_file" "posture_header.${surface}.csp=missing" || failures=$((failures + 1))
        expect_line "$output_file" "posture_header.${surface}.csp_report_only=${expected_app_www}" || failures=$((failures + 1))
    done
    expect_line "$output_file" 'posture_header.monitor_https.csp=missing' || failures=$((failures + 1))
    expect_line "$output_file" 'posture_header.monitor_https.csp_report_only=missing' || failures=$((failures + 1))

    if (( failures == 0 )); then
        printf 'csp_evidence.public_headers.status=passed\n'
        printf 'csp_evidence.public_headers.result_class=header_posture_verified\n'
        return 0
    fi
    printf 'csp_evidence.public_headers.status=failed\n'
    printf 'csp_evidence.public_headers.result_class=header_posture_mismatch\n'
    return 1
}

verify_functional_health() {
    local output_file="$1"
    local failures=0
    expect_line "$output_file" 'app_https=200' || failures=$((failures + 1))
    expect_line "$output_file" 'www_https=200' || failures=$((failures + 1))
    expect_one_of_lines "$output_file" 'monitor_https=200' 'monitor_https=301' 'monitor_https=302' || failures=$((failures + 1))
    expect_line "$output_file" 'renderer_http=200' || failures=$((failures + 1))
    expect_line "$output_file" 'deep_health_http=200' || failures=$((failures + 1))

    if (( failures == 0 )); then
        printf 'csp_evidence.functional_health.status=passed\n'
        printf 'csp_evidence.functional_health.result_class=functional_health_verified\n'
        return 0
    fi
    printf 'csp_evidence.functional_health.status=failed\n'
    printf 'csp_evidence.functional_health.result_class=functional_health_mismatch\n'
    return 1
}

run_state_evidence() {
    local expectation="$1"
    local remote_output=''
    local remote_exit=0
    local validated_output=''
    local validator_exit=0
    local validator_args=("--expect=${expectation}")
    remote_output="$(
        ssh "${SSH_OPTIONS[@]}" "$PROD_SSH_TARGET" \
            "/usr/bin/php /var/www/html/easyappointments/scripts/ops/csp_report_only_status.php --expect=${expectation}"
    )" || remote_exit=$?
    if (( remote_exit != 0 && remote_exit != 1 && remote_exit != 2 )); then
        printf 'csp_evidence.activation.status=failed\n'
        printf 'csp_evidence.activation.result_class=state_remote_unavailable\n'
        printf 'csp_evidence.aggregate.status=not_checked\n'
        printf 'csp_evidence.aggregate.result_class=state_remote_unavailable\n'
        return 1
    fi

    set +e
    validated_output="$(printf '%s' "$remote_output" | php "$STATE_VALIDATOR_SCRIPT" "${validator_args[@]}")"
    validator_exit=$?
    set -e
    if (( validator_exit == 0 && remote_exit == 0 )); then
        CURRENT_RELEASE_BINDING="$(printf '%s' "$validated_output" | extract_release_binding)" || {
            printf 'csp_evidence.activation.status=failed\n'
            printf 'csp_evidence.activation.result_class=release_binding_invalid\n'
            printf 'csp_evidence.aggregate.status=not_checked\n'
            printf 'csp_evidence.aggregate.result_class=release_binding_invalid\n'
            return 1
        }
        if [[ -n "$EXPECTED_RELEASE_BINDING" && "$CURRENT_RELEASE_BINDING" != "$EXPECTED_RELEASE_BINDING" ]]; then
            printf '%s\n' "$validated_output"
            printf 'csp_evidence.release_binding=%s\n' "$CURRENT_RELEASE_BINDING"
            printf 'csp_evidence.activation.status=failed\n'
            printf 'csp_evidence.activation.result_class=release_binding_changed\n'
            printf 'csp_evidence.aggregate.status=not_checked\n'
            printf 'csp_evidence.aggregate.result_class=release_binding_changed\n'
            return 1
        fi
        printf '%s\n' "$validated_output"
        printf 'csp_evidence.release_binding=%s\n' "$CURRENT_RELEASE_BINDING"
        printf 'csp_evidence.activation.status=passed\n'
        printf 'csp_evidence.activation.result_class=activation_state_verified\n'
        printf 'csp_evidence.aggregate.status=passed\n'
        printf 'csp_evidence.aggregate.result_class=aggregate_state_verified\n'
        return 0
    fi
    if (( validator_exit == 3 && (remote_exit == 1 || remote_exit == 2) )); then
        printf '%s\n' "$validated_output"
        printf 'csp_evidence.activation.status=failed\n'
        printf 'csp_evidence.activation.result_class=state_reported_failure\n'
        printf 'csp_evidence.aggregate.status=failed\n'
        printf 'csp_evidence.aggregate.result_class=state_reported_failure\n'
        return 1
    fi

    printf 'csp_evidence.activation.status=failed\n'
    printf 'csp_evidence.activation.result_class=state_receipt_contradictory\n'
    printf 'csp_evidence.aggregate.status=not_checked\n'
    printf 'csp_evidence.aggregate.result_class=state_receipt_contradictory\n'
    return 1
}

run_activation_prerequisites() {
    local remote_output=''
    local remote_exit=0
    local validated_output=''
    local validator_exit=0

    if [[ "$PHASE" == 'active' ]]; then
        printf 'csp_evidence.activation_prerequisites.status=not_run\n'
        printf 'csp_evidence.activation_prerequisites.result_class=active_state\n'
        return 0
    fi
    if [[ ! "$CURRENT_RELEASE_BINDING" =~ ^[a-f0-9]{64}$ ]]; then
        printf 'csp_evidence.activation_prerequisites.status=failed\n'
        printf 'csp_evidence.activation_prerequisites.result_class=release_binding_unavailable\n'
        return 1
    fi

    remote_output="$(
        ssh "${SSH_OPTIONS[@]}" "$PROD_SSH_TARGET" \
            "/usr/bin/php /var/www/html/easyappointments/scripts/ops/csp_report_only_activation.php --action=preflight --expected-release-binding=${CURRENT_RELEASE_BINDING}"
    )" || remote_exit=$?
    set +e
    validated_output="$(
        printf '%s' "$remote_output" |
            php "$ACTIVATION_VALIDATOR_SCRIPT" --action=preflight \
                "--expected-release-binding=${CURRENT_RELEASE_BINDING}"
    )"
    validator_exit=$?
    set -e
    if (( validator_exit == 0 && remote_exit == 0 )); then
        printf '%s\n' "$validated_output"
        printf 'csp_evidence.activation_prerequisites.status=passed\n'
        printf 'csp_evidence.activation_prerequisites.result_class=activation_preflight_verified\n'
        return 0
    fi
    if (( validator_exit == 3 && (remote_exit == 1 || remote_exit == 2) )); then
        printf '%s\n' "$validated_output"
        printf 'csp_evidence.activation_prerequisites.status=failed\n'
        printf 'csp_evidence.activation_prerequisites.result_class=activation_preflight_reported_failure\n'
        return 1
    fi
    printf 'csp_evidence.activation_prerequisites.status=failed\n'
    printf 'csp_evidence.activation_prerequisites.result_class=activation_preflight_receipt_contradictory\n'
    return 1
}

run_runtime_evidence() {
    local remote_output=''
    local remote_exit=0
    local validated_output=''
    local validator_exit=0

    if [[ "$PHASE" == 'preflight' ]]; then
        printf 'csp_evidence.runtime_write_readiness.status=not_run\n'
        printf 'csp_evidence.runtime_write_readiness.result_class=preactivation_read_only\n'
        return 0
    fi

    remote_output="$(
        ssh "${SSH_OPTIONS[@]}" "$PROD_SSH_TARGET" \
            '/usr/bin/php /var/www/html/easyappointments/scripts/ops/csp_report_only_runtime_probe.php'
    )" || remote_exit=$?
    if (( remote_exit != 0 && remote_exit != 1 && remote_exit != 2 )); then
        printf 'csp_evidence.runtime_write_readiness.status=failed\n'
        printf 'csp_evidence.runtime_write_readiness.result_class=runtime_remote_unavailable\n'
        return 1
    fi

    set +e
    validated_output="$(printf '%s' "$remote_output" | php "$RUNTIME_VALIDATOR_SCRIPT")"
    validator_exit=$?
    set -e
    if (( validator_exit == 0 && remote_exit == 0 )); then
        printf '%s\n' "$validated_output"
        printf 'csp_evidence.runtime_write_readiness.status=passed\n'
        printf 'csp_evidence.runtime_write_readiness.result_class=write_ready\n'
        return 0
    fi
    if (( validator_exit == 3 && (remote_exit == 1 || remote_exit == 2) )); then
        printf '%s\n' "$validated_output"
        printf 'csp_evidence.runtime_write_readiness.status=failed\n'
        printf 'csp_evidence.runtime_write_readiness.result_class=probe_reported_failure\n'
        return 1
    fi

    printf 'csp_evidence.runtime_write_readiness.status=failed\n'
    printf 'csp_evidence.runtime_write_readiness.result_class=runtime_receipt_contradictory\n'
    return 1
}

run_status() {
    local doctor_output
    local expectation='inactive'
    local header_status=0
    local health_status=0
    local state_status=0
    local prerequisite_status=0
    local runtime_status=0

    [[ "$PHASE" == 'active' ]] && expectation='active'
    doctor_output="$(mktemp)"

    printf 'csp_evidence.schema=csp_report_only_evidence.v2\n'
    printf 'csp_evidence.phase=%s\n' "$PHASE"
    if ! bash "$PROD_DOCTOR_SCRIPT" --prod-ssh-target "$PROD_SSH_TARGET" >"$doctor_output"; then
        rm -f "$doctor_output"
        printf 'csp_evidence.status=failed\n'
        printf 'csp_evidence.result_class=doctor_unavailable\n'
        return 1
    fi

    verify_header_classes "$doctor_output" || header_status=1
    verify_functional_health "$doctor_output" || health_status=1
    run_state_evidence "$expectation" || state_status=1
    if (( state_status == 0 )); then
        run_activation_prerequisites || prerequisite_status=1
    else
        printf 'csp_evidence.activation_prerequisites.status=not_run\n'
        printf 'csp_evidence.activation_prerequisites.result_class=state_unavailable\n'
        prerequisite_status=1
    fi
    if (( state_status == 0 )); then
        run_runtime_evidence || runtime_status=1
    else
        printf 'csp_evidence.runtime_write_readiness.status=not_run\n'
        printf 'csp_evidence.runtime_write_readiness.result_class=state_unavailable\n'
        runtime_status=1
    fi
    rm -f "$doctor_output"

    if ((
        header_status == 0 &&
        health_status == 0 &&
        state_status == 0 &&
        prerequisite_status == 0 &&
        runtime_status == 0
    )); then
        printf 'csp_evidence.status=passed\n'
        printf 'csp_evidence.result_class=evidence_verified\n'
        return 0
    fi
    printf 'csp_evidence.status=failed\n'
    printf 'csp_evidence.result_class=evidence_incomplete\n'
    return 1
}

main() {
    parse_args "$@"
    prod_require_cmd bash
    prod_require_cmd php
    prod_require_cmd ssh
    prod_print_plan 'prod-csp-report-only-status' "$PROD_SSH_TARGET" \
        "$([[ "$PHASE" == 'preflight' ]] && printf 'fully read-only preactivation evidence' || printf 'active evidence plus one bounded web-runtime write probe')"
    run_status
}

main "$@"
