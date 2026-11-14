#!/usr/bin/env python3
"""Verify a downloaded production backup against its canonical handoff."""

import argparse
import hashlib
import json
import os
import re
import stat
import sys
import zlib

BACKUP_ID = re.compile(r"20[0-9]{6}T[0-9]{6}Z\Z")
SHA256 = re.compile(r"[0-9a-f]{64}\Z")
MAX_COMPRESSED = 16 * 1024 * 1024 * 1024
MAX_UNCOMPRESSED = 64 * 1024 * 1024 * 1024
MAX_METADATA = 64 * 1024


def fail(message: str) -> "NoReturn":
    raise ValueError(message)


def require_regular(path: str, label: str) -> None:
    try:
        mode = os.lstat(path).st_mode
    except OSError as error:
        fail(f"{label} is unreadable: {error}")
    if not stat.S_ISREG(mode):
        fail(f"{label} is not a regular file")


def read_json(path: str) -> dict:
    require_regular(path, "handoff")
    try:
        if os.stat(path, follow_symlinks=False).st_size > MAX_METADATA:
            fail("handoff is too large")
        with open(path, "rb") as handle:
            value = json.load(handle, object_pairs_hook=_reject_duplicate_keys)
    except (OSError, ValueError) as error:
        fail(f"invalid handoff: {error}")
    if not isinstance(value, dict):
        fail("handoff must be an object")
    return value


def _reject_duplicate_keys(pairs: list[tuple[str, object]]) -> dict[str, object]:
    value: dict[str, object] = {}
    for key, item in pairs:
        if key in value:
            fail("handoff contains duplicate keys")
        value[key] = item
    return value


def read_manifest(path: str) -> dict[str, str]:
    values: dict[str, str] = {}
    require_regular(path, "manifest")
    try:
        if os.stat(path, follow_symlinks=False).st_size > MAX_METADATA:
            fail("manifest is too large")
        with open(path, "r", encoding="ascii", newline="") as handle:
            for line in handle:
                key, separator, value = line.rstrip("\n").partition("=")
                if not separator or not re.fullmatch(r"[a-z][a-z0-9_]*", key) or key in values:
                    fail("manifest format is invalid")
                values[key] = value
    except (OSError, UnicodeError) as error:
        fail(f"manifest unreadable: {error}")
    expected = {
        "schema",
        "backup_set_id",
        "created_at_utc",
        "dump_sha256",
        "compressed_size_bytes",
        "uncompressed_size_bytes",
    }
    if set(values) != expected or values["schema"] != "production_backup_set.v1":
        fail("manifest keys or schema are invalid")
    return values


def integer(value: object, label: str, maximum: int) -> int:
    if not isinstance(value, int) or isinstance(value, bool) or value <= 0 or value > maximum:
        fail(f"{label} is invalid")
    return value


def verify(handoff_path: str, manifest_path: str, dump_path: str, expected_id: str) -> dict[str, object]:
    handoff = read_json(handoff_path)
    if set(handoff) != {
        "backup_set_id",
        "compressed_size_bytes",
        "dump_sha256",
        "schema",
        "uncompressed_size_bytes",
    } or handoff.get("schema") != "production_backup_set_handoff.v1":
        fail("handoff keys or schema are invalid")
    backup_id = handoff["backup_set_id"]
    if not isinstance(backup_id, str) or not BACKUP_ID.fullmatch(backup_id) or backup_id != expected_id:
        fail("handoff backup ID does not match the selected set")
    digest = handoff["dump_sha256"]
    if not isinstance(digest, str) or not SHA256.fullmatch(digest):
        fail("handoff digest is invalid")
    compressed = integer(handoff["compressed_size_bytes"], "handoff compressed size", MAX_COMPRESSED)
    uncompressed = integer(handoff["uncompressed_size_bytes"], "handoff uncompressed size", MAX_UNCOMPRESSED)
    manifest = read_manifest(manifest_path)
    if manifest.get("backup_set_id") != backup_id or manifest.get("dump_sha256") != digest:
        fail("manifest does not match the handoff")
    if manifest.get("created_at_utc") != f"{backup_id[:4]}-{backup_id[4:6]}-{backup_id[6:8]}T{backup_id[9:11]}:{backup_id[11:13]}:{backup_id[13:15]}Z":
        fail("manifest timestamp does not match the handoff")
    try:
        if not re.fullmatch(r"[1-9][0-9]*", manifest["compressed_size_bytes"]) or not re.fullmatch(r"[1-9][0-9]*", manifest["uncompressed_size_bytes"]):
            fail("manifest sizes are invalid")
        if int(manifest["compressed_size_bytes"]) != compressed or int(manifest["uncompressed_size_bytes"]) != uncompressed:
            fail("manifest sizes do not match the handoff")
    except (KeyError, ValueError):
        fail("manifest sizes are invalid")
    try:
        observed_compressed = os.stat(dump_path, follow_symlinks=False)
    except OSError as error:
        fail(f"dump is unreadable: {error}")
    if not stat.S_ISREG(observed_compressed.st_mode) or observed_compressed.st_size != compressed:
        fail("compressed dump size does not match the handoff")
    observed_digest = hashlib.sha256()
    observed_uncompressed = 0
    try:
        with open(dump_path, "rb") as raw:
            opened = os.fstat(raw.fileno())
            if not stat.S_ISREG(opened.st_mode) or opened.st_size != compressed:
                fail("dump identity changed before hashing")
            while raw_chunk := raw.read(1024 * 1024):
                observed_digest.update(raw_chunk)
            if observed_digest.hexdigest() != digest:
                fail("compressed dump bytes do not match the handoff")
            raw.seek(0)
            decompressor = zlib.decompressobj(16 + zlib.MAX_WBITS)
            while raw_chunk := raw.read(1024 * 1024):
                pending = raw_chunk
                while pending or not decompressor.eof:
                    expanded = decompressor.decompress(pending, 1024 * 1024)
                    observed_uncompressed += len(expanded)
                    if decompressor.unused_data:
                        fail("gzip dump contains trailing bytes")
                    if observed_uncompressed > MAX_UNCOMPRESSED:
                        fail("uncompressed dump is too large")
                    pending = decompressor.unconsumed_tail
                    if not pending and not expanded:
                        break
            if not decompressor.eof:
                fail("gzip stream is incomplete")
            observed_uncompressed += len(decompressor.flush(1024 * 1024))
            closed = os.fstat(raw.fileno())
            if (closed.st_dev, closed.st_ino, closed.st_size, closed.st_mtime_ns) != (
                opened.st_dev,
                opened.st_ino,
                opened.st_size,
                opened.st_mtime_ns,
            ):
                fail("dump identity changed during verification")
    except (OSError, EOFError, zlib.error) as error:
        fail(f"gzip dump is invalid: {error}")
    if observed_digest.hexdigest() != digest or observed_uncompressed != uncompressed:
        fail("dump bytes do not match the handoff")
    return {"backup_set_id": backup_id, "compressed_size_bytes": compressed, "uncompressed_size_bytes": uncompressed}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--handoff", required=True)
    parser.add_argument("--manifest", required=True)
    parser.add_argument("--dump", required=True)
    parser.add_argument("--backup-id", required=True)
    args = parser.parse_args()
    try:
        print(json.dumps(verify(args.handoff, args.manifest, args.dump, args.backup_id), sort_keys=True))
    except ValueError as error:
        print(f"backup import verification failed: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
