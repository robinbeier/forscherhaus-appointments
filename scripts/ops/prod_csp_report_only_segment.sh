#!/usr/bin/env bash
set -euo pipefail

if [[ "$(uname -s)" == "Darwin" ]]; then
    export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/lib/prod_common.sh"

SSH_OPTIONS=(-o StrictHostKeyChecking=accept-new)
PROD_SSH_TARGET="$(prod_default_ssh_target)"
STATUS_SCRIPT="${CSP_SEGMENT_STATUS_SCRIPT:-${SCRIPT_DIR}/prod_csp_report_only_status.sh}"
ACTIVATION_VALIDATOR="${SCRIPT_DIR}/csp_report_only_activation_validate_receipt.php"
STATE_PATH="${CSP_SEGMENT_STATE_FILE:-/var/tmp/fh-csp-report-only-segment.state.json}"
PHASE=''
DURATION_SECONDS=''
JOURNAL_LOCK_HELD=0

usage() {
    cat <<'USAGE'
Usage:
  bash scripts/ops/prod_csp_report_only_segment.sh --phase preflight|start|recover|observe|finish [options]

preflight is read-only. start creates one durable journal before one remote
install-segment call. recover is read-only on production and reconstructs a
finishable journal after a confirmed install with a lost local journal update.
observe is read-only and resumable. finish removes the
bound segment at most once and verifies the inactive postflight state.
USAGE
    prod_usage_common
}

fail() {
    printf 'csp_segment.status=failed\n'
    printf 'csp_segment.result_class=%s\n' "$1"
    return 1
}

acquire_journal_lock() {
    if ! mkdir "${STATE_PATH}.lock" 2>/dev/null; then
        printf 'csp_segment.status=stopped\n'
        printf 'csp_segment.result_class=segment_journal_busy\n'
        return 1
    fi
    JOURNAL_LOCK_HELD=1
}

release_journal_lock() {
    if (( JOURNAL_LOCK_HELD == 1 )); then
        rmdir "${STATE_PATH}.lock" 2>/dev/null || true
        JOURNAL_LOCK_HELD=0
    fi
}

parse_args() {
    local phase_seen=0 duration_seen=0 target_seen=0
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --phase)
                (( phase_seen == 0 )) || { printf 'ERROR: --phase supplied more than once.\n' >&2; exit 2; }
                [[ $# -ge 2 ]] || { printf 'ERROR: --phase requires a value.\n' >&2; exit 2; }
                phase_seen=1; PHASE="$2"; shift 2 ;;
            --duration-seconds)
                (( duration_seen == 0 )) || { printf 'ERROR: duration supplied more than once.\n' >&2; exit 2; }
                [[ $# -ge 2 && "$2" =~ ^[0-9]+$ ]] || { printf 'ERROR: duration must be an integer.\n' >&2; exit 2; }
                duration_seen=1; DURATION_SECONDS="$2"; shift 2 ;;
            --duration-seconds=*)
                (( duration_seen == 0 )) || { printf 'ERROR: duration supplied more than once.\n' >&2; exit 2; }
                DURATION_SECONDS="${1#*=}"; [[ "$DURATION_SECONDS" =~ ^[0-9]+$ ]] || { printf 'ERROR: duration must be an integer.\n' >&2; exit 2; }
                duration_seen=1; shift ;;
            --prod-ssh-target)
                (( target_seen == 0 )) || { printf 'ERROR: target supplied more than once.\n' >&2; exit 2; }
                [[ $# -ge 2 ]] || { printf 'ERROR: target requires a value.\n' >&2; exit 2; }
                target_seen=1; PROD_SSH_TARGET="$2"; shift 2 ;;
            -h|--help) usage; exit 0 ;;
            *) printf 'ERROR: unknown option: %s\n' "$1" >&2; exit 2 ;;
        esac
    done
    [[ "$PHASE" =~ ^(preflight|start|recover|observe|finish)$ ]] || { printf 'ERROR: invalid phase.\n' >&2; exit 2; }
    if [[ "$PHASE" == 'start' ]]; then
        [[ "$DURATION_SECONDS" =~ ^[0-9]+$ && "$DURATION_SECONDS" -ge 900 && "$DURATION_SECONDS" -le 14400 ]] || {
            printf 'ERROR: start requires duration 900..14400.\n' >&2; exit 2;
        }
    elif [[ -n "$DURATION_SECONDS" ]]; then
        printf 'ERROR: duration is accepted only for start.\n' >&2; exit 2
    fi
}

target_binding() { php -r 'echo hash("sha256", $argv[1]);' "$PROD_SSH_TARGET"; }

write_journal() {
    local json allow_update="${JOURNAL_UPDATE:-1}"
    json="$(php -r '
        $path=$argv[1]; $run=$argv[2]; $binding=$argv[3]; $target=$argv[4]; $duration=$argv[5];
        $hash=$argv[6] === "" ? null : $argv[6]; $start=$argv[7] === "" ? null : (int)$argv[7];
        $expiry=$argv[8] === "" ? null : (int)$argv[8]; $checkpoint=$argv[9]; $terminal=$argv[10]; $attempted=(bool)(int)$argv[11];
        $value=["schema"=>"csp_report_only_segment.v1","run_id"=>$run,"release_binding"=>$binding,"production_target_binding"=>$target,
          "duration_seconds"=>(int)$duration,"candidate_sha256"=>$hash,"starts_at_unix"=>$start,"expires_at_unix"=>$expiry,
          "checkpoint"=>$checkpoint,"terminal"=>$terminal,"removal_attempted"=>$attempted];
        $allow=(int)$argv[12]; $dir=dirname($path); if (!is_dir($dir) && !mkdir($dir,0700,true)) exit(2);
        if (is_link($path) || (!$allow && lstat($path)!==false)) exit(3); $tmp=tempnam($dir,".segment-"); if (!is_string($tmp)) exit(4);
        $bytes=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
        $handle=@fopen($tmp,"wb");
        $saved=is_resource($handle) && @chmod($tmp,0600) && @fwrite($handle,$bytes)===strlen($bytes) && @fflush($handle) && (!function_exists("fsync") || @fsync($handle));
        if (is_resource($handle)) fclose($handle);
        if (!$saved) { @unlink($tmp); exit(5); }
        if (!($allow ? @rename($tmp,$path) : @link($tmp,$path))) { @unlink($tmp); exit(6); }
        @unlink($tmp);
        $directory=@fopen($dir,"rb");
        $synced=is_resource($directory) && (!function_exists("fsync") || @fsync($directory));
        if (is_resource($directory)) fclose($directory);
        if (!$synced) exit(7);
    ' "$STATE_PATH" "$RUN_ID" "$RELEASE_BINDING" "$(target_binding)" "$DURATION_SECONDS" "${CANDIDATE_SHA256:-}" "${STARTS_AT_UNIX:-}" "${EXPIRES_AT_UNIX:-}" "$CHECKPOINT" "$TERMINAL" "$REMOVAL_ATTEMPTED" "$allow_update")" || return 1
}

read_journal() {
    [[ -f "$STATE_PATH" && ! -L "$STATE_PATH" ]] || return 1
    local fields
    fields="$(php -r '
      $s=json_decode(stream_get_contents(STDIN),true); if (!is_array($s) || ($s["schema"]??null)!=="csp_report_only_segment.v1") exit(2);
      foreach (["run_id","release_binding","production_target_binding","duration_seconds","candidate_sha256","starts_at_unix","expires_at_unix","checkpoint","terminal","removal_attempted"] as $k) if (!array_key_exists($k,$s)) exit(3);
      if (!preg_match("/\A[a-f0-9]{32}\z/",$s["run_id"]) || !preg_match("/\A[a-f0-9]{64}\z/",$s["release_binding"]) || !preg_match("/\A[a-f0-9]{64}\z/",$s["production_target_binding"])) exit(4);
      if (!is_int($s["duration_seconds"]) || $s["duration_seconds"]<900 || $s["duration_seconds"]>14400) exit(5);
      foreach (["checkpoint","terminal"] as $k) if (!is_string($s[$k]) || preg_match("/[^a-z0-9_]/",$s[$k])) exit(6);
      foreach (["candidate_sha256"] as $k) if ($s[$k]!==null && (!is_string($s[$k]) || !preg_match("/\A[a-f0-9]{64}\z/",$s[$k]))) exit(7);
      foreach (["starts_at_unix","expires_at_unix"] as $k) if ($s[$k]!==null && !is_int($s[$k])) exit(8);
      if (!is_bool($s["removal_attempted"])) exit(9);
      echo $s["run_id"]."|".$s["release_binding"]."|".$s["production_target_binding"]."|".$s["duration_seconds"]."|".($s["candidate_sha256"]??"")."|".($s["starts_at_unix"]??"")."|".($s["expires_at_unix"]??"")."|".$s["checkpoint"]."|".$s["terminal"]."|".(int)$s["removal_attempted"];
    ' <"$STATE_PATH")" || return 2
    IFS='|' read -r RUN_ID RELEASE_BINDING STATE_TARGET DURATION_SECONDS CANDIDATE_SHA256 STARTS_AT_UNIX EXPIRES_AT_UNIX CHECKPOINT TERMINAL REMOVAL_ATTEMPTED <<<"$fields"
}

status_check() {
    local expectation="$1" output
    output="$(bash "$STATUS_SCRIPT" --expect "$expectation" --prod-ssh-target "$PROD_SSH_TARGET")" || {
        printf '%s\n' "$output"; return 1;
    }
    printf '%s\n' "$output"
    return 0
}

remote_activation() {
    local action="$1" output exit_code=0
    local validator_args=("--action=$action" "--expected-release-binding=$RELEASE_BINDING" "--run-id=$RUN_ID")
    local duration_arg=''
    [[ "$action" == 'install-segment' || "$action" == 'inspect-segment' ]] && validator_args+=("--duration-seconds=$DURATION_SECONDS")
    [[ "$action" == 'install-segment' || "$action" == 'inspect-segment' ]] && duration_arg=" --duration-seconds=$DURATION_SECONDS"
    [[ "$action" == 'remove' ]] && validator_args+=("--expected-candidate-sha256=$CANDIDATE_SHA256")
    output="$(ssh "${SSH_OPTIONS[@]}" "$PROD_SSH_TARGET" "/usr/bin/php /var/www/html/easyappointments/scripts/ops/csp_report_only_activation.php --action=$action --expected-release-binding=$RELEASE_BINDING --run-id=$RUN_ID$duration_arg")" || exit_code=$?
    local validated
    set +e
    validated="$(printf '%s' "$output" | php "$ACTIVATION_VALIDATOR" "${validator_args[@]}")"
    local validator_exit=$?
    set -e
    if (( exit_code == 0 && validator_exit == 0 )); then
        printf '%s' "$validated"; return 0
    fi
    if (( (exit_code == 1 || exit_code == 2) && validator_exit == 3 )); then
        printf '%s' "$validated"; return 3
    fi
    return 1
}

extract_receipt() { php -r '$r=json_decode(stream_get_contents(STDIN),true); if(!is_array($r))exit(1); foreach(array_slice($argv,1) as $k){if(!array_key_exists($k,$r))exit(2); echo ($r[$k]??"")."\n";}' "$@"; }

status_receipt() {
    php -r '
      foreach (file("php://stdin", FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $r=json_decode($line,true);
        if (is_array($r) && ($r["schema"]??null)==="csp_report_only_state.v2") { echo $line; exit; }
      }
      exit(1);
    '
}

verify_segment_status() {
    local expectation="$1" output="$2" receipt status hash starts expires binding scope
    receipt="$(printf '%s\n' "$output" | status_receipt)" || return 1
    status="$(printf '%s' "$receipt" | extract_receipt status)"
    hash="$(printf '%s' "$receipt" | php -r '$r=json_decode(stream_get_contents(STDIN),true); echo $r["activation"]["sha256"]??"";')"
    starts="$(printf '%s' "$receipt" | php -r '$r=json_decode(stream_get_contents(STDIN),true); echo $r["activation"]["starts_at_unix"]??"";')"
    expires="$(printf '%s' "$receipt" | php -r '$r=json_decode(stream_get_contents(STDIN),true); echo $r["activation"]["expires_at_unix"]??"";')"
    binding="$(printf '%s' "$receipt" | extract_receipt release_binding)"
    scope="$(printf '%s' "$receipt" | php -r '$r=json_decode(stream_get_contents(STDIN),true); echo $r["aggregate"]["scope"]??"";')"
    [[ "$status" == 'passed' && "$binding" == "$RELEASE_BINDING" && "$hash" == "$CANDIDATE_SHA256" && "$starts" == "$STARTS_AT_UNIX" && "$expires" == "$EXPIRES_AT_UNIX" && "$scope" == 'cumulative_retention_window' ]] || return 1
    [[ "$expectation" == 'segment-expired' && "$(printf '%s' "$receipt" | php -r '$r=json_decode(stream_get_contents(STDIN),true); echo $r["activation"]["status"]??"";')" == 'expired' ]] ||
        [[ "$expectation" != 'segment-expired' && "$(printf '%s' "$receipt" | php -r '$r=json_decode(stream_get_contents(STDIN),true); echo $r["activation"]["status"]??"";')" == 'active' ]]
}

cleanup_after_start_failure() {
    local cause="$1" remove_output inactive_output
    REMOVAL_ATTEMPTED=1; CHECKPOINT='remove'
    if ! write_journal; then
        printf 'csp_segment.status=stopped\n'
        printf 'csp_segment.result_class=cleanup_journal_unavailable\n'
        return 1
    fi
    local remove_exit=0
    remove_output="$(remote_activation remove)" || remove_exit=$?
    if (( remove_exit != 0 )); then
        TERMINAL="${cause}_cleanup_$([[ "$remove_exit" -eq 3 ]] && printf 'failed' || printf 'unknown')"
        write_journal || true; printf '%s\n' "$remove_output"; printf 'csp_segment.result_class=%s\n' "$TERMINAL"; return 1
    fi
    if ! inactive_output="$(status_check inactive)"; then
        TERMINAL="${cause}_cleanup_unverified"; write_journal || true; printf 'csp_segment.result_class=%s\n' "$TERMINAL"; return 1
    fi
    TERMINAL="${cause}_cleanup_verified"; CHECKPOINT='remove'; write_journal || true
    printf '%s\n' "$remove_output"; printf '%s\n' "$inactive_output"; printf 'csp_segment.result_class=%s\n' "$TERMINAL"; return 1
}

run_preflight() {
    local output binding
    output="$(bash "$STATUS_SCRIPT" --phase preflight --prod-ssh-target "$PROD_SSH_TARGET")" || { printf '%s\n' "$output"; return 1; }
    binding="$(printf '%s\n' "$output" | sed -n 's/^csp_evidence\.release_binding=\([a-f0-9]\{64\}\)$/\1/p' | tail -n1)"
    [[ "$binding" =~ ^[a-f0-9]{64}$ ]] || return 1
    RELEASE_BINDING="$binding"
    printf '%s\n' "$output"
}

run_start() {
    [[ ! -e "$STATE_PATH" ]] || fail 'segment_journal_already_present' || return 1
    run_preflight || { printf 'csp_segment.result_class=preflight_failed\n'; return 1; }
    RUN_ID="$(php -r 'echo bin2hex(random_bytes(16));')"; CANDIDATE_SHA256=''; STARTS_AT_UNIX=''; EXPIRES_AT_UNIX=''; CHECKPOINT='activation'; TERMINAL=''; REMOVAL_ATTEMPTED=0
    JOURNAL_UPDATE=0 write_journal || { printf 'csp_segment.result_class=journal_write_failed\n'; return 1; }
    local receipt
    local install_exit=0
    receipt="$(remote_activation install-segment)" || install_exit=$?
    if (( install_exit != 0 )); then
        TERMINAL=$([[ "$install_exit" -eq 3 ]] && printf 'activation_failed' || printf 'activation_outcome_unknown')
        write_journal || true
        printf '%s\n' "$receipt"; printf 'csp_segment.result_class=%s\n' "$TERMINAL"; return 1
    fi
    local receipt_status receipt_binding
    receipt_status="$(printf '%s' "$receipt" | extract_receipt status)"; receipt_binding="$(printf '%s' "$receipt" | extract_receipt release_binding)"
    CANDIDATE_SHA256="$(printf '%s' "$receipt" | extract_receipt candidate_sha256)"; STARTS_AT_UNIX="$(printf '%s' "$receipt" | extract_receipt starts_at_unix)"; EXPIRES_AT_UNIX="$(printf '%s' "$receipt" | extract_receipt expires_at_unix)"
    [[ "$receipt_status" == 'passed' && "$receipt_binding" == "$RELEASE_BINDING" && "$CANDIDATE_SHA256" =~ ^[a-f0-9]{64}$ && "$STARTS_AT_UNIX" =~ ^[0-9]+$ && "$EXPIRES_AT_UNIX" =~ ^[0-9]+$ && $((EXPIRES_AT_UNIX-STARTS_AT_UNIX)) -eq "$DURATION_SECONDS" ]] || { TERMINAL='activation_receipt_binding_mismatch'; write_journal || true; printf 'csp_segment.result_class=%s\n' "$TERMINAL"; return 1; }
    CHECKPOINT='active'; write_journal || { printf '%s\n' "$receipt"; printf 'csp_segment.result_class=postinstall_journal_unavailable\n'; return 1; }
    output="$(bash "$STATUS_SCRIPT" --expect segment-active --probe-runtime --prod-ssh-target "$PROD_SSH_TARGET")" || { cleanup_after_start_failure 'active_probe_failed'; return 1; }
    if ! verify_segment_status segment-active "$output"; then
        cleanup_after_start_failure 'active_state_mismatch'; return 1
    fi
    printf '%s\n' "$output"; CHECKPOINT='observe'; write_journal || return 1
    printf 'csp_segment.aggregate_scope=cumulative_unattributed\n'
    printf 'csp_segment.status=passed\n'; printf 'csp_segment.result_class=segment_started\n'
}

run_recover() {
    read_journal || { printf 'csp_segment.result_class=journal_invalid\n'; return 1; }
    [[ "$STATE_TARGET" == "$(target_binding)" && "$CHECKPOINT" == 'activation' &&
       "$REMOVAL_ATTEMPTED" == 0 && -z "$CANDIDATE_SHA256" &&
       ( -z "$TERMINAL" || "$TERMINAL" == 'activation_outcome_unknown' || "$TERMINAL" == 'activation_failed' ) ]] || {
        printf 'csp_segment.result_class=recovery_journal_not_eligible\n'; return 1;
    }
    local receipt inspect_exit=0
    receipt="$(remote_activation inspect-segment)" || inspect_exit=$?
    if (( inspect_exit != 0 )); then
        printf '%s\n' "$receipt"
        printf 'csp_segment.result_class=%s\n' "$([[ "$inspect_exit" -eq 3 ]] && printf 'recovery_absent_or_mismatch' || printf 'recovery_unknown')"
        return 1
    fi
    CANDIDATE_SHA256="$(printf '%s' "$receipt" | extract_receipt candidate_sha256)"
    STARTS_AT_UNIX="$(printf '%s' "$receipt" | extract_receipt starts_at_unix)"
    EXPIRES_AT_UNIX="$(printf '%s' "$receipt" | extract_receipt expires_at_unix)"
    [[ "$CANDIDATE_SHA256" =~ ^[a-f0-9]{64}$ && "$STARTS_AT_UNIX" =~ ^[0-9]+$ &&
       "$EXPIRES_AT_UNIX" =~ ^[0-9]+$ && $((EXPIRES_AT_UNIX-STARTS_AT_UNIX)) -eq "$DURATION_SECONDS" ]] || {
        printf 'csp_segment.result_class=recovery_receipt_mismatch\n'; return 1;
    }
    local expectation='segment-observe' output
    [[ "$(date +%s)" -ge "$EXPIRES_AT_UNIX" ]] && expectation='segment-expired'
    output="$(status_check "$expectation")" || { printf 'csp_segment.result_class=recovery_state_unknown\n'; return 1; }
    verify_segment_status "$expectation" "$output" || { printf 'csp_segment.result_class=recovery_state_mismatch\n'; return 1; }
    CHECKPOINT='active'; TERMINAL=''
    write_journal || { printf '%s\n' "$receipt"; printf 'csp_segment.result_class=recovery_journal_unavailable\n'; return 1; }
    printf '%s\n' "$receipt"; printf '%s\n' "$output"
    printf 'csp_segment.aggregate_scope=cumulative_unattributed\n'
    printf 'csp_segment.status=passed\n'; printf 'csp_segment.result_class=segment_recovered\n'
}

run_observe() {
    read_journal || { printf 'csp_segment.result_class=journal_invalid\n'; return 1; }
    [[ -z "$TERMINAL" && ( "$CHECKPOINT" == 'active' || "$CHECKPOINT" == 'observe' ) ]] || { printf 'csp_segment.result_class=journal_not_observable\n'; return 1; }
    [[ "$STATE_TARGET" == "$(target_binding)" && -n "$CANDIDATE_SHA256" ]] || { printf 'csp_segment.result_class=journal_binding_mismatch\n'; return 1; }
    local expectation='segment-observe'; [[ "$(date +%s)" -ge "$EXPIRES_AT_UNIX" ]] && expectation='segment-expired'
    local output receipt status hash starts expires binding
    output="$(status_check "$expectation")" || { printf 'csp_segment.result_class=observe_unknown\n'; return 1; }
    verify_segment_status "$expectation" "$output" || { printf 'csp_segment.result_class=observe_binding_mismatch\n'; return 1; }
    printf '%s\n' "$output"; printf 'csp_segment.aggregate_scope=cumulative_unattributed\n'
    printf 'csp_segment.status=passed\n'; printf 'csp_segment.result_class=segment_functional_observed\n'
}

run_finish() {
    read_journal || { printf 'csp_segment.result_class=journal_invalid\n'; return 1; }
    [[ -z "$TERMINAL" && ( "$CHECKPOINT" == 'active' || "$CHECKPOINT" == 'observe' ) ]] || { printf 'csp_segment.result_class=journal_not_finishable\n'; return 1; }
    [[ "$STATE_TARGET" == "$(target_binding)" ]] || { printf 'csp_segment.result_class=journal_binding_mismatch\n'; return 1; }
    (( REMOVAL_ATTEMPTED == 0 )) || { printf 'csp_segment.result_class=removal_already_attempted\n'; return 1; }
    local expectation='segment-observe'; [[ "$(date +%s)" -ge "$EXPIRES_AT_UNIX" ]] && expectation='segment-expired'
    local state_output state_receipt state_status state_hash state_starts state_expires state_binding
    state_output="$(status_check "$expectation")" || { printf 'csp_segment.result_class=finish_state_unknown\n'; return 1; }
    state_receipt="$(printf '%s\n' "$state_output" | status_receipt)" || { printf 'csp_segment.result_class=finish_state_receipt_missing\n'; return 1; }
    state_status="$(printf '%s' "$state_receipt" | extract_receipt status)"; state_hash="$(printf '%s' "$state_receipt" | php -r '$r=json_decode(stream_get_contents(STDIN),true); echo $r["activation"]["sha256"]??"";')"; state_starts="$(printf '%s' "$state_receipt" | php -r '$r=json_decode(stream_get_contents(STDIN),true); echo $r["activation"]["starts_at_unix"]??"";')"; state_expires="$(printf '%s' "$state_receipt" | php -r '$r=json_decode(stream_get_contents(STDIN),true); echo $r["activation"]["expires_at_unix"]??"";')"; state_binding="$(printf '%s' "$state_receipt" | extract_receipt release_binding)"
    verify_segment_status "$expectation" "$state_output" || { printf 'csp_segment.result_class=finish_binding_mismatch\n'; return 1; }
    REMOVAL_ATTEMPTED=1; CHECKPOINT='remove'; write_journal || return 1
    local remove_output remove_class remove_exit=0
    remove_output="$(remote_activation remove)" || remove_exit=$?
    if (( remove_exit != 0 )); then
        TERMINAL=$([[ "$remove_exit" -eq 3 ]] && printf 'removal_failed' || printf 'removal_outcome_unknown')
        write_journal || true; printf '%s\n' "$remove_output"; printf 'csp_segment.result_class=%s\n' "$TERMINAL"; return 1
    fi
    remove_class="$(printf '%s' "$remove_output" | extract_receipt result_class)"
    if [[ "$remove_class" == 'activation_already_absent' ]]; then
        TERMINAL='activation_already_absent'; write_journal || true; printf '%s\n' "$remove_output"; printf 'csp_segment.result_class=%s\n' "$TERMINAL"; return 1
    fi
    [[ "$remove_class" == 'activation_removed' ]] || { TERMINAL='removal_receipt_unexpected'; write_journal || true; printf 'csp_segment.result_class=%s\n' "$TERMINAL"; return 1; }
    local output; output="$(status_check inactive)" || { TERMINAL='postflight_unknown'; write_journal || true; printf 'csp_segment.result_class=%s\n' "$TERMINAL"; return 1; }; printf '%s\n' "$remove_output"; printf '%s\n' "$output"
    CHECKPOINT='finished'; TERMINAL='finished'; write_journal || return 1
    printf 'csp_segment.status=passed\n'; printf 'csp_segment.result_class=segment_finished\n'
}

main() {
    parse_args "$@"
    if [[ "$PHASE" != 'preflight' ]]; then
        acquire_journal_lock || return 1
        trap release_journal_lock EXIT
    fi
    case "$PHASE" in
        preflight) run_preflight ;;
        start) run_start ;;
        recover) run_recover ;;
        observe) run_observe ;;
        finish) run_finish ;;
    esac
}

main "$@"
