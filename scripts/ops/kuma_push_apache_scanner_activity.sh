#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/ops/lib/kuma_push_common.sh
source "$SCRIPT_DIR/lib/kuma_push_common.sh"

kuma_push_load_env_file
kuma_push_require_env KUMA_PUSH_URL_SECURITY_SCANNER

WINDOW_MINUTES="${KUMA_SECURITY_SCANNER_WINDOW_MINUTES:-5}"
THRESHOLD="${KUMA_SECURITY_SCANNER_THRESHOLD:-50}"
LOG_GLOB="${KUMA_SECURITY_SCANNER_LOG_GLOB:-/var/log/apache2/*access.log}"

patterns='(wp-admin|wp-login|xmlrpc\.php|/\.env|/vendor/phpunit|/phpinfo|/config\.php|/server-status|/boaform|/HNAP1|/cgi-bin/)'

now_epoch="$(date +%s)"
cutoff_epoch=$((now_epoch - WINDOW_MINUTES * 60))
count="0"

shopt -s nullglob
for log_file in $LOG_GLOB; do
  [[ -r "$log_file" ]] || continue
  while IFS= read -r line; do
    [[ "$line" =~ $patterns ]] || continue
    timestamp="$(sed -n 's/.*\[\([^]]*\)\].*/\1/p' <<<"$line" | awk '{print $1}')"
    [[ -n "$timestamp" ]] || continue
    if event_epoch="$(date -d "${timestamp/:/ }" +%s 2>/dev/null)"; then
      if (( event_epoch >= cutoff_epoch )); then
        count=$((count + 1))
      fi
    fi
  done < <(tail -n "${KUMA_SECURITY_SCANNER_TAIL_LINES:-2000}" "$log_file")
done
shopt -u nullglob

if (( count > THRESHOLD )); then
  msg="WARN scanner_activity=${count} window=${WINDOW_MINUTES}m threshold=${THRESHOLD}"
  kuma_push_send "$KUMA_PUSH_URL_SECURITY_SCANNER" "down" "$msg" "0"
else
  msg="OK scanner_activity=${count} window=${WINDOW_MINUTES}m threshold=${THRESHOLD}"
  kuma_push_send "$KUMA_PUSH_URL_SECURITY_SCANNER" "up" "$msg" "1"
fi

kuma_push_log "$msg"
