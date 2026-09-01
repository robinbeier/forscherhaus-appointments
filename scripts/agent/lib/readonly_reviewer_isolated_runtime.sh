#!/bin/bash

# Loaded only from an exact review-base blob. This library owns the sealed
# Seatbelt runtime and model invocation; bundle construction stays separate.

readonly_reviewer_seatbelt_run() {
    "$sandbox_exec" \
        -D CODEX_BIN="$codex_bin" \
        -D SEALED_ROOT="$sealed_root" \
        -D ARG0_ROOT="$arg0_root" \
        -D RUNTIME_TMP="$runtime_tmp" \
        -D AUTH_FILE="$auth_source" \
        -D INSTALLATION_ID="$installation_id" \
        -f "$seatbelt_profile" \
        "$@"
}

readonly_reviewer_execute_isolated() {
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
        HOME="$reviewer_os_home"
        USER="${USER:-}"
        LOGNAME="${LOGNAME:-}"
        CODEX_HOME="$runtime_home"
        CODEX_SQLITE_HOME="$sqlite_root"
        TMPDIR="$runtime_tmp"
        TMP="$runtime_tmp"
        TEMP="$runtime_tmp"
        XDG_CACHE_HOME="$runtime_tmp"
        LANG="C.UTF-8"
    )

    cd "$sealed_root"

    model_catalog="$control_root/models.json"
    if ! readonly_reviewer_seatbelt_run "${reviewer_environment[@]}" "$codex_bin" debug models --bundled 2>/dev/null \
        | trusted_php "$control_root/scripts/agent/readonly_review_bundle.php" model-catalog \
            --model="$model" > "$model_catalog"; then
        echo "Reviewer tool-free model catalog could not be derived." >&2
        exit 1
    fi

    prompt_role_probe='UNTRUSTED-REVIEW-BUNDLE-PROBE'
    if ! readonly_reviewer_seatbelt_run "${reviewer_environment[@]}" "$codex_bin" \
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
    outside_canary_root="$(mktemp -d /private/tmp/forscherhaus-readonly-review-denied.XXXXXX)" || {
        echo "Reviewer external temp canary could not be created." >&2
        exit 2
    }
    outside_canary="$outside_canary_root/denied"
    : > "$outside_canary"
    home_canary="$(mktemp "$reviewer_os_home/.forscherhaus-readonly-review-denied.XXXXXX")" || {
        echo "Reviewer home canary could not be created." >&2
        exit 2
    }
    chmod -R a-w "$review_root"
    chmod a-w "$outside_canary" "$home_canary" "$developer_instructions_file" "$review_input" "$model_catalog"

    if ! readonly_reviewer_seatbelt_run /bin/cat "$allowed_canary" >/dev/null 2>&1; then
        echo "Reviewer Seatbelt profile did not admit the exact bundle." >&2
        exit 1
    fi
    if readonly_reviewer_seatbelt_run /bin/cat "$outside_canary" >/dev/null 2>&1; then
        echo "Reviewer Seatbelt profile did not deny foreign temp data." >&2
        exit 1
    fi
    if readonly_reviewer_seatbelt_run /bin/cat "$home_canary" >/dev/null 2>&1; then
        echo "Reviewer Seatbelt profile did not deny host-home data." >&2
        exit 1
    fi
    if readonly_reviewer_seatbelt_run /bin/cat "$repo_root/AGENTS.md" >/dev/null 2>&1; then
        echo "Reviewer Seatbelt profile did not deny the original worktree." >&2
        exit 1
    fi

    codex_stderr="$runtime_tmp/codex.stderr"
    set +e
    readonly_reviewer_seatbelt_run "${reviewer_environment[@]}" "$codex_bin" --ask-for-approval never exec \
            --dangerously-bypass-approvals-and-sandbox \
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
}
