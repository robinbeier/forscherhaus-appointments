#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
IMPORT_SCRIPT="${IMPORT_SCRIPT_OVERRIDE:-$ROOT_DIR/scripts/import_prod_backup.sh}"
workspace="$(mktemp -d "${TMPDIR:-/tmp}/fh-import-input-test.XXXXXX")"
trap 'rm -rf -- "$workspace"' EXIT

fail() { printf 'import_prod_backup_input_validation=failed: %s\n' "$*" >&2; exit 1; }

function_body="$(sed -n '/^die() {/,/^ensure_paths() {/p' "$IMPORT_SCRIPT" | sed '$d')"
parse_body="$(sed -n '/^parse_args() {/,/^create_or_select_remote_backup() {/p' "$IMPORT_SCRIPT" | sed '$d')"
create_body="$(sed -n '/^create_or_select_remote_backup() {/,/^download_remote_backup() {/p' "$IMPORT_SCRIPT" | sed '$d')"
[[ -n "$function_body" && -n "$parse_body" && -n "$create_body" ]] || fail 'could not extract validation functions'

ssh_mock="$workspace/ssh"
cat > "$ssh_mock" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
if [[ "$*" == *'backup_easyappointments.sh'* ]]; then
    printf 'producer-called\n' >&2
    exit 1
else
    command_string="${!#}"
    [[ "$command_string" != *'test -d --'* && "$command_string" != *'test -f --'* ]] || exit 1
    fixture_root="${SSH_FIXTURE_ROOT:?}"
    mkdir -p "$fixture_root/20260313T074134Z/db" "$fixture_root/20260313T074134Z/meta"
    : > "$fixture_root/20260313T074134Z/db/easyappointments.sql.gz"
    : > "$fixture_root/20260313T074134Z/meta/backup.env"
    if [[ "${SSH_OUTPUT_MODE:-valid}" == symlink_db ]]; then
        rm -rf "$fixture_root/20260313T074134Z/db"
        ln -s "$fixture_root/20260313T074134Z/meta" "$fixture_root/20260313T074134Z/db"
    fi
    command_string="${command_string//\/root\/backups\/easyappointments/$fixture_root}"
    sh -c "$command_string"
fi
MOCK
chmod 0700 "$ssh_mock"

run_case() {
    local mode="$1" body="$2" expected="$3"
    local runner="$workspace/runner.sh"
    {
        printf '%s\n' '#!/usr/bin/env bash' 'set -euo pipefail'
        printf 'PATH=%q:$PATH\n' "$workspace"
        printf '%s\n' 'PROD_SSH_TARGET=root@synthetic.invalid' \
            'REMOTE_BACKUP_ROOT=/root/backups/easyappointments' \
            'REMOTE_BACKUP_DIR=' \
            'SSH_OPTIONS=(-o synthetic)' \
            'LOCAL_DB_NAME=easyappointments' \
            'log() { :; }' \
            "$function_body" \
            "$parse_body" \
            "$create_body"
        printf 'export SSH_FIXTURE_ROOT=%q\n' "$workspace/remote"
        printf '%s\n' "$body"
    } > "$runner"
    chmod 0700 "$runner"
    set +e
    SSH_OUTPUT_MODE="$mode" bash "$runner" >/dev/null 2>&1
    status=$?
    set -e
    [[ "$status" -eq "$expected" ]] || fail "case $mode expected status $expected, got $status"
}

run_case valid 'parse_args' 1
run_case valid 'parse_args --remote-backup-script /tmp/evil' 1
run_case valid 'parse_args --prod-ssh-target -oProxyCommand=evil' 1
run_case valid 'REMOTE_BACKUP_DIR=/root/backups/easyappointments/20260313T074134Z; parse_args; create_or_select_remote_backup' 0
run_case valid 'REMOTE_BACKUP_DIR=/root/backups/easyappointments/../20260313T074134Z; create_or_select_remote_backup' 1
run_case valid 'REMOTE_BACKUP_DIR="/root/backups/easyappointments/20260313T074134Z;touch"; create_or_select_remote_backup' 1
run_case symlink_db 'REMOTE_BACKUP_DIR=/root/backups/easyappointments/20260313T074134Z; create_or_select_remote_backup' 1

printf 'import_prod_backup_input_validation=passed\n'
