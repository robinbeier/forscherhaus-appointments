#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/ops/lib/kuma_push_common.sh
source "$SCRIPT_DIR/lib/kuma_push_common.sh"

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

mkdir -p "$STATE_DIR"

if command -v flock >/dev/null 2>&1; then
  exec 9>"$LOCK_FILE"
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
  msg="OK primed app log monitor at $(basename "$LOG_FILE") size=${current_size}"
  kuma_push_send "$KUMA_PUSH_URL_APP_LOGS" "up" "$msg" "1"
  kuma_push_log "$msg"
  exit 0
fi

IFS='|' read -r previous_file previous_inode previous_offset < "$STATE_FILE" || true
previous_offset="${previous_offset:-0}"

tmp_delta="$(mktemp)"
cleanup() {
  rm -f "$tmp_delta"
}
trap cleanup EXIT

if [[ "$previous_file" == "$LOG_FILE" && "$previous_inode" == "$current_inode" && "$current_size" -ge "$previous_offset" ]]; then
  if (( previous_offset < current_size )); then
    tail -c "+$((previous_offset + 1))" "$LOG_FILE" > "$tmp_delta"
  else
    : > "$tmp_delta"
  fi
else
  cp "$LOG_FILE" "$tmp_delta"
fi

if [[ -n "$IGNORE_REGEX" ]]; then
  grep -Ev "$IGNORE_REGEX" "$tmp_delta" > "${tmp_delta}.filtered" || true
  mv "${tmp_delta}.filtered" "$tmp_delta"
fi

printf '%s|%s|%s\n' "$LOG_FILE" "$current_inode" "$current_size" > "$STATE_FILE"

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
