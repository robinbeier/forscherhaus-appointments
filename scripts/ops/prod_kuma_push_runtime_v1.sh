#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "$(uname -s)" == 'Darwin' ]]; then
    export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
PROD_SSH_TARGET='root@100.90.124.111'
SSH_OPTIONS=(-o BatchMode=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=accept-new)
EXECUTE=0
CONFIRM_LIVE_WRITE=''
EXPECTED_COMMIT=''
MANIFEST_RELATIVE='scripts/ops/config/kuma_push_runtime_bundle_v1.json'
HELPER_RELATIVE='scripts/ops/libexec/kuma_push_runtime_v1.py'

usage() {
    cat <<'USAGE'
Usage: bash scripts/ops/prod_kuma_push_runtime_v1.sh [options]

Plan or execute the ROB-489 immutable Kuma Push runtime installation and exact
ten-invocation cron path migration. The production target is fixed to the
Tailscale address root@100.90.124.111. Default mode is local plan-only and does
not contact or mutate the host.

Options:
  --execute
  --confirm-live-write ROB-489
  --expected-commit 40_HEX_COMMIT
  -h, --help
USAGE
}

while (( $# > 0 )); do
    case "$1" in
        --execute) EXECUTE=1; shift ;;
        --confirm-live-write) [[ $# -ge 2 ]] || exit 1; CONFIRM_LIVE_WRITE="$2"; shift 2 ;;
        --expected-commit) [[ $# -ge 2 ]] || exit 1; EXPECTED_COMMIT="$2"; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) printf 'ERROR: unknown option: %s\n' "$1" >&2; exit 1 ;;
    esac
done

if (( EXECUTE == 0 )); then
    [[ -z "$CONFIRM_LIVE_WRITE" && -z "$EXPECTED_COMMIT" ]] || {
        printf 'ERROR: confirmation and commit binding are valid only with --execute.\n' >&2
        exit 1
    }
    cat <<PLAN
[prod-kuma-push-runtime-v1] Plan
  mode          : plan-only
  ssh target    : ${PROD_SSH_TARGET}
  runtime       : /usr/local/libexec/fh-kuma-push-runtime-v1
  cron          : /etc/cron.d/fh-uptime-kuma-push
  bundle files  : 15
  entrypoints   : 9
  invocations   : 10
PLAN
    exit 0
fi

[[ "$CONFIRM_LIVE_WRITE" == 'ROB-489' ]] || {
    printf 'ERROR: --execute requires --confirm-live-write ROB-489.\n' >&2
    exit 1
}
[[ "$EXPECTED_COMMIT" =~ ^[0-9a-f]{40}$ ]] || {
    printf 'ERROR: --execute requires a lowercase 40-hex --expected-commit.\n' >&2
    exit 1
}
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

ARTIFACTS=("$MANIFEST_RELATIVE" "$HELPER_RELATIVE")
ARTIFACT_OUTPUT="$(python3 - "$REPO_ROOT" "$EXPECTED_COMMIT" "$MANIFEST_RELATIVE" "$HELPER_RELATIVE" <<'PY'
import json
import pathlib
import re
import subprocess
import sys

repository, commit, manifest_path, helper_path = sys.argv[1:]
result = subprocess.run(
    ['git', '-C', repository, 'show', f'{commit}:{manifest_path}'],
    check=True,
    stdout=subprocess.PIPE,
    stderr=subprocess.DEVNULL,
)
manifest = json.loads(result.stdout.decode('utf-8'))
if (
    manifest.get('schema') != 'fh_kuma_push_runtime_bundle.v1'
    or manifest.get('runtime') != 'v1'
    or manifest.get('install_root') != '/usr/local/libexec/fh-kuma-push-runtime-v1'
    or manifest.get('cron_path') != '/etc/cron.d/fh-uptime-kuma-push'
    or not isinstance(manifest.get('files'), list)
    or len(manifest['files']) != 15
):
    raise SystemExit('invalid runtime manifest')
paths = [manifest_path, helper_path, manifest.get('cron_source')] + [
    entry.get('source') for entry in manifest['files']
]
if len(paths) != len(set(paths)):
    raise SystemExit('duplicate runtime artifact')
for path in paths:
    if (
        not isinstance(path, str)
        or pathlib.PurePosixPath(path).is_absolute()
        or '..' in pathlib.PurePosixPath(path).parts
        or not re.fullmatch(r'[A-Za-z0-9._/-]+', path)
    ):
        raise SystemExit('invalid runtime artifact path')
for path in paths[2:]:
    print(path)
PY
)"
while IFS= read -r artifact; do
    [[ -n "$artifact" ]] && ARTIFACTS+=("$artifact")
done <<<"$ARTIFACT_OUTPUT"
[[ "${#ARTIFACTS[@]}" -eq 18 ]] || {
    printf 'ERROR: runtime artifact count contract failed.\n' >&2
    exit 1
}

REMOTE_STAGE="$(ssh "${SSH_OPTIONS[@]}" "$PROD_SSH_TARGET" \
    'umask 077; mktemp -d -p /root .fh-kuma-push-runtime-v1.XXXXXXXX')"
[[ "$REMOTE_STAGE" =~ ^/root/\.fh-kuma-push-runtime-v1\.[A-Za-z0-9]{8}$ ]] || {
    printf 'ERROR: remote staging path contract failed.\n' >&2
    exit 1
}

git -C "$REPO_ROOT" archive --format=tar "$EXPECTED_COMMIT" -- "${ARTIFACTS[@]}" \
    | ssh "${SSH_OPTIONS[@]}" "$PROD_SSH_TARGET" \
        "umask 022; /usr/bin/tar --no-same-owner --no-same-permissions -xf - -C '${REMOTE_STAGE}'"

set +e
INSPECT_OUTPUT="$(ssh "${SSH_OPTIONS[@]}" "$PROD_SSH_TARGET" \
    "/usr/bin/python3 -I -B '${REMOTE_STAGE}/scripts/ops/libexec/kuma_push_runtime_v1.py' --source-root '${REMOTE_STAGE}'")"
INSPECT_RC=$?
set -e
printf '%s\n' "$INSPECT_OUTPUT"
if (( INSPECT_RC != 0 )); then
    printf 'ERROR: remote preflight failed; staging is retained and no further mutation is attempted.\n' >&2
    exit "$INSPECT_RC"
fi
python3 -c 'import json,sys; value=json.loads(sys.stdin.read()); assert value.get("status") == "pass" and value.get("execution_ready") is True and value.get("mutation_performed") is False' <<<"$INSPECT_OUTPUT"

set +e
EXECUTE_OUTPUT="$(ssh "${SSH_OPTIONS[@]}" "$PROD_SSH_TARGET" \
    "/usr/bin/python3 -I -B '${REMOTE_STAGE}/scripts/ops/libexec/kuma_push_runtime_v1.py' --source-root '${REMOTE_STAGE}' --execute --confirm-live-write ROB-489")"
EXECUTE_RC=$?
set -e
printf '%s\n' "$EXECUTE_OUTPUT"
if (( EXECUTE_RC != 0 )); then
    printf 'ERROR: remote execute result is not successful; staging is retained and no retry is attempted.\n' >&2
    exit "$EXECUTE_RC"
fi
python3 -c 'import json,sys; value=json.loads(sys.stdin.read()); assert value.get("status") == "pass" and value.get("execution_ready") is True and value.get("bundle_installed") is True and value.get("cron_state") == "installed"' <<<"$EXECUTE_OUTPUT"

ssh "${SSH_OPTIONS[@]}" "$PROD_SSH_TARGET" \
    "case '${REMOTE_STAGE}' in /root/.fh-kuma-push-runtime-v1.[A-Za-z0-9][A-Za-z0-9][A-Za-z0-9][A-Za-z0-9][A-Za-z0-9][A-Za-z0-9][A-Za-z0-9][A-Za-z0-9]) rm -rf -- '${REMOTE_STAGE}' ;; *) exit 70 ;; esac"

printf '[prod-kuma-push-runtime-v1] installation and cron migration passed for commit %s\n' "$EXPECTED_COMMIT"
