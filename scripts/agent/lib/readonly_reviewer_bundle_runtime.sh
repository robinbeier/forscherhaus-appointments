#!/bin/bash

# Loaded only from an exact review-base blob after the outer runner has pinned
# the canonical base, head, and clean worktree. This library owns deterministic
# review-bundle materialization; it must not perform network or model calls.

readonly_reviewer_materialize_changed_blob() {
    local commit_sha="$1"
    local side="$2"
    local changed_path="$3"
    local entry=""
    local mode=""
    local object=""
    local object_type=""
    local object_bytes=""
    local target=""

    entry="$(trusted_git ls-tree "$commit_sha" -- ":(literal)$changed_path")" || return 1
    if [[ -z "$entry" ]]; then
        printf 'absent\t\t\t'
        return 0
    fi
    if [[ ! "$entry" =~ ^([0-7]{6})[[:space:]]+(blob|tree|commit)[[:space:]]+([0-9a-f]{40,64})$'\t'(.+)$ ]]; then
        return 1
    fi
    mode="${BASH_REMATCH[1]}"
    object_type="${BASH_REMATCH[2]}"
    object="${BASH_REMATCH[3]}"
    if [[ "${BASH_REMATCH[4]}" != "$changed_path" ]]; then
        return 1
    fi
    if [[ "$object_type" == "tree" && "$mode" == "040000" ]]; then
        printf 'absent\t\t\t'
        return 0
    fi
    if [[ "$object_type" != "blob" ]]; then
        return 1
    fi
    case "$mode" in
        100644|100755) ;;
        *) return 1 ;;
    esac
    object_bytes="$(trusted_git cat-file -s "$object")" || return 1
    if [[ ! "$object_bytes" =~ ^[0-9]+$ ]]; then
        return 1
    fi
    target="$review_root/$side/$changed_path"
    mkdir -p "$(dirname "$target")" || return 1
    trusted_git cat-file blob "$object" > "$target" || return 1
    printf 'file\t%s\t%s\t%s' "$mode" "$object" "$object_bytes"
}

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

    if ! trusted_git diff --binary --full-index --no-renames --no-ext-diff --no-textconv "$base_sha" "$head_sha" -- > "$review_root/review.patch"; then
        echo "Reviewer committed patch could not be materialized." >&2
        exit 1
    fi
    if ! trusted_php "$control_root/scripts/agent/readonly_review_bundle.php" changed-paths-nul \
        --input="$changed_paths_file" > "$control_root/changed-paths.nul"; then
        echo "Reviewer changed-path stream could not be materialized." >&2
        exit 1
    fi

    blob_evidence_file="$control_root/blob-evidence.tsv"
    : > "$blob_evidence_file"
    while IFS= read -r -d '' changed_path; do
        if ! base_blob="$(readonly_reviewer_materialize_changed_blob "$base_sha" base "$changed_path")"; then
            echo "Reviewer base context could not be materialized." >&2
            exit 1
        fi
        if ! head_blob="$(readonly_reviewer_materialize_changed_blob "$head_sha" head "$changed_path")"; then
            echo "Reviewer head context could not be materialized." >&2
            exit 1
        fi
        printf '%s\t%s\t%s\n' "$changed_path" "$base_blob" "$head_blob" >> "$blob_evidence_file"
    done < "$control_root/changed-paths.nul"

    if ! trusted_php "$control_root/scripts/agent/readonly_review_bundle.php" manifest \
        --bundle-root="$review_root" \
        --lens="$lens" \
        --base-sha="$base_sha" \
        --head-sha="$head_sha" \
        --changed-paths="$changed_paths_file" \
        --blob-evidence="$blob_evidence_file" \
        --trusted-paths="$trusted_paths_file" > "$review_root/manifest.json"; then
        echo "Reviewer deterministic bundle manifest could not be materialized." >&2
        exit 1
    fi
    trusted_php -r 'copy($argv[1], $argv[2]) || exit(1);' "$changed_paths_file" "$review_root/changed-paths.json" || {
        echo "Reviewer readable changed-path evidence could not be materialized." >&2
        exit 1
    }

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
