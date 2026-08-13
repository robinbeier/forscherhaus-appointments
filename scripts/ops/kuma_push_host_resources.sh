#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/ops/lib/kuma_push_common.sh
source "$SCRIPT_DIR/lib/kuma_push_common.sh"

kuma_push_load_env_file
kuma_push_require_env KUMA_PUSH_URL_HOST_RESOURCES

DISK_PATH="${KUMA_HOST_RESOURCES_DISK_PATH:-/}"
DISK_USED_WARN_PERCENT="${KUMA_HOST_RESOURCES_DISK_USED_WARN_PERCENT:-85}"
MEM_USED_WARN_PERCENT="${KUMA_HOST_RESOURCES_MEM_USED_WARN_PERCENT:-90}"
LOAD_WARN_PER_CORE="${KUMA_HOST_RESOURCES_LOAD_WARN_PER_CORE:-4}"
SESSION_RETENTION_MONITOR_ENABLED="${KUMA_SESSION_RETENTION_MONITOR_ENABLED:-0}"
SESSION_RETENTION_MARKER_MAX_AGE_SECONDS="${KUMA_SESSION_RETENTION_MARKER_MAX_AGE_SECONDS:-129600}"
SESSION_RETENTION_HELPER="${KUMA_SESSION_RETENTION_HELPER:-/usr/local/libexec/fh-session-retention-v1}"
RELEASE_RETENTION_MONITOR_ENABLED="${KUMA_RELEASE_RETENTION_MONITOR_ENABLED:-0}"
RELEASE_RETENTION_MARKER_MAX_AGE_SECONDS="${KUMA_RELEASE_RETENTION_MARKER_MAX_AGE_SECONDS:-691200}"
RELEASE_RETENTION_HELPER="${KUMA_RELEASE_RETENTION_HELPER:-/usr/local/libexec/fh-release-archive-dump-retention-v1}"
JOURNALD_RETENTION_MONITOR_ENABLED="${KUMA_JOURNALD_RETENTION_MONITOR_ENABLED:-0}"
JOURNALD_RETENTION_HELPER="${KUMA_JOURNALD_RETENTION_HELPER:-/usr/local/libexec/fh-journald-retention-v1}"
APP_LOG_RETENTION_MONITOR_ENABLED="${KUMA_APP_LOG_RETENTION_MONITOR_ENABLED:-0}"
APP_LOG_RETENTION_MARKER_MAX_AGE_SECONDS="${KUMA_APP_LOG_RETENTION_MARKER_MAX_AGE_SECONDS:-129600}"
APP_LOG_RETENTION_HELPER="${KUMA_APP_LOG_RETENTION_HELPER:-/usr/local/libexec/fh-app-log-retention-v1}"

if [[ "$SESSION_RETENTION_MONITOR_ENABLED" != '0' && "$SESSION_RETENTION_MONITOR_ENABLED" != '1' ]]; then
  kuma_push_die 'KUMA_SESSION_RETENTION_MONITOR_ENABLED must be 0 or 1'
fi
if ! [[ "$SESSION_RETENTION_MARKER_MAX_AGE_SECONDS" =~ ^[1-9][0-9]{0,6}$ ]]; then
  kuma_push_die 'KUMA_SESSION_RETENTION_MARKER_MAX_AGE_SECONDS is invalid'
fi
if [[ "$RELEASE_RETENTION_MONITOR_ENABLED" != '0' && "$RELEASE_RETENTION_MONITOR_ENABLED" != '1' ]]; then
  kuma_push_die 'KUMA_RELEASE_RETENTION_MONITOR_ENABLED must be 0 or 1'
fi
if ! [[ "$RELEASE_RETENTION_MARKER_MAX_AGE_SECONDS" =~ ^[1-9][0-9]{0,6}$ ]]; then
  kuma_push_die 'KUMA_RELEASE_RETENTION_MARKER_MAX_AGE_SECONDS is invalid'
fi
if [[ "$JOURNALD_RETENTION_MONITOR_ENABLED" != '0' && "$JOURNALD_RETENTION_MONITOR_ENABLED" != '1' ]]; then
  kuma_push_die 'KUMA_JOURNALD_RETENTION_MONITOR_ENABLED must be 0 or 1'
fi
if [[ "$APP_LOG_RETENTION_MONITOR_ENABLED" != '0' && "$APP_LOG_RETENTION_MONITOR_ENABLED" != '1' ]]; then
  kuma_push_die 'KUMA_APP_LOG_RETENTION_MONITOR_ENABLED must be 0 or 1'
fi
if ! [[ "$APP_LOG_RETENTION_MARKER_MAX_AGE_SECONDS" =~ ^[1-9][0-9]{0,6}$ ]]; then
  kuma_push_die 'KUMA_APP_LOG_RETENTION_MARKER_MAX_AGE_SECONDS is invalid'
fi

disk_used_percent="$(
  df -P "$DISK_PATH" | awk 'NR == 2 {gsub(/%/, "", $5); print $5}'
)"
mem_used_percent="$(
  awk '
    /^MemTotal:/ {total=$2}
    /^MemAvailable:/ {available=$2}
    END {
      if (total > 0) {
        printf "%.0f", ((total - available) / total) * 100
      } else {
        print 0
      }
    }
  ' /proc/meminfo
)"
load_1m="$(awk '{print $1}' /proc/loadavg)"
cores="$(getconf _NPROCESSORS_ONLN 2>/dev/null || printf '1')"
load_limit="$(awk -v cores="$cores" -v per_core="$LOAD_WARN_PER_CORE" 'BEGIN {printf "%.2f", cores * per_core}')"

status="up"
ping="1"
reasons=()

if (( disk_used_percent >= DISK_USED_WARN_PERCENT )); then
  status="down"
  ping="0"
  reasons+=("disk=${disk_used_percent}%")
fi

if [[ "$RELEASE_RETENTION_MONITOR_ENABLED" == '1' ]]; then
  release_marker_json=''
  release_marker_status='invalid'
  if [[ -x "$RELEASE_RETENTION_HELPER" ]] \
    && release_marker_json="$("$RELEASE_RETENTION_HELPER" marker-status "$RELEASE_RETENTION_MARKER_MAX_AGE_SECONDS" 2>/dev/null)"; then
    release_marker_status="$(printf '%s' "$release_marker_json" | sed -n 's/^.*"status":"\([a-z_]*\)".*$/\1/p')"
  fi
  case "$release_marker_status" in
    pass)
      ;;
    missing|stale|invalid)
      status="down"
      ping="0"
      reasons+=("release_retention=${release_marker_status}")
      ;;
    *)
      status="down"
      ping="0"
      reasons+=("release_retention=invalid")
      ;;
  esac
fi

if [[ "$JOURNALD_RETENTION_MONITOR_ENABLED" == '1' ]]; then
  journald_retention_json=''
  journald_retention_status='invalid'
  if [[ -x "$JOURNALD_RETENTION_HELPER" ]] \
    && journald_retention_json="$("$JOURNALD_RETENTION_HELPER" inspect 2>/dev/null)"; then
    journald_retention_status="$(printf '%s' "$journald_retention_json" | sed -n 's/^.*"status":"\([a-z_]*\)".*$/\1/p')"
  fi
  case "$journald_retention_status" in
    pass)
      ;;
    drift|invalid)
      status="down"
      ping="0"
      reasons+=("journald_retention=${journald_retention_status}")
      ;;
    *)
      status="down"
      ping="0"
      reasons+=("journald_retention=invalid")
      ;;
  esac
fi

if [[ "$APP_LOG_RETENTION_MONITOR_ENABLED" == '1' ]]; then
  app_log_marker_json=''
  app_log_marker_status='invalid'
  if [[ -x "$APP_LOG_RETENTION_HELPER" ]] \
    && app_log_marker_json="$("$APP_LOG_RETENTION_HELPER" marker-status "$APP_LOG_RETENTION_MARKER_MAX_AGE_SECONDS" 2>/dev/null)"; then
    app_log_marker_status="$(printf '%s' "$app_log_marker_json" | sed -n 's/^.*"status":"\([a-z_]*\)".*$/\1/p')"
  fi
  case "$app_log_marker_status" in
    pass)
      ;;
    missing|stale|invalid)
      status="down"
      ping="0"
      reasons+=("app_log_retention=${app_log_marker_status}")
      ;;
    *)
      status="down"
      ping="0"
      reasons+=("app_log_retention=invalid")
      ;;
  esac
fi

if (( mem_used_percent >= MEM_USED_WARN_PERCENT )); then
  status="down"
  ping="0"
  reasons+=("mem=${mem_used_percent}%")
fi

if awk -v load_value="$load_1m" -v limit="$load_limit" 'BEGIN {exit !(load_value >= limit)}'; then
  status="down"
  ping="0"
  reasons+=("load=${load_1m}")
fi

if [[ "$SESSION_RETENTION_MONITOR_ENABLED" == '1' ]]; then
  marker_json=''
  marker_status='invalid'
  if [[ -x "$SESSION_RETENTION_HELPER" ]] \
    && marker_json="$("$SESSION_RETENTION_HELPER" marker-status "$SESSION_RETENTION_MARKER_MAX_AGE_SECONDS" 2>/dev/null)"; then
    marker_status="$(printf '%s' "$marker_json" | sed -n 's/^.*"status":"\([a-z_]*\)".*$/\1/p')"
  fi
  case "$marker_status" in
    pass)
      ;;
    missing|stale|invalid)
      status="down"
      ping="0"
      reasons+=("session_retention=${marker_status}")
      ;;
    *)
      status="down"
      ping="0"
      reasons+=("session_retention=invalid")
      ;;
  esac
fi

if [[ "$status" == "down" ]]; then
  msg="CRIT resources $(IFS=,; printf '%s' "${reasons[*]}")"
else
  msg="OK disk=${disk_used_percent}% mem=${mem_used_percent}% load1=${load_1m}/${load_limit}"
fi

kuma_push_send "$KUMA_PUSH_URL_HOST_RESOURCES" "$status" "$msg" "$ping"
kuma_push_log "$msg"
