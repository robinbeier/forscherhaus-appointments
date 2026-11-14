#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
VERIFY="${ROOT_DIR}/scripts/ops/libexec/backup_import_verify.py"
workspace="$(mktemp -d "${TMPDIR:-/tmp}/fh-backup-attestation-test.XXXXXX")"
trap 'rm -rf -- "$workspace"' EXIT

fail() { printf 'import_prod_backup_attestation=failed: %s\n' "$*" >&2; exit 1; }

backup_id='20260313T074134Z'
printf 'synthetic SQL payload\n' > "$workspace/source.sql"
gzip -n -c "$workspace/source.sql" > "$workspace/easyappointments.sql.gz"
digest="$(sha256sum "$workspace/easyappointments.sql.gz" | awk '{print $1}')"
compressed="$(stat -c '%s' "$workspace/easyappointments.sql.gz" 2>/dev/null || stat -f '%z' "$workspace/easyappointments.sql.gz")"
uncompressed="$(stat -c '%s' "$workspace/source.sql" 2>/dev/null || stat -f '%z' "$workspace/source.sql")"
created='2026-03-13T07:41:34Z'
printf '%s\n' \
    'schema=production_backup_set.v1' \
    "backup_set_id=${backup_id}" \
    "created_at_utc=${created}" \
    "dump_sha256=${digest}" \
    "compressed_size_bytes=${compressed}" \
    "uncompressed_size_bytes=${uncompressed}" > "$workspace/backup.env"
printf '%s\n' "{\"backup_set_id\":\"${backup_id}\",\"compressed_size_bytes\":${compressed},\"dump_sha256\":\"${digest}\",\"schema\":\"production_backup_set_handoff.v1\",\"uncompressed_size_bytes\":${uncompressed}}" > "$workspace/last_backup_set.json"

write_metadata_for_dump() {
    local dump_path="$1"
    local dump_digest dump_compressed
    dump_digest="$(sha256sum "$dump_path" | awk '{print $1}')"
    dump_compressed="$(stat -c '%s' "$dump_path" 2>/dev/null || stat -f '%z' "$dump_path")"
    printf '%s\n' \
        'schema=production_backup_set.v1' \
        "backup_set_id=${backup_id}" \
        "created_at_utc=${created}" \
        "dump_sha256=${dump_digest}" \
        "compressed_size_bytes=${dump_compressed}" \
        "uncompressed_size_bytes=${uncompressed}" > "$workspace/backup.env"
    printf '%s\n' "{\"backup_set_id\":\"${backup_id}\",\"compressed_size_bytes\":${dump_compressed},\"dump_sha256\":\"${dump_digest}\",\"schema\":\"production_backup_set_handoff.v1\",\"uncompressed_size_bytes\":${uncompressed}}" > "$workspace/last_backup_set.json"
}

python3 "$VERIFY" --handoff "$workspace/last_backup_set.json" --manifest "$workspace/backup.env" --dump "$workspace/easyappointments.sql.gz" --backup-id "$backup_id" >/dev/null || fail 'valid handoff rejected'

dd if=/dev/zero of="$workspace/large.sql" bs=1048576 count=3 >/dev/null 2>&1
gzip -n -c "$workspace/large.sql" > "$workspace/large.sql.gz"
uncompressed="$(stat -c '%s' "$workspace/large.sql" 2>/dev/null || stat -f '%z' "$workspace/large.sql")"
write_metadata_for_dump "$workspace/large.sql.gz"
if ! python3 "$VERIFY" --handoff "$workspace/last_backup_set.json" --manifest "$workspace/backup.env" --dump "$workspace/large.sql.gz" --backup-id "$backup_id" >/dev/null; then
    fail 'large valid gzip dump rejected'
fi
uncompressed="$(stat -c '%s' "$workspace/source.sql" 2>/dev/null || stat -f '%z' "$workspace/source.sql")"
write_metadata_for_dump "$workspace/easyappointments.sql.gz"

printf '%s\n' "{\"backup_set_id\":\"${backup_id}\",\"backup_set_id\":\"${backup_id}\",\"compressed_size_bytes\":${compressed},\"dump_sha256\":\"${digest}\",\"schema\":\"production_backup_set_handoff.v1\",\"uncompressed_size_bytes\":${uncompressed}}" > "$workspace/last_backup_set.json"
if python3 "$VERIFY" --handoff "$workspace/last_backup_set.json" --manifest "$workspace/backup.env" --dump "$workspace/easyappointments.sql.gz" --backup-id "$backup_id" >/dev/null 2>&1; then
    fail 'duplicate handoff JSON keys accepted'
fi
write_metadata_for_dump "$workspace/easyappointments.sql.gz"

head -c 65537 /dev/zero > "$workspace/backup.env"
if python3 "$VERIFY" --handoff "$workspace/last_backup_set.json" --manifest "$workspace/backup.env" --dump "$workspace/easyappointments.sql.gz" --backup-id "$backup_id" >/dev/null 2>&1; then
    fail 'oversized manifest accepted'
fi
write_metadata_for_dump "$workspace/easyappointments.sql.gz"

printf 'tampered\n' >> "$workspace/source.sql"
printf 'tampered\n' >> "$workspace/easyappointments.sql.gz"
if python3 "$VERIFY" --handoff "$workspace/last_backup_set.json" --manifest "$workspace/backup.env" --dump "$workspace/easyappointments.sql.gz" --backup-id "$backup_id" >/dev/null 2>&1; then
    fail 'tampered dump accepted'
fi

printf 'synthetic SQL payload\n' | gzip -n -c > "$workspace/easyappointments.sql.gz"
printf 'dump_sha256=%s\n' '0000000000000000000000000000000000000000000000000000000000000000' >> "$workspace/backup.env"
if python3 "$VERIFY" --handoff "$workspace/last_backup_set.json" --manifest "$workspace/backup.env" --dump "$workspace/easyappointments.sql.gz" --backup-id "$backup_id" >/dev/null 2>&1; then
    fail 'duplicate or altered manifest accepted'
fi

printf 'synthetic SQL payload\n' | gzip -n -c > "$workspace/easyappointments.sql.gz"
write_metadata_for_dump "$workspace/easyappointments.sql.gz"
mv "$workspace/easyappointments.sql.gz" "$workspace/easyappointments.sql.gz.real"
ln -s easyappointments.sql.gz.real "$workspace/easyappointments.sql.gz"
if python3 "$VERIFY" --handoff "$workspace/last_backup_set.json" --manifest "$workspace/backup.env" --dump "$workspace/easyappointments.sql.gz" --backup-id "$backup_id" >/dev/null 2>&1; then
    fail 'symlink dump accepted'
fi
rm "$workspace/easyappointments.sql.gz"
mv "$workspace/easyappointments.sql.gz.real" "$workspace/easyappointments.sql.gz"

cp "$workspace/easyappointments.sql.gz" "$workspace/trailing.sql.gz"
printf 'trailing bytes\n' >> "$workspace/trailing.sql.gz"
write_metadata_for_dump "$workspace/trailing.sql.gz"
if python3 "$VERIFY" --handoff "$workspace/last_backup_set.json" --manifest "$workspace/backup.env" --dump "$workspace/trailing.sql.gz" --backup-id "$backup_id" >/dev/null 2>&1; then
    fail 'gzip dump with trailing bytes accepted'
fi

write_metadata_for_dump "$workspace/easyappointments.sql.gz"
wrong_compressed=$((compressed + 1))
printf '%s\n' \
    'schema=production_backup_set.v1' \
    "backup_set_id=${backup_id}" \
    "created_at_utc=${created}" \
    "dump_sha256=${digest}" \
    "compressed_size_bytes=${wrong_compressed}" \
    "uncompressed_size_bytes=${uncompressed}" > "$workspace/backup.env"
printf '%s\n' "{\"backup_set_id\":\"${backup_id}\",\"compressed_size_bytes\":${wrong_compressed},\"dump_sha256\":\"${digest}\",\"schema\":\"production_backup_set_handoff.v1\",\"uncompressed_size_bytes\":${uncompressed}}" > "$workspace/last_backup_set.json"
if python3 "$VERIFY" --handoff "$workspace/last_backup_set.json" --manifest "$workspace/backup.env" --dump "$workspace/easyappointments.sql.gz" --backup-id "$backup_id" >/dev/null 2>&1; then
    fail 'compressed size mismatch accepted'
fi

verify_line="$(grep -nE '^    verify_downloaded_backup$' "$ROOT_DIR/scripts/import_prod_backup.sh" | cut -d: -f1)"
backup_line="$(grep -nE '^    backup_local_mysql_dir$' "$ROOT_DIR/scripts/import_prod_backup.sh" | cut -d: -f1)"
reset_line="$(grep -nE '^    reset_and_import_local_database$' "$ROOT_DIR/scripts/import_prod_backup.sh" | cut -d: -f1)"
(( verify_line < backup_line && verify_line < reset_line )) || fail 'verification is not ordered before destructive local steps'

printf 'import_prod_backup_attestation=passed\n'
