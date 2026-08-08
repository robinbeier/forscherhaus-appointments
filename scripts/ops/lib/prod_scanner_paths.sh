#!/usr/bin/env bash

prod_scanner_path_specs() {
    cat <<'SPECS'
scanner_path|root_env|/.env
scanner_path|root_env_production|/.env.production
scanner_path|root_env_tilde_backup|/.env~
scanner_path|dotgit_config|/.git/config
scanner_path|root_config|/config.php
scanner_path|wp_admin|/wp-admin/
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

prod_scanner_default_host_path_specs() {
    cat <<'SPECS'
wp_admin|/wp-admin/
wp_config|/wp-config.php
wp_login|/wp-login.php
xmlrpc|/xmlrpc.php
SPECS
}

prod_scanner_public_ipv4_address() {
    local route_interface=''
    local address=''

    if command -v ip >/dev/null 2>&1; then
        route_interface="$(ip -4 route show default 2>/dev/null | awk 'NR == 1 {for (i = 1; i <= NF; i++) if ($i == "dev") {print $(i + 1); exit}}')"
        if [[ -n "$route_interface" ]]; then
            address="$(ip -o -4 addr show dev "$route_interface" scope global 2>/dev/null | awk 'NR == 1 {split($4, parts, "/"); print parts[1]}')"
        fi
    fi

    if [[ -z "$address" ]] && command -v hostname >/dev/null 2>&1; then
        address="$(hostname -I 2>/dev/null | awk '{for (i = 1; i <= NF; i++) if ($i ~ /^([0-9]{1,3}\.){3}[0-9]{1,3}$/ && $i !~ /^127\./) {print $i; exit}}')"
    fi

    [[ "$address" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || return 1
    [[ ! "$address" =~ ^127\. ]] || return 1
    printf '%s\n' "$address"
}

prod_scanner_host_context_specs() {
    local address="$1"
    local unmatched_host="$2"

    printf 'http_default|http://%s|hostless|\n' "$address"
    printf 'http_unmatched|http://%s|resolved|%s:80:%s\n' "$unmatched_host" "$unmatched_host" "$address"
    printf 'http_ip_literal|http://%s|normal|\n' "$address"
    printf 'https_default|https://%s|hostless|\n' "$address"
    printf 'https_unmatched|https://%s|resolved|%s:443:%s\n' "$unmatched_host" "$unmatched_host" "$address"
    printf 'https_ip_literal|https://%s|normal|\n' "$address"
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

prod_scanner_host_contexts_check_all() {
    local unmatched_host="${PROD_SCANNER_UNMATCHED_HOST:-rob429-unmatched.invalid}"
    local timeout="${PROD_SCANNER_PATH_HTTP_TIMEOUT:-10}"
    local emit_failures="${PROD_SCANNER_PATH_EMIT_FAILURES:-1}"
    local failures=0
    local address=''
    local context
    local base_url
    local mode
    local resolve_spec
    local label
    local path
    local url
    local tmp_body
    local http_code
    local -a curl_args

    address="$(prod_scanner_public_ipv4_address 2>/dev/null || true)"

    if [[ -z "$address" ]]; then
        printf 'scanner_host.public_interface=context_unavailable\n'
        if [[ "$emit_failures" == "1" ]]; then
            printf 'FAIL scanner_host.public_interface context_unavailable\n' >&2
        fi
        failures=$((failures + 1))
    fi

    while IFS='|' read -r context base_url mode resolve_spec; do
        [[ -n "$context" && -n "$base_url" ]] || continue

        while IFS='|' read -r label path; do
            [[ -n "$label" ]] || continue

            url="${base_url}${path}"
            tmp_body="$(mktemp)"
            curl_args=(-sS --max-time "$timeout" -o "$tmp_body" -w '%{http_code}')
            if [[ "$base_url" == https://* ]]; then
                curl_args+=(--insecure)
            fi
            if [[ "$mode" == "hostless" ]]; then
                curl_args+=(--http1.0 -H 'Host:')
            elif [[ "$mode" == "resolved" ]]; then
                curl_args+=(--resolve "$resolve_spec")
            fi

            if http_code="$(curl "${curl_args[@]}" "$url" 2>/dev/null)"; then
                printf 'scanner_host.%s.%s=%s\n' "$context" "$label" "$http_code"
                if [[ "$http_code" != "403" && "$http_code" != "404" ]]; then
                    if [[ "$emit_failures" == "1" ]]; then
                        printf 'FAIL scanner_host.%s.%s unexpected_http_%s\n' "$context" "$label" "$http_code" >&2
                    fi
                    failures=$((failures + 1))
                fi
            else
                printf 'scanner_host.%s.%s=curl_failed\n' "$context" "$label"
                if [[ "$emit_failures" == "1" ]]; then
                    printf 'FAIL scanner_host.%s.%s probe_failed\n' "$context" "$label" >&2
                fi
                failures=$((failures + 1))
            fi

            rm -f "$tmp_body"
        done < <(prod_scanner_default_host_path_specs)
    done < <(prod_scanner_host_context_specs "$address" "$unmatched_host")

    PROD_SCANNER_HOST_CONTEXT_FAILURES="$failures"
    return 0
}
