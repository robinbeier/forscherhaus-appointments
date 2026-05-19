#!/usr/bin/env bash
set -euo pipefail

if [[ "$(uname -s)" == "Darwin" ]]; then
    export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/ops/lib/prod_common.sh
source "${SCRIPT_DIR}/lib/prod_common.sh"

SSH_OPTIONS=(-o StrictHostKeyChecking=accept-new)
PROD_SSH_TARGET="$(prod_default_ssh_target)"
REMOTE_PATH="/etc/fh/AGENT_README.md"
EXECUTE=0

usage() {
    cat <<'USAGE'
Usage:
  bash scripts/ops/install_prod_agent_readme.sh [options]

Install the server-local Codex orientation file. Default mode is dry-run.

Options:
  --execute  Write /etc/fh/AGENT_README.md on the production server.
USAGE
    prod_usage_common
}

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --execute)
                EXECUTE=1
                shift
                ;;
            --prod-ssh-target)
                PROD_SSH_TARGET="$2"
                shift 2
                ;;
            -h|--help)
                usage
                exit 0
                ;;
            *)
                printf 'ERROR: unknown option: %s\n' "$1" >&2
                exit 1
                ;;
        esac
    done
}

print_readme() {
    cat <<'README'
# Codex Production Orientation

This host runs the production `forscherhaus-appointments` application.

Canonical repo documentation:

- `AGENTS.md`
- `docs/ops/agent-operations.md`
- `docs/observability.md`
- `docs/uptime-kuma.md`
- `docs/deployment.md`

Default rule for agents:

1. Start read-only from the local repo.
2. Use `scripts/ops/prod_doctor.sh` for current status.
3. Use `scripts/ops/prod_logs_summary.sh` for redacted logs.
4. After any server change, run `scripts/ops/prod_validate_after_change.sh`.

Production map:

- App URL: `https://dasforscherhaus-leg.de/`
- Monitor URL: `https://monitor.dasforscherhaus-leg.de/`
- Active app path: `/var/www/html/easyappointments`
- Release archive path: `/root/releases`
- Core services: `apache2`, `php8.5-fpm`, `mariadb`, `docker`, `fail2ban`, `cron`, `unattended-upgrades`, `fh-pdf-renderer`
- Uptime Kuma data path: `/var/lib/uptime-kuma-data`
- PDF renderer health: `http://127.0.0.1:3003/healthz`
- Kuma local port: `127.0.0.1:3001`
- Host Node/npm: intentionally absent while artifact deploy remains in use

Log paths:

- Apache: `/var/log/apache2`
- App: `/var/www/html/easyappointments/storage/logs`
- Journals: `php8.5-fpm`, `mariadb`, `fh-pdf-renderer`, `docker`, `cron`

Secret-bearing paths. Do not print contents:

- `/etc/fh`
- `/var/www/html/easyappointments/application/config/config.php`
- `/root/backups/uptime-kuma-push.env`
- `/var/lib/uptime-kuma-data/kuma.db`

Stop and ask before deleting backup artifacts, release archives, Kuma data, DB
dumps, or provider snapshot evidence.
README
}

run_remote_install() {
    print_readme | ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" "
set -euo pipefail
REMOTE_PATH='${REMOTE_PATH}'
tmp=\"\$(mktemp)\"
cat > \"\$tmp\"
install -d -m 0700 -o root -g root \"\$(dirname \"\$REMOTE_PATH\")\"
install -m 0640 -o root -g root \"\$tmp\" \"\$REMOTE_PATH\"
rm -f \"\$tmp\"
stat -c 'installed=%n owner=%U:%G mode=%a size=%s' \"\$REMOTE_PATH\"
"
}

main() {
    parse_args "$@"
    prod_require_cmd ssh
    prod_print_plan "install-prod-agent-readme" "${PROD_SSH_TARGET}" "$([[ "${EXECUTE}" == "1" ]] && printf write || printf dry-run)"
    printf '  remote path: %s\n' "${REMOTE_PATH}"

    if [[ "${EXECUTE}" != "1" ]]; then
        printf '\n[dry-run]\n'
        printf 'Would install the following secret-free orientation file on %s:\n\n' "${REMOTE_PATH}"
        print_readme
        exit 0
    fi

    run_remote_install
}

main "$@"
