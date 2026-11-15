#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
IMPORT_SCRIPT="${IMPORT_SCRIPT_OVERRIDE:-$ROOT_DIR/scripts/import_prod_backup.sh}"
workspace="$(mktemp -d "${TMPDIR:-/tmp}/fh-import-input-test.XXXXXX")"
workspace="$(realpath "$workspace")"
# The importer deliberately rejects known-hosts files below a group/world
# writable ancestor. Keep the valid fixture under the checked-out worktree;
# the test workspace itself remains under the system temporary directory for
# all other disposable state.
known_hosts_root="$(mktemp -d "$ROOT_DIR/.fh-import-known-hosts.XXXXXX")"
known_hosts_root="$(realpath "$known_hosts_root")"
chmod 0700 "$known_hosts_root"
trap 'rm -rf -- "$workspace" "$known_hosts_root"' EXIT

fail() { printf 'import_prod_backup_input_validation=failed: %s\n' "$*" >&2; exit 1; }

function_body="$(sed -n '/^die() {/,/^ensure_paths() {/p' "$IMPORT_SCRIPT" | sed '$d')"
parse_body="$(sed -n '/^parse_args() {/,/^create_or_select_remote_backup() {/p' "$IMPORT_SCRIPT" | sed '$d')"
create_body="$(sed -n '/^create_or_select_remote_backup() {/,/^download_remote_backup() {/p' "$IMPORT_SCRIPT" | sed '$d')"
[[ -n "$function_body" && -n "$parse_body" && -n "$create_body" ]] || fail 'could not extract validation functions'

ssh_mock="$workspace/ssh"
cat > "$ssh_mock" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
[[ "$*" == *'StrictHostKeyChecking=yes'* ]] || exit 1
[[ "$*" == *'UserKnownHostsFile='* ]] || exit 1
[[ "$*" == *'GlobalKnownHostsFile=/dev/null'* ]] || exit 1
if [[ "$*" == *'backup_easyappointments.sh'* ]]; then
    printf 'producer-called\n' >&2
    exit 1
else
    command_string="${!#}"
    [[ "$command_string" != *'test -d --'* && "$command_string" != *'test -f --'* ]] || exit 1
    fixture_root="${SSH_FIXTURE_ROOT:?}"
    mkdir -p "$fixture_root/20260313T074134Z/db" "$fixture_root/20260313T074134Z/meta"
    printf '%s\n' '{"schema":"production_backup_set_handoff.v1","backup_set_id":"20260313T074134Z","dump_sha256":"0000000000000000000000000000000000000000000000000000000000000000","compressed_size_bytes":1,"uncompressed_size_bytes":1}' > "$fixture_root/last_backup_set.json"
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
            "SSH_KNOWN_HOSTS_FILE=$known_hosts_root/known_hosts" \
            'SSH_OPTIONS=(-o synthetic)' \
            'LOCAL_DB_NAME=easyappointments' \
            'log() { :; }' \
            "$function_body" \
            "$parse_body" \
            "$create_body"
        printf 'export SSH_FIXTURE_ROOT=%q\n' "$workspace/remote"
        printf 'printf synthetic-host-key > %q\n' "$known_hosts_root/known_hosts"
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
run_case valid 'chmod 0666 "$SSH_KNOWN_HOSTS_FILE"; parse_args' 1
run_case valid 'parse_args; printf changed >> "$SSH_KNOWN_HOSTS_FILE"; REMOTE_BACKUP_DIR=/root/backups/easyappointments/20260313T074134Z; create_or_select_remote_backup' 1
run_case valid 'known_hosts_dir="$(dirname "$SSH_KNOWN_HOSTS_FILE")"; ln -s "$SSH_KNOWN_HOSTS_FILE" "$known_hosts_dir/symlink-known-hosts"; SSH_KNOWN_HOSTS_FILE="$known_hosts_dir/symlink-known-hosts"; parse_args' 1

missing_known_hosts_runner="$workspace/missing-known-hosts.sh"
{
    printf '%s\n' '#!/usr/bin/env bash' 'set -euo pipefail'
    printf 'SSH_KNOWN_HOSTS_FILE=%q\n' "$workspace/no-such-known-hosts"
    printf '%s\n' 'REMOTE_BACKUP_ROOT=/root/backups/easyappointments' 'REMOTE_BACKUP_DIR=/root/backups/easyappointments/20260313T074134Z'
    printf '%s\n' 'log() { :; }' "$function_body" "$parse_body" 'parse_args'
} > "$missing_known_hosts_runner"
chmod 0700 "$missing_known_hosts_runner"
set +e
bash "$missing_known_hosts_runner" >/dev/null 2>&1
missing_known_hosts_status=$?
set -e
[[ "$missing_known_hosts_status" -ne 0 ]] || fail 'missing dedicated known-hosts file was accepted'

grep -q 'StrictHostKeyChecking=yes' "$IMPORT_SCRIPT" || fail 'strict host-key verification is not enabled'
grep -q 'UserKnownHostsFile=' "$IMPORT_SCRIPT" || fail 'dedicated known-hosts file is not bound'
grep -q 'GlobalKnownHostsFile=/dev/null' "$IMPORT_SCRIPT" || fail 'implicit global known-hosts file is not disabled'
if grep -q 'StrictHostKeyChecking=accept-new' "$IMPORT_SCRIPT"; then
    fail 'first-use host-key acceptance remains enabled'
fi

printf 'import_prod_backup_input_validation=passed\n'
