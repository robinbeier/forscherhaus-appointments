#!/bin/bash

# Machine contract: .codex/contracts/agent-workflow.json
# This file is data in the checkout, not an executable entry point. The Primary
# must materialize this exact file from the verified base commit with fixed
# /usr/bin/git, verify its blob and mode, and only then execute it with clean
# /bin/bash. Only that external boundary may authorize a payload launch.

set -euo pipefail

launcher_source_input="${TRUSTED_BASE_LAUNCHER_SOURCE_PATH:-}"
launcher_executed_source="${BASH_SOURCE[0]:-}"
if [[ "${TRUSTED_BASE_MATERIALIZED:-}" != "1" || "$launcher_source_input" != /* || \
    "$launcher_executed_source" != "$launcher_source_input" ]]; then
    echo "Trusted-base launcher must be externally materialized from the verified base." >&2
    exit 2
fi
unset TRUSTED_BASE_MATERIALIZED TRUSTED_BASE_LAUNCHER_SOURCE_PATH
unset BASH_ENV ENV CDPATH PYTHONHOME PYTHONPATH PHPRC PHP_INI_SCAN_DIR
PATH=/usr/bin:/bin:/usr/sbin:/sbin
export PATH

usage() {
    echo "Usage: trusted-base-launcher --repo-root=<absolute-path> --base-sha=<sha> --payload=reviewer -- <payload-options>" >&2
}

repo_root_input=""
base_sha=""
payload_name=""
payload_arguments=()
parsing_payload=false

for argument in "$@"; do
    if [[ "$parsing_payload" == true ]]; then
        payload_arguments+=("$argument")
        continue
    fi
    case "$argument" in
        --repo-root=*) repo_root_input="${argument#*=}" ;;
        --base-sha=*) base_sha="${argument#*=}" ;;
        --payload=*) payload_name="${argument#*=}" ;;
        --) parsing_payload=true ;;
        *) usage; exit 2 ;;
    esac
done

if [[ "$parsing_payload" != true ]]; then
    usage
    exit 2
fi
if [[ "$repo_root_input" != /* || ! "$base_sha" =~ ^[a-f0-9]{40}$ ]]; then
    echo "Trusted-base launcher requires an absolute repository root and full lowercase base commit." >&2
    exit 2
fi

git_bin=/usr/bin/git
python_bin=/usr/bin/python3

assert_system_tool() {
    local tool_path="$1" metadata="" mode=""
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

if ! assert_system_tool "$git_bin" || ! assert_system_tool "$python_bin"; then
    echo "Trusted-base launcher requires root-owned, non-writable system Git and Python." >&2
    exit 2
fi

trusted_python() {
    /usr/bin/env -i \
        PATH=/usr/bin:/bin:/usr/sbin:/sbin \
        LANG=C \
        LC_ALL=C \
        TMPDIR=/tmp \
        "$python_bin" -I -B "$@"
}

canonical_path() {
    trusted_python -c '
import os
import sys

resolved = os.path.realpath(sys.argv[1])
if not os.path.exists(resolved):
    raise SystemExit(1)
sys.stdout.write(resolved)
' "$1"
}

repo_root="$(canonical_path "$repo_root_input")" || {
    echo "Trusted-base launcher repository root is invalid." >&2
    exit 2
}
if [[ ! -d "$repo_root" ]]; then
    echo "Trusted-base launcher repository root is invalid." >&2
    exit 2
fi

trusted_git() {
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
        PATH=/usr/bin:/bin:/usr/sbin:/sbin \
        TMPDIR=/tmp \
        "$git_bin" \
        -c core.fsmonitor=false \
        -c core.hooksPath=/dev/null \
        -c core.untrackedCache=false \
        -c diff.external= \
        -c core.excludesfile=/dev/null \
        -C "$repo_root" "$@"
}

resolved_root="$(trusted_git rev-parse --show-toplevel 2>/dev/null)" || {
    echo "Trusted-base launcher must target a Git worktree." >&2
    exit 2
}
resolved_root="$(canonical_path "$resolved_root")" || exit 2
if [[ "$resolved_root" != "$repo_root" ]]; then
    echo "Trusted-base launcher repository root is not canonical." >&2
    exit 2
fi

resolved_base="$(trusted_git rev-parse --verify "${base_sha}^{commit}" 2>/dev/null)" || {
    echo "Trusted-base launcher base commit is unavailable." >&2
    exit 2
}
if [[ "$resolved_base" != "$base_sha" ]]; then
    echo "Trusted-base launcher base commit is not exact." >&2
    exit 2
fi

bootstrap_contract_path='.codex/contracts/agent-workflow.json'
parser_repository_path='scripts/agent/lib/trusted_base_bootstrap_contract.py'
parser_mode='0400'
case "$(/usr/bin/uname -s 2>/dev/null)" in
    Darwin) private_parent=/private/tmp ;;
    Linux) private_parent=/tmp ;;
    *)
        echo "Trusted-base launcher platform is unsupported." >&2
        exit 2
        ;;
esac
umask 077
parser_root="$(/usr/bin/mktemp -d "$private_parent/trusted-base-bootstrap.XXXXXX")" || exit 2
trap 'chmod -R u+w "$parser_root" 2>/dev/null || true; /bin/rm -rf -- "$parser_root"' EXIT
parser_target="$parser_root/contract-parser.py"
if ! trusted_git show "${base_sha}:${parser_repository_path}" > "$parser_target"; then
    echo "Trusted-base launcher contract parser is unavailable." >&2
    exit 2
fi
parser_blob="$(trusted_git hash-object --no-filters "$parser_target")" || exit 2
parser_tree_entry="$(trusted_git ls-tree "$base_sha" -- "$parser_repository_path")" || exit 2
IFS=$' \t' read -r parser_tree_mode parser_tree_type parser_tree_blob parser_tree_path <<< "$parser_tree_entry"
if [[ "$parser_tree_mode" != '100644' || "$parser_tree_type" != 'blob' || \
    "$parser_tree_path" != "$parser_repository_path" || ! "$parser_tree_blob" =~ ^[a-f0-9]{40}$ || \
    "$parser_blob" != "$parser_tree_blob" ]] || \
    ! trusted_git show "${base_sha}:${parser_repository_path}" | /usr/bin/cmp -s - "$parser_target"; then
    echo "Trusted-base launcher contract parser blob is invalid." >&2
    exit 2
fi
chmod "$parser_mode" "$parser_target"
if ! trusted_python -c '
import os
import stat
import sys

metadata = os.stat(sys.argv[1], follow_symlinks=False)
if (
    os.path.islink(sys.argv[1])
    or not stat.S_ISREG(metadata.st_mode)
    or metadata.st_uid != os.geteuid()
    or stat.S_IMODE(metadata.st_mode) != int(sys.argv[2], 8)
):
    raise SystemExit(1)
' "$parser_target" "$parser_mode"; then
    echo "Trusted-base launcher contract parser mode is unsafe." >&2
    exit 2
fi
bootstrap_record="$(
    trusted_git show "${base_sha}:${bootstrap_contract_path}" 2>/dev/null | trusted_python "$parser_target" "$payload_name"
)" || {
    echo "Trusted-base launcher bootstrap manifest is invalid." >&2
    exit 2
}
bootstrap_contract_path="$(/usr/bin/printf '%s\n' "$bootstrap_record" | /usr/bin/sed -n '1p')"
launcher_repository_path="$(/usr/bin/printf '%s\n' "$bootstrap_record" | /usr/bin/sed -n '2p')"
launcher_runtime_mode="$(/usr/bin/printf '%s\n' "$bootstrap_record" | /usr/bin/sed -n '3p')"
parser_repository_path="$(/usr/bin/printf '%s\n' "$bootstrap_record" | /usr/bin/sed -n '4p')"
parser_mode="$(/usr/bin/printf '%s\n' "$bootstrap_record" | /usr/bin/sed -n '5p')"
shared_runtime_path="$(/usr/bin/printf '%s\n' "$bootstrap_record" | /usr/bin/sed -n '6p')"
shared_runtime_mode="$(/usr/bin/printf '%s\n' "$bootstrap_record" | /usr/bin/sed -n '7p')"
payload_path="$(/usr/bin/printf '%s\n' "$bootstrap_record" | /usr/bin/sed -n '8p')"
payload_runtime_mode="$(/usr/bin/printf '%s\n' "$bootstrap_record" | /usr/bin/sed -n '9p')"
payload_environment_profile="$(/usr/bin/printf '%s\n' "$bootstrap_record" | /usr/bin/sed -n '10p')"
if [[ "$parser_repository_path" != 'scripts/agent/lib/trusted_base_bootstrap_contract.py' || "$parser_mode" != '0400' ]]; then
    echo "Trusted-base launcher contract parser is not allowlisted." >&2
    exit 2
fi
if [[ -z "$payload_path" || "$payload_environment_profile" != "$payload_name" ]]; then
    echo "Trusted-base launcher payload is not allowlisted." >&2
    exit 2
fi

launcher_source="$(canonical_path "$launcher_source_input")" || {
    echo "Trusted-base launcher materialization path is invalid." >&2
    exit 2
}
case "$launcher_source" in
    "$repo_root"|"$repo_root"/*)
        echo "Trusted-base launcher must be materialized outside the checkout." >&2
        exit 2
        ;;
esac
launcher_tree_entry="$(trusted_git ls-tree "$base_sha" -- "$launcher_repository_path")" || exit 2
IFS=$' \t' read -r launcher_mode launcher_type launcher_blob launcher_tree_path <<< "$launcher_tree_entry"
if [[ "$launcher_mode" != "100644" || "$launcher_type" != "blob" || \
    ! "$launcher_blob" =~ ^[a-f0-9]{40}$ || \
    "$launcher_tree_path" != "$launcher_repository_path" ]]; then
    echo "Trusted-base launcher base blob has an unsafe tree record." >&2
    exit 2
fi
if [[ "$(trusted_git hash-object --no-filters "$launcher_source")" != "$launcher_blob" ]] || \
    ! trusted_git show "${base_sha}:${launcher_repository_path}" | /usr/bin/cmp -s - "$launcher_source"; then
    echo "Trusted-base launcher is not the exact verified-base blob." >&2
    exit 2
fi
trusted_python -c '
import os
import stat
import sys

path = sys.argv[1]
metadata = os.stat(path, follow_symlinks=False)
if os.path.islink(path) or not stat.S_ISREG(metadata.st_mode):
    raise SystemExit(1)
if metadata.st_uid != os.geteuid() or stat.S_IMODE(metadata.st_mode) != int(sys.argv[2], 8):
    raise SystemExit(1)
' "$launcher_source" "$launcher_runtime_mode" || {
    echo "Trusted-base launcher materialization ownership or mode is unsafe." >&2
    exit 2
}

materialized_root="$(/usr/bin/mktemp -d "$private_parent/forscherhaus-trusted-base.XXXXXX")" || {
    echo "Trusted-base launcher could not create a private materialization root." >&2
    exit 2
}
cleanup_materialized_root() {
    chmod -R u+w "$materialized_root" 2>/dev/null || true
    /bin/rm -rf -- "$materialized_root"
    chmod -R u+w "$parser_root" 2>/dev/null || true
    /bin/rm -rf -- "$parser_root"
}
trap cleanup_materialized_root EXIT

materialize_exact_base_file() {
    local repository_path="$1" target_path="$2" runtime_mode="$3"
    local tree_entry='' tree_mode='' tree_type='' tree_blob='' tree_path='' materialized_blob=''

    tree_entry="$(trusted_git ls-tree "$base_sha" -- "$repository_path")" || return 1
    IFS=$' \t' read -r tree_mode tree_type tree_blob tree_path <<< "$tree_entry"
    if [[ "$tree_mode" != '100644' || "$tree_type" != 'blob' || \
        ! "$tree_blob" =~ ^[a-f0-9]{40}$ || "$tree_path" != "$repository_path" ]]; then
        return 1
    fi
    if ! trusted_git show "${base_sha}:${repository_path}" > "$target_path"; then
        return 1
    fi
    materialized_blob="$(trusted_git hash-object --no-filters "$target_path")" || return 1
    if [[ "$materialized_blob" != "$tree_blob" ]]; then
        return 1
    fi
    chmod "$runtime_mode" "$target_path"
    trusted_python -c '
import os
import stat
import sys

path, root, repository = map(os.path.realpath, sys.argv[1:4])
expected_mode = int(sys.argv[4], 8)
metadata = os.stat(path, follow_symlinks=False)
if os.path.islink(sys.argv[1]) or not stat.S_ISREG(metadata.st_mode):
    raise SystemExit(1)
if metadata.st_uid != os.geteuid() or stat.S_IMODE(metadata.st_mode) != expected_mode:
    raise SystemExit(1)
if os.path.commonpath([path, root]) != root:
    raise SystemExit(1)
if os.path.commonpath([path, repository]) == repository:
    raise SystemExit(1)
' "$target_path" "$materialized_root" "$repo_root" "$runtime_mode"
}

materialized_payload="$materialized_root/$(/usr/bin/basename -- "$payload_path")"
materialized_runtime="$materialized_root/$(/usr/bin/basename -- "$shared_runtime_path")"
if ! materialize_exact_base_file "$payload_path" "$materialized_payload" "$payload_runtime_mode" || \
    ! materialize_exact_base_file "$shared_runtime_path" "$materialized_runtime" "$shared_runtime_mode"; then
    echo "Trusted-base launcher private payload boundary is invalid." >&2
    exit 2
fi

account_record="$(trusted_python -c '
import os
import pwd
import sys

account = pwd.getpwuid(os.geteuid())
home = os.path.realpath(account.pw_dir)
if not account.pw_name or not os.path.isabs(home) or not os.path.isdir(home):
    raise SystemExit(2)
metadata = os.stat(home)
if os.geteuid() != 0 and metadata.st_uid != os.geteuid():
    raise SystemExit(2)
sys.stdout.write(f"{os.geteuid()}\n{account.pw_name}\n{home}\n")
')" || {
    echo "Trusted-base launcher OS account could not be resolved." >&2
    exit 2
}
reviewer_effective_uid="$(/usr/bin/printf '%s\n' "$account_record" | /usr/bin/sed -n '1p')"
reviewer_os_user="$(/usr/bin/printf '%s\n' "$account_record" | /usr/bin/sed -n '2p')"
reviewer_os_home="$(/usr/bin/printf '%s\n' "$account_record" | /usr/bin/sed -n '3p')"
if [[ -z "$reviewer_effective_uid" || -z "$reviewer_os_user" || -z "$reviewer_os_home" ]]; then
    echo "Trusted-base launcher OS account could not be resolved." >&2
    exit 2
fi

payload_prefix_arguments=()
payload_environment=(
    /usr/bin/env -i
    PATH=/usr/bin:/bin:/usr/sbin:/sbin
    TMPDIR=/tmp
    LANG=C
    LC_ALL=C
    TRUSTED_BASE_LAUNCHER=1
    TRUSTED_BASE_LAUNCHER_BASE_SHA="$base_sha"
    TRUSTED_BASE_BOOTSTRAP_CONTRACT_PATH="$bootstrap_contract_path"
    TRUSTED_BASE_BOOTSTRAP_PARSER_PATH="$parser_target"
    TRUSTED_BASE_BOOTSTRAP_PARSER_REPOSITORY_PATH="$parser_repository_path"
    TRUSTED_BASE_BOOTSTRAP_PARSER_MODE="$parser_mode"
    TRUSTED_BASE_BOOTSTRAP_PARSER_BLOB="$parser_blob"
    TRUSTED_BASE_LAUNCHER_PAYLOAD_ID="$payload_name"
    TRUSTED_BASE_LAUNCHER_PAYLOAD_REPOSITORY_PATH="$payload_path"
    TRUSTED_BASE_LAUNCHER_PAYLOAD_MODE="$payload_runtime_mode"
    TRUSTED_BASE_LAUNCHER_MATERIALIZED_PATH="$materialized_payload"
    TRUSTED_BASE_SHARED_RUNTIME_PATH="$materialized_runtime"
    TRUSTED_BASE_SHARED_RUNTIME_REPOSITORY_PATH="$shared_runtime_path"
    TRUSTED_BASE_SHARED_RUNTIME_MODE="$shared_runtime_mode"
)
case "$payload_environment_profile" in
    reviewer)
        payload_prefix_arguments=("--repo-root=$repo_root" "--base-sha=$base_sha")
        payload_environment+=(
            HOME="$reviewer_os_home"
            USER="$reviewer_os_user"
            LOGNAME="$reviewer_os_user"
            CODEX_HOME="$reviewer_os_home/.codex"
            REVIEWER_OS_HOME="$reviewer_os_home"
            REVIEWER_EFFECTIVE_UID="$reviewer_effective_uid"
        )
        ;;
esac

set +e
"${payload_environment[@]}" \
    /bin/bash --noprofile --norc "$materialized_runtime" "$materialized_payload" \
        "${payload_prefix_arguments[@]}" "${payload_arguments[@]}"
payload_status=$?
set -e
exit "$payload_status"
