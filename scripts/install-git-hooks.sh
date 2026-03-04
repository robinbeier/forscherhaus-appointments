#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(git rev-parse --show-toplevel)"
HOOKS_DIR="$ROOT_DIR/.git/hooks"
SOURCE_PRE_PUSH="$ROOT_DIR/scripts/hooks/pre-push"
TARGET_PRE_PUSH="$HOOKS_DIR/pre-push"
MARKER="managed-by-forscherhaus-prepush"

mkdir -p "$HOOKS_DIR"

if [[ ! -f "$SOURCE_PRE_PUSH" ]]; then
    echo "[hooks] Missing source hook: $SOURCE_PRE_PUSH" >&2
    exit 1
fi

if [[ -f "$TARGET_PRE_PUSH" ]] && ! grep -q "$MARKER" "$TARGET_PRE_PUSH"; then
    if [[ "${FORCE_HOOK_INSTALL:-0}" != "1" ]]; then
        echo "[hooks] Existing custom pre-push hook detected; leaving it untouched."
        echo "[hooks] Re-run with FORCE_HOOK_INSTALL=1 to overwrite."
        exit 0
    fi
fi

cp "$SOURCE_PRE_PUSH" "$TARGET_PRE_PUSH"
chmod +x "$TARGET_PRE_PUSH"

echo "[hooks] Installed managed pre-push hook at .git/hooks/pre-push"
