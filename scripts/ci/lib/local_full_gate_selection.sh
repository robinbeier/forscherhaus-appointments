#!/usr/bin/env bash

# Output true/false; selection errors are fatal, never a reason to skip a check.
pre_pr_full_should_run_integration_smoke() (
    set -o pipefail
    local base_ref="${1:?base ref is required}"
    [[ "$base_ref" == origin/* ]] || base_ref="origin/${base_ref}"

    if ! git rev-parse --verify --end-of-options "${base_ref}^{commit}" >/dev/null 2>&1; then
        echo "[pre-pr-full] ERROR: cannot resolve changed-path base ${base_ref}." >&2
        return 1
    fi

    # Disable rename detection so a move out of a protected path still checks
    # its deletion. NUL delimiters preserve spaces, quoting and newlines.
    {
        git diff --name-only --no-renames -z "${base_ref}...HEAD" -- || exit 1
        git diff --name-only --no-renames -z -- || exit 1
        git diff --cached --name-only --no-renames -z -- || exit 1
        git ls-files --others --exclude-standard -z -- || exit 1
    } | php scripts/ci/select_local_full_gate.php --workflow=.github/workflows/ci.yml
)
