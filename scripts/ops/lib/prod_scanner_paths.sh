#!/usr/bin/env bash

prod_scanner_path_specs() {
    cat <<'SPECS'
scanner_path|root_env|/.env
scanner_path|root_env_production|/.env.production
scanner_path|dotgit_config|/.git/config
scanner_path|root_config|/config.php
scanner_path|wp_config|/wp-config.php
scanner_path|wp_login|/wp-login.php
scanner_path|xmlrpc|/xmlrpc.php
scanner_path|phpinfo_root|/phpinfo.php
scanner_path|phpinfo_nested|/administrator/phpinfo.php
scanner_path|vendor_phpunit|/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php
scanner_path|server_status|/server-status
scanner_path|boaform|/boaform/admin/formLogin
scanner_path|hnap1|/HNAP1/
scanner_path|cgi_bin|/cgi-bin/test.cgi
scanner_query|phpinfo_page|/?page=phpinfo
scanner_query|phpinfo_flag|/?phpinfo=1
SPECS
}

prod_scanner_paths_check_all() {
    local base_url="${1:-${PROD_SCANNER_PATH_BASE_URL:-https://dasforscherhaus-leg.de}}"
    local timeout="${PROD_SCANNER_PATH_HTTP_TIMEOUT:-10}"
    local emit_failures="${PROD_SCANNER_PATH_EMIT_FAILURES:-1}"
    local failures=0
    local kind
    local label
    local path
    local url
    local tmp_body
    local http_code

    base_url="${base_url%/}"

    while IFS='|' read -r kind label path; do
        [[ -n "$kind" ]] || continue

        url="${base_url}${path}"
        tmp_body="$(mktemp)"

        if http_code="$(curl -sS --max-time "$timeout" -o "$tmp_body" -w '%{http_code}' "$url" 2>/dev/null)"; then
            printf '%s.%s=%s\n' "$kind" "$label" "$http_code"
            if [[ "$http_code" =~ ^2[0-9][0-9]$ ]]; then
                if [[ "$emit_failures" == "1" ]]; then
                    printf 'FAIL %s.%s public_http_%s\n' "$kind" "$label" "$http_code" >&2
                fi
                failures=$((failures + 1))
            fi
        else
            printf '%s.%s=curl_failed\n' "$kind" "$label"
            if [[ "$emit_failures" == "1" ]]; then
                printf 'FAIL %s.%s probe_failed\n' "$kind" "$label" >&2
            fi
            failures=$((failures + 1))
        fi

        rm -f "$tmp_body"
    done < <(prod_scanner_path_specs)

    PROD_SCANNER_PATH_FAILURES="$failures"
    return 0
}
