#!/usr/bin/env bash

prod_default_ssh_target() {
    printf '%s\n' "${PROD_SSH_TARGET:-root@booking-server}"
}

prod_apply_macos_path() {
    if [[ "$(uname -s)" == "Darwin" ]]; then
        export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
    fi
}

prod_usage_common() {
    cat <<'USAGE'
Common options:
  --prod-ssh-target TARGET  SSH target. Default: root@booking-server (Tailscale)
  -h, --help                Show help.
USAGE
}

prod_require_cmd() {
    command -v "$1" >/dev/null 2>&1 || {
        printf 'ERROR: missing required command: %s\n' "$1" >&2
        exit 1
    }
}

prod_loopback_http_code() {
    local url="$1"
    local observed
    local status
    local peer
    local header_file=''
    local header_seen=0
    local header_value
    local arg
    local next
    local -a curl_args=()
    shift

    case "$url" in
        http://127.0.0.1/*|https://127.0.0.1/*|http://\[::1\]/*|https://\[::1\]/*)
            ;;
        *)
            printf 'loopback_url_required'
            return 0
            ;;
    esac

    while [[ $# -gt 0 ]]; do
        arg="$1"
        shift
        if [[ "$arg" == '-H' ]]; then
            [[ $# -gt 0 ]] || { printf 'curl_header_invalid'; return 0; }
            next="$1"
            shift
            if [[ "$next" =~ ^[Xx]-[Hh][Ee][Aa][Ll][Tt][Hh]-[Tt][Oo][Kk][Ee][Nn]:[[:space:]]* ]]; then
                if (( header_seen != 0 )); then
                    [[ -z "$header_file" ]] || rm -f "$header_file"
                    printf 'curl_header_invalid'
                    return 0
                fi
                header_value="${next#*:}"
                header_value="${header_value#"${header_value%%[![:space:]]*}"}"
                [[ -n "$header_value" && "$header_value" != *$'\r'* && "$header_value" != *$'\n'* ]] || {
                    printf 'curl_header_invalid'
                    return 0
                }
                header_file="$(mktemp "${TMPDIR:-/tmp}/fh-health-header.XXXXXX")" || {
                    printf 'curl_header_file_failed'
                    return 0
                }
                chmod 0600 "$header_file" || {
                    rm -f "$header_file"
                    printf 'curl_header_file_failed'
                    return 0
                }
                (umask 077; printf 'X-Health-Token: %s\n' "$header_value" > "$header_file") || {
                    rm -f "$header_file"
                    printf 'curl_header_file_failed'
                    return 0
                }
                curl_args+=('-H' "@$header_file")
                header_seen=1
            else
                curl_args+=("$arg" "$next")
            fi
        else
            curl_args+=("$arg")
        fi
    done

    if ! observed="$(
        curl --disable -sS -o /dev/null -w $'%{http_code}\t%{remote_ip}' "${curl_args[@]}" \
            --proxy '' --noproxy '*' "$url" 2>/dev/null
    )"; then
        [[ -z "$header_file" ]] || rm -f "$header_file"
        printf 'curl_failed'
        return 0
    fi
    [[ -z "$header_file" ]] || rm -f "$header_file"

    IFS=$'\t' read -r status peer <<< "$observed"
    if [[ "$status" =~ ^[0-9]{3}$ && ( "$peer" == 127.0.0.1 || "$peer" == ::1 ) ]]; then
        printf '%s' "$status"
        return 0
    fi

    printf 'loopback_peer_mismatch'
}

prod_print_plan() {
    local script_name="$1"
    local target="$2"
    local mode="${3:-read-only}"

    cat <<PLAN
[${script_name}] Plan
  mode       : ${mode}
  ssh target : ${target}
PLAN
}
