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
SOURCE_THRESHOLD="${KUMA_SECURITY_SCANNER_SOURCE_THRESHOLD:-5}"
LOG_GLOB="${KUMA_SECURITY_SCANNER_LOG_GLOB:-/var/log/apache2/*access.log}"

patterns='(wp-admin|wp-login|xmlrpc\.php|/\.env|/vendor/phpunit|/phpinfo|/config\.php|/server-status|/boaform|/HNAP1|/cgi-bin/)'

parse_apache_access_log_epoch() {
  local raw_timestamp="$1"

  if [[ "$raw_timestamp" =~ ^([0-9]{1,2})/([A-Za-z]{3})/([0-9]{4}):([0-9]{2}:[0-9]{2}:[0-9]{2})([[:space:]]+([+-][0-9]{4}))?$ ]]; then
    local timezone="${BASH_REMATCH[6]:-}"
    date -d "${BASH_REMATCH[1]} ${BASH_REMATCH[2]} ${BASH_REMATCH[3]} ${BASH_REMATCH[4]} ${timezone}" +%s 2>/dev/null
    return
  fi

  date -d "$raw_timestamp" +%s 2>/dev/null
}

now_epoch="$(date +%s)"
cutoff_epoch=$((now_epoch - WINDOW_MINUTES * 60))
count="0"
success_2xx="0"
redirect_3xx="0"
blocked_4xx="0"
other_status="0"
declare -A scanner_sources=()

shopt -s nullglob
for log_file in $LOG_GLOB; do
  [[ -r "$log_file" ]] || continue
  while IFS= read -r line; do
    [[ "$line" =~ $patterns ]] || continue
    timestamp="$(sed -n 's/.*\[\([^]]*\)\].*/\1/p' <<<"$line")"
    [[ -n "$timestamp" ]] || continue
    if event_epoch="$(parse_apache_access_log_epoch "$timestamp")"; then
      if (( event_epoch >= cutoff_epoch )); then
        count=$((count + 1))
        source_ip="${line%% *}"
        if [[ -n "$source_ip" && "$source_ip" != "$line" ]]; then
          scanner_sources["$source_ip"]=1
        fi

        status_code="$(sed -n 's/.*" \([0-9][0-9][0-9]\) .*/\1/p' <<<"$line")"
        if [[ "$status_code" =~ ^2[0-9][0-9]$ ]]; then
          success_2xx=$((success_2xx + 1))
        elif [[ "$status_code" =~ ^3[0-9][0-9]$ ]]; then
          redirect_3xx=$((redirect_3xx + 1))
        elif [[ "$status_code" =~ ^4[0-9][0-9]$ ]]; then
          blocked_4xx=$((blocked_4xx + 1))
        else
          other_status=$((other_status + 1))
        fi
      fi
    fi
  done < <(tail -n "${KUMA_SECURITY_SCANNER_TAIL_LINES:-2000}" "$log_file")
done
shopt -u nullglob

source_count="${#scanner_sources[@]}"
actionable="0"
if (( count > THRESHOLD )) && (( success_2xx > 0 || source_count >= SOURCE_THRESHOLD )); then
  actionable="1"
fi

metrics="scanner_activity=${count} window=${WINDOW_MINUTES}m threshold=${THRESHOLD} actionable=${actionable} success_2xx=${success_2xx} redirect_3xx=${redirect_3xx} blocked_4xx=${blocked_4xx} other_status=${other_status} sources=${source_count} source_threshold=${SOURCE_THRESHOLD}"

if [[ "$actionable" == "1" ]]; then
  msg="WARN ${metrics}"
  kuma_push_send "$KUMA_PUSH_URL_SECURITY_SCANNER" "down" "$msg" "0"
else
  msg="OK ${metrics}"
  kuma_push_send "$KUMA_PUSH_URL_SECURITY_SCANNER" "up" "$msg" "1"
fi

kuma_push_log "$msg"
