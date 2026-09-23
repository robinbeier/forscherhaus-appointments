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
    shift

    case "$url" in
        http://127.0.0.1/*|https://127.0.0.1/*|http://\[::1\]/*|https://\[::1\]/*)
            ;;
        *)
            printf 'loopback_url_required'
            return 0
            ;;
    esac

    if ! observed="$(
        curl -sS -o /dev/null -w $'%{http_code}\t%{remote_ip}' "$@" \
            --proxy '' --noproxy '*' "$url" 2>/dev/null
    )"; then
        printf 'curl_failed'
        return 0
    fi

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
