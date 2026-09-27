#!/usr/bin/env bash
set -euo pipefail

app_root=${APP_ROOT:-/var/www/html/easyappointments}
action=${1:-}
[[ $# == 1 ]] || { echo 'exactly one action required' >&2; exit 64; }
case "$action" in activate|verify|deactivate) ;; *) echo 'usage: zero_surprise_canary_fixture.sh activate|verify|deactivate' >&2; exit 64 ;; esac
[[ "$(id -u)" == 0 ]] || { echo 'root required' >&2; exit 77; }

# Serialize every fixture transition with release admission. When the deploy
# runner already holds the lock, reuse its inherited open file description.
lock_path='/var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock'
lock_parent="$(dirname -- "$lock_path")"
[[ -d "$lock_parent" && ! -L "$lock_parent" && "$(stat -c '%a:%u:%g' -- "$lock_parent" 2>/dev/null || true)" == 700:0:0 ]] || exit 75
[[ -f "$lock_path" && ! -L "$lock_path" && "$(realpath -e -- "$lock_path" 2>/dev/null || true)" == "$lock_path" ]] || exit 75
lock_before="$(stat -c '%a:%u:%g:%h:%s:%d:%i' -- "$lock_path" 2>/dev/null || true)"
[[ "$lock_before" =~ ^600:0:0:1:0:[0-9]+:[0-9]+$ ]] || exit 75
if [[ -n "${ORDINARY_CHANGE_LOCK_FD:-}" ]]; then
  [[ "$ORDINARY_CHANGE_LOCK_FD" =~ ^[0-9]+$ ]] || exit 75
  lock_fd="$ORDINARY_CHANGE_LOCK_FD"
else
  exec {lock_fd}<"$lock_path" || exit 75
fi
[[ "$(stat -Lc '%a:%u:%g:%h:%s:%d:%i' -- "/proc/$$/fd/$lock_fd" 2>/dev/null || true)" == "$lock_before" ]] || exit 75
flock -n "$lock_fd" || exit 75
[[ "$(stat -c '%a:%u:%g:%h:%s:%d:%i' -- "$lock_path" 2>/dev/null || true)" == "$lock_before" ]] || exit 75

unit=fh-zero-surprise-canary-cleanup
if [[ "$action" == activate ]]; then
  for suffix in timer service; do
    state=$(systemctl show "$unit.$suffix" --property=LoadState --value) || exit 1
    [[ "$state" == not-found ]] || { echo 'cleanup unit already exists' >&2; exit 75; }
  done
  # The callback follows the original directory inode across the existing
  # deploy script's rollback rename. It never runs another release's console.
  app_root=$(realpath -e -- "$app_root")
  parent=$(dirname -- "$app_root")
  identity=$(stat -c '%d:%i' -- "$app_root")
  callback='set -euo pipefail
for candidate in "$1"/*; do
  [[ -d "$candidate" && ! -L "$candidate" ]] || continue
  [[ $(stat -c "%d:%i" -- "$candidate") == "$2" ]] || continue
  exec php "$candidate/index.php" console zero_surprise_canary deactivate
done
echo "canary cleanup release directory unavailable" >&2
exit 1'
  systemd-run --quiet --unit="$unit" --on-active=10m --collect /bin/bash -c "$callback" canary-cleanup "$parent" "$identity"
  if ! php "$app_root/index.php" console zero_surprise_canary activate; then
    # Keep the independent timer when compensation fails.
    if php "$app_root/index.php" console zero_surprise_canary deactivate; then
      systemctl stop "$unit.timer"
    fi
    exit 1
  fi
elif [[ "$action" == verify ]]; then
  php "$app_root/index.php" console zero_surprise_canary verify
else
  php "$app_root/index.php" console zero_surprise_canary deactivate
  php "$app_root/index.php" console zero_surprise_canary verify
  systemctl stop "$unit.timer"
fi
