#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
IMPORT_SCRIPT="${IMPORT_SCRIPT_OVERRIDE:-$ROOT_DIR/scripts/import_prod_backup.sh}"
workspace="$(mktemp -d "${TMPDIR:-/tmp}/fh-suspended-input.XXXXXX")"
trap 'rm -rf -- "$workspace"' EXIT
calls="$workspace/calls"
cat > "$workspace/docker" <<'MOCK'
#!/usr/bin/env bash
printf 'docker\n' >> "${IMPORT_TEST_CALLS:?}"
exit 99
MOCK
chmod 0700 "$workspace/docker"
set +e
output="$(PATH="$workspace:$PATH" IMPORT_TEST_CALLS="$calls" bash "$IMPORT_SCRIPT" --remote-backup-dir /root/backups/easyappointments/20260313T074134Z 2>&1)"
status=$?
set -e
[[ "$status" -eq 1 ]] || { printf 'import_prod_backup_input_validation=failed: status %s\n' "$status" >&2; exit 1; }
grep -Fq 'Production dump import is suspended' <<<"$output"
[[ ! -e "$calls" ]] || { printf 'import_prod_backup_input_validation=failed: docker called\n' >&2; exit 1; }
printf 'import_prod_backup_input_validation=passed\n'
