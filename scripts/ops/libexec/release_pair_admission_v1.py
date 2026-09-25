#!/usr/bin/python3
"""Read-only admission check for one published release archive/provenance pair."""

import hashlib
import hmac
import json
import os
import re
import stat
import sys

ROOT = "/root/releases"
RELEASE = re.compile(r"[A-Za-z0-9._-]+\Z")
SHA256 = re.compile(r"[0-9a-f]{64}\Z")
MAX_ARCHIVE_BYTES = 17_179_869_184
MAX_PROVENANCE_BYTES = 67_108_864
CHUNK = 1024 * 1024


class PairAdmissionError(Exception):
    def __init__(self, result_class, release=""):
        super().__init__(result_class)
        self.result_class = result_class
        self.release = release


def result(status, result_class, release):
    return {
        "schema": "release_pair_admission.v1",
        "status": status,
        "result_class": result_class,
        "release": release,
    }


def reject(result_class, release=""):
    sys.stdout.write(json.dumps(result("failed", result_class, release), separators=(",", ":")) + "\n")
    raise SystemExit(70)


def identity(value):
    return (
        value.st_dev,
        value.st_ino,
        value.st_mode,
        value.st_uid,
        value.st_nlink,
        value.st_size,
        value.st_mtime_ns,
        value.st_ctime_ns,
    )


def verify_directory(path, expected_mode=None):
    value = os.lstat(path)
    if (
        not stat.S_ISDIR(value.st_mode)
        or value.st_uid != 0
        or value.st_gid != 0
        or (expected_mode is not None and stat.S_IMODE(value.st_mode) != expected_mode)
        or (expected_mode is None and stat.S_IMODE(value.st_mode) & 0o022)
    ):
        raise OSError("unsafe release directory")
    return value


def open_root():
    verify_directory("/")
    verify_directory("/root")
    before = verify_directory(ROOT, 0o700)
    fd = os.open(ROOT, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW)
    try:
        opened = os.fstat(fd)
        if identity(before) != identity(opened):
            raise OSError("release root changed")
    except BaseException:
        os.close(fd)
        raise
    return fd


def verify_leaf(directory, leaf, expected_sha, expected_size, max_size):
    try:
        before = os.stat(leaf, dir_fd=directory, follow_symlinks=False)
    except FileNotFoundError:
        raise LookupError("missing")
    if (
        not stat.S_ISREG(before.st_mode)
        or before.st_uid != 0
        or before.st_gid != 0
        or stat.S_IMODE(before.st_mode) != 0o600
        or before.st_nlink != 1
    ):
        raise OSError("occupied")
    if before.st_size != expected_size or expected_size <= 0 or expected_size > max_size:
        raise ValueError("size mismatch")
    fd = os.open(leaf, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW, dir_fd=directory)
    try:
        opened = os.fstat(fd)
        if identity(before) != identity(opened):
            raise OSError("identity drift")
        digest = hashlib.sha256()
        total = 0
        while total < expected_size:
            chunk = os.read(fd, min(CHUNK, expected_size - total))
            if not chunk:
                raise OSError("short read")
            total += len(chunk)
            digest.update(chunk)
        if os.read(fd, 1):
            raise OSError("size drift")
        if total != expected_size or not hmac.compare_digest(digest.hexdigest(), expected_sha):
            raise ValueError("hash mismatch")
        after = os.fstat(fd)
        current = os.stat(leaf, dir_fd=directory, follow_symlinks=False)
        if identity(opened) != identity(after) or identity(after) != identity(current):
            raise OSError("identity drift")
    finally:
        os.close(fd)
    return {"sha256": digest.hexdigest(), "size_bytes": expected_size, "status": "verified"}


def verify_pair(release, archive_sha, archive_size, provenance_sha, provenance_size):
    """Verify and return private details for a published release pair."""
    if not RELEASE.fullmatch(release) or not SHA256.fullmatch(archive_sha) or not SHA256.fullmatch(provenance_sha):
        raise PairAdmissionError("input_invalid", release if RELEASE.fullmatch(release) else "")
    if not isinstance(archive_size, int) or not isinstance(provenance_size, int):
        raise PairAdmissionError("input_invalid", release)
    if archive_size <= 0 or archive_size > MAX_ARCHIVE_BYTES or provenance_size <= 0 or provenance_size > MAX_PROVENANCE_BYTES:
        raise PairAdmissionError("input_invalid", release)
    try:
        directory = open_root()
    except FileNotFoundError:
        raise PairAdmissionError("release_root_missing", release)
    except (OSError, ValueError):
        raise PairAdmissionError("release_root_unsafe", release)
    try:
        archive = verify_leaf(directory, release + ".tar.gz", archive_sha, archive_size, MAX_ARCHIVE_BYTES)
        provenance = verify_leaf(directory, release + ".build-provenance.json", provenance_sha, provenance_size, MAX_PROVENANCE_BYTES)
        output = result("passed", "pair_verified", release)
        output["archive"] = archive
        output["provenance"] = provenance
        return output
    except LookupError:
        raise PairAdmissionError("pair_missing", release)
    except ValueError:
        raise PairAdmissionError("pair_mismatch", release)
    except (OSError, TypeError):
        raise PairAdmissionError("pair_occupied", release)
    finally:
        os.close(directory)


def main():
    if len(sys.argv) != 6:
        reject("input_invalid")
    release, archive_sha, archive_size_raw, provenance_sha, provenance_size_raw = sys.argv[1:]
    try:
        archive_size = int(archive_size_raw)
        provenance_size = int(provenance_size_raw)
    except ValueError:
        reject("input_invalid", release)
    try:
        output = verify_pair(release, archive_sha, archive_size, provenance_sha, provenance_size)
        sys.stdout.write(json.dumps(output, sort_keys=True, separators=(",", ":")) + "\n")
    except PairAdmissionError as error:
        reject(error.result_class, error.release)


if __name__ == "__main__":
    main()
