#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

usage() {
  cat <<'USAGE'
Usage: runtime_config_rollback.sh --active PATH --previous PATH --failed PATH --runtime-user USER

Moves the active release aside, restores the previous release, and applies the
runtime config permission contract to both resulting trees.
USAGE
}

fail() {
  echo "[!] Runtime config rollback failed: $1" >&2
  exit 1
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  usage
  exit 0
fi

ACTIVE_PATH=""
PREVIOUS_PATH=""
FAILED_PATH=""
RUNTIME_USER=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --active)
      [[ $# -ge 2 ]] || fail "--active requires a value"
      ACTIVE_PATH="$2"
      shift 2
      ;;
    --previous)
      [[ $# -ge 2 ]] || fail "--previous requires a value"
      PREVIOUS_PATH="$2"
      shift 2
      ;;
    --failed)
      [[ $# -ge 2 ]] || fail "--failed requires a value"
      FAILED_PATH="$2"
      shift 2
      ;;
    --runtime-user)
      [[ $# -ge 2 ]] || fail "--runtime-user requires a value"
      RUNTIME_USER="$2"
      shift 2
      ;;
    *)
      fail "unknown argument: $1"
      ;;
  esac
done

[[ -n "$ACTIVE_PATH" ]] || fail "--active is required"
[[ -n "$PREVIOUS_PATH" ]] || fail "--previous is required"
[[ -n "$FAILED_PATH" ]] || fail "--failed is required"
[[ -n "$RUNTIME_USER" ]] || fail "--runtime-user is required"

for path in "$ACTIVE_PATH" "$PREVIOUS_PATH" "$FAILED_PATH"; do
  [[ "$path" == /* ]] || fail "release paths must be absolute: $path"
  [[ "$path" != "/" ]] || fail "release paths must not be the filesystem root"
done

[[ "$EUID" -eq 0 ]] || fail "rollback requires root privileges"
[[ -d "$ACTIVE_PATH" && ! -L "$ACTIVE_PATH" ]] || fail "active release is not a regular directory: $ACTIVE_PATH"
[[ -d "$PREVIOUS_PATH" && ! -L "$PREVIOUS_PATH" ]] || fail "previous release is not a regular directory: $PREVIOUS_PATH"
[[ ! -e "$FAILED_PATH" && ! -L "$FAILED_PATH" ]] || fail "failed release target already exists: $FAILED_PATH"

ROLLBACK_SCRIPT="$ACTIVE_PATH/scripts/ops/runtime_config_rollback.sh"
[[ -f "$ROLLBACK_SCRIPT" && ! -L "$ROLLBACK_SCRIPT" ]] \
  || fail "rollback script is missing from active release: $ROLLBACK_SCRIPT"

mv -- "$ACTIVE_PATH" "$FAILED_PATH" \
  || fail "could not move active release to failed path"

if ! mv -- "$PREVIOUS_PATH" "$ACTIVE_PATH"; then
  echo "[!] Runtime config rollback failed: could not restore previous release" >&2
  if mv -- "$FAILED_PATH" "$ACTIVE_PATH"; then
    echo "[i] Restored the original active release after rollback switch failure." >&2
  else
    echo "[!] Could not restore the original active release; manual intervention required." >&2
  fi
  exit 1
fi

PERMISSION_HELPER="$FAILED_PATH/scripts/ops/runtime_config_permissions.sh"
[[ -f "$PERMISSION_HELPER" && ! -L "$PERMISSION_HELPER" ]] \
  || fail "permission helper is missing from failed release: $PERMISSION_HELPER"

permission_ok=1
if ! bash "$PERMISSION_HELPER" harden --app-root "$ACTIVE_PATH" --runtime-user "$RUNTIME_USER" \
  || ! bash "$PERMISSION_HELPER" verify --app-root "$ACTIVE_PATH" --runtime-user "$RUNTIME_USER"; then
  echo "[!] Restored release runtime config permissions are unverifiable." >&2
  permission_ok=0
fi

if ! bash "$PERMISSION_HELPER" harden --app-root "$FAILED_PATH" --runtime-user "$RUNTIME_USER" \
  || ! bash "$PERMISSION_HELPER" verify --app-root "$FAILED_PATH" --runtime-user "$RUNTIME_USER"; then
  echo "[!] Failed release runtime config permissions are unverifiable." >&2
  permission_ok=0
fi

[[ "$permission_ok" -eq 1 ]] || exit 1

echo "[OK] Runtime config rollback switch and permission contracts verified."
