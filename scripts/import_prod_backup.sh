#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'USAGE'
Usage:
  bash ./scripts/import_prod_backup.sh --help

The production-dump importer is suspended. This entrypoint intentionally has
no SSH, SCP, Docker, database, or filesystem import implementation. A future
replacement must execute from an independently trusted immutable runtime with
network isolation and an explicitly authorized or anonymized data path.
USAGE
}

if [[ "${1:-}" == '-h' || "${1:-}" == '--help' ]]; then
    usage
    exit 0
fi

printf '[import-prod-backup] ERROR: Production dump import is suspended; no external or local side effect was attempted.\n' >&2
exit 1
