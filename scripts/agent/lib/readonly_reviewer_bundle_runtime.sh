#!/bin/bash

# Loaded only from an exact review-base blob after the outer runner has pinned
# the canonical base, head, and clean worktree. This library owns deterministic
# review-bundle materialization; it must not perform network or model calls.

readonly_reviewer_materialize_bundle() {
    changed_paths_file="$control_root/changed-paths.json"
    if ! trusted_git diff --name-only --no-renames --no-ext-diff --no-textconv -z "$base_sha" "$head_sha" \
        | trusted_php "$control_root/scripts/agent/readonly_review_bundle.php" changed-paths > "$changed_paths_file"; then
        echo "Reviewer changed-path evidence could not be materialized." >&2
        exit 1
    fi

    trusted_paths="$(trusted_php "$control_root/scripts/agent/readonly_reviewer_contract.php" trusted-paths --lens="$lens")" || exit $?

    trusted_path_count=0
    trusted_paths_file="$control_root/trusted-paths.txt"
    : > "$trusted_paths_file"
    while IFS= read -r trusted_path || [[ -n "$trusted_path" ]]; do
        if [[ -z "$trusted_path" ]]; then
            echo "Reviewer trust-path manifest is invalid." >&2
            exit 1
        fi
        printf '%s\n' "$trusted_path" >> "$trusted_paths_file"
        if [[ ! -f "$control_root/$trusted_path" ]]; then
            mkdir -p "$control_root/$(dirname "$trusted_path")"
            if ! trusted_git show "${base_sha}:${trusted_path}" > "$control_root/$trusted_path"; then
                echo "Reviewer trust bundle is unavailable in the review base." >&2
                exit 1
            fi
        fi
        mkdir -p "$review_root/policy/$(dirname "$trusted_path")"
        if ! trusted_git show "${base_sha}:${trusted_path}" > "$review_root/policy/$trusted_path"; then
            echo "Reviewer readable base policy is unavailable." >&2
            exit 1
        fi
        trusted_path_count=$((trusted_path_count + 1))
    done <<< "$trusted_paths"
    if [[ "$trusted_path_count" -eq 0 ]]; then
        echo "Reviewer trust-path manifest is empty." >&2
        exit 1
    fi

    reviewer_config="$(trusted_php "$control_root/scripts/agent/readonly_reviewer_contract.php" resolve --lens="$lens")" || exit $?
    IFS=$'\t' read -r role_file model reasoning disabled_features output_schema_path <<< "$reviewer_config"
    if [[ -z "$role_file" || -z "$model" || -z "$reasoning" || -z "$disabled_features" || -z "$output_schema_path" ]]; then
        echo "Reviewer invocation policy is incomplete." >&2
        exit 1
    fi
    if [[ ! -f "$control_root/$output_schema_path" ]]; then
        echo "Reviewer output schema is unavailable from the trusted policy bundle." >&2
        exit 1
    fi

    disable_arguments=()
    IFS=',' read -r -a disabled_feature_list <<< "$disabled_features"
    for feature in "${disabled_feature_list[@]}"; do
        disable_arguments+=(--disable "$feature")
    done

    trusted_role_instructions="$(trusted_php "$control_root/scripts/agent/readonly_reviewer_contract.php" instructions --lens="$lens")" || exit $?
    if [[ -z "$trusted_role_instructions" ]]; then
        echo "Reviewer role instructions are empty." >&2
        exit 1
    fi

    if ! trusted_git diff --numstat --no-renames --no-ext-diff --no-textconv -z "$base_sha" "$head_sha" -- \
        | trusted_php "$control_root/scripts/agent/readonly_review_bundle.php" assert-text-diff \
            --changed-paths="$changed_paths_file"; then
        echo "Reviewer binary or mismatched diff evidence was rejected." >&2
        exit 1
    fi
    if ! trusted_git diff --full-index --unified=0 --no-renames --no-ext-diff --no-textconv \
        "$base_sha" "$head_sha" -- \
        | trusted_php "$control_root/scripts/agent/readonly_review_bundle.php" sanitize-patch \
            > "$review_root/review.patch"; then
        echo "Reviewer committed patch could not be materialized." >&2
        exit 1
    fi
    trusted_php -r 'copy($argv[1], $argv[2]) || exit(1);' "$changed_paths_file" "$review_root/changed-paths.json" || {
        echo "Reviewer readable changed-path evidence could not be materialized." >&2
        exit 1
    }

    if ! trusted_php "$control_root/scripts/agent/readonly_review_bundle.php" manifest \
        --bundle-root="$review_root" \
        --lens="$lens" \
        --base-sha="$base_sha" \
        --head-sha="$head_sha" \
        --changed-paths="$review_root/changed-paths.json" \
        --trusted-paths="$trusted_paths_file" > "$review_root/manifest.json"; then
        echo "Reviewer deterministic bundle manifest could not be materialized." >&2
        exit 1
    fi

    review_input="$control_root/review-input.json"
    if ! trusted_php "$control_root/scripts/agent/readonly_review_bundle.php" serialize \
        --bundle-root="$review_root" \
        --max-raw-bytes=8000000 > "$review_input"; then
        echo "Reviewer deterministic input could not be serialized." >&2
        exit 1
    fi

    developer_instructions_file="$control_root/developer-instructions.txt"
    if ! trusted_php "$control_root/scripts/agent/readonly_review_bundle.php" developer-instructions \
        --role="$control_root/$role_file" \
        --lens="$lens" \
        --base-sha="$base_sha" \
        --head-sha="$head_sha" > "$developer_instructions_file"; then
        echo "Reviewer developer instructions could not be materialized." >&2
        exit 1
    fi
    developer_instructions_toml="$(trusted_php "$control_root/scripts/agent/readonly_review_bundle.php" toml-string \
        --input="$developer_instructions_file")" || {
        echo "Reviewer developer instructions could not be encoded." >&2
        exit 1
    }
}
