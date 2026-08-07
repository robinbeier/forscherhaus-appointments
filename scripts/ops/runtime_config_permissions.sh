#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

usage() {
  cat <<'USAGE'
Usage: runtime_config_permissions.sh harden|verify --app-root PATH --runtime-user USER

Enforces the production runtime config contract without reading config.php:
  app root   root:root 0755
  config.php root:<runtime-user-primary-group> 0440, regular non-symlink, one hardlink
USAGE
}

fail() {
  echo "[!] Runtime config permission contract failed: $1" >&2
  exit 1
}

require_command() {
  command -v "$1" >/dev/null 2>&1 || fail "required command is unavailable: $1"
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  usage
  exit 0
fi

ACTION="${1:-}"
if [[ -n "$ACTION" ]]; then
  shift
fi

APP_ROOT=""
RUNTIME_USER=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --app-root)
      [[ $# -ge 2 ]] || fail "--app-root requires a value"
      APP_ROOT="$2"
      shift 2
      ;;
    --runtime-user)
      [[ $# -ge 2 ]] || fail "--runtime-user requires a value"
      RUNTIME_USER="$2"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      fail "unknown argument: $1"
      ;;
  esac
done

case "$ACTION" in
  harden|verify) ;;
  *)
    usage >&2
    fail "action must be harden or verify"
    ;;
esac

[[ -n "$APP_ROOT" ]] || fail "--app-root is required"
[[ -n "$RUNTIME_USER" ]] || fail "--runtime-user is required"

while [[ "$APP_ROOT" != "/" && "$APP_ROOT" == */ ]]; do
  APP_ROOT="${APP_ROOT%/}"
done

[[ "$APP_ROOT" == /* ]] || fail "app root must be an absolute path"
[[ "$APP_ROOT" != "/" ]] || fail "app root must not be the filesystem root"

require_command chmod
require_command chown
require_command id
require_command php
require_command runuser
require_command stat

[[ "$EUID" -eq 0 ]] || fail "$ACTION requires root privileges"
[[ -d "$APP_ROOT" ]] || fail "app root is not a directory: $APP_ROOT"
[[ ! -L "$APP_ROOT" ]] || fail "app root must not be a symlink: $APP_ROOT"

CONFIG_PATH="$APP_ROOT/config.php"
[[ -f "$CONFIG_PATH" ]] || fail "config.php is not a regular file: $CONFIG_PATH"
[[ ! -L "$CONFIG_PATH" ]] || fail "config.php must not be a symlink: $CONFIG_PATH"

ROOT_UID="$(id -u root)" || fail "could not resolve root uid"
ROOT_GID="$(id -g root)" || fail "could not resolve root gid"
RUNTIME_UID="$(id -u "$RUNTIME_USER")" || fail "runtime user does not exist: $RUNTIME_USER"
RUNTIME_GID="$(id -g "$RUNTIME_USER")" || fail "could not resolve runtime group for: $RUNTIME_USER"

[[ "$RUNTIME_UID" != "$ROOT_UID" ]] || fail "runtime user must not be root"

CONFIG_LINKS="$(stat -c '%h' -- "$CONFIG_PATH")" || fail "could not read config.php link count"
[[ "$CONFIG_LINKS" == "1" ]] || fail "config.php must have exactly one hardlink (observed: $CONFIG_LINKS)"

HARDEN_TRANSACTION_ACTIVE=0
ORIGINAL_APP_OWNER=""
ORIGINAL_APP_MODE=""
ORIGINAL_CONFIG_OWNER=""
ORIGINAL_CONFIG_MODE=""

restore_original_permissions() {
  local exit_code="$?"
  local restore_ok=1

  trap - EXIT

  if [[ "$HARDEN_TRANSACTION_ACTIVE" -eq 1 ]]; then
    chown "$ORIGINAL_CONFIG_OWNER" -- "$CONFIG_PATH" || restore_ok=0
    chmod "$ORIGINAL_CONFIG_MODE" -- "$CONFIG_PATH" || restore_ok=0
    chown "$ORIGINAL_APP_OWNER" -- "$APP_ROOT" || restore_ok=0
    chmod "$ORIGINAL_APP_MODE" -- "$APP_ROOT" || restore_ok=0

    if [[ "$restore_ok" -eq 1 ]]; then
      echo "[i] Restored prior runtime config permission metadata after failed hardening." >&2
    else
      echo "[!] Failed to restore prior runtime config permission metadata; manual intervention required." >&2
      exit 2
    fi
  fi

  exit "$exit_code"
}

if [[ "$ACTION" == "harden" ]]; then
  ORIGINAL_APP_OWNER="$(stat -c '%u:%g' -- "$APP_ROOT")" \
    || fail "could not capture app root ownership"
  ORIGINAL_APP_MODE="$(stat -c '%a' -- "$APP_ROOT")" \
    || fail "could not capture app root mode"
  ORIGINAL_CONFIG_OWNER="$(stat -c '%u:%g' -- "$CONFIG_PATH")" \
    || fail "could not capture config.php ownership"
  ORIGINAL_CONFIG_MODE="$(stat -c '%a' -- "$CONFIG_PATH")" \
    || fail "could not capture config.php mode"
  HARDEN_TRANSACTION_ACTIVE=1
  trap restore_original_permissions EXIT

  chown "$ROOT_UID:$ROOT_GID" -- "$APP_ROOT" \
    || fail "could not set app root ownership"
  chmod 0755 -- "$APP_ROOT" \
    || fail "could not set app root mode"
  chown "$ROOT_UID:$RUNTIME_GID" -- "$CONFIG_PATH" \
    || fail "could not set config.php ownership"
  chmod 0440 -- "$CONFIG_PATH" \
    || fail "could not set config.php mode"
fi

APP_OWNER="$(stat -c '%u:%g' -- "$APP_ROOT")" || fail "could not read app root ownership"
APP_MODE="$(stat -c '%a' -- "$APP_ROOT")" || fail "could not read app root mode"
CONFIG_OWNER="$(stat -c '%u:%g' -- "$CONFIG_PATH")" || fail "could not read config.php ownership"
CONFIG_MODE="$(stat -c '%a' -- "$CONFIG_PATH")" || fail "could not read config.php mode"
CONFIG_LINKS="$(stat -c '%h' -- "$CONFIG_PATH")" || fail "could not read config.php link count"

[[ "$APP_OWNER" == "$ROOT_UID:$ROOT_GID" ]] \
  || fail "app root owner must be $ROOT_UID:$ROOT_GID (observed: $APP_OWNER)"
[[ "$APP_MODE" == "755" ]] \
  || fail "app root mode must be 755 (observed: $APP_MODE)"
[[ "$CONFIG_OWNER" == "$ROOT_UID:$RUNTIME_GID" ]] \
  || fail "config.php owner must be $ROOT_UID:$RUNTIME_GID (observed: $CONFIG_OWNER)"
[[ "$CONFIG_MODE" == "440" ]] \
  || fail "config.php mode must be 440 (observed: $CONFIG_MODE)"
[[ "$CONFIG_LINKS" == "1" ]] \
  || fail "config.php must have exactly one hardlink (observed: $CONFIG_LINKS)"

if ! runuser -u "$RUNTIME_USER" -- php -r 'exit(is_readable($argv[1]) ? 0 : 1);' "$CONFIG_PATH"; then
  fail "config.php is not readable by runtime user: $RUNTIME_USER"
fi

if runuser -u "$RUNTIME_USER" -- php -r 'exit(is_writable($argv[1]) ? 0 : 1);' "$CONFIG_PATH"; then
  fail "config.php is writable by runtime user: $RUNTIME_USER"
fi

if runuser -u "$RUNTIME_USER" -- php -r 'exit(is_writable($argv[1]) ? 0 : 1);' "$APP_ROOT"; then
  fail "app root is writable by runtime user: $RUNTIME_USER"
fi

HARDEN_TRANSACTION_ACTIVE=0
trap - EXIT

echo "[OK] Runtime config permission contract verified: app_owner=$APP_OWNER app_mode=$APP_MODE config_owner=$CONFIG_OWNER config_mode=$CONFIG_MODE config_links=$CONFIG_LINKS runtime_user=$RUNTIME_USER readable=yes writable=no replaceable=no"
