#!/usr/bin/env bash
set -euo pipefail

resolve_repo_root() {
    local script_dir
    script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
    cd "$script_dir/../.." && pwd -P
}

workspace_path="$(pwd -P)"
repo_root="${SYMPHONY_REPO_ROOT:-$(resolve_repo_root)}"

if [[ ! -d "$repo_root/.git" ]]; then
    exit 0
fi

is_registered_worktree=0
if git -C "$repo_root" worktree list --porcelain | awk -v workspace="$workspace_path" '
    $1 == "worktree" && $2 == workspace { found = 1 }
    END { exit found ? 0 : 1 }
'; then
    is_registered_worktree=1
fi

if [[ "$is_registered_worktree" -eq 0 ]]; then
    exit 0
fi

git -C "$repo_root" worktree remove --force "$workspace_path" >/dev/null 2>&1 || true
git -C "$repo_root" worktree prune >/dev/null 2>&1 || true

echo "[symphony-worktree] Removed worktree registration for $workspace_path."
