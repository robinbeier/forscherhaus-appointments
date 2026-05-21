#!/usr/bin/env bash

prod_posture_header_specs() {
    cat <<'SPECS'
app_https|https://dasforscherhaus-leg.de/
www_https|https://www.dasforscherhaus-leg.de/
monitor_https|https://monitor.dasforscherhaus-leg.de/
SPECS
}

prod_posture_header_names() {
    cat <<'HEADERS'
hsts|strict-transport-security
csp|content-security-policy
x_frame_options|x-frame-options
referrer_policy|referrer-policy
permissions_policy|permissions-policy
x_content_type_options|x-content-type-options
HEADERS
}

prod_posture_check_headers() {
    local timeout="${PROD_POSTURE_HTTP_TIMEOUT:-10}"
    local label
    local url
    local tmp_headers
    local header_key
    local header_name

    while IFS='|' read -r label url; do
        [[ -n "$label" ]] || continue

        tmp_headers="$(mktemp)"
        if curl -sS --max-time "$timeout" -D "$tmp_headers" -o /dev/null "$url" >/dev/null 2>&1; then
            while IFS='|' read -r header_key header_name; do
                [[ -n "$header_key" ]] || continue
                if grep -Eiq "^${header_name}:" "$tmp_headers"; then
                    printf 'posture_header.%s.%s=present\n' "$label" "$header_key"
                else
                    printf 'posture_header.%s.%s=missing\n' "$label" "$header_key"
                fi
            done < <(prod_posture_header_names)
        else
            printf 'posture_header.%s.probe=curl_failed\n' "$label"
        fi
        rm -f "$tmp_headers"
    done < <(prod_posture_header_specs)
}

prod_posture_check_ssh() {
    local sshd_output
    local key
    local value
    local expected_key
    local found

    if ! command -v sshd >/dev/null 2>&1; then
        printf 'posture_ssh.status=sshd_missing\n'
        return
    fi

    if ! sshd_output="$(sshd -T 2>/dev/null)"; then
        printf 'posture_ssh.status=sshd_t_failed\n'
        return
    fi

    for expected_key in permitrootlogin pubkeyauthentication passwordauthentication x11forwarding allowtcpforwarding; do
        found=0
        while read -r key value _rest; do
            if [[ "$key" == "$expected_key" ]]; then
                printf 'posture_ssh.%s=%s\n' "$key" "$value"
                found=1
                break
            fi
        done <<<"$sshd_output"

        if [[ "$found" == "0" ]]; then
            printf 'posture_ssh.%s=missing\n' "$expected_key"
        fi
    done
}

prod_posture_ufw_status() {
    local status

    if ! command -v ufw >/dev/null 2>&1; then
        printf 'missing'
        return
    fi

    status="$(ufw status 2>/dev/null | awk 'NR == 1 {print tolower($2)}' || true)"
    if [[ -n "$status" ]]; then
        printf '%s' "$status"
    else
        printf 'unknown'
    fi
}

prod_posture_listen_class() {
    local port="$1"
    local lines
    local line
    local local_addr
    local public=0
    local loopback=0
    local wildcard=0

    lines="$(ss -H -ltn "sport = :${port}" 2>/dev/null || true)"
    if [[ -z "$lines" ]]; then
        printf 'not_listening'
        return
    fi

    while IFS= read -r line; do
        [[ -n "$line" ]] || continue
        local_addr="$(awk '{print $4}' <<<"$line")"
        case "$local_addr" in
            127.*:*|localhost:*|[[]::1[]]:*|[[]::1%*[]]:*)
                loopback=1
                ;;
            0.0.0.0:*|[[]::*[]]:*|\*:*)
                wildcard=1
                ;;
            *)
                public=1
                ;;
        esac
    done <<<"$lines"

    if (( wildcard == 1 )); then
        printf 'wildcard'
    elif (( public == 1 )); then
        printf 'public'
    elif (( loopback == 1 )); then
        printf 'loopback'
    else
        printf 'unknown'
    fi
}

prod_posture_ss_listening_ports() {
    ss -H -ltn 2>/dev/null \
        | awk '{addr=$4; if (addr ~ /\]:[0-9]+$/) {sub(/^.*\]:/, "", addr)} else {sub(/^.*:/, "", addr)} print addr}' \
        | grep -E '^[0-9]+$' \
        | sort -n \
        | uniq
}

prod_posture_check_firewall_and_ports() {
    local port
    local class
    local expected_public=0
    local unexpected_public=0

    printf 'posture_ufw.status=%s\n' "$(prod_posture_ufw_status)"

    for port in 22 80 443 3001 3003 3306; do
        printf 'posture_tcp.%s.listen_class=%s\n' "$port" "$(prod_posture_listen_class "$port")"
    done

    while read -r port; do
        [[ -n "$port" ]] || continue
        class="$(prod_posture_listen_class "$port")"
        case "${port}:${class}" in
            22:wildcard|22:public|80:wildcard|80:public|443:wildcard|443:public)
                expected_public=$((expected_public + 1))
                ;;
            *:wildcard|*:public)
                unexpected_public=$((unexpected_public + 1))
                ;;
        esac
    done < <(prod_posture_ss_listening_ports)

    printf 'posture_tcp.expected_public_listener_classes=%s\n' "$expected_public"
    printf 'posture_tcp.unexpected_public_listener_count=%s\n' "$unexpected_public"
}
