#!/usr/bin/env bash
# v1.2 - Hardened host deployment for Easy!Appointments
# - Mandatory pre-switch pdf-renderer dependency gate
# - Post-switch renderer + deep-health validation
# - Automatic strict rollback on post-switch validation failure

set -Eeuo pipefail
umask 022

SCRIPT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CURRENT_SCRIPT_PATH="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")"
DEPLOY_CWD="$(pwd -P)"

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
ZERO_SURPRISE_REPORT=""
ZERO_SURPRISE_DUMP_FILE=""
ZERO_SURPRISE_PREDEPLOY_CREDENTIALS_FILE=""
ZERO_SURPRISE_MAX_AGE_MINUTES=240
REQUIRE_ZERO_SURPRISE=1
ZERO_SURPRISE_EXPECTED_MODE="predeploy"
ZERO_SURPRISE_PROFILE="school-day-default"
ZERO_SURPRISE_BREAKGLASS_FILE=""
ZERO_SURPRISE_BREAKGLASS_USED=0
ZERO_SURPRISE_BREAKGLASS_TICKET=""
ZERO_SURPRISE_BREAKGLASS_REASON=""
ZERO_SURPRISE_CANARY_ENABLED=1
ZERO_SURPRISE_CANARY_TIMEOUT=300
ZERO_SURPRISE_CANARY_CREDENTIALS_FILE=""
ZERO_SURPRISE_CANARY_REPORT=""
ZERO_SURPRISE_INCIDENT_WEBHOOK_FILE=""
ZERO_SURPRISE_INCIDENT_TIMEOUT=10

RENDERER_HEALTH_RETRIES=15
RENDERER_HEALTH_SLEEP_SECONDS=2
DEEP_HEALTH_RETRIES=10
DEEP_HEALTH_SLEEP_SECONDS=2

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
  --reload LIST                Services to reload (CSV)           [default: apache2,<detected php-fpm>]
  --dry-run                    Print actions only
  --no-mark                    Skip writing _RELEASE marker

Renderer / health gate options:
  --renderer-service NAME      systemd service name               [default: fh-pdf-renderer]
  --renderer-health-url URL    Renderer health endpoint           [default: http://127.0.0.1:3003/healthz]
  --renderer-state-dir PATH    Persistent renderer state dir      [default: /var/lib/fh-pdf-renderer]
  --deep-health-url URL         App deep health endpoint           [default: http://localhost/index.php/healthz]
  --healthz-token-file PATH     File containing deep-health token  [required for non-dry deploy]
  --zero-surprise-report PATH   Output path for generated predeploy report
  --zero-surprise-dump-file PATH
                                Restore dump for predeploy replay   [required when gate is enabled]
  --zero-surprise-predeploy-credentials-file PATH
                                INI file for predeploy replay       [required when gate is enabled]
  --zero-surprise-profile NAME  Named zero-surprise profile         [default: school-day-default]
  --zero-surprise-max-age-minutes N
                                Max age for report in minutes      [default: 240]
  --require-zero-surprise 0|1   Enforce zero-surprise hard-fail    [default: 1]
  --zero-surprise-breakglass-file PATH
                                Expiring ack JSON required for any bypass
  --zero-surprise-canary-enabled 0|1
                                Run post-switch live canary        [default: 1]
  --zero-surprise-canary-timeout N
                                Live canary timeout in seconds      [default: 300]
  --zero-surprise-canary-credentials-file PATH
                                INI file for live canary creds      [required when canary is enabled]
  --zero-surprise-incident-webhook-file PATH
                                INI file for zero-surprise incident webhook
  --zero-surprise-incident-timeout N
                                Incident webhook timeout seconds    [default: 10]

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

require_docker_compose() {
  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] prerequisite check: docker compose version"
    return 0
  fi

  docker compose version >/dev/null 2>&1 || die "[!] Required command missing: docker compose"
}

absolutize_path() {
  local value="$1"

  case "$value" in
    "~")
      value="$HOME"
      ;;
    "~/"*)
      value="$HOME/${value#~/}"
      ;;
  esac

  if [[ "$value" != /* ]]; then
    value="${DEPLOY_CWD}/${value}"
  fi

  printf '%s\n' "$value"
}

absolutize_path_var() {
  local var_name="$1"
  local value="${!var_name:-}"

  [[ -n "$value" ]] || return 0
  printf -v "$var_name" '%s' "$(absolutize_path "$value")"
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

validate_stage_release_artifact() {
  local validator_script="$STAGE_ROOT/scripts/release-gate/validate_release_artifact.php"

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] validate release artifact at '$STAGE_ROOT' with '$validator_script'"
    return 0
  fi

  [[ -f "$validator_script" ]] || die "[!] Missing release artifact validator in stage: $validator_script"

  php "$validator_script" --root="$STAGE_ROOT" \
    || die "[!] Extracted stage is missing required release artifacts."
}

validate_deploy_script_drift() {
  local stage_deploy_script="$STAGE_ROOT/deploy_ea.sh"

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] compare running deploy script '$CURRENT_SCRIPT_PATH' with staged '$stage_deploy_script'"
    return 0
  fi

  [[ -f "$stage_deploy_script" ]] || die "[!] Missing deploy script in staged release: $stage_deploy_script"

  cmp -s "$CURRENT_SCRIPT_PATH" "$stage_deploy_script" \
    || die "[!] Host deploy script drift detected: '$CURRENT_SCRIPT_PATH' does not match '$stage_deploy_script'. Sync the host deploy script from the merged repo before deploying."
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

detect_php_fpm_reload_service() {
  local unit

  while read -r unit _; do
    [[ "$unit" =~ ^php[0-9.]+-fpm\.service$ ]] || continue
    printf '%s\n' "${unit%.service}"
    return 0
  done < <(/bin/systemctl list-units --type=service --all 'php*-fpm.service' --no-legend 2>/dev/null || true)

  while read -r unit _; do
    [[ "$unit" =~ ^php[0-9.]+-fpm\.service$ ]] || continue
    printf '%s\n' "${unit%.service}"
    return 0
  done < <(/bin/systemctl list-unit-files 'php*-fpm.service' --type=service --no-legend 2>/dev/null || true)

  return 1
}

resolve_reload_services() {
  local default_reload="apache2,php8.2-fpm"
  local detected_php_fpm

  [[ "$RELOAD_SERVICES" == "$default_reload" ]] || return 0

  detected_php_fpm="$(detect_php_fpm_reload_service || true)"
  [[ -n "$detected_php_fpm" ]] || return 0

  RELOAD_SERVICES="apache2,${detected_php_fpm}"
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
  local xdg_config="${RENDERER_STATE_DIR}/config"
  local xdg_cache="${RENDERER_STATE_DIR}/cache"
  local xdg_data="${RENDERER_STATE_DIR}/data"
  local tmp_dir="${RENDERER_STATE_DIR}/tmp"
  local puppeteer_cache="${xdg_cache}/puppeteer"

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] mkdir -p '$state_home' '$npm_cache' '$xdg_config' '$xdg_cache' '$xdg_data' '$tmp_dir' '$puppeteer_cache'"
    echo "[DRY-RUN] chown -R '$WEBUSER':'$WEBUSER' '$RENDERER_STATE_DIR'"
    echo "[DRY-RUN] chmod 0750 '$RENDERER_STATE_DIR' '$state_home' '$npm_cache' '$xdg_config' '$xdg_cache' '$xdg_data' '$tmp_dir' '$puppeteer_cache'"
    return 0
  fi

  mkdir -p "$state_home" "$npm_cache" "$xdg_config" "$xdg_cache" "$xdg_data" "$tmp_dir" "$puppeteer_cache"
  chown -R "$WEBUSER":"$WEBUSER" "$RENDERER_STATE_DIR"
  chmod 0750 "$RENDERER_STATE_DIR" "$state_home" "$npm_cache" "$xdg_config" "$xdg_cache" "$xdg_data" "$tmp_dir" "$puppeteer_cache"
}

restore_runtime_script_permissions() {
  local ops_dir="${STAGE_ROOT}/scripts/ops"

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] restore executable bits for '$ops_dir' shell scripts when present"
    return 0
  fi

  [[ -d "$ops_dir" ]] || return 0

  find "$ops_dir" -type f -name '*.sh' -exec chmod 755 {} \;
}

install_renderer_dependencies() {
  local renderer_dir="${STAGE_ROOT}/pdf-renderer"
  local state_home="${RENDERER_STATE_DIR}/home"
  local npm_cache="${RENDERER_STATE_DIR}/npm-cache"
  local puppeteer_cache="${RENDERER_STATE_DIR}/cache/puppeteer"

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
  local attempt

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] deep health probe: $DEEP_HEALTH_URL with header X-Health-Token:<redacted> and contract status=ok + checks.pdf_renderer.ok=true (${DEEP_HEALTH_RETRIES}x, ${DEEP_HEALTH_SLEEP_SECONDS}s)"
    return 0
  fi

  token="$(read_healthz_token)" || return 1

  for ((attempt = 1; attempt <= DEEP_HEALTH_RETRIES; attempt++)); do
    body_file="$(mktemp)"
    http_code="$(curl -sS -o "$body_file" -w '%{http_code}' -H "X-Health-Token: $token" "$DEEP_HEALTH_URL" || echo 000)"

    if [[ "$http_code" != "200" ]]; then
      echo "[i] Deep health pending: HTTP $http_code from $DEEP_HEALTH_URL (attempt $attempt/$DEEP_HEALTH_RETRIES)"
      rm -f "$body_file"
      sleep "$DEEP_HEALTH_SLEEP_SECONDS"
      continue
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
      echo "[OK] Deep health contract passed: status=ok and checks.pdf_renderer.ok=true (attempt $attempt/$DEEP_HEALTH_RETRIES)"
      rm -f "$body_file"
      return 0
    fi

    echo "[i] Deep health contract pending: $DEEP_HEALTH_URL (attempt $attempt/$DEEP_HEALTH_RETRIES)"
    rm -f "$body_file"
    sleep "$DEEP_HEALTH_SLEEP_SECONDS"
  done

  echo "[!] Deep health contract validation failed after $DEEP_HEALTH_RETRIES attempts: $DEEP_HEALTH_URL"
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

is_positive_integer() {
  [[ "$1" =~ ^[1-9][0-9]*$ ]]
}

extract_base64_field() {
  local payload="$1"
  local field="$2"

  php -r '
    $payload = stream_get_contents(STDIN);
    $field = (string) ($argv[1] ?? "");
    if ($payload === false || $field === "") {
        exit(1);
    }
    $value = null;
    foreach (preg_split("/\\r?\\n/", trim($payload)) as $line) {
        if (!is_string($line) || $line === "") {
            continue;
        }
        [$key, $encoded] = array_pad(explode("=", $line, 2), 2, "");
        if ($key !== $field) {
            continue;
        }
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            exit(1);
        }
        $value = $decoded;
        break;
    }
    if ($value === null) {
        exit(1);
    }
    echo $value;
  ' "$field" <<<"$payload"
}

emit_zero_surprise_incident() {
  local event="$1"
  local severity="$2"
  local reason="$3"
  local rollback_result="${4:-}"
  local report_path="${5:-}"
  local report_root="${6:-$APP}"

  if [[ -z "$ZERO_SURPRISE_INCIDENT_WEBHOOK_FILE" ]]; then
    echo "[i] Zero-surprise incident webhook not configured; skipping incident notification."
    return 0
  fi

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] notify zero-surprise incident event='$event' severity='$severity' reason='$reason' rollback='$rollback_result' report='${report_path:-<none>}'"
    return 0
  fi

  local notify_script="${SCRIPT_ROOT}/scripts/release-gate/zero_surprise_incident_notify.php"
  [[ -r "$notify_script" ]] || {
    echo "[!] Zero-surprise incident notifier script missing or unreadable: $notify_script"
    return 0
  }

  local command=(
    php
    "$notify_script"
    "--webhook-file=$ZERO_SURPRISE_INCIDENT_WEBHOOK_FILE"
    "--event=$event"
    "--severity=$severity"
    "--release-id=$REL"
    "--reason=$reason"
    "--log-path=$LOG"
    "--breakglass-used=$ZERO_SURPRISE_BREAKGLASS_USED"
    "--timeout-seconds=$ZERO_SURPRISE_INCIDENT_TIMEOUT"
  )

  if [[ -n "$rollback_result" ]]; then
    command+=("--rollback-result=$rollback_result")
  fi
  if [[ -n "$report_path" ]]; then
    command+=("--report-path=$report_path" "--report-root=$report_root")
  fi
  if [[ -n "$ZERO_SURPRISE_BREAKGLASS_TICKET" ]]; then
    command+=("--ticket=$ZERO_SURPRISE_BREAKGLASS_TICKET")
  fi

  if ! "${command[@]}"; then
    echo "[!] Zero-surprise incident notification failed, continuing without blocking deploy/rollback."
  fi

  return 0
}

validate_breakglass_policy() {
  local disable_predeploy=0
  local disable_canary=0
  local validator_file
  local validator_runtime_code
  local validator_output

  [[ "$REQUIRE_ZERO_SURPRISE" -eq 1 ]] || disable_predeploy=1
  [[ "$ZERO_SURPRISE_CANARY_ENABLED" -eq 1 ]] || disable_canary=1

  if [[ "$disable_predeploy" -ne 1 && "$disable_canary" -ne 1 ]]; then
    return 0
  fi

  [[ -n "$ZERO_SURPRISE_BREAKGLASS_FILE" ]] || {
    echo "[!] Zero-surprise bypass requested, but --zero-surprise-breakglass-file is missing."
    return 1
  }

  ZERO_SURPRISE_BREAKGLASS_USED=1

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] validate breakglass file '$ZERO_SURPRISE_BREAKGLASS_FILE' for release '$REL' (disable_predeploy=$disable_predeploy disable_canary=$disable_canary)"
    return 0
  fi

  validator_file="${SCRIPT_ROOT}/scripts/release-gate/lib/ZeroSurpriseBreakglassValidator.php"
  [[ -r "$validator_file" ]] || {
    echo "[!] Zero-surprise breakglass validator missing or unreadable: $validator_file"
    return 1
  }

  read -r -d '' validator_runtime_code <<'PHP' || true
require_once $argv[1];

$validator = new \ReleaseGate\ZeroSurpriseBreakglassValidator();
$result = $validator->validateFile(
    (string) ($argv[2] ?? ''),
    (string) ($argv[3] ?? ''),
    ($argv[4] ?? '0') === '1',
    ($argv[5] ?? '0') === '1',
);

if (($result['ok'] ?? false) !== true) {
    foreach (($result['errors'] ?? []) as $error) {
        fwrite(STDERR, "    - " . (string) $error . PHP_EOL);
    }
    exit(1);
}

$normalized = is_array($result['normalized'] ?? null) ? $result['normalized'] : [];
foreach (['ticket', 'reason', 'approved_by', 'expires_at_utc'] as $field) {
    $value = is_scalar($normalized[$field] ?? null) ? (string) $normalized[$field] : '';
    fwrite(STDOUT, $field . '=' . base64_encode($value) . PHP_EOL);
}
exit(0);
PHP

  echo "[i] Validating zero-surprise breakglass ack: $ZERO_SURPRISE_BREAKGLASS_FILE"
  if ! validator_output="$(php -r "$validator_runtime_code" \
    "$validator_file" \
    "$ZERO_SURPRISE_BREAKGLASS_FILE" \
    "$REL" \
    "$disable_predeploy" \
    "$disable_canary" 2> >(cat >&2))"; then
    echo "[!] Zero-surprise breakglass validation failed."
    return 1
  fi

  ZERO_SURPRISE_BREAKGLASS_TICKET="$(extract_base64_field "$validator_output" ticket || true)"
  ZERO_SURPRISE_BREAKGLASS_REASON="$(extract_base64_field "$validator_output" reason || true)"
  local breakglass_approved_by
  local breakglass_expires_at
  breakglass_approved_by="$(extract_base64_field "$validator_output" approved_by || true)"
  breakglass_expires_at="$(extract_base64_field "$validator_output" expires_at_utc || true)"

  echo "[i] Breakglass ack accepted"
  echo "    Ticket            : ${ZERO_SURPRISE_BREAKGLASS_TICKET:-<missing>}"
  echo "    Approved by       : ${breakglass_approved_by:-<missing>}"
  echo "    Expires at (UTC)  : ${breakglass_expires_at:-<missing>}"
  echo "    Disable predeploy : $disable_predeploy"
  echo "    Disable canary    : $disable_canary"

  emit_zero_surprise_incident \
    "zero_surprise_breakglass_used" \
    "warning" \
    "${ZERO_SURPRISE_BREAKGLASS_REASON:-zero-surprise breakglass used}" \
    "not_applicable"

  return 0
}

read_zero_surprise_predeploy_base_url() {
  php -r '
    $path = (string) ($argv[1] ?? "");
    if ($path === "" || !is_file($path) || !is_readable($path)) {
        exit(1);
    }
    $ini = parse_ini_file($path, false, INI_SCANNER_RAW);
    if (!is_array($ini)) {
        exit(2);
    }
    $baseUrl = trim((string) ($ini["base_url"] ?? ""));
    if ($baseUrl === "") {
        exit(3);
    }
    echo $baseUrl;
  ' "$ZERO_SURPRISE_PREDEPLOY_CREDENTIALS_FILE"
}

prepare_zero_surprise_stage_runtime() {
  local stage_config
  local stage_sample
  local base_url

  stage_config="$STAGE_ROOT/config.php"
  stage_sample="$STAGE_ROOT/config-sample.php"

  if [[ "$REQUIRE_ZERO_SURPRISE" -ne 1 ]]; then
    return 0
  fi

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] would generate zero-surprise stage config from '$stage_sample' -> '$stage_config'"
    echo "[DRY-RUN] would ensure '$STAGE_ROOT/storage/logs/release-gate' exists for replay reports"
    return 0
  fi

  [[ -f "$stage_sample" ]] || die "[!] Missing zero-surprise stage sample config: $stage_sample"

  base_url="$(read_zero_surprise_predeploy_base_url)" \
    || die "[!] Could not resolve zero-surprise predeploy base_url from $ZERO_SURPRISE_PREDEPLOY_CREDENTIALS_FILE"

  cp "$stage_sample" "$stage_config"
  mkdir -p "$STAGE_ROOT/storage/logs/release-gate"

  php "$STAGE_ROOT/scripts/release-gate/prepare_zero_surprise_stage_config.php" \
    --config="$stage_config" \
    --base-url="$base_url" \
    || die "[!] Could not prepare zero-surprise stage config."
}

run_zero_surprise_predeploy_replay() {
  local replay_script

  if [[ "$REQUIRE_ZERO_SURPRISE" -ne 1 ]]; then
    echo "[i] Zero-surprise predeploy replay disabled (--require-zero-surprise=0)."
    return 0
  fi

  ZERO_SURPRISE_REPORT="${ZERO_SURPRISE_REPORT:-$STAGE_ROOT/storage/logs/release-gate/zero-surprise-predeploy-${REL}-$(date -u +%Y%m%dT%H%M%SZ).json}"

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] run zero-surprise predeploy replay from '$STAGE_ROOT' with dump '$ZERO_SURPRISE_DUMP_FILE', credentials '$ZERO_SURPRISE_PREDEPLOY_CREDENTIALS_FILE', profile '$ZERO_SURPRISE_PROFILE', report '$ZERO_SURPRISE_REPORT'"
    return 0
  fi

  replay_script="$STAGE_ROOT/scripts/release-gate/zero_surprise_replay.php"
  [[ -r "$replay_script" ]] || {
    echo "[!] Zero-surprise replay script missing or unreadable: $replay_script"
    return 1
  }

  echo "[i] Running zero-surprise predeploy replay"
  echo "    Dump file         : $ZERO_SURPRISE_DUMP_FILE"
  echo "    Credentials file  : $ZERO_SURPRISE_PREDEPLOY_CREDENTIALS_FILE"
  echo "    Profile           : $ZERO_SURPRISE_PROFILE"
  echo "    Report            : $ZERO_SURPRISE_REPORT"

  if ! (
    cd "$STAGE_ROOT" && php scripts/release-gate/zero_surprise_replay.php \
      --release-id="$REL" \
      --dump-file="$ZERO_SURPRISE_DUMP_FILE" \
      --credentials-file="$ZERO_SURPRISE_PREDEPLOY_CREDENTIALS_FILE" \
      --profile="$ZERO_SURPRISE_PROFILE" \
      --output-json="$ZERO_SURPRISE_REPORT"
  ); then
    echo "[!] Zero-surprise predeploy replay failed."
    return 1
  fi

  echo "[OK] Zero-surprise predeploy replay passed."
  return 0
}

validate_zero_surprise_report() {
  local validator_file
  local validator_runtime_code

  if [[ "$REQUIRE_ZERO_SURPRISE" -ne 1 ]]; then
    echo "[i] Zero-surprise hard-fail gate disabled (--require-zero-surprise=0)."
    return 0
  fi

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] validate zero-surprise report '$ZERO_SURPRISE_REPORT' for release '$REL' (mode=$ZERO_SURPRISE_EXPECTED_MODE, max-age=${ZERO_SURPRISE_MAX_AGE_MINUTES}m)"
    return 0
  fi

  validator_file="$STAGE_ROOT/scripts/release-gate/lib/ZeroSurpriseReportValidator.php"
  [[ -r "$validator_file" ]] || {
    echo "[!] Zero-surprise validator missing or unreadable: $validator_file"
    return 1
  }

  read -r -d '' validator_runtime_code <<'PHP' || true
require_once $argv[1];

$validator = new \ReleaseGate\ZeroSurpriseReportValidator();
$result = $validator->validateFile(
    (string) ($argv[2] ?? ''),
    (string) ($argv[3] ?? ''),
    (string) ($argv[4] ?? ''),
    (int) ($argv[5] ?? 0),
);

if (($result['ok'] ?? false) === true) {
    exit(0);
}

$errors = $result['errors'] ?? [];
if (!is_array($errors) || $errors === []) {
    fwrite(STDERR, "    - Zero-surprise report validation failed with unknown errors." . PHP_EOL);
    exit(1);
}

foreach ($errors as $error) {
    fwrite(STDERR, "    - " . (string) $error . PHP_EOL);
}

exit(1);
PHP

  echo "[i] Validating zero-surprise report: $ZERO_SURPRISE_REPORT"
  if ! php -r "$validator_runtime_code" \
    "$validator_file" \
    "$ZERO_SURPRISE_REPORT" \
    "$REL" \
    "$ZERO_SURPRISE_EXPECTED_MODE" \
    "$ZERO_SURPRISE_MAX_AGE_MINUTES"; then
    echo "[!] Zero-surprise report validation failed."
    return 1
  fi

  echo "[OK] Zero-surprise report validation passed."
  return 0
}

run_zero_surprise_live_canary() {
  local canary_script

  if [[ "$ZERO_SURPRISE_CANARY_ENABLED" -ne 1 ]]; then
    echo "[i] Zero-surprise post-deploy canary disabled (--zero-surprise-canary-enabled=0)."
    return 0
  fi

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] run zero-surprise live canary with credentials '$ZERO_SURPRISE_CANARY_CREDENTIALS_FILE' (timeout=${ZERO_SURPRISE_CANARY_TIMEOUT}s)"
    return 0
  fi

  canary_script="$APP/scripts/release-gate/zero_surprise_live_canary.php"
  [[ -r "$canary_script" ]] || {
    echo "[!] Zero-surprise live canary script missing or unreadable: $canary_script"
    return 1
  }

  ZERO_SURPRISE_CANARY_REPORT="$APP/storage/logs/release-gate/zero-surprise-live-canary-${REL}-$(date -u +%Y%m%dT%H%M%SZ).json"

  echo "[i] Running zero-surprise post-deploy canary"
  echo "    Credentials file : $ZERO_SURPRISE_CANARY_CREDENTIALS_FILE"
  echo "    Timeout (seconds): $ZERO_SURPRISE_CANARY_TIMEOUT"
  echo "    Profile          : $ZERO_SURPRISE_PROFILE"
  echo "    Report           : $ZERO_SURPRISE_CANARY_REPORT"

  if ! php "$canary_script" \
    --release-id="$REL" \
    --credentials-file="$ZERO_SURPRISE_CANARY_CREDENTIALS_FILE" \
    --profile="$ZERO_SURPRISE_PROFILE" \
    --timeout-seconds="$ZERO_SURPRISE_CANARY_TIMEOUT" \
    --output-json="$ZERO_SURPRISE_CANARY_REPORT"; then
    echo "[!] Zero-surprise post-deploy canary failed."
    return 1
  fi

  echo "[OK] Zero-surprise post-deploy canary passed."
  return 0
}

rollback_after_failure() {
  local reason="$1"
  local failed_base="${APP}_failed_${REL}"
  local failed_path="$failed_base"
  local renderer_result="skipped"
  local deep_result="skipped"
  local rollback_ok=1
  local rollback_result="rollback_failed_or_unverifiable"
  local incident_event="deploy_rollback"
  local incident_report=""
  local incident_report_root="$APP"

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
    rollback_result="rollback_succeeded"
    if [[ "$reason" == "zero-surprise canary failed" ]]; then
      incident_event="zero_surprise_canary_failed"
      incident_report="${ZERO_SURPRISE_CANARY_REPORT/$APP/$failed_path}"
      incident_report_root="$failed_path"
    fi
    emit_zero_surprise_incident \
      "$incident_event" \
      "critical" \
      "$reason" \
      "$rollback_result" \
      "$incident_report" \
      "$incident_report_root"
    echo "[!] Rollback succeeded, deployment remains failed."
    exit "$EXIT_ROLLBACK_SUCCESS"
  fi

  if [[ "$reason" == "zero-surprise canary failed" ]]; then
    incident_event="zero_surprise_canary_failed"
    incident_report="$ZERO_SURPRISE_CANARY_REPORT"
  fi
  emit_zero_surprise_incident \
    "$incident_event" \
    "critical" \
    "$reason" \
    "$rollback_result" \
    "$incident_report" \
    "$incident_report_root"
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
    --zero-surprise-report) ZERO_SURPRISE_REPORT="$2"; shift 2;;
    --zero-surprise-dump-file) ZERO_SURPRISE_DUMP_FILE="$2"; shift 2;;
    --zero-surprise-predeploy-credentials-file) ZERO_SURPRISE_PREDEPLOY_CREDENTIALS_FILE="$2"; shift 2;;
    --zero-surprise-profile) ZERO_SURPRISE_PROFILE="$2"; shift 2;;
    --zero-surprise-max-age-minutes) ZERO_SURPRISE_MAX_AGE_MINUTES="$2"; shift 2;;
    --require-zero-surprise) REQUIRE_ZERO_SURPRISE="$2"; shift 2;;
    --zero-surprise-breakglass-file) ZERO_SURPRISE_BREAKGLASS_FILE="$2"; shift 2;;
    --zero-surprise-canary-enabled) ZERO_SURPRISE_CANARY_ENABLED="$2"; shift 2;;
    --zero-surprise-canary-timeout) ZERO_SURPRISE_CANARY_TIMEOUT="$2"; shift 2;;
    --zero-surprise-canary-credentials-file) ZERO_SURPRISE_CANARY_CREDENTIALS_FILE="$2"; shift 2;;
    --zero-surprise-incident-webhook-file) ZERO_SURPRISE_INCIDENT_WEBHOOK_FILE="$2"; shift 2;;
    --zero-surprise-incident-timeout) ZERO_SURPRISE_INCIDENT_TIMEOUT="$2"; shift 2;;
    -h|--help) usage; exit 0;;
    *) die "[!] Unknown option: $1";;
  esac
done

[[ -n "$REL" ]] || die "[!] --rel is required."
[[ "$REQUIRE_ZERO_SURPRISE" == "0" || "$REQUIRE_ZERO_SURPRISE" == "1" ]] \
  || die "[!] --require-zero-surprise must be 0 or 1."
[[ "$ZERO_SURPRISE_CANARY_ENABLED" == "0" || "$ZERO_SURPRISE_CANARY_ENABLED" == "1" ]] \
  || die "[!] --zero-surprise-canary-enabled must be 0 or 1."
is_positive_integer "$ZERO_SURPRISE_MAX_AGE_MINUTES" \
  || die "[!] --zero-surprise-max-age-minutes must be a positive integer."
is_positive_integer "$ZERO_SURPRISE_CANARY_TIMEOUT" \
  || die "[!] --zero-surprise-canary-timeout must be a positive integer."
is_positive_integer "$ZERO_SURPRISE_INCIDENT_TIMEOUT" \
  || die "[!] --zero-surprise-incident-timeout must be a positive integer."
if [[ "$REQUIRE_ZERO_SURPRISE" -eq 1 ]]; then
  [[ -n "$ZERO_SURPRISE_DUMP_FILE" ]] || die "[!] --zero-surprise-dump-file is required when --require-zero-surprise=1."
  [[ -n "$ZERO_SURPRISE_PREDEPLOY_CREDENTIALS_FILE" ]] \
    || die "[!] --zero-surprise-predeploy-credentials-file is required when --require-zero-surprise=1."
fi
if [[ "$ZERO_SURPRISE_CANARY_ENABLED" -eq 1 ]]; then
  [[ -n "$ZERO_SURPRISE_CANARY_CREDENTIALS_FILE" ]] \
    || die "[!] --zero-surprise-canary-credentials-file is required when --zero-surprise-canary-enabled=1."
fi
if [[ "$REQUIRE_ZERO_SURPRISE" -eq 0 || "$ZERO_SURPRISE_CANARY_ENABLED" -eq 0 ]]; then
  [[ -n "$ZERO_SURPRISE_BREAKGLASS_FILE" ]] \
    || die "[!] --zero-surprise-breakglass-file is required when any zero-surprise bypass is requested."
fi
if [[ -z "$ZERO_SURPRISE_PROFILE" ]]; then
  die "[!] --zero-surprise-profile must not be empty."
fi

absolutize_path_var HEALTHZ_TOKEN_FILE
absolutize_path_var ZERO_SURPRISE_REPORT
absolutize_path_var ZERO_SURPRISE_DUMP_FILE
absolutize_path_var ZERO_SURPRISE_PREDEPLOY_CREDENTIALS_FILE
absolutize_path_var ZERO_SURPRISE_BREAKGLASS_FILE
absolutize_path_var ZERO_SURPRISE_CANARY_CREDENTIALS_FILE
absolutize_path_var ZERO_SURPRISE_INCIDENT_WEBHOOK_FILE
resolve_reload_services

if [[ "$DRYRUN" -eq 0 ]]; then
  [[ -n "$HEALTHZ_TOKEN_FILE" ]] || die "[!] --healthz-token-file is required for non-dry deployments."
  [[ -r "$HEALTHZ_TOKEN_FILE" ]] || die "[!] Token file is not readable: $HEALTHZ_TOKEN_FILE"
  if [[ "$REQUIRE_ZERO_SURPRISE" -eq 1 ]]; then
    [[ -r "$ZERO_SURPRISE_DUMP_FILE" ]] \
      || die "[!] Predeploy dump file is not readable: $ZERO_SURPRISE_DUMP_FILE"
    [[ -r "$ZERO_SURPRISE_PREDEPLOY_CREDENTIALS_FILE" ]] \
      || die "[!] Predeploy credentials file is not readable: $ZERO_SURPRISE_PREDEPLOY_CREDENTIALS_FILE"
  fi
  if [[ "$ZERO_SURPRISE_CANARY_ENABLED" -eq 1 ]]; then
    [[ -r "$ZERO_SURPRISE_CANARY_CREDENTIALS_FILE" ]] \
      || die "[!] Canary credentials file is not readable: $ZERO_SURPRISE_CANARY_CREDENTIALS_FILE"
  fi
  [[ -n "$ZERO_SURPRISE_INCIDENT_WEBHOOK_FILE" ]] \
    || die "[!] --zero-surprise-incident-webhook-file is required for non-dry deployments."
  [[ -r "$ZERO_SURPRISE_INCIDENT_WEBHOOK_FILE" ]] \
    || die "[!] Incident webhook file is not readable: $ZERO_SURPRISE_INCIDENT_WEBHOOK_FILE"
  if [[ -n "$ZERO_SURPRISE_BREAKGLASS_FILE" ]]; then
    [[ -r "$ZERO_SURPRISE_BREAKGLASS_FILE" ]] \
      || die "[!] Breakglass file is not readable: $ZERO_SURPRISE_BREAKGLASS_FILE"
  fi
fi

ARCHIVE="${SRC}/${REL}.tar.gz"
STAGE="${APP}_${REL}_stage"
PREV="${APP}_prev_${REL}"
LOG="/var/log/deploy_ea_${REL}.log"

mkdir -p "$(dirname "$LOG")"
if [[ "$DRYRUN" -eq 1 && ! -w "$(dirname "$LOG")" ]]; then
  echo "[i] Dry-run log path is not writable, streaming output without tee: $LOG"
else
  exec > >(tee -a "$LOG") 2>&1
fi

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
echo "    Zero-surprise gate   : $REQUIRE_ZERO_SURPRISE"
echo "    Zero-surprise dump   : ${ZERO_SURPRISE_DUMP_FILE:-<not-set>}"
echo "    Zero-surprise creds  : ${ZERO_SURPRISE_PREDEPLOY_CREDENTIALS_FILE:-<not-set>}"
echo "    Zero-surprise report : ${ZERO_SURPRISE_REPORT:-<auto>}"
echo "    Zero-surprise profile: ${ZERO_SURPRISE_PROFILE}"
echo "    Zero-surprise max age: ${ZERO_SURPRISE_MAX_AGE_MINUTES}m"
echo "    Breakglass file      : ${ZERO_SURPRISE_BREAKGLASS_FILE:-<not-set>}"
echo "    Canary enabled       : $ZERO_SURPRISE_CANARY_ENABLED"
echo "    Canary timeout       : ${ZERO_SURPRISE_CANARY_TIMEOUT}s"
echo "    Canary credentials   : ${ZERO_SURPRISE_CANARY_CREDENTIALS_FILE:-<not-set>}"
echo "    Incident webhook     : ${ZERO_SURPRISE_INCIDENT_WEBHOOK_FILE:-<not-set>}"
echo "    Incident timeout     : ${ZERO_SURPRISE_INCIDENT_TIMEOUT}s"
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
if [[ "$REQUIRE_ZERO_SURPRISE" -eq 1 ]]; then
  require_command docker
  require_docker_compose
fi
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

validate_stage_release_artifact
validate_deploy_script_drift
validate_breakglass_policy || die "[!] Zero-surprise breakglass policy validation failed."
prepare_zero_surprise_stage_runtime
run_shell "chown -R '$WEBUSER':'$WEBUSER' '$STAGE_ROOT'"
run_shell "find '$STAGE_ROOT' -type d -exec chmod 755 {} \\;"
run_shell "find '$STAGE_ROOT' -type f -exec chmod 644 {} \\;"
restore_runtime_script_permissions
run_zero_surprise_predeploy_replay || die "[!] Zero-surprise pre-deploy replay failed. Aborting before atomic switch."
validate_zero_surprise_report || die "[!] Zero-surprise pre-deploy gate failed. Aborting before atomic switch."

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
restore_runtime_script_permissions

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

if ! run_zero_surprise_live_canary; then
  rollback_after_failure "zero-surprise canary failed"
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
