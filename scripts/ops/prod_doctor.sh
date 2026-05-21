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

usage() {
    cat <<'USAGE'
Usage:
  bash scripts/ops/prod_doctor.sh [options]

Print a concise read-only, redacted production status snapshot.

USAGE
    prod_usage_common
}

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
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

run_remote() {
    ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" 'bash -s' <<'REMOTE'
set -euo pipefail

section() {
    printf '\n[%s]\n' "$1"
}

kv() {
    printf '%s=%s\n' "$1" "$2"
}

http_code() {
    local url="$1"
    shift
    curl -sS -o /dev/null -w '%{http_code}' "$@" "$url" 2>/dev/null || printf 'curl_failed'
}

warning_count() {
    local unit="$1"
    journalctl -u "$unit" --since '60 min ago' -p warning..alert --no-pager 2>/dev/null \
        | grep -vc '^-- No entries --' || true
}

app_error_count() {
    local count=0
    local file
    local matches
    local error_like_regex='^(ERROR|CRITICAL)[[:space:]-]|^(Fatal error|Uncaught)|^PHP (Fatal error|Parse error|Recoverable fatal error)'
    if [[ ! -d /var/www/html/easyappointments/storage/logs ]]; then
        printf 'missing'
        return
    fi
    while IFS= read -r -d '' file; do
        matches="$(grep -Eh "$error_like_regex" "$file" 2>/dev/null | wc -l | awk '{print $1}' || true)"
        count=$((count + matches))
    done < <(find /var/www/html/easyappointments/storage/logs -maxdepth 1 -type f -mtime -1 -print0)
    printf '%s' "$count"
}

section identity
kv captured_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
kv hostname "$(hostname -f 2>/dev/null || hostname)"
if [[ -r /etc/os-release ]]; then
    . /etc/os-release
    kv os "${PRETTY_NAME:-unknown}"
    kv os_version_id "${VERSION_ID:-unknown}"
fi
kv kernel "$(uname -r)"

section endpoints
health_token=''
if [[ -r /etc/fh/healthz.token ]]; then
    health_token="$(tr -d '\r\n' </etc/fh/healthz.token)"
fi
kv app_https "$(http_code https://dasforscherhaus-leg.de/)"
kv www_https "$(http_code https://www.dasforscherhaus-leg.de/)"
kv monitor_https "$(http_code https://monitor.dasforscherhaus-leg.de/)"
kv renderer_http "$(http_code http://127.0.0.1:3003/healthz)"
if [[ -n "$health_token" ]]; then
    kv deep_health_http "$(http_code http://localhost/index.php/healthz -H "X-Health-Token: ${health_token}")"
else
    kv deep_health_http health_token_missing
fi

section sensitive_paths
if [[ -r /var/www/html/easyappointments/scripts/ops/lib/prod_sensitive_paths.sh ]]; then
    # shellcheck source=scripts/ops/lib/prod_sensitive_paths.sh
    source /var/www/html/easyappointments/scripts/ops/lib/prod_sensitive_paths.sh
    prod_sensitive_paths_check_all "https://dasforscherhaus-leg.de"
    kv sensitive_path_failures "${PROD_SENSITIVE_PATH_FAILURES:-0}"
else
    kv sensitive_path_check helper_missing
fi

section services
for service in apache2 php8.5-fpm mariadb docker fail2ban cron unattended-upgrades fh-pdf-renderer; do
    kv "service.${service}" "$(systemctl is-active "$service" 2>/dev/null || true)"
done

section containers
if command -v docker >/dev/null 2>&1; then
    docker ps --format 'container={{.Names}} status={{.Status}}' 2>/dev/null || true
else
    kv docker_command missing
fi

section resources
kv load_1m "$(awk '{print $1}' /proc/loadavg)"
kv mem_available_mib "$(awk '/MemAvailable/ {printf "%d", $2 / 1024}' /proc/meminfo)"
kv swap_total_mib "$(awk '/SwapTotal/ {printf "%d", $2 / 1024}' /proc/meminfo)"
kv root_used_pct "$(df -P / | awk 'NR == 2 {print $5}')"
kv root_avail_gib "$(df -BG / | awk 'NR == 2 {gsub(/G/, "", $4); print $4}')"

section runtime
kv apache "$(apache2 -v 2>/dev/null | awk -F': ' '/Server version/ {print $2; exit}' || true)"
kv php "$(php -r 'echo PHP_VERSION;' 2>/dev/null || true)"
kv mariadb "$(mariadb --version 2>/dev/null | awk '{print $1 " " $5}' | sed 's/,$//' || true)"
kv docker "$(docker --version 2>/dev/null | sed 's/,.*//' || true)"
kv compose "$(docker compose version 2>/dev/null || true)"
kv certbot "$(certbot --version 2>/dev/null | awk '{print $2}' || true)"
if command -v node >/dev/null 2>&1; then kv node "$(node --version)"; else kv node absent; fi
if command -v npm >/dev/null 2>&1; then kv npm "$(npm --version)"; else kv npm absent; fi

section database
if command -v mariadb >/dev/null 2>&1; then
    mariadb --protocol=socket easyappointments -N -e "
SELECT 'db.tables', COUNT(*) FROM information_schema.tables WHERE table_schema = 'easyappointments';
SELECT 'db.settings', COUNT(*) FROM ea_settings;
SELECT 'db.users', COUNT(*) FROM ea_users;
SELECT 'db.appointments', COUNT(*) FROM ea_appointments;
SELECT 'db.migration_version', MAX(version) FROM ea_migrations;
" 2>/dev/null | awk '{print $1 "=" $2}' || kv db.status query_failed
else
    kv db.status mariadb_missing
fi

section kuma
if [[ -r /var/lib/uptime-kuma-data/kuma.db ]] && command -v sqlite3 >/dev/null 2>&1; then
    sqlite3 /var/lib/uptime-kuma-data/kuma.db "
SELECT 'kuma.active_monitors=' || COUNT(*) FROM monitor WHERE active = 1;
SELECT 'kuma.green_latest=' || SUM(CASE WHEN latest_status = 1 THEN 1 ELSE 0 END)
FROM (
  SELECT m.id, COALESCE((SELECT h.status FROM heartbeat h WHERE h.monitor_id = m.id ORDER BY h.time DESC LIMIT 1), -1) latest_status
  FROM monitor m
  WHERE m.active = 1
);
" 2>/dev/null || kv kuma.status query_failed
else
    kv kuma.status unavailable
fi

section certbot
certbot certificates 2>/dev/null | awk '
    /Certificate Name:/ {name=$3}
    /Domains:/ {domains=$0; sub(/^ +Domains: /, "", domains)}
    /Expiry Date:/ {expiry=$0; sub(/^ +Expiry Date: /, "", expiry); printf "cert.%s.domains=%s\ncert.%s.expiry=%s\n", name, domains, name, expiry}
' || true
systemctl list-timers --all --no-pager 2>/dev/null | awk '/certbot|snap.certbot/ {print "timer.certbot=" $0}' || true

section logs
for unit in apache2 php8.5-fpm mariadb fh-pdf-renderer docker cron; do
    kv "warnings_60m.${unit}" "$(warning_count "$unit")"
done
kv app_error_like_lines_24h "$(app_error_count)"
REMOTE
}

main() {
    parse_args "$@"
    prod_require_cmd ssh
    prod_print_plan "prod-doctor" "${PROD_SSH_TARGET}" "read-only"
    run_remote
}

main "$@"
