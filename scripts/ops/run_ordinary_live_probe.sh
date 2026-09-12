#!/usr/bin/env bash
set -euo pipefail

# Root operator wrapper for one reviewed, ordinary synthetic account/session run.
action=${1:-preflight}
release=${2:-}
app_root=${APP_ROOT:-/var/www/html/easyappointments}
case "$action" in preflight|account|session|cleanup|verify) ;; *) echo 'unsupported action' >&2; exit 64 ;; esac
[[ $# == 2 && "$release" =~ ^ea_[a-zA-Z0-9_]+$ ]] || { echo 'action and expected release required' >&2; exit 64; }
[[ $(id -u) == 0 ]] || { echo 'root required' >&2; exit 77; }
umask 077
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
probe="$script_dir/ordinary_live_probe.php"
app_root=$(realpath -e -- "$app_root")

# Do not execute a root cleanup callback from a caller-writable tool checkout.
for path in "$probe" "$script_dir/../release-gate/lib/OrdinaryLiveFixture.php" \
    "$script_dir/../release-gate/lib/OrdinaryProbeSessions.php" \
    "$script_dir/../release-gate/lib/OrdinaryProbeEvidence.php" \
    "$script_dir/../release-gate/lib/OrdinarySessionProbe.php" \
    "$script_dir/../release-gate/lib/OrdinaryAccountProbe.php" \
    "$script_dir/../release-gate/lib/GateHttpClient.php" \
    "$script_dir/../../deploy_ea.sh" /root/deploy_ea.sh; do
    path=$(realpath -e -- "$path")
    [[ -f "$path" && ! -L "$path" && $(stat -c %u -- "$path") == 0 ]] || exit 77
    while [[ "$path" != / ]]; do
        mode=$(stat -c %a -- "$path")
        [[ $(stat -c %u -- "$path") == 0 ]] && (( (8#$mode & 8#022) == 0 )) || exit 77
        path=$(dirname -- "$path")
    done
done

# The reviewed tool bundle is independent of the replaceable application tree.
tool_root=$(cd "$script_dir/../.." && pwd -P)
[[ "$tool_root" != "$app_root" && "$tool_root" != "$app_root/"* ]] || {
    echo 'use an immutable root-controlled operator bundle outside the application release' >&2; exit 77;
}
coordination="$tool_root/deploy_ea.sh"
cmp -s -- "$coordination" /root/deploy_ea.sh || {
    echo 'installed deploy script must match the reviewed coordinated version' >&2; exit 77;
}
# The root-controlled deploy script exposes the same lock contract when sourced.
source "$coordination"
umask 077
ordinary_production_change_lock || exit $?
coordination_identity=$(stat -c '%d:%i' -- "$coordination")
parent=$(dirname -- "$app_root")
identity=$(stat -c '%d:%i' -- "$app_root")
probe_identity=$(stat -c '%d:%i' -- "$probe")
invoke() {
    local candidate
    [[ $(stat -c '%d:%i' -- "$probe") == "$probe_identity" ]] || return 1
    if [[ "$1" != deactivate && "$1" != verify ]]; then
        [[ -d "$app_root" && ! -L "$app_root" && $(stat -c '%d:%i' -- "$app_root") == "$identity" ]] || {
            echo 'active application identity changed; evidence collection stopped' >&2
            return 1
        }
    fi
    for candidate in "$parent"/*; do
        [[ -d "$candidate" && ! -L "$candidate" ]] || continue
        [[ $(stat -c '%d:%i' -- "$candidate") == "$identity" ]] || continue
        php "$probe" --action="$1" --app-root="$candidate" --active-app-root="$app_root" \
            --expected-app-identity="$identity" --expected-release="$release"
        return $?
    done
    echo 'original ordinary probe application directory unavailable' >&2
    return 1
}
unit=fh-defense-ordinary-cleanup
stop_cleanup_units() {
    local load_state
    systemctl stop "$unit.timer" || return $?
    load_state=$(systemctl show "$unit.service" --property=LoadState --value) || return $?
    if [[ "$load_state" != not-found ]]; then
        systemctl stop "$unit.service" || return $?
    fi
}
if [[ "$action" == preflight || "$action" == verify ]]; then
    ordinary_assert_no_pending_probe || exit $?
    invoke "$action"
    exit
fi
if [[ "$action" == cleanup ]]; then
    invoke deactivate
    invoke verify
    stop_cleanup_units
    # Recovery can revoke known identity/session state, but must not erase an
    # interruption marker whose unjournaled response window is not accounted for.
    ordinary_assert_no_pending_probe || exit $?
    exit
fi
for suffix in timer service; do
    [[ $(systemctl show "$unit.$suffix" --property=LoadState --value) == not-found ]] || {
        echo 'ordinary cleanup unit already exists; inspect prior run' >&2; exit 75;
    }
done
ordinary_assert_no_pending_probe || exit $?
invoke preflight
ordinary_probe_begin || exit $?
callback='set -euo pipefail
[[ -f "$3" && ! -L "$3" && $(stat -c "%d:%i" -- "$3") == "$5" ]] || exit 1
[[ -f "$6" && ! -L "$6" && $(stat -c "%d:%i" -- "$6") == "$7" ]] || exit 1
source "$6"
umask 077
ordinary_production_change_lock /var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock 300 || exit $?
for candidate in "$1"/*; do
    [[ -d "$candidate" && ! -L "$candidate" ]] || continue
    [[ $(stat -c "%d:%i" -- "$candidate") == "$2" ]] || continue
    exec php "$3" --action=deactivate --app-root="$candidate" --active-app-root="$candidate" \
        --expected-app-identity="$2" --expected-release="$4"
done
echo "original ordinary probe application directory unavailable" >&2
exit 1'
# Failed callbacks retry after lock release; foreground cleanup cancels retries.
systemd-run --quiet --unit="$unit" --on-active=3h --collect \
    --property=Restart=on-failure --property=RestartSec=60s --property=StartLimitIntervalSec=0 \
    /bin/bash -c "$callback" ordinary-cleanup "$parent" "$identity" "$probe" "$release" "$probe_identity" "$coordination" "$coordination_identity"
cleanup() {
    local status=$?
    trap - EXIT
    if invoke deactivate && invoke verify; then
        stop_cleanup_units || status=1
        if [[ "$status" == 0 ]]; then
            ordinary_probe_finish || status=1
        else
            echo 'interrupted or failed probe; persistent recovery marker retained' >&2
        fi
    else
        echo 'ordinary cleanup incomplete; independent timer retained' >&2
        status=1
    fi
    exit "$status"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM
invoke activate
invoke account
if [[ "$action" == session ]]; then
    invoke session
fi
