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
STATE_VALIDATOR="${SCRIPT_DIR}/csp_report_only_validate_receipt.php"
STATE_PATH="${CSP_PILOT_STATE_FILE:-/var/tmp/fh-csp-report-only-pilot.state.json}"
PILOT_LOCK_PATH="${CSP_PILOT_LOCK_PATH:-/var/tmp/fh-csp-report-only-pilot.lock}"
STATE_SCHEMA='csp_report_only_pilot.v2'
PHASE=''
ACTIVATION_MAY_BE_PRESENT=0
ROLLBACK_ATTEMPTED=0
EXPECTED_RELEASE_BINDING=''
RUN_ID=''
STATE_COMPLETED=''
STATE_CHECKPOINT=''
STATE_TERMINAL=''
STATE_RELEASE_BINDING=''
STATE_PRODUCTION_TARGET_BINDING=''
STATE_ACTIVATION_AT=''
STATE_RESULTS=''
LAST_RESULT_CLASS=''
PILOT_LOCK_HELD=0

usage() {
    cat <<'USAGE'
Usage:
  bash scripts/ops/prod_csp_report_only_pilot.sh --phase preflight|pilot [options]

`preflight` is fully read-only. `pilot` performs exactly one activation attempt,
collects evidence at 0, 15 and 60 minutes, and removes the activation file at
the end or after the first failed, unknown or contradictory result. It never
retries activation or evidence automatically.

Production execution of `pilot` requires a separate, concrete approval.

The pilot persists a release-, production-target- and run-bound checkpoint journal in
`CSP_PILOT_STATE_FILE` (default `/var/tmp/fh-csp-report-only-pilot.state.json`).
An interrupted or in-flight checkpoint is never repeated automatically.

USAGE
    prod_usage_common
}

state_write() {
    local completed="$1"
    local checkpoint="$2"
    local terminal="${3:-}"
    local directory temporary
    directory="$(dirname "$STATE_PATH")"
    mkdir -p "$directory"
    if [[ -L "$STATE_PATH" ]]; then
        printf 'csp_pilot.state.status=failed\n'
        printf 'csp_pilot.result_class=state_path_symlink\n'
        return 1
    fi
    temporary="$(mktemp "${STATE_PATH}.tmp.XXXXXX")" || {
        printf 'csp_pilot.state.status=failed\n'
        printf 'csp_pilot.result_class=state_temp_create_failed\n'
        return 1
    }
    umask 077
    local production_target_binding
    production_target_binding="$(php -r 'echo hash("sha256", $argv[1]);' "$PROD_SSH_TARGET")" || {
        printf 'csp_pilot.state.status=failed\n'
        printf 'csp_pilot.result_class=production_target_binding_failed\n'
        return 1
    }
    printf '{"schema":"%s","run_id":"%s","release_binding":"%s","production_target_binding":"%s","activation_at":"%s","completed":"%s","checkpoint":"%s","results":"%s","terminal":"%s"}\n' \
        "$STATE_SCHEMA" "$RUN_ID" "$EXPECTED_RELEASE_BINDING" "$production_target_binding" "$STATE_ACTIVATION_AT" "$completed" "$checkpoint" "$STATE_RESULTS" "$terminal" >"$temporary"
    chmod 0600 "$temporary"
    mv -f "$temporary" "$STATE_PATH"
}

acquire_pilot_lock() {
    local directory
    directory="$(dirname "$PILOT_LOCK_PATH")"
    mkdir -p "$directory"
    if ! mkdir "$PILOT_LOCK_PATH" 2>/dev/null; then
        printf 'csp_pilot.status=stopped\n'
        printf 'csp_pilot.result_class=pilot_lock_busy\n'
        return 1
    fi
    PILOT_LOCK_HELD=1
}

release_pilot_lock() {
    if (( PILOT_LOCK_HELD == 1 )); then
        rmdir "$PILOT_LOCK_PATH" 2>/dev/null || true
        PILOT_LOCK_HELD=0
    fi
}

state_read() {
    [[ -f "$STATE_PATH" && ! -L "$STATE_PATH" ]] || return 1
    local fields
    fields="$(php -r '
        $state = json_decode(stream_get_contents(STDIN), true);
        if (!is_array($state) || ($state["schema"] ?? null) !== "csp_report_only_pilot.v2") exit(2);
        foreach (["run_id", "release_binding", "production_target_binding", "activation_at", "completed", "checkpoint", "results", "terminal"] as $key) {
            if (!isset($state[$key]) || !is_string($state[$key])) exit(3);
        }
        if (!preg_match("/\\A[a-f0-9]{32}\\z/", $state["run_id"]) ||
            !preg_match("/\\A[a-f0-9]{64}\\z/", $state["release_binding"]) ||
            !preg_match("/\\A[a-f0-9]{64}\\z/", $state["production_target_binding"]) ||
            preg_match("/[^a-z0-9_,]/", $state["completed"]) ||
            preg_match("/[^a-z0-9_]/", $state["checkpoint"]) ||
            ($state["activation_at"] !== "" && preg_match("/[^0-9]/", $state["activation_at"])) ||
            preg_match("/[^a-z0-9_=,]/", $state["results"]) ||
            preg_match("/[^a-z0-9_]/", $state["terminal"])) exit(4);
        echo $state["run_id"]."|".$state["release_binding"]."|".$state["production_target_binding"]."|".$state["activation_at"]."|".$state["completed"]."|".$state["checkpoint"]."|".$state["results"]."|".$state["terminal"];
    ' <"$STATE_PATH")" || return 2
    IFS='|' read -r RUN_ID STATE_RELEASE_BINDING STATE_PRODUCTION_TARGET_BINDING STATE_ACTIVATION_AT STATE_COMPLETED STATE_CHECKPOINT STATE_RESULTS STATE_TERMINAL <<<"$fields"
    return 0
}

state_completed() {
    [[ ",${STATE_COMPLETED}," == *",$1,"* ]]
}

state_append_completed() {
    local checkpoint="$1"
    if state_completed "$checkpoint"; then
        return 0
    fi
    if [[ -n "$STATE_COMPLETED" ]]; then
        STATE_COMPLETED+=",$checkpoint"
    else
        STATE_COMPLETED="$checkpoint"
    fi
}

state_checkpoint_start() {
    STATE_CHECKPOINT="$1"
    state_write "$STATE_COMPLETED" "$STATE_CHECKPOINT"
}

state_checkpoint_pass() {
    local checkpoint="$1"
    local result_class="${2:-checkpoint_verified}"
    state_append_completed "$checkpoint"
    if [[ -n "$STATE_RESULTS" ]]; then
        STATE_RESULTS+=",$checkpoint=$result_class"
    else
        STATE_RESULTS="$checkpoint=$result_class"
    fi
    STATE_CHECKPOINT=''
    state_write "$STATE_COMPLETED" "$STATE_CHECKPOINT"
}

state_validate_semantics() {
    local expected_checkpoint=''
    case "$STATE_TERMINAL" in
        ''|checkpoint_outcome_unknown_cleanup_verified|checkpoint_outcome_unknown_cleanup_unverified) ;;
        *) return 1 ;;
    esac
    case "$STATE_COMPLETED" in
        '') expected_checkpoint='activation' ;;
        activation) expected_checkpoint='0m' ;;
        activation,0m) expected_checkpoint='15m' ;;
        activation,0m,15m) expected_checkpoint='60m' ;;
        activation,0m,15m,60m) expected_checkpoint='remove' ;;
        activation,0m,15m,60m,remove) expected_checkpoint='postflight' ;;
        activation,0m,15m,60m,remove,postflight) expected_checkpoint='' ;;
        *) return 1 ;;
    esac
    [[ "$STATE_CHECKPOINT" == '' || "$STATE_CHECKPOINT" == "$expected_checkpoint" ]] || return 1
    [[ "$STATE_COMPLETED" == '' || "$STATE_ACTIVATION_AT" =~ ^[1-9][0-9]{8,10}$ ]] || return 1
    case "$STATE_COMPLETED" in
        '') [[ "$STATE_RESULTS" == '' ]] || return 1 ;;
        activation) [[ "$STATE_RESULTS" =~ ^activation=[a-z0-9_]+$ ]] || return 1 ;;
        activation,0m) [[ "$STATE_RESULTS" =~ ^activation=[a-z0-9_]+,0m=[a-z0-9_]+$ ]] || return 1 ;;
        activation,0m,15m) [[ "$STATE_RESULTS" =~ ^activation=[a-z0-9_]+,0m=[a-z0-9_]+,15m=[a-z0-9_]+$ ]] || return 1 ;;
        activation,0m,15m,60m) [[ "$STATE_RESULTS" =~ ^activation=[a-z0-9_]+,0m=[a-z0-9_]+,15m=[a-z0-9_]+,60m=[a-z0-9_]+$ ]] || return 1 ;;
        activation,0m,15m,60m,remove) [[ "$STATE_RESULTS" =~ ^activation=[a-z0-9_]+,0m=[a-z0-9_]+,15m=[a-z0-9_]+,60m=[a-z0-9_]+,remove=[a-z0-9_]+$ ]] || return 1 ;;
        activation,0m,15m,60m,remove,postflight) [[ "$STATE_RESULTS" =~ ^activation=[a-z0-9_]+,0m=[a-z0-9_]+,15m=[a-z0-9_]+,60m=[a-z0-9_]+,remove=[a-z0-9_]+,postflight=[a-z0-9_]+$ ]] || return 1 ;;
        *) return 1 ;;
    esac
    return 0
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
        LAST_RESULT_CLASS="activation_${action}_verified"
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
    LAST_RESULT_CLASS='activation_receipt_contradictory'
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
    LAST_RESULT_CLASS="$(printf '%s\n' "$output" | sed -n 's/^csp_evidence\.result_class=\([a-z0-9_]*\)$/\1/p' | tail -n 1)"
    [[ -n "$LAST_RESULT_CLASS" ]] || LAST_RESULT_CLASS='preflight_verified'
}

run_read_only_state() {
    local expectation="$1"
    local journal_binding="$2"
    local remote_output=''
    local remote_exit=0
    local validated_output=''
    local validator_exit=0
    remote_output="$({
        ssh "${SSH_OPTIONS[@]}" "$PROD_SSH_TARGET" \
            "/usr/bin/php /var/www/html/easyappointments/scripts/ops/csp_report_only_status.php --expect=${expectation}"
    })" || remote_exit=$?
    if (( remote_exit != 0 && remote_exit != 1 && remote_exit != 2 )); then
        printf 'csp_pilot.resume.status=failed\n'
        printf 'csp_pilot.result_class=resume_state_remote_unavailable\n'
        return 1
    fi
    set +e
    validated_output="$(printf '%s' "$remote_output" | php "$STATE_VALIDATOR" \
        "--expect=${expectation}")"
    validator_exit=$?
    set -e
    if (( validator_exit == 0 && remote_exit == 0 )); then
        local current_binding=''
        current_binding="$(printf '%s' "$validated_output" | php -r '$r=json_decode(stream_get_contents(STDIN), true); echo is_string($r["release_binding"] ?? null) ? $r["release_binding"] : "";')"
        if [[ "$current_binding" != "$journal_binding" ]]; then
            printf '%s\n' "$validated_output"
            printf 'csp_pilot.resume.status=failed\n'
            printf 'csp_pilot.result_class=resume_release_binding_changed\n'
            return 1
        fi
        printf '%s\n' "$validated_output"
        printf 'csp_pilot.resume.status=passed\n'
        printf 'csp_pilot.resume.result_class=resume_state_verified\n'
        return 0
    fi
    printf '%s\n' "$validated_output"
    printf 'csp_pilot.resume.status=failed\n'
    printf 'csp_pilot.result_class=resume_state_contradictory\n'
    return 1
}

run_active_evidence() {
    local observation="$1"
    printf 'csp_pilot.observation=%s\n' "$observation"
    local output=''
    if ! output="$(bash "$STATUS_SCRIPT" --phase active --prod-ssh-target "$PROD_SSH_TARGET" \
        --expected-release-binding "$EXPECTED_RELEASE_BINDING"
    )"; then
        printf '%s\n' "$output"
        LAST_RESULT_CLASS='evidence_incomplete'
        return 1
    fi
    printf '%s\n' "$output"
    LAST_RESULT_CLASS="$(printf '%s\n' "$output" | sed -n 's/^csp_evidence\.result_class=\([a-z0-9_]*\)$/\1/p' | tail -n 1)"
    [[ -n "$LAST_RESULT_CLASS" ]] || LAST_RESULT_CLASS='evidence_verified'
}

sleep_until_checkpoint() {
    local offset_seconds="$1"
    local now due remaining
    now="$(date +%s)"
    due=$((STATE_ACTIVATION_AT + offset_seconds))
    remaining=$((due - now))
    if (( remaining > 0 )); then
        sleep "$remaining"
    fi
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
    if (( status != 0 )) && [[ -n "$RUN_ID" && -n "$EXPECTED_RELEASE_BINDING" ]]; then
        if (( ACTIVATION_MAY_BE_PRESENT == 0 )); then
            STATE_TERMINAL='checkpoint_outcome_unknown_cleanup_verified'
        else
            STATE_TERMINAL='checkpoint_outcome_unknown_cleanup_unverified'
        fi
        state_write "$STATE_COMPLETED" "$STATE_CHECKPOINT" "$STATE_TERMINAL" || true
    fi
    release_pilot_lock
    exit "$status"
}

run_pilot() {
    local resume_expectation='inactive'
    local current_target_binding=''
    printf 'csp_pilot.schema=csp_report_only_pilot.v1\n'
    printf 'csp_pilot.phase=%s\n' "$PHASE"
    if [[ "$PHASE" == 'preflight' ]]; then
        run_preflight
        printf 'csp_pilot.status=passed\n'
        return 0
    fi
    acquire_pilot_lock || return 1
    trap on_exit EXIT

    if [[ -e "$STATE_PATH" ]]; then
        [[ -f "$STATE_PATH" && ! -L "$STATE_PATH" ]] || {
            printf 'csp_pilot.status=stopped\n'
            printf 'csp_pilot.result_class=state_path_invalid\n'
            return 1
        }
        if ! state_read; then
            printf 'csp_pilot.status=stopped\n'
            printf 'csp_pilot.result_class=state_invalid\n'
            return 1
        fi
        if ! state_validate_semantics; then
            printf 'csp_pilot.status=stopped\n'
            printf 'csp_pilot.result_class=state_semantics_invalid\n'
            return 1
        fi
        current_target_binding="$(php -r 'echo hash("sha256", $argv[1]);' "$PROD_SSH_TARGET")"
        if [[ "$STATE_PRODUCTION_TARGET_BINDING" != "$current_target_binding" ]]; then
            printf 'csp_pilot.status=stopped\n'
            printf 'csp_pilot.result_class=resume_production_target_changed\n'
            return 1
        fi
        if [[ -n "$STATE_TERMINAL" ]]; then
            printf 'csp_pilot.status=stopped\n'
            printf 'csp_pilot.result_class=%s\n' "$STATE_TERMINAL"
            return 1
        fi
        EXPECTED_RELEASE_BINDING="$STATE_RELEASE_BINDING"
        if state_completed activation || [[ -n "$STATE_CHECKPOINT" && "$STATE_CHECKPOINT" != 'activation' ]]; then
            resume_expectation='active'
        fi
        if state_completed remove || [[ "$STATE_CHECKPOINT" == 'remove' ]]; then
            resume_expectation='inactive'
        fi
        if state_completed postflight; then
            resume_expectation='inactive'
        fi
        # A resumed journal never proves cleanup by itself. Keep the
        # conservative assumption until the current read-only state confirms
        # that activation is inactive.
        ACTIVATION_MAY_BE_PRESENT=1
        if [[ "$STATE_CHECKPOINT" == 'activation' ]]; then
            if run_read_only_state inactive "$STATE_RELEASE_BINDING"; then
                ACTIVATION_MAY_BE_PRESENT=0
                STATE_TERMINAL='checkpoint_outcome_unknown_cleanup_verified'
                state_write "$STATE_COMPLETED" "$STATE_CHECKPOINT" "$STATE_TERMINAL" || true
                printf 'csp_pilot.status=stopped\n'
                printf 'csp_pilot.result_class=%s\n' "$STATE_TERMINAL"
                return 1
            fi
            if run_read_only_state active "$STATE_RELEASE_BINDING"; then
                EXPECTED_RELEASE_BINDING="$STATE_RELEASE_BINDING"
                ACTIVATION_MAY_BE_PRESENT=1
                trap on_exit EXIT
                rollback_once 1 || true
                if (( ACTIVATION_MAY_BE_PRESENT == 0 )); then
                    STATE_TERMINAL='checkpoint_outcome_unknown_cleanup_verified'
                else
                    STATE_TERMINAL='checkpoint_outcome_unknown_cleanup_unverified'
                fi
                state_write "$STATE_COMPLETED" "$STATE_CHECKPOINT" "$STATE_TERMINAL" || true
                printf 'csp_pilot.status=stopped\n'
                printf 'csp_pilot.result_class=%s\n' "$STATE_TERMINAL"
                return 1
            fi
            STATE_TERMINAL='checkpoint_outcome_unknown_cleanup_unverified'
            state_write "$STATE_COMPLETED" "$STATE_CHECKPOINT" "$STATE_TERMINAL" || true
            printf 'csp_pilot.status=stopped\n'
            printf 'csp_pilot.result_class=%s\n' "$STATE_TERMINAL"
            return 1
        fi
        if ! run_read_only_state "$resume_expectation" "$STATE_RELEASE_BINDING"; then
            if [[ "$resume_expectation" == 'inactive' ]]; then
                trap on_exit EXIT
                rollback_once 1 || true
                if (( ACTIVATION_MAY_BE_PRESENT == 0 )); then
                    STATE_TERMINAL='checkpoint_outcome_unknown_cleanup_verified'
                else
                    STATE_TERMINAL='checkpoint_outcome_unknown_cleanup_unverified'
                fi
                state_write "$STATE_COMPLETED" "$STATE_CHECKPOINT" "$STATE_TERMINAL" || true
                printf 'csp_pilot.status=stopped\n'
                printf 'csp_pilot.result_class=%s\n' "$STATE_TERMINAL"
                return 1
            fi
            printf 'csp_pilot.status=stopped\n'
            printf 'csp_pilot.result_class=state_release_or_activation_mismatch\n'
            return 1
        fi
        if [[ "$resume_expectation" == 'inactive' ]]; then
            ACTIVATION_MAY_BE_PRESENT=0
        fi
        if state_completed postflight; then
            rm -f "$STATE_PATH"
            printf 'csp_pilot.status=passed\n'
            printf 'csp_pilot.result_class=postflight_already_verified\n'
            return 0
        fi
        if [[ -n "$STATE_CHECKPOINT" ]]; then
            if [[ "$STATE_CHECKPOINT" == 'remove' ]]; then
                ACTIVATION_MAY_BE_PRESENT=0
                LAST_RESULT_CLASS='activation_remove_read_only_verified'
                state_checkpoint_pass remove "$LAST_RESULT_CLASS" || {
                    printf 'csp_pilot.status=stopped\n'
                    printf 'csp_pilot.result_class=state_write_failed\n'
                    return 1
                }
            else
                if [[ "$STATE_CHECKPOINT" == 'postflight' ]]; then
                    ACTIVATION_MAY_BE_PRESENT=0
                    STATE_TERMINAL='checkpoint_outcome_unknown_cleanup_verified'
                    state_write "$STATE_COMPLETED" "$STATE_CHECKPOINT" "$STATE_TERMINAL" || true
                    printf 'csp_pilot.status=stopped\n'
                    printf 'csp_pilot.result_class=%s\n' "$STATE_TERMINAL"
                    return 1
                fi
                if [[ "$STATE_CHECKPOINT" == 'activation' && "$resume_expectation" == 'inactive' ]]; then
                    ACTIVATION_MAY_BE_PRESENT=0
                    STATE_TERMINAL='checkpoint_outcome_unknown_cleanup_verified'
                    state_write "$STATE_COMPLETED" "$STATE_CHECKPOINT" "$STATE_TERMINAL" || true
                    printf 'csp_pilot.status=stopped\n'
                    printf 'csp_pilot.result_class=%s\n' "$STATE_TERMINAL"
                    return 1
                fi
                ACTIVATION_MAY_BE_PRESENT=1
                trap on_exit EXIT
                rollback_once 1 || true
                if (( ACTIVATION_MAY_BE_PRESENT == 0 )); then
                    STATE_TERMINAL='checkpoint_outcome_unknown_cleanup_verified'
                else
                    STATE_TERMINAL='checkpoint_outcome_unknown_cleanup_unverified'
                fi
                state_write "$STATE_COMPLETED" "$STATE_CHECKPOINT" "$STATE_TERMINAL" || true
                printf 'csp_pilot.status=stopped\n'
                printf 'csp_pilot.result_class=%s\n' "$STATE_TERMINAL"
                return 1
            fi
        fi
        if [[ "$resume_expectation" == 'inactive' && "$STATE_COMPLETED" != *',remove' && "$STATE_COMPLETED" != 'activation,0m,15m,60m,remove' ]]; then
            run_preflight
        fi
    else
        run_preflight
        RUN_ID="$(php -r 'echo bin2hex(random_bytes(16));')"
        [[ "$RUN_ID" =~ ^[a-f0-9]{32}$ ]] || {
            printf 'csp_pilot.status=failed\n'
            printf 'csp_pilot.result_class=run_id_generation_failed\n'
            return 1
        }
        state_write '' '' || {
            printf 'csp_pilot.status=failed\n'
            printf 'csp_pilot.result_class=state_write_failed\n'
            return 1
        }
    fi
    if state_completed remove; then
        ACTIVATION_MAY_BE_PRESENT=0
    else
        ACTIVATION_MAY_BE_PRESENT=1
    fi
    trap on_exit EXIT
    if ! state_completed activation; then
        state_checkpoint_start activation
        run_activation_action install
        STATE_ACTIVATION_AT="$(date +%s)"
        [[ "$STATE_ACTIVATION_AT" =~ ^[1-9][0-9]{8,10}$ ]] || {
            printf 'csp_pilot.status=stopped\n'
            printf 'csp_pilot.result_class=activation_time_unavailable\n'
            return 1
        }
        state_checkpoint_pass activation "$LAST_RESULT_CLASS"
    fi
    if ! state_completed 0m; then
        state_checkpoint_start 0m
        run_active_evidence '0m'
        state_checkpoint_pass 0m "$LAST_RESULT_CLASS"
    fi
    if ! state_completed 15m; then
        sleep_until_checkpoint 900
        state_checkpoint_start 15m
        run_active_evidence '15m'
        state_checkpoint_pass 15m "$LAST_RESULT_CLASS"
    fi
    if ! state_completed 60m; then
        sleep_until_checkpoint 3600
        state_checkpoint_start 60m
        run_active_evidence '60m'
        state_checkpoint_pass 60m "$LAST_RESULT_CLASS"
    fi

    if ! state_completed remove; then
        state_checkpoint_start remove
        rollback_once 0
        state_checkpoint_pass remove "$LAST_RESULT_CLASS"
    fi
    state_checkpoint_start postflight
    run_preflight
    state_checkpoint_pass postflight "$LAST_RESULT_CLASS"
    rm -f "$STATE_PATH"
    printf 'csp_pilot.status=passed\n'
}

main() {
    parse_args "$@"
    prod_require_cmd bash
    prod_require_cmd php
    prod_require_cmd ssh
    prod_require_cmd sleep
    prod_require_cmd date
    prod_require_cmd mktemp
    prod_print_plan 'prod-csp-report-only-pilot' "$PROD_SSH_TARGET" \
        "$([[ "$PHASE" == 'preflight' ]] && printf 'fully read-only preactivation gate' || printf 'single supervised activation with automatic fail-closed removal')"
    run_pilot
}

main "$@"
