#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/ops/lib/kuma_push_common.sh
source "$SCRIPT_DIR/lib/kuma_push_common.sh"

kuma_push_load_env_file
kuma_push_require_env KUMA_PUSH_URL_HOST_SERVICES

SERVICES="${KUMA_HOST_SERVICES_LIST:-apache2 php8.5-fpm mariadb docker fh-pdf-renderer}"
failed=()

for service in $SERVICES; do
  if ! systemctl is-active --quiet "$service"; then
    failed+=("$service")
  fi
done

if (( ${#failed[@]} > 0 )); then
  msg="CRIT inactive_services=$(IFS=,; printf '%s' "${failed[*]}")"
  kuma_push_send "$KUMA_PUSH_URL_HOST_SERVICES" "down" "$msg" "0"
else
  msg="OK services_active=$(wc -w <<<"$SERVICES" | tr -d ' ')"
  kuma_push_send "$KUMA_PUSH_URL_HOST_SERVICES" "up" "$msg" "1"
fi

kuma_push_log "$msg"
