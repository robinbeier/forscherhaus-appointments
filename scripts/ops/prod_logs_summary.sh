#!/usr/bin/env bash
set -euo pipefail

if [[ "$(uname -s)" == "Darwin" ]]; then
    export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/ops/lib/prod_common.sh
source "${SCRIPT_DIR}/lib/prod_common.sh"
# shellcheck source=scripts/ops/lib/app_log_classification.sh
source "${SCRIPT_DIR}/lib/app_log_classification.sh"

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
    {
        declare -f app_log_known_noise_regex
        declare -f app_log_filter_actionable_file
        declare -f app_log_error_like_regex
        declare -f app_log_extract_error_like_file
        declare -f app_log_count_error_like_file
        cat <<'REMOTE'
set -euo pipefail
APP_ROOT="${APP_ROOT:-/var/www/html/easyappointments}"

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
if [[ -d "$APP_ROOT/storage/logs" ]]; then
    total_count=0
    actionable_count=0
    while IFS= read -r -d '' file; do
        matches="$(app_log_count_error_like_file "$file" || true)"
        total_count=$((total_count + matches))
        tmp_matches="$(mktemp)"
        tmp_actionable="$(mktemp)"
        app_log_extract_error_like_file "$file" "$tmp_matches"
        app_log_filter_actionable_file "$tmp_matches" "$tmp_actionable"
        matches="$(app_log_count_error_like_file "$tmp_actionable" || true)"
        actionable_count=$((actionable_count + matches))
        rm -f "$tmp_matches" "$tmp_actionable"
    done < <(find "$APP_ROOT/storage/logs" -maxdepth 1 -type f -mtime -1 -print0)
    ignored_count=$((total_count - actionable_count))
    printf 'app_error_like_lines_24h=%s\n' "$actionable_count"
    printf 'app_error_like_lines_24h_total=%s\n' "$total_count"
    printf 'app_error_like_lines_24h_ignored_known_noise=%s\n' "$ignored_count"
    if (( actionable_count > 0 )); then
        tmp_matches="$(mktemp)"
        tmp_actionable="$(mktemp)"
        while IFS= read -r -d '' file; do
            tmp_file_matches="$(mktemp)"
            app_log_extract_error_like_file "$file" "$tmp_file_matches"
            cat "$tmp_file_matches" >> "$tmp_matches"
            rm -f "$tmp_file_matches"
        done < <(find "$APP_ROOT/storage/logs" -maxdepth 1 -type f -mtime -1 -print0)
        app_log_filter_actionable_file "$tmp_matches" "$tmp_actionable"
        tail -n 20 "$tmp_actionable" \
            | redact || true
        rm -f "$tmp_matches" "$tmp_actionable"
    fi
else
    printf 'app_logs=missing\n'
fi
REMOTE
    } | ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" "SINCE='${SINCE}' bash -s"
}

main() {
    parse_args "$@"
    prod_require_cmd ssh
    prod_print_plan "prod-logs-summary" "${PROD_SSH_TARGET}" "read-only"
    printf '  since      : %s\n' "${SINCE}"
    run_remote
}

main "$@"
