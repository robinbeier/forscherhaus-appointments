#!/usr/bin/python3
"""Fail-closed ROB-451 journald configuration, inspection, and bounded vacuum."""

from __future__ import annotations

import ctypes
import errno
import fcntl
import json
import os
import stat
import subprocess
import sys
from pathlib import Path
from typing import NoReturn

SCHEMA = 'prod_journald_retention.v1'
MANAGED_LEAF = '60-fh-journald-retention.conf'
EXPECTED_CONFIG = b'[Journal]\nSystemMaxUse=1G\nMaxRetentionSec=30day\n'
SYSTEM_MAX_USE_BYTES = 1_073_741_824
MAX_RETENTION_SECONDS = 2_592_000
MAX_CONFIG_BYTES = 65_536
MAX_JOURNAL_ENTRIES = 100_000
MAX_JOURNAL_DEPTH = 4
MAX_MERGED_CONFIG_BYTES = 262_144
TARGET_KEYS = {'SystemMaxUse', 'MaxRetentionSec'}
RENAME_NOREPLACE = 1
DROPIN_ROOTS = (
    'usr/lib/systemd/journald.conf.d',
    'usr/local/lib/systemd/journald.conf.d',
    'run/systemd/journald.conf.d',
    'etc/systemd/journald.conf.d',
)
MAIN_CONFIGS = (
    'usr/lib/systemd/journald.conf',
    'usr/local/lib/systemd/journald.conf',
    'run/systemd/journald.conf',
    'etc/systemd/journald.conf',
)


class RetentionError(Exception):
    def __init__(self, reason: str, code: int = 70) -> None:
        super().__init__(reason)
        self.reason = reason
        self.code = code


def reject(reason: str, code: int = 70) -> NoReturn:
    raise RetentionError(reason, code)


def test_mode() -> bool:
    return os.environ.get('FH_JOURNALD_RETENTION_TESTING') == '1'


def filesystem_root() -> Path:
    raw = os.environ.get('FH_JOURNALD_RETENTION_TEST_ROOT')
    if raw is None:
        return Path('/')
    if not test_mode():
        reject('test_root_forbidden')
    root = Path(raw)
    if not root.is_absolute() or root == Path('/') or not str(root).startswith(
        ('/tmp/', '/private/tmp/', '/var/folders/', '/private/var/folders/')
    ):
        reject('test_root_unsafe')
    return root


def expected_uid(root: Path) -> int:
    return os.getuid() if root != Path('/') else 0


def assert_safe_directory(path: Path, uid: int, exact_mode: int | None = None) -> os.stat_result:
    try:
        before = os.lstat(path)
    except FileNotFoundError:
        reject('directory_missing')
    if not stat.S_ISDIR(before.st_mode) or stat.S_ISLNK(before.st_mode):
        reject('directory_unsafe')
    mode = stat.S_IMODE(before.st_mode)
    if before.st_uid != uid or before.st_nlink < 2 or mode & 0o022:
        reject('directory_unsafe')
    if exact_mode is not None and mode != exact_mode:
        reject('directory_unsafe')
    try:
        fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
    except OSError:
        reject('directory_unsafe')
    try:
        opened = os.fstat(fd)
        after = os.lstat(path)
        identity = lambda value: (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_nlink)
        if identity(before) != identity(opened) or identity(opened) != identity(after):
            reject('directory_changed')
        return opened
    finally:
        os.close(fd)


def ensure_ancestor_chain(root: Path, relative: str, uid: int) -> None:
    current = root
    assert_safe_directory(current, uid)
    for component in Path(relative).parts:
        current = current / component
        assert_safe_directory(current, uid)


def stable_read(path: Path, uid: int, *, exact_mode: int | None = None) -> bytes:
    try:
        before = os.lstat(path)
    except FileNotFoundError:
        reject('file_missing')
    if not stat.S_ISREG(before.st_mode) or stat.S_ISLNK(before.st_mode):
        reject('file_unsafe')
    mode = stat.S_IMODE(before.st_mode)
    if before.st_uid != uid or before.st_nlink != 1 or mode & 0o022:
        reject('file_unsafe')
    if exact_mode is not None and mode != exact_mode:
        reject('file_unsafe')
    if before.st_size <= 0 or before.st_size > MAX_CONFIG_BYTES:
        reject('file_size_invalid')
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC)
    except OSError:
        reject('file_unsafe')
    try:
        opened = os.fstat(fd)
        identity = lambda value: (
            value.st_dev, value.st_ino, value.st_mode, value.st_uid,
            value.st_nlink, value.st_size, value.st_mtime_ns, value.st_ctime_ns,
        )
        if identity(before) != identity(opened):
            reject('file_changed')
        data = bytearray()
        while len(data) <= MAX_CONFIG_BYTES:
            chunk = os.read(fd, min(65_536, MAX_CONFIG_BYTES + 1 - len(data)))
            if not chunk:
                break
            data.extend(chunk)
        after_fd = os.fstat(fd)
        after_path = os.lstat(path)
        if len(data) > MAX_CONFIG_BYTES or identity(opened) != identity(after_fd) or identity(after_fd) != identity(after_path):
            reject('file_changed')
        return bytes(data)
    finally:
        os.close(fd)


def stable_read_at(directory: int, leaf: str, uid: int, *, exact_mode: int | None = None) -> bytes:
    try:
        before = os.stat(leaf, dir_fd=directory, follow_symlinks=False)
    except FileNotFoundError:
        reject('file_missing')
    if not stat.S_ISREG(before.st_mode):
        reject('file_unsafe')
    mode = stat.S_IMODE(before.st_mode)
    if before.st_uid != uid or before.st_nlink != 1 or mode & 0o022:
        reject('file_unsafe')
    if exact_mode is not None and mode != exact_mode:
        reject('file_unsafe')
    if before.st_size <= 0 or before.st_size > MAX_CONFIG_BYTES:
        reject('file_size_invalid')
    try:
        fd = os.open(leaf, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=directory)
    except OSError:
        reject('file_unsafe')
    try:
        opened = os.fstat(fd)
        identity = lambda value: (
            value.st_dev, value.st_ino, value.st_mode, value.st_uid,
            value.st_nlink, value.st_size, value.st_mtime_ns, value.st_ctime_ns,
        )
        if identity(before) != identity(opened):
            reject('file_changed')
        data = bytearray()
        while len(data) <= MAX_CONFIG_BYTES:
            chunk = os.read(fd, min(65_536, MAX_CONFIG_BYTES + 1 - len(data)))
            if not chunk:
                break
            data.extend(chunk)
        after_fd = os.fstat(fd)
        after_path = os.stat(leaf, dir_fd=directory, follow_symlinks=False)
        if len(data) > MAX_CONFIG_BYTES or identity(opened) != identity(after_fd) or identity(after_fd) != identity(after_path):
            reject('file_changed')
        return bytes(data)
    finally:
        os.close(fd)


def assignments(data: bytes) -> dict[str, str]:
    try:
        text = data.decode('utf-8')
    except UnicodeDecodeError:
        reject('config_invalid')
    section = ''
    found: dict[str, str] = {}
    for raw_line in text.splitlines():
        line = raw_line.strip()
        if not line or line.startswith(('#', ';')):
            continue
        if line.startswith('[') and line.endswith(']'):
            section = line[1:-1].strip()
            continue
        if section != 'Journal' or '=' not in line:
            continue
        key, value = (item.strip() for item in line.split('=', 1))
        if key in TARGET_KEYS:
            if key in found:
                reject('duplicate_retention_setting')
            found[key] = value
    return found


def scan_configuration(root: Path) -> tuple[str, str, int | None, int | None]:
    uid = expected_uid(root)
    managed_path = root / 'etc/systemd/journald.conf.d' / MANAGED_LEAF
    managed_present = managed_path.exists() or managed_path.is_symlink()
    managed_exact = False
    conflicts: list[str] = []

    for relative in MAIN_CONFIGS:
        path = root / relative
        if not path.exists() and not path.is_symlink():
            continue
        ensure_ancestor_chain(root, str(Path(relative).parent), uid)
        values = assignments(stable_read(path, uid))
        if values:
            conflicts.append(relative)

    for relative in DROPIN_ROOTS:
        directory = root / relative
        if not directory.exists() and not directory.is_symlink():
            continue
        ensure_ancestor_chain(root, relative, uid)
        assert_safe_directory(directory, uid)
        try:
            names = sorted(os.listdir(directory))
        except OSError:
            reject('dropin_directory_unreadable')
        for name in names:
            if not name.endswith('.conf'):
                continue
            path = directory / name
            data = stable_read(path, uid, exact_mode=0o644 if path == managed_path else None)
            values = assignments(data)
            if path == managed_path:
                managed_exact = data == EXPECTED_CONFIG and values == {
                    'SystemMaxUse': '1G',
                    'MaxRetentionSec': '30day',
                }
            elif values:
                conflicts.append(f'{relative}/{name}')

    if conflicts:
        return 'drift', 'conflicting_retention_setting', None, None
    if not managed_present:
        return 'drift', 'managed_dropin_missing', None, None
    if not managed_exact:
        return 'drift', 'managed_dropin_mismatch', None, None
    return 'pass', 'none', SYSTEM_MAX_USE_BYTES, MAX_RETENTION_SECONDS


def command_path(name: str, production: str) -> str:
    if not test_mode():
        return production
    override = os.environ.get(f'FH_JOURNALD_RETENTION_{name}')
    if override is None or not os.path.isabs(override):
        reject('test_command_missing')
    return override


def run_command(argv: list[str], timeout_seconds: int = 30) -> None:
    try:
        completed = subprocess.run(
            argv,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            timeout=timeout_seconds,
            check=False,
            env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LC_ALL': 'C'},
        )
    except (OSError, subprocess.TimeoutExpired):
        reject('command_failed', 75)
    if completed.returncode != 0:
        reject('command_failed', 75)


def run_command_capture(argv: list[str]) -> bytes:
    try:
        completed = subprocess.run(
            argv,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
            timeout=30,
            check=False,
            env={
                'PATH': '/usr/sbin:/usr/bin:/sbin:/bin',
                'LC_ALL': 'C',
                'SYSTEMD_COLORS': '0',
                'SYSTEMD_PAGER': 'cat',
            },
        )
    except (OSError, subprocess.TimeoutExpired):
        reject('effective_config_unavailable', 75)
    if completed.returncode != 0 or len(completed.stdout) > MAX_MERGED_CONFIG_BYTES:
        reject('effective_config_unavailable', 75)
    return completed.stdout


def assert_effective_configuration(root: Path) -> None:
    argv = [command_path('SYSTEMD_ANALYZE', '/usr/bin/systemd-analyze'), '--no-pager']
    if root != Path('/'):
        argv.append(f'--root={root}')
    argv.extend(['cat-config', 'systemd/journald.conf'])
    values = assignments(run_command_capture(argv))
    if values != {'SystemMaxUse': '1G', 'MaxRetentionSec': '30day'}:
        reject('effective_config_mismatch', 75)


def disk_usage_bytes(root: Path) -> int:
    uid = expected_uid(root)
    relative = 'var/log/journal'
    path = root / relative
    ensure_ancestor_chain(root, relative, uid)
    top = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
    try:
        boundary = os.fstat(top).st_dev
        count = [0]
        return journal_tree_usage(top, boundary, uid, 0, count)
    finally:
        os.close(top)


def journal_tree_usage(directory: int, boundary: int, uid: int, depth: int, count: list[int]) -> int:
    if depth > MAX_JOURNAL_DEPTH:
        reject('journal_tree_invalid')
    total = 0
    try:
        names = sorted(os.listdir(directory))
    except OSError:
        reject('journal_tree_unreadable')
    for name in names:
        count[0] += 1
        if count[0] > MAX_JOURNAL_ENTRIES or name in {'.', '..'} or '/' in name or '\x00' in name:
            reject('journal_tree_invalid')
        try:
            before = os.stat(name, dir_fd=directory, follow_symlinks=False)
        except OSError:
            reject('journal_tree_changed')
        if before.st_dev != boundary or before.st_uid != uid or stat.S_IMODE(before.st_mode) & 0o022:
            reject('journal_tree_unsafe')
        if stat.S_ISREG(before.st_mode):
            if before.st_nlink != 1:
                reject('journal_tree_unsafe')
            try:
                fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=directory)
            except OSError:
                reject('journal_tree_unsafe')
            try:
                opened = os.fstat(fd)
                after = os.stat(name, dir_fd=directory, follow_symlinks=False)
                identity = lambda value: (
                    value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_nlink,
                )
                if identity(before) != identity(opened) or identity(opened) != identity(after):
                    reject('journal_tree_changed')
                total += opened.st_blocks * 512
            finally:
                os.close(fd)
        elif stat.S_ISDIR(before.st_mode):
            if before.st_nlink < 2:
                reject('journal_tree_unsafe')
            try:
                child = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=directory)
            except OSError:
                reject('journal_tree_unsafe')
            try:
                opened = os.fstat(child)
                after = os.stat(name, dir_fd=directory, follow_symlinks=False)
                identity = lambda value: (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_nlink)
                if identity(before) != identity(opened) or identity(opened) != identity(after):
                    reject('journal_tree_changed')
                total += journal_tree_usage(child, boundary, uid, depth + 1, count)
            finally:
                os.close(child)
        else:
            reject('journal_tree_unsafe')
        if total < 0 or total > (1 << 63) - 1:
            reject('journal_usage_overflow')
    return total


def ensure_write_paths(root: Path) -> tuple[int, int]:
    uid = expected_uid(root)
    ensure_ancestor_chain(root, 'etc/systemd', uid)
    config_dir = root / 'etc/systemd/journald.conf.d'
    if not config_dir.exists():
        os.mkdir(config_dir, 0o755)
        parent_fd = os.open(config_dir.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
        try:
            os.fsync(parent_fd)
        finally:
            os.close(parent_fd)
    assert_safe_directory(config_dir, uid, 0o755)

    ensure_ancestor_chain(root, 'var/lib/fh-deploy-orchestrator/locks', uid)
    global_lock_path = root / 'var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock'
    try:
        lock_before = os.lstat(global_lock_path)
        global_lock_fd = os.open(global_lock_path, os.O_RDWR | os.O_NOFOLLOW | os.O_CLOEXEC)
        lock_stat = os.fstat(global_lock_fd)
        lock_after = os.lstat(global_lock_path)
    except OSError:
        reject('global_lock_unsafe')
    lock_identity = lambda value: (
        value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_nlink,
        value.st_size, value.st_mtime_ns, value.st_ctime_ns,
    )
    if (
        lock_identity(lock_before) != lock_identity(lock_stat)
        or lock_identity(lock_stat) != lock_identity(lock_after)
        or not stat.S_ISREG(lock_stat.st_mode)
        or lock_stat.st_uid != uid
        or lock_stat.st_nlink != 1
        or stat.S_IMODE(lock_stat.st_mode) != 0o600
        or lock_stat.st_size != 0
    ):
        os.close(global_lock_fd)
        reject('global_lock_unsafe')
    try:
        fcntl.flock(global_lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        os.close(global_lock_fd)
        reject('global_lock_busy', 75)

    config_fd = os.open(config_dir, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
    return global_lock_fd, config_fd


def publish_config(config_fd: int) -> bool:
    try:
        existing = stable_read_at(config_fd, MANAGED_LEAF, os.getuid(), exact_mode=0o644)
    except RetentionError as error:
        if error.reason != 'file_missing':
            raise
    else:
        if existing != EXPECTED_CONFIG:
            reject('managed_dropin_conflict', 75)
        fsync_leaf(config_fd, MANAGED_LEAF)
        os.fsync(config_fd)
        return False

    temp = f'.{MANAGED_LEAF}.tmp'
    fd = -1
    try:
        try:
            stale = os.stat(temp, dir_fd=config_fd, follow_symlinks=False)
        except FileNotFoundError:
            pass
        else:
            if (
                not stat.S_ISREG(stale.st_mode)
                or stale.st_uid != os.getuid()
                or stale.st_nlink != 1
                or stat.S_IMODE(stale.st_mode) not in {0o600, 0o644}
            ):
                reject('managed_temp_unsafe', 75)
            os.unlink(temp, dir_fd=config_fd)
            os.fsync(config_fd)
        fd = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC, 0o600, dir_fd=config_fd)
        view = memoryview(EXPECTED_CONFIG)
        while view:
            written = os.write(fd, view)
            if written <= 0:
                reject('config_write_failed', 75)
            view = view[written:]
        os.fsync(fd)
        os.fchmod(fd, 0o644)
        os.fsync(fd)
        os.close(fd)
        fd = -1
        try:
            rename_noreplace(config_fd, temp, MANAGED_LEAF)
        except OSError as error:
            if error.errno != errno.EEXIST:
                raise
            existing = stable_read_at(config_fd, MANAGED_LEAF, os.getuid(), exact_mode=0o644)
            if existing != EXPECTED_CONFIG:
                reject('managed_dropin_conflict', 75)
            fsync_leaf(config_fd, MANAGED_LEAF)
            os.fsync(config_fd)
            return False
        os.fsync(config_fd)
        return True
    finally:
        if fd >= 0:
            os.close(fd)
        try:
            os.unlink(temp, dir_fd=config_fd)
        except FileNotFoundError:
            pass


def fsync_leaf(directory: int, leaf: str) -> None:
    try:
        fd = os.open(leaf, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=directory)
    except OSError:
        reject('managed_dropin_unsafe', 75)
    try:
        opened = os.fstat(fd)
        if (
            not stat.S_ISREG(opened.st_mode)
            or opened.st_uid != os.getuid()
            or opened.st_nlink != 1
            or stat.S_IMODE(opened.st_mode) != 0o644
        ):
            reject('managed_dropin_unsafe', 75)
        os.fsync(fd)
    finally:
        os.close(fd)


def rename_noreplace(directory: int, source: str, target: str) -> None:
    libc = ctypes.CDLL(None, use_errno=True)
    if hasattr(libc, 'renameat2'):
        result = libc.renameat2(
            directory,
            source.encode(),
            directory,
            target.encode(),
            RENAME_NOREPLACE,
        )
    elif hasattr(libc, 'renameatx_np'):
        result = libc.renameatx_np(
            directory,
            source.encode(),
            directory,
            target.encode(),
            0x00000004,
        )
    else:
        reject('atomic_publish_unavailable', 75)
    if result != 0:
        error = ctypes.get_errno()
        raise OSError(error, os.strerror(error))


def unlink_exact_config(config_fd: int) -> bool:
    try:
        data = stable_read_at(config_fd, MANAGED_LEAF, os.getuid(), exact_mode=0o644)
    except RetentionError as error:
        if error.reason == 'file_missing':
            return False
        raise
    if data != EXPECTED_CONFIG:
        reject('managed_dropin_conflict', 75)
    os.unlink(MANAGED_LEAF, dir_fd=config_fd)
    os.fsync(config_fd)
    return True


def restart_journald() -> None:
    run_command([command_path('SYSTEMCTL', '/usr/bin/systemctl'), 'restart', 'systemd-journald.service'])


def payload(mode: str, status: str, reason: str, use: int | None, age: int | None, usage: int | None) -> dict[str, object]:
    return {
        'schema': SCHEMA,
        'mode': mode,
        'status': status,
        'reason': reason,
        'system_max_use_bytes': use,
        'max_retention_seconds': age,
        'disk_usage_bytes': usage,
    }


def inspect(root: Path, mode: str = 'inspect') -> dict[str, object]:
    status, reason, use, age = scan_configuration(root)
    if status == 'pass':
        try:
            assert_effective_configuration(root)
        except RetentionError as error:
            status, reason, use, age = 'invalid', error.reason, None, None
    usage: int | None = None
    try:
        usage = disk_usage_bytes(root)
    except RetentionError:
        if status == 'pass':
            return payload(mode, 'invalid', 'disk_usage_unavailable', use, age, None)
    if status == 'pass' and usage is not None and usage > SYSTEM_MAX_USE_BYTES:
        return payload(mode, 'drift', 'disk_usage_over_limit', use, age, usage)
    return payload(mode, status, reason, use, age, usage)


def apply_config(root: Path) -> dict[str, object]:
    global_fd, config_fd = ensure_write_paths(root)
    published = False
    try:
        before = scan_configuration(root)
        if before[0] == 'drift' and before[1] not in {'managed_dropin_missing'}:
            reject(before[1], 75)
        published = publish_config(config_fd)
        try:
            after = scan_configuration(root)
            if after[:4] != ('pass', 'none', SYSTEM_MAX_USE_BYTES, MAX_RETENTION_SECONDS):
                reject('post_publish_validation_failed', 75)
            assert_effective_configuration(root)
            restart_journald()
        except RetentionError:
            if published:
                unlink_exact_config(config_fd)
                restart_journald()
            raise
        result = inspect(root, 'apply_config')
        if result['status'] not in {'pass', 'drift'} or result['reason'] not in {'none', 'disk_usage_over_limit'}:
            reject('post_apply_validation_failed', 75)
        if result['reason'] == 'disk_usage_over_limit':
            result['status'] = 'applied_needs_vacuum'
        else:
            result['status'] = 'applied' if published else 'attached'
            result['reason'] = 'none'
        return result
    finally:
        os.close(config_fd)
        os.close(global_fd)


def rollback_config(root: Path) -> dict[str, object]:
    global_fd, config_fd = ensure_write_paths(root)
    try:
        usage = disk_usage_bytes(root)
        removed = unlink_exact_config(config_fd)
        if removed:
            try:
                restart_journald()
            except RetentionError:
                publish_config(config_fd)
                restart_journald()
                raise
        return payload('rollback_config', 'removed' if removed else 'absent', 'none', None, None, usage)
    finally:
        os.close(config_fd)
        os.close(global_fd)


def vacuum(root: Path) -> dict[str, object]:
    global_fd, config_fd = ensure_write_paths(root)
    try:
        status, reason, use, age = scan_configuration(root)
        if status != 'pass':
            reject(reason, 75)
        assert_effective_configuration(root)
        before = disk_usage_bytes(root)
        journalctl = command_path('JOURNALCTL', '/usr/bin/journalctl')
        run_command([journalctl, '--rotate'])
        run_command([journalctl, '--vacuum-size=1G', '--vacuum-time=30days'], timeout_seconds=300)
        after = disk_usage_bytes(root)
        if after > SYSTEM_MAX_USE_BYTES:
            reject('disk_usage_over_limit', 75)
        result = payload('vacuum', 'completed', 'none', use, age, after)
        result['disk_usage_before_bytes'] = before
        result['reclaimed_bytes'] = max(0, before - after)
        return result
    finally:
        os.close(config_fd)
        os.close(global_fd)


def main(argv: list[str]) -> int:
    if len(argv) != 2 or argv[1] not in {'inspect', 'apply_config', 'vacuum', 'rollback_config'}:
        print('ERROR: invalid journald-retention operation', file=sys.stderr)
        return 70
    try:
        root = filesystem_root()
        operation = argv[1]
        if operation == 'inspect':
            result = inspect(root)
        elif operation == 'apply_config':
            result = apply_config(root)
        elif operation == 'vacuum':
            result = vacuum(root)
        else:
            result = rollback_config(root)
        print(json.dumps(result, sort_keys=True, separators=(',', ':')))
        return 0
    except RetentionError as error:
        print(f'ERROR: {error.reason}', file=sys.stderr)
        return error.code
    except (OSError, ValueError, TypeError):
        print('ERROR: internal journald-retention failure', file=sys.stderr)
        return 70


if __name__ == '__main__':
    raise SystemExit(main(sys.argv))
