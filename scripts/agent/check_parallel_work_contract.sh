#!/bin/sh

# Establish a clean, root-owned shell boundary before any repository-selected
# interpreter is resolved. Non-interactive POSIX sh does not evaluate BASH_ENV.
set -eu
unset BASH_ENV ENV CDPATH PYTHONHOME PYTHONPATH PHPRC PHP_INI_SCAN_DIR

bootstrap_python=/usr/bin/python3
if [ ! -x "$bootstrap_python" ]; then
    echo "Parallel-work system Python bootstrap is unavailable." >&2
    exit 2
fi
case "$(/usr/bin/uname -s 2>/dev/null)" in
    Darwin) bootstrap_python_metadata="$(/usr/bin/stat -f '%u:%OLp' "$bootstrap_python" 2>/dev/null)" ;;
    Linux) bootstrap_python_metadata="$(/usr/bin/stat -Lc '%u:%a' "$bootstrap_python" 2>/dev/null)" ;;
    *) bootstrap_python_metadata='' ;;
esac
bootstrap_python_mode="${bootstrap_python_metadata#*:}"
bootstrap_python_group_other="$(/usr/bin/printf '%s\n' "$bootstrap_python_mode" | /usr/bin/sed 's/.*\(..\)$/\1/')"
case "$bootstrap_python_metadata:$bootstrap_python_group_other" in
    0:[0-7][0-7][0-7]:[0145][0145]|0:[0-7][0-7][0-7][0-7]:[0145][0145]) ;;
    *)
        echo "Parallel-work system Python bootstrap ownership is unsafe." >&2
        exit 2
        ;;
esac

/usr/bin/sed '1,/^__ROB501_BASH_PAYLOAD__$/d' "$0" | \
    /usr/bin/env -i \
        PATH=/usr/bin:/bin:/usr/sbin:/sbin \
        TMPDIR=/tmp \
        LANG=C \
        LC_ALL=C \
        /bin/bash --noprofile --norc -s -- "$0" "$@"
exit $?

__ROB501_BASH_PAYLOAD__
#!/bin/bash

set -euo pipefail

# With `bash -s -- <runner> ...`, Bash retains its own `$0` and exposes the
# materialized declared-base runner as `$1`.
runner_source_input="${1:-}"
if [[ -z "$runner_source_input" ]]; then
    echo "Parallel-work validator trusted source path is unavailable." >&2
    exit 2
fi
shift

validator_checkout=''
manifest_path=''
forward_arguments=()
for argument in "$@"; do
    case "$argument" in
        --validator-checkout=*)
            if [[ -n "$validator_checkout" ]]; then
                echo "Parallel-work validator checkout may be supplied only once." >&2
                exit 2
            fi
            validator_checkout="${argument#--validator-checkout=}"
            ;;
        --manifest=*)
            if [[ -n "$manifest_path" ]]; then
                echo "Parallel-work manifest may be supplied only once." >&2
                exit 2
            fi
            manifest_path="${argument#--manifest=}"
            forward_arguments+=("$argument")
            ;;
        --repo-root=*|--verify-lane=*|--require-clean|--allow-dirty-precommit)
            forward_arguments+=("$argument")
            ;;
        *)
            echo "Unknown option." >&2
            exit 2
            ;;
    esac
done

if [[ -z "$validator_checkout" || "$validator_checkout" != /* ]]; then
    echo "Parallel-work validator checkout must be supplied as an absolute path." >&2
    exit 2
fi
validator_checkout="$(CDPATH= cd -- "$validator_checkout" 2>/dev/null && /bin/pwd -P)" || {
    echo "Parallel-work validator checkout is invalid." >&2
    exit 2
}

python_bin=/usr/bin/python3
if [[ ! -x "$python_bin" ]]; then
    echo "System Python is unavailable on the fixed parallel-work validator path." >&2
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

if [[ -z "$manifest_path" ]]; then
    echo "Missing --manifest." >&2
    exit 2
fi
if ! manifest_base="$(
    trusted_python -c '
import json
import re
import sys

try:
    with open(sys.argv[1], "r", encoding="utf-8") as stream:
        manifest = json.load(stream)
except (OSError, ValueError, UnicodeError):
    print("Parallel-work manifest is not valid JSON.", file=sys.stderr)
    raise SystemExit(2)
base = manifest.get("base_sha") if isinstance(manifest, dict) else None
if not isinstance(base, str) or re.fullmatch(r"[a-f0-9]{40}", base) is None:
    print("Parallel-work input has an invalid shape.", file=sys.stderr)
    raise SystemExit(2)
sys.stdout.write(base)
' "$manifest_path"
)"; then
    exit 2
fi

trusted_git=/usr/bin/git
if [[ ! -x "$trusted_git" ]]; then
    echo "Git is unavailable on the fixed parallel-work validator path." >&2
    exit 2
fi

trusted_git_run() {
    /usr/bin/env -i \
        GIT_ATTR_NOSYSTEM=1 \
        GIT_CONFIG_GLOBAL=/dev/null \
        GIT_CONFIG_NOSYSTEM=1 \
        GIT_NO_LAZY_FETCH=1 \
        GIT_NO_REPLACE_OBJECTS=1 \
        GIT_OPTIONAL_LOCKS=0 \
        GIT_PAGER=cat \
        GIT_TERMINAL_PROMPT=0 \
        LANG=C \
        LC_ALL=C \
        PATH=/usr/bin:/bin:/usr/sbin:/sbin \
        TMPDIR=/tmp \
        "$trusted_git" \
        -c core.fsmonitor=false \
        -c core.hooksPath=/dev/null \
        -c core.untrackedCache=false \
        -c diff.external= \
        -c core.excludesfile=/dev/null \
        -C "$validator_checkout" "$@"
}

canonical_repository_url='https://github.com/robinbeier/forscherhaus-appointments.git'
canonical_main_record="$(
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
        "$trusted_git" \
        -c core.fsmonitor=false \
        -c core.hooksPath=/dev/null \
        -c core.untrackedCache=false \
        -c credential.helper= \
        -c diff.external= \
        -c http.proxy= \
        -c https.proxy= \
        -c core.excludesfile=/dev/null \
        ls-remote --exit-code --refs "$canonical_repository_url" refs/heads/main 2>/dev/null
)" || {
    echo "Parallel-work canonical main is unavailable." >&2
    exit 2
}
if [[ ! "$canonical_main_record" =~ ^([a-f0-9]{40})$'\t'refs/heads/main$ ]]; then
    echo "Parallel-work canonical main is invalid." >&2
    exit 2
fi
canonical_main_sha="${BASH_REMATCH[1]}"

local_origin_main="$(trusted_git_run rev-parse --verify refs/remotes/origin/main 2>/dev/null)" || {
    echo "Parallel-work local origin/main is unavailable." >&2
    exit 2
}
if [[ ! "$local_origin_main" =~ ^[a-f0-9]{40}$ || "$local_origin_main" != "$canonical_main_sha" ]]; then
    /usr/bin/printf '%s\n' '{"schema_version":1,"status":"fail","errors":["canonical_main_mismatch"]}'
    exit 1
fi

resolved_checkout="$(trusted_git_run rev-parse --show-toplevel 2>/dev/null)" || {
    echo "Parallel-work validator checkout is invalid." >&2
    exit 2
}
resolved_checkout="$(CDPATH= cd -- "$resolved_checkout" 2>/dev/null && /bin/pwd -P)" || {
    echo "Parallel-work validator checkout is invalid." >&2
    exit 2
}
if [[ "$resolved_checkout" != "$validator_checkout" ]]; then
    echo "Parallel-work validator checkout is invalid." >&2
    exit 2
fi

validator_head="$(trusted_git_run rev-parse --verify HEAD 2>/dev/null)" || {
    echo "Parallel-work validator base is unavailable." >&2
    exit 2
}
if [[ ! "$validator_head" =~ ^[a-f0-9]{40}$ ]]; then
    echo "Parallel-work validator base is invalid." >&2
    exit 2
fi
if [[ "$validator_head" != "$manifest_base" || "$manifest_base" != "$canonical_main_sha" ]]; then
    /usr/bin/printf '%s\n' '{"schema_version":1,"status":"fail","errors":["validator_base_mismatch"]}'
    exit 1
fi

if [[ -L "$runner_source_input" ]]; then
    echo "Parallel-work validator runner is invalid." >&2
    exit 2
fi
runner_directory="$(CDPATH= cd -- "$(/usr/bin/dirname -- "$runner_source_input")" 2>/dev/null && /bin/pwd -P)" || {
    echo "Parallel-work validator runner is invalid." >&2
    exit 2
}
runner_source="$runner_directory/$(/usr/bin/basename -- "$runner_source_input")"
if [[ ! -f "$runner_source" ]]; then
    echo "Parallel-work validator runner is invalid." >&2
    exit 2
fi
case "$runner_source" in
    "$validator_checkout"|"$validator_checkout"/*)
        echo "Parallel-work validator runner must be materialized outside the checkout." >&2
        exit 1
        ;;
esac
if ! trusted_git_run show "${validator_head}:scripts/agent/check_parallel_work_contract.sh" | \
    /usr/bin/cmp -s - "$runner_source"; then
    echo "Parallel-work validator runner is not the declared-base blob." >&2
    exit 1
fi

trusted_root="$(/usr/bin/mktemp -d /tmp/parallel-work-validator.XXXXXX)" || {
    echo "Parallel-work validator trust bundle could not be created." >&2
    exit 2
}
trap '/bin/rm -rf "$trusted_root"' EXIT

trusted_paths=(
    .codex/contracts/agent-workflow.json
    scripts/agent/check_parallel_work_contract.sh
    scripts/agent/check_parallel_work_contract.php
    scripts/agent/verify_trusted_php_runtime.py
    scripts/agent/lib/ParallelWorkContract.php
    scripts/agent/lib/ParallelWorkOwnershipContract.php
    scripts/agent/lib/ParallelWorkPolicyContract.php
    scripts/agent/lib/RepoPath.php
)
for path in "${trusted_paths[@]}"; do
    /bin/mkdir -p "$trusted_root/$(/usr/bin/dirname -- "$path")"
    if ! trusted_git_run show "${validator_head}:${path}" > "$trusted_root/$path"; then
        echo "Parallel-work validator base source is unavailable." >&2
        exit 2
    fi
done

validator_os_name="$(/usr/bin/uname -s 2>/dev/null)" || {
    echo "Parallel-work validator operating system is unavailable." >&2
    exit 2
}
validator_os_arch="$(/usr/bin/uname -m 2>/dev/null)" || {
    echo "Parallel-work validator architecture is unavailable." >&2
    exit 2
}
validator_platform="$validator_os_name-$validator_os_arch"
trusted_php="$(
    trusted_python "$trusted_root/scripts/agent/verify_trusted_php_runtime.py" \
        --contract="$trusted_root/.codex/contracts/agent-workflow.json" \
        --platform="$validator_platform" \
        --materialize-root="$trusted_root/php-runtime"
)" || exit $?
if [[ "$trusted_php" != /* || ! -x "$trusted_php" ]]; then
    echo "Parallel-work trusted PHP runtime attestation is invalid." >&2
    exit 2
fi

/usr/bin/env -i \
    PATH=/usr/bin:/bin:/usr/sbin:/sbin \
    LANG=C \
    LC_ALL=C \
    TMPDIR=/tmp \
    PARALLEL_WORK_VALIDATOR_CHECKOUT_ROOT="$validator_checkout" \
    "$trusted_php" -n -d auto_prepend_file= -d auto_append_file= \
    "$trusted_root/scripts/agent/check_parallel_work_contract.php" "${forward_arguments[@]}"
