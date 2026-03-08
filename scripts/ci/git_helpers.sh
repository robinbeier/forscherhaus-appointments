#!/usr/bin/env bash

git_ci_common_dir_is_within_repo() {
    local repo_root common_dir

    repo_root="$(git rev-parse --path-format=absolute --show-toplevel 2>/dev/null || git rev-parse --show-toplevel)"
    common_dir="$(git rev-parse --path-format=absolute --git-common-dir 2>/dev/null || true)"

    if [[ -z "$common_dir" ]]; then
        return 0
    fi

    case "$common_dir" in
        "$repo_root"|"$repo_root"/*)
            return 0
            ;;
        *)
            return 1
            ;;
    esac
}

git_ci_refresh_base_ref_if_safe() {
    local base_ref="${1:?base ref is required}"
    local log_prefix="${2:-git-ci}"

    if [[ "${EA_FORCE_GIT_FETCH:-0}" == "1" ]]; then
        git fetch --no-tags --no-write-fetch-head origin "$base_ref" >/dev/null 2>&1 || true
        return 0
    fi

    if ! git_ci_common_dir_is_within_repo; then
        echo "[$log_prefix] WARN: skipping git fetch for origin/$base_ref because the git common dir is outside the writable worktree root." >&2
        return 0
    fi

    git fetch --no-tags --no-write-fetch-head origin "$base_ref" >/dev/null 2>&1 || true
}
