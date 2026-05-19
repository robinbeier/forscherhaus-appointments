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
SINCE="60 min ago"

usage() {
    cat <<'USAGE'
Usage:
  bash scripts/ops/prod_logs_summary.sh [options]

Print redacted production log counts and short samples.

Options:
  --since VALUE              journalctl/find time window. Default: 60 min ago
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
            --since)
                SINCE="$2"
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

    if [[ "${SINCE}" == *"'"* || "${SINCE}" == *$'\n'* ]]; then
        printf 'ERROR: --since must not contain quotes or newlines\n' >&2
        exit 1
    fi
}

run_remote() {
    ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" "SINCE='${SINCE}' bash -s" <<'REMOTE'
set -euo pipefail

section() {
    printf '\n[%s]\n' "$1"
}

redact() {
    sed -E \
        -e 's#https?://[^[:space:]]+#https://[REDACTED_URL]#g' \
        -e 's#([?&](token|key|secret|password|pass|auth)=)[^[:space:]&]+#\1[REDACTED]#Ig' \
        -e 's#(token|key|secret|password|pass|auth|dsn)=([^[:space:]]+)#\1=[REDACTED]#Ig' \
        -e 's#push/[A-Za-z0-9_-]+#push/[REDACTED]#g' \
        -e 's#(X-Health-Token: )[A-Za-z0-9._:-]+#\1[REDACTED]#Ig'
}

warning_count() {
    local unit="$1"
    journalctl -u "$unit" --since "$SINCE" -p warning..alert --no-pager 2>/dev/null \
        | grep -vc '^-- No entries --' || true
}

section summary
printf 'captured_at=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
printf 'since=%s\n' "$SINCE"
for unit in apache2 php8.5-fpm mariadb fh-pdf-renderer docker cron; do
    printf 'warnings.%s=%s\n' "$unit" "$(warning_count "$unit")"
done

section journal_samples
for unit in apache2 php8.5-fpm mariadb fh-pdf-renderer docker cron; do
    printf -- '-- %s --\n' "$unit"
    journalctl -u "$unit" --since "$SINCE" -p warning..alert --no-pager 2>/dev/null \
        | tail -n 12 \
        | redact || true
done

section app_log_summary
if [[ -d /var/www/html/easyappointments/storage/logs ]]; then
    count=0
    while IFS= read -r -d '' file; do
        matches="$(grep -Eih '(ERROR|CRITICAL|Fatal error|Uncaught)' "$file" 2>/dev/null | wc -l | awk '{print $1}' || true)"
        count=$((count + matches))
    done < <(find /var/www/html/easyappointments/storage/logs -maxdepth 1 -type f -mtime -1 -print0)
    printf 'app_error_like_lines_24h=%s\n' "$count"
    if (( count > 0 )); then
        find /var/www/html/easyappointments/storage/logs -maxdepth 1 -type f -mtime -1 -print0 \
            | xargs -0 grep -Eih '(ERROR|CRITICAL|Fatal error|Uncaught)' 2>/dev/null \
            | tail -n 20 \
            | redact || true
    fi
else
    printf 'app_logs=missing\n'
fi
REMOTE
}

main() {
    parse_args "$@"
    prod_require_cmd ssh
    prod_print_plan "prod-logs-summary" "${PROD_SSH_TARGET}" "read-only"
    printf '  since      : %s\n' "${SINCE}"
    run_remote
}

main "$@"
