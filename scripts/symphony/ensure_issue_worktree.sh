#!/usr/bin/env bash
set -euo pipefail

resolve_repo_root() {
    local script_dir
    script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
    cd "$script_dir/../.." && pwd -P
}

workspace_path="$(pwd -P)"
repo_root="${SYMPHONY_REPO_ROOT:-$(resolve_repo_root)}"
base_ref="${SYMPHONY_WORKTREE_BASE_REF:-HEAD}"
issue_key="$(basename "$workspace_path")"

if [[ ! -d "$repo_root/.git" ]]; then
    echo "[symphony-worktree] Repo root is not a git repository: $repo_root" >&2
    exit 1
fi

branch_slug="$(printf '%s' "$issue_key" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9._-]+/-/g; s/^-+//; s/-+$//; s/-+/-/g')"
if [[ -z "$branch_slug" ]]; then
    branch_slug="workspace"
fi
branch_name="codex/symphony-${branch_slug}"

workspace_registered_in_git() {
    git -C "$repo_root" worktree list --porcelain | awk -v workspace="$workspace_path" '
        $1 == "worktree" && $2 == workspace { found = 1 }
        END { exit found ? 0 : 1 }
    '
}

if workspace_registered_in_git; then
    if git -C "$workspace_path" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        exit 0
    fi

    # Recover from stale registrations where the directory exists but is no longer a worktree.
    git -C "$repo_root" worktree remove --force "$workspace_path" >/dev/null 2>&1 || true
    git -C "$repo_root" worktree prune >/dev/null 2>&1 || true
fi

if find "$workspace_path" -mindepth 1 -maxdepth 1 -print -quit | grep -q .; then
    echo "[symphony-worktree] Workspace is not empty and cannot be initialized safely: $workspace_path" >&2
    exit 1
fi

git -C "$repo_root" worktree prune >/dev/null 2>&1 || true

while IFS= read -r branch_worktree_path; do
    if [[ "$branch_worktree_path" == "$workspace_path" ]]; then
        continue
    fi

    if [[ -d "$branch_worktree_path" ]]; then
        echo "[symphony-worktree] Branch $branch_name is already checked out at $branch_worktree_path" >&2
        exit 1
    fi
done < <(
    git -C "$repo_root" worktree list --porcelain | awk -v target="refs/heads/$branch_name" '
        $1 == "worktree" { path = $2 }
        $1 == "branch" && $2 == target { print path }
    '
)

if git -C "$repo_root" show-ref --verify --quiet "refs/heads/$branch_name"; then
    git -C "$repo_root" worktree add --force "$workspace_path" "$branch_name" >/dev/null
else
    git -C "$repo_root" worktree add -b "$branch_name" "$workspace_path" "$base_ref" >/dev/null
fi

echo "[symphony-worktree] Prepared worktree $workspace_path on branch $branch_name (base: $base_ref)."
