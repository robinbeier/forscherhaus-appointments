#!/usr/bin/env bash
set -Eeuo pipefail
export GIT_NO_REPLACE_OBJECTS=1

if [[ "$(uname -s)" == 'Darwin' ]]; then
    export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
PROD_SSH_TARGET='root@100.90.124.111'
SSH_OPTIONS=(-o BatchMode=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=yes)
MODE='plan'
CONFIRM_LIVE_WRITE=''
EXPECTED_COMMIT=''
HELPER_RELATIVE='scripts/ops/libexec/kuma_monitoring_env_v1.py'
INSTALLER_RELATIVE='scripts/ops/libexec/kuma_monitoring_env_install_v1.py'
INSTALLED_HELPER='/usr/local/libexec/fh-kuma-monitoring-env-v1'

usage() {
    cat <<'USAGE'
Usage: bash scripts/ops/prod_kuma_monitoring_env_v1.sh [options]

Plan, inspect, install, or execute the ROB-490 Kuma retention-monitoring Env
transaction. The production target is fixed to the Tailscale address
root@100.90.124.111. Default mode is local plan-only and never contacts SSH.

Options:
  --inspect
  --install --confirm-live-write ROB-490
  --execute --confirm-live-write ROB-490
  --expected-commit 40_HEX_COMMIT
  -h, --help
USAGE
}

while (( $# > 0 )); do
    case "$1" in
        --inspect)
            [[ "$MODE" == 'plan' ]] || { printf 'ERROR: select exactly one mode.\n' >&2; exit 1; }
            MODE='inspect'
            shift
            ;;
        --install)
            [[ "$MODE" == 'plan' ]] || { printf 'ERROR: select exactly one mode.\n' >&2; exit 1; }
            MODE='install'
            shift
            ;;
        --execute)
            [[ "$MODE" == 'plan' ]] || { printf 'ERROR: select exactly one mode.\n' >&2; exit 1; }
            MODE='execute'
            shift
            ;;
        --confirm-live-write)
            [[ $# -ge 2 ]] || exit 1
            CONFIRM_LIVE_WRITE="$2"
            shift 2
            ;;
        --expected-commit)
            [[ $# -ge 2 ]] || exit 1
            EXPECTED_COMMIT="$2"
            shift 2
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            printf 'ERROR: unknown option: %s\n' "$1" >&2
            exit 1
            ;;
    esac
done

if [[ "$MODE" == 'plan' ]]; then
    [[ -z "$CONFIRM_LIVE_WRITE" && -z "$EXPECTED_COMMIT" ]] || {
        printf 'ERROR: confirmation and commit binding require an explicit remote mode.\n' >&2
        exit 1
    }
    cat <<PLAN
[prod-kuma-monitoring-env-v1] Plan
  mode          : plan-only
  ssh target    : ${PROD_SSH_TARGET}
  helper        : ${INSTALLED_HELPER}
  env           : /root/backups/uptime-kuma-push.env
  recovery      : /var/lib/fh-kuma-monitoring-v1
  mutation      : none
PLAN
    exit 0
fi

[[ "$EXPECTED_COMMIT" =~ ^[0-9a-f]{40}$ ]] || {
    printf 'ERROR: remote modes require a lowercase 40-hex --expected-commit.\n' >&2
    exit 1
}
if [[ "$MODE" == 'inspect' ]]; then
    [[ -z "$CONFIRM_LIVE_WRITE" ]] || {
        printf 'ERROR: inspect does not accept a live-write confirmation.\n' >&2
        exit 1
    }
else
    [[ "$CONFIRM_LIVE_WRITE" == 'ROB-490' ]] || {
        printf 'ERROR: install and execute require --confirm-live-write ROB-490.\n' >&2
        exit 1
    }
fi
for command in git python3 ssh; do
    command -v "$command" >/dev/null 2>&1 || {
        printf 'ERROR: missing required command: %s\n' "$command" >&2
        exit 1
    }
done

HEAD_COMMIT="$(git -C "$REPO_ROOT" rev-parse HEAD)"
[[ "$HEAD_COMMIT" == "$EXPECTED_COMMIT" ]] || {
    printf 'ERROR: local HEAD does not match --expected-commit.\n' >&2
    exit 1
}
ORIGIN_MAIN_COMMIT="$(git -C "$REPO_ROOT" rev-parse --verify 'refs/remotes/origin/main^{commit}')" || {
    printf 'ERROR: unable to resolve local origin/main.\n' >&2
    exit 1
}
[[ "$ORIGIN_MAIN_COMMIT" == "$EXPECTED_COMMIT" ]] || {
    printf 'ERROR: local origin/main does not match --expected-commit.\n' >&2
    exit 1
}
REMOTE_MAIN_OUTPUT="$(git -C "$REPO_ROOT" ls-remote --exit-code origin refs/heads/main)" || {
    printf 'ERROR: unable to verify live origin/main.\n' >&2
    exit 1
}
EXPECTED_REMOTE_MAIN_OUTPUT="${EXPECTED_COMMIT}"$'\t''refs/heads/main'
[[ "$REMOTE_MAIN_OUTPUT" == "$EXPECTED_REMOTE_MAIN_OUTPUT" ]] || {
    printf 'ERROR: live origin/main does not match --expected-commit.\n' >&2
    exit 1
}
git -C "$REPO_ROOT" diff --quiet || {
    printf 'ERROR: tracked worktree changes present.\n' >&2
    exit 1
}
git -C "$REPO_ROOT" diff --cached --quiet || {
    printf 'ERROR: staged worktree changes present.\n' >&2
    exit 1
}

HELPER_SHA256="$(python3 - "$REPO_ROOT" "$EXPECTED_COMMIT" "$HELPER_RELATIVE" <<'PY'
import hashlib
import subprocess
import sys

repository, commit, path = sys.argv[1:]
result = subprocess.run(
    ['git', '-C', repository, 'show', f'{commit}:{path}'],
    check=True,
    stdout=subprocess.PIPE,
    stderr=subprocess.DEVNULL,
)
print(hashlib.sha256(result.stdout).hexdigest())
PY
)"
[[ "$HELPER_SHA256" =~ ^[0-9a-f]{64}$ ]] || {
    printf 'ERROR: unable to derive the helper hash from the expected commit.\n' >&2
    exit 1
}

REMOTE_STAGE="$(ssh "${SSH_OPTIONS[@]}" "$PROD_SSH_TARGET" \
    'umask 077; mktemp -d -p /root .fh-kuma-monitoring-env-v1.XXXXXXXX')"
[[ "$REMOTE_STAGE" =~ ^/root/\.fh-kuma-monitoring-env-v1\.[A-Za-z0-9]{8}$ ]] || {
    printf 'ERROR: remote staging path contract failed.\n' >&2
    exit 1
}

git -C "$REPO_ROOT" archive --format=tar "$EXPECTED_COMMIT" -- \
    "$HELPER_RELATIVE" "$INSTALLER_RELATIVE" \
    | ssh "${SSH_OPTIONS[@]}" "$PROD_SSH_TARGET" \
        "umask 022; /usr/bin/tar --no-same-owner --no-same-permissions -xf - -C '${REMOTE_STAGE}' && \
        /usr/bin/chown root:root '${REMOTE_STAGE}/${HELPER_RELATIVE}' '${REMOTE_STAGE}/${INSTALLER_RELATIVE}' && \
        /usr/bin/chmod 0555 '${REMOTE_STAGE}/${HELPER_RELATIVE}' '${REMOTE_STAGE}/${INSTALLER_RELATIVE}'"

set +e
INSTALL_INSPECT_OUTPUT="$(ssh "${SSH_OPTIONS[@]}" "$PROD_SSH_TARGET" \
    "/usr/bin/python3 -I -B '${REMOTE_STAGE}/${INSTALLER_RELATIVE}' \
    --source '${REMOTE_STAGE}/${HELPER_RELATIVE}' --expected-sha256 '${HELPER_SHA256}'")"
INSTALL_INSPECT_RC=$?
set -e
printf '%s\n' "$INSTALL_INSPECT_OUTPUT"
if (( INSTALL_INSPECT_RC != 0 )); then
    printf 'ERROR: installer preflight failed; staging is retained and no mutation is attempted.\n' >&2
    exit "$INSTALL_INSPECT_RC"
fi
python3 -c 'import json,sys; value=json.loads(sys.stdin.read()); assert value.get("status") == "pass" and value.get("execution_ready") is True and value.get("mutation_performed") is False and value.get("install_state") in {"absent","installed"}' <<<"$INSTALL_INSPECT_OUTPUT"

if [[ "$MODE" == 'install' ]]; then
    set +e
    RESULT_OUTPUT="$(ssh "${SSH_OPTIONS[@]}" "$PROD_SSH_TARGET" \
        "/usr/bin/python3 -I -B '${REMOTE_STAGE}/${INSTALLER_RELATIVE}' \
        --source '${REMOTE_STAGE}/${HELPER_RELATIVE}' --expected-sha256 '${HELPER_SHA256}' \
        --execute --confirm-live-write ROB-490")"
    RESULT_RC=$?
    set -e
    printf '%s\n' "$RESULT_OUTPUT"
    if (( RESULT_RC != 0 )); then
        printf 'ERROR: helper installation did not return a known pass; staging is retained and no retry is attempted.\n' >&2
        exit "$RESULT_RC"
    fi
    python3 -c 'import json,sys; value=json.loads(sys.stdin.read()); assert value.get("status") == "pass" and value.get("execution_ready") is True and value.get("install_state") == "installed"' <<<"$RESULT_OUTPUT"
    set +e
    HELPER_OUTPUT="$(ssh "${SSH_OPTIONS[@]}" "$PROD_SSH_TARGET" \
        "/usr/bin/python3 -I -B '${REMOTE_STAGE}/${INSTALLER_RELATIVE}' \
        --source '${REMOTE_STAGE}/${HELPER_RELATIVE}' --expected-sha256 '${HELPER_SHA256}' \
        --invoke-installed inspect")"
    HELPER_RC=$?
    set -e
elif [[ "$MODE" == 'inspect' ]]; then
    set +e
    HELPER_OUTPUT="$(ssh "${SSH_OPTIONS[@]}" "$PROD_SSH_TARGET" \
        "/usr/bin/python3 -I -B '${REMOTE_STAGE}/${INSTALLER_RELATIVE}' \
        --source '${REMOTE_STAGE}/${HELPER_RELATIVE}' --expected-sha256 '${HELPER_SHA256}' \
        --invoke-source inspect")"
    HELPER_RC=$?
    set -e
else
    python3 -c 'import json,sys; value=json.loads(sys.stdin.read()); assert value.get("install_state") == "installed"' <<<"$INSTALL_INSPECT_OUTPUT" || {
        printf 'ERROR: execute requires the exact helper to be installed by the separate install gate.\n' >&2
        exit 70
    }
    set +e
    HELPER_OUTPUT="$(ssh "${SSH_OPTIONS[@]}" "$PROD_SSH_TARGET" \
        "/usr/bin/python3 -I -B '${REMOTE_STAGE}/${INSTALLER_RELATIVE}' \
        --source '${REMOTE_STAGE}/${HELPER_RELATIVE}' --expected-sha256 '${HELPER_SHA256}' \
        --invoke-installed execute --confirm-live-write ROB-490")"
    HELPER_RC=$?
    set -e
fi

printf '%s\n' "$HELPER_OUTPUT"
if (( HELPER_RC != 0 )); then
    printf 'ERROR: helper result is not a known pass; staging is retained and no retry is attempted.\n' >&2
    exit "$HELPER_RC"
fi
python3 -c 'import json,sys; value=json.loads(sys.stdin.read()); assert value.get("status") == "pass" and value.get("execution_ready") is True' <<<"$HELPER_OUTPUT"

ssh "${SSH_OPTIONS[@]}" "$PROD_SSH_TARGET" \
    "case '${REMOTE_STAGE}' in /root/.fh-kuma-monitoring-env-v1.[A-Za-z0-9][A-Za-z0-9][A-Za-z0-9][A-Za-z0-9][A-Za-z0-9][A-Za-z0-9][A-Za-z0-9][A-Za-z0-9]) rm -rf -- '${REMOTE_STAGE}' ;; *) exit 70 ;; esac"

printf '[prod-kuma-monitoring-env-v1] %s passed for commit %s\n' "$MODE" "$EXPECTED_COMMIT"
