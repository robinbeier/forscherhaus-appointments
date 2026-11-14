#!/usr/bin/env bash
set -euo pipefail

image="${1:-${PHP_FPM_IMAGE:-}}"
[[ -n "$image" ]] || { printf 'Usage: %s IMAGE\n' "$0" >&2; exit 2; }

docker run --rm --network none --entrypoint node "$image" --version >/dev/null
docker run --rm --network none --entrypoint npm "$image" --version >/dev/null
docker run --rm --network none --entrypoint npx "$image" --version >/dev/null
printf 'php_fpm_node_runtime_smoke=passed image=%s\n' "$image"
