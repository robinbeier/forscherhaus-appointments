#!/bin/bash

set -euo pipefail

if [[ "${TRUSTED_BASE_LAUNCHER:-}" != "1" ]]; then
    echo "Reviewer runner must be launched from the exact-base trusted launcher." >&2
    exit 2
fi

# The launcher executes the verified shared runtime, which dispatches only this
# separately attested payload and supplies its materialized path as `$1`.
runner_source_input="${1:-}"
if [[ -z "$runner_source_input" ]]; then
    echo "Reviewer trusted source path is unavailable." >&2
    exit 2
fi
shift

reviewer_system_path="/usr/bin:/bin:/usr/sbin:/sbin"
export PATH="$reviewer_system_path"

usage() {
    echo "Usage: $runner_source_input [--repo-root=<absolute-path>] [--codex-bin=<absolute-path>] [--diagnostic-bootstrap-only] --lens=<lens> --base-sha=<sha> --head-sha=<sha>" >&2
}

lens=""
base_sha=""
head_sha=""
requested_repo_root=""
requested_codex_bin=""
diagnostic_bootstrap_only=false
lens_seen=false
base_sha_seen=false
head_sha_seen=false
repo_root_seen=false
codex_bin_seen=false
diagnostic_seen=false

for argument in "$@"; do
    case "$argument" in
        --lens=*)
            if [[ "$lens_seen" == true ]]; then
                echo "Reviewer option may be supplied only once: --lens." >&2
                exit 2
            fi
            lens_seen=true
            lens="${argument#*=}"
            ;;
        --base-sha=*)
            if [[ "$base_sha_seen" == true ]]; then
                echo "Reviewer option may be supplied only once: --base-sha." >&2
                exit 2
            fi
            base_sha_seen=true
            base_sha="${argument#*=}"
            ;;
        --head-sha=*)
            if [[ "$head_sha_seen" == true ]]; then
                echo "Reviewer option may be supplied only once: --head-sha." >&2
                exit 2
            fi
            head_sha_seen=true
            head_sha="${argument#*=}"
            ;;
        --repo-root=*)
            if [[ "$repo_root_seen" == true ]]; then
                echo "Reviewer option may be supplied only once: --repo-root." >&2
                exit 2
            fi
            repo_root_seen=true
            requested_repo_root="${argument#*=}"
            ;;
        --codex-bin=*)
            if [[ "$codex_bin_seen" == true ]]; then
                echo "Reviewer option may be supplied only once: --codex-bin." >&2
                exit 2
            fi
            codex_bin_seen=true
            requested_codex_bin="${argument#*=}"
            ;;
        --diagnostic-bootstrap-only)
            if [[ "$diagnostic_seen" == true ]]; then
                echo "Reviewer option may be supplied only once: --diagnostic-bootstrap-only." >&2
                exit 2
            fi
            diagnostic_seen=true
            diagnostic_bootstrap_only=true
            ;;
        *) usage; exit 2 ;;
    esac
done

if ! declare -F trusted_base_payload_initialize >/dev/null 2>&1; then
    echo "Reviewer runner requires the verified shared payload runtime." >&2
    exit 2
fi
trusted_base_payload_initialize \
    'reviewer' "$runner_source_input" "$requested_repo_root" "$base_sha" || exit $?
repo_root="$trusted_base_repo_root"
cd "$repo_root"

trusted_python() {
    trusted_base_python "$@"
}

canonical_path() {
    trusted_base_canonical_path "$@"
}

trusted_git() {
    trusted_base_git "$@"
}

trusted_remote_git() {
    trusted_base_remote_git "$@"
}

sha_pattern='^[a-f0-9]{40}$'
if [[ ! "$base_sha" =~ $sha_pattern || ! "$head_sha" =~ $sha_pattern ]]; then
    echo "Reviewer SHAs must be full lowercase commit IDs." >&2
    exit 2
fi

review_base_ref="refs/remotes/origin/main"
# Keep the canonical remote as an immutable transport/identity floor. The
# reviewed repository's mutable configuration must not choose the source used
# to verify the live main commit.
canonical_main_remote='https://github.com/robinbeier/forscherhaus-appointments.git'
remote_main_record="$(
    trusted_remote_git ls-remote --exit-code --refs "$canonical_main_remote" refs/heads/main 2>/dev/null
)" || {
    echo "Reviewer live canonical main is unavailable." >&2
    exit 1
}
if [[ ! "$remote_main_record" =~ ^([a-f0-9]{40})$'\t'refs/heads/main$ ]]; then
    echo "Reviewer live canonical main is invalid." >&2
    exit 1
fi
remote_main_sha="${BASH_REMATCH[1]}"
tracking_main_sha="$(trusted_git rev-parse --verify "${review_base_ref}^{commit}" 2>/dev/null)" || {
    echo "Reviewer canonical base ref is unavailable; fetch origin/main before review." >&2
    exit 1
}
if [[ "$tracking_main_sha" != "$remote_main_sha" ]]; then
    echo "Reviewer origin/main does not match live canonical main; fetch before review." >&2
    exit 1
fi
trusted_git cat-file -e "${remote_main_sha}^{commit}" 2>/dev/null || {
    echo "Reviewer live canonical main commit is unavailable; fetch before review." >&2
    exit 1
}
trusted_git cat-file -e "${base_sha}^{commit}" 2>/dev/null || {
    echo "Reviewer base commit is unavailable." >&2
    exit 1
}
expected_base_sha="$(trusted_git merge-base "$remote_main_sha" "$head_sha")" || {
    echo "Reviewer canonical merge base could not be resolved." >&2
    exit 1
}
if [[ "$base_sha" != "$expected_base_sha" ]]; then
    echo "Reviewer base does not match the canonical origin/main merge base." >&2
    exit 1
fi

if [[ "$(trusted_git rev-parse HEAD)" != "$head_sha" ]]; then
    echo "Reviewer head does not match the checked-out HEAD." >&2
    exit 1
fi
trusted_git merge-base --is-ancestor "$base_sha" "$head_sha" || {
    echo "Reviewer base is not an ancestor of the reviewed head." >&2
    exit 1
}
if [[ -n "$(trusted_git status --porcelain --untracked-files=all)" ]]; then
    echo "Reviewer worktree must be clean." >&2
    exit 1
fi

bootstrap_review_paths=(.codex/config.toml)
bootstrap_review_paths_output="$({
    trusted_git show "${base_sha}:.codex/contracts/agent-workflow.json" 2>/dev/null | trusted_python -c '
import json
import re
import sys

try:
    reviewer = json.load(sys.stdin)["authority"]["reviewer"]
    profiles = reviewer["profiles"]
    paths = (
        reviewer["bootstrap_paths"]
        + [profiles[lens]["instructions"] for lens in sorted(profiles)]
        + reviewer["policy_context_paths"]
    )
except (KeyError, TypeError, ValueError):
    raise SystemExit(1)
if not isinstance(paths, list) or not paths:
    raise SystemExit(1)
seen = set()
for path in paths:
    if (
        not isinstance(path, str)
        or not path
        or path.startswith("/")
        or path.endswith("/")
        or "\\" in path
        or re.search(r"[\x00-\x1f\x7f]", path)
        or any(segment in ("", ".", "..") for segment in path.split("/"))
        or path in seen
    ):
        raise SystemExit(1)
    seen.add(path)
    print(path)
'
})" || {
    echo "Reviewer trusted-path policy is invalid; external bootstrap review is required." >&2
    exit 1
}
while IFS= read -r bootstrap_review_path || [[ -n "$bootstrap_review_path" ]]; do
    if [[ -z "$bootstrap_review_path" ]]; then
        echo "Reviewer trusted-path policy is invalid; external bootstrap review is required." >&2
        exit 1
    fi
    bootstrap_review_paths+=("$bootstrap_review_path")
done <<< "$bootstrap_review_paths_output"
bootstrap_review_paths+=(':(glob)**/AGENTS.md')
if ! trusted_git diff --quiet --no-ext-diff --no-textconv "$base_sha" "$head_sha" -- "${bootstrap_review_paths[@]}"; then
    echo "Reviewer runtime configuration changed; external bootstrap review is required." >&2
    exit 1
fi

assert_tree_has_only_regular_blobs() {
    local tree_sha="$1"
    trusted_git ls-tree -r -z "$tree_sha" | trusted_python -c '
import re
import sys

raw = sys.stdin.buffer.read()
if raw and not raw.endswith(b"\0"):
    raise SystemExit(1)
for entry in ([] if not raw else raw[:-1].split(b"\0")):
    match = re.match(rb"^([0-7]{6}) blob [0-9a-f]{40,64}\t", entry, re.DOTALL)
    if match is None or match.group(1) == b"120000":
        raise SystemExit(1)
'
}
if ! assert_tree_has_only_regular_blobs "$base_sha" || ! assert_tree_has_only_regular_blobs "$head_sha"; then
    echo "Reviewer exact commit tree contains a tracked symlink, gitlink, or invalid entry." >&2
    exit 1
fi

if [[ "$diagnostic_bootstrap_only" == true ]]; then
    if [[ -n "$requested_codex_bin" ]]; then
        echo "Reviewer bootstrap diagnostic must not receive a Codex binary." >&2
        exit 2
    fi
else
    if [[ -z "$requested_codex_bin" ]]; then
        echo "Reviewer Codex binary must be supplied explicitly by the primary." >&2
        exit 2
    fi
    if [[ "$requested_codex_bin" != /* || ! -x "$requested_codex_bin" ]]; then
        echo "Reviewer Codex binary must be an executable absolute path." >&2
        exit 2
    fi
    requested_codex_name="$(basename -- "$requested_codex_bin")"
    codex_source="$(canonical_path "$requested_codex_bin")" || {
        echo "Reviewer Codex binary target could not be resolved." >&2
        exit 2
    }
    if [[ ! -x "$codex_source" ]]; then
        echo "Reviewer Codex binary target must be executable." >&2
        exit 2
    fi
    case "$codex_source" in
        "$repo_root"|"$repo_root"/*)
            echo "Reviewer Codex binary must be outside the reviewed repository." >&2
            exit 2
            ;;
    esac
    if [[ "$requested_codex_name" != "codex" ]]; then
        echo "Reviewer Codex binary does not identify as Codex CLI." >&2
        exit 2
    fi
fi

reviewer_os_name="$(/usr/bin/uname -s 2>/dev/null)" || {
    echo "Reviewer operating system could not be resolved." >&2
    exit 2
}
reviewer_os_arch="$(/usr/bin/uname -m 2>/dev/null)" || {
    echo "Reviewer architecture could not be resolved." >&2
    exit 2
}
if [[ "$reviewer_os_name" != "Darwin" ]]; then
    echo "Reviewer isolation is available only through the pinned macOS Seatbelt contract." >&2
    exit 2
fi
reviewer_platform="$reviewer_os_name-$reviewer_os_arch"
sandbox_exec="/usr/bin/sandbox-exec"
if [[ ! -x "$sandbox_exec" || -L "$sandbox_exec" ]]; then
    echo "Reviewer Seatbelt launcher is unavailable or not canonical." >&2
    exit 2
fi
if ! trusted_python -c '
import os
import stat
import sys

metadata = os.stat(sys.argv[1], follow_symlinks=False)
if not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != 0 or metadata.st_mode & 0o022:
    raise SystemExit(1)
' "$sandbox_exec"; then
    echo "Reviewer Seatbelt launcher ownership is unsafe." >&2
    exit 2
fi

if [[ ! "${REVIEWER_EFFECTIVE_UID:-}" =~ ^[0-9]+$ ]]; then
    echo "Reviewer OS account ownership is unavailable." >&2
    exit 2
fi

umask 077
sealed_root="$(mktemp -d "/private/tmp/forscherhaus-readonly-review.XXXXXX")" || {
    echo "Reviewer sealed bundle root could not be created." >&2
    exit 2
}
case "$sealed_root" in
    /private/tmp/forscherhaus-readonly-review.*) ;;
    *)
        echo "Reviewer sealed bundle root escaped the private system temp parent." >&2
        exit 2
        ;;
esac
cleanup_sealed_review() {
    chmod -R u+w "$sealed_root" 2>/dev/null || true
    rm -rf -- "$sealed_root"
    if [[ -n "${diagnostic_outside_root:-}" ]]; then
        chmod -R u+w "$diagnostic_outside_root" 2>/dev/null || true
        rm -rf -- "$diagnostic_outside_root"
    fi
}
trap cleanup_sealed_review EXIT

control_root="$sealed_root/control"
review_root="$sealed_root/bundle"
mkdir -m 0700 "$control_root" "$review_root"

trusted_php() {
    /usr/bin/env -i \
        PATH=/usr/bin:/bin:/usr/sbin:/sbin \
        LANG=C \
        LC_ALL=C \
        TMPDIR=/tmp \
        "$php_bin" -n -d auto_prepend_file= -d auto_append_file= "$@"
}

contract_relative_path=".codex/contracts/agent-workflow.json"
if ! trusted_base_materialize_declared_paths \
    "$control_root" "$contract_relative_path" 'authority.reviewer.bootstrap_paths'; then
    echo "Reviewer bootstrap-path policy is invalid." >&2
    exit 1
fi

php_bin="$(
    trusted_python "$control_root/scripts/agent/verify_trusted_php_runtime.py" \
        --contract="$control_root/$contract_relative_path" \
        --platform="$reviewer_platform" \
        --materialize-root="$control_root/php-runtime"
)" || exit $?
if [[ "$php_bin" != /* || ! -x "$php_bin" ]]; then
    echo "Reviewer trusted PHP runtime attestation is invalid." >&2
    exit 2
fi

if [[ "$diagnostic_bootstrap_only" == true ]]; then
    seatbelt_profile="$control_root/scripts/agent/readonly-reviewer.sb"
    diagnostic_allowed="$control_root/bootstrap-diagnostic-readable"
    diagnostic_arg0_root="$sealed_root/diagnostic-arg0"
    diagnostic_runtime_tmp="$sealed_root/diagnostic-runtime"
    diagnostic_installation_id="$diagnostic_runtime_tmp/installation-id"
    diagnostic_outside_root="$(mktemp -d "/private/tmp/forscherhaus-reviewer-bootstrap-denied.XXXXXX")" || {
        echo "Reviewer bootstrap diagnostic could not create its denied canary root." >&2
        exit 2
    }
    diagnostic_outside="$diagnostic_outside_root/denied"
    if [[ ! -f "$repo_root/AGENTS.md" || -L "$repo_root/AGENTS.md" ]]; then
        echo "Reviewer bootstrap diagnostic worktree canary is unavailable." >&2
        exit 2
    fi
    mkdir -m 0700 "$diagnostic_arg0_root" "$diagnostic_runtime_tmp"
    : > "$diagnostic_allowed"
    : > "$diagnostic_outside"
    chmod 0400 "$diagnostic_allowed" "$diagnostic_outside"
    if ! "$sandbox_exec" \
        -D CODEX_BIN=/bin/cat -D SEALED_ROOT="$sealed_root" \
        -D ARG0_ROOT="$diagnostic_arg0_root" -D RUNTIME_TMP="$diagnostic_runtime_tmp" \
        -D AUTH_FILE=/dev/null -D INSTALLATION_ID="$diagnostic_installation_id" \
        -f "$seatbelt_profile" /bin/cat "$diagnostic_allowed" >/dev/null 2>&1; then
        echo "Reviewer bootstrap diagnostic did not admit its exact-base bundle." >&2
        exit 1
    fi
    if "$sandbox_exec" \
        -D CODEX_BIN=/bin/cat -D SEALED_ROOT="$sealed_root" \
        -D ARG0_ROOT="$diagnostic_arg0_root" -D RUNTIME_TMP="$diagnostic_runtime_tmp" \
        -D AUTH_FILE=/dev/null -D INSTALLATION_ID="$diagnostic_installation_id" \
        -f "$seatbelt_profile" /bin/cat "$diagnostic_outside" >/dev/null 2>&1 || \
        "$sandbox_exec" \
            -D CODEX_BIN=/bin/cat -D SEALED_ROOT="$sealed_root" \
            -D ARG0_ROOT="$diagnostic_arg0_root" -D RUNTIME_TMP="$diagnostic_runtime_tmp" \
            -D AUTH_FILE=/dev/null -D INSTALLATION_ID="$diagnostic_installation_id" \
            -f "$seatbelt_profile" /bin/cat "$repo_root/AGENTS.md" >/dev/null 2>&1; then
        echo "Reviewer bootstrap diagnostic did not deny external repository data." >&2
        exit 1
    fi
    /usr/bin/printf '{"schema_version":1,"status":"diagnostic_pass","base_sha":"%s","head_sha":"%s","review_evidence":false}\n' \
        "$base_sha" "$head_sha"
    exit 0
fi

runtime_config="$(trusted_php "$control_root/scripts/agent/readonly_reviewer_contract.php" runtime --platform="$reviewer_platform")" || exit $?
IFS=$'\t' read -r expected_codex_version expected_codex_sha256 expected_codex_archive_sha256 expected_codex_closure_sha256 <<< "$runtime_config"
if (
    [[ ! "$expected_codex_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] ||
    [[ ! "$expected_codex_sha256" =~ ^[a-f0-9]{64}$ ]] ||
    [[ ! "$expected_codex_archive_sha256" =~ ^[a-f0-9]{64}$ ]] ||
    [[ ! "$expected_codex_closure_sha256" =~ ^[a-f0-9]{64}$ ]]
); then
    echo "Reviewer Codex runtime policy is invalid." >&2
    exit 1
fi

trusted_php "$control_root/scripts/agent/readonly_reviewer_contract.php" validate-codex-source \
    --path="$codex_source" \
    --expected-owner="$REVIEWER_EFFECTIVE_UID" || exit $?

materialized_codex="$control_root/codex"
/bin/cp "$codex_source" "$materialized_codex" || {
    echo "Reviewer Codex binary could not be materialized privately." >&2
    exit 2
}
chmod 0500 "$materialized_codex"
trusted_php "$control_root/scripts/agent/readonly_reviewer_contract.php" validate-codex-copy \
    --path="$materialized_codex" \
    --expected-owner="$REVIEWER_EFFECTIVE_UID" \
    --expected-sha256="$expected_codex_sha256" || exit $?
attested_codex="$(
    trusted_python "$control_root/scripts/agent/verify_trusted_php_runtime.py" \
        --runtime=codex \
        --contract="$control_root/$contract_relative_path" \
        --platform="$reviewer_platform" \
        --path="$materialized_codex" \
        --expected-closure-sha256="$expected_codex_closure_sha256"
)" || exit $?
if [[ "$attested_codex" != "$materialized_codex" ]]; then
    echo "Reviewer Codex dependency attestation is invalid." >&2
    exit 2
fi
codex_bin="$attested_codex"

codex_version="$("$codex_bin" --version 2>/dev/null)" || {
    echo "Reviewer Codex binary does not identify as Codex CLI." >&2
    exit 2
}
trusted_php "$control_root/scripts/agent/readonly_reviewer_contract.php" validate-version \
    --version-output="$codex_version" \
    --expected-version="$expected_codex_version" || exit $?

reviewer_os_home="$(canonical_path "${REVIEWER_OS_HOME:-}")" || {
    echo "Reviewer canonical OS home is unavailable." >&2
    exit 2
}
if [[ "$reviewer_os_home" != "${HOME:-}" || "${CODEX_HOME:-}" != "$reviewer_os_home/.codex" ]]; then
    echo "Reviewer authentication home is not bound to the canonical OS account." >&2
    exit 2
fi
auth_source="$reviewer_os_home/.codex/auth.json"
if ! trusted_python -c '
import os
import stat
import sys

path = sys.argv[1]
expected_owner = int(sys.argv[2])
if os.path.islink(path) or os.path.realpath(path) != path:
    raise SystemExit(1)
metadata = os.stat(path, follow_symlinks=False)
if not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != expected_owner or metadata.st_mode & 0o077:
    raise SystemExit(1)
' "$auth_source" "$REVIEWER_EFFECTIVE_UID"; then
    echo "Reviewer host login is unavailable or not private." >&2
    exit 2
fi

trusted_php "$control_root/scripts/agent/readonly_reviewer_contract.php" trusted-paths --lens="$lens" >/dev/null || exit $?

# These libraries are exact base blobs from the hard-coded bootstrap set. The
# validated machine contract also requires both paths in the complete trust set.
# shellcheck source=scripts/agent/lib/readonly_reviewer_bundle_runtime.sh
source "$control_root/scripts/agent/lib/readonly_reviewer_bundle_runtime.sh"
# shellcheck source=scripts/agent/lib/readonly_reviewer_isolated_runtime.sh
source "$control_root/scripts/agent/lib/readonly_reviewer_isolated_runtime.sh"

readonly_reviewer_materialize_bundle "$control_root" "$review_root" "$base_sha" "$head_sha" "$lens"
readonly_reviewer_execute_isolated \
    "$control_root" "$review_root" "$sealed_root" "$repo_root" \
    "$auth_source" "$codex_bin" "$reviewer_system_path" "$reviewer_os_home" \
    "$sandbox_exec" "$lens" "$base_sha" "$head_sha"
