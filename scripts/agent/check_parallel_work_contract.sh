#!/bin/bash

set -euo pipefail

if [[ "${TRUSTED_BASE_LAUNCHER:-}" != "1" ]]; then
    echo "Parallel-work validator must be launched from the exact-base trusted launcher." >&2
    exit 2
fi

# The launcher executes one private script assembled from the verified shared
# runtime and this payload, then supplies the materialized payload path as `$1`.
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
if ! declare -F trusted_base_payload_initialize >/dev/null 2>&1; then
    echo "Parallel-work validator requires the verified shared payload runtime." >&2
    exit 2
fi
trusted_base_payload_initialize \
    'scripts/agent/check_parallel_work_contract.sh' \
    "$runner_source_input" "$validator_checkout" "${TRUSTED_BASE_LAUNCHER_BASE_SHA:-}" || exit $?
validator_checkout="$trusted_base_repo_root"

trusted_python() {
    trusted_base_python "$@"
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
trusted_git_run() {
    trusted_base_git "$@"
}

canonical_repository_url='https://github.com/robinbeier/forscherhaus-appointments.git'
canonical_main_record="$(
    trusted_base_remote_git ls-remote --exit-code --refs \
        "$canonical_repository_url" refs/heads/main 2>/dev/null
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
if [[ "${TRUSTED_BASE_LAUNCHER_BASE_SHA:-}" != "$validator_head" ]]; then
    echo "Parallel-work validator is not bound to the trusted launcher base." >&2
    exit 2
fi

trusted_root="$(/usr/bin/mktemp -d /tmp/parallel-work-validator.XXXXXX)" || {
    echo "Parallel-work validator trust bundle could not be created." >&2
    exit 2
}
trap '/bin/rm -rf "$trusted_root"' EXIT

contract_relative_path=.codex/contracts/agent-workflow.json
if ! trusted_base_materialize_declared_paths \
    "$trusted_root" "$contract_relative_path" 'parallel_work.validator_bootstrap_paths'; then
    echo "Parallel-work validator bootstrap-path policy is invalid." >&2
    exit 2
fi

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
        --contract="$trusted_root/$contract_relative_path" \
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
