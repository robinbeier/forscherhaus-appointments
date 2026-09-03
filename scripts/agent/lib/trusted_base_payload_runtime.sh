#!/bin/bash

# Shared exact-base payload bootstrap. This file is data in the checkout. The
# trusted launcher materializes and verifies it beside the selected payload
# before either reviewer or parallel-work code may source it.

if [[ "${TRUSTED_BASE_LAUNCHER:-}" != '1' ]]; then
    echo 'Trusted-base shared runtime must be assembled by the exact-base launcher.' >&2
    exit 2
fi

trusted_base_system_path=/usr/bin:/bin:/usr/sbin:/sbin
trusted_base_git_bin=/usr/bin/git
trusted_base_python_bin=/usr/bin/python3
trusted_base_repo_root=''
trusted_base_base_sha=''

trusted_base_assert_system_tool() {
    local tool_path="$1" metadata='' mode=''
    if [[ ! -x "$tool_path" || ! -f "$tool_path" ]]; then
        return 1
    fi
    case "$(/usr/bin/uname -s 2>/dev/null)" in
        Darwin) metadata="$(/usr/bin/stat -Lf '%u:%OLp' "$tool_path" 2>/dev/null)" ;;
        Linux) metadata="$(/usr/bin/stat -Lc '%u:%a' "$tool_path" 2>/dev/null)" ;;
        *) return 1 ;;
    esac
    mode="${metadata#*:}"
    [[ "$metadata" =~ ^0:[0-7]{3,4}$ ]] || return 1
    (( (8#$mode & 0022) == 0 ))
}

trusted_base_python() {
    /usr/bin/env -i \
        PATH="$trusted_base_system_path" \
        LANG=C \
        LC_ALL=C \
        TMPDIR=/tmp \
        "$trusted_base_python_bin" -I -B "$@"
}

trusted_base_canonical_path() {
    trusted_base_python -c '
import os
import sys

resolved = os.path.realpath(sys.argv[1])
if not os.path.exists(resolved):
    raise SystemExit(1)
sys.stdout.write(resolved)
' "$1"
}

trusted_base_git() {
    /usr/bin/env -i \
        GIT_ATTR_NOSYSTEM=1 \
        GIT_CONFIG_GLOBAL=/dev/null \
        GIT_CONFIG_NOSYSTEM=1 \
        GIT_CONFIG_SYSTEM=/dev/null \
        GIT_NO_LAZY_FETCH=1 \
        GIT_NO_REPLACE_OBJECTS=1 \
        GIT_OPTIONAL_LOCKS=0 \
        GIT_PAGER=cat \
        GIT_TERMINAL_PROMPT=0 \
        LANG=C \
        LC_ALL=C \
        PATH="$trusted_base_system_path" \
        TMPDIR=/tmp \
        "$trusted_base_git_bin" \
        -c core.askPass= \
        -c core.fsmonitor=false \
        -c core.hooksPath=/dev/null \
        -c core.untrackedCache=false \
        -c credential.helper= \
        -c diff.external= \
        -c http.proxy= \
        -c https.proxy= \
        -c pager.diff=false \
        -c core.excludesfile=/dev/null \
        -C "$trusted_base_repo_root" "$@"
}

trusted_base_remote_git() {
    /usr/bin/env -i \
        GIT_ATTR_NOSYSTEM=1 \
        GIT_CONFIG_GLOBAL=/dev/null \
        GIT_CONFIG_NOSYSTEM=1 \
        GIT_CONFIG_SYSTEM=/dev/null \
        GIT_DIR=/dev/null \
        GIT_NO_LAZY_FETCH=1 \
        GIT_NO_REPLACE_OBJECTS=1 \
        GIT_OPTIONAL_LOCKS=0 \
        GIT_PAGER=cat \
        GIT_TERMINAL_PROMPT=0 \
        LANG=C \
        LC_ALL=C \
        PATH="$trusted_base_system_path" \
        TMPDIR=/tmp \
        "$trusted_base_git_bin" \
        -c core.askPass= \
        -c core.fsmonitor=false \
        -c core.hooksPath=/dev/null \
        -c core.untrackedCache=false \
        -c credential.helper= \
        -c diff.external= \
        -c http.proxy= \
        -c https.proxy= \
        -c pager.diff=false \
        -c core.excludesfile=/dev/null \
        -C /tmp "$@"
}

# Post-dispatch exact-base blob-type gate shared by both payloads.
trusted_base_declared_tree_entry=''
trusted_base_assert_declared_blob() {
    local repository_path="$1"
    local tree_entry='' tree_header='' tree_path=''

    # A pathspec can resolve to more than one tree record (for example when a
    # caller supplies an ambiguous path).  Require one, and only one, exact
    # regular-file blob record before allowing git show to consume it.
    tree_entry="$(trusted_base_git ls-tree "$trusted_base_base_sha" -- "$repository_path")" || return 1
    tree_header="${tree_entry%%$'\t'*}"
    tree_path="${tree_entry#*$'\t'}"
    if [[ -z "$tree_entry" || "$tree_entry" == *$'\n'* || "$tree_path" != "$repository_path" ||
        ! "$tree_header" =~ ^100644[[:space:]]blob[[:space:]][a-f0-9]{40}$ ]]; then
        return 1
    fi
    trusted_base_declared_tree_entry="$tree_entry"
}

trusted_base_assert_materialized_blob() {
    local materialized_path="$1" repository_path="$2" expected_mode="$3"
    local expected_blob="${4:-}"
    local tree_entry='' tree_mode='' tree_type='' tree_blob='' tree_path=''

    trusted_base_assert_declared_blob "$repository_path" || return 1
    tree_entry="$trusted_base_declared_tree_entry"
    IFS=$' \t' read -r tree_mode tree_type tree_blob tree_path <<< "$tree_entry"
    if [[ -n "$expected_blob" && "$tree_blob" != "$expected_blob" ]] || \
        [[ "$(trusted_base_git hash-object --no-filters "$materialized_path")" != "$tree_blob" ]] || \
        ! trusted_base_git show "${trusted_base_base_sha}:${repository_path}" | /usr/bin/cmp -s - "$materialized_path"; then
        return 1
    fi
    trusted_base_python -c '
import os
import stat
import sys

path, repository, expected_mode = sys.argv[1], os.path.realpath(sys.argv[2]), int(sys.argv[3], 8)
canonical = os.path.realpath(path)
metadata = os.stat(path, follow_symlinks=False)
if os.path.islink(path) or not stat.S_ISREG(metadata.st_mode):
    raise SystemExit(1)
if metadata.st_uid != os.geteuid() or stat.S_IMODE(metadata.st_mode) != expected_mode:
    raise SystemExit(1)
if os.path.commonpath([canonical, repository]) == repository:
    raise SystemExit(1)
' "$materialized_path" "$trusted_base_repo_root" "$expected_mode"
}

trusted_base_assert_bootstrap_manifest() {
    local expected_payload_id="$1" contract_path="$2" payload_path="$3" payload_mode="$4"
    local runtime_path="$5" runtime_mode="$6"
    local parser_source="${TRUSTED_BASE_BOOTSTRAP_PARSER_PATH:-}"
    local parser_path="${TRUSTED_BASE_BOOTSTRAP_PARSER_REPOSITORY_PATH:-}"
    local parser_mode="${TRUSTED_BASE_BOOTSTRAP_PARSER_MODE:-}"
    local parser_blob="${TRUSTED_BASE_BOOTSTRAP_PARSER_BLOB:-}"

    if [[ "$parser_source" != /* || "$parser_path" != 'scripts/agent/lib/trusted_base_bootstrap_contract.py' || \
        "$parser_mode" != '0400' || ! "$parser_blob" =~ ^[a-f0-9]{40}$ ]]; then
        return 1
    fi
    if ! trusted_base_assert_materialized_blob "$parser_source" "$parser_path" "$parser_mode" "$parser_blob"; then
        return 1
    fi
    # Reattest the contract tree entry before the runtime consumes its bytes;
    # the launcher performs the corresponding independent pre-dispatch check.
    trusted_base_assert_declared_blob "$contract_path" || return 1
    if ! trusted_base_git show "${trusted_base_base_sha}:${contract_path}" 2>/dev/null | \
        trusted_base_python "$parser_source" "$expected_payload_id" >/dev/null; then
        return 1
    fi

    # Keep an independent structural cross-check after the canonical parser.
    # This deliberate implementation diversity is a security floor: parser
    # drift must fail both checks rather than silently redefining its own trust
    # inputs. Do not consolidate it without a separately reviewed replacement.
    trusted_base_git show "${trusted_base_base_sha}:${contract_path}" 2>/dev/null | trusted_base_python -c '
import json
import sys

try:
    contract = json.load(sys.stdin)
    bootstrap = contract["trusted_base_bootstrap"]
    launcher = bootstrap["launcher"]
    parser = bootstrap["contract_parser"]
    runtime = bootstrap["shared_runtime"]
    payloads = bootstrap["payloads"]
    payload = payloads[sys.argv[1]]
except (KeyError, TypeError, ValueError, UnicodeError):
    raise SystemExit(1)
if set(bootstrap) != {"schema_version", "contract_path", "launcher", "contract_parser", "shared_runtime", "payloads"}:
    raise SystemExit(1)
if bootstrap["schema_version"] != 2 or bootstrap["contract_path"] != sys.argv[2]:
    raise SystemExit(1)
if set(payloads) != {"reviewer", "parallel"}:
    raise SystemExit(1)
if set(launcher) != {"path", "mode"} or set(runtime) != {"path", "mode"}:
    raise SystemExit(1)
if set(payload) != {"path", "mode", "environment_profile"}:
    raise SystemExit(1)
if launcher != {"path": "scripts/agent/trusted_base_launcher.sh", "mode": "0500"}:
    raise SystemExit(1)
if parser != {"path": "scripts/agent/lib/trusted_base_bootstrap_contract.py", "mode": "0400"}:
    raise SystemExit(1)
if runtime != {"path": "scripts/agent/lib/trusted_base_payload_runtime.sh", "mode": "0400"}:
    raise SystemExit(1)
if payloads != {
    "reviewer": {
        "path": "scripts/agent/run_readonly_reviewer.sh",
        "mode": "0500",
        "environment_profile": "reviewer",
    },
    "parallel": {
        "path": "scripts/agent/check_parallel_work_contract.sh",
        "mode": "0500",
        "environment_profile": "parallel",
    },
}:
    raise SystemExit(1)
if runtime != {"path": sys.argv[5], "mode": sys.argv[6]}:
    raise SystemExit(1)
if payload != {"path": sys.argv[3], "mode": sys.argv[4], "environment_profile": sys.argv[1]}:
    raise SystemExit(1)
' "$expected_payload_id" "$contract_path" "$payload_path" "$payload_mode" "$runtime_path" "$runtime_mode"
}

trusted_base_materialize_declared_paths() {
    local target_root="$1" contract_path="$2" selector="$3"
    local paths_output='' path=''

    trusted_base_python -c '
import os
import stat
import sys

root, repository = map(os.path.realpath, sys.argv[1:3])
metadata = os.stat(root, follow_symlinks=False)
if os.path.islink(sys.argv[1]) or not stat.S_ISDIR(metadata.st_mode):
    raise SystemExit(1)
if metadata.st_uid != os.geteuid() or stat.S_IMODE(metadata.st_mode) & 0o077:
    raise SystemExit(1)
if os.path.commonpath([root, repository]) == repository:
    raise SystemExit(1)
' "$target_root" "$trusted_base_repo_root" || return 1

    trusted_base_assert_declared_blob "$contract_path" || return 1
    /bin/mkdir -p "$target_root/$(/usr/bin/dirname -- "$contract_path")"
    if ! trusted_base_git show "${trusted_base_base_sha}:${contract_path}" > "$target_root/$contract_path"; then
        return 1
    fi
    paths_output="$(trusted_base_python -c '
import json
import re
import sys

try:
    with open(sys.argv[1], "r", encoding="utf-8") as stream:
        value = json.load(stream)
    for segment in sys.argv[2].split("."):
        value = value[segment]
except (KeyError, OSError, TypeError, ValueError, UnicodeError):
    raise SystemExit(1)
if not isinstance(value, list) or not value or sys.argv[3] not in value:
    raise SystemExit(1)
seen = set()
for path in value:
    if (
        not isinstance(path, str)
        or not path
        or path.startswith("/")
        or path.startswith(":")
        or path.endswith("/")
        or "\\" in path
        or any(character in path for character in "*?[]")
        or re.search(r"[\x00-\x1f\x7f]", path)
        or any(segment in ("", ".", "..") for segment in path.split("/"))
        or path in seen
    ):
        raise SystemExit(1)
    seen.add(path)
    print(path)
' "$target_root/$contract_path" "$selector" "$contract_path")" || return 1

    # Validate every declared path before materializing any of them.  This
    # keeps a malformed, symlink, tree, gitlink, missing, or ambiguous entry
    # from producing a partially populated bootstrap boundary.
    while IFS= read -r path || [[ -n "$path" ]]; do
        if [[ -z "$path" ]]; then
            return 1
        fi
        trusted_base_assert_declared_blob "$path" || return 1
    done <<< "$paths_output"

    while IFS= read -r path || [[ -n "$path" ]]; do
        /bin/mkdir -p "$target_root/$(/usr/bin/dirname -- "$path")"
        if [[ "$path" != "$contract_path" ]] && \
            ! trusted_base_git show "${trusted_base_base_sha}:${path}" > "$target_root/$path"; then
            return 1
        fi
        chmod 0400 "$target_root/$path"
    done <<< "$paths_output"
}

trusted_base_payload_initialize() {
    local expected_payload_id="$1" runner_source_input="$2" requested_repo_root="$3" requested_base_sha="$4"
    local runtime_source_input="${TRUSTED_BASE_SHARED_RUNTIME_PATH:-}"
    local contract_path="${TRUSTED_BASE_BOOTSTRAP_CONTRACT_PATH:-}"
    local payload_path="${TRUSTED_BASE_LAUNCHER_PAYLOAD_REPOSITORY_PATH:-}"
    local payload_mode="${TRUSTED_BASE_LAUNCHER_PAYLOAD_MODE:-}"
    local runtime_path="${TRUSTED_BASE_SHARED_RUNTIME_REPOSITORY_PATH:-}"
    local runtime_mode="${TRUSTED_BASE_SHARED_RUNTIME_MODE:-}"
    local resolved_root='' resolved_base='' runner_source='' runtime_source=''

    if [[ "${TRUSTED_BASE_LAUNCHER:-}" != '1' || \
        "${TRUSTED_BASE_LAUNCHER_PAYLOAD_ID:-}" != "$expected_payload_id" || \
        "${TRUSTED_BASE_LAUNCHER_BASE_SHA:-}" != "$requested_base_sha" || \
        "$contract_path" != '.codex/contracts/agent-workflow.json' || \
        "$payload_mode" != '0500' || "$runtime_mode" != '0400' || \
        "$runner_source_input" != /* || "$runtime_source_input" != /* || \
        "$requested_repo_root" != /* || ! "$requested_base_sha" =~ ^[a-f0-9]{40}$ ]]; then
        echo 'Trusted-base payload is not bound to the launcher context.' >&2
        return 2
    fi
    if ! trusted_base_assert_system_tool "$trusted_base_git_bin" || \
        ! trusted_base_assert_system_tool "$trusted_base_python_bin"; then
        echo 'Trusted-base payload requires root-owned, non-writable system Git and Python.' >&2
        return 2
    fi

    trusted_base_repo_root="$(trusted_base_canonical_path "$requested_repo_root")" || {
        echo 'Trusted-base payload repository root is invalid.' >&2
        return 2
    }
    resolved_root="$(trusted_base_git rev-parse --show-toplevel 2>/dev/null)" || {
        echo 'Trusted-base payload must target a Git worktree.' >&2
        return 2
    }
    resolved_root="$(trusted_base_canonical_path "$resolved_root")" || return 2
    if [[ "$resolved_root" != "$trusted_base_repo_root" ]]; then
        echo 'Trusted-base payload repository root is not canonical.' >&2
        return 2
    fi
    resolved_base="$(trusted_base_git rev-parse --verify "${requested_base_sha}^{commit}" 2>/dev/null)" || {
        echo 'Trusted-base payload base commit is unavailable.' >&2
        return 2
    }
    if [[ "$resolved_base" != "$requested_base_sha" ]]; then
        echo 'Trusted-base payload base commit is not exact.' >&2
        return 2
    fi
    trusted_base_base_sha="$requested_base_sha"

    if ! trusted_base_assert_bootstrap_manifest \
        "$expected_payload_id" "$contract_path" "$payload_path" "$payload_mode" "$runtime_path" "$runtime_mode"; then
        echo 'Trusted-base payload bootstrap manifest is invalid.' >&2
        return 2
    fi

    runner_source="$(trusted_base_canonical_path "$runner_source_input")" || return 2
    runtime_source="$(trusted_base_canonical_path "$runtime_source_input")" || return 2
    if [[ "${TRUSTED_BASE_LAUNCHER_MATERIALIZED_PATH:-}" != "$runner_source" ]]; then
        echo 'Trusted-base payload materialization is not bound to the launcher.' >&2
        return 2
    fi
    if ! trusted_base_assert_materialized_blob "$runner_source" "$payload_path" "$payload_mode"; then
        echo 'Trusted-base payload is not the exact declared-base blob.' >&2
        return 2
    fi
    if ! trusted_base_assert_materialized_blob \
        "$runtime_source" "$runtime_path" "$runtime_mode"; then
        echo 'Trusted-base shared runtime is not the exact declared-base blob.' >&2
        return 2
    fi
}

trusted_base_dispatch_payload() {
    local payload_source="${1:-}"
    if [[ -z "$payload_source" ]]; then
        echo 'Trusted-base shared runtime payload is unavailable.' >&2
        return 2
    fi
    shift
    if [[ "$payload_source" != "${TRUSTED_BASE_LAUNCHER_MATERIALIZED_PATH:-}" || \
        "$payload_source" != /* || ! -f "$payload_source" || -L "$payload_source" ]]; then
        echo 'Trusted-base shared runtime payload is not launcher-bound.' >&2
        return 2
    fi

    # Source only the exact materialized payload. The payload immediately calls
    # trusted_base_payload_initialize(), which re-attests both this runtime and
    # the payload against the declared base before repository work begins.
    source "$payload_source" "$payload_source" "$@"
}

if [[ "${BASH_SOURCE[0]:-}" == "$0" ]]; then
    trusted_base_dispatch_payload "$@"
fi
