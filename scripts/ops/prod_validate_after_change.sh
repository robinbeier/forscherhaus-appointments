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
WITH_CERTBOT_DRY_RUN=0

usage() {
    cat <<'USAGE'
Usage:
  bash scripts/ops/prod_validate_after_change.sh [options]

Run the standard post-change production validation gate.

Options:
  --with-certbot-dry-run  Also run certbot renew dry-run with no random sleep.
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
            --with-certbot-dry-run)
                WITH_CERTBOT_DRY_RUN=1
                shift
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
    ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" "WITH_CERTBOT_DRY_RUN='${WITH_CERTBOT_DRY_RUN}' bash -s" <<'REMOTE'
set -euo pipefail

failures=0

section() {
    printf '\n[%s]\n' "$1"
}

if [[ -r /var/www/html/easyappointments/scripts/ops/lib/app_log_classification.sh ]]; then
    # shellcheck source=scripts/ops/lib/app_log_classification.sh
    source /var/www/html/easyappointments/scripts/ops/lib/app_log_classification.sh
else
    app_log_known_noise_regex() {
        cat <<'REGEX'
ERROR - .*--> 404 Page Not Found: Azenvnet/index|ERROR - .*--> Severity: Warning --> unlink\(.*/storage/cache/rate_limit_key_[^)]*\): No such file or directory .*/system/libraries/Cache/drivers/Cache_file\.php 279
REGEX
    }

    app_log_filter_actionable_file() {
        local input_file="$1"
        local output_file="$2"
        grep -Ev "$(app_log_known_noise_regex)" "$input_file" > "$output_file" || true
    }

    app_log_count_error_like_file() {
        local input_file="$1"
        grep -Eih '(ERROR|CRITICAL|Fatal error|Uncaught)' "$input_file" 2>/dev/null \
            | wc -l \
            | awk '{print $1}'
    }
fi

check_eq() {
    local key="$1"
    local got="$2"
    local expected="$3"
    printf '%s=%s\n' "$key" "$got"
    if [[ "$got" != "$expected" ]]; then
        printf 'FAIL %s expected=%s got=%s\n' "$key" "$expected" "$got" >&2
        failures=$((failures + 1))
    fi
}

check_zero() {
    local key="$1"
    local got="$2"
    printf '%s=%s\n' "$key" "$got"
    if [[ "$got" != "0" ]]; then
        printf 'FAIL %s expected=0 got=%s\n' "$key" "$got" >&2
        failures=$((failures + 1))
    fi
}

http_code() {
    local url="$1"
    shift
    curl -sS -o /dev/null -w '%{http_code}' "$@" "$url" 2>/dev/null || printf 'curl_failed'
}

warning_count() {
    local unit="$1"
    journalctl -u "$unit" --since '10 min ago' -p warning..alert --no-pager 2>/dev/null \
        | grep -vc '^-- No entries --' || true
}

app_error_count() {
    local count=0
    local file
    local matches
    local tmp_matches
    local tmp_actionable
    if [[ ! -d /var/www/html/easyappointments/storage/logs ]]; then
        printf 'missing'
        return
    fi
    while IFS= read -r -d '' file; do
        tmp_matches="$(mktemp)"
        tmp_actionable="$(mktemp)"
        grep -Eih '(ERROR|CRITICAL|Fatal error|Uncaught)' "$file" > "$tmp_matches" 2>/dev/null || true
        app_log_filter_actionable_file "$tmp_matches" "$tmp_actionable"
        matches="$(app_log_count_error_like_file "$tmp_actionable" || true)"
        count=$((count + matches))
        rm -f "$tmp_matches" "$tmp_actionable"
    done < <(find /var/www/html/easyappointments/storage/logs -maxdepth 1 -type f -mtime -1 -print0)
    printf '%s' "$count"
}

section endpoints
health_token=''
if [[ -r /etc/fh/healthz.token ]]; then
    health_token="$(tr -d '\r\n' </etc/fh/healthz.token)"
fi
check_eq app_https "$(http_code https://dasforscherhaus-leg.de/)" 200
check_eq www_https "$(http_code https://www.dasforscherhaus-leg.de/)" 200
check_eq monitor_https "$(http_code https://monitor.dasforscherhaus-leg.de/)" 302
check_eq renderer_http "$(http_code http://127.0.0.1:3003/healthz)" 200
if [[ -n "$health_token" ]]; then
    check_eq deep_health_http "$(http_code http://localhost/index.php/healthz -H "X-Health-Token: ${health_token}")" 200
else
    printf 'FAIL deep_health_http health_token_missing\n' >&2
    failures=$((failures + 1))
fi

section services
for service in apache2 php8.5-fpm mariadb docker fail2ban cron unattended-upgrades fh-pdf-renderer; do
    check_eq "service.${service}" "$(systemctl is-active "$service" 2>/dev/null || true)" active
done

section containers
if docker ps --format '{{.Names}}' 2>/dev/null | grep -qx 'uptime-kuma-uptime-kuma-1'; then
    printf 'container.uptime_kuma=present\n'
else
    printf 'FAIL container.uptime_kuma missing\n' >&2
    failures=$((failures + 1))
fi
if docker ps --format '{{.Names}}' 2>/dev/null | grep -qx 'fh-pdf-renderer-pdf-renderer-1'; then
    printf 'container.pdf_renderer=present\n'
else
    printf 'FAIL container.pdf_renderer missing\n' >&2
    failures=$((failures + 1))
fi

section kuma
if [[ -r /var/lib/uptime-kuma-data/kuma.db ]] && command -v sqlite3 >/dev/null 2>&1; then
    active="$(sqlite3 /var/lib/uptime-kuma-data/kuma.db 'SELECT COUNT(*) FROM monitor WHERE active = 1;' 2>/dev/null || printf query_failed)"
    green="$(sqlite3 /var/lib/uptime-kuma-data/kuma.db "SELECT SUM(CASE WHEN latest_status = 1 THEN 1 ELSE 0 END) FROM (SELECT m.id, COALESCE((SELECT h.status FROM heartbeat h WHERE h.monitor_id = m.id ORDER BY h.time DESC LIMIT 1), -1) latest_status FROM monitor m WHERE m.active = 1);" 2>/dev/null || printf query_failed)"
    printf 'kuma.active_monitors=%s\n' "$active"
    printf 'kuma.green_latest=%s\n' "$green"
    if [[ "$active" != "12" || "$green" != "12" ]]; then
        printf 'FAIL kuma expected 12 active and 12 green\n' >&2
        failures=$((failures + 1))
    fi
else
    printf 'FAIL kuma unavailable\n' >&2
    failures=$((failures + 1))
fi

section resources
root_used_pct="$(df -P / | awk 'NR == 2 {gsub(/%/, "", $5); print $5}')"
mem_available_mib="$(awk '/MemAvailable/ {printf "%d", $2 / 1024}' /proc/meminfo)"
printf 'root_used_pct=%s\n' "$root_used_pct"
printf 'mem_available_mib=%s\n' "$mem_available_mib"
if (( root_used_pct >= 85 )); then
    printf 'FAIL root disk usage >=85%%\n' >&2
    failures=$((failures + 1))
fi
if (( mem_available_mib < 200 )); then
    printf 'FAIL available memory <200MiB\n' >&2
    failures=$((failures + 1))
fi

section certbot
certbot_certificates_output="$(certbot certificates 2>/dev/null || true)"
if [[ -n "$certbot_certificates_output" ]]; then
    awk '/Certificate Name:|Domains:|Expiry Date:/ {gsub(/^ +/, ""); print "certbot." $0}' <<<"$certbot_certificates_output"
else
    printf 'FAIL certbot certificates failed\n' >&2
    failures=$((failures + 1))
fi
if systemctl list-timers --all --no-pager 2>/dev/null | grep -Eq 'certbot|snap.certbot'; then
    printf 'certbot.timer=present\n'
else
    printf 'FAIL certbot.timer missing\n' >&2
    failures=$((failures + 1))
fi
if [[ "$WITH_CERTBOT_DRY_RUN" == "1" ]]; then
    certbot_dry_run_output=''
    if certbot_dry_run_output="$(timeout 180 certbot renew --dry-run --non-interactive --no-random-sleep-on-renew 2>&1)"; then
        printf 'certbot.dry_run=ok\n'
    else
        printf 'FAIL certbot.dry_run failed_or_timed_out\n' >&2
        sed -E 's#https?://[^[:space:]]+#https://[REDACTED_URL]#g' <<<"$certbot_dry_run_output" >&2 || true
        failures=$((failures + 1))
    fi
else
    printf 'certbot.dry_run=skipped\n'
fi

section host_node
if command -v node >/dev/null 2>&1; then
    printf 'FAIL node=present\n' >&2
    failures=$((failures + 1))
else
    printf 'node=absent\n'
fi
if command -v npm >/dev/null 2>&1; then
    printf 'FAIL npm=present\n' >&2
    failures=$((failures + 1))
else
    printf 'npm=absent\n'
fi

section logs
for unit in apache2 php8.5-fpm mariadb fh-pdf-renderer docker cron; do
    check_zero "warnings_10m.${unit}" "$(warning_count "$unit")"
done
check_zero app_error_like_lines_24h "$(app_error_count)"

section result
if (( failures > 0 )); then
    printf 'validation=failed failures=%s\n' "$failures"
    exit 1
fi
printf 'validation=passed\n'
REMOTE
}

main() {
    parse_args "$@"
    prod_require_cmd ssh
    prod_print_plan "prod-validate-after-change" "${PROD_SSH_TARGET}" "read-only"
    printf '  certbot dry-run : %s\n' "$([[ "${WITH_CERTBOT_DRY_RUN}" == "1" ]] && printf yes || printf no)"
    run_remote
}

main "$@"
