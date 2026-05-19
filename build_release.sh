#!/usr/bin/env bash
# v1.1 — Build & (optional) Upload eines Easy!Appointments Release-Archivs (macOS-freundlich)
# - Schließt Konfig-/Storage-Dateien, VCS-Daten & Dev-Artefakte aus (ankert am Projekt-Root)
# - Verifiziert, dass application/config/config.php im Archiv ist
# - Zeigt lokale SHA-256; verifiziert nach Upload die Remote-SHA-256 und den Archivinhalt

set -Eeuo pipefail
umask 022

PROJECT="${PROJECT:-$PWD}"
REL=""
UPLOAD="${UPLOAD:-root@188.245.244.123}"   # Ziel-Host (user@host); mit --skip-upload deaktivieren
REMOTE_DIR="${REMOTE_DIR:-/root/releases}" # Zielverzeichnis auf dem Server
DRYRUN=0

usage() {
  cat <<'USAGE'
Usage: build_release.sh [--rel REL] [--project DIR] [--upload user@host] [--remote-dir DIR] [--skip-upload] [--dry-run]
  --rel REL          Release-ID (Standard: ea_YYYYMMDD_HHMM)
  --project DIR      Projektverzeichnis (Standard: aktuelles Verzeichnis)
  --upload TARGET    Upload-Ziel (user@host). Mit --skip-upload deaktivieren
  --remote-dir DIR   Remote-Verzeichnis (Default: /root/releases)
  --skip-upload      Archiv NICHT hochladen
  --dry-run          Nur anzeigen, was passieren würde (keine Änderungen)
Beispiel:
  ./build_release.sh --rel ea_20251005_2000 --project "/Users/robinbeier/Documents/forscherhaus-appointments"
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --rel) REL="$2"; shift 2;;
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

if [[ "$REL" =~ [^A-Za-z0-9._-] ]]; then
  echo "[!] Release-ID enthält ungültige Zeichen (erlaubt: A-Z a-z 0-9 . _ -)." >&2
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

echo "[i] Build Easy!Appointments Release"
echo "    Project : $PROJECT"
echo "    Release : $REL"
echo "    Upload  : ${UPLOAD:-<kein Upload>}"
echo "    Remote  : $REMOTE_DIR"
echo "    Logfile : $LOG"

cd "$PROJECT"

# Vorbedingung: CI-Config muss lokal existieren (sie gehört zum Code!)
if [[ ! -f "application/config/config.php" ]]; then
  echo "[!] application/config/config.php fehlt LOKAL. Abbruch."; exit 1
fi

ARCHIVE="/tmp/${REL}.tar.gz"
STAGE="$(mktemp -d "/tmp/${REL}.XXXXXX")"
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
    echo "[!] Frontend asset refresh produced uncommitted changes in assets/css, assets/js, or assets/vendor." >&2
    echo "[!] Commit the generated frontend artifacts before building a release." >&2
    git status --short -- assets/css assets/js assets/vendor >&2 || true
    exit 1
  }
else
  echo "[DRY-RUN] Würde npm run assets:refresh ausführen"
  echo "[DRY-RUN] Würde sicherstellen, dass assets/css, assets/js und assets/vendor danach keinen Diff haben"
fi

# 1) Stage befüllen (nur Root-config & storage ausschließen; ankern!)
if [[ "$DRYRUN" -eq 0 ]]; then
  rsync -a --delete \
    --exclude '/config.php' \
    --exclude '/storage' \
    --exclude '/.git' \
    --exclude '/.DS_Store' \
    --exclude '/node_modules' \
    --exclude '/vendor' \
    --exclude '/easyappointments-*.zip' \
    --exclude '/tests' \
    --exclude '/docker' \
    ./ "$STAGE/"

  # Zero-surprise replays on the deployment host shell into docker compose
  # using the root compose file plus the dedicated override. Keep only the
  # runtime docker assets required for that flow, not local container data.
  mkdir -p "$STAGE/docker"
  cp docker/compose.zero-surprise.yml "$STAGE/docker/compose.zero-surprise.yml"
  cp -R docker/php-fpm "$STAGE/docker/php-fpm"
  mkdir -p "$STAGE/docker/nginx"
  cp docker/nginx/nginx.conf "$STAGE/docker/nginx/nginx.conf"
else
  echo "[DRY-RUN] rsync Projekt → Stage (excl. /config.php, /storage, /.git, /.DS_Store, /node_modules, /vendor, /easyappointments-*.zip, /tests, /docker)"
  echo "[DRY-RUN] Würde docker/compose.zero-surprise.yml sowie docker/php-fpm und docker/nginx/nginx.conf gezielt ins Stage kopieren"
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
else
  echo "[DRY-RUN] Würde Archiv erstellen: $ARCHIVE"
  echo "[DRY-RUN] Würde CI-Config im Archiv verifizieren"
  echo "[DRY-RUN] Würde das Release-Archiv mit scripts/release-gate/validate_release_artifact.php prüfen"
fi

# 5) Lokale SHA-256 ausgeben (macOS: shasum)
if [[ "$DRYRUN" -eq 0 ]]; then
  LOCAL_SHA=$(shasum -a 256 "$ARCHIVE" | awk '{print $1}')
  echo "[i] Local SHA-256: $LOCAL_SHA  $(basename "$ARCHIVE")"
else
  echo "[DRY-RUN] Würde lokale SHA-256 berechnen"
fi

# 6) Optional: Upload + Remote-Verifikation
if [[ -n "${UPLOAD}" ]]; then
  if [[ "$DRYRUN" -eq 0 ]]; then
    run "ssh '${UPLOAD}' 'mkdir -p \"$REMOTE_DIR\"'"
    run "scp '$ARCHIVE' '${UPLOAD}':'$REMOTE_DIR/'"

    # Remote-Checksumme (Linux: sha256sum)
    REMOTE_SHA=$(ssh "${UPLOAD}" "sha256sum '${REMOTE_DIR}/$(basename "$ARCHIVE")' | awk '{print \$1}'" || true)
    [[ -n "$REMOTE_SHA" ]] && echo "[i] Remote SHA-256: $REMOTE_SHA  $(basename "$ARCHIVE")" || echo "[i] Remote SHA-256: <nicht verfügbar>"

    if [[ -n "$REMOTE_SHA" && -n "${LOCAL_SHA:-}" && "$REMOTE_SHA" != "$LOCAL_SHA" ]]; then
      echo "[!] WARNUNG: Remote-Checksumme ≠ Lokal! Prüfe Netzwerk/Zielhost/Pfade."
    fi

    # Remote-Inhalt prüfen (tolerant ggü. Top-Level-Ordnern)
    ssh "${UPLOAD}" "tar -tzf '${REMOTE_DIR}/$(basename "$ARCHIVE")' 2>/dev/null | tr -d '\r' | grep -E '(^|.*/)(application/config/config\.php)$' >/dev/null \
      && echo '[OK] Remote: CI-Config im Archiv' \
      || { echo '[!] Remote: CI-Config NICHT im Archiv (prüfe Top-Level-Ordner/Upload)'; exit 1; }"

    while IFS= read -r required_path; do
      [[ -n "$required_path" ]] || continue
      ssh "${UPLOAD}" "tar -tzf '${REMOTE_DIR}/$(basename "$ARCHIVE")' 2>/dev/null | tr -d '\r' | grep -F -x './${required_path}' >/dev/null || tar -tzf '${REMOTE_DIR}/$(basename "$ARCHIVE")' 2>/dev/null | tr -d '\r' | grep -F -x '${required_path}' >/dev/null" \
        && echo "[OK] Remote: ${required_path}" \
        || { echo "[!] Remote: required artifact fehlt im Archiv: ${required_path}"; exit 1; }
    done < <(php scripts/release-gate/validate_release_artifact.php --print-required-paths)
  else
    echo "[DRY-RUN] (würde hochladen + remote prüfen)"
  fi
fi

echo "[✓] Build abgeschlossen: $ARCHIVE"
echo "    Log: $LOG"
if [[ -n "${UPLOAD}" ]]; then
  echo "    Hochgeladen nach: ${UPLOAD}:${REMOTE_DIR}/$(basename "$ARCHIVE")"
fi
