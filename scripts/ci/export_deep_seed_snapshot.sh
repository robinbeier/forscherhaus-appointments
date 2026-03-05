#!/usr/bin/env bash
set -euo pipefail

output_path="${1:-}"

if [[ -z "$output_path" ]]; then
    echo "Usage: $0 OUTPUT_PATH" >&2
    exit 1
fi

mkdir -p "$(dirname "$output_path")"

docker compose exec -T mysql mysqldump \
    -uroot \
    -psecret \
    --databases easyappointments \
    --single-transaction \
    --quick \
    --lock-tables=false \
    | gzip -c >"$output_path"

if [[ ! -s "$output_path" ]]; then
    echo "::error::Deep seed snapshot export produced an empty archive." >&2
    exit 1
fi
