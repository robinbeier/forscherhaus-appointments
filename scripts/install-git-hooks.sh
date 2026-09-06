#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(git rev-parse --show-toplevel)"
HOOKS_DIR="$(git rev-parse --git-path hooks)"

mkdir -p "$HOOKS_DIR"

install_managed_hook() {
    local hook_name="$1"
    local marker="$2"
    local source_hook="$ROOT_DIR/scripts/hooks/$hook_name"
    local target_hook="$HOOKS_DIR/$hook_name"

    if [[ ! -f "$source_hook" ]]; then
        echo "[hooks] Missing source hook: $source_hook" >&2
        exit 1
    fi

    if [[ -f "$target_hook" ]] && ! grep -q "$marker" "$target_hook"; then
        if [[ "${FORCE_HOOK_INSTALL:-0}" != "1" ]]; then
            echo "[hooks] Existing custom $hook_name hook detected; leaving it untouched."
            echo "[hooks] Re-run with FORCE_HOOK_INSTALL=1 to overwrite."
            return
        fi
    fi

    cp "$source_hook" "$target_hook"
    chmod +x "$target_hook"

    echo "[hooks] Installed managed $hook_name hook at $target_hook"
}

install_managed_hook "pre-commit" "managed-by-forscherhaus-precommit"
# Retire only our old automatic push checks; user-owned hooks stay untouched.
old_pre_push="$HOOKS_DIR/pre-push"
if [[ -f "$old_pre_push" && ! -L "$old_pre_push" ]] &&
    grep -Fxq '# managed-by-forscherhaus-prepush' "$old_pre_push"; then
    rm -- "$old_pre_push"
    echo "[hooks] Removed retired managed pre-push hook."
elif [[ -e "$old_pre_push" || -L "$old_pre_push" ]]; then
    echo "[hooks] Existing custom pre-push hook detected; leaving it untouched."
fi
