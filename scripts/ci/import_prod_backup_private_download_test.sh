#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
IMPORT_SCRIPT="${IMPORT_SCRIPT_OVERRIDE:-$ROOT_DIR/scripts/import_prod_backup.sh}"
workspace="$(mktemp -d "${TMPDIR:-/tmp}/fh-suspended-import-test.XXXXXX")"
trap 'rm -rf -- "$workspace"' EXIT

fail() {
    printf 'import_prod_backup_private_download=failed: %s\n' "$*" >&2
    exit 1
}

calls="$workspace/calls"
mkdir -p "$workspace/downloads"

cat > "$workspace/ssh" <<'MOCK'
#!/usr/bin/env bash
printf 'ssh-called\n' >> "${IMPORT_TEST_CALLS:?}"
exit 99
MOCK
cat > "$workspace/scp" <<'MOCK'
#!/usr/bin/env bash
printf 'scp-called\n' >> "${IMPORT_TEST_CALLS:?}"
exit 99
MOCK
cat > "$workspace/docker" <<'MOCK'
#!/usr/bin/env bash
printf 'docker-called\n' >> "${IMPORT_TEST_CALLS:?}"
exit 99
MOCK
chmod 0700 "$workspace/ssh" "$workspace/scp" "$workspace/docker"

set +e
output="$(
    PATH="$workspace:$PATH" \
        IMPORT_TEST_CALLS="$calls" \
        LOCAL_DOWNLOAD_ROOT="$workspace/downloads" \
        bash "$IMPORT_SCRIPT" \
            --remote-backup-dir /root/backups/easyappointments/20260313T074134Z \
            --core-services-only 2>&1
)"
status=$?
set -e

[[ "$status" -eq 1 ]] || fail "suspended importer returned $status instead of 1: $output"
grep -Fq 'Production dump import is suspended' <<<"$output" || fail "missing suspension message: $output"
[[ ! -e "$calls" ]] || fail 'suspended importer contacted ssh, scp, or docker'
[[ -z "$(find "$workspace/downloads" -mindepth 1 -print -quit)" ]] || fail 'suspended importer created download state'

printf 'import_prod_backup_private_download=passed\n'
