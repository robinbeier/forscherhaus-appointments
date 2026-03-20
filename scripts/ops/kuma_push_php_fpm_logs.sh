#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/ops/lib/kuma_push_common.sh
source "$SCRIPT_DIR/lib/kuma_push_common.sh"

kuma_push_load_env_file

WINDOW_MINUTES="${KUMA_PHP_FPM_LOG_WINDOW_MINUTES:-5}"
SERVICE_NAME="${KUMA_PHP_FPM_SERVICE_NAME:-php8.3-fpm}"
THRESHOLD="${KUMA_PHP_FPM_ERROR_THRESHOLD:-0}"

kuma_push_require_env KUMA_PUSH_URL_PHP_FPM_LOGS

entries="$(journalctl -u "$SERVICE_NAME" --since "-${WINDOW_MINUTES} min" -p err..alert --no-pager -o cat || true)"
count="$(printf '%s\n' "$entries" | sed '/^$/d' | wc -l | tr -d ' ')"
count="${count:-0}"

if (( count > THRESHOLD )); then
  latest="$(printf '%s\n' "$entries" | tail -n 1)"
  latest="$(kuma_push_trim "$latest" 180)"
  msg="CRIT php_fpm_errors=${count} window=${WINDOW_MINUTES}m latest=${latest}"
  kuma_push_send "$KUMA_PUSH_URL_PHP_FPM_LOGS" "down" "$msg" "0"
else
  msg="OK php_fpm_errors=${count} window=${WINDOW_MINUTES}m"
  kuma_push_send "$KUMA_PUSH_URL_PHP_FPM_LOGS" "up" "$msg" "1"
fi

kuma_push_log "$msg"
