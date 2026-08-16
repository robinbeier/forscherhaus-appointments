#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/ops/lib/prod_common.sh
source "${SCRIPT_DIR}/lib/prod_common.sh"

SSH_OPTIONS=(-o StrictHostKeyChecking=accept-new)
PROD_SSH_TARGET="$(prod_default_ssh_target)"
MODE='inspect'
CONFIRMED=0

usage() {
    cat <<'USAGE'
Usage:
  bash scripts/ops/prod_legacy_release_provenance.sh [options]

Inspect the host-local legacy release provenance state. Execute mode requires
the exact explicit live-write confirmation token.

Options:
  --execute                 Request the bounded host-local execute operation.
  --confirm-live-write ROB-468
                            Required together with --execute.
USAGE
    prod_usage_common
}

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --prod-ssh-target)
                [[ $# -ge 2 && -n "$2" && "$2" != -* ]] || {
                    printf 'ERROR: --prod-ssh-target requires TARGET\n' >&2
                    exit 1
                }
                PROD_SSH_TARGET="$2"
                shift 2
                ;;
            --execute)
                [[ "$MODE" == 'inspect' ]] || {
                    printf 'ERROR: duplicate mode option\n' >&2
                    exit 1
                }
                MODE='execute'
                shift
                ;;
            --confirm-live-write)
                [[ $# -ge 2 && "$2" == 'ROB-468' ]] || {
                    printf 'ERROR: exact confirmation token ROB-468 is required\n' >&2
                    exit 1
                }
                [[ "$CONFIRMED" == 0 ]] || {
                    printf 'ERROR: duplicate confirmation option\n' >&2
                    exit 1
                }
                CONFIRMED=1
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

    if [[ "$MODE" == 'execute' && "$CONFIRMED" != 1 ]]; then
        printf 'ERROR: --execute requires --confirm-live-write ROB-468\n' >&2
        exit 1
    fi
    if [[ "$MODE" == 'inspect' && "$CONFIRMED" == 1 ]]; then
        printf 'ERROR: --confirm-live-write requires --execute\n' >&2
        exit 1
    fi
}

run_remote() {
    local output=''
    local status=0
    local result_pattern='^\{"mode":"(inspect|execute)","mutation_counts":\{"sidecars_published":[0-9]+,"temp_files_created":[0-9]+,"temp_files_removed":[0-9]+\},"mutation_outcome":"(none|known|unknown)",("reason":"(archive_invalid|authorization_invalid|host_contract_invalid|internal_error|lock_busy|metadata_invalid|publication_blocked)",)?"schema":"legacy_release_provenance_result\.v1","status":"(blocked|busy|pass)","targets":\{"attached":[0-9]+,"pending":[0-9]+,"preflighted":[0-9]+,"published":[0-9]+\}\}$'

    set +e
    if [[ "$MODE" == 'execute' ]]; then
        output="$(ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" \
            /usr/bin/python3 -I -B /usr/local/libexec/fh-legacy-release-provenance-v1 \
            execute ROB-468 2>/dev/null)"
    else
        output="$(ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" \
            /usr/bin/python3 -I -B /usr/local/libexec/fh-legacy-release-provenance-v1 \
            inspect 2>/dev/null)"
    fi
    status=$?
    set -e

    if [[ ${#output} -le 2048 && "$output" == "{\"mode\":\"${MODE}\","* && "$output" =~ $result_pattern ]]; then
        printf '%s\n' "$output"
        return "$status"
    fi

    if [[ "$MODE" == 'execute' ]]; then
        printf '%s\n' '{"mode":"execute","mutation_counts":{"sidecars_published":0,"temp_files_created":0,"temp_files_removed":0},"mutation_outcome":"unknown","reason":"transport_result_unavailable","schema":"legacy_release_provenance_result.v1","status":"blocked","targets":{"attached":0,"pending":0,"preflighted":0,"published":0}}'
    else
        printf '%s\n' '{"mode":"inspect","mutation_counts":{"sidecars_published":0,"temp_files_created":0,"temp_files_removed":0},"mutation_outcome":"none","reason":"transport_result_unavailable","schema":"legacy_release_provenance_result.v1","status":"blocked","targets":{"attached":0,"pending":0,"preflighted":0,"published":0}}'
    fi
    return 70
}

parse_args "$@"
prod_require_cmd ssh
prod_print_plan 'prod_legacy_release_provenance.sh' "${PROD_SSH_TARGET}" \
    "$([[ "$MODE" == 'inspect' ]] && printf 'read-only' || printf '%s' "$MODE")"
run_remote
