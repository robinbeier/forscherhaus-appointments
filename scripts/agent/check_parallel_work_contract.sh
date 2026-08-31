#!/bin/bash

set -euo pipefail

validator_checkout=''
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
        *)
            forward_arguments+=("$argument")
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
        "$trusted_git" \
        -c core.fsmonitor=false \
        -c core.hooksPath=/dev/null \
        -c core.untrackedCache=false \
        -c diff.external= \
        -c core.excludesfile=/dev/null \
        -C "$validator_checkout" "$@"
}

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

if [[ -L "$0" ]]; then
    echo "Parallel-work validator runner is invalid." >&2
    exit 2
fi
runner_directory="$(CDPATH= cd -- "$(/usr/bin/dirname -- "$0")" 2>/dev/null && /bin/pwd -P)" || {
    echo "Parallel-work validator runner is invalid." >&2
    exit 2
}
runner_source="$runner_directory/$(/usr/bin/basename -- "$0")"
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
