#!/usr/bin/env bash
set -euo pipefail

resolve_repo_root() {
    local script_dir
    script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
    cd "$script_dir/../.." && pwd -P
}

repo_root_is_git_repository() {
    git -C "$repo_root" rev-parse --git-dir >/dev/null 2>&1
}

workspace_path="$(pwd -P)"
repo_root="${SYMPHONY_REPO_ROOT:-$(resolve_repo_root)}"
issue_key="$(basename "$workspace_path")"

if ! repo_root_is_git_repository; then
    echo "[symphony-worktree] Repo root is not a git repository: $repo_root" >&2
    exit 1
fi

branch_slug="$(printf '%s' "$issue_key" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9._-]+/-/g; s/^-+//; s/-+$//; s/-+/-/g')"
if [[ -z "$branch_slug" ]]; then
    branch_slug="workspace"
fi
branch_name="codex/symphony-${branch_slug}"

origin_remote_exists() {
    git -C "$repo_root" remote get-url origin >/dev/null 2>&1
}

refresh_origin_refs() {
    if ! origin_remote_exists; then
        return
    fi

    if ! git -C "$repo_root" fetch --prune origin >/dev/null 2>&1; then
        echo "[symphony-worktree] Warning: failed to refresh origin refs. Continuing with local refs." >&2
    fi
}

resolve_base_ref() {
    if [[ -n "${SYMPHONY_WORKTREE_BASE_REF:-}" ]]; then
        if git -C "$repo_root" rev-parse --verify --quiet "${SYMPHONY_WORKTREE_BASE_REF}^{commit}" >/dev/null; then
            printf '%s\n' "$SYMPHONY_WORKTREE_BASE_REF"
            return
        fi

        echo "[symphony-worktree] Warning: requested base ref ${SYMPHONY_WORKTREE_BASE_REF} does not exist locally. Falling back." >&2
    fi

    if git -C "$repo_root" show-ref --verify --quiet "refs/remotes/origin/main"; then
        printf '%s\n' "origin/main"
        return
    fi

    local origin_head=""
    origin_head="$(git -C "$repo_root" symbolic-ref --quiet --short "refs/remotes/origin/HEAD" 2>/dev/null || true)"
    if [[ -n "$origin_head" ]]; then
        printf '%s\n' "$origin_head"
        return
    fi

    printf '%s\n' "HEAD"
}

workspace_registered_in_git() {
    git -C "$repo_root" worktree list --porcelain | awk -v workspace="$workspace_path" '
        $1 == "worktree" && $2 == workspace { found = 1 }
        END { exit found ? 0 : 1 }
    '
}

ensure_line_in_file() {
    local file_path="$1"
    local line="$2"

    touch "$file_path"
    if ! grep -Fqx "$line" "$file_path"; then
        printf '%s\n' "$line" >>"$file_path"
    fi
}

align_workspace_to_issue_branch() {
    local issue_branch="${SYMPHONY_ISSUE_BRANCH_NAME:-}"
    local workspace_status=""
    local current_branch=""

    if [[ -z "$issue_branch" ]]; then
        return
    fi

    workspace_status="$(git -C "$workspace_path" status --porcelain=v1 --untracked-files=all 2>/dev/null || true)"
    if [[ -n "$workspace_status" ]]; then
        echo "[symphony-worktree] Preserving dirty workspace on current branch; skipping branch alignment to $issue_branch." >&2
        return
    fi

    current_branch="$(git -C "$workspace_path" symbolic-ref --quiet --short HEAD 2>/dev/null || true)"

    if git -C "$repo_root" show-ref --verify --quiet "refs/remotes/origin/$issue_branch"; then
        if git -C "$workspace_path" checkout -B "$issue_branch" "origin/$issue_branch" >/dev/null 2>&1; then
            if [[ "$current_branch" != "$issue_branch" ]]; then
                echo "[symphony-worktree] Aligned workspace branch to origin/$issue_branch." >&2
            fi
        else
            echo "[symphony-worktree] Warning: failed to align workspace branch to origin/$issue_branch. Continuing on ${current_branch:-current HEAD}." >&2
        fi
        return
    fi

    if git -C "$repo_root" show-ref --verify --quiet "refs/heads/$issue_branch"; then
        if git -C "$workspace_path" checkout "$issue_branch" >/dev/null 2>&1; then
            if [[ "$current_branch" != "$issue_branch" ]]; then
                echo "[symphony-worktree] Switched workspace branch to local $issue_branch." >&2
            fi
        else
            echo "[symphony-worktree] Warning: failed to switch workspace branch to local $issue_branch. Continuing on ${current_branch:-current HEAD}." >&2
        fi
    fi
}

sync_worker_support_files() {
    local repo_skills_path="$repo_root/.codex/skills"
    local repo_napkin_path="$repo_root/.claude/napkin.md"
    local exclude_file

    mkdir -p "$workspace_path/.codex" "$workspace_path/.claude"

    if [[ -d "$repo_skills_path" ]]; then
        rm -rf "$workspace_path/.codex/skills"
        cp -R "$repo_skills_path" "$workspace_path/.codex/skills"
    fi

    if [[ -f "$repo_napkin_path" ]]; then
        cp "$repo_napkin_path" "$workspace_path/.claude/napkin.md"
    fi

    exclude_file="$(git -C "$workspace_path" rev-parse --git-path info/exclude)"
    if [[ "$exclude_file" != /* ]]; then
        exclude_file="$(cd "$workspace_path" && pwd -P)/$exclude_file"
    fi

    mkdir -p "$(dirname "$exclude_file")"
    ensure_line_in_file "$exclude_file" '/.codex/skills/*'
    ensure_line_in_file "$exclude_file" '/.codex/skills/**'
    ensure_line_in_file "$exclude_file" '/.claude/napkin.md'

    while IFS= read -r tracked_path; do
        if [[ -n "$tracked_path" ]]; then
            git -C "$workspace_path" update-index --assume-unchanged -- "$tracked_path" >/dev/null 2>&1 || true
        fi
    done < <(git -C "$workspace_path" ls-files .codex/skills .claude/napkin.md 2>/dev/null || true)
}

refresh_origin_refs
base_ref="$(resolve_base_ref)"
worktree_ready=0

if workspace_registered_in_git; then
    if git -C "$workspace_path" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        worktree_ready=1
    else
        # Recover from stale registrations where the directory exists but is no longer a worktree.
        git -C "$repo_root" worktree remove --force "$workspace_path" >/dev/null 2>&1 || true
        git -C "$repo_root" worktree prune >/dev/null 2>&1 || true
    fi
fi

if [[ "$worktree_ready" -ne 1 ]]; then
    if find "$workspace_path" -mindepth 1 -maxdepth 1 -print -quit | grep -q .; then
        echo "[symphony-worktree] Workspace is not empty and cannot be initialized safely: $workspace_path" >&2
        exit 1
    fi

    git -C "$repo_root" worktree prune >/dev/null 2>&1 || true

    find_branch_worktree_path() {
        git -C "$repo_root" worktree list --porcelain | awk -v target="refs/heads/$branch_name" '
            $1 == "worktree" { path = $2 }
            $1 == "branch" && $2 == target { print path }
        '
    }

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

branch_is_merged_into_base() {
    git -C "$repo_root" merge-base --is-ancestor "refs/heads/$branch_name" "$base_ref" >/dev/null 2>&1
}

branch_has_closed_or_merged_pr() {
    if ! command -v gh >/dev/null 2>&1; then
        return 1
    fi

    if ! gh auth status >/dev/null 2>&1; then
        return 1
    fi

    local pr_states
    pr_states="$(
        cd "$repo_root" && gh pr list --head "$branch_name" --state all --json state --jq '.[].state' 2>/dev/null || true
    )"

    if [[ -z "$pr_states" ]]; then
        return 1
    fi

    while IFS= read -r pr_state; do
        case "$pr_state" in
            CLOSED|MERGED)
                return 0
                ;;
        esac
    done <<<"$pr_states"

    return 1
}

branch_requires_recreation=0

if git -C "$repo_root" show-ref --verify --quiet "refs/heads/$branch_name"; then
    if branch_is_merged_into_base; then
        branch_requires_recreation=1
        echo "[symphony-worktree] Recreating $branch_name because it is already merged into $base_ref." >&2
    elif branch_has_closed_or_merged_pr; then
        branch_requires_recreation=1
        echo "[symphony-worktree] Recreating $branch_name because its previous PR is already closed or merged." >&2
    fi
fi

if [[ "$branch_requires_recreation" -eq 1 ]]; then
    existing_branch_worktree="$(find_branch_worktree_path)"
    if [[ -n "$existing_branch_worktree" && "$existing_branch_worktree" != "$workspace_path" && -d "$existing_branch_worktree" ]]; then
        echo "[symphony-worktree] Cannot recreate stale branch $branch_name because it is checked out at $existing_branch_worktree" >&2
        exit 1
    fi

    git -C "$repo_root" branch -D "$branch_name" >/dev/null
fi

    if git -C "$repo_root" show-ref --verify --quiet "refs/heads/$branch_name"; then
        git -C "$repo_root" worktree add --force "$workspace_path" "$branch_name" >/dev/null
    else
        git -C "$repo_root" worktree add -b "$branch_name" "$workspace_path" "$base_ref" >/dev/null
    fi
fi

align_workspace_to_issue_branch
sync_worker_support_files

echo "[symphony-worktree] Prepared worktree $workspace_path on branch $branch_name (base: $base_ref)."
