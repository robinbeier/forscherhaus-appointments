#!/usr/bin/env bash

set -euo pipefail

# GitHub-hosted PHP jobs run as the runner user.  The application deliberately
# uses four filesystem locks to bound login KDF concurrency; provision only the
# fixed, non-secret lock directory required by those jobs.
KDF_PARENT='/var/www/html'
KDF_ROOT="$KDF_PARENT/.fh-login-kdf"
SLOT_COUNT=4

fail() {
    echo "host_kdf_slots=failed: $*" >&2
    exit 1
}

command -v sudo >/dev/null 2>&1 || fail 'sudo is required'
command -v stat >/dev/null 2>&1 || fail 'stat is required'
command -v find >/dev/null 2>&1 || fail 'find is required'
[[ "${GITHUB_ACTIONS:-}" == true ]] || fail 'only GitHub Actions may provision host slots'

runner_uid="$(id -u)"
runner_gid="$(id -g)"
[[ "$runner_uid" =~ ^[0-9]+$ ]] || fail 'effective UID is not numeric'
[[ "$runner_gid" =~ ^[0-9]+$ ]] || fail 'effective GID is not numeric'
[[ -n "${GITHUB_ENV:-}" ]] || fail 'GITHUB_ENV is not set'
[[ -f "$GITHUB_ENV" ]] || fail 'GITHUB_ENV is not a regular file'
[[ -w "$GITHUB_ENV" ]] || fail 'GITHUB_ENV is not writable'

# Runner images may or may not contain Apache's default web root.  Create
# only fixed root-owned parents; reject symlinks or unexpected metadata.
for parent in /var /var/www "$KDF_PARENT"; do
    sudo -n test ! -L "$parent" || fail "$parent is a symlink"
    if ! sudo -n test -e "$parent"; then
        sudo -n install -d -m 0755 -o root -g root -- "$parent" || fail "could not create $parent"
    fi
    sudo -n test -d "$parent" || fail "$parent is not a directory"
    parent_metadata="$(sudo -n stat -c '%F|%a|%u|%g' -- "$parent")"
    [[ "$parent_metadata" == 'directory|755|0|0' ]] || fail "$parent has unsafe metadata"
done

sudo -n test ! -L "$KDF_ROOT" || fail "$KDF_ROOT is a symlink"
if ! sudo -n test -e "$KDF_ROOT"; then
    sudo -n install -d -m 0755 -o root -g root -- "$KDF_ROOT" || fail "could not create $KDF_ROOT"
fi
sudo -n test -d "$KDF_ROOT" || fail "$KDF_ROOT is not a directory"

# Refuse to operate on an existing directory containing anything outside the
# four expected lock names.  This avoids touching an unknown file or secret.
unexpected_entry="$(sudo -n find "$KDF_ROOT" -mindepth 1 -maxdepth 1 \
    ! -name 'slot-0.lock' ! -name 'slot-1.lock' ! -name 'slot-2.lock' \
    ! -name 'slot-3.lock' -print -quit)"
[[ -z "$unexpected_entry" ]] || fail "$KDF_ROOT contains an unexpected entry"

root_before="$(sudo -n stat -c '%F|%a|%u|%g|%i' -- "$KDF_ROOT")"
[[ "$root_before" == 'directory|755|0|0|'* ]] || {
    fail "$KDF_ROOT has unsafe metadata"
}

slot_path() {
    printf '%s/slot-%s.lock' "$KDF_ROOT" "$1"
}

create_exclusive_slot() {
    local slot="$1"
    # noclobber gives the shell's create redirection O_EXCL semantics.  The
    # path is fixed by this script and passed as a positional argument.
    sudo -n sh -c 'set -eu; umask 077; (set -C; : > "$1")' sh "$slot" \
        || fail "could not create $slot"
}

declare -a slot_inodes=()
for ((slot_number = 0; slot_number < SLOT_COUNT; slot_number++)); do
    slot="$(slot_path "$slot_number")"

    sudo -n test ! -L "$slot" || fail "$slot is a symlink"
    if sudo -n test -e "$slot"; then
        sudo -n test -f "$slot" || fail "$slot is not a regular file"
    else
        create_exclusive_slot "$slot"
        sudo -n chown "$runner_uid:$runner_gid" -- "$slot" \
            || fail "could not set ownership on $slot"
        sudo -n chmod 0600 -- "$slot" || fail "could not set mode on $slot"
    fi

    metadata="$(sudo -n stat -c '%a|%u|%g|%h|%i' -- "$slot")"
    [[ "$metadata" =~ ^600\|$runner_uid\|$runner_gid\|1\|([0-9]+)$ ]] \
        || fail "$slot has unsafe metadata"
    slot_inodes[slot_number]="${BASH_REMATCH[1]}"
done

# Existing files are never replaced.  Confirm that the directory and every
# slot retained the inode observed after provisioning (including reruns).
root_after="$(sudo -n stat -c '%F|%a|%u|%g|%i' -- "$KDF_ROOT")"
[[ "$root_after" == "$root_before" ]] || fail "$KDF_ROOT inode or metadata changed"
for ((slot_number = 0; slot_number < SLOT_COUNT; slot_number++)); do
    slot="$(slot_path "$slot_number")"
    metadata="$(sudo -n stat -c '%a|%u|%g|%h|%i' -- "$slot")"
    [[ "$metadata" =~ \|${slot_inodes[slot_number]}$ ]] \
        || fail "$slot inode changed during provisioning"
done

printf 'EA_LOGIN_KDF_SLOT_UID=%s\n' "$runner_uid" >>"$GITHUB_ENV"
printf 'EA_LOGIN_KDF_SLOT_GID=%s\n' "$runner_gid" >>"$GITHUB_ENV"
echo "host_kdf_slots=passed uid=$runner_uid gid=$runner_gid slots=$SLOT_COUNT"
