#!/usr/bin/env bash
# Stream a reviewed, read-only recovery inspector to the Tailscale production host.
set -Eeuo pipefail
umask 077
export GIT_NO_REPLACE_OBJECTS=1

PROJECT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
SOURCE='scripts/ops/libexec/bound_release_recovery_inspect_v1.py'
TARGET='root@booking-server'
MODE=''; SOURCE_COMMIT=''; ACTIVE=''; REL=''; COMMIT=''; RUN_ID=''
ARCHIVE_SHA=''; PROVENANCE_SHA=''; CONTINUITY_SHA=''; DEPLOY_SHA=''; PAIR_SHA=''; BACKUP_SHA=''
RUN=0; CONFIRM=''
INSPECTION_TIMEOUT_SECONDS="${FH_RECOVERY_INSPECT_TIMEOUT_SECONDS:-60}"

usage() {
    cat <<'USAGE'
Usage: prod_inspect_bound_release_recovery.sh --source-commit FULL_MAIN_SHA --mode no-guard|recovery --expected-active-release EA_ID [recovery bindings] [--run-read-only --confirm-read-only ROB-621]

Recovery bindings: --release EA_ID --commit ORIGINAL_RELEASE_COMMIT --run-id HEX32
  --archive-sha HEX64 --provenance-sha HEX64 --continuity-sha HEX64
  --deploy-sha HEX64 --pair-helper-sha HEX64 --backup-helper-sha HEX64

Default is plan-only. no-guard checks only the absent global guard and the
expected active marker. recovery inspects one originally documented run.
Both modes take the shared lock and read protected state; neither deploys,
acknowledges, uploads, deletes, or releases a guard. Unknown results block.
The target is the Tailscale MagicDNS host booking-server.
USAGE
}

while (( $# > 0 )); do
    case "$1" in
        --source-commit) SOURCE_COMMIT="${2:-}"; shift 2 ;;
        --mode) MODE="${2:-}"; shift 2 ;;
        --expected-active-release) ACTIVE="${2:-}"; shift 2 ;;
        --release) REL="${2:-}"; shift 2 ;;
        --commit) COMMIT="${2:-}"; shift 2 ;;
        --run-id) RUN_ID="${2:-}"; shift 2 ;;
        --archive-sha) ARCHIVE_SHA="${2:-}"; shift 2 ;;
        --provenance-sha) PROVENANCE_SHA="${2:-}"; shift 2 ;;
        --continuity-sha) CONTINUITY_SHA="${2:-}"; shift 2 ;;
        --deploy-sha) DEPLOY_SHA="${2:-}"; shift 2 ;;
        --pair-helper-sha) PAIR_SHA="${2:-}"; shift 2 ;;
        --backup-helper-sha) BACKUP_SHA="${2:-}"; shift 2 ;;
        --run-read-only) RUN=1; shift ;;
        --confirm-read-only) CONFIRM="${2:-}"; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) usage >&2; exit 64 ;;
    esac
done

[[ "$SOURCE_COMMIT" =~ ^[a-f0-9]{40}$ && "$ACTIVE" =~ ^ea_[A-Za-z0-9_]+$ ]] || {
    echo 'ERROR: source commit or active-release identity invalid.' >&2; exit 64;
}
case "$MODE" in
    no-guard)
        [[ -z "$REL$COMMIT$RUN_ID$ARCHIVE_SHA$PROVENANCE_SHA$CONTINUITY_SHA$DEPLOY_SHA$PAIR_SHA$BACKUP_SHA" ]] || {
            echo 'ERROR: no-guard mode accepts no recovery bindings.' >&2; exit 64;
        }
        ;;
    recovery)
        [[ "$REL" =~ ^ea_[A-Za-z0-9_]+$ && "$REL" != "$ACTIVE" &&
           "$COMMIT" =~ ^[a-f0-9]{40}$ && "$RUN_ID" =~ ^[a-f0-9]{32}$ ]] || {
            echo 'ERROR: recovery identity invalid.' >&2; exit 64;
        }
        for digest in "$ARCHIVE_SHA" "$PROVENANCE_SHA" "$CONTINUITY_SHA" "$DEPLOY_SHA" "$PAIR_SHA" "$BACKUP_SHA"; do
            [[ "$digest" =~ ^[a-f0-9]{64}$ ]] || { echo 'ERROR: recovery binding invalid.' >&2; exit 64; }
        done
        ;;
    *) usage >&2; exit 64 ;;
esac
if (( RUN == 0 )); then
    [[ -z "$CONFIRM" ]] || { echo 'ERROR: confirmation requires --run-read-only.' >&2; exit 64; }
    printf 'schema=bound_release_recovery_inspect_plan.v1\nstatus=plan_only\nmode=%s\ntarget=booking-server\n' "$MODE"
    exit 0
fi
[[ "$CONFIRM" == ROB-621 ]] || { echo 'ERROR: read-only confirmation required.' >&2; exit 64; }
[[ "$INSPECTION_TIMEOUT_SECONDS" =~ ^([1-9]|[1-5][0-9]|60)$ ]] || {
    echo 'ERROR: inspection timeout must be between 1 and 60 seconds.' >&2; exit 64;
}
[[ "$(git -C "$PROJECT" symbolic-ref --short HEAD)" == main &&
   "$(git -C "$PROJECT" rev-parse HEAD)" == "$SOURCE_COMMIT" ]] || {
    echo 'ERROR: reviewed current main commit required.' >&2; exit 70;
}
git -C "$PROJECT" diff --quiet HEAD && git -C "$PROJECT" diff --cached --quiet || {
    echo 'ERROR: tracked source is modified.' >&2; exit 70;
}
git -C "$PROJECT" ls-files --error-unmatch "$SOURCE" >/dev/null || {
    echo 'ERROR: inspector source is not tracked.' >&2; exit 70;
}
[[ -f "$PROJECT/$SOURCE" && ! -L "$PROJECT/$SOURCE" ]] || {
    echo 'ERROR: inspector source unavailable.' >&2; exit 70;
}
source_sha="$(git -C "$PROJECT" cat-file blob "$SOURCE_COMMIT:$SOURCE" | shasum -a 256 | awk '{print $1}')"
[[ "$(shasum -a 256 "$PROJECT/$SOURCE" | awk '{print $1}')" == "$source_sha" ]] || {
    echo 'ERROR: inspector differs from reviewed source.' >&2; exit 70;
}
remote_args=(--mode "$MODE" --expected-active-release "$ACTIVE")
if [[ "$MODE" == recovery ]]; then
    remote_args+=(--release "$REL" --commit "$COMMIT" --run-id "$RUN_ID"
        --archive-sha "$ARCHIVE_SHA" --provenance-sha "$PROVENANCE_SHA"
        --continuity-sha "$CONTINUITY_SHA" --deploy-sha "$DEPLOY_SHA"
        --pair-helper-sha "$PAIR_SHA" --backup-helper-sha "$BACKUP_SHA")
fi
receipt_file="$(mktemp "${TMPDIR:-/tmp}/fh-recovery-inspect.XXXXXX")"
trap 'rm -f -- "$receipt_file"' EXIT
if git -C "$PROJECT" cat-file blob "$SOURCE_COMMIT:$SOURCE" | python3 -I -B -c '
import hashlib
import subprocess
import sys

source = sys.stdin.buffer.read(1024 * 1024 + 1)
if not source or len(source) > 1024 * 1024 or hashlib.sha256(source).hexdigest() != sys.argv[2]:
    sys.exit(70)
command = [
    "ssh", "-o", "BatchMode=yes", "-o", "ConnectTimeout=12", sys.argv[4],
    "/usr/bin/python3", "-I", "-B", "-", *sys.argv[5:],
]
try:
    with open(sys.argv[1], "wb") as receipt:
        completed = subprocess.run(
            command, input=source, stdout=receipt, stderr=subprocess.DEVNULL,
            timeout=int(sys.argv[3]), check=False,
        )
except subprocess.TimeoutExpired:
    sys.exit(124)
except OSError:
    sys.exit(127)
sys.exit(completed.returncode if completed.returncode >= 0 else 128 - completed.returncode)
' "$receipt_file" "$source_sha" "$INSPECTION_TIMEOUT_SECONDS" "$TARGET" "${remote_args[@]}"; then
    remote_rc=0
else
    remote_rc=$?
fi
[[ "$(shasum -a 256 "$PROJECT/$SOURCE" | awk '{print $1}')" == "$source_sha" ]] || {
    echo 'schema=bound_release_recovery_inspect.v1'; echo 'status=blocked'; echo 'result_class=local_source_changed'; exit 70;
}

python3 -I -B - "$receipt_file" "$remote_rc" <<'PY'
import json
import sys

allowed_pass = {'no_pending_guard', 'terminal_deployed', 'terminal_confirmed_failed'}
allowed_busy = {'lock_busy'}
allowed_block = {
    'input_invalid', 'host_invalid', 'path_unsafe', 'lock_unsafe',
    'guard_present', 'guard_missing', 'guard_unsafe', 'guard_unknown',
    'guard_changed', 'guard_invalid', 'guard_mismatch',
    'intent_missing', 'intent_unsafe', 'intent_unknown', 'intent_changed',
    'intent_invalid', 'intent_mismatch', 'binding_mismatch',
    'receipt_missing', 'receipt_unsafe', 'receipt_unknown', 'receipt_changed',
    'receipt_invalid', 'marker_missing', 'marker_unsafe', 'marker_unknown',
    'marker_changed', 'marker_invalid', 'marker_mismatch', 'marker_conflict',
    'recovery_required_exit31', 'recovery_required_exit32',
    'recovery_required_exit143', 'observation_unknown',
}
try:
    with open(sys.argv[1], 'rb') as handle:
        raw = handle.read(1025)
    value = json.loads(raw)
    code = int(sys.argv[2])
    if (len(raw) > 1024 or not isinstance(value, dict) or
            set(value) != {'schema', 'status', 'result_class'} or
            value['schema'] != 'bound_release_recovery_inspect.v1' or
            (code == 0 and (value['status'] != 'passed' or value['result_class'] not in allowed_pass)) or
            (code == 75 and (value['status'] != 'blocked' or value['result_class'] not in allowed_busy)) or
            (code == 70 and (value['status'] != 'blocked' or value['result_class'] not in allowed_block)) or
            code not in (0, 70, 75)):
        raise ValueError('contradictory receipt')
    print('schema=bound_release_recovery_inspect.v1')
    print('status=' + value['status'])
    print('result_class=' + value['result_class'])
    sys.exit(code)
except (OSError, ValueError, KeyError, TypeError):
    print('schema=bound_release_recovery_inspect.v1')
    print('status=blocked')
    print('result_class=transport_or_receipt_unknown')
    sys.exit(70)
PY
