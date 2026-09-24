#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/lib/prod_common.sh"
SSH_OPTIONS=(-o StrictHostKeyChecking=accept-new)
PROD_SSH_TARGET="$(prod_default_ssh_target)"
EXPECTED_RELEASE=''; APP_ROOT='/var/www/html/easyappointments'
LOCK_PATH='/var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock'
RECOVERY_MARKER='/var/lib/fh-defense-ordinary/request-unconfirmed'
CLEANUP_TIMER='fh-defense-ordinary-cleanup.timer'; RETENTION_TIMER='fh-release-archive-dump-retention.timer'
BACKUP_TIMER='fh-backup-set-continuity.timer'; SESSION_TIMER='fh-session-retention.timer'
HELPERS=()
usage() { printf '%s\n' 'Usage: prod_release_readiness_preflight.sh --expected-active-release EA_ID [--prod-ssh-target root@booking-server]'; }
while (( $# > 0 )); do
    case "$1" in
        --expected-active-release) [[ $# -ge 2 ]] || exit 64; EXPECTED_RELEASE="$2"; shift 2 ;;
        --prod-ssh-target) [[ $# -ge 2 ]] || exit 64; PROD_SSH_TARGET="$2"; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) printf 'ERROR: unknown option: %s\n' "$1" >&2; exit 64 ;;
    esac
done
[[ "$EXPECTED_RELEASE" =~ ^ea_[A-Za-z0-9_]+$ ]] || { printf 'ERROR: expected release is invalid.\n' >&2; exit 64; }
[[ "$PROD_SSH_TARGET" == root@booking-server ]] || { printf 'ERROR: canonical production SSH target required.\n' >&2; exit 64; }
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd -P)"
prod_require_cmd git
local_sha256() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum -- "$1" | awk '{print $1}'
    elif command -v shasum >/dev/null 2>&1; then
        shasum -a 256 -- "$1" | awk '{print $1}'
    else
        return 1
    fi
}
for binding in \
    'deploy_ea.sh=/root/deploy_ea.sh' \
    'scripts/ops/libexec/backup_set_producer_v1.py=/usr/local/libexec/fh-backup-set-producer-v1' \
    'scripts/ops/libexec/deployment_dump_attestation_v1.py=/usr/local/libexec/fh/deployment_dump_attestation_v1.py'; do
    local_file="${binding%%=*}"; installed_file="${binding#*=}"
    [[ -f "$REPO_ROOT/$local_file" && ! -L "$REPO_ROOT/$local_file" ]] || { printf 'ERROR: reviewed local helper source unavailable.\n' >&2; exit 64; }
    git -C "$REPO_ROOT" ls-files --error-unmatch "$local_file" >/dev/null 2>&1 || { printf 'ERROR: local helper source untracked.\n' >&2; exit 64; }
    git -C "$REPO_ROOT" diff --quiet HEAD -- "$local_file" || { printf 'ERROR: local helper source modified.\n' >&2; exit 64; }
    local_hash="$(local_sha256 "$REPO_ROOT/$local_file")" || { printf 'ERROR: local helper hash unavailable.\n' >&2; exit 64; }
    [[ "$local_hash" =~ ^[a-f0-9]{64}$ ]] || { printf 'ERROR: local helper hash invalid.\n' >&2; exit 64; }
    HELPERS+=("$installed_file=$local_hash")
done
prod_require_cmd ssh
receipt_file="$(mktemp "${TMPDIR:-/tmp}/prod-release-readiness.XXXXXX")"
trap 'rm -f -- "$receipt_file"' EXIT
if ssh "${SSH_OPTIONS[@]}" "$PROD_SSH_TARGET" bash -s -- "$APP_ROOT" "$EXPECTED_RELEASE" "$LOCK_PATH" "$RECOVERY_MARKER" "$CLEANUP_TIMER" "$RETENTION_TIMER" "$BACKUP_TIMER" "$SESSION_TIMER" "${HELPERS[@]}" >"$receipt_file" 2>/dev/null <<'REMOTE'
set -u
APP_ROOT="$1"; EXPECTED_RELEASE="$2"; LOCK_PATH="$3"; RECOVERY_MARKER="$4"; CLEANUP_TIMER="$5"; RETENTION_TIMER="$6"; BACKUP_TIMER="$7"; SESSION_TIMER="$8"; shift 8
result() {
    printf 'schema=production_release_readiness.v1\nstatus=%s\nresult_class=%s\ncaptured_at_utc=%s\nsource_marker=app_root/_RELEASE\nsource_lock=shared_production_lock\nsource_timers=systemctl_show\nsource_tools=tracked_local_vs_installed_sha256\ninvalidation=first_mutation_or_identity_change\n' \
        "$1" "$2" "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
fail() { result failed "$1"; exit 20; }
[[ "$(id -u)" == 0 ]] || fail remote_not_root
[[ "$APP_ROOT" == /* && "$(realpath -e -- "$APP_ROOT" 2>/dev/null || true)" == "$APP_ROOT" && -d "$APP_ROOT" && ! -L "$APP_ROOT" ]] || fail app_root_invalid
cursor=''; IFS=/ read -ra parts <<< "${APP_ROOT#/}"
for part in "${parts[@]}"; do
    cursor="${cursor}/${part}"
    [[ -d "$cursor" && ! -L "$cursor" && "$(stat -c '%u:%g' -- "$cursor" 2>/dev/null || true)" == 0:0 ]] || fail app_root_identity_invalid
    mode="$(stat -c '%a' -- "$cursor" 2>/dev/null || true)"; [[ "$mode" =~ ^[0-7]+$ ]] && (( (8#$mode & 8#022) == 0 )) || fail app_root_identity_invalid
done
marker="$APP_ROOT/_RELEASE"
[[ -e "$marker" || -L "$marker" ]] || fail marker_missing
[[ -f "$marker" && ! -L "$marker" ]] || fail marker_identity_invalid
before="$(stat -c '%a:%u:%g:%h:%s:%d:%i' -- "$marker" 2>/dev/null || true)"
[[ "$before" =~ ^644:0:0:1:[1-9][0-9]*:[0-9]+:[0-9]+$ ]] || fail marker_identity_invalid
exec 3<"$marker" || fail marker_unreadable
opened="$(stat -Lc '%a:%u:%g:%h:%s:%d:%i' -- /proc/$$/fd/3 2>/dev/null || true)"
bytes="$(head -c 513 <&3 2>/dev/null || true)"
[[ "$opened" == "$before" ]] || fail marker_identity_changed
[[ ${#bytes} -le 512 ]] || fail marker_format_unknown
marker_size="$(stat -c '%s' -- "$marker" 2>/dev/null || true)"
content_size="${#bytes}"
(( marker_size == content_size + 1 && marker_size <= 512 )) || fail marker_format_unknown
last_byte="$(od -An -t x1 -j "$((marker_size - 1))" -N 1 -- /proc/$$/fd/3 2>/dev/null | tr -d ' \n')"
exec 3<&-
[[ "$last_byte" == 0a ]] || fail marker_format_unknown
after="$(stat -c '%a:%u:%g:%h:%s:%d:%i' -- "$marker" 2>/dev/null || true)"
[[ "$before" == "$after" ]] || fail marker_identity_changed
[[ "$bytes" =~ ^(ea_[A-Za-z0-9_]+)\ {2}([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2})Z$ ]] || fail marker_format_unknown
[[ "${BASH_REMATCH[1]}" == "$EXPECTED_RELEASE" ]] || fail marker_release_mismatch
timestamp="${BASH_REMATCH[2]}Z"; observed_epoch="$(date -u -d "$timestamp" +%s 2>/dev/null || true)"; now_epoch="$(date -u +%s)"
[[ "$observed_epoch" =~ ^[0-9]+$ ]] || fail marker_timestamp_invalid
(( observed_epoch <= now_epoch )) || fail marker_timestamp_future
[[ -e "$LOCK_PATH" || -L "$LOCK_PATH" ]] || fail lock_missing
[[ -f "$LOCK_PATH" && ! -L "$LOCK_PATH" ]] || fail lock_identity_invalid
lock_parent="$(dirname -- "$LOCK_PATH")"
[[ -d "$lock_parent" && ! -L "$lock_parent" && "$(stat -c '%a:%u:%g' -- "$lock_parent" 2>/dev/null || true)" == 700:0:0 ]] || fail lock_parent_identity_invalid
orchestrator_root="$(dirname -- "$lock_parent")"
[[ -d "$orchestrator_root" && ! -L "$orchestrator_root" && "$(stat -c '%a:%u:%g' -- "$orchestrator_root" 2>/dev/null || true)" == 700:0:0 ]] || fail lock_parent_identity_invalid
lock="$(stat -c '%a:%u:%g:%h:%s:%d:%i' -- "$LOCK_PATH" 2>/dev/null || true)"
[[ "$lock" =~ ^600:0:0:1:0:[0-9]+:[0-9]+$ ]] || fail lock_identity_invalid
recovery_root="$(dirname -- "$RECOVERY_MARKER")"
[[ -d "$recovery_root" && ! -L "$recovery_root" && "$(stat -c '%a:%u:%g' -- "$recovery_root" 2>/dev/null || true)" == 700:0:0 ]] || fail recovery_parent_identity_invalid
csp_lease="$orchestrator_root/csp-report-only-pilot.state.json"
if [[ -e "$RECOVERY_MARKER" || -L "$RECOVERY_MARKER" || -e "$csp_lease" || -L "$csp_lease" ]]; then fail recovery_pending; fi
for artifact in run.pending request-unconfirmed state.json defense-verification.json defense-verification.json.tmp sessions.json sessions.json.tmp; do
    [[ ! -e "$recovery_root/$artifact" && ! -L "$recovery_root/$artifact" ]] || fail recovery_pending
done
timer_state() {
    local unit="$1" expected="$2" observed line key value
    local load='' active='' sub='' file_state='' result_state=''
    observed="$(systemctl show "$unit" \
        --property=LoadState,ActiveState,SubState,UnitFileState,Result --no-pager 2>/dev/null)" || fail timer_unknown
    while IFS='=' read -r key value; do
        case "$key" in
            LoadState) load="$value" ;;
            ActiveState) active="$value" ;;
            SubState) sub="$value" ;;
            UnitFileState) file_state="$value" ;;
            Result) result_state="$value" ;;
        esac
    done <<< "$observed"
    case "$expected" in
        active)
            [[ "$load" == loaded && "$active" == active && "$sub" == waiting && "$file_state" == enabled && "$result_state" == success ]] || fail timer_not_active
            ;;
        inactive)
            [[ "$load" == loaded && "$active" == inactive && "$sub" == dead && "$file_state" == disabled && "$result_state" == success ]] || fail timer_unexpected
            ;;
        absent)
            [[ "$load" == not-found && "$active" == inactive && "$sub" == dead && "$file_state" == '' ]] || fail timer_unexpected
            ;;
    esac
}
timer_state "$BACKUP_TIMER" active
timer_state "$SESSION_TIMER" active
timer_state "$RETENTION_TIMER" inactive
timer_state "$CLEANUP_TIMER" absent
for spec in "$@"; do
    path="${spec%%=*}"; expected="${spec#*=}"
    [[ "$path" == /* && "$path" != "$spec" && "$expected" =~ ^[a-f0-9]{64}$ && -f "$path" && ! -L "$path" ]] || fail helper_spec_invalid
    helper_parent="$(dirname -- "$path")"
    [[ "$(realpath -e -- "$helper_parent" 2>/dev/null || true)" == "$helper_parent" ]] || fail helper_parent_identity_invalid
    cursor=''; IFS=/ read -ra parts <<< "${helper_parent#/}"
    for part in "${parts[@]}"; do
        cursor="${cursor}/${part}"
        [[ -d "$cursor" && ! -L "$cursor" && "$(stat -c '%u:%g' -- "$cursor" 2>/dev/null || true)" == 0:0 ]] || fail helper_parent_identity_invalid
        mode="$(stat -c '%a' -- "$cursor" 2>/dev/null || true)"
        [[ "$mode" =~ ^[0-7]+$ ]] && (( (8#$mode & 8#022) == 0 )) || fail helper_parent_identity_invalid
    done
    before_helper="$(stat -c '%a:%u:%g:%h:%s:%d:%i' -- "$path" 2>/dev/null || true)"
    [[ "$before_helper" =~ ^(555|700):0:0:1:[1-9][0-9]*:[0-9]+:[0-9]+$ ]] || fail helper_identity_invalid
    exec 4<"$path" || fail helper_unreadable
    opened_helper="$(stat -Lc '%a:%u:%g:%h:%s:%d:%i' -- /proc/$$/fd/4 2>/dev/null || true)"
    actual="$(sha256sum -- /proc/$$/fd/4 2>/dev/null | awk '{print $1}')"
    exec 4<&-
    [[ "$opened_helper" == "$before_helper" ]] || fail helper_identity_changed
    [[ "$actual" == "$expected" ]] || fail helper_hash_mismatch
    after_helper="$(stat -c '%a:%u:%g:%h:%s:%d:%i' -- "$path" 2>/dev/null || true)"; [[ "$before_helper" == "$after_helper" ]] || fail helper_identity_changed
done
result passed readiness_verified
REMOTE
then
    remote_rc=0
else
    remote_rc=$?
fi
remote_output="$(cat -- "$receipt_file")"
receipt=()
while IFS= read -r line; do
    receipt+=("$line")
done < "$receipt_file"
valid_receipt=1
[[ ${#receipt[@]} -eq 9 ]] || valid_receipt=0
if (( valid_receipt )); then
    [[ "${receipt[0]}" == 'schema=production_release_readiness.v1' ]] || valid_receipt=0
    [[ "${receipt[1]}" == 'status=passed' || "${receipt[1]}" == 'status=failed' ]] || valid_receipt=0
    case "${receipt[2]}" in
        result_class=readiness_verified|result_class=remote_not_root|result_class=app_root_invalid|result_class=app_root_identity_invalid|result_class=marker_missing|result_class=marker_identity_invalid|result_class=marker_unreadable|result_class=marker_identity_changed|result_class=marker_format_unknown|result_class=marker_release_mismatch|result_class=marker_timestamp_invalid|result_class=marker_timestamp_future|result_class=lock_missing|result_class=lock_parent_identity_invalid|result_class=lock_identity_invalid|result_class=recovery_parent_identity_invalid|result_class=recovery_pending|result_class=timer_unknown|result_class=timer_not_active|result_class=timer_unexpected|result_class=helper_spec_invalid|result_class=helper_parent_identity_invalid|result_class=helper_identity_invalid|result_class=helper_unreadable|result_class=helper_identity_changed|result_class=helper_hash_mismatch) ;;
        *) valid_receipt=0 ;;
    esac
    [[ "${receipt[3]}" =~ ^captured_at_utc=20[0-9]{2}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] || valid_receipt=0
    [[ "${receipt[4]}" == 'source_marker=app_root/_RELEASE' ]] || valid_receipt=0
    [[ "${receipt[5]}" == 'source_lock=shared_production_lock' ]] || valid_receipt=0
    [[ "${receipt[6]}" == 'source_timers=systemctl_show' ]] || valid_receipt=0
    [[ "${receipt[7]}" == 'source_tools=tracked_local_vs_installed_sha256' ]] || valid_receipt=0
    [[ "${receipt[8]}" == 'invalidation=first_mutation_or_identity_change' ]] || valid_receipt=0
fi
if (( valid_receipt )) && { { (( remote_rc == 0 )) && [[ "${receipt[1]}" == 'status=passed' && "${receipt[2]}" == 'result_class=readiness_verified' ]]; } || { (( remote_rc == 20 )) && [[ "${receipt[1]}" == 'status=failed' ]]; }; }; then
    printf '%s\n' "$remote_output"
    exit "$remote_rc"
fi
printf 'schema=production_release_readiness.v1\nstatus=failed\nresult_class=transport_or_receipt_unknown\ncaptured_at_utc=%s\nsource_marker=unverified\nsource_lock=unverified\nsource_timers=unverified\nsource_tools=unverified\ninvalidation=immediate\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
exit 20
