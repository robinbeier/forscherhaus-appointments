#!/usr/bin/env bash
# v1.1 — Build & (optional) Upload eines Easy!Appointments Release-Archivs (macOS-freundlich)
# - Schließt Konfig-/Storage-Dateien, VCS-Daten & Dev-Artefakte aus (ankert am Projekt-Root)
# - Verifiziert, dass application/config/config.php im Archiv ist
# - Zeigt lokale SHA-256; verifiziert nach Upload die Remote-SHA-256 und den Archivinhalt

set -Eeuo pipefail
umask 022

PROJECT="${PROJECT:-$PWD}"
REL=""
EXPECTED_COMMIT=""
UPLOAD="${UPLOAD:-root@188.245.244.123}"   # Ziel-Host (user@host); mit --skip-upload deaktivieren
REMOTE_DIR="${REMOTE_DIR:-/root/releases}" # Zielverzeichnis auf dem Server
DRYRUN=0

usage() {
  cat <<'USAGE'
Usage: build_release.sh --expected-commit FULL_SHA [--rel REL] [--project DIR] [--upload user@host] [--remote-dir DIR] [--skip-upload] [--dry-run]
  --rel REL          Release-ID (Standard: ea_YYYYMMDD_HHMM)
  --expected-commit  Exact lowercase 40-hex commit exported into the release
  --project DIR      Projektverzeichnis (Standard: aktuelles Verzeichnis)
  --upload TARGET    Upload-Ziel (user@host). Mit --skip-upload deaktivieren
  --remote-dir DIR   Remote-Verzeichnis (Default: /root/releases)
  --skip-upload      Archiv NICHT hochladen
  --dry-run          Nur anzeigen, was passieren würde (keine Änderungen)
Beispiel:
  ./build_release.sh --expected-commit "$(git rev-parse HEAD)" --rel ea_20251005_2000 --project "/Users/robinbeier/Documents/forscherhaus-appointments"
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --rel) REL="$2"; shift 2;;
    --expected-commit) EXPECTED_COMMIT="$2"; shift 2;;
    --project) PROJECT="$2"; shift 2;;
    --upload) UPLOAD="$2"; shift 2;;
    --remote-dir) REMOTE_DIR="$2"; shift 2;;
    --skip-upload) UPLOAD=""; shift 1;;
    --dry-run) DRYRUN=1; shift 1;;
    -h|--help) usage; exit 0;;
    *) echo "Unbekannte Option: $1"; usage; exit 1;;
  esac
done

[[ -n "$REL" ]] || REL="ea_$(date +%Y%m%d_%H%M)"
[[ "$EXPECTED_COMMIT" =~ ^[0-9a-f]{40}$ ]] || { echo "[!] --expected-commit is required and must be a full lowercase commit." >&2; exit 1; }

if [[ ! "$REL" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$ ]]; then
  echo "[!] Release-ID muss 1-128 Zeichen lang sein, alphanumerisch beginnen und darf danach A-Z a-z 0-9 . _ - enthalten." >&2
  exit 1
fi
LOG="/tmp/build_ea_${REL}.log"
exec > >(tee -a "$LOG") 2>&1

run() {
  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] $*"
  else
    bash -lc "$*"
  fi
}

prune_children_except() (
  set -Eeuo pipefail
  local directory="$1"
  shift
  local child base allowed keep
  shopt -s nullglob dotglob
  for child in "$directory"/*; do
    base="${child##*/}"
    keep=0
    for allowed in "$@"; do
      if [[ "$base" == "$allowed" ]]; then
        keep=1
        break
      fi
    done
    if [[ "$keep" -eq 0 ]]; then
      rm -rf -- "$child"
    fi
  done
)

echo "[i] Build Easy!Appointments Release"
echo "    Project : $PROJECT"
echo "    Release : $REL"
echo "    Upload  : ${UPLOAD:-<kein Upload>}"
echo "    Remote  : $REMOTE_DIR"
echo "    Logfile : $LOG"

cd "$PROJECT"
PROJECT="$(pwd -P)"
[[ "$(git rev-parse --verify HEAD)" == "$EXPECTED_COMMIT" ]] || { echo "[!] HEAD does not match --expected-commit." >&2; exit 1; }
git diff --quiet --exit-code && git diff --cached --quiet --exit-code || {
  echo "[!] Tracked source is dirty; refusing a release build." >&2; exit 1;
}

# Vorbedingung: CI-Config muss lokal existieren (sie gehört zum Code!)
if [[ ! -f "application/config/config.php" ]]; then
  echo "[!] application/config/config.php fehlt LOKAL. Abbruch."; exit 1
fi

OUTPUT="$(mktemp -d "/tmp/${REL}.output.XXXXXX")"
OUTPUT="$(cd "$OUTPUT" && pwd -P)"
ARCHIVE="$OUTPUT/${REL}.tar.gz"
PROVENANCE="$OUTPUT/${REL}.build-provenance.json"
STAGE="$(mktemp -d "/tmp/${REL}.stage.XXXXXX")"
STAGE="$(cd "$STAGE" && pwd -P)"
cleanup() { rm -rf "$STAGE"; }
trap cleanup EXIT

echo "[i] Stage-Verzeichnis: $STAGE"

command -v composer >/dev/null 2>&1 || {
  echo "[!] composer command not found locally. Abbruch."; exit 1
}
command -v php >/dev/null 2>&1 || {
  echo "[!] php command not found locally. Abbruch."; exit 1
}
command -v npm >/dev/null 2>&1 || {
  echo "[!] npm command not found locally. Abbruch."; exit 1
}
command -v node >/dev/null 2>&1 || {
  echo "[!] node command not found locally. Abbruch."; exit 1
}

echo "[i] Refresh frontend release assets"
if [[ "$DRYRUN" -eq 0 ]]; then
  npm run assets:refresh
  git diff --quiet --exit-code -- assets/css assets/js assets/vendor || {
    echo "[!] Frontend asset refresh changed tracked source inputs in assets/css, assets/js, or assets/vendor." >&2
    git status --short -- assets/css assets/js assets/vendor >&2 || true
    exit 1
  }
else
  echo "[DRY-RUN] Würde npm run assets:refresh ausführen"
  echo "[DRY-RUN] Würde sicherstellen, dass assets/css, assets/js und assets/vendor danach keinen Diff haben"
fi

# 1) Stage befüllen (Root-config, runtime storage, and local build artifacts ausschließen; ankern!)
if [[ "$DRYRUN" -eq 0 ]]; then
  git archive --format=tar "$EXPECTED_COMMIT" | tar -xf - -C "$STAGE"
  rm -rf "$STAGE/storage" "$STAGE/build" "$STAGE/node_modules" "$STAGE/vendor" "$STAGE/tests"

  # Zero-surprise replays on the deployment host shell into docker compose
  # using the root compose file plus the dedicated override. Keep only the
  # runtime docker assets required for that flow, not local container data.
  prune_children_except "$STAGE/docker" 'compose.zero-surprise.yml' 'php-fpm' 'nginx'
  prune_children_except "$STAGE/docker/nginx" 'nginx.conf'

  # Compiled frontend outputs are intentionally ignored by Git, so the exact
  # commit export cannot contain them. Derive the exhaustive runtime manifest
  # from the exported JS/SCSS sources plus the closed vendor-output contract,
  # then copy exactly those refreshed files. Never copy an arbitrary ignored or
  # untracked tree.
  GENERATED_ASSET_LIST="$OUTPUT/generated-release-assets.list"
  php "$STAGE/scripts/release-gate/validate_release_artifact.php" \
    --root="$STAGE" --print-generated-runtime-paths > "$GENERATED_ASSET_LIST"
  GENERATED_ASSET_COUNT=0
  while IFS= read -r asset_path; do
    case "$asset_path" in
      assets/css/*.css|assets/js/*.min.js|assets/vendor/*) ;;
      *) continue ;;
    esac
    [[ "$asset_path" =~ ^assets/[A-Za-z0-9.@_/-]+$ ]] || {
      echo "[!] Generated release asset path is unsafe: $asset_path" >&2
      exit 1
    }
    ASSET_SOURCE="$PROJECT/$asset_path"
    ASSET_TARGET="$STAGE/$asset_path"
    [[ -f "$ASSET_SOURCE" && ! -L "$ASSET_SOURCE" ]] || {
      echo "[!] Refreshed release asset is missing or unsafe: $asset_path" >&2
      exit 1
    }
    mkdir -p -- "$(dirname "$ASSET_TARGET")"
    /usr/bin/install -m 0644 "$ASSET_SOURCE" "$ASSET_TARGET"
    GENERATED_ASSET_COUNT=$((GENERATED_ASSET_COUNT + 1))
  done < "$GENERATED_ASSET_LIST"
  [[ "$GENERATED_ASSET_COUNT" -gt 0 ]] || {
    echo "[!] Release validator declared no generated frontend assets." >&2
    exit 1
  }
  rm -f -- "$GENERATED_ASSET_LIST"
else
  echo "[DRY-RUN] rsync Projekt → Stage (excl. /config.php, /storage, /build, /.git, /.DS_Store, /node_modules, /vendor, /easyappointments-*.zip, /tests, /docker)"
  echo "[DRY-RUN] Würde docker/compose.zero-surprise.yml sowie docker/php-fpm und docker/nginx/nginx.conf gezielt ins Stage kopieren"
  echo "[DRY-RUN] Würde das vollständige, aus Commit-Quellen und Vendor-Vertrag abgeleitete Runtime-Assetmanifest ins Stage kopieren"
fi

# 2) Safety-Check: CI-Config muss jetzt im Stage existieren
if [[ "$DRYRUN" -eq 0 ]]; then
  if [[ ! -f "$STAGE/application/config/config.php" ]]; then
    echo "[!] CI-Config fehlt im Stage: $STAGE/application/config/config.php"; exit 1
  fi
  php scripts/release-gate/validate_release_artifact.php --root="$STAGE"
else
  echo "[DRY-RUN] Würde prüfen: $STAGE/application/config/config.php existiert"
  echo "[DRY-RUN] Würde das Stage-Verzeichnis mit scripts/release-gate/validate_release_artifact.php prüfen"
fi

# 2b) Produktions-Vendor im Stage aus Lockfile erzeugen, damit Releases nicht
# versehentlich lokale Dev-Abhängigkeiten oder einen falschen Platform-Check mitbringen.
if [[ "$DRYRUN" -eq 0 ]]; then
  composer install \
    --working-dir="$STAGE" \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --optimize-autoloader \
    --classmap-authoritative
else
  echo "[DRY-RUN] Würde composer install --working-dir='$STAGE' --no-dev --prefer-dist --no-interaction --optimize-autoloader --classmap-authoritative ausführen"
fi

# 3) Archiv bauen
if [[ "$DRYRUN" -eq 0 ]]; then
  # macOS: avoid Apple metadata/xattrs in the release tarball to keep remote
  # validation output small and reproducible across GNU tar environments.
  COPYFILE_DISABLE=1 tar --no-mac-metadata --no-xattrs -C "$STAGE" -czf "$ARCHIVE" .
  # 4) Archivinhalt prüfen (toleriert optionales './')
  tar -tzf "$ARCHIVE" | tr -d '\r' | grep -E '^(\./)?application/config/config.php$' >/dev/null \
    && echo "[OK] CI-Config im Archiv" \
    || { echo "[!] CI-Config fehlt im Archiv"; exit 1; }
  php scripts/release-gate/validate_release_artifact.php --archive="$ARCHIVE"
  php scripts/ops/create_release_build_provenance.php \
    --release="$REL" --commit="$EXPECTED_COMMIT" --stage="$STAGE" --archive="$ARCHIVE" \
    --build-script="$STAGE/build_release.sh" --composer-lock="$STAGE/composer.lock" \
    --package-lock="$STAGE/package-lock.json" --deploy-script="$STAGE/deploy_ea.sh" > "$PROVENANCE"
else
  echo "[DRY-RUN] Würde Archiv erstellen: $ARCHIVE"
  echo "[DRY-RUN] Würde CI-Config im Archiv verifizieren"
  echo "[DRY-RUN] Würde das Release-Archiv mit scripts/release-gate/validate_release_artifact.php prüfen"
fi

# 5) Lokale SHA-256 ausgeben (macOS: shasum)
if [[ "$DRYRUN" -eq 0 ]]; then
  LOCAL_SHA=$(shasum -a 256 "$ARCHIVE" | awk '{print $1}')
  PROVENANCE_SHA=$(shasum -a 256 "$PROVENANCE" | awk '{print $1}')
  echo "[i] Local SHA-256: $LOCAL_SHA  $(basename "$ARCHIVE")"
else
  echo "[DRY-RUN] Würde lokale SHA-256 berechnen"
fi

# 6) Optional: Upload + Remote-Verifikation
if [[ -n "${UPLOAD}" ]]; then
  if [[ "$DRYRUN" -eq 0 ]]; then
    [[ "$UPLOAD" =~ ^root@[A-Za-z0-9.-]+$ ]] || { echo "[!] Upload target must be root@host." >&2; exit 1; }
    [[ "$REMOTE_DIR" == "/root/releases" ]] || { echo "[!] Remote release directory must be /root/releases." >&2; exit 1; }
    REMOTE_NONCE=$(php -r 'echo bin2hex(random_bytes(16));')
    ARCHIVE_SIZE=$(wc -c < "$ARCHIVE" | tr -d ' ')
    PROVENANCE_SIZE=$(wc -c < "$PROVENANCE" | tr -d ' ')
    ARCHIVE_TEMP=".${REL}.tar.gz.upload-${REMOTE_NONCE}"
    PROVENANCE_TEMP=".${REL}.build-provenance.json.upload-${REMOTE_NONCE}"
    remote_cleanup() {
      ssh "$UPLOAD" /usr/bin/rm -f -- "$REMOTE_DIR/$ARCHIVE_TEMP" "$REMOTE_DIR/$PROVENANCE_TEMP" >/dev/null 2>&1 || true
    }
    trap 'remote_cleanup; cleanup' EXIT

    PREPARE_STATUS=$(ssh "$UPLOAD" /usr/bin/python3 -I -B - --prepare "$REMOTE_DIR" \
      < scripts/ops/libexec/publish_release_pair_v1.py)
    [[ "$PREPARE_STATUS" == "ready" ]] || { echo "[!] Remote release root preparation failed." >&2; exit 1; }
    scp -- "$ARCHIVE" "$UPLOAD:$REMOTE_DIR/$ARCHIVE_TEMP"
    scp -- "$PROVENANCE" "$UPLOAD:$REMOTE_DIR/$PROVENANCE_TEMP"
    ssh "$UPLOAD" /usr/bin/chmod 0600 "$REMOTE_DIR/$ARCHIVE_TEMP" "$REMOTE_DIR/$PROVENANCE_TEMP"
    PUBLISH_STATUS=$(ssh "$UPLOAD" /usr/bin/python3 -I -B - \
      "$REMOTE_DIR" "$REL" "$REMOTE_NONCE" "$LOCAL_SHA" "$ARCHIVE_SIZE" \
      "$PROVENANCE_SHA" "$PROVENANCE_SIZE" \
      < scripts/ops/libexec/publish_release_pair_v1.py)
    [[ "$PUBLISH_STATUS" =~ ^(published|attached):(published|attached)$ ]] || {
      echo "[!] Remote release pair publication returned an invalid status." >&2; exit 1;
    }
    trap cleanup EXIT
    echo "[OK] Remote release pair: $PUBLISH_STATUS"
  else
    echo "[DRY-RUN] (würde Archiv und Provenance als verifiziertes No-Clobber-Paar veröffentlichen)"
  fi
fi

echo "[✓] Build abgeschlossen: $ARCHIVE"
echo "    Provenance: $PROVENANCE"
echo "    Provenance SHA-256: ${PROVENANCE_SHA:-<dry-run>}"
echo "    Log: $LOG"
if [[ -n "${UPLOAD}" ]]; then
  echo "    Hochgeladen nach: ${UPLOAD}:${REMOTE_DIR}/$(basename "$ARCHIVE")"
fi
