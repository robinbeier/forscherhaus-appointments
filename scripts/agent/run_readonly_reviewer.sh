#!/usr/bin/env -S PATH=/usr/bin:/bin:/opt/homebrew/bin:/usr/local/bin:/opt/local/bin php -n
<?php

declare(strict_types=1);

$source = (string) file_get_contents(__FILE__);
$payload = substr($source, __COMPILER_HALT_OFFSET__);
if ($payload === false || $payload === '') {
    fwrite(STDERR, "Reviewer bootstrap payload is unavailable.\n");
    exit(2);
}

$effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : false;
$account = is_int($effectiveUid) && function_exists('posix_getpwuid') ? posix_getpwuid($effectiveUid) : false;
if (
    !is_array($account) ||
    !is_string($account['name'] ?? null) ||
    $account['name'] === '' ||
    !is_string($account['dir'] ?? null)
) {
    fwrite(STDERR, "Reviewer OS account could not be resolved.\n");
    exit(2);
}
$osHome = realpath($account['dir']);
if (
    $osHome === false ||
    !str_starts_with($osHome, '/') ||
    !is_dir($osHome) ||
    ($effectiveUid !== 0 && fileowner($osHome) !== $effectiveUid)
) {
    fwrite(STDERR, "Reviewer OS home could not be resolved.\n");
    exit(2);
}

$environment = [
    'PATH' => '/usr/bin:/bin:/usr/sbin:/sbin:/opt/homebrew/bin:/usr/local/bin:/opt/local/bin',
    'TMPDIR' => '/tmp',
    'HOME' => $osHome,
    'USER' => $account['name'],
    'LOGNAME' => $account['name'],
    'CODEX_HOME' => $osHome . '/.codex',
    'REVIEWER_OS_HOME' => $osHome,
    'REVIEWER_EFFECTIVE_UID' => (string) $effectiveUid,
];
$process = proc_open(
    ['/bin/bash', '-s', '--', __FILE__, ...array_slice($argv, 1)],
    [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR],
    $pipes,
    getcwd() ?: null,
    $environment,
);
if (!is_resource($process)) {
    fwrite(STDERR, "Reviewer bootstrap could not start the trusted shell.\n");
    exit(2);
}

$offset = 0;
$length = strlen($payload);
while ($offset < $length) {
    $written = fwrite($pipes[0], substr($payload, $offset));
    if ($written === false || $written === 0) {
        fclose($pipes[0]);
        proc_terminate($process);
        proc_close($process);
        fwrite(STDERR, "Reviewer bootstrap payload could not be delivered.\n");
        exit(2);
    }
    $offset += $written;
}
fclose($pipes[0]);
exit(proc_close($process));

__halt_compiler();
#!/bin/bash

set -euo pipefail

# With `bash -s -- <runner> ...`, Bash keeps its own `$0` and assigns the
# materialized runner path to `$1`.
runner_source_input="${1:-}"
if [[ -z "$runner_source_input" ]]; then
    echo "Reviewer trusted source path is unavailable." >&2
    exit 2
fi
shift

reviewer_system_path="/usr/bin:/bin:/usr/sbin:/sbin:/opt/homebrew/bin:/usr/local/bin:/opt/local/bin"
export PATH="$reviewer_system_path"

usage() {
    echo "Usage: $runner_source_input [--repo-root=<absolute-path>] [--codex-bin=<absolute-path>] --lens=<lens> --base-sha=<sha> --head-sha=<sha>" >&2
}

lens=""
base_sha=""
head_sha=""
requested_repo_root=""
requested_codex_bin=""

for argument in "$@"; do
    case "$argument" in
        --lens=*) lens="${argument#*=}" ;;
        --base-sha=*) base_sha="${argument#*=}" ;;
        --head-sha=*) head_sha="${argument#*=}" ;;
        --repo-root=*) requested_repo_root="${argument#*=}" ;;
        --codex-bin=*) requested_codex_bin="${argument#*=}" ;;
        *) usage; exit 2 ;;
    esac
done

git_bin="$(command -v git 2>/dev/null)" || {
    echo "Git is unavailable on the fixed reviewer tool path." >&2
    exit 2
}
php_bin="$(command -v php 2>/dev/null)" || {
    echo "PHP is unavailable on the fixed reviewer tool path." >&2
    exit 2
}

canonical_path() {
    "$php_bin" -n -d auto_prepend_file= -d auto_append_file= -r '
        $resolved = realpath($argv[1]);
        if ($resolved === false) {
            exit(1);
        }
        fwrite(STDOUT, $resolved);
    ' "$1"
}

trusted_git() {
    env \
        -u GIT_ALTERNATE_OBJECT_DIRECTORIES \
        -u GIT_ASKPASS \
        -u GIT_COMMON_DIR \
        -u GIT_CONFIG_COUNT \
        -u GIT_CONFIG_PARAMETERS \
        -u GIT_DIR \
        -u GIT_EXEC_PATH \
        -u GIT_INDEX_FILE \
        -u GIT_NAMESPACE \
        -u GIT_OBJECT_DIRECTORY \
        -u GIT_PROXY_COMMAND \
        -u GIT_SSH \
        -u GIT_SSH_COMMAND \
        -u GIT_TEMPLATE_DIR \
        -u GIT_WORK_TREE \
        -u SSH_ASKPASS \
        GIT_ATTR_NOSYSTEM=1 \
        GIT_CONFIG_GLOBAL=/dev/null \
        GIT_CONFIG_NOSYSTEM=1 \
        GIT_CONFIG_SYSTEM=/dev/null \
        GIT_NO_LAZY_FETCH=1 \
        GIT_NO_REPLACE_OBJECTS=1 \
        GIT_OPTIONAL_LOCKS=0 \
        GIT_PAGER=cat \
        GIT_TERMINAL_PROMPT=0 \
        "$git_bin" \
        -c core.fsmonitor=false \
        -c core.hooksPath=/dev/null \
        -c core.untrackedCache=false \
        -c diff.external= \
        -c http.proxy= \
        -c https.proxy= \
        -c pager.diff=false \
        "$@"
}

trusted_remote_git() {
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
        PATH=/usr/bin:/bin:/usr/sbin:/sbin:/opt/homebrew/bin:/usr/local/bin:/opt/local/bin \
        TMPDIR=/tmp \
        "$git_bin" \
        -c credential.helper= \
        -c core.askPass= \
        -c core.fsmonitor=false \
        -c core.hooksPath=/dev/null \
        -c core.untrackedCache=false \
        -c diff.external= \
        -c http.proxy= \
        -c https.proxy= \
        -c pager.diff=false \
        -C /tmp \
        "$@"
}

sha_pattern='^[a-f0-9]{40}$'
if [[ ! "$base_sha" =~ $sha_pattern || ! "$head_sha" =~ $sha_pattern ]]; then
    echo "Reviewer SHAs must be full lowercase commit IDs." >&2
    exit 2
fi

if [[ -n "$requested_repo_root" && "$requested_repo_root" != /* ]]; then
    echo "Reviewer repository root must be absolute." >&2
    exit 2
fi
repo_root_input="$(trusted_git -C "${requested_repo_root:-.}" rev-parse --show-toplevel 2>/dev/null)" || {
    echo "Reviewer must run inside a Git worktree." >&2
    exit 2
}
repo_root="$(canonical_path "$repo_root_input")" || {
    echo "Reviewer repository root could not be resolved." >&2
    exit 2
}
if [[ ! -d "$repo_root" ]]; then
    echo "Reviewer repository root is invalid." >&2
    exit 2
fi
cd "$repo_root"

review_base_ref="refs/remotes/origin/main"
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

runner_source="$(canonical_path "$runner_source_input")" || {
    echo "Reviewer trusted source path could not be resolved." >&2
    exit 2
}
case "$runner_source" in
    "$repo_root"|"$repo_root"/*)
        echo "Reviewer runner must be materialized from the review base outside the worktree." >&2
        exit 1
        ;;
esac

runner_path="scripts/agent/run_readonly_reviewer.sh"
if ! trusted_git show "${base_sha}:${runner_path}" 2>/dev/null | cmp -s - "$runner_source"; then
    echo "Reviewer runner is not the trusted copy from the review base; external bootstrap review is required." >&2
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

if ! trusted_git diff --quiet --no-ext-diff --no-textconv "$base_sha" "$head_sha" -- .codex/config.toml ':(glob)**/AGENTS.md'; then
    echo "Reviewer runtime configuration changed; external bootstrap review is required." >&2
    exit 1
fi

assert_tree_has_no_symlinks() {
    local tree_sha="$1"
    trusted_git ls-tree -r -z "$tree_sha" | env -u PHPRC -u PHP_INI_SCAN_DIR \
        "$php_bin" -n -d auto_prepend_file= -d auto_append_file= -r '
            $raw = (string) stream_get_contents(STDIN);
            if ($raw !== "" && !str_ends_with($raw, "\0")) {
                exit(1);
            }
            foreach ($raw === "" ? [] : explode("\0", substr($raw, 0, -1)) as $entry) {
                if (preg_match("/^([0-7]{6}) (?:blob|commit) [0-9a-f]{40,64}\t/sD", $entry, $matches) !== 1) {
                    exit(1);
                }
                if ($matches[1] === "120000") {
                    exit(1);
                }
            }
        '
}
if ! assert_tree_has_no_symlinks "$base_sha" || ! assert_tree_has_no_symlinks "$head_sha"; then
    echo "Reviewer exact commit tree contains a tracked symlink or invalid entry." >&2
    exit 1
fi

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
if ! "$php_bin" -n -d auto_prepend_file= -d auto_append_file= -r '
    $path = $argv[1];
    $owner = fileowner($path);
    $mode = fileperms($path);
    if ($owner !== 0 || !is_int($mode) || ($mode & 0o022) !== 0) {
        exit(1);
    }
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
outside_canary_root=""
home_canary=""
cleanup_sealed_review() {
    if [[ -n "$outside_canary_root" ]]; then
        chmod -R u+w -- "$outside_canary_root" 2>/dev/null || true
        rm -rf -- "$outside_canary_root"
    fi
    if [[ -n "$home_canary" ]]; then
        rm -f -- "$home_canary"
    fi
    chmod -R u+w -- "$sealed_root" 2>/dev/null || true
    rm -rf -- "$sealed_root"
}
trap cleanup_sealed_review EXIT

control_root="$sealed_root/control"
review_root="$sealed_root/bundle"
mkdir -m 0700 "$control_root" "$review_root"

trusted_php() {
    env -u PHPRC -u PHP_INI_SCAN_DIR \
        "$php_bin" -n -d auto_prepend_file= -d auto_append_file= "$@"
}

contract_relative_path=".codex/contracts/agent-workflow.json"
bootstrap_paths=(
    "$contract_relative_path"
    "scripts/agent/readonly-reviewer.sb"
    "scripts/agent/readonly_review_bundle.php"
    "scripts/agent/readonly_reviewer_contract.php"
    "scripts/agent/lib/RepoPath.php"
    "scripts/agent/lib/ReadonlyReviewBundle.php"
    "scripts/agent/lib/ReadonlyReviewerContract.php"
    "scripts/agent/lib/readonly_reviewer_bundle_runtime.sh"
    "scripts/agent/lib/readonly_reviewer_isolated_runtime.sh"
)
for bootstrap_path in "${bootstrap_paths[@]}"; do
    mkdir -p "$control_root/$(dirname "$bootstrap_path")"
    if ! trusted_git show "${base_sha}:${bootstrap_path}" > "$control_root/$bootstrap_path"; then
        echo "Reviewer trust bootstrap is unavailable in the review base." >&2
        exit 1
    fi
done

runtime_config="$(trusted_php "$control_root/scripts/agent/readonly_reviewer_contract.php" runtime --platform="$reviewer_platform")" || exit $?
IFS=$'\t' read -r expected_codex_version expected_codex_sha256 expected_codex_archive_sha256 <<< "$runtime_config"
if (
    [[ ! "$expected_codex_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] ||
    [[ ! "$expected_codex_sha256" =~ ^[a-f0-9]{64}$ ]] ||
    [[ ! "$expected_codex_archive_sha256" =~ ^[a-f0-9]{64}$ ]]
); then
    echo "Reviewer Codex runtime policy is invalid." >&2
    exit 1
fi

trusted_php "$control_root/scripts/agent/readonly_reviewer_contract.php" validate-codex-source \
    --path="$codex_source" \
    --expected-owner="$REVIEWER_EFFECTIVE_UID" || exit $?

materialized_codex="$control_root/codex"
/bin/cp -- "$codex_source" "$materialized_codex" || {
    echo "Reviewer Codex binary could not be materialized privately." >&2
    exit 2
}
chmod 0500 "$materialized_codex"
trusted_php "$control_root/scripts/agent/readonly_reviewer_contract.php" validate-codex-copy \
    --path="$materialized_codex" \
    --expected-owner="$REVIEWER_EFFECTIVE_UID" \
    --expected-sha256="$expected_codex_sha256" || exit $?
codex_bin="$materialized_codex"

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
if ! "$php_bin" -n -d auto_prepend_file= -d auto_append_file= -r '
    [$path, $expectedOwner] = array_slice($argv, 1);
    if (!is_file($path) || is_link($path) || realpath($path) !== $path) {
        exit(1);
    }
    $owner = fileowner($path);
    $mode = fileperms($path);
    if ($owner !== (int) $expectedOwner || !is_int($mode) || ($mode & 0o077) !== 0) {
        exit(1);
    }
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

readonly_reviewer_materialize_bundle
readonly_reviewer_execute_isolated
