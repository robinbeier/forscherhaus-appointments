#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/ops/lib/kuma_push_common.sh
source "$SCRIPT_DIR/lib/kuma_push_common.sh"
# shellcheck source=scripts/ops/lib/app_log_classification.sh
source "$SCRIPT_DIR/lib/app_log_classification.sh"

kuma_push_load_env_file

APP_ROOT="${KUMA_APP_ROOT:-/var/www/html/easyappointments}"
LOG_FILE="${KUMA_APP_LOG_FILE:-${APP_ROOT}/storage/logs/log-$(date +%F).php}"
STATE_DIR="${KUMA_PUSH_STATE_DIR:-/var/tmp/kuma-push-state}"
STATE_FILE="${STATE_DIR}/app-logs.state"
LOCK_FILE="${KUMA_APP_LOG_LOCK_FILE:-${STATE_DIR}/app-logs.lock}"
PATTERN="${KUMA_APP_LOG_PATTERN:-ERROR - }"
IGNORE_REGEX="${KUMA_APP_LOG_IGNORE_REGEX:-}"
THRESHOLD="${KUMA_APP_LOG_ERROR_THRESHOLD:-0}"

kuma_push_require_env KUMA_PUSH_URL_APP_LOGS

STATE_DIR="$(kuma_push_prepare_private_directory "$STATE_DIR")" || kuma_push_die "Unsafe private state directory"
STATE_FILE="${STATE_DIR}/app-logs.state"
if [[ -n "${KUMA_APP_LOG_LOCK_FILE:-}" ]]; then
  lock_parent="$(dirname -- "$KUMA_APP_LOG_LOCK_FILE")"
  lock_leaf="$(basename -- "$KUMA_APP_LOG_LOCK_FILE")"
  [[ "$lock_leaf" != . && "$lock_leaf" != .. && -n "$lock_leaf" ]] || kuma_push_die "Unsafe private lock file path"
  lock_parent="$(kuma_push_prepare_private_directory "$lock_parent")" || kuma_push_die "Unsafe private lock directory"
  LOCK_FILE="${lock_parent}/${lock_leaf}"
else
  LOCK_FILE="${STATE_DIR}/app-logs.lock"
fi
kuma_push_validate_private_file "$STATE_FILE" || kuma_push_die "Unsafe private state file"
kuma_push_validate_private_file "$LOCK_FILE" || kuma_push_die "Unsafe private lock file"

if command -v flock >/dev/null 2>&1; then
  if [[ ! -e "$LOCK_FILE" ]]; then
    (set -o noclobber; : > "$LOCK_FILE") || kuma_push_die "Unable to create private lock file"
  fi
  kuma_push_validate_private_file "$LOCK_FILE" || kuma_push_die "Unsafe private lock file"
  exec 9>>"$LOCK_FILE"
  if ! flock -n 9; then
    msg="OK app log monitor already running: $(basename "$LOCK_FILE")"
    kuma_push_log "$msg"
    exit 0
  fi
fi

if [[ ! -f "$LOG_FILE" ]]; then
  msg="OK app log not present yet: $(basename "$LOG_FILE")"
  kuma_push_send "$KUMA_PUSH_URL_APP_LOGS" "up" "$msg" "1"
  kuma_push_log "$msg"
  exit 0
fi

current_inode="$(kuma_push_stat_dev_inode "$LOG_FILE")"
current_size="$(kuma_push_stat_size "$LOG_FILE")"

if [[ ! -f "$STATE_FILE" ]]; then
  printf '%s|%s|%s\n' "$LOG_FILE" "$current_inode" "$current_size" > "$STATE_FILE"
  kuma_push_validate_private_file "$STATE_FILE" || kuma_push_die "Unsafe private state file"
  msg="OK primed app log monitor at $(basename "$LOG_FILE") size=${current_size}"
  kuma_push_send "$KUMA_PUSH_URL_APP_LOGS" "up" "$msg" "1"
  kuma_push_log "$msg"
  exit 0
fi

IFS='|' read -r previous_file previous_inode previous_offset < "$STATE_FILE" || true
previous_offset="${previous_offset:-0}"

tmp_dir="$(mktemp -d "$STATE_DIR/delta.XXXXXX")" || kuma_push_die "Unable to create private app-log workspace"
tmp_delta="$tmp_dir/delta"
tmp_filtered="$tmp_dir/filtered"
cleanup() {
  rm -f "$tmp_delta" "$tmp_filtered"
  rmdir "$tmp_dir" 2>/dev/null || true
}
trap cleanup EXIT
(umask 077; : > "$tmp_delta"; : > "$tmp_filtered") || kuma_push_die "Unable to create private app-log workspace files"

if [[ "$previous_file" == "$LOG_FILE" && "$previous_inode" == "$current_inode" && "$current_size" -ge "$previous_offset" ]]; then
  if (( previous_offset < current_size )); then
    tail -c "+$((previous_offset + 1))" "$LOG_FILE" > "$tmp_delta"
  else
    : > "$tmp_delta"
  fi
else
  cp "$LOG_FILE" "$tmp_delta"
fi

app_log_filter_actionable_file "$tmp_delta" "$tmp_filtered" "$IGNORE_REGEX"
mv "$tmp_filtered" "$tmp_delta"

printf '%s|%s|%s\n' "$LOG_FILE" "$current_inode" "$current_size" > "$STATE_FILE"
kuma_push_validate_private_file "$STATE_FILE" || kuma_push_die "Unsafe private state file"

new_errors="$(grep -cF "$PATTERN" "$tmp_delta" || true)"
new_errors="${new_errors:-0}"

if (( new_errors > THRESHOLD )); then
  latest_line="$(grep -F "$PATTERN" "$tmp_delta" | tail -n 1 || true)"
  latest_line="$(kuma_push_trim "$latest_line" 180)"
  msg="CRIT new_app_errors=${new_errors} file=$(basename "$LOG_FILE") latest=${latest_line}"
  kuma_push_send "$KUMA_PUSH_URL_APP_LOGS" "down" "$msg" "0"
else
  msg="OK new_app_errors=${new_errors} file=$(basename "$LOG_FILE")"
  kuma_push_send "$KUMA_PUSH_URL_APP_LOGS" "up" "$msg" "1"
fi

kuma_push_log "$msg"
