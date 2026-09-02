#!/bin/bash

# Loaded only from an exact review-base blob after the outer runner has pinned
# the canonical base, head, and clean worktree. This library owns deterministic
# review-bundle materialization; it must not perform network or model calls.

readonly_reviewer_evidence_git_dir=''
readonly_reviewer_evidence_objects=''
readonly_reviewer_evidence_work_tree=''

readonly_reviewer_prepare_evidence_git() {
    local control_root="$1" repo_root="$2"
    local evidence_git_dir="$control_root/evidence-git"
    local evidence_objects='' evidence_objects_canonical='' evidence_git_dir_canonical='' template_dir="$control_root/evidence-git-template"
    local entry relative_entry evidence_entries
    readonly_reviewer_evidence_git_dir=''
    readonly_reviewer_evidence_objects=''
    readonly_reviewer_evidence_work_tree=''
    evidence_objects="$(trusted_git rev-parse --git-path objects 2>/dev/null)" || return 1
    if [[ "$evidence_objects" != /* ]]; then evidence_objects="$repo_root/$evidence_objects"; fi
    evidence_objects_canonical="$(canonical_path "$evidence_objects")" || return 1
    [[ -d "$evidence_objects_canonical" && ! -L "$evidence_objects_canonical" ]] || return 1
    mkdir -m 0700 -- "$template_dir" || return 1
    # The explicit empty template prevents default-template files such as
    # description or hooks from entering this private evidence boundary.
    /usr/bin/env -i GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_SYSTEM=/dev/null \
        LANG=C LC_ALL=C PATH=/usr/bin:/bin:/usr/sbin:/sbin TMPDIR=/tmp \
        /usr/bin/git init --bare --template="$template_dir" -q "$evidence_git_dir" || return 1
    chmod 0700 "$evidence_git_dir" || return 1
    evidence_git_dir_canonical="$(canonical_path "$evidence_git_dir")" || return 1
    [[ "$evidence_git_dir_canonical" == "$evidence_git_dir" && "$evidence_git_dir_canonical" != "$repo_root" && "$evidence_git_dir_canonical" != "$repo_root"/* ]] || return 1
    evidence_entries="$(/usr/bin/find "$evidence_git_dir_canonical" -mindepth 1 -print)" || return 1
    while IFS= read -r entry; do
        [[ -n "$entry" && ! -L "$entry" ]] || return 1
        relative_entry="${entry#"$evidence_git_dir_canonical/"}"
        case "$relative_entry" in
            HEAD|config|branches|hooks|info|objects|objects/info|objects/pack|refs|refs/heads|refs/tags) ;;
            *) return 1 ;;
        esac
    done <<< "$evidence_entries"
    readonly_reviewer_evidence_git_dir="$evidence_git_dir_canonical"
    readonly_reviewer_evidence_objects="$evidence_objects_canonical"
}

readonly_reviewer_evidence_git() {
    [[ -n "$readonly_reviewer_evidence_git_dir" && -n "$readonly_reviewer_evidence_objects" &&
        -n "$readonly_reviewer_evidence_work_tree" &&
        ! -e "$readonly_reviewer_evidence_git_dir/info/attributes" ]] || return 1
    /usr/bin/env -i GIT_ATTR_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_SYSTEM=/dev/null \
        GIT_NO_LAZY_FETCH=1 GIT_NO_REPLACE_OBJECTS=1 GIT_ALTERNATE_OBJECT_DIRECTORIES="$readonly_reviewer_evidence_objects" \
        GIT_OPTIONAL_LOCKS=0 GIT_PAGER=cat GIT_TERMINAL_PROMPT=0 GIT_WORK_TREE="$readonly_reviewer_evidence_work_tree" \
        LANG=C LC_ALL=C PATH=/usr/bin:/bin:/usr/sbin:/sbin TMPDIR=/tmp \
        /usr/bin/git -c core.askPass= -c core.attributesFile=/dev/null -c core.bare=false -c core.fsmonitor=false -c core.hooksPath=/dev/null \
        -c core.untrackedCache=false -c credential.helper= -c diff.external= -c diff.algorithm=default -c diff.submodule=short \
        -c http.proxy= -c https.proxy= -c pager.diff=false -c core.excludesfile=/dev/null --git-dir="$readonly_reviewer_evidence_git_dir" "$@"
}

readonly_reviewer_bind_evidence_base_policy() {
    local control_root="$1" repo_root="$2" base_sha="$3"
    local work_tree="$control_root/evidence-work-tree" work_tree_canonical=''

    mkdir -m 0700 -- "$work_tree" || return 1
    work_tree_canonical="$(canonical_path "$work_tree")" || return 1
    [[ "$work_tree_canonical" == "$work_tree" && "$work_tree_canonical" != "$repo_root" &&
        "$work_tree_canonical" != "$repo_root"/* ]] || return 1
    readonly_reviewer_evidence_work_tree="$work_tree_canonical"

    # Attribute policy is an authority boundary. The base index is queried
    # explicitly with check-attr --cached before any head index is installed.
    readonly_reviewer_evidence_git read-tree "$base_sha" || return 1
}

readonly_reviewer_materialize_bundle() {
    local control_root="$1" review_root="$2" base_sha="$3" head_sha="$4" lens="$5"
    local changed_paths_file changed_paths_nul changed_path changed_commit changed_blob_type
    local trusted_paths trusted_path trusted_path_count trusted_paths_file
    local reviewer_config role_file model reasoning disabled_features output_schema_path
    local ignored_sandbox_mode ignored_approval_policy
    local trusted_role_instructions review_input developer_instructions_file
    readonly_reviewer_prepare_evidence_git "$control_root" "$repo_root" || {
        echo "Reviewer sterile evidence Git boundary is invalid." >&2
        exit 1
    }
    evidence_git() { readonly_reviewer_evidence_git "$@"; }
    if ! readonly_reviewer_bind_evidence_base_policy "$control_root" "$repo_root" "$base_sha"; then
        echo "Reviewer committed attribute index could not be bound to the trusted review base." >&2
        exit 1
    fi

    changed_paths_file="$control_root/changed-paths.json"
    changed_paths_nul="$control_root/changed-paths.z"
    if ! evidence_git diff --name-only --no-color --no-renames --no-ext-diff --no-textconv -z \
        "$base_sha" "$head_sha" > "$changed_paths_nul" ||
        ! trusted_php "$control_root/scripts/agent/readonly_review_bundle.php" changed-paths \
            < "$changed_paths_nul" > "$changed_paths_file"; then
        echo "Reviewer changed-path evidence could not be materialized." >&2
        exit 1
    fi
    if ! evidence_git check-attr --cached -z --stdin diff < "$changed_paths_nul" \
        | trusted_php "$control_root/scripts/agent/readonly_review_bundle.php" assert-base-diff-attributes \
            --changed-paths="$changed_paths_file"; then
        echo "Reviewer binary evidence was rejected by trusted-base attributes." >&2
        exit 1
    fi
    while IFS= read -r -d '' changed_path; do
        for changed_commit in "$base_sha" "$head_sha"; do
            changed_blob_type="$(evidence_git cat-file -t "${changed_commit}:${changed_path}" 2>/dev/null || true)"
            case "$changed_blob_type" in
                '') continue ;;
                blob)
                    if ! evidence_git cat-file blob "${changed_commit}:${changed_path}" \
                        | trusted_php "$control_root/scripts/agent/readonly_review_bundle.php" assert-text-blob \
                            --max-raw-bytes=8000000; then
                        echo "Reviewer changed blob is not bounded UTF-8 text." >&2
                        exit 1
                    fi
                    ;;
                *)
                    echo "Reviewer changed path does not resolve to a regular blob." >&2
                    exit 1
                    ;;
            esac
        done
    done < "$changed_paths_nul"
    readonly_reviewer_evidence_git read-tree "$head_sha" || {
        echo "Reviewer exact head index could not be materialized." >&2
        exit 1
    }

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
            if ! evidence_git show "${base_sha}:${trusted_path}" > "$control_root/$trusted_path"; then
                echo "Reviewer trust bundle is unavailable in the review base." >&2
                exit 1
            fi
        fi
        mkdir -p "$review_root/policy/$(dirname "$trusted_path")"
        if ! evidence_git show "${base_sha}:${trusted_path}" > "$review_root/policy/$trusted_path"; then
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
    IFS=$'\t' read -r role_file model reasoning disabled_features output_schema_path \
        ignored_sandbox_mode ignored_approval_policy <<< "$reviewer_config"
    if [[ -z "$role_file" || -z "$model" || -z "$reasoning" || -z "$disabled_features" || -z "$output_schema_path" ]]; then
        echo "Reviewer invocation policy is incomplete." >&2
        exit 1
    fi
    if [[ ! -f "$control_root/$output_schema_path" ]]; then
        echo "Reviewer output schema is unavailable from the trusted policy bundle." >&2
        exit 1
    fi

    trusted_role_instructions="$(trusted_php "$control_root/scripts/agent/readonly_reviewer_contract.php" instructions --lens="$lens")" || exit $?
    if [[ -z "$trusted_role_instructions" ]]; then
        echo "Reviewer role instructions are empty." >&2
        exit 1
    fi

    if ! evidence_git diff --cached --text --numstat --no-color --no-renames --no-ext-diff --no-textconv -z "$base_sha" -- \
        | trusted_php "$control_root/scripts/agent/readonly_review_bundle.php" assert-text-diff \
            --changed-paths="$changed_paths_file"; then
        echo "Reviewer binary or mismatched diff evidence was rejected." >&2
        exit 1
    fi
    if ! evidence_git diff --cached --text --full-index --unified=0 --no-color --src-prefix=a/ --dst-prefix=b/ \
        --no-renames --no-ext-diff --no-textconv \
        "$base_sha" -- \
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
}
