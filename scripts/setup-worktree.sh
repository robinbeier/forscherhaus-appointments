#!/usr/bin/env bash
set -euo pipefail

# Ensure Homebrew binaries are resolvable on Apple Silicon and Intel Macs.
if [[ "$(uname -s)" == "Darwin" ]]; then
    export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
fi

require_cmd() {
    command -v "$1" >/dev/null 2>&1 || {
        echo "[setup] Missing required command: $1"
        exit 1
    }
}

require_cmd git
require_cmd php
require_cmd composer
require_cmd node
require_cmd npm
require_cmd npx
bash ./scripts/ci/require_node_minimum.sh 24.0.0 setup

# Prevent noisy permission-only diffs in Docker workflows.
git config core.fileMode false || true

# Create local config once (config.php is gitignored).
if [[ ! -f config.php ]]; then
    cp config-sample.php config.php
    echo "[setup] Created config.php from config-sample.php"
fi

# Ensure non-session runtime folders exist and are writable in local/dev Docker
# usage. PHP-FPM receives sessions through its private named volume; create a
# fresh host-side session directory privately for host PHP, without widening
# an existing directory.
if [[ -L storage || ( -e storage && ! -d storage ) ]]; then
    echo "[setup] Refusing invalid storage root: storage" >&2
    exit 1
fi
mkdir -p storage
# The checkout owner can create runtime children; other local users must not
# replace a child between the symlink check and the recursive permission pass.
chmod 0755 storage
# Keep session data out of the broad developer-writable permission pass.
if ! symlinked_storage_child="$(find -P storage -mindepth 1 -maxdepth 1 ! -name sessions -type l -print -quit)"; then
    echo "[setup] Could not inspect storage children" >&2
    exit 1
fi
if [[ -n "$symlinked_storage_child" ]]; then
    echo "[setup] Refusing symlinked storage child" >&2
    exit 1
fi
mkdir -p storage/{backups,cache,logs,uploads}
# Only the fixed runtime directories need shared local write access. Leave
# existing nested files and directories unchanged; traversing a writable tree
# to chmod it would reopen a symlink race.
chmod a+rwX storage/{backups,cache,logs,uploads}
source ./scripts/prepare-session-storage.sh
prepare_session_storage storage

# Install backend/frontend dependencies.
bash ./scripts/ci/ensure_local_deps.sh --force

# Install the managed pre-commit hook; pre-PR checks remain explicit commands.
bash ./scripts/install-git-hooks.sh

echo "[setup] Worktree setup completed."
