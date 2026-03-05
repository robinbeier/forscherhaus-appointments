#!/usr/bin/env bash
set -euo pipefail

snapshot_path="${1:-}"

if [[ -z "$snapshot_path" ]]; then
    echo "Usage: $0 SNAPSHOT_PATH" >&2
    exit 1
fi

if [[ ! -f "$snapshot_path" ]]; then
    echo "::error::Deep seed snapshot not found: $snapshot_path" >&2
    exit 1
fi

gunzip -c "$snapshot_path" | docker compose exec -T mysql mysql -uroot -psecret

docker compose exec -T mysql mysql -uuser -ppassword -e "USE easyappointments; SELECT COUNT(*) FROM ea_settings;" >/dev/null 2>&1
