#!/usr/bin/env bash
# v1.1 — Deployment eines Easy!Appointments Release-Archivs auf dem Server
# - Toleranter Pre-Check (CI-Config irgendwo im Archiv; Top-Level-Ordner egal)
# - Stage entpacken, STAGE_ROOT automatisch aus CI-Config ableiten
# - Root-config & storage übernehmen, Rechte setzen, atomar umschalten
# - Optionaler Release-Marker; Services reloaden; Log nach /var/log/deploy_ea_<REL>.log

set -Eeuo pipefail
umask 022

REL=""
APP="/var/www/html/easyappointments"
SRC="/root/releases"
WEBUSER="www-data"
RELOAD_SERVICES="apache2,php8.2-fpm"
DRYRUN=0
MARK_RELEASE=1

usage() {
  cat <<'USAGE'
Usage: deploy_ea.sh --rel REL [--app PATH] [--src DIR] [--user WEBUSER] [--reload svc1,svc2] [--dry-run] [--no-mark]
  --rel REL         Release-ID (z.B. ea_20251005_2000)   [Pflicht]
  --app PATH        Live-Pfad der App                    [Default: /var/www/html/easyappointments]
  --src DIR         Verzeichnis mit dem .tar.gz          [Default: /root/releases]
  --user WEBUSER    Webserver-User für chown             [Default: www-data]
  --reload LIST     Services reloaden (CSV)              [Default: apache2,php8.2-fpm]
  --dry-run         Nur anzeigen, was passieren würde
  --no-mark         Kein _RELEASE Marker schreiben
Beispiel:
  /root/deploy_ea.sh --rel ea_20251005_2000
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --rel) REL="$2"; shift 2;;
    --app) APP="$2"; shift 2;;
    --src) SRC="$2"; shift 2;;
    --user) WEBUSER="$2"; shift 2;;
    --reload) RELOAD_SERVICES="$2"; shift 2;;
    --dry-run) DRYRUN=1; shift 1;;
    --no-mark) MARK_RELEASE=0; shift 1;;
    -h|--help) usage; exit 0;;
    *) echo "Unbekannte Option: $1"; usage; exit 1;;
  esac
done

if [[ -z "$REL" ]]; then echo "[!] --rel ist Pflicht"; usage; exit 1; fi

ARCHIVE="${SRC}/${REL}.tar.gz"
STAGE="${APP}_${REL}_stage"
PREV="${APP}_prev_${REL}"
LOG="/var/log/deploy_ea_${REL}.log"
mkdir -p "$(dirname "$LOG")"
exec > >(tee -a "$LOG") 2>&1

run() { if [[ "$DRYRUN" -eq 1 ]]; then echo "[DRY-RUN] $*"; else bash -lc "$*"; fi; }

echo "[i] Deploy Easy!Appointments"
echo "    Release : $REL"
echo "    Archiv  : $ARCHIVE"
echo "    Live    : $APP"
echo "    Stage   : $STAGE"
echo "    Prev    : $PREV"
echo "    User    : $WEBUSER"
echo "    Reload  : $RELOAD_SERVICES"
echo "    Logfile : $LOG"

# Vorprüfungen (lesen erlaubt auch im Dry-Run)
[[ -f "$ARCHIVE" ]] || { echo "[!] Archiv nicht gefunden: $ARCHIVE"; exit 1; }
[[ -d "$APP" ]] || { echo "[!] Live-Verzeichnis fehlt: $APP"; exit 1; }
[[ -f "$APP/config.php" ]] || { echo "[!] Root-config.php fehlt in Live: $APP/config.php"; exit 1; }

# --- Pre-Check: Archiv muss die CI-Config irgendwo enthalten (tolerant ggü. Top-Level-Ordnern) ---
ARCH_LIST=$(tar -tzf "$ARCHIVE" | tr -d '\r' || true)
if ! echo "$ARCH_LIST" | grep -E '(^|.*/)(application/config/config\.php)$' >/dev/null; then
  echo "[!] CI-Config nicht im Archiv gefunden (toleranter Check fehlgeschlagen)."
  echo "    Archiv-Beispiele (erste 40 Zeilen):"
  echo "$ARCH_LIST" | sed -n '1,40p'
  exit 1
fi

# --- Stage vorbereiten & entpacken ---
if [[ -e "$STAGE" ]]; then run "rm -rf '$STAGE'"; fi
run "mkdir -p '$STAGE'"
run "tar -xzf '$ARCHIVE' -C '$STAGE'"

# --- STAGE_ROOT automatisch ermitteln (Pfad zur App-Wurzel via CI-Config) ---
if [[ "$DRYRUN" -eq 0 ]]; then
  CAND=$(find "$STAGE" -type f -path "*/application/config/config.php" | head -n 1 || true)
  if [[ -z "$CAND" ]]; then
    echo "[!] Nach dem Entpacken keine CI-Config gefunden. Abbruch."
    exit 1
  fi
  STAGE_ROOT="${CAND%/application/config/config.php}"
  echo "[i] STAGE_ROOT = $STAGE_ROOT"
else
  echo "[DRY-RUN] Würde STAGE_ROOT per find ermitteln (*/application/config/config.php)"
  STAGE_ROOT="$STAGE"  # Platzhalter
fi

# --- Root-config & storage übernehmen ---
run "cp '$APP/config.php' '$STAGE_ROOT/config.php'"
run "mkdir -p '$STAGE_ROOT/storage'"
run "rsync -a '$APP/storage/' '$STAGE_ROOT/storage/' 2>/dev/null || true"

# --- Rechte/Owner ---
run "chown -R '$WEBUSER':'$WEBUSER' '$STAGE_ROOT'"
run "find '$STAGE_ROOT' -type d -exec chmod 755 {} \\;"
run "find '$STAGE_ROOT' -type f -exec chmod 644 {} \\;"

# --- Umschalten (atomar) ---
run "mv '$APP' '$PREV'"
run "mv '$STAGE_ROOT' '$APP'"

# --- Release-Markierung (optional) ---
if [[ "$MARK_RELEASE" -eq 1 ]]; then
  run "bash -lc 'echo \"$REL  \$(date -u +%FT%TZ)\" > \"$APP/_RELEASE\"'"
fi

# --- Services neu laden ---
IFS=',' read -ra SVCS <<< "$RELOAD_SERVICES"
for s in "${SVCS[@]}"; do
  s_trim="$(echo "$s" | xargs)"
  [[ -n "$s_trim" ]] || continue
  run "systemctl reload '$s_trim' 2>/dev/null || true"
done

# --- Kurzer HTTP-Check (optional) ---
if command -v curl >/dev/null 2>&1; then
  run "curl -fsS http://localhost/ >/dev/null && echo '[OK] HTTP-Check localhost/' || echo '[i] HTTP-Check übersprungen/fehlgeschlagen (nicht kritisch)'"
fi

echo "[✓] Deployment abgeschlossen: $APP"
echo "    Archiv: $ARCHIVE"
echo "    Vorige Version: $PREV"
echo "    Log: $LOG"

echo
echo "Rollback (falls nötig):"
echo "  mv '$APP' '${APP}_failed_${REL}' && mv '$PREV' '$APP'"
