#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/ops/lib/kuma_push_common.sh
source "$SCRIPT_DIR/lib/kuma_push_common.sh"

kuma_push_load_env_file
kuma_push_require_env KUMA_PUSH_URL_OPS_JOBS

VERIFY_FILE="${KUMA_OPS_JOBS_VERIFY_FILE:-/root/backups/easyappointments/last_verify_success.utc}"
MAX_AGE_MINUTES="${KUMA_OPS_JOBS_MAX_VERIFY_AGE_MINUTES:-1440}"

if [[ ! -f "$VERIFY_FILE" ]]; then
  msg="CRIT restore_verify_marker_missing=$(basename "$VERIFY_FILE")"
  kuma_push_send "$KUMA_PUSH_URL_OPS_JOBS" "down" "$msg" "0"
  kuma_push_log "$msg"
  exit 0
fi

marker="$(tr -d '\r\n' < "$VERIFY_FILE")"
marker_epoch="$(date -u -d "$marker" +%s 2>/dev/null || true)"

if [[ -z "$marker_epoch" ]]; then
  msg="CRIT restore_verify_marker_unparseable=$(basename "$VERIFY_FILE")"
  kuma_push_send "$KUMA_PUSH_URL_OPS_JOBS" "down" "$msg" "0"
  kuma_push_log "$msg"
  exit 0
fi

now_epoch="$(date -u +%s)"
age_minutes="$(( (now_epoch - marker_epoch) / 60 ))"

if (( age_minutes > MAX_AGE_MINUTES )); then
  msg="CRIT restore_verify_age_minutes=${age_minutes} max=${MAX_AGE_MINUTES}"
  kuma_push_send "$KUMA_PUSH_URL_OPS_JOBS" "down" "$msg" "0"
else
  msg="OK restore_verify_age_minutes=${age_minutes} max=${MAX_AGE_MINUTES}"
  kuma_push_send "$KUMA_PUSH_URL_OPS_JOBS" "up" "$msg" "1"
fi

kuma_push_log "$msg"
