#!/usr/bin/python3
"""Atomically publish one verified release archive/provenance pair."""

import ctypes
import errno
import fcntl
import hashlib
import os
import re
import stat
import sys

RENAME_NOREPLACE = 1
RELEASE = re.compile(r"[A-Za-z0-9._-]+\Z")
NONCE = re.compile(r"[0-9a-f]{32}\Z")
SHA256 = re.compile(r"[0-9a-f]{64}\Z")


def reject(message="release pair publication rejected"):
    sys.stderr.write(message + "\n")
    raise SystemExit(70)


def digest(fd):
    os.lseek(fd, 0, os.SEEK_SET)
    value = hashlib.sha256()
    while True:
        chunk = os.read(fd, 65536)
        if not chunk:
            return value.hexdigest()
        value.update(chunk)


def open_regular(directory, leaf, expected_sha, expected_size):
    before = os.stat(leaf, dir_fd=directory, follow_symlinks=False)
    fd = os.open(
        leaf,
        os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK,
        dir_fd=directory,
    )
    try:
        opened = os.fstat(fd)
        if (
            (before.st_dev, before.st_ino) != (opened.st_dev, opened.st_ino)
            or not stat.S_ISREG(opened.st_mode)
            or opened.st_uid != os.geteuid()
            or stat.S_IMODE(opened.st_mode) != 0o600
            or opened.st_nlink != 1
            or opened.st_size != expected_size
            or digest(fd) != expected_sha
        ):
            raise OSError("unsafe release leaf")
        after = os.fstat(fd)
        post = os.stat(leaf, dir_fd=directory, follow_symlinks=False)
        if (
            (opened.st_dev, opened.st_ino, opened.st_size, opened.st_mtime_ns, opened.st_ctime_ns)
            != (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns)
            or (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns)
            != (post.st_dev, post.st_ino, post.st_size, post.st_mtime_ns, post.st_ctime_ns)
        ):
            raise OSError("release leaf changed")
    except BaseException:
        os.close(fd)
        raise
    return fd


def rename_noreplace(directory, source, target):
    libc = ctypes.CDLL(None, use_errno=True)
    if hasattr(libc, "renameat2"):
        result = libc.renameat2(
            directory,
            source.encode(),
            directory,
            target.encode(),
            RENAME_NOREPLACE,
        )
    elif hasattr(libc, "renameatx_np"):
        # macOS test path. RENAME_EXCL has the same no-replace property.
        result = libc.renameatx_np(
            directory,
            source.encode(),
            directory,
            target.encode(),
            0x00000004,
        )
    else:
        raise OSError("atomic no-replace rename unavailable")
    if result != 0:
        error = ctypes.get_errno()
        raise OSError(error, os.strerror(error))


def publish(directory, temporary, final, expected_sha, expected_size):
    try:
        existing = open_regular(directory, final, expected_sha, expected_size)
    except FileNotFoundError:
        existing = None
    if existing is not None:
        os.fsync(existing)
        os.close(existing)
        os.unlink(temporary, dir_fd=directory)
        os.fsync(directory)
        return "attached"

    candidate = open_regular(directory, temporary, expected_sha, expected_size)
    try:
        os.fsync(candidate)
        try:
            rename_noreplace(directory, temporary, final)
        except OSError as error:
            if error.errno != errno.EEXIST:
                raise
            existing = open_regular(directory, final, expected_sha, expected_size)
            os.fsync(existing)
            os.close(existing)
            os.unlink(temporary, dir_fd=directory)
            os.fsync(directory)
            return "attached"
        os.fsync(directory)
    finally:
        os.close(candidate)

    final_fd = open_regular(directory, final, expected_sha, expected_size)
    os.fsync(final_fd)
    os.close(final_fd)
    return "published"


def preflight(directory, temporary, final, expected_sha, expected_size):
    candidate = open_regular(directory, temporary, expected_sha, expected_size)
    os.close(candidate)
    try:
        existing = open_regular(directory, final, expected_sha, expected_size)
    except FileNotFoundError:
        return
    os.close(existing)


def validate_directory(fd, expected_uid, exact_mode=None):
    value = os.fstat(fd)
    mode = stat.S_IMODE(value.st_mode)
    if (
        not stat.S_ISDIR(value.st_mode)
        or value.st_uid != expected_uid
        or (exact_mode is not None and mode != exact_mode)
        or (exact_mode is None and mode & 0o022)
    ):
        raise OSError("unsafe release directory")


def open_lock(directory, expected_uid):
    fd = os.open(
        ".release-pair.lock",
        os.O_RDWR | os.O_CREAT | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK,
        0o600,
        dir_fd=directory,
    )
    value = os.fstat(fd)
    if (
        not stat.S_ISREG(value.st_mode)
        or value.st_uid != expected_uid
        or stat.S_IMODE(value.st_mode) != 0o600
        or value.st_nlink != 1
    ):
        os.close(fd)
        raise OSError("unsafe release lock")
    fcntl.flock(fd, fcntl.LOCK_EX)
    return fd


def open_production_root(create, migrate_legacy=False):
    slash = os.open("/", os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW)
    try:
        validate_directory(slash, 0)
        root = os.open("root", os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW, dir_fd=slash)
        try:
            validate_directory(root, 0)
            try:
                releases = os.open(
                    "releases",
                    os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW,
                    dir_fd=root,
                )
            except FileNotFoundError:
                if not create:
                    raise
                os.mkdir("releases", 0o700, dir_fd=root)
                os.fsync(root)
                releases = os.open(
                    "releases",
                    os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW,
                    dir_fd=root,
                )
            current = os.fstat(releases)
            if migrate_legacy and stat.S_IMODE(current.st_mode) == 0o755:
                validate_directory(releases, 0, 0o755)
                identity = (current.st_dev, current.st_ino, current.st_uid, current.st_nlink)
                os.fchmod(releases, 0o700)
                os.fsync(releases)
                os.fsync(root)
                migrated = os.fstat(releases)
                if (
                    identity != (migrated.st_dev, migrated.st_ino, migrated.st_uid, migrated.st_nlink)
                    or stat.S_IMODE(migrated.st_mode) != 0o700
                ):
                    raise OSError("release directory migration failed")
            validate_directory(releases, 0, 0o700)
            return releases
        finally:
            os.close(root)
    finally:
        os.close(slash)


def main():
    if sys.argv[1:] == ["--prepare", "/root/releases"]:
        directory = open_production_root(True, migrate_legacy=True)
        os.fsync(directory)
        os.close(directory)
        sys.stdout.write("ready\n")
        return
    if len(sys.argv) != 8:
        reject()
    root, release, nonce, archive_sha, archive_size_raw, sidecar_sha, sidecar_size_raw = sys.argv[1:]
    if (
        not RELEASE.fullmatch(release)
        or not NONCE.fullmatch(nonce)
        or not SHA256.fullmatch(archive_sha)
        or not SHA256.fullmatch(sidecar_sha)
    ):
        reject()
    try:
        archive_size = int(archive_size_raw)
        sidecar_size = int(sidecar_size_raw)
    except ValueError:
        reject()
    if archive_size <= 0 or sidecar_size <= 0:
        reject()

    production = root == "/root/releases"
    test_root = re.fullmatch(r"/tmp/release-pair-test-[A-Za-z0-9._-]+", root) is not None
    if not production and not test_root:
        reject()
    if production:
        directory = open_production_root(False)
        before = os.fstat(directory)
    else:
        before = os.lstat(root)
        directory = os.open(root, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW)
    try:
        opened = os.fstat(directory)
        expected_mode = 0o700
        expected_uid = 0 if production else os.geteuid()
        if (
            (before.st_dev, before.st_ino) != (opened.st_dev, opened.st_ino)
            or not stat.S_ISDIR(opened.st_mode)
            or opened.st_uid != expected_uid
            or stat.S_IMODE(opened.st_mode) != expected_mode
        ):
            raise OSError("unsafe release root")

        archive_final = release + ".tar.gz"
        sidecar_final = release + ".build-provenance.json"
        archive_temp = "." + archive_final + ".upload-" + nonce
        sidecar_temp = "." + sidecar_final + ".upload-" + nonce
        lock = open_lock(directory, expected_uid)
        try:
            # Prove both candidates and any existing finals before publishing
            # the archive. A conflicting sidecar must never leave a new archive.
            preflight(directory, archive_temp, archive_final, archive_sha, archive_size)
            preflight(directory, sidecar_temp, sidecar_final, sidecar_sha, sidecar_size)
            archive_status = publish(directory, archive_temp, archive_final, archive_sha, archive_size)
            sidecar_status = publish(directory, sidecar_temp, sidecar_final, sidecar_sha, sidecar_size)
        finally:
            cleanup_error = None
            for leaf in (archive_temp, sidecar_temp):
                try:
                    os.unlink(leaf, dir_fd=directory)
                except FileNotFoundError:
                    pass
                except OSError as error:
                    cleanup_error = cleanup_error or error
            try:
                os.fsync(directory)
            except OSError as error:
                cleanup_error = cleanup_error or error
            finally:
                os.close(lock)
            if cleanup_error is not None:
                raise cleanup_error
        sys.stdout.write(archive_status + ":" + sidecar_status + "\n")
    finally:
        os.close(directory)


try:
    main()
except SystemExit:
    raise
except (OSError, TypeError, ValueError):
    reject()
