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

# Prevent noisy permission-only diffs in Docker workflows.
git config core.fileMode false || true

# Create local config once (config.php is gitignored).
if [[ ! -f config.php ]]; then
    cp config-sample.php config.php
    echo "[setup] Created config.php from config-sample.php"
fi

# Ensure runtime folders exist and are writable in local/dev Docker usage.
mkdir -p storage/{backups,cache,logs,sessions,uploads}
chmod -R a+rwX storage

# Install backend/frontend dependencies.
composer install --no-interaction --prefer-dist
if [[ -f package-lock.json ]]; then
    npm ci --no-audit --no-fund
else
    npm install --no-audit --no-fund
fi

# Populate assets/vendor from node_modules (needed by gulp workflows).
npx gulp vendor

# Install managed git hooks for pre-push CI preflight checks.
bash ./scripts/install-git-hooks.sh

echo "[setup] Worktree setup completed."
