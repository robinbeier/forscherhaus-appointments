#!/usr/bin/env bash

# Prepare the local session directory without changing real session data.
prepare_session_storage() {
    local storage_root=${1:-}
    local sessions_dir="$storage_root/sessions"

    if [[ -z "$storage_root" || ! -d "$storage_root" || -L "$storage_root" ]]; then
        echo "[setup] Invalid storage path: $storage_root" >&2
        return 1
    fi

    if [[ -L "$sessions_dir" ]]; then
        echo "[setup] Refusing symlinked session directory: $sessions_dir" >&2
        return 1
    fi

    local created=0
    if [[ ! -e "$sessions_dir" ]]; then
        (umask 077 && mkdir "$sessions_dir") || {
            echo "[setup] Could not create session directory: $sessions_dir" >&2
            return 1
        }
        created=1
    elif [[ ! -d "$sessions_dir" ]]; then
        echo "[setup] Invalid session path: $sessions_dir" >&2
        return 1
    fi

    local placeholder_only=1
    local entry name
    local entries_file
    entries_file=$(mktemp "${TMPDIR:-/tmp}/fh-session-entries.XXXXXX") || return 1
    if ! find "$sessions_dir" -mindepth 1 -maxdepth 1 -print0 >"$entries_file"; then
        rm -f "$entries_file"
        echo "[setup] Could not inspect session directory: $sessions_dir" >&2
        return 1
    fi
    while IFS= read -r -d '' entry; do
        name=${entry##*/}
        if [[ -L "$entry" ]]; then
            rm -f "$entries_file"
            echo "[setup] Refusing symlink in session directory: $entry" >&2
            return 1
        fi
        if [[ "$name" != ".htaccess" && "$name" != "index.html" ]] || [[ ! -f "$entry" ]]; then
            placeholder_only=0
            break
        fi
    done <"$entries_file"
    rm -f "$entries_file"

    if (( created || placeholder_only )); then
        chmod 0700 "$sessions_dir" || {
            echo "[setup] Could not secure session directory: $sessions_dir" >&2
            return 1
        }
    fi
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
    prepare_session_storage "${1:-${STORAGE_ROOT:-}}"
fi
