#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
IMPORT_SCRIPT="${IMPORT_SCRIPT_OVERRIDE:-$ROOT_DIR/scripts/import_prod_backup.sh}"
workspace="$(mktemp -d "${TMPDIR:-/tmp}/fh-private-download-test.XXXXXX")"
trap 'rm -rf -- "$workspace"' EXIT

fail() {
    printf 'import_prod_backup_private_download=failed: %s\n' "$*" >&2
    exit 1
}

assert_mode() {
    local expected="$1"
    local path="$2"
    local actual
    actual="$(stat -c '%a' "$path" 2>/dev/null || stat -f '%Lp' "$path")"
    [[ "$actual" == "$expected" ]] || fail "$path mode is $actual, expected $expected"
}

function_body="$(sed -n '/^download_remote_backup() {/,/^}$/p' "$IMPORT_SCRIPT")"
[[ -n "$function_body" ]] || fail 'could not extract download_remote_backup()'

fixture_repo="$workspace/repo"
download_root="$workspace/downloads"
mkdir -p "$fixture_repo" "$download_root"

scp_mock="$workspace/scp"
cat > "$scp_mock" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail

destination="${!#}"
mkdir -p "$(dirname -- "$destination")"
if [[ "$destination" == *.sql.gz ]]; then
    printf 'synthetic dump\n' | gzip > "$destination"
else
    printf 'synthetic metadata\n' > "$destination"
fi
# Model an SCP implementation that applies permissive source mode after write.
# The importer must still leave both files private before continuing.
chmod 0644 "$destination"
MOCK
chmod 0700 "$scp_mock"

run_download() {
    local output_file="$1"
    local script="$workspace/run-download.sh"
    {
        printf '%s\n' '#!/usr/bin/env bash' 'set -euo pipefail'
        printf 'PATH=%q:$PATH\n' "$workspace"
        printf 'LOCAL_DOWNLOAD_ROOT=%q\n' "$download_root"
        printf '%s\n' 'LOCAL_DB_NAME=easyappointments' \
            'REMOTE_BACKUP_DIR=/root/backups/easyappointments/known-stamp' \
            'PROD_SSH_TARGET=synthetic@invalid' \
            'SSH_OPTIONS=(-o synthetic)' \
            'log() { :; }' \
            "$function_body" \
            'download_remote_backup' \
            'printf "%s\n" "$LOCAL_IMPORT_DIR"'
    } > "$script"
    chmod 0700 "$script"
    bash "$script" > "$output_file"
}

# A predictable pre-existing path must remain untouched. The current importer
# resolves this exact path from REMOTE_BACKUP_DIR and writes through it.
known_path="$download_root/easyappointments-prod-known-stamp"
mkdir -p "$known_path"
printf 'keep this dump\n' > "$known_path/easyappointments.sql.gz"
printf 'keep this metadata\n' > "$known_path/backup.env"

set +e
first_output="$workspace/first-output"
run_download "$first_output"
first_status=$?
set -e
[[ "$first_status" -eq 0 ]] || fail "download fixture failed unexpectedly (status $first_status)"
first_dir="$(tail -n1 "$first_output")"

[[ "$first_dir" != "$known_path" ]] || fail 'download used the pre-existing predictable directory'
[[ -d "$first_dir" ]] || fail 'download directory was not created'
assert_mode 700 "$first_dir"
assert_mode 600 "$first_dir/easyappointments.sql.gz"
assert_mode 600 "$first_dir/backup.env"
[[ "$(cat "$known_path/easyappointments.sql.gz")" == 'keep this dump' ]] || fail 'pre-existing dump was overwritten'
[[ "$(cat "$known_path/backup.env")" == 'keep this metadata' ]] || fail 'pre-existing metadata was overwritten'

second_output="$workspace/second-output"
run_download "$second_output"
second_dir="$(tail -n1 "$second_output")"
[[ "$second_dir" != "$first_dir" ]] || fail 'download directory was reused'
assert_mode 700 "$second_dir"
assert_mode 600 "$second_dir/easyappointments.sql.gz"
assert_mode 600 "$second_dir/backup.env"

# A planted symlink must not redirect the download into an attacker-selected
# directory. This check is intentionally separate from the known-path check.
symlink_target="$workspace/symlink-target"
mkdir -p "$symlink_target"
symlink_path="$download_root/easyappointments-prod-symlink-stamp"
ln -s "$symlink_target" "$symlink_path"

symlink_script="$workspace/run-symlink-download.sh"
{
    printf '%s\n' '#!/usr/bin/env bash' 'set -euo pipefail'
    printf 'PATH=%q:$PATH\n' "$workspace"
    printf 'LOCAL_DOWNLOAD_ROOT=%q\n' "$download_root"
    printf '%s\n' 'LOCAL_DB_NAME=easyappointments' \
        'REMOTE_BACKUP_DIR=/root/backups/easyappointments/symlink-stamp' \
        'PROD_SSH_TARGET=synthetic@invalid' \
        'SSH_OPTIONS=(-o synthetic)' \
        'log() { :; }' \
        "$function_body" \
        'download_remote_backup' \
        'printf "%s\n" "$LOCAL_IMPORT_DIR"'
} > "$symlink_script"
chmod 0700 "$symlink_script"

set +e
bash "$symlink_script" >"$workspace/symlink-output" 2>&1
symlink_status=$?
set -e
[[ "$symlink_status" -eq 0 ]] || fail "download failed with planted symlink (status $symlink_status): $(cat "$workspace/symlink-output")"
[[ ! -e "$symlink_target/easyappointments.sql.gz" ]] || fail 'symlink target received the dump'
[[ ! -e "$symlink_target/backup.env" ]] || fail 'symlink target received metadata'
symlink_dir="$(tail -n1 "$workspace/symlink-output")"
[[ "$symlink_dir" != "$symlink_path" ]] || fail 'download reused the planted symlink path'
[[ -d "$symlink_dir" ]] || fail 'download directory was not created beside planted symlink'
assert_mode 700 "$symlink_dir"
assert_mode 600 "$symlink_dir/easyappointments.sql.gz"
assert_mode 600 "$symlink_dir/backup.env"

printf 'import_prod_backup_private_download=passed\n'
