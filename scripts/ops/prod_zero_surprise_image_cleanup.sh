#!/usr/bin/env bash
set -euo pipefail

if [[ "$(uname -s)" == "Darwin" ]]; then
    export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/ops/lib/prod_common.sh
source "${SCRIPT_DIR}/lib/prod_common.sh"

readonly HELPER="${SCRIPT_DIR}/libexec/zero_surprise_image_cleanup_v1.py"
SSH_OPTIONS=(-o StrictHostKeyChecking=accept-new)
PROD_SSH_TARGET="$(prod_default_ssh_target)"
MODE='dry-run'
CONFIRM_LIVE_WRITE=''
PREPARE_GLOBAL_LOCK=0

usage() {
    cat <<'USAGE'
Usage:
  bash scripts/ops/prod_zero_surprise_image_cleanup.sh [options]

Inspect legacy Zero-Surprise replay images using aggregate, secret-free facts.
Default mode is read-only and never removes an image.

Options:
  --execute                    Remove only the closed, validated candidate set.
  --prepare-global-lock        Create or attach the exact shared production-change lock.
  --confirm-live-write VALUE   Required with either live mode; VALUE must be ROB-458.

Limits:
  - at most 32 exact Zero-Surprise projects and 64 exact images per run;
  - no image/container/system prune and no forced deletion;
  - any ambiguous image, container reference, race, activity, or cap stops fail-closed.

USAGE
    prod_usage_common
}

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
            [[ "$PREPARE_GLOBAL_LOCK" == '0' ]] || {
                printf 'ERROR: --execute and --prepare-global-lock are mutually exclusive.\n' >&2
                exit 1
            }
            MODE='execute'
            shift
            ;;
        --prepare-global-lock)
            [[ "$MODE" == 'dry-run' ]] || {
                printf 'ERROR: --execute and --prepare-global-lock are mutually exclusive.\n' >&2
                exit 1
            }
            PREPARE_GLOBAL_LOCK=1
            MODE='prepare-lock'
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

if [[ "$MODE" != 'dry-run' && "$CONFIRM_LIVE_WRITE" != 'ROB-458' ]]; then
    printf 'ERROR: live modes require --confirm-live-write ROB-458.\n' >&2
    exit 1
fi
if [[ "$MODE" == 'dry-run' && -n "$CONFIRM_LIVE_WRITE" ]]; then
    printf 'ERROR: --confirm-live-write is valid only with --execute.\n' >&2
    exit 1
fi
[[ -r "$HELPER" ]] || {
    printf 'ERROR: ROB-458 runtime is incomplete.\n' >&2
    exit 1
}
LOCAL_PYTHON="$(command -v python3 || true)"
[[ "$LOCAL_PYTHON" == /* && -x "$LOCAL_PYTHON" ]] || {
    printf 'ERROR: local Python 3 validator is unavailable.\n' >&2
    exit 1
}
readonly LOCAL_PYTHON

printf 'ROB-458 zero-surprise image cleanup\n'
printf 'target     : %s\n' "$PROD_SSH_TARGET"
case "$MODE" in
    execute) DISPLAY_MODE='live-write' ;;
    prepare-lock) DISPLAY_MODE='lock-bootstrap' ;;
    *) DISPLAY_MODE='read-only' ;;
esac
printf 'mode       : %s\n' "$DISPLAY_MODE"

set +e
REMOTE_OUTPUT="$(ssh "${SSH_OPTIONS[@]}" "$PROD_SSH_TARGET" "/usr/bin/python3 -I -B - '${MODE}'" < "$HELPER")"
REMOTE_EXIT=$?
set -e

if (( ${#REMOTE_OUTPUT} > 4096 )); then
    printf 'ERROR: ROB-458 response exceeded the fixed bound.\n' >&2
    exit 2
fi

if ! printf '%s\n' "$REMOTE_OUTPUT" | "$LOCAL_PYTHON" -I -B "$HELPER" validate "$MODE" "$REMOTE_EXIT"; then
    printf 'ERROR: ROB-458 response validation failed.\n' >&2
    exit 2
fi

printf '%s\n' "$REMOTE_OUTPUT"
exit "$REMOTE_EXIT"
