#!/usr/bin/env bash
# Publish the exact release pair produced by an earlier --skip-upload build.
set -Eeuo pipefail
umask 077

PROJECT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
REL=""
EXPECTED_COMMIT=""
ARCHIVE=""
PROVENANCE=""
EXPECTED_ARCHIVE_SHA=""
EXPECTED_PROVENANCE_SHA=""
UPLOAD=""
REMOTE_DIR="/root/releases"
VERIFY_ONLY=0

usage() {
  cat <<'USAGE'
Usage: publish_existing_release.sh --rel REL --expected-commit FULL_SHA --archive PATH --provenance PATH --expected-archive-sha256 SHA --expected-provenance-sha256 SHA [--upload root@host] [--remote-dir /root/releases] [--verify-only]

Verifies and publishes one already-built archive/provenance pair without rebuilding it.
--verify-only performs every local check without contacting production.
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --rel) REL="$2"; shift 2;;
    --expected-commit) EXPECTED_COMMIT="$2"; shift 2;;
    --archive) ARCHIVE="$2"; shift 2;;
    --provenance) PROVENANCE="$2"; shift 2;;
    --expected-archive-sha256) EXPECTED_ARCHIVE_SHA="$2"; shift 2;;
    --expected-provenance-sha256) EXPECTED_PROVENANCE_SHA="$2"; shift 2;;
    --upload) UPLOAD="$2"; shift 2;;
    --remote-dir) REMOTE_DIR="$2"; shift 2;;
    --verify-only) VERIFY_ONLY=1; shift;;
    -h|--help) usage; exit 0;;
    *) usage >&2; exit 64;;
  esac
done

[[ "$REL" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$ ]] || { echo "[!] Invalid release ID." >&2; exit 64; }
[[ "$EXPECTED_COMMIT" =~ ^[0-9a-f]{40}$ ]] || { echo "[!] Expected commit must be a full SHA." >&2; exit 64; }
[[ "$EXPECTED_ARCHIVE_SHA" =~ ^[0-9a-f]{64}$ && "$EXPECTED_PROVENANCE_SHA" =~ ^[0-9a-f]{64}$ ]] || {
  echo "[!] Both pinned release SHA-256 values are required." >&2; exit 64;
}
[[ "$ARCHIVE" == /* && "$PROVENANCE" == /* ]] || { echo "[!] Absolute release paths required." >&2; exit 64; }
[[ "$REMOTE_DIR" == "/root/releases" ]] || { echo "[!] Unexpected remote release directory." >&2; exit 64; }
if [[ "$VERIFY_ONLY" -eq 0 ]]; then
  [[ "$UPLOAD" =~ ^root@[A-Za-z0-9.-]+$ ]] || { echo "[!] Upload target must be root@host." >&2; exit 64; }
else
  [[ -z "$UPLOAD" ]] || { echo "[!] --verify-only cannot upload." >&2; exit 64; }
fi

[[ "$(git -C "$PROJECT" rev-parse --verify HEAD)" == "$EXPECTED_COMMIT" ]] || {
  echo "[!] Current checkout is not the reviewed build commit." >&2; exit 70;
}
git -C "$PROJECT" diff --quiet --exit-code && git -C "$PROJECT" diff --cached --quiet --exit-code || {
  echo "[!] Tracked source is dirty; release publication rejected." >&2; exit 70;
}

[[ "$(php "$PROJECT/scripts/ops/verify_local_release_pair.php" \
  --release="$REL" --commit="$EXPECTED_COMMIT" \
  --archive="$ARCHIVE" --provenance="$PROVENANCE")" == "verified" ]] || {
  echo "[!] Local release pair verification failed." >&2; exit 70;
}
php "$PROJECT/scripts/release-gate/validate_release_artifact.php" --archive="$ARCHIVE" >/dev/null || {
  echo "[!] Release archive validation failed." >&2; exit 70;
}

ARCHIVE_SHA="$(shasum -a 256 "$ARCHIVE" | awk '{print $1}')"
PROVENANCE_SHA="$(shasum -a 256 "$PROVENANCE" | awk '{print $1}')"
[[ "$ARCHIVE_SHA" == "$EXPECTED_ARCHIVE_SHA" && "$PROVENANCE_SHA" == "$EXPECTED_PROVENANCE_SHA" ]] || {
  echo "[!] Release pair differs from the pinned build hashes." >&2; exit 70;
}
ARCHIVE_SIZE="$(wc -c < "$ARCHIVE" | tr -d ' ')"
PROVENANCE_SIZE="$(wc -c < "$PROVENANCE" | tr -d ' ')"
echo "[OK] Existing release pair verified: $REL at $EXPECTED_COMMIT"
echo "     Archive SHA-256: $ARCHIVE_SHA"
echo "     Provenance SHA-256: $PROVENANCE_SHA"

if [[ "$VERIFY_ONLY" -eq 1 ]]; then
  exit 0
fi

REMOTE_NONCE="$(php -r 'echo bin2hex(random_bytes(16));')"
ARCHIVE_TEMP=".${REL}.tar.gz.upload-${REMOTE_NONCE}"
PROVENANCE_TEMP=".${REL}.build-provenance.json.upload-${REMOTE_NONCE}"
remote_cleanup() {
  ssh "$UPLOAD" /usr/bin/rm -f -- "$REMOTE_DIR/$ARCHIVE_TEMP" "$REMOTE_DIR/$PROVENANCE_TEMP" >/dev/null 2>&1 || true
}
trap remote_cleanup EXIT

PREPARE_STATUS="$(ssh "$UPLOAD" /usr/bin/python3 -I -B - --prepare "$REMOTE_DIR" \
  < "$PROJECT/scripts/ops/libexec/publish_release_pair_v1.py")"
[[ "$PREPARE_STATUS" == "ready" ]] || { echo "[!] Remote release root preparation failed." >&2; exit 70; }
scp -- "$ARCHIVE" "$UPLOAD:$REMOTE_DIR/$ARCHIVE_TEMP"
scp -- "$PROVENANCE" "$UPLOAD:$REMOTE_DIR/$PROVENANCE_TEMP"
ssh "$UPLOAD" /usr/bin/chmod 0600 "$REMOTE_DIR/$ARCHIVE_TEMP" "$REMOTE_DIR/$PROVENANCE_TEMP"

# Publication must never proceed if the local source changed during transfer.
[[ "$(shasum -a 256 "$ARCHIVE" | awk '{print $1}')" == "$ARCHIVE_SHA" ]] || {
  echo "[!] Local archive changed during transfer." >&2; exit 70;
}
[[ "$(shasum -a 256 "$PROVENANCE" | awk '{print $1}')" == "$PROVENANCE_SHA" ]] || {
  echo "[!] Local provenance changed during transfer." >&2; exit 70;
}

PUBLISH_STATUS="$(ssh "$UPLOAD" /usr/bin/python3 -I -B - \
  "$REMOTE_DIR" "$REL" "$REMOTE_NONCE" "$ARCHIVE_SHA" "$ARCHIVE_SIZE" \
  "$PROVENANCE_SHA" "$PROVENANCE_SIZE" \
  < "$PROJECT/scripts/ops/libexec/publish_release_pair_v1.py")"
[[ "$PUBLISH_STATUS" =~ ^(published|attached):(published|attached)$ ]] || {
  echo "[!] Remote release pair publication returned an invalid status." >&2; exit 70;
}
trap - EXIT
echo "[OK] Remote release pair: $PUBLISH_STATUS"
