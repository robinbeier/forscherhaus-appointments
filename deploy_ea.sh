#!/usr/bin/env bash
# v1.2 - Hardened host deployment for Easy!Appointments
# - Mandatory pre-switch pdf-renderer dependency gate
# - Post-switch renderer + deep-health validation
# - Automatic strict rollback on post-switch validation failure

set -Eeuo pipefail
umask 022

REL=""
APP="/var/www/html/easyappointments"
SRC="/root/releases"
WEBUSER="www-data"
RELOAD_SERVICES="apache2,php8.2-fpm"
DRYRUN=0
MARK_RELEASE=1

RENDERER_SERVICE="fh-pdf-renderer"
RENDERER_HEALTH_URL="http://127.0.0.1:3003/healthz"
RENDERER_STATE_DIR="/var/lib/fh-pdf-renderer"
DEEP_HEALTH_URL="http://localhost/index.php/healthz"
HEALTHZ_TOKEN_FILE=""

RENDERER_HEALTH_RETRIES=15
RENDERER_HEALTH_SLEEP_SECONDS=2

EXIT_ROLLBACK_SUCCESS=30
EXIT_ROLLBACK_FAILED=31

SYSTEMCTL_BASE=(/bin/systemctl)

usage() {
  cat <<'USAGE'
Usage: deploy_ea.sh --rel REL [options]

Required:
  --rel REL                    Release-ID (e.g. ea_20251005_2000)

Core options:
  --app PATH                   Live app path                     [default: /var/www/html/easyappointments]
  --src DIR                    Directory with release archive     [default: /root/releases]
  --user WEBUSER               Web user for ownership/actions     [default: www-data]
  --reload LIST                Services to reload (CSV)           [default: apache2,php8.2-fpm]
  --dry-run                    Print actions only
  --no-mark                    Skip writing _RELEASE marker

Renderer / health gate options:
  --renderer-service NAME      systemd service name               [default: fh-pdf-renderer]
  --renderer-health-url URL    Renderer health endpoint           [default: http://127.0.0.1:3003/healthz]
  --renderer-state-dir PATH    Persistent renderer state dir      [default: /var/lib/fh-pdf-renderer]
  --deep-health-url URL         App deep health endpoint           [default: http://localhost/index.php/healthz]
  --healthz-token-file PATH     File containing deep-health token  [required for non-dry deploy]

Exit codes:
  0   Success
  30  Deploy failed, automatic rollback succeeded
  31  Deploy failed, rollback failed or unverifiable

Example:
  /root/deploy_ea.sh --rel ea_20251005_2000 --healthz-token-file /etc/fh/healthz.token
USAGE
}

die() {
  echo "$1"
  exit 1
}

run_shell() {
  local cmd="$1"
  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] $cmd"
  else
    bash -lc "$cmd"
  fi
}

require_command() {
  local cmd="$1"
  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] prerequisite check: command '$cmd' exists"
    return 0
  fi
  command -v "$cmd" >/dev/null 2>&1 || die "[!] Required command missing: $cmd"
}

ensure_renderer_restart_permissions() {
  if [[ "$EUID" -eq 0 ]]; then
    SYSTEMCTL_BASE=(/bin/systemctl)
    echo "[i] Service control mode: root"
    return 0
  fi

  SYSTEMCTL_BASE=(sudo -n /bin/systemctl)

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] prerequisite check: sudo -n -l /bin/systemctl restart '$RENDERER_SERVICE'"
    return 0
  fi

  command -v sudo >/dev/null 2>&1 || die "[!] 'sudo' is required for non-root deployment user."
  sudo -n -l /bin/systemctl restart "$RENDERER_SERVICE" >/dev/null 2>&1 \
    || die "[!] Missing non-interactive permission for '/bin/systemctl restart $RENDERER_SERVICE'."
}

systemctl_run() {
  local action="$1"
  shift
  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] ${SYSTEMCTL_BASE[*]} $action $*"
    return 0
  fi
  "${SYSTEMCTL_BASE[@]}" "$action" "$@"
}

reload_services() {
  local s
  local s_trim

  IFS=',' read -ra SVCS <<< "$RELOAD_SERVICES"
  for s in "${SVCS[@]}"; do
    s_trim="$(echo "$s" | xargs)"
    [[ -n "$s_trim" ]] || continue
    if [[ "$DRYRUN" -eq 1 ]]; then
      echo "[DRY-RUN] ${SYSTEMCTL_BASE[*]} reload '$s_trim' 2>/dev/null || true"
    else
      "${SYSTEMCTL_BASE[@]}" reload "$s_trim" 2>/dev/null || true
    fi
  done
}

prepare_renderer_state_dir() {
  local state_home="${RENDERER_STATE_DIR}/home"
  local npm_cache="${RENDERER_STATE_DIR}/npm-cache"
  local puppeteer_cache="${RENDERER_STATE_DIR}/puppeteer-cache"

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] mkdir -p '$state_home' '$npm_cache' '$puppeteer_cache'"
    echo "[DRY-RUN] chown -R '$WEBUSER':'$WEBUSER' '$RENDERER_STATE_DIR'"
    echo "[DRY-RUN] chmod 0750 '$RENDERER_STATE_DIR' '$state_home' '$npm_cache' '$puppeteer_cache'"
    return 0
  fi

  mkdir -p "$state_home" "$npm_cache" "$puppeteer_cache"
  chown -R "$WEBUSER":"$WEBUSER" "$RENDERER_STATE_DIR"
  chmod 0750 "$RENDERER_STATE_DIR" "$state_home" "$npm_cache" "$puppeteer_cache"
}

install_renderer_dependencies() {
  local renderer_dir="${STAGE_ROOT}/pdf-renderer"
  local state_home="${RENDERER_STATE_DIR}/home"
  local npm_cache="${RENDERER_STATE_DIR}/npm-cache"
  local puppeteer_cache="${RENDERER_STATE_DIR}/puppeteer-cache"

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] runuser -u '$WEBUSER' -- env HOME='$state_home' NPM_CONFIG_CACHE='$npm_cache' PUPPETEER_CACHE_DIR='$puppeteer_cache' bash -lc \"cd '$renderer_dir' && npm ci --omit=dev --no-audit --no-fund\""
    return 0
  fi

  runuser -u "$WEBUSER" -- env \
    HOME="$state_home" \
    NPM_CONFIG_CACHE="$npm_cache" \
    PUPPETEER_CACHE_DIR="$puppeteer_cache" \
    bash -lc "cd '$renderer_dir' && npm ci --omit=dev --no-audit --no-fund"
}

read_healthz_token() {
  local token

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "__DRY_RUN_TOKEN__"
    return 0
  fi

  [[ -r "$HEALTHZ_TOKEN_FILE" ]] || {
    echo "[!] Token file unreadable: $HEALTHZ_TOKEN_FILE"
    return 1
  }

  token="$(tr -d '\r\n' < "$HEALTHZ_TOKEN_FILE")"
  [[ -n "$token" ]] || {
    echo "[!] Token file is empty: $HEALTHZ_TOKEN_FILE"
    return 1
  }

  printf '%s' "$token"
}

probe_renderer_health() {
  local attempt
  local code

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] renderer health probe: $RENDERER_HEALTH_URL (${RENDERER_HEALTH_RETRIES}x, ${RENDERER_HEALTH_SLEEP_SECONDS}s)"
    return 0
  fi

  for ((attempt = 1; attempt <= RENDERER_HEALTH_RETRIES; attempt++)); do
    code="$(curl -sS -o /dev/null -w '%{http_code}' "$RENDERER_HEALTH_URL" || echo 000)"
    if [[ "$code" == "200" ]]; then
      echo "[OK] Renderer health is up: HTTP 200 (attempt $attempt/$RENDERER_HEALTH_RETRIES)"
      return 0
    fi
    echo "[i] Renderer health pending: HTTP $code (attempt $attempt/$RENDERER_HEALTH_RETRIES)"
    sleep "$RENDERER_HEALTH_SLEEP_SECONDS"
  done

  echo "[!] Renderer health failed after $RENDERER_HEALTH_RETRIES attempts: $RENDERER_HEALTH_URL"
  return 1
}

probe_deep_health_contract() {
  local token
  local body_file
  local http_code

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] deep health probe: $DEEP_HEALTH_URL with header X-Health-Token:<redacted> and contract status=ok + checks.pdf_renderer.ok=true"
    return 0
  fi

  token="$(read_healthz_token)" || return 1
  body_file="$(mktemp)"
  http_code="$(curl -sS -o "$body_file" -w '%{http_code}' -H "X-Health-Token: $token" "$DEEP_HEALTH_URL" || echo 000)"

  if [[ "$http_code" != "200" ]]; then
    echo "[!] Deep health failed: HTTP $http_code from $DEEP_HEALTH_URL"
    rm -f "$body_file"
    return 1
  fi

  if php -r '
    $raw = @file_get_contents($argv[1]);
    if ($raw === false) {
        fwrite(STDERR, "deep health body read failed" . PHP_EOL);
        exit(2);
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        fwrite(STDERR, "deep health response is not valid JSON" . PHP_EOL);
        exit(3);
    }
    $status = $json["status"] ?? null;
    $pdfOk = $json["checks"]["pdf_renderer"]["ok"] ?? null;
    if ($status === "ok" && $pdfOk === true) {
        exit(0);
    }
    fwrite(STDERR, "deep health contract mismatch: status=" . var_export($status, true) . ", checks.pdf_renderer.ok=" . var_export($pdfOk, true) . PHP_EOL);
    exit(4);
  ' "$body_file"; then
    echo "[OK] Deep health contract passed: status=ok and checks.pdf_renderer.ok=true"
    rm -f "$body_file"
    return 0
  fi

  echo "[!] Deep health contract validation failed: $DEEP_HEALTH_URL"
  rm -f "$body_file"
  return 1
}

restart_renderer_service() {
  echo "[i] Restarting renderer service: $RENDERER_SERVICE"
  systemctl_run restart "$RENDERER_SERVICE"
}

perform_atomic_switch() {
  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] mv '$APP' '$PREV'"
    echo "[DRY-RUN] mv '$STAGE_ROOT' '$APP'"
    return 0
  fi

  mv "$APP" "$PREV"
  mv "$STAGE_ROOT" "$APP"
}

rollback_after_failure() {
  local reason="$1"
  local failed_base="${APP}_failed_${REL}"
  local failed_path="$failed_base"
  local renderer_result="skipped"
  local deep_result="skipped"
  local rollback_ok=1

  if [[ -e "$failed_path" ]]; then
    failed_path="${failed_base}_$(date -u +%Y%m%d_%H%M%S)"
  fi

  echo "[!] Post-switch validation failed: $reason"
  echo "[!] Starting automatic rollback"
  echo "    Failed path target : $failed_path"
  echo "    Restore source     : $PREV"

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] mv '$APP' '$failed_path'"
    echo "[DRY-RUN] mv '$PREV' '$APP'"
    echo "[DRY-RUN] restart renderer + validate renderer/deep health"
    exit "$EXIT_ROLLBACK_SUCCESS"
  fi

  if [[ ! -d "$APP" ]]; then
    echo "[!] Rollback failed: active app path missing: $APP"
    rollback_ok=0
  fi
  if [[ ! -d "$PREV" ]]; then
    echo "[!] Rollback failed: previous app path missing: $PREV"
    rollback_ok=0
  fi

  if [[ "$rollback_ok" -eq 1 ]]; then
    if ! mv "$APP" "$failed_path"; then
      echo "[!] Rollback failed: could not move broken app to $failed_path"
      rollback_ok=0
    fi
  fi

  if [[ "$rollback_ok" -eq 1 ]]; then
    if ! mv "$PREV" "$APP"; then
      echo "[!] Rollback failed: could not restore $PREV to $APP"
      rollback_ok=0
    fi
  fi

  if [[ "$rollback_ok" -eq 1 ]]; then
    if restart_renderer_service && probe_renderer_health; then
      renderer_result="ok"
    else
      renderer_result="failed"
      rollback_ok=0
    fi
  fi

  reload_services || true

  if [[ "$rollback_ok" -eq 1 ]]; then
    if probe_deep_health_contract; then
      deep_result="ok"
    else
      deep_result="failed"
      rollback_ok=0
    fi
  fi

  echo "[!] Deployment failed; rollback result summary"
  echo "    Failure reason      : $reason"
  echo "    Failed release path : $failed_path"
  echo "    Restored app path   : $APP"
  echo "    Renderer check      : $renderer_result"
  echo "    Deep health check   : $deep_result"

  if [[ "$rollback_ok" -eq 1 ]]; then
    echo "[!] Rollback succeeded, deployment remains failed."
    exit "$EXIT_ROLLBACK_SUCCESS"
  fi

  echo "[!] Rollback failed or unverifiable. Manual intervention required."
  exit "$EXIT_ROLLBACK_FAILED"
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
    --renderer-service) RENDERER_SERVICE="$2"; shift 2;;
    --renderer-health-url) RENDERER_HEALTH_URL="$2"; shift 2;;
    --renderer-state-dir) RENDERER_STATE_DIR="$2"; shift 2;;
    --deep-health-url) DEEP_HEALTH_URL="$2"; shift 2;;
    --healthz-token-file) HEALTHZ_TOKEN_FILE="$2"; shift 2;;
    -h|--help) usage; exit 0;;
    *) die "[!] Unknown option: $1";;
  esac
done

[[ -n "$REL" ]] || die "[!] --rel is required."
if [[ "$DRYRUN" -eq 0 ]]; then
  [[ -n "$HEALTHZ_TOKEN_FILE" ]] || die "[!] --healthz-token-file is required for non-dry deployments."
  [[ -r "$HEALTHZ_TOKEN_FILE" ]] || die "[!] Token file is not readable: $HEALTHZ_TOKEN_FILE"
fi

ARCHIVE="${SRC}/${REL}.tar.gz"
STAGE="${APP}_${REL}_stage"
PREV="${APP}_prev_${REL}"
LOG="/var/log/deploy_ea_${REL}.log"

mkdir -p "$(dirname "$LOG")"
exec > >(tee -a "$LOG") 2>&1

echo "[i] Deploy Easy!Appointments"
echo "    Release              : $REL"
echo "    Archive              : $ARCHIVE"
echo "    Live                 : $APP"
echo "    Stage                : $STAGE"
echo "    Prev                 : $PREV"
echo "    Web user             : $WEBUSER"
echo "    Reload services      : $RELOAD_SERVICES"
echo "    Renderer service     : $RENDERER_SERVICE"
echo "    Renderer health URL  : $RENDERER_HEALTH_URL"
echo "    Renderer state dir   : $RENDERER_STATE_DIR"
echo "    Deep health URL      : $DEEP_HEALTH_URL"
echo "    Token file           : ${HEALTHZ_TOKEN_FILE:-<not-set-dry-run>}"
echo "    Logfile              : $LOG"

[[ -f "$ARCHIVE" ]] || die "[!] Archive not found: $ARCHIVE"
[[ -d "$APP" ]] || die "[!] Live directory missing: $APP"
[[ -f "$APP/config.php" ]] || die "[!] Missing root config in live app: $APP/config.php"
if [[ -e "$PREV" ]]; then
  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] would fail because PREV already exists: $PREV"
  else
    die "[!] Previous release path already exists (cleanup required): $PREV"
  fi
fi

ARCH_LIST="$(tar -tzf "$ARCHIVE" | tr -d '\r' || true)"
if ! echo "$ARCH_LIST" | grep -E '(^|.*/)(application/config/config\.php)$' >/dev/null; then
  echo "[!] CI config not found in archive (tolerant pre-check failed)."
  echo "    Archive sample (first 40 entries):"
  echo "$ARCH_LIST" | sed -n '1,40p'
  exit 1
fi

# Pre-switch mandatory gates: runtime tool checks + service-control permission.
require_command node
require_command npm
require_command curl
require_command php
require_command runuser
ensure_renderer_restart_permissions

if [[ -e "$STAGE" ]]; then
  run_shell "rm -rf '$STAGE'"
fi
run_shell "mkdir -p '$STAGE'"
run_shell "tar -xzf '$ARCHIVE' -C '$STAGE'"

if [[ "$DRYRUN" -eq 0 ]]; then
  CAND="$(find "$STAGE" -type f -path '*/application/config/config.php' | head -n 1 || true)"
  [[ -n "$CAND" ]] || die "[!] No CI config found in extracted stage. Aborting."
  STAGE_ROOT="${CAND%/application/config/config.php}"
  echo "[i] STAGE_ROOT = $STAGE_ROOT"
else
  echo "[DRY-RUN] would detect STAGE_ROOT by find(*/application/config/config.php)"
  STAGE_ROOT="$STAGE"
fi

run_shell "cp '$APP/config.php' '$STAGE_ROOT/config.php'"
run_shell "mkdir -p '$STAGE_ROOT/storage'"
run_shell "rsync -a '$APP/storage/' '$STAGE_ROOT/storage/' 2>/dev/null || true"

if [[ "$DRYRUN" -eq 0 ]]; then
  [[ -f "$STAGE_ROOT/pdf-renderer/package-lock.json" ]] \
    || die "[!] Missing pre-switch gate file: $STAGE_ROOT/pdf-renderer/package-lock.json"
else
  echo "[DRY-RUN] would verify $STAGE_ROOT/pdf-renderer/package-lock.json exists"
fi

prepare_renderer_state_dir
install_renderer_dependencies

run_shell "chown -R '$WEBUSER':'$WEBUSER' '$STAGE_ROOT'"
run_shell "find '$STAGE_ROOT' -type d -exec chmod 755 {} \\;"
run_shell "find '$STAGE_ROOT' -type f -exec chmod 644 {} \\;"

perform_atomic_switch

if ! restart_renderer_service; then
  rollback_after_failure "renderer service restart failed"
fi

if ! probe_renderer_health; then
  rollback_after_failure "renderer health check failed ($RENDERER_HEALTH_URL)"
fi

if ! probe_deep_health_contract; then
  rollback_after_failure "deep health contract failed ($DEEP_HEALTH_URL)"
fi

if [[ "$MARK_RELEASE" -eq 1 ]]; then
  run_shell "bash -lc 'echo \"$REL  \$(date -u +%FT%TZ)\" > \"$APP/_RELEASE\"'"
fi

reload_services

if command -v curl >/dev/null 2>&1; then
  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] curl -fsS http://localhost/ >/dev/null && echo '[OK] HTTP-Check localhost/' || echo '[i] HTTP-Check skipped/failed (non-critical)'"
  else
    if curl -fsS http://localhost/ >/dev/null; then
      echo "[OK] HTTP-Check localhost/"
    else
      echo "[i] HTTP-Check skipped/failed (non-critical)"
    fi
  fi
fi

echo "[✓] Deployment completed: $APP"
echo "    Archive        : $ARCHIVE"
echo "    Previous       : $PREV"
echo "    Log            : $LOG"

echo
echo "Rollback (manual fallback):"
echo "  mv '$APP' '${APP}_failed_${REL}' && mv '$PREV' '$APP'"
