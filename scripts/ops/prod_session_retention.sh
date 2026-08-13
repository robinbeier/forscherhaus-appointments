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
EXECUTE=0
CONFIRM_LIVE_WRITE=''

usage() {
    cat <<'USAGE'
Usage:
  bash scripts/ops/prod_session_retention.sh [options]

Inspect the fixed ROB-440 session-retention policy using aggregate output.
Default mode is read-only and never deletes a session file.

Options:
  --execute                    Apply one bounded retention pass.
  --confirm-live-write VALUE   Required with --execute; VALUE must be ROB-440.

Policy:
  - inspect only /var/www/html/easyappointments/storage/sessions;
  - require exact CodeIgniter session names and protected file identity;
  - retain every session newer than 86400 seconds;
  - delete no more than 10000 unlocked sessions in one pass;
  - never print session names or contents.

USAGE
    prod_usage_common
}

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --prod-ssh-target)
                [[ $# -ge 2 ]] || {
                    printf 'ERROR: --prod-ssh-target requires a value.\n' >&2
                    exit 1
                }
                PROD_SSH_TARGET="$2"
                shift 2
                ;;
            --execute)
                EXECUTE=1
                shift
                ;;
            --confirm-live-write)
                [[ $# -ge 2 ]] || {
                    printf 'ERROR: --confirm-live-write requires a value.\n' >&2
                    exit 1
                }
                CONFIRM_LIVE_WRITE="$2"
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

    if (( EXECUTE == 1 )) && [[ "$CONFIRM_LIVE_WRITE" != 'ROB-440' ]]; then
        printf 'ERROR: --execute requires --confirm-live-write ROB-440.\n' >&2
        exit 1
    fi
    if (( EXECUTE == 0 )) && [[ -n "$CONFIRM_LIVE_WRITE" ]]; then
        printf 'ERROR: --confirm-live-write is valid only with --execute.\n' >&2
        exit 1
    fi
}

run_remote() {
    local mode='dry-run'
    if (( EXECUTE == 1 )); then
        mode='execute'
    fi

    ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" \
        "/usr/bin/python3 -I -B /usr/local/libexec/fh-session-retention-v1 '${mode}'"
}

main() {
    parse_args "$@"
    prod_require_cmd ssh
    if (( EXECUTE == 1 )); then
        prod_print_plan "prod-session-retention" "${PROD_SSH_TARGET}" "live-write"
    else
        prod_print_plan "prod-session-retention" "${PROD_SSH_TARGET}" "read-only"
    fi
    run_remote
}

main "$@"
