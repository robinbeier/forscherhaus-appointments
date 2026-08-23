#!/usr/bin/env bash
set -euo pipefail

if (( $# != 0 )); then
    exit 70
fi

child_pid=''
forwarded_signal=''

forward_signal() {
    local signal_name="$1"
    forwarded_signal="$signal_name"
    if [[ -n "$child_pid" ]]; then
        kill "-${signal_name}" "$child_pid" 2>/dev/null || true
    fi
}

trap 'forward_signal HUP' HUP
trap 'forward_signal INT' INT
trap 'forward_signal TERM' TERM

/usr/bin/python3 -I -B /usr/local/libexec/fh-backup-set-producer-v1 &
child_pid="$!"
status=0
wait "$child_pid" || status="$?"
if [[ -n "$forwarded_signal" && "$status" -gt 128 ]]; then
    status=0
    wait "$child_pid" || status="$?"
fi
exit "$status"
