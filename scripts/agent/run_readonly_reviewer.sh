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
        -c pager.diff=false \
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

trusted_git cat-file -e "${base_sha}^{commit}" 2>/dev/null || {
    echo "Reviewer base commit is unavailable." >&2
    exit 1
}
review_base_ref="refs/remotes/origin/main"
trusted_git rev-parse --verify "${review_base_ref}^{commit}" >/dev/null 2>&1 || {
    echo "Reviewer canonical base ref is unavailable; fetch origin/main before review." >&2
    exit 1
}
expected_base_sha="$(trusted_git merge-base "$review_base_ref" "$head_sha")" || {
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

contract_relative_path=".codex/contracts/agent-workflow.json"
bootstrap_paths=(
    "$contract_relative_path"
    "scripts/agent/readonly-reviewer.sb"
    "scripts/agent/readonly_review_bundle.php"
    "scripts/agent/readonly_reviewer_contract.php"
    "scripts/agent/lib/RepoPath.php"
    "scripts/agent/lib/ReadonlyReviewBundle.php"
    "scripts/agent/lib/ReadonlyReviewerContract.php"
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
IFS=$'\t' read -r role_file model reasoning disabled_features <<< "$reviewer_config"
if [[ -z "$role_file" || -z "$model" || -z "$reasoning" || -z "$disabled_features" ]]; then
    echo "Reviewer invocation policy is incomplete." >&2
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

materialize_changed_blob() {
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

blob_evidence_file="$control_root/blob-evidence.tsv"
: > "$blob_evidence_file"
while IFS= read -r -d '' changed_path; do
    if ! base_blob="$(materialize_changed_blob "$base_sha" base "$changed_path")"; then
        echo "Reviewer base context could not be materialized." >&2
        exit 1
    fi
    if ! head_blob="$(materialize_changed_blob "$head_sha" head "$changed_path")"; then
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

seatbelt_run() {
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

model_catalog="$control_root/models.json"
if ! seatbelt_run "${reviewer_environment[@]}" "$codex_bin" debug models --bundled 2>/dev/null \
    | trusted_php "$control_root/scripts/agent/readonly_review_bundle.php" model-catalog \
        --model="$model" > "$model_catalog"; then
    echo "Reviewer tool-free model catalog could not be derived." >&2
    exit 1
fi

prompt_role_probe='UNTRUSTED-REVIEW-BUNDLE-PROBE'
if ! seatbelt_run "${reviewer_environment[@]}" "$codex_bin" \
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

if ! seatbelt_run /bin/cat "$allowed_canary" >/dev/null 2>&1; then
    echo "Reviewer Seatbelt profile did not admit the exact bundle." >&2
    exit 1
fi
if seatbelt_run /bin/cat "$outside_canary" >/dev/null 2>&1; then
    echo "Reviewer Seatbelt profile did not deny foreign temp data." >&2
    exit 1
fi
if seatbelt_run /bin/cat "$home_canary" >/dev/null 2>&1; then
    echo "Reviewer Seatbelt profile did not deny host-home data." >&2
    exit 1
fi
if seatbelt_run /bin/cat "$repo_root/AGENTS.md" >/dev/null 2>&1; then
    echo "Reviewer Seatbelt profile did not deny the original worktree." >&2
    exit 1
fi

cd "$sealed_root"
codex_stderr="$runtime_tmp/codex.stderr"
set +e
seatbelt_run "${reviewer_environment[@]}" "$codex_bin" --ask-for-approval never exec \
        --dangerously-bypass-approvals-and-sandbox \
        --ignore-user-config \
        --ignore-rules \
        --strict-config \
        --ephemeral \
        --skip-git-repo-check \
        --color never \
        --model "$model" \
        --output-schema "$control_root/scripts/agent/readonly-review-output.schema.json" \
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
