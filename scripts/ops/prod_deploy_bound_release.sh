#!/usr/bin/env bash
# Admit and invoke exactly one already-built, reviewed production release.
set -Eeuo pipefail
umask 077

PROJECT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
TARGET='root@booking-server'
REL=''; COMMIT=''; ACTIVE=''; ARCHIVE=''; PROVENANCE=''; EXECUTE=0; CONFIRM=''

usage() {
    cat <<'USAGE'
Usage: prod_deploy_bound_release.sh --rel EA_ID --expected-commit FULL_SHA --expected-active-release EA_ID --archive PATH --provenance PATH [--execute --confirm-live-deploy ROB-618]

Default is plan-only. A live invocation requires the reviewed main/CI release
pair already published on production and a fresh verified ROB-466/ROB-461
backup handoff. The command never builds, uploads, backs up, changes timers,
retries, or uses breakglass. It admits and calls the existing deploy_ea.sh once.
USAGE
}

while (( $# > 0 )); do
    case "$1" in
        --rel) REL="${2:-}"; shift 2 ;;
        --expected-commit) COMMIT="${2:-}"; shift 2 ;;
        --expected-active-release) ACTIVE="${2:-}"; shift 2 ;;
        --archive) ARCHIVE="${2:-}"; shift 2 ;;
        --provenance) PROVENANCE="${2:-}"; shift 2 ;;
        --execute) EXECUTE=1; shift ;;
        --confirm-live-deploy) CONFIRM="${2:-}"; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) usage >&2; exit 64 ;;
    esac
done

[[ "$REL" =~ ^ea_[A-Za-z0-9_]+$ && "$ACTIVE" =~ ^ea_[A-Za-z0-9_]+$ && "$COMMIT" =~ ^[a-f0-9]{40}$ ]] || {
    echo 'ERROR: invalid release or commit identity.' >&2; exit 64;
}
[[ "$REL" != "$ACTIVE" ]] || { echo 'ERROR: active release cannot be the deployment candidate.' >&2; exit 64; }
[[ "$ARCHIVE" == /* && "$PROVENANCE" == /* ]] || { echo 'ERROR: absolute release paths required.' >&2; exit 64; }
if (( EXECUTE == 0 )); then
    [[ -z "$CONFIRM" ]] || { echo 'ERROR: confirmation requires --execute.' >&2; exit 64; }
    printf 'schema=bound_release_deploy_plan.v1\nstatus=plan_only\ntarget=booking-server\n'
    exit 0
fi
[[ "$CONFIRM" == ROB-618 ]] || { echo 'ERROR: live deploy confirmation required.' >&2; exit 64; }
[[ "$(git -C "$PROJECT" symbolic-ref --short HEAD)" == main && "$(git -C "$PROJECT" rev-parse HEAD)" == "$COMMIT" ]] || {
    echo 'ERROR: clean reviewed main commit required.' >&2; exit 70;
}
git -C "$PROJECT" diff --quiet HEAD && git -C "$PROJECT" diff --cached --quiet || {
    echo 'ERROR: tracked source is modified.' >&2; exit 70;
}

readonly sources=(
    'deploy_ea.sh'
    'scripts/ops/libexec/release_pair_admission_v1.py'
    'scripts/ops/libexec/backup_handoff_admission_v1.py'
    'scripts/ops/libexec/bound_release_deploy_v1.py'
)
for source in "${sources[@]}"; do
    git -C "$PROJECT" ls-files --error-unmatch "$source" >/dev/null || { echo 'ERROR: untracked operator source.' >&2; exit 70; }
    [[ -f "$PROJECT/$source" && ! -L "$PROJECT/$source" ]] || { echo 'ERROR: operator source unavailable.' >&2; exit 70; }
done

ARCHIVE_SHA="$(shasum -a 256 "$ARCHIVE" | awk '{print $1}')"
PROVENANCE_SHA="$(shasum -a 256 "$PROVENANCE" | awk '{print $1}')"
ARCHIVE_SIZE="$(wc -c < "$ARCHIVE" | tr -d ' ')"
PROVENANCE_SIZE="$(wc -c < "$PROVENANCE" | tr -d ' ')"

# Both release verifiers and all code they load are extracted from the reviewed
# commit. The operator account and its same-UID processes remain trusted.
snapshot_dir="$(mktemp -d "${TMPDIR:-/tmp}/fh-bound-source.XXXXXX")"
trap 'rm -rf -- "$snapshot_dir"' EXIT
git -C "$PROJECT" archive "$COMMIT" \
    build_release.sh composer.lock package-lock.json deploy_ea.sh \
    scripts/ops/verify_local_release_pair.php \
    scripts/ops/lib/ReleaseBuildProvenanceProducerV1.php \
    scripts/ops/lib/DeploymentEvidenceAuthorityV1.php \
    scripts/ops/lib/DeploymentContractV1.php \
    scripts/ops/libexec/inspect_release_archive_v1.py \
    scripts/release-gate/validate_release_artifact.php \
    scripts/release-gate/lib/ReleaseArtifactValidator.php \
    scripts/ops/prod_release_readiness_preflight.sh \
    scripts/ops/lib/prod_common.sh \
    scripts/ops/libexec/backup_set_producer_v1.py \
    scripts/ops/libexec/backup_timer_transition_v1.py \
    scripts/ops/libexec/deployment_dump_attestation_v1.py \
    | tar -x -C "$snapshot_dir"
for source in \
    build_release.sh composer.lock package-lock.json deploy_ea.sh \
    scripts/ops/verify_local_release_pair.php \
    scripts/ops/lib/ReleaseBuildProvenanceProducerV1.php \
    scripts/ops/lib/DeploymentEvidenceAuthorityV1.php \
    scripts/ops/lib/DeploymentContractV1.php \
    scripts/ops/libexec/inspect_release_archive_v1.py \
    scripts/release-gate/validate_release_artifact.php \
    scripts/release-gate/lib/ReleaseArtifactValidator.php \
    scripts/ops/prod_release_readiness_preflight.sh \
    scripts/ops/lib/prod_common.sh \
    scripts/ops/libexec/backup_set_producer_v1.py \
    scripts/ops/libexec/backup_timer_transition_v1.py \
    scripts/ops/libexec/deployment_dump_attestation_v1.py; do
    [[ -f "$snapshot_dir/$source" && ! -L "$snapshot_dir/$source" ]] || {
        echo 'ERROR: commit-bound verification source unavailable.' >&2; exit 70;
    }
done

if [[ "$(php "$snapshot_dir/scripts/ops/verify_local_release_pair.php" \
    --release="$REL" --commit="$COMMIT" \
    --archive="$ARCHIVE" --provenance="$PROVENANCE")" != verified ]] ||
   ! php "$snapshot_dir/scripts/release-gate/validate_release_artifact.php" \
    --archive="$ARCHIVE" >/dev/null; then
    echo 'ERROR: local reviewed release pair failed verification.' >&2; exit 70
fi

if [[ "$(shasum -a 256 "$ARCHIVE" | awk '{print $1}')" != "$ARCHIVE_SHA" ||
      "$(shasum -a 256 "$PROVENANCE" | awk '{print $1}')" != "$PROVENANCE_SHA" ||
      "$(wc -c < "$ARCHIVE" | tr -d ' ')" != "$ARCHIVE_SIZE" ||
      "$(wc -c < "$PROVENANCE" | tr -d ' ')" != "$PROVENANCE_SIZE" ]]; then
    echo 'ERROR: local release pair changed after verification.' >&2; exit 70
fi
commit_blob_sha() {
    git -C "$PROJECT" cat-file blob "$COMMIT:$1" | shasum -a 256 | awk '{print $1}'
}
DEPLOY_SHA="$(commit_blob_sha 'deploy_ea.sh')"
PAIR_SHA="$(commit_blob_sha 'scripts/ops/libexec/release_pair_admission_v1.py')"
BACKUP_SHA="$(commit_blob_sha 'scripts/ops/libexec/backup_handoff_admission_v1.py')"
# Keep the exact commit-bound runner bytes in memory. The worktree path may be
# edited after the clean-tree check; it must never become root-executed input.
RUNNER_B64="$(git -C "$PROJECT" cat-file blob "$COMMIT:scripts/ops/libexec/bound_release_deploy_v1.py" | base64 | tr -d '\n')"
[[ -n "$RUNNER_B64" ]] || { echo 'ERROR: reviewed runner unavailable.' >&2; exit 70; }
decode_runner() {
    python3 -I -B -c 'import base64,sys;sys.stdout.buffer.write(base64.b64decode(sys.stdin.buffer.read(),validate=True))'
}
RUNNER_SHA="$(printf '%s' "$RUNNER_B64" | decode_runner | shasum -a 256 | awk '{print $1}')"
[[ "$(shasum -a 256 "$PROJECT/scripts/ops/libexec/bound_release_deploy_v1.py" | awk '{print $1}')" == "$RUNNER_SHA" ]] || {
    echo 'ERROR: local runner differs from reviewed commit.' >&2; exit 70;
}

# The readiness script also sends a read-only shell program to production.
git_dir="$(git -C "$PROJECT" rev-parse --absolute-git-dir)"
preflight="$(GIT_DIR="$git_dir" GIT_WORK_TREE="$snapshot_dir" \
    bash "$snapshot_dir/scripts/ops/prod_release_readiness_preflight.sh" \
    --expected-active-release "$ACTIVE")" || {
    echo 'ERROR: production readiness is not established.' >&2; exit 70
}
[[ "$preflight" == *$'schema=production_release_readiness.v1\nstatus=passed\nresult_class=readiness_verified\n'* ]] || {
    echo 'ERROR: production readiness receipt is contradictory.' >&2; exit 70
}

# This observation remains private to the operator invocation; the remote
# admission reopens and validates the protected file against this commitment.
continuity_sha="$(ssh -o BatchMode=yes -o ConnectTimeout=12 "$TARGET" \
    /usr/bin/sha256sum /root/backups/easyappointments/backup_continuity_state.json \
    2>/dev/null | awk 'NR == 1 {print $1}')" || {
    echo 'ERROR: backup continuity observation unavailable.' >&2; exit 70
}
[[ "$continuity_sha" =~ ^[a-f0-9]{64}$ ]] || { echo 'ERROR: backup continuity observation invalid.' >&2; exit 70; }

run_id="$(openssl rand -hex 16)"
[[ "$run_id" =~ ^[a-f0-9]{32}$ ]] || { echo 'ERROR: unique run identity unavailable.' >&2; exit 70; }
printf 'schema=bound_release_deploy_attempt.v1\nrun_id=%s\n' "$run_id"
receipt_file="$(mktemp "${TMPDIR:-/tmp}/fh-bound-deploy.XXXXXX")"
trap 'rm -f -- "$receipt_file"; rm -rf -- "$snapshot_dir"' EXIT

if printf '%s' "$RUNNER_B64" | decode_runner | ssh -o BatchMode=yes -o ConnectTimeout=12 "$TARGET" \
    /usr/bin/python3 -I -B - \
    --release "$REL" --expected-active-release "$ACTIVE" --commit "$COMMIT" \
    --archive-sha "$ARCHIVE_SHA" --archive-size "$ARCHIVE_SIZE" \
    --provenance-sha "$PROVENANCE_SHA" --provenance-size "$PROVENANCE_SIZE" \
    --continuity-sha "$continuity_sha" --deploy-sha "$DEPLOY_SHA" \
    --pair-helper-sha "$PAIR_SHA" --backup-helper-sha "$BACKUP_SHA" \
    --run-id "$run_id" \
    > "$receipt_file" 2>/dev/null; then
    remote_rc=0
else
    remote_rc=$?
fi

[[ "$(shasum -a 256 "$PROJECT/scripts/ops/libexec/bound_release_deploy_v1.py" | awk '{print $1}')" == "$RUNNER_SHA" ]] || {
    echo 'schema=bound_release_deploy.v1'; echo 'status=failed'; echo 'result_class=local_operator_identity_unknown'; exit 70;
}

python3 -I -B - "$receipt_file" "$remote_rc" <<'PY'
import json, sys
try:
    with open(sys.argv[1], 'rb') as handle:
        raw = handle.read(1025)
    value = json.loads(raw)
    code = int(sys.argv[2])
    if (len(raw) > 1024 or not isinstance(value, dict) or
            set(value) != {'schema', 'status', 'result_class'} or
            value['schema'] != 'bound_release_deploy.v1' or
            value['status'] not in ('passed', 'failed') or
            not isinstance(value['result_class'], str) or
            len(value['result_class']) > 80 or
            (code == 0 and (value['status'], value['result_class']) != ('passed', 'deployed')) or
            (code not in (0, 30, 31, 32, 70, 75, 143)) or
            (code != 0 and value['status'] != 'failed') or
            (code == 30 and value['result_class'] != 'confirmed_failed') or
            (code in (31, 32, 143) and value['result_class'] != 'recovery_required') or
            (code == 75 and value['result_class'] != 'lock_busy')):
        raise ValueError('contradictory result')
    print('schema=bound_release_deploy.v1')
    print('status=' + value['status'])
    print('result_class=' + value['result_class'])
    sys.exit(0 if code == 0 else 70)
except (OSError, ValueError, KeyError, TypeError):
    print('schema=bound_release_deploy.v1')
    print('status=failed')
    print('result_class=transport_or_receipt_unknown')
    sys.exit(70)
PY
