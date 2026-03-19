#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WITH_DEPS=0
DRY_RUN=0

usage() {
    cat <<'EOF'
Usage: bash ./scripts/cleanup_local_artifacts.sh [--with-deps] [--dry-run]

Removes conservative local artifacts:
- storage/logs contents (preserves .htaccess and index.html placeholders)
- build/
- .phpunit.cache/
- easyappointments-0.0.0.zip

Optional:
- --with-deps   also remove vendor/ and node_modules/
- --dry-run     print what would be removed without deleting anything

This script intentionally does not touch docker/mysql/.
EOF
}

log() {
    printf '[cleanup] %s\n' "$1"
}

path_size() {
    local path="$1"
    local size

    if [[ -e "$path" ]]; then
        if size="$(du -sh "$path" 2>/dev/null | awk '{print $1}')"; then
            printf '%s' "$size"
        else
            printf 'unknown'
        fi
    else
        printf '0B'
    fi
}

remove_path() {
    local path="$1"

    if [[ ! -e "$path" ]]; then
        log "skip $path (missing)"
        return
    fi

    local size
    size="$(path_size "$path")"

    if [[ "$DRY_RUN" -eq 1 ]]; then
        log "would remove $path ($size)"
        return
    fi

    rm -rf "$path"
    log "removed $path ($size)"
}

remove_storage_logs_contents() {
    local logs_dir="$ROOT_DIR/storage/logs"

    if [[ ! -d "$logs_dir" ]]; then
        log "skip $logs_dir (missing)"
        return
    fi

    local size
    size="$(path_size "$logs_dir")"

    if [[ "$DRY_RUN" -eq 1 ]]; then
        log "would remove contents of $logs_dir ($size, preserving placeholders)"
        return
    fi

    if find "$logs_dir" -depth -mindepth 1 ! -name '.htaccess' ! -name 'index.html' -exec rm -rf {} + 2>/dev/null; then
        log "removed contents of $logs_dir ($size, preserved placeholders)"
    else
        log "removed accessible contents of $logs_dir ($size, preserved placeholders; some entries may require manual cleanup)"
    fi
}

for arg in "$@"; do
    case "$arg" in
        --with-deps)
            WITH_DEPS=1
            ;;
        --dry-run)
            DRY_RUN=1
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            printf 'Unknown option: %s\n\n' "$arg" >&2
            usage >&2
            exit 1
            ;;
    esac
done

log "repo size before: $(path_size "$ROOT_DIR")"
remove_storage_logs_contents
remove_path "$ROOT_DIR/build"
remove_path "$ROOT_DIR/.phpunit.cache"
remove_path "$ROOT_DIR/easyappointments-0.0.0.zip"

if [[ "$WITH_DEPS" -eq 1 ]]; then
    remove_path "$ROOT_DIR/vendor"
    remove_path "$ROOT_DIR/node_modules"
else
    log "preserved $ROOT_DIR/vendor and $ROOT_DIR/node_modules (use --with-deps to remove)"
fi

log "preserved $ROOT_DIR/docker/mysql"
log "repo size after: $(path_size "$ROOT_DIR")"
