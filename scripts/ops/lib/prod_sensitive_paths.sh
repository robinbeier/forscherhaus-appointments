#!/usr/bin/env bash

prod_sensitive_path_specs() {
    cat <<'SPECS'
storage_root|/storage/
storage_sessions|/storage/sessions/
storage_cache|/storage/cache/
storage_logs|/storage/logs/
vendor_root|/vendor/
root_config|/config.php
application_root|/application/
system_root|/system/
SPECS
}

prod_sensitive_paths_check_all() {
    local base_url="${1:-${PROD_SENSITIVE_PATH_BASE_URL:-https://dasforscherhaus-leg.de}}"
    local timeout="${PROD_SENSITIVE_PATH_HTTP_TIMEOUT:-10}"
    local failures=0
    local label
    local path
    local url
    local tmp_body
    local http_code

    base_url="${base_url%/}"

    while IFS='|' read -r label path; do
        [[ -n "$label" ]] || continue

        url="${base_url}${path}"
        tmp_body="$(mktemp)"

        if http_code="$(curl -sS --max-time "$timeout" -o "$tmp_body" -w '%{http_code}' "$url" 2>/dev/null)"; then
            printf 'sensitive_path.%s=%s\n' "$label" "$http_code"
            if [[ "$http_code" =~ ^2[0-9][0-9]$ ]]; then
                printf 'FAIL sensitive_path.%s public_http_%s\n' "$label" "$http_code" >&2
                failures=$((failures + 1))
            fi
        else
            printf 'sensitive_path.%s=curl_failed\n' "$label"
            printf 'FAIL sensitive_path.%s probe_failed\n' "$label" >&2
            failures=$((failures + 1))
        fi

        rm -f "$tmp_body"
    done < <(prod_sensitive_path_specs)

    PROD_SENSITIVE_PATH_FAILURES="$failures"
    return 0
}
