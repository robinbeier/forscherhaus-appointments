#!/usr/bin/python3
"""Closed Linux filesystem primitives for DeploymentHostRunnerV1.

The PHP runner invokes this file only through the fixed isolated Python argv
documented by the host-runner contract.  No operation accepts a shell command.
"""

import errno
import base64
import fcntl
import hashlib
import json
import os
import pwd
import re
import secrets
import selectors
import signal
import stat
import subprocess
import sys
import tarfile
import time


EXIT_INVALID = 70
EXIT_CONFLICT = 75
EXIT_ABSENT = 78
MAX_TRANSFER = 1_048_576
MAX_REFERENCE_SMALL = 1_048_576
MAX_REFERENCE_DUMP = 17_179_869_184
FIXED_ERROR = b"host-runner storage rejected\n"
LOCK_FDS = (198, 199)
MAX_CHILD_OUTPUT = 65_536
MAX_TRAFFIC_REPORT = 262_144
MAX_RELEASE_UNPACKED = 68_719_476_736
RELEASE_BLOCK = 4096
RELEASE_TEMP_SCRATCH = 67_108_864
CAPACITY_DEVICE_KEYS = (
    'artifact', 'dump_pin', 'live_storage', 'release_root', 'renderer_state',
    'restore_scratch', 'stage', 'state_root', 'temp',
)
CONTROLLER_ENVIRONMENT = {"LANG": "C", "LC_ALL": "C", "PATH": "/usr/sbin:/usr/bin:/sbin:/bin"}
STATE_ROOT = "/var/lib/fh-deploy-orchestrator"
DEPLOY_CLI_TIMEOUT_SECONDS = 6300.0
OTHER_CLI_TIMEOUT_SECONDS = 2400.0
DUMP_ATTESTATION_ROOT = "/var/lib/fh-deploy-evidence/dump-attestations"


class Rejected(Exception):
    pass


class Conflict(Exception):
    pass


def reject() -> None:
    raise Rejected()


def parse_limit(raw: str) -> int:
    if not raw.isascii() or not raw.isdecimal() or raw.startswith("0") and raw != "0":
        reject()
    value = int(raw)
    if value < 1 or value > MAX_TRANSFER:
        reject()
    return value


def validate_component(value: str) -> None:
    if not value or value in (".", "..") or "/" in value or "\x00" in value:
        reject()
    if not all(character.isascii() and (character.isalnum() or character in "._-") for character in value):
        reject()


def validate_source_component(value: str) -> None:
    if not value or value in ('.', '..') or '/' in value or '\x00' in value or len(value.encode('utf-8')) > 255:
        reject()
    if any(ord(character) < 0x20 or ord(character) == 0x7f for character in value):
        reject()


def validate_storage_scope(root: str, relative: str, operation: str) -> None:
    test_root = re.fullmatch(r'/root/fh-host-runner-core-[0-9a-f]{16}', root) is not None
    if test_root:
        if operation == 'read' or (operation in ('pin', 'cow') and re.fullmatch(r'[A-Za-z0-9._-]+', relative)) or (operation == 'clear-exact' and relative == 'active-run.json'):
            return
        reject()
    if root != STATE_ROOT:
        reject()
    fixed = {"active-run.json"}
    run_leaf = re.fullmatch(
        r'runs/([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})/'
        r'(run\.lock|intent\.json|orchestrator-start\.json|orchestrator-finish\.json|predeploy-evidence\.json|traffic-gate-report\.json|request\.json|recovery-request\.json|execution-input\.json|recovery-execution-input\.json|state\.json|events\.jsonl|evidence\.json|operator-events\.jsonl|deploy-result\.json|deploy-child-observation\.json|deploy-timing\.jsonl|deploy-post-gate-report\.json|rollback-post-gate-report\.json|deploy-script\.sh|rollback-script\.sh|deploy-systemd-launch\.json|deploy-unit-binding\.json|deploy-unit-observation\.json|rollback-systemd-launch\.json|rollback-unit-binding\.json|rollback-unit-observation\.json)',
        relative,
    )
    if relative not in fixed and run_leaf is None:
        reject()
    leaf = relative.rsplit('/', 1)[-1]
    immutable = {
        'intent.json', 'orchestrator-start.json', 'orchestrator-finish.json', 'predeploy-evidence.json', 'traffic-gate-report.json',
        'request.json', 'recovery-request.json', 'execution-input.json', 'recovery-execution-input.json', 'deploy-result.json',
        'deploy-child-observation.json', 'deploy-timing.jsonl',
        'deploy-post-gate-report.json', 'rollback-post-gate-report.json',
        'deploy-script.sh', 'rollback-script.sh',
        'deploy-systemd-launch.json', 'deploy-unit-binding.json',
        'rollback-systemd-launch.json', 'rollback-unit-binding.json',
    }
    mutable = {
        'state.json', 'events.jsonl', 'evidence.json', 'operator-events.jsonl',
        'deploy-unit-observation.json', 'rollback-unit-observation.json',
    }
    if operation == 'read':
        return
    if operation == 'pin' and (relative == 'active-run.json' or leaf in immutable):
        return
    if operation == 'cow' and leaf in mutable:
        return
    if operation == 'binding-refresh' and leaf in ('deploy-unit-binding.json', 'rollback-unit-binding.json'):
        return
    if operation == 'claim-refresh' and relative == 'active-run.json':
        return
    if operation == 'clear-exact' and relative == 'active-run.json':
        return
    reject()


def validate_directory(metadata: os.stat_result, *, leaf: bool) -> None:
    if not stat.S_ISDIR(metadata.st_mode) or metadata.st_uid != 0:
        reject()
    if metadata.st_mode & 0o022:
        reject()
    if leaf and stat.S_IMODE(metadata.st_mode) != 0o700:
        reject()


def validate_regular(metadata: os.stat_result) -> None:
    if (
        not stat.S_ISREG(metadata.st_mode)
        or metadata.st_uid != 0
        or stat.S_IMODE(metadata.st_mode) != 0o600
        or metadata.st_nlink != 1
    ):
        reject()


def same_identity(left: os.stat_result, right: os.stat_result) -> bool:
    return (left.st_dev, left.st_ino, left.st_mode, left.st_uid, left.st_nlink) == (
        right.st_dev,
        right.st_ino,
        right.st_mode,
        right.st_uid,
        right.st_nlink,
    )


def same_read_snapshot(left: os.stat_result, right: os.stat_result) -> bool:
    return same_identity(left, right) and (
        left.st_size,
        left.st_mtime_ns,
        left.st_ctime_ns,
    ) == (
        right.st_size,
        right.st_mtime_ns,
        right.st_ctime_ns,
    )


def open_root(root: str) -> int:
    if not root.startswith("/") or root == "/" or root.endswith("/") or os.path.normpath(root) != root:
        reject()
    components = root[1:].split("/")
    for component in components:
        validate_component(component)

    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
    descriptor = os.open("/", flags)
    try:
        validate_directory(os.fstat(descriptor), leaf=False)
        for index, component in enumerate(components):
            before = os.stat(component, dir_fd=descriptor, follow_symlinks=False)
            validate_directory(before, leaf=index == len(components) - 1)
            child = os.open(component, flags, dir_fd=descriptor)
            after = os.fstat(child)
            if not same_identity(before, after):
                os.close(child)
                reject()
            post = os.stat(component, dir_fd=descriptor, follow_symlinks=False)
            if not same_identity(after, post):
                os.close(child)
                reject()
            os.close(descriptor)
            descriptor = child
        return descriptor
    except BaseException:
        os.close(descriptor)
        raise


def open_system_read_root(root: str) -> int:
    if not root.startswith('/') or root == '/' or root.endswith('/') or os.path.normpath(root) != root:
        reject()
    components = root[1:].split('/')
    for component in components:
        validate_component(component)
    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
    descriptor = os.open('/', flags)
    try:
        validate_directory(os.fstat(descriptor), leaf=False)
        for component in components:
            before = os.stat(component, dir_fd=descriptor, follow_symlinks=False)
            validate_directory(before, leaf=False)
            child = os.open(component, flags, dir_fd=descriptor)
            opened = os.fstat(child)
            post = os.stat(component, dir_fd=descriptor, follow_symlinks=False)
            if not same_identity(before, opened) or not same_identity(opened, post):
                os.close(child)
                reject()
            os.close(descriptor)
            descriptor = child
        return descriptor
    except BaseException:
        os.close(descriptor)
        raise


def open_parent(root: str, relative: str) -> tuple[int, str]:
    if relative.startswith("/") or relative.endswith("/"):
        reject()
    components = relative.split("/")
    for component in components:
        validate_component(component)
    parent = open_root(root)
    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
    try:
        for component in components[:-1]:
            before = os.stat(component, dir_fd=parent, follow_symlinks=False)
            validate_directory(before, leaf=True)
            child = os.open(component, flags, dir_fd=parent)
            after = os.fstat(child)
            if not same_identity(before, after):
                os.close(child)
                reject()
            post = os.stat(component, dir_fd=parent, follow_symlinks=False)
            if not same_identity(after, post):
                os.close(child)
                reject()
            os.close(parent)
            parent = child
        return parent, components[-1]
    except BaseException:
        os.close(parent)
        raise


def open_absolute_parent(path: str) -> tuple[int, str]:
    if not path.startswith('/') or path == '/' or path.endswith('/') or os.path.normpath(path) != path or len(path) > 4096:
        reject()
    components = path[1:].split('/')
    for component in components:
        validate_source_component(component)
    descriptor = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
    try:
        validate_directory(os.fstat(descriptor), leaf=False)
        flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
        for component in components[:-1]:
            before = os.stat(component, dir_fd=descriptor, follow_symlinks=False)
            validate_directory(before, leaf=False)
            child = os.open(component, flags, dir_fd=descriptor)
            opened = os.fstat(child)
            post = os.stat(component, dir_fd=descriptor, follow_symlinks=False)
            if not same_identity(before, opened) or not same_identity(opened, post):
                os.close(child)
                reject()
            os.close(descriptor)
            descriptor = child
        return descriptor, components[-1]
    except BaseException:
        os.close(descriptor)
        raise


def read_protected_absolute(path: str, limit: int) -> bytes:
    parent, leaf = open_absolute_parent(path)
    try:
        value = bounded_read_at(parent, leaf, limit)
        if value is None or value == b'' or b'\x00' in value:
            reject()
        return value
    finally:
        os.close(parent)


def read_stdin(limit: int) -> bytes:
    value = sys.stdin.buffer.read(limit + 1)
    if len(value) > limit:
        reject()
    return value


def close_inherited_descriptors() -> None:
    for entry in os.listdir('/proc/self/fd'):
        if not entry.isdecimal():
            continue
        descriptor = int(entry)
        if descriptor <= 2:
            continue
        try:
            os.close(descriptor)
        except OSError:
            pass


def bounded_read(root: str, relative: str, limit: int, *, optional: bool = False) -> bytes | None:
    parent, leaf = open_parent(root, relative)
    descriptor = -1
    try:
        try:
            before = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
        except FileNotFoundError:
            if optional:
                return None
            raise
        validate_regular(before)
        descriptor = os.open(
            leaf,
            os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC,
            dir_fd=parent,
        )
        opened = os.fstat(descriptor)
        if not same_read_snapshot(before, opened) or opened.st_size > limit:
            reject()
        chunks: list[bytes] = []
        remaining = limit + 1
        while remaining:
            chunk = os.read(descriptor, min(65536, remaining))
            if not chunk:
                break
            chunks.append(chunk)
            remaining -= len(chunk)
        value = b"".join(chunks)
        if len(value) > limit or len(value) != opened.st_size:
            reject()
        after = os.fstat(descriptor)
        post = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
        if not same_read_snapshot(opened, after) or not same_read_snapshot(after, post):
            reject()
        return value
    finally:
        if descriptor >= 0:
            os.close(descriptor)
        os.close(parent)


def bounded_read_at(parent: int, leaf: str, limit: int, *, optional: bool = False) -> bytes | None:
    descriptor = -1
    try:
        try:
            before = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
        except FileNotFoundError:
            if optional:
                return None
            raise
        validate_regular(before)
        descriptor = os.open(leaf, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=parent)
        opened = os.fstat(descriptor)
        if not same_read_snapshot(before, opened) or opened.st_size > limit:
            reject()
        chunks: list[bytes] = []
        remaining = limit + 1
        while remaining:
            chunk = os.read(descriptor, min(65536, remaining))
            if not chunk:
                break
            chunks.append(chunk)
            remaining -= len(chunk)
        value = b''.join(chunks)
        if len(value) > limit or len(value) != opened.st_size:
            reject()
        after = os.fstat(descriptor)
        post = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
        if not same_read_snapshot(opened, after) or not same_read_snapshot(after, post):
            reject()
        return value
    finally:
        if descriptor >= 0:
            os.close(descriptor)


def read_boot_id() -> bytes:
    parent = open_system_read_root('/proc/sys/kernel/random')
    descriptor = -1
    try:
        before = os.stat('boot_id', dir_fd=parent, follow_symlinks=False)
        if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1:
            reject()
        descriptor = os.open('boot_id', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=parent)
        opened = os.fstat(descriptor)
        if not same_identity(before, opened):
            reject()
        value = os.read(descriptor, 38)
        after = os.fstat(descriptor)
        post = os.stat('boot_id', dir_fd=parent, follow_symlinks=False)
        if not same_identity(opened, after) or not same_identity(after, post):
            reject()
        try:
            text = value.decode('ascii')
        except UnicodeDecodeError:
            reject()
        if re.fullmatch(r'[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\n', text) is None:
            reject()
        return value
    finally:
        if descriptor >= 0:
            os.close(descriptor)
        os.close(parent)


def write_all(descriptor: int, value: bytes) -> None:
    offset = 0
    while offset < len(value):
        written = os.write(descriptor, value[offset:])
        if written <= 0:
            reject()
        offset += written


def create_file(parent: int, leaf: str, value: bytes) -> os.stat_result:
    descriptor = os.open(
        leaf,
        os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC,
        0o600,
        dir_fd=parent,
    )
    try:
        os.fchmod(descriptor, 0o600)
        opened = os.fstat(descriptor)
        validate_regular(opened)
        write_all(descriptor, value)
        os.fsync(descriptor)
        after = os.fstat(descriptor)
        if not same_identity(opened, after):
            reject()
        return after
    finally:
        os.close(descriptor)


def pin(root: str, relative: str, value: bytes) -> None:
    parent, leaf = open_parent(root, relative)
    try:
        try:
            created = create_file(parent, leaf, value)
        except FileExistsError:
            descriptor = os.open(
                leaf,
                os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC,
                dir_fd=parent,
            )
            try:
                opened = os.fstat(descriptor)
                validate_regular(opened)
                if opened.st_size != len(value):
                    raise Conflict()
                existing = b''
                while len(existing) <= len(value):
                    chunk = os.read(descriptor, min(65536, len(value) + 1 - len(existing)))
                    if not chunk:
                        break
                    existing += chunk
                if existing != value:
                    raise Conflict()
                os.fsync(descriptor)
                after = os.fstat(descriptor)
                post = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
                if not same_read_snapshot(opened, after) or not same_read_snapshot(after, post):
                    reject()
                os.fsync(parent)
                return
            finally:
                os.close(descriptor)
        post = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
        if not same_identity(created, post):
            reject()
        os.fsync(parent)
    finally:
        os.close(parent)


REFERENCE_FIELDS = {
    'healthz-token': ('deploy-ref-healthz-token', MAX_REFERENCE_SMALL),
    'zero-surprise-dump-sql': ('deploy-ref-zero-surprise-dump.sql', MAX_REFERENCE_DUMP),
    'zero-surprise-dump-sql-gz': ('deploy-ref-zero-surprise-dump.sql.gz', MAX_REFERENCE_DUMP),
    'predeploy-credentials': ('deploy-ref-predeploy-credentials', MAX_REFERENCE_SMALL),
    'canary-credentials': ('deploy-ref-canary-credentials', MAX_REFERENCE_SMALL),
    'incident-webhook': ('deploy-ref-incident-webhook', MAX_REFERENCE_SMALL),
}


def stream_hash(descriptor: int, *, destination: int | None, limit: int) -> tuple[str, int]:
    import hashlib
    digest = hashlib.sha256()
    total = 0
    while True:
        chunk = os.read(descriptor, 65536)
        if not chunk:
            break
        total += len(chunk)
        if total > limit:
            reject()
        digest.update(chunk)
        if destination is not None:
            write_all(destination, chunk)
    return digest.hexdigest(), total


def rename_noreplace(parent: int, source: str, target: str) -> None:
    import ctypes
    import errno
    library = ctypes.CDLL(None, use_errno=True)
    function = getattr(library, 'renameat2', None)
    if function is None:
        reject()
    function.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint]
    function.restype = ctypes.c_int
    if function(parent, source.encode('ascii'), parent, target.encode('ascii'), 1) != 0:
        error = ctypes.get_errno()
        if error == errno.EEXIST:
            raise Conflict()
        raise OSError(error, 'renameat2 failed')


def pin_reference(root: str, run_id: str, field: str, source_path: str, expected_sha256: str) -> None:
    validate_run_id(run_id)
    if root != STATE_ROOT and re.fullmatch(r'/root/fh-host-runner-core-[0-9a-f]{16}', root) is None:
        reject()
    if not isinstance(source_path, str) or not isinstance(expected_sha256, str):
        reject()
    specification = REFERENCE_FIELDS.get(field)
    if specification is None or re.fullmatch(r'[0-9a-f]{64}', expected_sha256) is None:
        reject()
    target_leaf, limit = specification
    if field == 'zero-surprise-dump-sql' and not source_path.endswith('.sql'):
        reject()
    if field == 'zero-surprise-dump-sql-gz' and not source_path.endswith('.sql.gz'):
        reject()
    destination_parent = -1
    destination_parent, actual_leaf = open_parent(root, 'runs/' + run_id + '/' + target_leaf)
    if actual_leaf != target_leaf:
        reject()
    temporary_pattern = re.compile(r'^\.' + re.escape(target_leaf) + r'\.tmp-[0-9a-f]{32}$')
    orphans = [entry for entry in os.listdir(destination_parent) if temporary_pattern.fullmatch(entry)]
    if len(orphans) > 8:
        reject()
    for orphan in orphans:
        metadata = os.stat(orphan, dir_fd=destination_parent, follow_symlinks=False)
        validate_regular(metadata)
        if metadata.st_size > limit:
            reject()
        os.unlink(orphan, dir_fd=destination_parent)
    if orphans:
        os.fsync(destination_parent)
    try:
        existing = os.stat(target_leaf, dir_fd=destination_parent, follow_symlinks=False)
    except FileNotFoundError:
        existing = None
    if existing is not None:
        destination = -1
        try:
            validate_regular(existing)
            destination = os.open(target_leaf, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=destination_parent)
            destination_opened = os.fstat(destination)
            if not same_read_snapshot(existing, destination_opened):
                reject()
            destination_digest, destination_total = stream_hash(destination, destination=None, limit=limit)
            if destination_digest != expected_sha256 or destination_total != destination_opened.st_size:
                raise Conflict()
            os.fsync(destination)
            destination_after = os.fstat(destination)
            destination_post = os.stat(target_leaf, dir_fd=destination_parent, follow_symlinks=False)
            if not same_read_snapshot(destination_opened, destination_after) or not same_read_snapshot(destination_after, destination_post):
                reject()
            os.fsync(destination_parent)
            return
        finally:
            if destination >= 0:
                os.close(destination)
            os.close(destination_parent)
    source_parent, source_leaf = open_absolute_parent(source_path)
    source = -1
    destination = -1
    created: os.stat_result | None = None
    temporary: str | None = None
    published = False
    try:
        source_before = os.stat(source_leaf, dir_fd=source_parent, follow_symlinks=False)
        validate_regular(source_before)
        if source_before.st_size > limit:
            reject()
        source = os.open(
            source_leaf,
            os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC,
            dir_fd=source_parent,
        )
        source_opened = os.fstat(source)
        if not same_read_snapshot(source_before, source_opened):
            reject()
        available = os.fstatvfs(destination_parent).f_bavail * os.fstatvfs(destination_parent).f_frsize
        headroom = max(536_870_912, source_opened.st_size // 10)
        if available < source_opened.st_size + headroom:
            reject()
        try:
            existing = os.stat(target_leaf, dir_fd=destination_parent, follow_symlinks=False)
        except FileNotFoundError:
            existing = None
        if existing is None:
            temporary = '.%s.tmp-%s' % (target_leaf, secrets.token_hex(16))
            destination = os.open(
                temporary,
                os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC,
                0o600,
                dir_fd=destination_parent,
            )
            os.fchmod(destination, 0o600)
            created = os.fstat(destination)
            validate_regular(created)
            digest, total = stream_hash(source, destination=destination, limit=limit)
            if digest != expected_sha256 or total != source_opened.st_size:
                reject()
            os.fsync(destination)
            destination_after = os.fstat(destination)
            if not same_identity(created, destination_after) or destination_after.st_size != total:
                reject()
            source_after = os.fstat(source)
            source_post = os.stat(source_leaf, dir_fd=source_parent, follow_symlinks=False)
            if not same_read_snapshot(source_opened, source_after) or not same_read_snapshot(source_after, source_post):
                reject()
            os.close(destination)
            destination = -1
            temporary_post = os.stat(temporary, dir_fd=destination_parent, follow_symlinks=False)
            if not same_identity(destination_after, temporary_post):
                reject()
            rename_noreplace(destination_parent, temporary, target_leaf)
            temporary = None
            published = True
            target_post = os.stat(target_leaf, dir_fd=destination_parent, follow_symlinks=False)
            validate_regular(target_post)
            if not same_identity(destination_after, target_post):
                reject()
        else:
            validate_regular(existing)
            os.lseek(source, 0, os.SEEK_SET)
            destination = os.open(
                target_leaf,
                os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC,
                dir_fd=destination_parent,
            )
            destination_opened = os.fstat(destination)
            if not same_read_snapshot(existing, destination_opened):
                reject()
            validate_regular(destination_opened)
            source_digest, source_total = stream_hash(source, destination=None, limit=limit)
            destination_digest, destination_total = stream_hash(destination, destination=None, limit=limit)
            if (
                source_digest != expected_sha256 or destination_digest != expected_sha256 or
                source_total != source_opened.st_size or destination_total != source_total
            ):
                raise Conflict()
            source_after = os.fstat(source)
            source_post = os.stat(source_leaf, dir_fd=source_parent, follow_symlinks=False)
            if not same_read_snapshot(source_opened, source_after) or not same_read_snapshot(source_after, source_post):
                reject()
            os.fsync(destination)
            destination_after = os.fstat(destination)
            destination_post = os.stat(target_leaf, dir_fd=destination_parent, follow_symlinks=False)
            if not same_read_snapshot(destination_opened, destination_after) or not same_read_snapshot(destination_after, destination_post):
                reject()
        os.fsync(destination_parent)
    finally:
        if destination >= 0:
            os.close(destination)
        if source >= 0:
            os.close(source)
        os.close(source_parent)
        if destination_parent >= 0:
            if temporary is not None and created is not None:
                try:
                    current = os.stat(temporary, dir_fd=destination_parent, follow_symlinks=False)
                    if same_identity(created, current):
                        os.unlink(temporary, dir_fd=destination_parent)
                        os.fsync(destination_parent)
                except FileNotFoundError:
                    pass
            os.close(destination_parent)


def cow(root: str, relative: str, value: bytes) -> None:
    parent, leaf = open_parent(root, relative)
    temporary = ".%s.tmp-%s" % (leaf, secrets.token_hex(16))
    published = False
    created: os.stat_result | None = None
    try:
        try:
            existing = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
        except FileNotFoundError:
            existing = None
        if existing is not None:
            validate_regular(existing)
        created = create_file(parent, temporary, value)
        temporary_post = os.stat(temporary, dir_fd=parent, follow_symlinks=False)
        if not same_identity(created, temporary_post):
            reject()
        try:
            existing_post = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
        except FileNotFoundError:
            existing_post = None
        if (existing is None) != (existing_post is None):
            reject()
        if existing is not None and existing_post is not None and not same_identity(existing, existing_post):
            reject()
        os.replace(temporary, leaf, src_dir_fd=parent, dst_dir_fd=parent)
        published = True
        post = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
        if not same_identity(created, post):
            reject()
        os.fsync(parent)
    finally:
        if not published:
            try:
                temporary_metadata = os.stat(temporary, dir_fd=parent, follow_symlinks=False)
                if created is not None and same_identity(created, temporary_metadata):
                    os.unlink(temporary, dir_fd=parent)
                    os.fsync(parent)
            except FileNotFoundError:
                pass
        os.close(parent)


def cas_cow(root: str, relative: str, current: bytes, candidate: bytes) -> None:
    if bounded_read(root, relative, len(current)) != current:
        raise Conflict()
    cow(root, relative, candidate)


def clear_exact(root: str, relative: str, expected: bytes) -> None:
    parent, leaf = open_parent(root, relative)
    descriptor = -1
    try:
        before = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
        validate_regular(before)
        descriptor = os.open(leaf, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=parent)
        opened = os.fstat(descriptor)
        if not same_read_snapshot(before, opened) or opened.st_size != len(expected):
            raise Conflict()
        value = b''
        while len(value) <= len(expected):
            chunk = os.read(descriptor, min(65536, len(expected) + 1 - len(value)))
            if not chunk:
                break
            value += chunk
        post_fd = os.fstat(descriptor)
        post_path = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
        if value != expected or not same_read_snapshot(opened, post_fd) or not same_read_snapshot(post_fd, post_path):
            raise Conflict()
        os.unlink(leaf, dir_fd=parent)
        os.fsync(parent)
    finally:
        if descriptor >= 0:
            os.close(descriptor)
        os.close(parent)


def prepare_run(root: str, run_id: str) -> None:
    if root != STATE_ROOT and re.fullmatch(r'/root/fh-host-runner-core-[0-9a-f]{16}', root) is None:
        reject()
    validate_run_id(run_id)
    runs = open_root(root + '/runs')
    try:
        try:
            os.mkdir(run_id, 0o700, dir_fd=runs)
            os.fsync(runs)
        except FileExistsError:
            pass
        before = os.stat(run_id, dir_fd=runs, follow_symlinks=False)
        validate_directory(before, leaf=True)
        run_dir = os.open(run_id, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=runs)
        try:
            opened = os.fstat(run_dir)
            post = os.stat(run_id, dir_fd=runs, follow_symlinks=False)
            if not same_identity(before, opened) or not same_identity(opened, post):
                reject()
            try:
                lock = os.open('run.lock', os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600, dir_fd=run_dir)
                os.fchmod(lock, 0o600)
                os.fsync(lock)
                os.close(lock)
            except FileExistsError:
                metadata = os.stat('run.lock', dir_fd=run_dir, follow_symlinks=False)
                validate_regular(metadata)
                if metadata.st_size != 0:
                    reject()
            os.fsync(run_dir)
            os.fsync(runs)
        finally:
            os.close(run_dir)
    finally:
        os.close(runs)


def prepare_host(root: str) -> None:
    test_root = re.fullmatch(r'/root/fh-host-runner-core-[0-9a-f]{16}', root) is not None
    if root != STATE_ROOT and not test_root:
        reject()
    if test_root:
        state = open_root(root)
    else:
        parent = open_system_read_root('/var/lib')
        try:
            try:
                os.mkdir('fh-deploy-orchestrator', 0o700, dir_fd=parent)
                os.fsync(parent)
            except FileExistsError:
                pass
        finally:
            os.close(parent)
        state = open_root(root)
    try:
        for leaf in ('locks', 'runs'):
            try:
                os.mkdir(leaf, 0o700, dir_fd=state)
                os.fsync(state)
            except FileExistsError:
                pass
            before = os.stat(leaf, dir_fd=state, follow_symlinks=False)
            validate_directory(before, leaf=True)
            child = os.open(leaf, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=state)
            try:
                opened = os.fstat(child)
                post = os.stat(leaf, dir_fd=state, follow_symlinks=False)
                if not same_identity(before, opened) or not same_identity(opened, post):
                    reject()
                if leaf == 'locks':
                    try:
                        lock = os.open(
                            'fh-production-change.lock',
                            os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC,
                            0o600,
                            dir_fd=child,
                        )
                        os.fchmod(lock, 0o600)
                        os.fsync(lock)
                        os.close(lock)
                        os.fsync(child)
                    except FileExistsError:
                        metadata = os.stat('fh-production-change.lock', dir_fd=child, follow_symlinks=False)
                        validate_regular(metadata)
                        if metadata.st_size != 0:
                            reject()
            finally:
                os.close(child)
        os.fsync(state)
    finally:
        os.close(state)


def scan_run_ids(root: str, cursor: str) -> bytes:
    import heapq
    import json
    if root != STATE_ROOT and re.fullmatch(r'/root/fh-host-runner-core-[0-9a-f]{16}', root) is None:
        reject()
    runs = open_root(root + '/runs')
    try:
        if cursor != '-' and re.fullmatch(r'[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}', cursor) is None:
            reject()
        def eligible_entries():
            for entry in os.listdir(runs):
                if re.fullmatch(r'[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}', entry) is None:
                    reject()
                if cursor == '-' or entry > cursor:
                    yield entry
        page = heapq.nsmallest(129, eligible_entries())
        has_more = len(page) == 129
        run_ids = page[:128]
        for entry in run_ids:
            before = os.stat(entry, dir_fd=runs, follow_symlinks=False)
            validate_directory(before, leaf=True)
            directory = os.open(entry, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=runs)
            try:
                opened = os.fstat(directory)
                post = os.stat(entry, dir_fd=runs, follow_symlinks=False)
                if not same_identity(before, opened) or not same_identity(opened, post):
                    reject()
            finally:
                os.close(directory)
        encoded = json.dumps({
            'next_cursor': run_ids[-1] if has_more else None,
            'run_ids': run_ids,
        }, separators=(',', ':'), sort_keys=True).encode('ascii') + b'\n'
        if len(encoded) > 16384:
            reject()
        return encoded
    finally:
        os.close(runs)


def scan_run_bundle(root: str, run_id: str) -> bytes:
    import base64
    import json
    if root != STATE_ROOT and re.fullmatch(r'/root/fh-host-runner-core-[0-9a-f]{16}', root) is None:
        reject()
    validate_run_id(run_id)
    runs = open_root(root + '/runs')
    directory = -1
    try:
        before = os.stat(run_id, dir_fd=runs, follow_symlinks=False)
        validate_directory(before, leaf=True)
        directory = os.open(run_id, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=runs)
        opened = os.fstat(directory)
        if not same_identity(before, opened):
            reject()
        events = bounded_read_at(directory, 'events.jsonl', MAX_TRANSFER, optional=True)
        state = bounded_read_at(directory, 'state.json', 4096, optional=True)
        after = os.fstat(directory)
        post = os.stat(run_id, dir_fd=runs, follow_symlinks=False)
        if not same_identity(opened, after) or not same_identity(after, post):
            reject()
        encoded = json.dumps({
            'events_bytes': None if events is None else base64.b64encode(events).decode('ascii'),
            'run_id': run_id,
            'state_bytes': None if state is None else base64.b64encode(state).decode('ascii'),
        }, separators=(',', ':'), sort_keys=True).encode('ascii') + b'\n'
        if len(encoded) > 1_500_000:
            reject()
        return encoded
    finally:
        if directory >= 0:
            os.close(directory)
        os.close(runs)


def open_lock(root: str, relative: str) -> int:
    parent, leaf = open_parent(root, relative)
    descriptor = -1
    try:
        before = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
        validate_regular(before)
        descriptor = os.open(
            leaf,
            os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC,
            dir_fd=parent,
        )
        opened = os.fstat(descriptor)
        post = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
        if not same_identity(before, opened) or not same_identity(opened, post):
            reject()
        fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        locked = os.fstat(descriptor)
        locked_post = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
        if not same_identity(opened, locked) or not same_identity(locked, locked_post):
            reject()
        return descriptor
    except BlockingIOError as error:
        if descriptor >= 0:
            os.close(descriptor)
        raise Conflict() from error
    except BaseException:
        if descriptor >= 0:
            os.close(descriptor)
        raise
    finally:
        os.close(parent)


def validate_run_id(run_id: str) -> None:
    if len(run_id) != 36 or run_id[14] != "4" or run_id[19] not in "89ab":
        reject()
    for index, character in enumerate(run_id):
        if index in (8, 13, 18, 23):
            if character != "-":
                reject()
        elif character not in "0123456789abcdef":
            reject()


def fixed_php_probe_argv(milliseconds_raw: str) -> list[str]:
    repository = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.realpath(__file__)))))
    script = os.path.join(repository, "scripts", "ops", "deployment_host_runner_v1.php")
    return [
        "/usr/bin/php",
        script,
        "--internal-lock-probe-ms=" + milliseconds_raw,
    ]


def fixed_php_cli_argv(mode: str) -> list[str]:
    if mode not in ('validate', 'dispatch', 'probe'):
        reject()
    repository = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.realpath(__file__)))))
    script = os.path.join(repository, 'scripts', 'ops', 'deployment_host_runner_v1.php')
    return ['/usr/bin/php', script, '--internal-envelope-' + mode]


def reserve_lock_descriptors(global_lock: int, run_lock: int) -> None:
    for source, destination in zip((global_lock, run_lock), LOCK_FDS):
        if source in LOCK_FDS:
            reject()
        os.dup2(source, destination, inheritable=True)
        if not same_identity(os.fstat(source), os.fstat(destination)):
            reject()


def kill_and_reap(process: subprocess.Popen[bytes]) -> None:
    try:
        os.killpg(process.pid, signal.SIGTERM)
    except ProcessLookupError:
        pass
    try:
        process.wait(timeout=1)
    except subprocess.TimeoutExpired:
        pass
    try:
        os.killpg(process.pid, 0)
    except ProcessLookupError:
        return
    try:
        os.killpg(process.pid, signal.SIGKILL)
    except ProcessLookupError:
        pass
    try:
        process.wait(timeout=1)
    except subprocess.TimeoutExpired:
        reject()
    deadline = time.monotonic() + 1
    while time.monotonic() < deadline:
        try:
            os.killpg(process.pid, 0)
        except ProcessLookupError:
            return
        time.sleep(0.01)
    reject()


def bounded_communicate(process: subprocess.Popen[bytes], timeout_seconds: int) -> tuple[bytes, bytes]:
    if process.stdout is None or process.stderr is None:
        reject()
    selector = selectors.DefaultSelector()
    selector.register(process.stdout, selectors.EVENT_READ, 'stdout')
    selector.register(process.stderr, selectors.EVENT_READ, 'stderr')
    values = {'stdout': bytearray(), 'stderr': bytearray()}
    deadline = time.monotonic() + timeout_seconds
    try:
        while selector.get_map():
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise subprocess.TimeoutExpired('controller', timeout_seconds)
            for key, _ in selector.select(min(remaining, 0.1)):
                name = key.data
                chunk = os.read(key.fileobj.fileno(), min(65536, MAX_CHILD_OUTPUT + 1 - len(values[name])))
                if not chunk:
                    selector.unregister(key.fileobj)
                    continue
                values[name].extend(chunk)
                if len(values[name]) > MAX_CHILD_OUTPUT:
                    raise subprocess.SubprocessError('controller output exceeded the fixed bound')
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            raise subprocess.TimeoutExpired('controller', timeout_seconds)
        process.wait(timeout=remaining)
        return bytes(values['stdout']), bytes(values['stderr'])
    except (subprocess.SubprocessError, OSError):
        kill_and_reap(process)
        reject()
    finally:
        selector.close()


def relay_fixed_child(argv: list[str], timeout_seconds: float) -> int:
    process = subprocess.Popen(
        argv,
        stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        env=CONTROLLER_ENVIRONMENT,
        close_fds=True,
        pass_fds=LOCK_FDS,
        start_new_session=True,
    )
    assert process.stdout is not None
    selector = selectors.DefaultSelector()
    selector.register(process.stdout, selectors.EVENT_READ)
    output = bytearray()
    deadline = time.monotonic() + timeout_seconds
    try:
        while True:
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise subprocess.TimeoutExpired(argv[0], timeout_seconds)
            events = selector.select(min(remaining, 0.1))
            for key, _ in events:
                chunk = os.read(key.fileobj.fileno(), min(65536, MAX_CHILD_OUTPUT + 1 - len(output)))
                if chunk:
                    output.extend(chunk)
                    if len(output) > MAX_CHILD_OUTPUT:
                        raise subprocess.SubprocessError("child output exceeded the fixed bound")
                else:
                    selector.unregister(key.fileobj)
            return_code = process.poll()
            if return_code is not None and not selector.get_map():
                if return_code == 0:
                    sys.stdout.buffer.write(output)
                    sys.stdout.buffer.flush()
                return return_code
    except (subprocess.SubprocessError, OSError):
        kill_and_reap(process)
        reject()
    finally:
        selector.close()
        if process.poll() is None:
            kill_and_reap(process)


def run_fixed_child_with_input(
    argv: list[str],
    input_bytes: bytes,
    timeout_seconds: float,
    *,
    inherit_locks: bool,
) -> tuple[int, bytes]:
    process = subprocess.Popen(
        argv,
        stdin=subprocess.PIPE,
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        env=CONTROLLER_ENVIRONMENT,
        close_fds=True,
        pass_fds=LOCK_FDS if inherit_locks else (),
        start_new_session=True,
    )
    if process.stdin is None or process.stdout is None:
        kill_and_reap(process)
        reject()
    os.set_blocking(process.stdin.fileno(), False)
    os.set_blocking(process.stdout.fileno(), False)
    selector = selectors.DefaultSelector()
    selector.register(process.stdin, selectors.EVENT_WRITE, 'stdin')
    selector.register(process.stdout, selectors.EVENT_READ, 'stdout')
    written = 0
    output = bytearray()
    deadline = time.monotonic() + timeout_seconds
    try:
        while selector.get_map():
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise subprocess.TimeoutExpired(argv[0], timeout_seconds)
            for key, _ in selector.select(min(remaining, 0.1)):
                if key.data == 'stdin':
                    if written == len(input_bytes):
                        selector.unregister(process.stdin)
                        process.stdin.close()
                        continue
                    try:
                        count = os.write(process.stdin.fileno(), input_bytes[written:written + 65536])
                    except BrokenPipeError:
                        selector.unregister(process.stdin)
                        process.stdin.close()
                        continue
                    written += count
                    continue
                chunk = os.read(process.stdout.fileno(), min(65536, MAX_CHILD_OUTPUT + 1 - len(output)))
                if chunk:
                    output.extend(chunk)
                    if len(output) > MAX_CHILD_OUTPUT:
                        raise subprocess.SubprocessError('child output exceeded the fixed bound')
                else:
                    selector.unregister(process.stdout)
            if process.poll() is not None and written < len(input_bytes):
                raise subprocess.SubprocessError('child rejected its bounded input')
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            raise subprocess.TimeoutExpired(argv[0], timeout_seconds)
        process.wait(timeout=remaining)
        if written != len(input_bytes):
            reject()
        return process.returncode, bytes(output)
    except (subprocess.SubprocessError, OSError):
        kill_and_reap(process)
        reject()
    finally:
        selector.close()
        if process.poll() is None:
            kill_and_reap(process)


def cli_identity(value: bytes) -> tuple[str, str]:
    try:
        decoded = json.loads(value.decode('utf-8'))
    except (UnicodeDecodeError, json.JSONDecodeError):
        reject()
    if not isinstance(decoded, dict):
        reject()
    run_id = decoded.get('run_id')
    intent_sha256 = decoded.get('intent_sha256')
    if not isinstance(run_id, str) or not isinstance(intent_sha256, str):
        reject()
    validate_run_id(run_id)
    if re.fullmatch(r'[0-9a-f]{64}', intent_sha256) is None:
        reject()
    return run_id, intent_sha256


def build_cli_envelope(action: str, first: str, second: str) -> tuple[bytes, str, str]:
    if action not in ('deploy', 'post-gates', 'recovery', 'reconcile'):
        reject()
    request: bytes | None = None
    execution_input: bytes | None = None
    report: bytes | None = None
    if action == 'reconcile':
        run_id, intent_sha256 = first, second
        validate_run_id(run_id)
        if re.fullmatch(r'[0-9a-f]{64}', intent_sha256) is None:
            reject()
    else:
        request = read_protected_absolute(first, 16_384)
        run_id, intent_sha256 = cli_identity(request)
        candidate = read_protected_absolute(second, 16_384)
        candidate_run_id, candidate_intent_sha256 = cli_identity(candidate)
        if candidate_run_id != run_id or not secrets.compare_digest(candidate_intent_sha256, intent_sha256):
            reject()
        if action == 'post-gates':
            report = candidate
        else:
            execution_input = candidate
    envelope = (json.dumps({
        'action': action,
        'execution_input_bytes_base64': None if execution_input is None else base64.b64encode(execution_input).decode('ascii'),
        'intent_sha256': intent_sha256,
        'report_bytes_base64': None if report is None else base64.b64encode(report).decode('ascii'),
        'request_bytes_base64': None if request is None else base64.b64encode(request).decode('ascii'),
        'run_id': run_id,
    }, sort_keys=True, separators=(',', ':')) + '\n').encode('ascii')
    if len(envelope) > 65_536:
        reject()
    return envelope, run_id, intent_sha256


def supervise_cli(root: str, action: str, first: str, second: str, *, probe: bool) -> int:
    test_root = re.fullmatch(r'/root/fh-host-runner-core-[0-9a-f]{16}', root) is not None
    if (probe and not test_root) or (not probe and root != STATE_ROOT):
        reject()
    envelope, run_id, _intent_sha256 = build_cli_envelope(action, first, second)
    validation_exit, validation_output = run_fixed_child_with_input(
        fixed_php_cli_argv('validate'), envelope, 3.0, inherit_locks=False,
    )
    if validation_exit != 0 or validation_output != b'':
        reject()
    prepare_host(root)
    global_lock = open_lock(root, 'locks/fh-production-change.lock')
    try:
        prepare_run(root, run_id)
        run_lock = open_lock(root, 'runs/%s/run.lock' % run_id)
        try:
            reserve_lock_descriptors(global_lock, run_lock)
            dispatch_timeout = DEPLOY_CLI_TIMEOUT_SECONDS if action == 'deploy' else OTHER_CLI_TIMEOUT_SECONDS
            dispatch_exit, output = run_fixed_child_with_input(
                fixed_php_cli_argv('probe' if probe else 'dispatch'),
                envelope,
                3.0 if probe else dispatch_timeout,
                inherit_locks=True,
            )
            if dispatch_exit not in (0, EXIT_INVALID, EXIT_CONFLICT):
                reject()
            sys.stdout.buffer.write(output)
            sys.stdout.buffer.flush()
            return dispatch_exit
        finally:
            for descriptor in LOCK_FDS:
                try:
                    os.close(descriptor)
                except OSError:
                    pass
            os.close(run_lock)
    finally:
        os.close(global_lock)


def supervise_probe(root: str, run_id: str, milliseconds_raw: str) -> int:
    validate_run_id(run_id)
    if not milliseconds_raw.isdecimal() or milliseconds_raw.startswith("0"):
        reject()
    milliseconds = int(milliseconds_raw)
    if milliseconds < 50 or milliseconds > 5_000:
        reject()
    global_lock = open_lock(root, "locks/fh-production-change.lock")
    try:
        run_lock = open_lock(root, "runs/%s/run.lock" % run_id)
        try:
            reserve_lock_descriptors(global_lock, run_lock)
            if relay_fixed_child(fixed_php_probe_argv(milliseconds_raw), 3.0) != 0:
                reject()
            return 0
        finally:
            for descriptor in LOCK_FDS:
                try:
                    os.close(descriptor)
                except OSError:
                    pass
            os.close(run_lock)
    finally:
        os.close(global_lock)


def validate_controller_argv(argv: object) -> list[str]:
    if not isinstance(argv, list) or not argv or any(not isinstance(value, str) or not value or '\x00' in value for value in argv):
        reject()
    fixed = ['/usr/bin/env', '-i', 'LANG=C', 'LC_ALL=C', 'PATH=/usr/sbin:/usr/bin:/sbin:/bin']
    if argv[:len(fixed)] != fixed:
        reject()
    if len(argv) <= len(fixed):
        reject()
    executable = argv[len(fixed)]
    unit_pattern = re.compile(r'^fh-(deploy|rollback)-([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})-[0-9a-f]{12}\.service$')
    if executable == '/bin/systemctl':
        if len(argv) != len(fixed) + 5 or argv[len(fixed) + 1:len(fixed) + 4] != [
            'show',
            '--no-pager',
            '--property=Id,LoadState,ActiveState,SubState,Result,ExecMainCode,ExecMainStatus,InvocationID,Description,Transient,Type,RemainAfterExit,UMask,KillMode,Restart,RuntimeMaxUSec,TimeoutStopUSec,StandardInput,StandardOutput,StandardError',
        ]:
            reject()
        if unit_pattern.fullmatch(argv[-1]) is None:
            reject()
    elif executable == '/usr/bin/systemd-run':
        if argv.count('--') != 1:
            reject()
        separator = argv.index('--')
        manager = argv[len(fixed):separator]
        if len(manager) != 15 or manager[:3] != ['/usr/bin/systemd-run', '--quiet', '--expand-environment=no']:
            reject()
        unit_match = re.fullmatch(r'--unit=(.+)', manager[3])
        if unit_match is None:
            reject()
        matched_unit = unit_pattern.fullmatch(unit_match.group(1))
        if matched_unit is None:
            reject()
        action = matched_unit.group(1)
        unit_run_id = matched_unit.group(2)
        expected_runtime = '7200s' if action == 'deploy' else '1800s'
        exact_properties = [
            '--property=Type=exec', '--property=RemainAfterExit=yes', '--property=UMask=0077',
            '--property=KillMode=control-group', '--property=Restart=no',
            '--property=RuntimeMaxSec=' + expected_runtime, '--property=TimeoutStopSec=300s',
            '--property=StandardInput=null', '--property=StandardOutput=null', '--property=StandardError=null',
        ]
        if manager[4:14] != exact_properties:
            reject()
        description_prefix = '--property=Description=fh-deployment-host-runner-v1-'
        if not manager[14].startswith(description_prefix) or re.fullmatch(r'[0-9a-f]{64}', manager[14][len(description_prefix):]) is None:
            reject()
        child = argv[separator + 1:]
        child_prefix = [
            '/usr/bin/env', '-i', 'LANG=C', 'LC_ALL=C', 'PATH=/usr/sbin:/usr/bin:/sbin:/bin', '/bin/bash',
            '/var/lib/fh-deploy-orchestrator/runs/' + unit_run_id + '/' + action + '-script.sh',
        ]
        if child[:len(child_prefix)] != child_prefix:
            reject()
        tail = child[len(child_prefix):]
        if action == 'rollback':
            if len(tail) != 9 or tail[:3] != ['--runtime-config-rollback', '--active', '/var/www/html/easyappointments'] or tail[3] != '--previous' or not tail[4].startswith('/var/www/html/easyappointments_prev_') or tail[5] != '--failed' or not tail[6].startswith('/var/www/html/.fh-failed-') or tail[7:] != ['--runtime-user', 'www-data']:
                reject()
            release = tail[4][len('/var/www/html/easyappointments_prev_'):]
            if re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._-]{0,127}', release) is None or tail[6] != '/var/www/html/.fh-failed-' + unit_run_id:
                reject()
        else:
            option_names = ['--rel', '--renderer-deploy-mode', '--timing-run-id', '--healthz-token-file', '--zero-surprise-dump-file', '--zero-surprise-predeploy-credentials-file', '--zero-surprise-canary-credentials-file', '--zero-surprise-incident-webhook-file', '--result-file']
            if len(tail) != 18 or tail[::2] != option_names or tail[3] not in ('host', 'external'):
                reject()
            if re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._-]{0,127}', tail[1]) is None:
                reject()
            if (
                re.fullmatch(r'[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}', tail[5]) is None or
                tail[5] == unit_run_id
            ):
                reject()
            if tail[-1] != '/var/lib/fh-deploy-orchestrator/runs/' + unit_run_id + '/deploy-result.json':
                reject()
            expected_ref_paths = [
                '/var/lib/fh-deploy-orchestrator/runs/' + unit_run_id + '/deploy-ref-healthz-token',
                tail[9],
                '/var/lib/fh-deploy-orchestrator/runs/' + unit_run_id + '/deploy-ref-predeploy-credentials',
                '/var/lib/fh-deploy-orchestrator/runs/' + unit_run_id + '/deploy-ref-canary-credentials',
                '/var/lib/fh-deploy-orchestrator/runs/' + unit_run_id + '/deploy-ref-incident-webhook',
            ]
            if tail[9] not in (
                '/var/lib/fh-deploy-orchestrator/runs/' + unit_run_id + '/deploy-ref-zero-surprise-dump.sql',
                '/var/lib/fh-deploy-orchestrator/runs/' + unit_run_id + '/deploy-ref-zero-surprise-dump.sql.gz',
            ):
                reject()
            if tail[7:16:2] != expected_ref_paths:
                reject()
            for path in tail[7::2]:
                if not canonical_absolute_path(path):
                    reject()
    else:
        reject()
    return argv


def canonical_absolute_path(path: object) -> bool:
    if not isinstance(path, str) or not path.startswith('/') or path == '/' or path.endswith('/') or '//' in path or len(path) > 4095:
        return False
    for character in path:
        if ord(character) < 0x20 or ord(character) == 0x7f:
            return False
    return all(component not in ('', '.', '..') and len(component) <= 255 for component in path[1:].split('/'))


def controller() -> int:
    import base64
    import json
    close_inherited_descriptors()
    payload_bytes = read_stdin(131_072)
    try:
        payload = json.loads(payload_bytes.decode('utf-8'))
    except (UnicodeDecodeError, json.JSONDecodeError):
        reject()
    if not isinstance(payload, dict) or set(payload) != {'argv', 'environment', 'timeout_seconds'}:
        reject()
    argv = validate_controller_argv(payload['argv'])
    if payload['environment'] != CONTROLLER_ENVIRONMENT:
        reject()
    timeout = payload['timeout_seconds']
    expected_timeout = 30 if argv[5] == '/bin/systemctl' else 60
    if not isinstance(timeout, int) or isinstance(timeout, bool) or timeout != expected_timeout:
        reject()
    process = subprocess.Popen(
        argv,
        stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env=CONTROLLER_ENVIRONMENT,
        close_fds=True,
        start_new_session=True,
    )
    previous_term = signal.getsignal(signal.SIGTERM)
    previous_int = signal.getsignal(signal.SIGINT)

    def terminate_controller(_signum: int, _frame: object) -> None:
        kill_and_reap(process)
        raise Rejected()

    signal.signal(signal.SIGTERM, terminate_controller)
    signal.signal(signal.SIGINT, terminate_controller)
    try:
        stdout, stderr = bounded_communicate(process, timeout)
    finally:
        signal.signal(signal.SIGTERM, previous_term)
        signal.signal(signal.SIGINT, previous_int)
        if process.poll() is None:
            kill_and_reap(process)
    if b'\x00' in stdout + stderr:
        reject()
    response = {
        'exit_code': process.returncode if 0 <= process.returncode <= 255 else None,
        'stdout_base64': base64.b64encode(stdout).decode('ascii'),
        'stderr_base64': base64.b64encode(stderr).decode('ascii'),
        'transport_lost': process.returncode < 0,
    }
    sys.stdout.write(json.dumps(response, sort_keys=True, separators=(',', ':')) + '\n')
    return 0


def controller_timeout_probe(token: str) -> int:
    """Closed test-only probe for wrapper timeout and orphan cleanup."""
    if re.fullmatch(r'[0-9a-f]{32}', token) is None:
        reject()
    close_inherited_descriptors()
    script = (
        'import os,signal,sys,time;'
        'signal.signal(signal.SIGTERM,signal.SIG_IGN);'
        'pid=os.fork();'
        'sys.stdout.write("private-probe-output");sys.stdout.flush();'
        'time.sleep(10)'
    )
    process = subprocess.Popen(
        ['/usr/bin/python3', '-I', '-B', '-c', script, token],
        stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env=CONTROLLER_ENVIRONMENT,
        close_fds=True,
        start_new_session=True,
    )
    previous_term = signal.getsignal(signal.SIGTERM)
    previous_int = signal.getsignal(signal.SIGINT)

    def terminate_probe(_signum: int, _frame: object) -> None:
        kill_and_reap(process)
        raise Rejected()

    signal.signal(signal.SIGTERM, terminate_probe)
    signal.signal(signal.SIGINT, terminate_probe)
    try:
        bounded_communicate(process, 10)
    finally:
        signal.signal(signal.SIGTERM, previous_term)
        signal.signal(signal.SIGINT, previous_int)
        if process.poll() is None:
            kill_and_reap(process)
    reject()


def controller_fd_probe() -> int:
    """Closed test-only proof that controller and child inherit no lock/extra FDs."""
    close_inherited_descriptors()
    process = subprocess.Popen(
        ['/usr/bin/python3', '-I', '-B', '-c', 'import os;print(",".join(sorted(os.listdir("/proc/self/fd"))))'],
        stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env=CONTROLLER_ENVIRONMENT,
        close_fds=True,
        start_new_session=True,
    )
    stdout, stderr = bounded_communicate(process, 3)
    if process.returncode != 0 or stderr or not re.fullmatch(rb'0,1,2,3\n', stdout):
        reject()
    sys.stdout.buffer.write(stdout)
    return 0


def traffic_response(status: str, report: bytes | None, started_epoch: int, finished_epoch: int) -> bytes:
    value = {
        'bytes_base64': None if report is None else base64.b64encode(report).decode('ascii'),
        'finished_epoch': finished_epoch,
        'sha256': None if report is None else hashlib.sha256(report).hexdigest(),
        'started_epoch': started_epoch,
        'status': status,
    }
    return (json.dumps(value, sort_keys=True, separators=(',', ':')) + '\n').encode('ascii')


def collect_traffic(root: str, run_id: str, mode: str) -> bytes:
    test_root = re.fullmatch(r'/root/fh-host-runner-core-[0-9a-f]{16}', root) is not None
    if (root != STATE_ROOT and not test_root) or mode not in ('normal', 'no-business-traffic'):
        reject()
    validate_run_id(run_id)
    relative = 'runs/' + run_id + '/traffic-gate-report.json'
    if not test_root:
        validate_storage_scope(root, relative, 'pin')
    parent, leaf = open_parent(root, relative)
    temporary_pattern = re.compile(r'^\.traffic-gate-report\.json\.collect-[0-9a-f]{32}$')
    temporary: str | None = None
    started_epoch = int(time.time())
    try:
        try:
            existing = bounded_read_at(parent, leaf, MAX_TRAFFIC_REPORT, optional=True)
        except FileNotFoundError:
            existing = None
        if existing is not None:
            return traffic_response('attached', existing, started_epoch, int(time.time()))
        if test_root:
            reject()

        orphans = [entry for entry in os.listdir(parent) if temporary_pattern.fullmatch(entry)]
        if len(orphans) > 8:
            reject()
        for orphan in orphans:
            metadata = os.stat(orphan, dir_fd=parent, follow_symlinks=False)
            validate_regular(metadata)
            if metadata.st_size > MAX_TRAFFIC_REPORT:
                reject()
            os.unlink(orphan, dir_fd=parent)
        if orphans:
            os.fsync(parent)

        temporary = '.traffic-gate-report.json.collect-' + secrets.token_hex(16)
        output_path = STATE_ROOT + '/runs/' + run_id + '/' + temporary
        script = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', 'prod_traffic_gate.sh'))
        expected_script = os.path.abspath(os.path.join(os.path.dirname(__file__), '..')) + '/prod_traffic_gate.sh'
        if script != expected_script:
            reject()
        environment = dict(CONTROLLER_ENVIRONMENT)
        environment['TRAFFIC_GATE_PHP_BIN'] = '/usr/bin/php'
        process = subprocess.Popen(
            [
                '/bin/bash', script,
                '--purpose', 'deploy',
                '--mode', mode,
                '--window-seconds', '90',
                '--output-json', output_path,
            ],
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            env=environment,
            close_fds=True,
            start_new_session=True,
        )
        stdout, stderr = bounded_communicate(process, 120)
        finished_epoch = int(time.time())
        if process.returncode not in (0, 20, 21) or len(stdout) > MAX_CHILD_OUTPUT or len(stderr) > MAX_CHILD_OUTPUT:
            reject()
        report = bounded_read_at(parent, temporary, MAX_TRAFFIC_REPORT, optional=True)
        if report is None or report == b'':
            if report == b'':
                os.unlink(temporary, dir_fd=parent)
                temporary = None
                os.fsync(parent)
            if process.returncode != 21:
                reject()
            return traffic_response('not_observed', None, started_epoch, finished_epoch)

        if process.returncode in (0, 20):
            try:
                decoded = json.loads(report.decode('utf-8'))
            except (UnicodeDecodeError, json.JSONDecodeError):
                reject()
            if not isinstance(decoded, dict) or decoded.get('exit_code') != process.returncode:
                reject()
        try:
            rename_noreplace(parent, temporary, leaf)
            temporary = None
            status = 'pinned'
        except Conflict:
            existing = bounded_read_at(parent, leaf, MAX_TRAFFIC_REPORT)
            if existing != report:
                raise
            os.unlink(temporary, dir_fd=parent)
            temporary = None
            status = 'attached'
        final = bounded_read_at(parent, leaf, MAX_TRAFFIC_REPORT)
        if final != report:
            reject()
        descriptor = os.open(leaf, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=parent)
        try:
            os.fsync(descriptor)
        finally:
            os.close(descriptor)
        os.fsync(parent)
        return traffic_response(status, report, started_epoch, finished_epoch)
    finally:
        if temporary is not None:
            try:
                metadata = os.stat(temporary, dir_fd=parent, follow_symlinks=False)
                validate_regular(metadata)
                os.unlink(temporary, dir_fd=parent)
                os.fsync(parent)
            except FileNotFoundError:
                pass
        os.close(parent)


def observe_dump(root: str, run_id: str, leaf: str, expected_sha256: str) -> bytes:
    test_root = re.fullmatch(r'/root/fh-host-runner-core-[0-9a-f]{16}', root) is not None
    if (root != STATE_ROOT and not test_root) or re.fullmatch(r'[0-9a-f]{64}', expected_sha256) is None:
        reject()
    validate_run_id(run_id)
    if leaf not in ('deploy-ref-zero-surprise-dump.sql', 'deploy-ref-zero-surprise-dump.sql.gz'):
        reject()
    parent, actual_leaf = open_parent(root, 'runs/' + run_id + '/' + leaf)
    descriptor = -1
    try:
        before = os.stat(actual_leaf, dir_fd=parent, follow_symlinks=False)
        validate_regular(before)
        if before.st_size <= 0 or before.st_size > MAX_REFERENCE_DUMP:
            reject()
        descriptor = os.open(
            actual_leaf,
            os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC,
            dir_fd=parent,
        )
        opened = os.fstat(descriptor)
        if not same_read_snapshot(before, opened):
            reject()
        digest, size = stream_hash(descriptor, destination=None, limit=MAX_REFERENCE_DUMP)
        after = os.fstat(descriptor)
        post = os.stat(actual_leaf, dir_fd=parent, follow_symlinks=False)
        if digest != expected_sha256 or size != opened.st_size:
            raise Conflict()
        if not same_read_snapshot(opened, after) or not same_read_snapshot(after, post):
            reject()
    finally:
        if descriptor >= 0:
            os.close(descriptor)
        os.close(parent)

    observed_at = time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())
    attestation = None
    if not test_root:
        authority_root = open_root(DUMP_ATTESTATION_ROOT)
        try:
            attestation = bounded_read_at(
                authority_root,
                expected_sha256 + '.json',
                4096,
                optional=True,
            )
        finally:
            os.close(authority_root)
    value = {
        'attestation_bytes_base64': None if attestation is None else base64.b64encode(attestation).decode('ascii'),
        'attestation_sha256': None if attestation is None else hashlib.sha256(attestation).hexdigest(),
        'dump_sha256': expected_sha256,
        'dump_size_bytes': size,
        'observed_at_utc': observed_at,
        'status': 'not_observed' if attestation is None else 'observed',
    }
    return (json.dumps(value, sort_keys=True, separators=(',', ':')) + '\n').encode('ascii')


def hash_regular_at(parent: int, leaf: str, limit: int, allowed_modes: tuple[int, ...]) -> tuple[str, int, os.stat_result]:
    descriptor = -1
    before = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
    if (
        not stat.S_ISREG(before.st_mode) or before.st_uid != 0 or before.st_nlink != 1 or
        stat.S_IMODE(before.st_mode) not in allowed_modes or before.st_size <= 0 or before.st_size > limit
    ):
        reject()
    try:
        descriptor = os.open(leaf, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=parent)
        opened = os.fstat(descriptor)
        if not same_read_snapshot(before, opened):
            reject()
        digest, size = stream_hash(descriptor, destination=None, limit=limit)
        after = os.fstat(descriptor)
        post = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
        if not same_read_snapshot(opened, after) or not same_read_snapshot(after, post):
            reject()
        return digest, size, opened
    finally:
        if descriptor >= 0:
            os.close(descriptor)


def read_regular_at(parent: int, leaf: str, limit: int, allowed_modes: tuple[int, ...]) -> bytes:
    descriptor = -1
    before = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
    if (
        not stat.S_ISREG(before.st_mode) or before.st_uid != 0 or before.st_nlink != 1 or
        stat.S_IMODE(before.st_mode) not in allowed_modes or before.st_size <= 0 or before.st_size > limit
    ):
        reject()
    try:
        descriptor = os.open(leaf, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=parent)
        opened = os.fstat(descriptor)
        if not same_read_snapshot(before, opened):
            reject()
        chunks: list[bytes] = []
        remaining = limit + 1
        while remaining:
            chunk = os.read(descriptor, min(65536, remaining))
            if not chunk:
                break
            chunks.append(chunk)
            remaining -= len(chunk)
        value = b''.join(chunks)
        after = os.fstat(descriptor)
        post = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
        if len(value) != opened.st_size or len(value) > limit or not same_read_snapshot(opened, after) or not same_read_snapshot(after, post):
            reject()
        return value
    finally:
        if descriptor >= 0:
            os.close(descriptor)


def read_host_deploy_script(root: str) -> bytes:
    if root != '/root' and re.fullmatch(r'/root/fh-host-runner-core-[0-9a-f]{16}', root) is None:
        reject()
    parent = open_system_read_root(root)
    try:
        return read_regular_at(parent, 'deploy_ea.sh', MAX_REFERENCE_SMALL, (0o755,))
    finally:
        os.close(parent)


def observe_build(authority_root: str, release_id: str, authorized_sha256: str) -> bytes:
    test_root = re.fullmatch(r'/root/fh-host-runner-core-[0-9a-f]{16}', authority_root) is not None
    if (authority_root != '/root/releases' and not test_root):
        reject()
    if re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._-]{0,127}', release_id) is None:
        reject()
    if re.fullmatch(r'[0-9a-f]{64}', authorized_sha256) is None:
        reject()
    parent = open_root(authority_root)
    archive_descriptor = -1
    try:
        sidecar_leaf = release_id + '.build-provenance.json'
        sidecar = bounded_read_at(parent, sidecar_leaf, 4096)
        if hashlib.sha256(sidecar).hexdigest() != authorized_sha256:
            raise Conflict()
        try:
            provenance = json.loads(sidecar.decode('utf-8'))
        except (UnicodeDecodeError, json.JSONDecodeError):
            reject()
        if (
            not isinstance(provenance, dict) or
            (json.dumps(provenance, sort_keys=True, separators=(',', ':')) + '\n').encode('utf-8') != sidecar or
            provenance.get('schema') != 'release_build_provenance.v1' or
            provenance.get('release_id') != release_id
        ):
            reject()
        archive_authority = provenance.get('archive')
        if (
            not isinstance(archive_authority, dict) or
            archive_authority.get('name') != release_id + '.tar.gz' or
            not isinstance(archive_authority.get('size_bytes'), int) or archive_authority['size_bytes'] <= 0 or
            re.fullmatch(r'[0-9a-f]{64}', archive_authority.get('sha256', '')) is None
        ):
            reject()
        archive_leaf = release_id + '.tar.gz'
        archive_before = os.stat(archive_leaf, dir_fd=parent, follow_symlinks=False)
        validate_regular(archive_before)
        if archive_before.st_size != archive_authority['size_bytes'] or archive_before.st_size > MAX_REFERENCE_DUMP:
            raise Conflict()
        archive_descriptor = os.open(
            archive_leaf,
            os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC,
            dir_fd=parent,
        )
        archive_opened = os.fstat(archive_descriptor)
        if not same_read_snapshot(archive_before, archive_opened):
            reject()
        archive_sha256, archive_size = stream_hash(
            archive_descriptor,
            destination=None,
            limit=MAX_REFERENCE_DUMP,
        )
        if archive_sha256 != archive_authority['sha256'] or archive_size != archive_authority['size_bytes']:
            raise Conflict()
        os.lseek(archive_descriptor, 0, os.SEEK_SET)
        names: set[str] = set()
        entry_count = 0
        unpacked = RELEASE_BLOCK
        artifact_script_sha256 = None
        with os.fdopen(os.dup(archive_descriptor), 'rb', closefd=True) as source, tarfile.open(fileobj=source, mode='r:gz') as archive:
            for member in archive:
                name = member.name[2:] if member.name.startswith('./') else member.name
                if name in ('', '.') and member.isdir():
                    continue
                if (
                    not name or name.startswith('/') or len(name.encode('utf-8')) > 4096 or
                    name in ('.', '..') or any(part in ('', '.', '..') or len(part.encode('utf-8')) > 255 for part in name.split('/')) or
                    any(part.startswith('._') for part in name.split('/')) or name in names or
                    not (member.isfile() or member.isdir())
                ):
                    reject()
                names.add(name)
                if len(names) > 1_000_000:
                    reject()
                if member.isdir():
                    unpacked += RELEASE_BLOCK
                else:
                    entry_count += 1
                    unpacked += max(RELEASE_BLOCK, ((member.size + RELEASE_BLOCK - 1) // RELEASE_BLOCK) * RELEASE_BLOCK)
                    if unpacked > MAX_RELEASE_UNPACKED:
                        reject()
                    if name == 'deploy_ea.sh':
                        extracted = archive.extractfile(member)
                        if extracted is None:
                            reject()
                        digest = hashlib.sha256()
                        total = 0
                        while True:
                            chunk = extracted.read(65536)
                            if not chunk:
                                break
                            total += len(chunk)
                            if total > MAX_REFERENCE_SMALL:
                                reject()
                            digest.update(chunk)
                        artifact_script_sha256 = digest.hexdigest()
        archive_after = os.fstat(archive_descriptor)
        archive_post = os.stat(archive_leaf, dir_fd=parent, follow_symlinks=False)
        if not same_read_snapshot(archive_opened, archive_after) or not same_read_snapshot(archive_after, archive_post):
            reject()
        if artifact_script_sha256 is None:
            reject()
    finally:
        if archive_descriptor >= 0:
            os.close(archive_descriptor)
        os.close(parent)

    host_parent = open_system_read_root('/root' if not test_root else authority_root)
    try:
        host_sha256, _, _ = hash_regular_at(host_parent, 'deploy_ea.sh', MAX_REFERENCE_SMALL, (0o755,))
    finally:
        os.close(host_parent)
    value = {
        'archive_sha256': archive_sha256,
        'archive_size_bytes': archive_size,
        'artifact_deploy_script_sha256': artifact_script_sha256,
        'host_deploy_script_sha256': host_sha256,
        'provenance_bytes_base64': base64.b64encode(sidecar).decode('ascii'),
        'provenance_sha256': authorized_sha256,
        'stage_file_count': entry_count,
        'stage_inode_count': len(names) + 1,
        'stage_unpacked_bytes': unpacked,
        'temp_scratch_bytes': RELEASE_TEMP_SCRATCH,
    }
    return (json.dumps(value, sort_keys=True, separators=(',', ':')) + '\n').encode('ascii')


def open_capacity_directory(path: str, writable_tail: int = 0) -> int:
    if not path.startswith('/') or path == '/' or path.endswith('/') or os.path.normpath(path) != path:
        reject()
    components = path[1:].split('/')
    for component in components:
        validate_component(component)
    try:
        web_uid = pwd.getpwnam('www-data').pw_uid
    except KeyError:
        web_uid = -1
    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
    descriptor = os.open('/', flags)
    try:
        validate_directory(os.fstat(descriptor), leaf=False)
        writable_start = len(components) - writable_tail
        for index, component in enumerate(components):
            before = os.stat(component, dir_fd=descriptor, follow_symlinks=False)
            if not stat.S_ISDIR(before.st_mode) or before.st_mode & 0o022:
                reject()
            allowed_uids = (0, web_uid) if index >= writable_start else (0,)
            if before.st_uid not in allowed_uids:
                reject()
            child = os.open(component, flags, dir_fd=descriptor)
            opened = os.fstat(child)
            post = os.stat(component, dir_fd=descriptor, follow_symlinks=False)
            if not same_identity(before, opened) or not same_identity(opened, post):
                os.close(child)
                reject()
            os.close(descriptor)
            descriptor = child
        return descriptor
    except BaseException:
        os.close(descriptor)
        raise


def observe_regular_tree(root: int) -> tuple[int, int, int]:
    entry_limit = 1_000_000
    allocated = 0
    logical = 0
    inodes = 0
    try:
        web_uid = pwd.getpwnam('www-data').pw_uid
    except KeyError:
        web_uid = -1

    def walk(directory: int) -> None:
        nonlocal allocated, logical, inodes
        before = os.fstat(directory)
        if not stat.S_ISDIR(before.st_mode) or before.st_uid not in (0, web_uid) or before.st_mode & 0o022:
            reject()
        allocated += before.st_blocks * 512
        inodes += 1
        if inodes > entry_limit:
            reject()
        names = sorted(os.listdir(directory))
        for name in names:
            if name in ('', '.', '..') or '/' in name or '\x00' in name or len(name.encode('utf-8')) > 255:
                reject()
            metadata = os.stat(name, dir_fd=directory, follow_symlinks=False)
            if stat.S_ISDIR(metadata.st_mode):
                if metadata.st_uid not in (0, web_uid) or metadata.st_mode & 0o022:
                    reject()
                child = os.open(
                    name,
                    os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC,
                    dir_fd=directory,
                )
                try:
                    opened = os.fstat(child)
                    if not same_identity(metadata, opened):
                        reject()
                    walk(child)
                    post = os.stat(name, dir_fd=directory, follow_symlinks=False)
                    after = os.fstat(child)
                    if not same_read_snapshot(opened, after) or not same_read_snapshot(after, post):
                        reject()
                finally:
                    os.close(child)
                continue
            if (
                not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1 or
                metadata.st_uid not in (0, web_uid) or metadata.st_mode & 0o022
            ):
                reject()
            child = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=directory)
            try:
                opened = os.fstat(child)
                if not same_read_snapshot(metadata, opened):
                    reject()
                allocated += opened.st_blocks * 512
                logical += opened.st_size
                inodes += 1
                if inodes > entry_limit or allocated > 9_223_372_036_854_775_807 or logical > 9_223_372_036_854_775_807:
                    reject()
                after = os.fstat(child)
                post = os.stat(name, dir_fd=directory, follow_symlinks=False)
                if not same_read_snapshot(opened, after) or not same_read_snapshot(after, post):
                    reject()
            finally:
                os.close(child)
        after = os.fstat(directory)
        if not same_read_snapshot(before, after):
            reject()

    walk(root)
    return allocated, logical, inodes


def observe_capacity(authority_root: str, run_id: str, release_id: str, renderer_mode: str) -> bytes:
    test_root = re.fullmatch(r'/root/fh-host-runner-core-[0-9a-f]{16}', authority_root) is not None
    if authority_root != STATE_ROOT and not test_root:
        reject()
    validate_run_id(run_id)
    if re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._-]{0,127}', release_id) is None or renderer_mode not in ('host', 'external'):
        reject()
    if test_root:
        paths = {
            'artifact': authority_root,
            'dump_pin': authority_root + '/runs/' + run_id,
            'live_storage': authority_root + '/live-storage',
            'release_root': authority_root,
            'renderer_state': authority_root + '/renderer-state',
            'restore_scratch': authority_root + '/restore-scratch',
            'stage': authority_root + '/target',
            'state_root': authority_root,
            'temp': authority_root + '/target',
        }
        policy_root = authority_root
        writable_tails = {key: 0 for key in CAPACITY_DEVICE_KEYS}
    else:
        paths = {
            'artifact': '/root/releases',
            'dump_pin': STATE_ROOT + '/runs/' + run_id,
            'live_storage': '/var/www/html/easyappointments/storage',
            'release_root': '/root/releases',
            'renderer_state': '/var/lib/fh-pdf-renderer' if renderer_mode == 'host' else '/var/lib',
            'restore_scratch': '/var/lib/docker' if os.path.isdir('/var/lib/docker') else '/var/lib',
            'stage': '/var/www/html',
            'state_root': STATE_ROOT,
            'temp': '/var/www/html',
        }
        policy_root = '/etc/fh'
        writable_tails = {key: 0 for key in CAPACITY_DEVICE_KEYS}
        writable_tails['live_storage'] = 2
        writable_tails['renderer_state'] = 1 if renderer_mode == 'host' else 0

    descriptors: dict[str, int] = {}
    try:
        for key in CAPACITY_DEVICE_KEYS:
            descriptors[key] = open_capacity_directory(paths[key], writable_tails[key])
        target = descriptors['stage']
        snapshot = os.fstatvfs(target)
        if (
            snapshot.f_frsize <= 0 or snapshot.f_blocks <= 0 or snapshot.f_bavail < 0 or
            snapshot.f_bavail > snapshot.f_blocks or snapshot.f_files <= 0 or
            snapshot.f_favail < 0 or snapshot.f_favail > snapshot.f_files
        ):
            reject()
        filesystem_device = os.fstat(target).st_dev
        devices = {key: os.fstat(descriptors[key]).st_dev for key in CAPACITY_DEVICE_KEYS}
        if any(device != filesystem_device for device in devices.values()):
            reject()
        live_allocated, live_logical, live_inodes = observe_regular_tree(descriptors['live_storage'])
        if live_allocated <= 0 or live_logical <= 0 or live_inodes <= 0:
            reject()
        policy_parent = open_system_read_root(policy_root)
        try:
            policy = bounded_read_at(policy_parent, 'deployment-renderer-capacity-v1.json', 4096)
        finally:
            os.close(policy_parent)
    finally:
        for descriptor in descriptors.values():
            os.close(descriptor)

    value = {
        'block_size': snapshot.f_frsize,
        'blocks': snapshot.f_blocks,
        'blocks_available': snapshot.f_bavail,
        'component_devices': devices,
        'filesystem_device': filesystem_device,
        'inodes': snapshot.f_files,
        'inodes_available': snapshot.f_favail,
        'live_storage_allocated_bytes': live_allocated,
        'live_storage_inode_count': live_inodes,
        'live_storage_logical_bytes': live_logical,
        'policy_bytes_base64': base64.b64encode(policy).decode('ascii'),
    }
    return (json.dumps(value, sort_keys=True, separators=(',', ':')) + '\n').encode('ascii')


def validate_storage_scope_probe(root: str, relative: str, operation: str) -> int:
    validate_storage_scope(root, relative, operation)
    return 0


def main(arguments: list[str]) -> int:
    if len(arguments) < 2 or arguments[1] != 'probe-locks':
        close_inherited_descriptors()
    if len(arguments) == 3 and arguments[1] == 'validate-controller-payload':
        import json
        try:
            payload = json.loads(arguments[2])
        except json.JSONDecodeError:
            reject()
        if not isinstance(payload, dict) or set(payload) != {'argv', 'environment', 'timeout_seconds'}:
            reject()
        argv = validate_controller_argv(payload['argv'])
        if payload['environment'] != CONTROLLER_ENVIRONMENT:
            reject()
        expected_timeout = 30 if argv[5] == '/bin/systemctl' else 60
        if payload['timeout_seconds'] != expected_timeout:
            reject()
        return 0
    if len(arguments) == 2 and arguments[1] == 'controller':
        return controller()
    if len(arguments) == 2 and arguments[1] == 'read-boot-id':
        sys.stdout.buffer.write(read_boot_id())
        return 0
    if len(arguments) == 3 and arguments[1] == 'controller-timeout-probe':
        return controller_timeout_probe(arguments[2])
    if len(arguments) == 2 and arguments[1] == 'controller-fd-probe':
        return controller_fd_probe()
    if len(arguments) == 5 and arguments[1] == 'validate-storage-scope':
        return validate_storage_scope_probe(arguments[2], arguments[3], arguments[4])
    if len(arguments) == 5 and arguments[1] == 'collect-traffic':
        sys.stdout.buffer.write(collect_traffic(arguments[2], arguments[3], arguments[4]))
        return 0
    if len(arguments) == 6 and arguments[1] == 'observe-dump':
        sys.stdout.buffer.write(observe_dump(arguments[2], arguments[3], arguments[4], arguments[5]))
        return 0
    if len(arguments) == 5 and arguments[1] == 'observe-build':
        sys.stdout.buffer.write(observe_build(arguments[2], arguments[3], arguments[4]))
        return 0
    if len(arguments) == 3 and arguments[1] == 'read-host-deploy-script':
        sys.stdout.buffer.write(read_host_deploy_script(arguments[2]))
        return 0
    if len(arguments) == 6 and arguments[1] == 'observe-capacity':
        sys.stdout.buffer.write(observe_capacity(arguments[2], arguments[3], arguments[4], arguments[5]))
        return 0
    if len(arguments) == 4 and arguments[1] == 'prepare-run':
        prepare_run(arguments[2], arguments[3])
        return 0
    if len(arguments) == 3 and arguments[1] == 'prepare-host':
        prepare_host(arguments[2])
        return 0
    if len(arguments) == 4 and arguments[1] == 'scan-run-ids':
        sys.stdout.buffer.write(scan_run_ids(arguments[2], arguments[3]))
        return 0
    if len(arguments) == 4 and arguments[1] == 'scan-run-bundle':
        sys.stdout.buffer.write(scan_run_bundle(arguments[2], arguments[3]))
        return 0
    if len(arguments) == 5 and arguments[1] == 'pin-reference':
        import json
        payload = json.loads(read_stdin(8192).decode('utf-8'))
        if not isinstance(payload, dict) or set(payload) != {'source_path', 'sha256'}:
            reject()
        pin_reference(arguments[2], arguments[3], arguments[4], payload['source_path'], payload['sha256'])
        return 0
    if len(arguments) == 5 and arguments[1] == 'binding-refresh':
        import base64, json
        validate_storage_scope(arguments[2], arguments[3], 'binding-refresh')
        payload = json.loads(read_stdin(MAX_TRANSFER).decode('ascii'))
        if not isinstance(payload, dict) or set(payload) != {'current', 'candidate'}:
            reject()
        current = base64.b64decode(payload['current'], validate=True)
        candidate = base64.b64decode(payload['candidate'], validate=True)
        cas_cow(arguments[2], arguments[3], current, candidate)
        return 0
    if len(arguments) == 5 and arguments[1] == 'claim-refresh':
        import base64, json
        validate_storage_scope(arguments[2], arguments[3], 'claim-refresh')
        payload = json.loads(read_stdin(MAX_TRANSFER).decode('ascii'))
        if not isinstance(payload, dict) or set(payload) != {'current', 'candidate'}:
            reject()
        current = base64.b64decode(payload['current'], validate=True)
        candidate = base64.b64decode(payload['candidate'], validate=True)
        cas_cow(arguments[2], arguments[3], current, candidate)
        return 0
    if len(arguments) == 5 and arguments[1] == 'clear-exact':
        validate_storage_scope(arguments[2], arguments[3], 'clear-exact')
        clear_exact(arguments[2], arguments[3], read_stdin(parse_limit(arguments[4])))
        return 0
    if len(arguments) == 5 and arguments[1] == "probe-locks":
        return supervise_probe(arguments[2], arguments[3], arguments[4])
    if len(arguments) == 6 and arguments[1] == 'supervise-cli-probe':
        return supervise_cli(arguments[2], arguments[3], arguments[4], arguments[5], probe=True)
    if len(arguments) == 6 and arguments[1] == 'supervise-cli':
        return supervise_cli(arguments[2], arguments[3], arguments[4], arguments[5], probe=False)
    if len(arguments) != 5 or arguments[1] not in ("read", "read-optional", "pin", "cow"):
        reject()
    operation, root, relative, raw_limit = arguments[1:]
    validate_storage_scope(root, relative, 'read' if operation == 'read-optional' else operation)
    limit = parse_limit(raw_limit)
    if operation in ("read", "read-optional"):
        value = bounded_read(root, relative, limit, optional=operation == 'read-optional')
        if value is None:
            return EXIT_ABSENT
        sys.stdout.buffer.write(value)
        return 0
    value = read_stdin(limit)
    if operation == "pin":
        pin(root, relative, value)
    else:
        cow(root, relative, value)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main(sys.argv))
    except Conflict:
        sys.stderr.buffer.write(FIXED_ERROR)
        raise SystemExit(EXIT_CONFLICT)
    except (Rejected, OSError, ValueError, OverflowError, TypeError, UnicodeError, tarfile.TarError):
        sys.stderr.buffer.write(FIXED_ERROR)
        raise SystemExit(EXIT_INVALID)
