#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/ops/lib/prod_common.sh
source "${SCRIPT_DIR}/lib/prod_common.sh"

SSH_OPTIONS=(-o StrictHostKeyChecking=accept-new)
PROD_SSH_TARGET="$(prod_default_ssh_target)"

usage() {
    cat <<'USAGE'
Usage:
  bash scripts/ops/prod_dump_producer_admission.sh [--prod-ssh-target TARGET]

Read-only admission status for the closed production dump producer. This
wrapper has no execute or confirmation mode and does not mutate production.

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
}

main() {
    parse_args "$@"
    prod_require_cmd ssh
    prod_print_plan "prod-dump-producer-admission" "${PROD_SSH_TARGET}" "read-only"
    ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" \
        "/usr/bin/python3 -I -B /usr/local/libexec/fh-release-archive-dump-retention-v1 admission-status"
}

main "$@"
