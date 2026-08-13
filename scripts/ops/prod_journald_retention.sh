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
MODE='inspect'
CONFIRM_LIVE_WRITE=''

usage() {
    cat <<'USAGE'
Usage:
  bash scripts/ops/prod_journald_retention.sh [options]

Inspect the fixed ROB-451 journald-retention contract using aggregate output.
The default mode is read-only and never changes configuration or journal data.

Options:
  --apply-config               Atomically install the fixed drop-in and restart journald.
  --vacuum                     Rotate and vacuum only after the fixed drop-in is effective.
  --rollback-config            Remove only the exact managed drop-in and restart journald.
  --confirm-live-write VALUE   Required for a write mode. Exact values:
                                 ROB-451-CONFIG
                                 ROB-451-VACUUM
                                 ROB-451-ROLLBACK

Configuration and one-time vacuum are deliberately separate approvals.
Repository delivery does not execute either operation.

USAGE
    prod_usage_common
}

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --prod-ssh-target)
                [[ $# -ge 2 ]] || { printf 'ERROR: --prod-ssh-target requires a value.\n' >&2; exit 1; }
                PROD_SSH_TARGET="$2"
                shift 2
                ;;
            --apply-config|--vacuum|--rollback-config)
                [[ "$MODE" == 'inspect' ]] || { printf 'ERROR: choose exactly one write mode.\n' >&2; exit 1; }
                MODE="${1#--}"
                MODE="${MODE//-/_}"
                shift
                ;;
            --confirm-live-write)
                [[ $# -ge 2 ]] || { printf 'ERROR: --confirm-live-write requires a value.\n' >&2; exit 1; }
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

    case "$MODE:$CONFIRM_LIVE_WRITE" in
        inspect:) ;;
        apply_config:ROB-451-CONFIG) ;;
        vacuum:ROB-451-VACUUM) ;;
        rollback_config:ROB-451-ROLLBACK) ;;
        inspect:*) printf 'ERROR: --confirm-live-write is valid only with a write mode.\n' >&2; exit 1 ;;
        *) printf 'ERROR: the selected write mode requires its exact ROB-451 confirmation.\n' >&2; exit 1 ;;
    esac
}

run_remote() {
    ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" \
        "/usr/bin/python3 -I -B /usr/local/libexec/fh-journald-retention-v1 '${MODE}'"
}

main() {
    parse_args "$@"
    prod_require_cmd ssh
    if [[ "$MODE" == 'inspect' ]]; then
        prod_print_plan 'prod-journald-retention' "${PROD_SSH_TARGET}" 'read-only'
    else
        prod_print_plan 'prod-journald-retention' "${PROD_SSH_TARGET}" "live-write:${MODE}"
    fi
    run_remote
}

main "$@"
