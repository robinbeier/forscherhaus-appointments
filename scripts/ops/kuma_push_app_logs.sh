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
LOG_FILE="${KUMA_APP_LOG_FILE:-${APP_ROOT}/storage/logs/log-$(date -u +%F).php}"
STATE_DIR="${KUMA_PUSH_STATE_DIR:-/var/tmp/kuma-push-state}"
STATE_FILE="${STATE_DIR}/app-logs.state"
LOCK_FILE="${KUMA_APP_LOG_LOCK_FILE:-${STATE_DIR}/app-logs.lock}"
PATTERN="${KUMA_APP_LOG_PATTERN:-ERROR - }"
IGNORE_REGEX="${KUMA_APP_LOG_IGNORE_REGEX:-}"
THRESHOLD="${KUMA_APP_LOG_ERROR_THRESHOLD:-0}"

kuma_push_sha256_file_prefix() {
  local path="$1"
  local byte_count="$2"

  if command -v sha256sum >/dev/null 2>&1; then
    if (( byte_count == 0 )); then
      printf '' | sha256sum | awk '{print $1}'
    else
      head -c "$byte_count" "$path" | sha256sum | awk '{print $1}'
    fi
  elif command -v shasum >/dev/null 2>&1; then
    if (( byte_count == 0 )); then
      printf '' | shasum -a 256 | awk '{print $1}'
    else
      head -c "$byte_count" "$path" | shasum -a 256 | awk '{print $1}'
    fi
  else
    return 1
  fi
}

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
    if ! (set -o noclobber; : > "$LOCK_FILE"); then
      [[ -e "$LOCK_FILE" && ! -L "$LOCK_FILE" ]] || kuma_push_die "Unable to create private lock file"
    fi
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

if [[ -f "$STATE_FILE" ]]; then
  IFS='|' read -r previous_file previous_inode previous_offset < "$STATE_FILE" || true
  read -r previous_prefix_sha256 < <(sed -n '2p' "$STATE_FILE") || true
fi
previous_offset="${previous_offset:-0}"

tmp_dir="$(mktemp -d "$STATE_DIR/delta.XXXXXX")" || kuma_push_die "Unable to create private app-log workspace"
tmp_delta="$tmp_dir/delta"
tmp_filtered="$tmp_dir/filtered"
tmp_snapshot="$tmp_dir/snapshot"
cleanup() {
  rm -f "$tmp_delta" "$tmp_filtered" "$tmp_snapshot"
  rmdir "$tmp_dir" 2>/dev/null || true
}
trap cleanup EXIT
(umask 077; : > "$tmp_delta"; : > "$tmp_filtered"; : > "$tmp_snapshot") || kuma_push_die "Unable to create private app-log workspace files"

snapshot_inode="$current_inode"
if (( current_size == 0 )); then
  : > "$tmp_snapshot"
else
  head -c "$current_size" "$LOG_FILE" > "$tmp_snapshot"
fi
snapshot_size="$(kuma_push_stat_size "$tmp_snapshot")"
[[ "$snapshot_size" -eq "$current_size" ]] || kuma_push_die "App log snapshot was incomplete"
read_inode="$(kuma_push_stat_dev_inode "$LOG_FILE")"
read_size="$(kuma_push_stat_size "$LOG_FILE")"
[[ "$read_inode" == "$snapshot_inode" && "$read_size" -ge "$current_size" ]] || kuma_push_die "App log changed while snapshotting"
prefix_sha256="$(kuma_push_sha256_file_prefix "$tmp_snapshot" "$current_size")" || kuma_push_die "Unable to hash app log prefix"

if [[ ! -f "$STATE_FILE" ]]; then
  msg="OK primed app log monitor at $(basename "$LOG_FILE") size=${current_size}"
  kuma_push_send "$KUMA_PUSH_URL_APP_LOGS" "up" "$msg" "1"
  printf '%s|%s|%s\n%s\n' "$LOG_FILE" "$current_inode" "$current_size" "$prefix_sha256" > "$STATE_FILE"
  kuma_push_validate_private_file "$STATE_FILE" || kuma_push_die "Unsafe private state file"
  kuma_push_log "$msg"
  exit 0
fi

can_resume=0
if [[ "$previous_file" == "$LOG_FILE" && "$current_size" -ge "$previous_offset" ]]; then
  if [[ "$previous_inode" == "$current_inode" ]]; then
    can_resume=1
  elif [[ "$previous_prefix_sha256" =~ ^[a-f0-9]{64}$ ]]; then
    current_prefix_sha256="$(kuma_push_sha256_file_prefix "$tmp_snapshot" "$previous_offset")" || kuma_push_die "Unable to hash app log prefix"
    [[ "$current_prefix_sha256" == "$previous_prefix_sha256" ]] && can_resume=1
  fi
fi

if [[ "$can_resume" -eq 1 ]]; then
  if (( previous_offset < current_size )); then
    tail -c "+$((previous_offset + 1))" "$tmp_snapshot" > "$tmp_delta"
  else
    : > "$tmp_delta"
  fi
else
  cp "$tmp_snapshot" "$tmp_delta"
fi

delta_size="$(kuma_push_stat_size "$tmp_delta")"
expected_delta_size=$((can_resume == 1 ? current_size - previous_offset : current_size))
[[ "$delta_size" -eq "$expected_delta_size" ]] || kuma_push_die "App log read was incomplete"

app_log_filter_actionable_file "$tmp_delta" "$tmp_filtered" "$IGNORE_REGEX"
mv "$tmp_filtered" "$tmp_delta"

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

printf '%s|%s|%s\n%s\n' "$LOG_FILE" "$current_inode" "$current_size" "$prefix_sha256" > "$STATE_FILE"
kuma_push_validate_private_file "$STATE_FILE" || kuma_push_die "Unsafe private state file"
kuma_push_log "$msg"
