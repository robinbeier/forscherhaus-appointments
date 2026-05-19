#!/usr/bin/env bash

prod_default_ssh_target() {
    printf '%s\n' "${PROD_SSH_TARGET:-root@188.245.244.123}"
}

prod_apply_macos_path() {
    if [[ "$(uname -s)" == "Darwin" ]]; then
        export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
    fi
}

prod_usage_common() {
    cat <<'USAGE'
Common options:
  --prod-ssh-target TARGET  SSH target. Default: root@188.245.244.123
  -h, --help                Show help.
USAGE
}

prod_require_cmd() {
    command -v "$1" >/dev/null 2>&1 || {
        printf 'ERROR: missing required command: %s\n' "$1" >&2
        exit 1
    }
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
