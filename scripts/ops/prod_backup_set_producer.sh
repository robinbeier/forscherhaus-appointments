#!/usr/bin/env bash
set -euo pipefail

if [[ "$(uname -s)" == 'Darwin' ]]; then
    export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/ops/lib/prod_common.sh
source "${SCRIPT_DIR}/lib/prod_common.sh"

SSH_OPTIONS=(-o StrictHostKeyChecking=accept-new)
PROD_SSH_TARGET="$(prod_default_ssh_target)"
EXECUTE=0
CONFIRM_LIVE_WRITE=''
CONFIRM_LIVE_RESTORE=''

usage() {
    cat <<'USAGE'
Usage: bash scripts/ops/prod_backup_set_producer.sh [options]

Plan one closed ROB-480 production backup-and-restore continuity attempt. No
remote command runs by default. The two separately locked stages are bound by
the canonical pending state. A live attempt requires --execute plus both the
ROB-466 write and ROB-461 restore confirmations.

Options:
  --execute
  --confirm-live-write VALUE
  --confirm-live-restore VALUE
USAGE
    prod_usage_common
}

while (( $# > 0 )); do
    case "$1" in
        --prod-ssh-target) [[ $# -ge 2 ]] || exit 1; PROD_SSH_TARGET="$2"; shift 2 ;;
        --execute) EXECUTE=1; shift ;;
        --confirm-live-write) [[ $# -ge 2 ]] || exit 1; CONFIRM_LIVE_WRITE="$2"; shift 2 ;;
        --confirm-live-restore) [[ $# -ge 2 ]] || exit 1; CONFIRM_LIVE_RESTORE="$2"; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) printf 'ERROR: unknown option: %s\n' "$1" >&2; exit 1 ;;
    esac
done

if (( EXECUTE == 0 )); then
    [[ -z "$CONFIRM_LIVE_WRITE" && -z "$CONFIRM_LIVE_RESTORE" ]] || {
        printf 'ERROR: confirmations are valid only with --execute.\n' >&2
        exit 1
    }
    prod_print_plan 'prod-backup-set-producer' "$PROD_SSH_TARGET" 'plan-only'
    exit 0
fi
[[ "$CONFIRM_LIVE_WRITE" == 'ROB-466' ]] || {
    printf 'ERROR: --execute requires --confirm-live-write ROB-466.\n' >&2
    exit 1
}
[[ "$CONFIRM_LIVE_RESTORE" == 'ROB-461' ]] || {
    printf 'ERROR: --execute requires --confirm-live-restore ROB-461.\n' >&2
    exit 1
}
prod_require_cmd ssh
prod_print_plan 'prod-backup-set-producer' "$PROD_SSH_TARGET" 'live-write'
ssh "${SSH_OPTIONS[@]}" "$PROD_SSH_TARGET" \
    '/usr/bin/python3 -I -B /usr/local/libexec/fh-backup-set-producer-v1 && /usr/bin/php -n /usr/local/libexec/fh/verify_deployment_dump_v1.php --continuity-state'
