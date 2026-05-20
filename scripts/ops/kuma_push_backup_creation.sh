#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/ops/lib/kuma_push_common.sh
source "$SCRIPT_DIR/lib/kuma_push_common.sh"

kuma_push_load_env_file
kuma_push_require_env KUMA_PUSH_URL_BACKUP_CREATION

BACKUP_MARKER_FILE="${KUMA_BACKUP_CREATION_MARKER_FILE:-/root/backups/easyappointments/last_backup_success.utc}"
MAX_AGE_MINUTES="${KUMA_BACKUP_CREATION_MAX_AGE_MINUTES:-1440}"

if [[ ! -f "$BACKUP_MARKER_FILE" ]]; then
  msg="CRIT backup_marker_missing=$(basename "$BACKUP_MARKER_FILE")"
  kuma_push_send "$KUMA_PUSH_URL_BACKUP_CREATION" "down" "$msg" "0"
  kuma_push_log "$msg"
  exit 0
fi

marker="$(tr -d '\r\n' < "$BACKUP_MARKER_FILE")"
marker_epoch="$(date -u -d "$marker" +%s 2>/dev/null || true)"

if [[ -z "$marker_epoch" ]]; then
  msg="CRIT backup_marker_unparseable=$(basename "$BACKUP_MARKER_FILE")"
  kuma_push_send "$KUMA_PUSH_URL_BACKUP_CREATION" "down" "$msg" "0"
  kuma_push_log "$msg"
  exit 0
fi

now_epoch="$(date -u +%s)"
age_minutes="$(((now_epoch - marker_epoch) / 60))"

if ((age_minutes > MAX_AGE_MINUTES)); then
  msg="CRIT backup_age_minutes=${age_minutes} max=${MAX_AGE_MINUTES}"
  kuma_push_send "$KUMA_PUSH_URL_BACKUP_CREATION" "down" "$msg" "0"
else
  msg="OK backup_age_minutes=${age_minutes} max=${MAX_AGE_MINUTES}"
  kuma_push_send "$KUMA_PUSH_URL_BACKUP_CREATION" "up" "$msg" "1"
fi

kuma_push_log "$msg"
