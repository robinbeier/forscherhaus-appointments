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

usage() {
    cat <<'USAGE'
Usage:
  bash scripts/ops/prod_cleanup_inventory.sh [options]

Print a read-only, redacted production cleanup inventory.

The script aggregates cleanup-relevant release, backup, session, cache, log,
and disk facts. It never deletes files and does not print discovered filenames,
session data, backup contents, raw logs, DB rows, secrets, or host-local config
contents.

USAGE
    prod_usage_common
}

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --prod-ssh-target)
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
}

run_remote() {
    cat <<'REMOTE' | ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" 'bash -s'
set -euo pipefail

WEB_ROOT="${CLEANUP_WEB_ROOT:-/var/www/html}"
APP_ROOT="${CLEANUP_APP_ROOT:-${WEB_ROOT}/easyappointments}"
RELEASES_DIR="${CLEANUP_RELEASES_DIR:-/root/releases}"
BACKUP_DIR="${CLEANUP_BACKUP_DIR:-/root/backups/easyappointments}"
REBUILD_RESTORE_INPUTS_DIR="${CLEANUP_REBUILD_RESTORE_INPUTS_DIR:-/root/rebuild-restore-inputs}"
NOW_EPOCH="$(date +%s)"
SESSION_RETENTION_HELPER="${CLEANUP_SESSION_RETENTION_HELPER:-/usr/local/libexec/fh-session-retention-v1}"
SESSION_RETENTION_TIMER="${CLEANUP_SESSION_RETENTION_TIMER:-fh-session-retention.timer}"
RELEASE_RETENTION_HELPER="${CLEANUP_RELEASE_RETENTION_HELPER:-/usr/local/libexec/fh-release-archive-dump-retention-v1}"
RELEASE_RETENTION_TIMER="${CLEANUP_RELEASE_RETENTION_TIMER:-fh-release-archive-dump-retention.timer}"
RELEASE_RETENTION_SERVICE="${CLEANUP_RELEASE_RETENTION_SERVICE:-fh-release-archive-dump-retention.service}"
APP_LOG_RETENTION_HELPER="${CLEANUP_APP_LOG_RETENTION_HELPER:-/usr/local/libexec/fh-app-log-retention-v1}"
APP_LOG_RETENTION_TIMER="${CLEANUP_APP_LOG_RETENTION_TIMER:-fh-app-log-retention.timer}"

section() {
    printf '\n[%s]\n' "$1"
}

kv() {
    printf '%s=%s\n' "$1" "$2"
}

file_mtime_epoch() {
    local path="$1"

    stat -c '%Y' "$path" 2>/dev/null \
        || stat -f '%m' "$path" 2>/dev/null \
        || printf ''
}

age_days_for_path() {
    local path="$1"
    local mtime

    mtime="$(file_mtime_epoch "$path")"
    if ! [[ "$mtime" =~ ^[0-9]+$ ]]; then
        printf 'unknown'
        return
    fi

    printf '%s' "$(( (NOW_EPOCH - mtime) / 86400 ))"
}

count_class() {
    local count="$1"

    if (( count == 0 )); then
        printf '0'
    elif (( count <= 100 )); then
        printf '1-100'
    elif (( count <= 1000 )); then
        printf '101-1000'
    elif (( count <= 10000 )); then
        printf '1001-10000'
    else
        printf '10000+'
    fi
}

path_count() {
    local base="$1"
    local type="$2"
    local name_pattern="$3"
    local count=0
    local path

    [[ -d "$base" ]] || {
        printf '0'
        return
    }

    while IFS= read -r -d '' path; do
        count=$((count + 1))
    done < <(find "$base" -maxdepth 1 -type "$type" -name "$name_pattern" -print0 2>/dev/null || true)

    printf '%s' "$count"
}

tree_count() {
    local base="$1"
    local type="$2"
    local count=0
    local path

    [[ -d "$base" ]] || {
        printf '0'
        return
    }

    while IFS= read -r -d '' path; do
        count=$((count + 1))
    done < <(find "$base" -type "$type" -print0 2>/dev/null || true)

    printf '%s' "$count"
}

tree_count_name() {
    local base="$1"
    local type="$2"
    local name_pattern="$3"
    local count=0
    local path

    [[ -d "$base" ]] || {
        printf '0'
        return
    }

    while IFS= read -r -d '' path; do
        count=$((count + 1))
    done < <(find "$base" -type "$type" -name "$name_pattern" -print0 2>/dev/null || true)

    printf '%s' "$count"
}

path_total_mib() {
    local base="$1"
    local type="$2"
    local name_pattern="$3"
    local total_kib=0
    local size_kib
    local path

    [[ -d "$base" ]] || {
        printf '0'
        return
    }

    while IFS= read -r -d '' path; do
        size_kib="$(du -sk "$path" 2>/dev/null | awk '{print $1}' || true)"
        [[ "$size_kib" =~ ^[0-9]+$ ]] || size_kib=0
        total_kib=$((total_kib + size_kib))
    done < <(find "$base" -maxdepth 1 -type "$type" -name "$name_pattern" -print0 2>/dev/null || true)

    printf '%s' "$(( (total_kib + 1023) / 1024 ))"
}

tree_total_mib() {
    local base="$1"
    local size_kib

    [[ -e "$base" ]] || {
        printf '0'
        return
    }

    size_kib="$(du -sk "$base" 2>/dev/null | awk '{print $1}' || true)"
    [[ "$size_kib" =~ ^[0-9]+$ ]] || size_kib=0
    printf '%s' "$(( (size_kib + 1023) / 1024 ))"
}

age_stats_for_paths() {
    local base="$1"
    local type="$2"
    local name_pattern="$3"
    local count=0
    local newest=''
    local oldest=''
    local age
    local path

    [[ -d "$base" ]] || {
        printf 'count=0 newest_age_days=missing oldest_age_days=missing'
        return
    }

    while IFS= read -r -d '' path; do
        age="$(age_days_for_path "$path")"
        [[ "$age" =~ ^[0-9]+$ ]] || continue
        count=$((count + 1))
        if [[ -z "$newest" ]] || (( age < newest )); then
            newest="$age"
        fi
        if [[ -z "$oldest" ]] || (( age > oldest )); then
            oldest="$age"
        fi
    done < <(find "$base" -maxdepth 1 -type "$type" -name "$name_pattern" -print0 2>/dev/null || true)

    if (( count == 0 )); then
        printf 'count=0 newest_age_days=missing oldest_age_days=missing'
    else
        printf 'count=%s newest_age_days=%s oldest_age_days=%s' "$count" "$newest" "$oldest"
    fi
}

status_for_path() {
    local path="$1"

    if [[ -d "$path" ]]; then
        printf 'directory'
    elif [[ -e "$path" ]]; then
        printf 'present_not_directory'
    else
        printf 'missing'
    fi
}

systemctl_show_value() {
    local unit="$1"
    local property="$2"
    local value

    value="$(LC_ALL=C TZ=UTC systemctl show "$unit" --property="$property" --value 2>/dev/null)" || {
        printf unknown
        return
    }
    if [[ "$value" == "${property}="* ]]; then
        value="${value#*=}"
    fi
    if [[ "$value" =~ ^[^[:cntrl:]]+$ ]]; then
        printf '%s' "$value"
    else
        printf 'unknown'
    fi
}

systemctl_show_property() {
    local property="$1"
    local output="$2"
    local line
    local value=''

    while IFS= read -r line; do
        if [[ "$line" == "${property}="* ]]; then
            value="${line#*=}"
            break
        fi
    done <<< "$output"

    if [[ "$value" =~ ^[0-9]+$ ]]; then
        printf '%s' "$value"
    else
        printf 'unknown'
    fi
}

systemd_timestamp_epoch() {
    local timestamp="$1"
    local epoch=''

    [[ "$timestamp" != 'unknown' && "$timestamp" != 'n/a' && "$timestamp" != '' ]] || {
        printf 'unknown'
        return
    }
    epoch="$(date -d "$timestamp" +%s 2>/dev/null || true)"
    if ! [[ "$epoch" =~ ^[0-9]+$ ]]; then
        epoch="$(date -j -f '%Y-%m-%dT%H:%M:%SZ' "$timestamp" +%s 2>/dev/null || true)"
    fi
    if ! [[ "$epoch" =~ ^[0-9]+$ ]]; then
        epoch="$(date -j -f '%a %Y-%m-%d %H:%M:%S %Z' "$timestamp" +%s 2>/dev/null || true)"
    fi
    if ! [[ "$epoch" =~ ^[0-9]+$ ]]; then
        epoch="$(TZ=UTC date -j -f '%Y-%m-%d %H:%M:%S %Z' "$timestamp" +%s 2>/dev/null || true)"
    fi
    if [[ "$epoch" =~ ^[0-9]+$ ]]; then
        printf '%s' "$epoch"
    else
        printf 'unknown'
    fi
}

current_release_id() {
    if [[ -r "${APP_ROOT}/_RELEASE" ]]; then
        awk '{print $1; exit}' "${APP_ROOT}/_RELEASE" 2>/dev/null || printf 'unreadable'
    else
        printf 'missing'
    fi
}

candidate_for_prev_dirs() {
    local count="$1"

    if (( count == 0 )); then
        printf 'missing_rollback_directory'
    elif (( count == 1 )); then
        printf 'keep_current_rollback'
    else
        printf 'needs_review'
    fi
}

candidate_if_positive() {
    local count="$1"
    local positive_value="$2"

    if (( count > 0 )); then
        printf '%s' "$positive_value"
    else
        printf 'none'
    fi
}

storage_candidate() {
    local count="$1"
    local size_mib="$2"

    if (( count > 10000 || size_mib > 500 )); then
        printf 'needs_review'
    else
        printf 'observe'
    fi
}

section identity
kv captured_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
kv inventory_mode read_only
kv deletion_performed no
kv current_release "$(current_release_id)"

section resources
kv root_used_pct "$(df -P / | awk 'NR == 2 {gsub(/%/, "", $5); print $5}')"
kv root_avail_gib "$(df -BG / | awk 'NR == 2 {gsub(/G/, "", $4); print $4}')"

section release_dirs
kv app_root_status "$(status_for_path "$APP_ROOT")"
prev_dirs="$(path_count "$WEB_ROOT" d 'easyappointments_prev_*')"
stage_dirs="$(path_count "$WEB_ROOT" d 'easyappointments_*_stage')"
failed_dirs="$(path_count "$WEB_ROOT" d 'easyappointments_failed_*')"
named_variant_dirs="$(path_count "$WEB_ROOT" d 'easyappointments_*')"
kv release_dirs.prev.count "$prev_dirs"
kv release_dirs.prev.total_size_mib "$(path_total_mib "$WEB_ROOT" d 'easyappointments_prev_*')"
kv release_dirs.prev.age_summary "$(age_stats_for_paths "$WEB_ROOT" d 'easyappointments_prev_*')"
kv release_dirs.stage.count "$stage_dirs"
kv release_dirs.stage.total_size_mib "$(path_total_mib "$WEB_ROOT" d 'easyappointments_*_stage')"
kv release_dirs.failed.count "$failed_dirs"
kv release_dirs.failed.total_size_mib "$(path_total_mib "$WEB_ROOT" d 'easyappointments_failed_*')"
kv release_dirs.named_variant.count "$named_variant_dirs"

section release_archives
release_archive_count="$(path_count "$RELEASES_DIR" f '*.tar.gz')"
kv release_archives.count "$release_archive_count"
kv release_archives.total_size_mib "$(path_total_mib "$RELEASES_DIR" f '*.tar.gz')"
kv release_archives.age_summary "$(age_stats_for_paths "$RELEASES_DIR" f '*.tar.gz')"
kv release_archives.retention "$(candidate_if_positive "$release_archive_count" needs_decision)"

section backup_artifacts
backup_dump_count="$(tree_count_name "$BACKUP_DIR" f '*.sql.gz')"
backup_verify_marker="${BACKUP_DIR}/last_verify_success.utc"
backup_creation_marker="${BACKUP_DIR}/last_backup_success.utc"
kv backup_dir_status "$(status_for_path "$BACKUP_DIR")"
kv backup_dumps.count "$backup_dump_count"
kv backup_dir.total_size_mib "$(tree_total_mib "$BACKUP_DIR")"
kv backup_creation_marker.status "$([[ -r "$backup_creation_marker" ]] && printf readable || { [[ -e "$backup_creation_marker" ]] && printf present_not_readable || printf missing; })"
kv backup_creation_marker.age_days "$([[ -e "$backup_creation_marker" ]] && age_days_for_path "$backup_creation_marker" || printf missing)"
kv restore_verify_marker.status "$([[ -r "$backup_verify_marker" ]] && printf readable || { [[ -e "$backup_verify_marker" ]] && printf present_not_readable || printf missing; })"
kv restore_verify_marker.age_days "$([[ -e "$backup_verify_marker" ]] && age_days_for_path "$backup_verify_marker" || printf missing)"
dump_producer_admission_status=unavailable
if [[ -x "$RELEASE_RETENTION_HELPER" ]]; then
    dump_producer_admission_exit=0
    "$RELEASE_RETENTION_HELPER" admission-status >/dev/null 2>&1 || dump_producer_admission_exit=$?
    case "$dump_producer_admission_exit" in
        0) dump_producer_admission_status=pass ;;
        70) dump_producer_admission_status=blocked ;;
        75) dump_producer_admission_status=retryable ;;
        *) dump_producer_admission_status=invalid ;;
    esac
fi
kv dump_producer_admission.status "$dump_producer_admission_status"
kv dump_producer_admission.contract registry_manifest_required
kv backup_artifacts.retention "$(candidate_if_positive "$backup_dump_count" needs_decision)"

section restore_inputs
restore_input_file_count="$(tree_count "$REBUILD_RESTORE_INPUTS_DIR" f)"
kv restore_inputs.status "$(status_for_path "$REBUILD_RESTORE_INPUTS_DIR")"
kv restore_inputs.file_count_class "$(count_class "$restore_input_file_count")"
kv restore_inputs.total_size_mib "$(tree_total_mib "$REBUILD_RESTORE_INPUTS_DIR")"
kv restore_inputs.retention "$(candidate_if_positive "$restore_input_file_count" needs_decision)"

section app_storage
sessions_count="$(tree_count "${APP_ROOT}/storage/sessions" f)"
sessions_size_mib="$(tree_total_mib "${APP_ROOT}/storage/sessions")"
cache_count="$(tree_count "${APP_ROOT}/storage/cache" f)"
cache_size_mib="$(tree_total_mib "${APP_ROOT}/storage/cache")"
logs_count="$(tree_count "${APP_ROOT}/storage/logs" f)"
logs_size_mib="$(tree_total_mib "${APP_ROOT}/storage/logs")"
uploads_count="$(tree_count "${APP_ROOT}/storage/uploads" f)"
uploads_size_mib="$(tree_total_mib "${APP_ROOT}/storage/uploads")"
kv sessions.status "$(status_for_path "${APP_ROOT}/storage/sessions")"
kv sessions.file_count_class "$(count_class "$sessions_count")"
kv sessions.size_mib "$sessions_size_mib"
kv sessions.cleanup_candidate "$(storage_candidate "$sessions_count" "$sessions_size_mib")"
kv cache.status "$(status_for_path "${APP_ROOT}/storage/cache")"
kv cache.file_count_class "$(count_class "$cache_count")"
kv cache.size_mib "$cache_size_mib"
kv cache.cleanup_candidate "$(storage_candidate "$cache_count" "$cache_size_mib")"
kv logs.status "$(status_for_path "${APP_ROOT}/storage/logs")"
kv logs.file_count_class "$(count_class "$logs_count")"
kv logs.size_mib "$logs_size_mib"
kv logs.cleanup_candidate "$(storage_candidate "$logs_count" "$logs_size_mib")"
kv uploads.status "$(status_for_path "${APP_ROOT}/storage/uploads")"
kv uploads.file_count_class "$(count_class "$uploads_count")"
kv uploads.size_mib "$uploads_size_mib"
kv uploads.retention "$(candidate_if_positive "$uploads_count" needs_decision)"

section session_retention
if command -v systemctl >/dev/null 2>&1; then
    kv session_retention.timer_enabled "$(systemctl is-enabled "$SESSION_RETENTION_TIMER" 2>/dev/null || printf 'unknown')"
    kv session_retention.timer_active "$(systemctl is-active "$SESSION_RETENTION_TIMER" 2>/dev/null || printf 'unknown')"
else
    kv session_retention.timer_enabled unavailable
    kv session_retention.timer_active unavailable
fi
marker_status=unavailable
marker_age_seconds=unknown
if [[ -x "$SESSION_RETENTION_HELPER" ]]; then
    marker_json="$($SESSION_RETENTION_HELPER marker-status 129600 2>/dev/null || true)"
    parsed_marker="$(printf '%s' "$marker_json" | sed -n 's/^.*"age_seconds":\([^,}]*\).*"status":"\([a-z_]*\)".*$/\2|\1/p')"
    if [[ "$parsed_marker" == *'|'* ]]; then
        marker_status="${parsed_marker%%|*}"
        marker_age_seconds="${parsed_marker#*|}"
    fi
fi
kv session_retention.marker_status "$marker_status"
kv session_retention.marker_age_seconds "$marker_age_seconds"

section release_archive_dump_retention
if command -v systemctl >/dev/null 2>&1; then
    kv release_retention.timer_enabled "$(systemctl is-enabled "$RELEASE_RETENTION_TIMER" 2>/dev/null || printf 'unknown')"
    kv release_retention.timer_active "$(systemctl is-active "$RELEASE_RETENTION_TIMER" 2>/dev/null || printf 'unknown')"
else
    kv release_retention.timer_enabled unavailable
    kv release_retention.timer_active unavailable
fi
release_marker_status=unavailable
release_marker_age_seconds=unknown
if [[ -x "$RELEASE_RETENTION_HELPER" ]]; then
    release_marker_json="$($RELEASE_RETENTION_HELPER marker-status 691200 2>/dev/null || true)"
    parsed_release_marker="$(printf '%s' "$release_marker_json" | sed -n 's/^.*"age_seconds":\([^,}]*\).*"status":"\([a-z_]*\)".*$/\2|\1/p')"
    if [[ "$parsed_release_marker" == *'|'* ]]; then
        release_marker_status="${parsed_release_marker%%|*}"
        release_marker_age_seconds="${parsed_release_marker#*|}"
    fi
fi
kv release_retention.marker_status "$release_marker_status"
kv release_retention.marker_age_seconds "$release_marker_age_seconds"
release_last_exit_status=unknown
release_next_run_utc=unknown
release_helper_updated_since_last_run=unknown
if command -v systemctl >/dev/null 2>&1; then
    release_service_show="$(LC_ALL=C TZ=UTC systemctl show "$RELEASE_RETENTION_SERVICE" \
        --property=ExecMainStatus,ExecMainExitTimestamp 2>/dev/null)" || release_service_show=''
    release_last_exit_status="$(systemctl_show_property ExecMainStatus "$release_service_show")"
    release_exit_timestamp=''
    while IFS= read -r release_service_line; do
        case "$release_service_line" in
            ExecMainExitTimestamp=*) release_exit_timestamp="${release_service_line#*=}" ;;
        esac
    done <<< "$release_service_show"
    release_run_epoch="$(systemd_timestamp_epoch "$release_exit_timestamp")"
    if ! [[ "$release_run_epoch" =~ ^[0-9]+$ ]]; then
        release_last_exit_status=unknown
    fi
    release_helper_mtime="$(file_mtime_epoch "$RELEASE_RETENTION_HELPER")"
    if [[ "$release_run_epoch" =~ ^[0-9]+$ && "$release_helper_mtime" =~ ^[0-9]+$ ]]; then
        if (( release_helper_mtime > release_run_epoch )); then
            release_helper_updated_since_last_run=yes
        else
            release_helper_updated_since_last_run=no
        fi
    fi
    release_next_run_raw="$(systemctl_show_value "$RELEASE_RETENTION_TIMER" NextElapseUSecRealtime)"
    release_next_epoch="$(systemd_timestamp_epoch "$release_next_run_raw")"
    if [[ "$release_next_epoch" =~ ^[0-9]+$ ]]; then
        release_next_run_utc="$(date -u -d "@$release_next_epoch" +%Y-%m-%dT%H:%M:%SZ 2>/dev/null \
            || date -u -r "$release_next_epoch" +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || true)"
        [[ "$release_next_run_utc" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] || release_next_run_utc=unknown
    fi
fi
kv release_retention.last_exit_status "$release_last_exit_status"
kv release_retention.next_run_utc "$release_next_run_utc"
kv release_retention.helper_updated_since_last_run "$release_helper_updated_since_last_run"

section app_log_retention
if command -v systemctl >/dev/null 2>&1; then
    kv app_log_retention.timer_enabled "$(systemctl is-enabled "$APP_LOG_RETENTION_TIMER" 2>/dev/null || printf 'unknown')"
    kv app_log_retention.timer_active "$(systemctl is-active "$APP_LOG_RETENTION_TIMER" 2>/dev/null || printf 'unknown')"
else
    kv app_log_retention.timer_enabled unavailable
    kv app_log_retention.timer_active unavailable
fi
app_log_marker_status=unavailable
app_log_marker_age_seconds=unknown
if [[ -x "$APP_LOG_RETENTION_HELPER" ]]; then
    app_log_marker_json="$($APP_LOG_RETENTION_HELPER marker-status 129600 2>/dev/null || true)"
    parsed_app_log_marker="$(printf '%s' "$app_log_marker_json" | sed -n 's/^.*"age_seconds":\([^,}]*\).*"status":"\([a-z_]*\)".*$/\2|\1/p')"
    if [[ "$parsed_app_log_marker" == *'|'* ]]; then
        app_log_marker_status="${parsed_app_log_marker%%|*}"
        app_log_marker_age_seconds="${parsed_app_log_marker#*|}"
    fi
fi
kv app_log_retention.marker_status "$app_log_marker_status"
kv app_log_retention.marker_age_seconds "$app_log_marker_age_seconds"

section cleanup_candidates
kv cleanup_candidate.prev_release_dirs "$(candidate_for_prev_dirs "$prev_dirs")"
kv cleanup_candidate.stage_dirs "$(candidate_if_positive "$stage_dirs" safe_candidate)"
kv cleanup_candidate.failed_dirs "$(candidate_if_positive "$failed_dirs" safe_candidate)"
kv cleanup_candidate.release_archives "$(candidate_if_positive "$release_archive_count" needs_retention_decision)"
kv cleanup_candidate.backup_artifacts "$(candidate_if_positive "$backup_dump_count" needs_retention_decision)"
kv cleanup_candidate.restore_inputs "$(candidate_if_positive "$restore_input_file_count" needs_retention_decision)"
kv cleanup_requires_live_write_gate yes
REMOTE
}

main() {
    parse_args "$@"
    prod_require_cmd ssh
    prod_print_plan "prod-cleanup-inventory" "${PROD_SSH_TARGET}" "read-only"
    run_remote
}

main "$@"
