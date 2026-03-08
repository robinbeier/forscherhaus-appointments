#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(git rev-parse --show-toplevel)"
cd "$ROOT_DIR"
source ./scripts/ci/git_helpers.sh

event_name="${GITHUB_EVENT_NAME:-local}"
range=""

if [[ "$event_name" == "pull_request" ]]; then
    base_ref="${GITHUB_BASE_REF:-main}"
    git_ci_refresh_base_ref_if_safe "$base_ref" "js-lint-changed"
    if git rev-parse --verify "origin/$base_ref" >/dev/null 2>&1; then
        base_sha="$(git merge-base HEAD "origin/$base_ref")"
        range="$base_sha...HEAD"
    else
        range="HEAD~1...HEAD"
    fi
elif [[ "$event_name" == "push" ]]; then
    before_sha="${GITHUB_EVENT_BEFORE:-}"
    if [[ -n "$before_sha" && "$before_sha" != "0000000000000000000000000000000000000000" ]]; then
        range="$before_sha...HEAD"
    else
        range="HEAD~1...HEAD"
    fi
else
    range="HEAD~1...HEAD"
fi

changed_files=()

while IFS= read -r file; do
    if [[ "$file" == assets/js/*.js && "$file" != assets/js/*.min.js ]]; then
        changed_files+=("$file")
    fi
done < <(git diff --name-only --diff-filter=ACMR "$range")

if [[ "${#changed_files[@]}" -eq 0 ]]; then
    echo "No changed JS files under assets/js (excluding *.min.js); skipping ESLint."
    exit 0
fi

echo "Running ESLint for changed JS files:"
printf ' - %s\n' "${changed_files[@]}"

./node_modules/.bin/eslint --max-warnings=0 "${changed_files[@]}"
