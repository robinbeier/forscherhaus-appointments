#!/usr/bin/env bash

set -euo pipefail

mode="lint"
if [[ "$#" -gt 1 ]]; then
    echo "Usage: $0 [--check-only]" >&2
    exit 2
fi
if [[ "$#" -eq 1 ]]; then
    if [[ "$1" != "--check-only" ]]; then
        echo "Usage: $0 [--check-only]" >&2
        exit 2
    fi
    mode="check"
fi

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
build_tools_changed=false
changed_file_list="$(mktemp "${TMPDIR:-/tmp}/js-lint-changed.XXXXXX")"
trap 'rm -f -- "$changed_file_list"' EXIT

if ! git diff --name-only -z --diff-filter=ACMRD "$range" >"$changed_file_list"; then
    echo "Unable to determine changed files for range $range." >&2
    exit 1
fi

while IFS= read -r -d '' file; do
    if [[ "$file" == assets/js/*.js && "$file" != assets/js/*.min.js && -f "$file" ]]; then
        changed_files+=("$file")
    fi
    case "$file" in
        gulpfile.js|babel.config.json|package.json|package-lock.json|tests/JavaScript/gulp_build.test.js|scripts/ci/js-lint-changed.sh|.github/workflows/ci.yml)
            build_tools_changed=true
            ;;
    esac
done <"$changed_file_list"

# The compiler regression uses the same Node installation as ESLint. Keep
# JavaScript selection separate so tooling-only changes do not lint all sources.
if [[ "$mode" == "check" ]]; then
    : "${GITHUB_OUTPUT:?GITHUB_OUTPUT is required for --check-only}"
    if [[ "$build_tools_changed" == "true" || "${#changed_files[@]}" -gt 0 ]]; then
        printf 'needs_node=true\n' >>"$GITHUB_OUTPUT"
    else
        printf 'needs_node=false\n' >>"$GITHUB_OUTPUT"
    fi
fi

if [[ "${#changed_files[@]}" -eq 0 ]]; then
    echo "No changed JS files under assets/js (excluding *.min.js); skipping ESLint."
    if [[ "$mode" == "check" ]]; then
        : "${GITHUB_OUTPUT:?GITHUB_OUTPUT is required for --check-only}"
        printf 'has_changes=false\n' >>"$GITHUB_OUTPUT"
    fi
    exit 0
fi

echo "Selected JavaScript files:"
printf ' - %s\n' "${changed_files[@]}"

if [[ "$mode" == "check" ]]; then
    : "${GITHUB_OUTPUT:?GITHUB_OUTPUT is required for --check-only}"
    printf 'has_changes=true\n' >>"$GITHUB_OUTPUT"
    exit 0
fi

./node_modules/.bin/eslint --max-warnings=0 "${changed_files[@]}"
