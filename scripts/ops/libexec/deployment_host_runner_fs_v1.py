#!/usr/bin/python3
"""Closed Linux filesystem primitives for DeploymentHostRunnerV1.

The PHP runner invokes this file only through the fixed isolated Python argv
documented by the host-runner contract.  No operation accepts a shell command.
"""

import errno
import fcntl
import os
import re
import secrets
import selectors
import signal
import stat
import subprocess
import sys
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
CONTROLLER_ENVIRONMENT = {"LANG": "C", "LC_ALL": "C", "PATH": "/usr/sbin:/usr/bin:/sbin:/bin"}
STATE_ROOT = "/var/lib/fh-deploy-orchestrator"


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
        r'(run\.lock|intent\.json|orchestrator-start\.json|orchestrator-finish\.json|predeploy-evidence\.json|traffic-gate-report\.json|request\.json|recovery-request\.json|execution-input\.json|recovery-execution-input\.json|state\.json|events\.jsonl|evidence\.json|operator-events\.jsonl|deploy-result\.json|deploy-child-observation\.json|deploy-timing\.jsonl|deploy-post-gate-report\.json|rollback-post-gate-report\.json|deploy-systemd-launch\.json|deploy-unit-binding\.json|deploy-unit-observation\.json|rollback-systemd-launch\.json|rollback-unit-binding\.json|rollback-unit-observation\.json)',
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
        child_prefix = ['/usr/bin/env', '-i', 'LANG=C', 'LC_ALL=C', 'PATH=/usr/sbin:/usr/bin:/sbin:/bin', '/bin/bash', '/root/deploy_ea.sh']
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
            option_names = ['--rel', '--renderer-deploy-mode', '--healthz-token-file', '--zero-surprise-dump-file', '--zero-surprise-predeploy-credentials-file', '--zero-surprise-canary-credentials-file', '--zero-surprise-incident-webhook-file', '--result-file']
            if len(tail) != 16 or tail[::2] != option_names or tail[3] not in ('host', 'external'):
                reject()
            if re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._-]{0,127}', tail[1]) is None:
                reject()
            if tail[-1] != '/var/lib/fh-deploy-orchestrator/runs/' + unit_run_id + '/deploy-result.json':
                reject()
            expected_ref_paths = [
                '/var/lib/fh-deploy-orchestrator/runs/' + unit_run_id + '/deploy-ref-healthz-token',
                tail[7],
                '/var/lib/fh-deploy-orchestrator/runs/' + unit_run_id + '/deploy-ref-predeploy-credentials',
                '/var/lib/fh-deploy-orchestrator/runs/' + unit_run_id + '/deploy-ref-canary-credentials',
                '/var/lib/fh-deploy-orchestrator/runs/' + unit_run_id + '/deploy-ref-incident-webhook',
            ]
            if tail[7] not in (
                '/var/lib/fh-deploy-orchestrator/runs/' + unit_run_id + '/deploy-ref-zero-surprise-dump.sql',
                '/var/lib/fh-deploy-orchestrator/runs/' + unit_run_id + '/deploy-ref-zero-surprise-dump.sql.gz',
            ):
                reject()
            if tail[5:14:2] != expected_ref_paths:
                reject()
            for path in tail[5::2]:
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
    if len(arguments) == 4 and arguments[1] == 'prepare-run':
        prepare_run(arguments[2], arguments[3])
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
    except (Rejected, OSError, ValueError, OverflowError, TypeError, UnicodeError):
        sys.stderr.buffer.write(FIXED_ERROR)
        raise SystemExit(EXIT_INVALID)
