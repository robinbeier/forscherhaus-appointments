#!/bin/bash

# Loaded only from an exact review-base blob. This library owns the sealed
# Seatbelt runtime and model invocation; bundle construction stays separate.

readonly_reviewer_seatbelt_run() {
    local sandbox_exec="$1" seatbelt_profile="$2" codex_bin="$3" sealed_root="$4"
    local arg0_root="$5" runtime_tmp="$6" auth_source="$7" installation_id="$8"
    shift 8
    "$sandbox_exec" \
        -D CODEX_BIN="$codex_bin" -D SEALED_ROOT="$sealed_root" \
        -D ARG0_ROOT="$arg0_root" -D RUNTIME_TMP="$runtime_tmp" \
        -D AUTH_FILE="$auth_source" -D INSTALLATION_ID="$installation_id" \
        -f "$seatbelt_profile" \
        "$@"
}

readonly_reviewer_execute_isolated() (
    local control_root="$1" review_root="$2" sealed_root="$3" repo_root="$4"
    local auth_source="$5" codex_bin="$6" reviewer_system_path="$7" reviewer_os_home="$8"
    local sandbox_exec="$9" lens="${10}" base_sha="${11}" head_sha="${12}"
    local runtime_home runtime_tmp arg0_root sqlite_root log_root installation_id seatbelt_profile
    local model_catalog prompt_role_probe allowed_canary outside_canary_root outside_canary
    local private_temp_parent reviewer_os_user
    local codex_stderr review_pipeline_status
    local ignored_role model reasoning disabled_features output_schema_path codex_sandbox_mode codex_approval_policy
    local developer_instructions_toml
    local developer_instructions_file review_input changed_paths_file
    local reviewer_config feature
    local -a disable_arguments disabled_feature_list reviewer_environment

    reviewer_config="$(trusted_php "$control_root/scripts/agent/readonly_reviewer_contract.php" resolve --lens="$lens")" || exit $?
    IFS=$'\t' read -r ignored_role model reasoning disabled_features output_schema_path \
        codex_sandbox_mode codex_approval_policy <<< "$reviewer_config"
    if [[ -z "$model" || -z "$reasoning" || -z "$disabled_features" || -z "$output_schema_path" || \
        "$codex_sandbox_mode" != "read-only" || "$codex_approval_policy" != "never" ]]; then
        echo "Reviewer invocation policy is incomplete." >&2
        exit 1
    fi
    developer_instructions_file="$control_root/developer-instructions.txt"
    review_input="$control_root/review-input.json"
    changed_paths_file="$control_root/changed-paths.json"
    developer_instructions_toml="$(trusted_php "$control_root/scripts/agent/readonly_review_bundle.php" toml-string \
        --input="$developer_instructions_file")" || {
        echo "Reviewer developer instructions could not be encoded." >&2
        exit 1
    }
    disable_arguments=()
    IFS=',' read -r -a disabled_feature_list <<< "$disabled_features"
    for feature in "${disabled_feature_list[@]}"; do
        disable_arguments+=(--disable "$feature")
    done

    case "$(/usr/bin/uname -s 2>/dev/null)" in
        Darwin) reviewer_os_user="$(/usr/bin/stat -f '%Su' "$reviewer_os_home" 2>/dev/null)" ;;
        Linux) reviewer_os_user="$(/usr/bin/stat -Lc '%U' "$reviewer_os_home" 2>/dev/null)" ;;
        *) reviewer_os_user="" ;;
    esac
    if [[ ! "$reviewer_os_user" =~ ^[A-Za-z0-9._-]+$ ]]; then
        echo "Reviewer OS account name is unavailable." >&2
        exit 2
    fi

    runtime_home="$control_root/codex-home"
    runtime_tmp="$control_root/runtime-tmp"
    arg0_root="$runtime_home/tmp/arg0"
    sqlite_root="$runtime_tmp/sqlite"
    log_root="$runtime_tmp/log"
    installation_id="$runtime_home/installation_id"
    mkdir -m 0700 "$runtime_home" "$runtime_home/tmp" "$arg0_root" "$runtime_tmp" "$sqlite_root" "$log_root"
    printf '%s' '11111111-1111-4111-8111-111111111111' > "$installation_id"
    chmod 0644 "$installation_id"
    ln -s "$auth_source" "$runtime_home/auth.json"
    chmod 0500 "$runtime_home"

    seatbelt_profile="$control_root/scripts/agent/readonly-reviewer.sb"
    reviewer_environment=(
        env -i
        PATH="$reviewer_system_path"
        HOME="$runtime_home"
        USER="$reviewer_os_user"
        LOGNAME="$reviewer_os_user"
        CODEX_HOME="$runtime_home"
        CODEX_SQLITE_HOME="$sqlite_root"
        TMPDIR="$runtime_tmp"
        TMP="$runtime_tmp"
        TEMP="$runtime_tmp"
        XDG_CACHE_HOME="$runtime_tmp"
        LANG="C.UTF-8"
    )

    cd "$sealed_root"

    # Codex 0.145 rejects the exec-only config-isolation flags on debug.
    # These probes instead inherit only the fresh synthetic HOME/CODEX_HOME, clean env,
    # sealed working directory, and Seatbelt boundary established above.
    model_catalog="$control_root/models.json"
    if ! readonly_reviewer_seatbelt_run "$sandbox_exec" "$seatbelt_profile" "$codex_bin" "$sealed_root" "$arg0_root" "$runtime_tmp" "$auth_source" "$installation_id" \
        "${reviewer_environment[@]}" "$codex_bin" \
        debug models --bundled 2>/dev/null \
        | trusted_php "$control_root/scripts/agent/readonly_review_bundle.php" model-catalog \
            --model="$model" > "$model_catalog"; then
        echo "Reviewer tool-free model catalog could not be derived." >&2
        exit 1
    fi

    prompt_role_probe='UNTRUSTED-REVIEW-BUNDLE-PROBE'
    if ! readonly_reviewer_seatbelt_run "$sandbox_exec" "$seatbelt_profile" "$codex_bin" "$sealed_root" "$arg0_root" "$runtime_tmp" "$auth_source" "$installation_id" \
        "${reviewer_environment[@]}" "$codex_bin" \
            "${disable_arguments[@]}" \
            -c "developer_instructions=$developer_instructions_toml" \
            -c 'mcp_servers={}' \
            -c 'agents.max_threads=1' \
            -c 'agents.max_depth=0' \
            debug prompt-input "$prompt_role_probe" 2>/dev/null \
        | trusted_php "$control_root/scripts/agent/readonly_review_bundle.php" validate-prompt-roles \
            --developer="$developer_instructions_file" \
            --user-probe="$prompt_role_probe"; then
        echo "Reviewer pinned CLI did not preserve developer/user prompt priority." >&2
        exit 1
    fi

    allowed_canary="$review_root/.review-bundle-readable-canary"
    : > "$allowed_canary"
    private_temp_parent="$(dirname -- "$sealed_root")"
    outside_canary_root="$(mktemp -d "$private_temp_parent/forscherhaus-readonly-review-denied.XXXXXX")" || {
        echo "Reviewer external temp canary could not be created." >&2
        exit 2
    }
    outside_canary="$outside_canary_root/denied"
    : > "$outside_canary"
    cleanup_runtime_canaries() {
        if [[ -n "$outside_canary_root" ]]; then
            chmod -R u+w "$outside_canary_root" 2>/dev/null || true
            rm -rf -- "$outside_canary_root"
        fi
    }
    trap cleanup_runtime_canaries EXIT
    chmod -R a-w "$review_root"
    chmod a-w "$outside_canary" "$developer_instructions_file" "$review_input" "$model_catalog"

    if ! readonly_reviewer_seatbelt_run "$sandbox_exec" "$seatbelt_profile" "$codex_bin" "$sealed_root" "$arg0_root" "$runtime_tmp" "$auth_source" "$installation_id" /bin/cat "$allowed_canary" >/dev/null 2>&1; then
        echo "Reviewer Seatbelt profile did not admit the exact bundle." >&2
        exit 1
    fi
    if readonly_reviewer_seatbelt_run "$sandbox_exec" "$seatbelt_profile" "$codex_bin" "$sealed_root" "$arg0_root" "$runtime_tmp" "$auth_source" "$installation_id" /bin/cat "$outside_canary" >/dev/null 2>&1; then
        echo "Reviewer Seatbelt profile did not deny foreign temp data." >&2
        exit 1
    fi
    if readonly_reviewer_seatbelt_run "$sandbox_exec" "$seatbelt_profile" "$codex_bin" "$sealed_root" "$arg0_root" "$runtime_tmp" "$auth_source" "$installation_id" /bin/cat "$repo_root/AGENTS.md" >/dev/null 2>&1; then
        echo "Reviewer Seatbelt profile did not deny the original worktree." >&2
        exit 1
    fi

    codex_stderr="$runtime_tmp/codex.stderr"
    set +e
    readonly_reviewer_seatbelt_run "$sandbox_exec" "$seatbelt_profile" "$codex_bin" "$sealed_root" "$arg0_root" "$runtime_tmp" "$auth_source" "$installation_id" \
        "${reviewer_environment[@]}" "$codex_bin" --ask-for-approval "$codex_approval_policy" \
            --sandbox "$codex_sandbox_mode" exec \
            --ignore-user-config \
            --ignore-rules \
            --strict-config \
            --ephemeral \
            --skip-git-repo-check \
            --color never \
            --model "$model" \
            --output-schema "$control_root/$output_schema_path" \
            "${disable_arguments[@]}" \
            -c "model_catalog_json=\"$model_catalog\"" \
            -c "model_reasoning_effort=\"$reasoning\"" \
            -c "log_dir=\"$log_root\"" \
            -c "developer_instructions=$developer_instructions_toml" \
            -c 'project_root_markers=[]' \
            -c 'web_search="disabled"' \
            -c 'shell_environment_policy.inherit="none"' \
            -c 'mcp_servers={}' \
            -c 'agents.max_threads=1' \
            -c 'agents.max_depth=0' \
            -C "$sealed_root" \
            - < "$review_input" 2> "$codex_stderr" \
        | trusted_php "$control_root/scripts/agent/readonly_reviewer_contract.php" validate \
            --lens="$lens" \
            --base-sha="$base_sha" \
            --head-sha="$head_sha" \
            --changed-paths-json="$changed_paths_file"
    review_pipeline_status=("${PIPESTATUS[@]}")
    set -e
    if [[ "${review_pipeline_status[0]:-1}" -ne 0 || "${review_pipeline_status[1]:-1}" -ne 0 ]]; then
        echo "Reviewer isolated model call or output validation failed." >&2
        exit 1
    fi
)
