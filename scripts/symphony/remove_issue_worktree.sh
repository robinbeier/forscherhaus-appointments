#!/usr/bin/env bash
set -euo pipefail

resolve_repo_root() {
    local script_dir
    script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
    cd "$script_dir/../.." && pwd -P
}

close_open_prs_for_branch() {
    local workspace_path="$1"
    local branch_name="$2"
    local pr_numbers
    local pr_number
    local close_output
    local close_exit_code

    if [[ -z "$branch_name" ]]; then
        return 0
    fi

    if ! command -v gh >/dev/null 2>&1; then
        return 0
    fi

    if ! gh auth status >/dev/null 2>&1; then
        return 0
    fi

    if ! pr_numbers="$(cd "$workspace_path" && gh pr list --head "$branch_name" --state open --json number --jq '.[].number' 2>/dev/null)"; then
        return 0
    fi

    while IFS= read -r pr_number; do
        if [[ -z "$pr_number" ]]; then
            continue
        fi

        if close_output="$(cd "$workspace_path" && gh pr close "$pr_number" --delete-branch=false 2>&1 >/dev/null)"; then
            echo "[symphony-worktree] Closed PR #$pr_number for branch $branch_name."
            continue
        fi

        close_exit_code=$?
        if [[ -n "$close_output" ]]; then
            echo "[symphony-worktree] Failed to close PR #$pr_number for branch $branch_name: $close_output" >&2
        else
            echo "[symphony-worktree] Failed to close PR #$pr_number for branch $branch_name: exit $close_exit_code" >&2
        fi
    done <<<"$pr_numbers"
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

branch_name="$(git -C "$workspace_path" symbolic-ref --quiet --short HEAD 2>/dev/null || true)"
close_open_prs_for_branch "$workspace_path" "$branch_name"

git -C "$repo_root" worktree remove --force "$workspace_path" >/dev/null 2>&1 || true
git -C "$repo_root" worktree prune >/dev/null 2>&1 || true

echo "[symphony-worktree] Removed worktree registration for $workspace_path."
