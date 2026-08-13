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
  bash scripts/ops/prod_build_cache_retention.sh [options]

Inspect Docker build-cache retention using aggregate, secret-free facts.
Default mode is read-only and never prunes anything.

Options:
  --execute                    Apply the fixed ROB-450 retention policy.
  --confirm-live-write VALUE   Required with --execute; VALUE must be ROB-450.

Policy:
  - prune only Docker build cache older than 168 hours;
  - reserve at least 2147483648 bytes (2 GiB) of cache;
  - never use image, container, volume, network, builder --all, or system prune.

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

    if (( EXECUTE == 1 )) && [[ "$CONFIRM_LIVE_WRITE" != 'ROB-450' ]]; then
        printf 'ERROR: --execute requires --confirm-live-write ROB-450.\n' >&2
        exit 1
    fi

    if (( EXECUTE == 0 )) && [[ -n "$CONFIRM_LIVE_WRITE" ]]; then
        printf 'ERROR: --confirm-live-write is valid only with --execute.\n' >&2
        exit 1
    fi
}

run_remote() {
    local remote_mode='dry-run'
    if (( EXECUTE == 1 )); then
        remote_mode='execute'
    fi

    cat <<'REMOTE' | ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" \
        "BUILD_CACHE_RETENTION_MODE='${remote_mode}' bash -s"
set -euo pipefail
export LC_ALL=C

MODE="${BUILD_CACHE_RETENTION_MODE:-dry-run}"
MIN_AGE_HOURS=168
KEEP_STORAGE_BYTES=2147483648
LOCK_DIR="${BUILD_CACHE_RETENTION_LOCK_DIR:-/var/lib/fh-build-cache-retention}"
GLOBAL_LOCK_PATH="${BUILD_CACHE_RETENTION_GLOBAL_LOCK_PATH:-/var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock}"
PYTHON_BIN="${BUILD_CACHE_RETENTION_PYTHON_BIN:-/usr/bin/python3}"
DELETION_PERFORMED=no

section() {
    printf '\n[%s]\n' "$1"
}

kv() {
    printf '%s=%s\n' "$1" "$2"
}

blocked() {
    local reason="$1"
    local code="${2:-2}"

    section result
    kv status blocked
    kv reason "$reason"
    kv deletion_performed "$DELETION_PERFORMED"
    exit "$code"
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || blocked "missing_required_command" 2
}

for required in awk dirname docker flock grep mkdir sed sha256sum sort stat timeout tr wc; do
    require_command "$required"
done

[[ "$MODE" == 'dry-run' || "$MODE" == 'execute' ]] || blocked invalid_mode 2
[[ -x "$PYTHON_BIN" ]] || blocked missing_python 2

activity_count() {
    "$PYTHON_BIN" -I -B - <<'PY'
import os
import re

proc_root = os.environ.get('BUILD_CACHE_RETENTION_PROC_ROOT', '/proc')
patterns = (
    re.compile(r'(^|\s)docker(?:-compose)?\s+(?:build|builder\s+prune|buildx\s+(?:build|bake|prune))(?:\s|$)'),
    re.compile(r'(^|\s)docker\s+compose\b.*(?:\s--build(?:\s|$)|\s(?:build|run|up)(?:\s|$))'),
    re.compile(r'(^|\s)docker-compose\b.*(?:\s--build(?:\s|$)|\s(?:build|run|up)(?:\s|$))'),
    re.compile(r'(^|/)buildctl(?:\s|$)'),
    re.compile(r'(^|/)(?:deploy_ea\.sh|deployment_host_runner_v1\.php|zero_surprise_replay\.php)(?:\s|$)'),
    re.compile(r'(^|/)(?:prod_(?:customers|provider)_ui_smoke\.sh|traffic_gate_v1\.php)(?:\s|$)'),
    re.compile(r'(^|/)(?:mysqldump|mariadb-dump|backup_easyappointments\.sh|backup_set_producer_v1\.py|fh-backup-set-producer-v1|prod_backup_set_producer\.sh)(?:\s|$)'),
)

count = 0
for entry in os.scandir(proc_root):
    if not entry.name.isdigit() or int(entry.name) == os.getpid():
        continue
    try:
        with open(os.path.join(proc_root, entry.name, 'cmdline'), 'rb') as handle:
            raw = handle.read(131073)
    except (FileNotFoundError, ProcessLookupError):
        continue
    except PermissionError:
        raise SystemExit(2)
    if len(raw) > 131072:
        raise SystemExit(2)
    if not raw:
        continue
    command = raw.replace(b'\0', b' ').decode('utf-8', 'replace').strip()
    if any(pattern.search(command) for pattern in patterns):
        count += 1

print(count)
PY
}

inventory_hash() {
    local kind="$1"
    local output=''

    case "$kind" in
        images)
            output="$(timeout 30 docker image ls --all --quiet --no-trunc 2>/dev/null)" || return 1
            ;;
        containers)
            output="$(timeout 30 docker container ls --all --quiet --no-trunc 2>/dev/null)" || return 1
            ;;
        volumes)
            output="$(timeout 30 docker volume ls --quiet 2>/dev/null)" || return 1
            ;;
        *)
            return 1
            ;;
    esac

    printf '%s\n' "$output" | sed '/^$/d' | LC_ALL=C sort -u | sha256sum | awk '{print $1}'
}

inventory_count() {
    local kind="$1"
    local output=''

    case "$kind" in
        images)
            output="$(timeout 30 docker image ls --all --quiet --no-trunc 2>/dev/null)" || return 1
            ;;
        containers)
            output="$(timeout 30 docker container ls --all --quiet --no-trunc 2>/dev/null)" || return 1
            ;;
        volumes)
            output="$(timeout 30 docker volume ls --quiet 2>/dev/null)" || return 1
            ;;
        *)
            return 1
            ;;
    esac

    printf '%s\n' "$output" | sed '/^$/d' | LC_ALL=C sort -u | wc -l | tr -d ' '
}

measure_cache() {
    local raw=''

    raw="$(timeout 30 docker system df --format '{{json .}}' 2>/dev/null)" || return 1
    (( ${#raw} <= 65536 )) || return 1

    BUILD_CACHE_DF_RAW="$raw" "$PYTHON_BIN" -I -B - <<'PY'
import decimal
import json
import os
import re

payload = os.environ.get('BUILD_CACHE_DF_RAW', '')
records = []
for line in payload.splitlines():
    if not line.strip():
        continue
    try:
        record = json.loads(line)
    except json.JSONDecodeError:
        print('invalid')
        raise SystemExit(0)
    if isinstance(record, dict) and str(record.get('Type', '')).lower() == 'build cache':
        records.append(record)

if len(records) != 1:
    print('invalid')
    raise SystemExit(0)

record = records[0]

def integer(value):
    text = str(value)
    if re.fullmatch(r'[0-9]+', text) is None:
        raise ValueError
    return int(text)

def size(value):
    text = str(value).strip().split(' ', 1)[0]
    match = re.fullmatch(r'([0-9]+(?:\.[0-9]+)?)(B|kB|KB|KiB|MB|MiB|GB|GiB|TB|TiB)', text)
    if match is None:
        raise ValueError
    units = {
        'B': 1,
        'kB': 1000,
        'KB': 1000,
        'KiB': 1024,
        'MB': 1000 ** 2,
        'MiB': 1024 ** 2,
        'GB': 1000 ** 3,
        'GiB': 1024 ** 3,
        'TB': 1000 ** 4,
        'TiB': 1024 ** 4,
    }
    amount = decimal.Decimal(match.group(1)) * units[match.group(2)]
    if amount < 0 or amount > 9223372036854775807:
        raise ValueError
    return int(amount)

try:
    count = integer(record.get('TotalCount'))
    total = size(record.get('Size'))
    reclaimable = size(record.get('Reclaimable'))
except (ValueError, decimal.InvalidOperation):
    print('invalid')
    raise SystemExit(0)

if reclaimable > total:
    print('invalid')
    raise SystemExit(0)

print(f'{count}|{total}|{reclaimable}')
PY
}

prune_space_flag() {
    local help=''

    help="$(timeout 30 docker builder prune --help 2>/dev/null)" || return 1

    grep -Fq -- '--force' <<<"$help" || {
        printf '%s' unsupported
        return 0
    }
    grep -Fq -- '--filter' <<<"$help" || {
        printf '%s' unsupported
        return 0
    }

    if grep -Fq -- '--keep-storage' <<<"$help"; then
        printf '%s' '--keep-storage'
        return
    fi
    if grep -Fq -- '--reserved-space' <<<"$help"; then
        printf '%s' '--reserved-space'
        return
    fi

    printf '%s' unsupported
}

timeout 30 docker info >/dev/null 2>&1 || blocked docker_unavailable 2

space_flag="$(prune_space_flag)" || blocked prune_capability_failed 2
[[ "$space_flag" != 'unsupported' ]] || blocked prune_capability_unsupported 2
activity="$(activity_count)" || blocked activity_unknown 2
[[ "$activity" =~ ^[0-9]+$ ]] || blocked activity_unknown 2
(( activity == 0 )) || blocked active_production_work 75

cache_before="$(measure_cache)" || blocked cache_inventory_failed 2
[[ "$cache_before" != 'invalid' ]] || blocked cache_inventory_invalid 2
IFS='|' read -r cache_count_before cache_total_before cache_reclaimable_before <<<"$cache_before"

images_hash_before="$(inventory_hash images)" || blocked protected_inventory_failed 2
containers_hash_before="$(inventory_hash containers)" || blocked protected_inventory_failed 2
volumes_hash_before="$(inventory_hash volumes)" || blocked protected_inventory_failed 2
images_count_before="$(inventory_count images)" || blocked protected_inventory_failed 2
containers_count_before="$(inventory_count containers)" || blocked protected_inventory_failed 2
volumes_count_before="$(inventory_count volumes)" || blocked protected_inventory_failed 2

section identity
kv schema prod_build_cache_retention.v1
kv captured_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
kv mode "$MODE"

section policy
kv min_age_hours "$MIN_AGE_HOURS"
kv keep_storage_bytes "$KEEP_STORAGE_BYTES"
kv prune_space_flag "${space_flag#--}"
kv broad_prune_allowed no

section preflight
kv activity_state clear
kv activity_match_count "$activity"
kv cleanup_lock "$([[ "$MODE" == 'execute' ]] && printf pending || printf not_acquired)"

section before
kv cache.record_count "$cache_count_before"
kv cache.total_bytes "$cache_total_before"
kv cache.reclaimable_bytes "$cache_reclaimable_before"
kv images.count "$images_count_before"
kv containers.count "$containers_count_before"
kv volumes.count "$volumes_count_before"

if [[ "$MODE" == 'dry-run' ]]; then
    section action
    kv deletion_performed no
    kv cleanup_candidate "$([[ "$cache_reclaimable_before" -gt 0 ]] && printf observe || printf none)"

    section result
    kv status pass
    kv reason none
    exit 0
fi

if [[ ! -e "$LOCK_DIR" && ! -L "$LOCK_DIR" ]]; then
    [[ "$LOCK_DIR" == '/var/lib/fh-build-cache-retention' ]] || blocked cleanup_lock_parent_unsafe 2
    mkdir --mode=0700 -- "$LOCK_DIR" || blocked cleanup_lock_failed 2
fi
[[ -d "$LOCK_DIR" && ! -L "$LOCK_DIR" ]] || blocked cleanup_lock_unsafe 2

lock_path_meta="$(stat -Lc '%F|%a|%u|%d|%i' "$LOCK_DIR" 2>/dev/null)" \
    || blocked cleanup_lock_unsafe 2
[[ "$lock_path_meta" == directory\|700\|0\|* ]] || blocked cleanup_lock_unsafe 2
exec 9<"$LOCK_DIR" || blocked cleanup_lock_failed 2
flock -n 9 || blocked cleanup_lock_busy 75
lock_fd_meta="$(stat -Lc '%F|%a|%u|%d|%i' "/proc/$$/fd/9" 2>/dev/null)" \
    || blocked cleanup_lock_unsafe 2
lock_path_after="$(stat -Lc '%F|%a|%u|%d|%i' "$LOCK_DIR" 2>/dev/null)" \
    || blocked cleanup_lock_unsafe 2
[[ "$lock_fd_meta" == "$lock_path_meta" && "$lock_path_after" == "$lock_path_meta" ]] \
    || blocked cleanup_lock_unsafe 2

global_lock_state=absent
if [[ -e "$GLOBAL_LOCK_PATH" || -L "$GLOBAL_LOCK_PATH" ]]; then
    [[ -f "$GLOBAL_LOCK_PATH" && ! -L "$GLOBAL_LOCK_PATH" ]] || blocked global_change_lock_unsafe 2
    global_lock_meta="$(stat -Lc '%F|%a|%u|%h|%d|%i' "$GLOBAL_LOCK_PATH" 2>/dev/null)" \
        || blocked global_change_lock_unsafe 2
    [[ "$global_lock_meta" == regular\ empty\ file\|600\|0\|1\|* ]] \
        || blocked global_change_lock_unsafe 2
    exec 8>>"$GLOBAL_LOCK_PATH" || blocked global_change_lock_failed 2
    flock -n 8 || blocked active_production_work 75
    global_lock_fd_meta="$(stat -Lc '%F|%a|%u|%h|%d|%i' "/proc/$$/fd/8" 2>/dev/null)" \
        || blocked global_change_lock_unsafe 2
    global_lock_after="$(stat -Lc '%F|%a|%u|%h|%d|%i' "$GLOBAL_LOCK_PATH" 2>/dev/null)" \
        || blocked global_change_lock_unsafe 2
    [[ "$global_lock_fd_meta" == "$global_lock_meta" && "$global_lock_after" == "$global_lock_meta" ]] \
        || blocked global_change_lock_unsafe 2
    global_lock_state=acquired
fi

activity="$(activity_count)" || blocked activity_unknown 2
[[ "$activity" == '0' ]] || blocked active_production_work 75

DELETION_PERFORMED=attempted
if ! timeout 900 docker builder prune \
    --force \
    --filter "until=${MIN_AGE_HOURS}h" \
    "$space_flag" "$KEEP_STORAGE_BYTES" >/dev/null 2>&1; then
    blocked prune_failed 2
fi
DELETION_PERFORMED=yes

cache_after="$(measure_cache)" || blocked cache_inventory_failed 2
[[ "$cache_after" != 'invalid' ]] || blocked cache_inventory_invalid 2
IFS='|' read -r cache_count_after cache_total_after cache_reclaimable_after <<<"$cache_after"

images_hash_after="$(inventory_hash images)" || blocked protected_inventory_failed 2
containers_hash_after="$(inventory_hash containers)" || blocked protected_inventory_failed 2
volumes_hash_after="$(inventory_hash volumes)" || blocked protected_inventory_failed 2
images_count_after="$(inventory_count images)" || blocked protected_inventory_failed 2
containers_count_after="$(inventory_count containers)" || blocked protected_inventory_failed 2
volumes_count_after="$(inventory_count volumes)" || blocked protected_inventory_failed 2

[[ "$images_hash_after" == "$images_hash_before" ]] || blocked protected_inventory_changed 2
[[ "$containers_hash_after" == "$containers_hash_before" ]] || blocked protected_inventory_changed 2
[[ "$volumes_hash_after" == "$volumes_hash_before" ]] || blocked protected_inventory_changed 2

freed_bytes=0
if (( cache_total_before > cache_total_after )); then
    freed_bytes=$((cache_total_before - cache_total_after))
fi

section action
kv cleanup_lock acquired
kv global_change_lock "$global_lock_state"
kv deletion_performed yes
kv prune_exit_code 0

section after
kv cache.record_count "$cache_count_after"
kv cache.total_bytes "$cache_total_after"
kv cache.reclaimable_bytes "$cache_reclaimable_after"
kv cache.freed_bytes "$freed_bytes"
kv images.count "$images_count_after"
kv containers.count "$containers_count_after"
kv volumes.count "$volumes_count_after"
kv protected_inventory_unchanged yes

section result
kv status pass
kv reason none
REMOTE
}

main() {
    parse_args "$@"
    prod_require_cmd ssh
    prod_print_plan \
        "prod-build-cache-retention" \
        "${PROD_SSH_TARGET}" \
        "$([[ "${EXECUTE}" == "1" ]] && printf live-write || printf read-only)"
    run_remote
}

main "$@"
