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

if (( mem_used_percent >= MEM_USED_WARN_PERCENT )); then
  status="down"
  ping="0"
  reasons+=("mem=${mem_used_percent}%")
fi

if awk -v load="$load_1m" -v limit="$load_limit" 'BEGIN {exit !(load >= limit)}'; then
  status="down"
  ping="0"
  reasons+=("load=${load_1m}")
fi

if [[ "$status" == "down" ]]; then
  msg="CRIT resources $(IFS=,; printf '%s' "${reasons[*]}")"
else
  msg="OK disk=${disk_used_percent}% mem=${mem_used_percent}% load1=${load_1m}/${load_limit}"
fi

kuma_push_send "$KUMA_PUSH_URL_HOST_RESOURCES" "$status" "$msg" "$ping"
kuma_push_log "$msg"
