#!/usr/bin/env -S PATH=/usr/bin:/bin:/opt/homebrew/bin:/usr/local/bin:/opt/local/bin php -n
<?php

declare(strict_types=1);

$source = (string) file_get_contents(__FILE__);
$payload = substr($source, __COMPILER_HALT_OFFSET__);
if ($payload === false || $payload === '') {
    fwrite(STDERR, "Parallel-work validator bootstrap payload is unavailable.\n");
    exit(2);
}

$environment = [
    'PATH' => '/usr/bin:/bin:/usr/sbin:/sbin',
    'TMPDIR' => '/tmp',
    'LANG' => 'C',
    'LC_ALL' => 'C',
];
$process = proc_open(
    ['/bin/bash', '-s', '--', __FILE__, ...array_slice($argv, 1)],
    [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR],
    $pipes,
    getcwd() ?: null,
    $environment,
);
if (!is_resource($process)) {
    fwrite(STDERR, "Parallel-work validator bootstrap could not start the trusted shell.\n");
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
        fwrite(STDERR, "Parallel-work validator bootstrap payload could not be delivered.\n");
        exit(2);
    }
    $offset += $written;
}
fclose($pipes[0]);
exit(proc_close($process));

__halt_compiler();
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

trusted_php=''
for candidate in /usr/bin/php /opt/homebrew/bin/php /usr/local/bin/php /opt/local/bin/php; do
    if [[ -x "$candidate" ]]; then
        trusted_php="$candidate"
        break
    fi
done
if [[ -z "$trusted_php" ]]; then
    echo "PHP is unavailable on the fixed parallel-work validator path." >&2
    exit 2
fi

if [[ -z "$manifest_path" ]]; then
    echo "Missing --manifest." >&2
    exit 2
fi
if ! manifest_base="$(
    /usr/bin/env -i \
        PATH=/usr/bin:/bin:/usr/sbin:/sbin \
        LANG=C \
        LC_ALL=C \
        "$trusted_php" -n -d auto_prepend_file= -d auto_append_file= -r '
            $raw = @file_get_contents($argv[1] ?? "");
            if (!is_string($raw)) {
                fwrite(STDERR, "Parallel-work manifest is not valid JSON.\n");
                exit(2);
            }
            try {
                $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                fwrite(STDERR, "Parallel-work manifest is not valid JSON.\n");
                exit(2);
            }
            $base = is_array($manifest) ? ($manifest["base_sha"] ?? null) : null;
            if (!is_string($base) || preg_match("/^[a-f0-9]{40}$/D", $base) !== 1) {
                fwrite(STDERR, "Parallel-work input has an invalid shape.\n");
                exit(2);
            }
            fwrite(STDOUT, $base);
        ' "$manifest_path"
)"; then
    exit 2
fi

trusted_git=''
for candidate in /usr/bin/git /opt/homebrew/bin/git /usr/local/bin/git /opt/local/bin/git; do
    if [[ -x "$candidate" ]]; then
        trusted_git="$candidate"
        break
    fi
done
if [[ -z "$trusted_git" ]]; then
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
    scripts/agent/check_parallel_work_contract.sh
    scripts/agent/check_parallel_work_contract.php
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

/usr/bin/env -i \
    PATH=/usr/bin:/bin:/usr/sbin:/sbin \
    LANG=C \
    LC_ALL=C \
    TMPDIR=/tmp \
    PARALLEL_WORK_VALIDATOR_CHECKOUT_ROOT="$validator_checkout" \
    "$trusted_php" -n -d auto_prepend_file= -d auto_append_file= \
    "$trusted_root/scripts/agent/check_parallel_work_contract.php" "${forward_arguments[@]}"
