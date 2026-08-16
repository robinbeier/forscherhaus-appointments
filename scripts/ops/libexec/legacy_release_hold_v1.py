#!/usr/bin/python3
"""Read-only inspection and one-shot provisioning of the legacy release hold."""

import fcntl
import hashlib
import json
import os
import re
import secrets
import stat
import sys
import tarfile
import ctypes
import errno

RESULT_SCHEMA = 'legacy_release_hold_result.v1'
HOLD_SCHEMA = 'legacy_release_hold.v1'
HOLD_PATH = '/etc/fh/legacy-release-hold.v1.json'
WEB_ROOT = '/var/www/html/easyappointments'
RELEASES_ROOT = '/root/releases'
CURRENT_MARKER = '_RELEASE'
ROLLBACK_PREFIX = '/var/www/html/easyappointments_prev_'
TOKEN = 'ROB-470-HOLD'
GLOBAL_LOCK = '/var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock'
PUBLICATION_LOCK = '/root/releases/.release-pair.lock'
MAX_ARCHIVE_BYTES = 16 * 1024 * 1024 * 1024
MAX_ENTRIES = 1_000_000
MAX_UNPACKED = 68_719_476_736
BLOCK_BYTES = 4096
MAX_MEMBER = 16 * 1024 * 1024
TEMP_SCRATCH_BYTES = 67_108_864
MAX_HELPER_TEMPS = 32
RENAME_NOREPLACE = 1
MAX_STAGE_FILE_COUNT = MAX_ENTRIES
# The staging root is an inode in addition to the archive's materialized
# entries.  Keep this decode limit aligned with tar_bounds().
MAX_STAGE_INODE_COUNT = MAX_ENTRIES + 1
TEMP_PREFIX = '.legacy-release-hold.v1.json.rob470-'
TEMP_PATTERN = re.compile(r'\.legacy-release-hold\.v1\.json\.rob470-[0-9a-f]{32}\.tmp\Z')
RID = re.compile(r'[A-Za-z0-9][A-Za-z0-9._-]{0,127}\Z')
SHA = re.compile(r'[0-9a-f]{64}\Z')
NONCE = re.compile(r'[0-9a-f]{32}\Z')

class MutationLedger:
    def __init__(self):
        self.counts = {'hold_published': 0, 'temp_files_created': 0, 'temp_files_removed': 0}
        self.in_flight = 0

    def begin(self):
        self.in_flight += 1

    def confirm(self, key):
        if key not in self.counts or self.in_flight < 1:
            reject('mutation_ledger_invalid')
        self.in_flight -= 1
        self.counts[key] += 1

    def cancel(self):
        if self.in_flight < 1:
            reject('mutation_ledger_invalid')
        self.in_flight -= 1

    def fields(self):
        return self.counts.copy(), ('unknown' if self.in_flight else ('known' if sum(self.counts.values()) else 'none'))


LEDGER = MutationLedger()


class HoldError(Exception):
    def __init__(self, reason, code=70):
        super().__init__(reason)
        self.reason, self.code = reason, code


def reject(reason='host_contract_invalid', code=70):
    raise HoldError(reason, code)


def canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(',', ':')) + '\n').encode()


def emit(value):
    print(json.dumps(value, sort_keys=True, separators=(',', ':')), flush=True)


def file_identity(value):
    return (
        value.st_dev,
        value.st_ino,
        value.st_mode,
        value.st_uid,
        value.st_gid,
        value.st_nlink,
        value.st_size,
        value.st_mtime_ns,
        value.st_ctime_ns,
    )


def attach_identity(value):
    return (
        value.st_dev,
        value.st_ino,
        value.st_mode,
        value.st_uid,
        value.st_gid,
        value.st_nlink,
        value.st_size,
    )


def open_stable_regular(path, maximum, modes=(0o600,), minimum=1, allow_missing=False, empty_ok=False):
    try:
        before = os.stat(path, follow_symlinks=False)
    except FileNotFoundError:
        if allow_missing:
            return None
        reject('missing_required_file')
    if (
        not stat.S_ISREG(before.st_mode)
        or before.st_uid != 0
        or before.st_gid != 0
        or stat.S_IMODE(before.st_mode) not in modes
        or before.st_nlink != 1
        or (not empty_ok and before.st_size < minimum)
        or before.st_size > maximum
    ):
        reject('unsafe_file')
    descriptor = os.open(path, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW)
    try:
        opened = os.fstat(descriptor)
        if file_identity(before) != file_identity(opened):
            reject('file_changed', 75)
        return {'fd': descriptor, 'path': path, 'identity': file_identity(opened)}
    except BaseException:
        os.close(descriptor)
        raise


def open_stable_regular_at(parent_fd, name, maximum, modes=(0o600,), minimum=1, allow_missing=False, empty_ok=False):
    try:
        before = os.stat(name, dir_fd=parent_fd, follow_symlinks=False)
    except FileNotFoundError:
        if allow_missing:
            return None
        reject('missing_required_file')
    if (
        not stat.S_ISREG(before.st_mode)
        or before.st_uid != 0
        or before.st_gid != 0
        or stat.S_IMODE(before.st_mode) not in modes
        or before.st_nlink != 1
        or (not empty_ok and before.st_size < minimum)
        or before.st_size > maximum
    ):
        reject('unsafe_file')
    descriptor = os.open(name, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW, dir_fd=parent_fd)
    try:
        opened = os.fstat(descriptor)
        if file_identity(before) != file_identity(opened):
            reject('file_changed', 75)
        return {
            'dir_fd': parent_fd,
            'fd': descriptor,
            'identity': file_identity(opened),
            'name': name,
        }
    except BaseException:
        os.close(descriptor)
        raise


def ensure_file_stable(record):
    opened = os.fstat(record['fd'])
    if 'dir_fd' in record:
        current = os.stat(record['name'], dir_fd=record['dir_fd'], follow_symlinks=False)
    else:
        current = os.stat(record['path'], follow_symlinks=False)
    if record['identity'] != file_identity(opened) or record['identity'] != file_identity(current):
        reject('file_changed', 75)


def close_record(record):
    if record is None:
        return
    try:
        os.close(record['fd'])
    except OSError:
        pass


def read_all(record, maximum):
    os.lseek(record['fd'], 0, os.SEEK_SET)
    chunks = []
    total = 0
    while total <= maximum:
        chunk = os.read(record['fd'], min(65_536, maximum + 1 - total))
        if not chunk:
            break
        total += len(chunk)
        chunks.append(chunk)
    data = b''.join(chunks)
    ensure_file_stable(record)
    if len(data) != record['identity'][6]:
        reject('file_changed', 75)
    return data


def digest_fd(fd):
    os.lseek(fd, 0, os.SEEK_SET)
    digest = hashlib.sha256()
    while True:
        chunk = os.read(fd, 1024 * 1024)
        if not chunk:
            return digest.hexdigest()
        digest.update(chunk)


def safe_file(path, maximum, modes=(0o600,), allow_missing=False):
    record = open_stable_regular(path, maximum, modes=modes, allow_missing=allow_missing)
    if record is None:
        return None
    try:
        return read_all(record, maximum), os.fstat(record['fd'])
    finally:
        close_record(record)


def safe_file_at(parent_fd, name, maximum, modes=(0o600,), allow_missing=False):
    record = open_stable_regular_at(
        parent_fd,
        name,
        maximum,
        modes=modes,
        allow_missing=allow_missing,
    )
    if record is None:
        return None
    try:
        return read_all(record, maximum), os.fstat(record['fd'])
    finally:
        close_record(record)


def ensure_directory_stable(path, descriptor, expected):
    opened = os.fstat(descriptor)
    current = os.stat(path, follow_symlinks=False)
    fields = ('st_dev', 'st_ino', 'st_mode', 'st_uid', 'st_gid')
    if any(getattr(opened, field) != getattr(expected, field) for field in fields) or any(
        getattr(current, field) != getattr(expected, field) for field in fields
    ):
        reject('hold_parent_changed', 75)


def marker(path):
    record = open_stable_regular(path, 512, modes=(0o600, 0o640, 0o644))
    try:
        value = read_all(record, 512).decode('ascii').split()[0]
    except (UnicodeDecodeError, IndexError):
        reject('invalid_release_marker')
    finally:
        close_record(record)
    if RID.fullmatch(value) is None:
        reject('invalid_release_marker')
    return value


def normalize_member_name(name):
    normalized = name[2:] if name.startswith('./') else name
    parts = normalized.split('/')
    if (
        not normalized
        or normalized.startswith('/')
        or len(normalized.encode('utf-8')) > 4096
        or '\x00' in normalized
        or '\\' in normalized
        or any(
            part in ('', '.', '..')
            or part.startswith('._')
            or len(part.encode('utf-8')) > 255
            for part in parts
        )
        or any(ord(character) < 32 or ord(character) == 127 for character in normalized)
    ):
        reject('unsafe_tar_member')
    return normalized


def tar_bounds(record):
    # digest_fd() consumes the shared stable descriptor.  Rewind that same
    # descriptor before duplicating it for tarfile so hashing and scanning
    # always inspect the complete archive.
    os.lseek(record['fd'], 0, os.SEEK_SET)
    entries = file_count = 0
    unpacked = BLOCK_BYTES
    stage_types = {}
    try:
        with os.fdopen(os.dup(record['fd']), 'rb', closefd=True) as source:
            with tarfile.open(fileobj=source, mode='r:gz') as archive:
                for member in archive:
                    entries += 1
                    if entries > MAX_ENTRIES:
                        reject('tar_entry_limit')
                    if member.name in ('.', './') and member.isdir():
                        continue
                    name = normalize_member_name(member.name)
                    if member.issym() or member.islnk() or member.isdev() or not (member.isfile() or member.isdir()):
                        reject('unsafe_tar_member')
                    if name in stage_types:
                        reject('unsafe_tar_member')
                    parts = name.split('/')
                    for index in range(1, len(parts)):
                        parent = '/'.join(parts[:index])
                        if parent in stage_types:
                            if stage_types[parent] == 'file':
                                reject('unsafe_tar_member')
                            continue
                        stage_types[parent] = 'directory'
                        if len(stage_types) > MAX_ENTRIES:
                            reject('tar_entry_limit')
                        unpacked += BLOCK_BYTES
                        if unpacked > MAX_UNPACKED:
                            reject('tar_unpacked_limit')
                    member_type = 'directory' if member.isdir() else 'file'
                    if name not in stage_types:
                        stage_types[name] = member_type
                        if len(stage_types) > MAX_ENTRIES:
                            reject('tar_entry_limit')
                        if member.isdir():
                            unpacked += BLOCK_BYTES
                            if unpacked > MAX_UNPACKED:
                                reject('tar_unpacked_limit')
                    if member.isfile():
                        if member.size < 0 or member.size > MAX_MEMBER:
                            reject('tar_unpacked_limit')
                        file_count += 1
                        unpacked += max(BLOCK_BYTES, ((member.size + BLOCK_BYTES - 1) // BLOCK_BYTES) * BLOCK_BYTES)
                        if unpacked > MAX_UNPACKED:
                            reject('tar_unpacked_limit')
    except HoldError:
        raise
    except (OSError, EOFError, tarfile.TarError, ValueError):
        reject('unsafe_tar')
    ensure_file_stable(record)
    return {
        'stage_file_count': file_count,
        'stage_inode_count': len(stage_types) + 1,
        'stage_unpacked_bytes': unpacked,
        'temp_scratch_bytes': TEMP_SCRATCH_BYTES,
    }


def rename_noreplace(parent_fd, source, target):
    """Atomically attach within one trusted directory, never replacing target."""
    libc = ctypes.CDLL(None, use_errno=True)
    native = getattr(libc, 'renameat2', None)
    if native is not None:
        native.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint]
        native.restype = ctypes.c_int
        result = native(parent_fd, source.encode(), parent_fd, target.encode(), RENAME_NOREPLACE)
        if result == 0:
            return
        error = ctypes.get_errno()
        if error == errno.EEXIST:
            raise FileExistsError(target)
        if error != errno.ENOSYS:
            raise OSError(error, os.strerror(error))
    syscall = getattr(libc, 'syscall', None)
    if syscall is None:
        reject('renameat2_unavailable')
    # Linux x86_64 and arm64 both expose renameat2 as syscall 316/276;
    # Python production hosts are x86_64, while tests may monkeypatch this call.
    number = 316 if os.uname().machine == 'x86_64' else 276
    result = syscall(number, parent_fd, source.encode(), parent_fd, target.encode(), RENAME_NOREPLACE)
    if result != 0:
        error = ctypes.get_errno()
        if error == errno.ENOSYS:
            reject('renameat2_unavailable')
        if error == errno.EEXIST:
            raise FileExistsError(target)
        raise OSError(error, os.strerror(error))


def enumerate_temps(parent_fd):
    count = 0
    for name in os.listdir(parent_fd):
        if name.startswith('.legacy-release-hold.v1.json') and not TEMP_PATTERN.fullmatch(name):
            reject('foreign_helper_temp')
        if TEMP_PATTERN.fullmatch(name):
            count += 1
            if count > MAX_HELPER_TEMPS:
                reject('helper_temp_limit')
            yield name


def unlink_owned_temp(parent_fd, name, expected):
    descriptor = os.open(name, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW, dir_fd=parent_fd)
    try:
        current = os.fstat(descriptor)
        path_stat = os.stat(name, dir_fd=parent_fd, follow_symlinks=False)
        if file_identity(current) != expected or file_identity(path_stat) != expected:
            reject('temp_changed', 75)
        LEDGER.begin()
        os.unlink(name, dir_fd=parent_fd)
        if os.fstat(descriptor).st_nlink != 0:
            reject('temp_changed', 75)
        LEDGER.confirm('temp_files_removed')
        os.fsync(parent_fd)
    finally:
        os.close(descriptor)


def archive(path, release_id):
    if os.path.basename(path) != release_id + '.tar.gz':
        reject('archive_identity_mismatch')
    record = open_stable_regular(path, MAX_ARCHIVE_BYTES, minimum=1)
    try:
        digest = digest_fd(record['fd'])
        bounds = tar_bounds(record)
        ensure_file_stable(record)
        return digest, record['identity'][6], bounds
    finally:
        close_record(record)


def target(role, release_id):
    path = os.path.join(RELEASES_ROOT, release_id + '.tar.gz')
    digest, size, bounds = archive(path, release_id)
    return {
        'archive': {'name': release_id + '.tar.gz', 'sha256': digest, 'size_bytes': size},
        'capacity_bounds': bounds,
        'release_id': release_id,
        'role_at_provisioning': role,
    }


def preflight():
    current = marker(os.path.join(WEB_ROOT, CURRENT_MARKER))
    rollback = marker(ROLLBACK_PREFIX + current + '/' + CURRENT_MARKER)
    if current == rollback:
        reject('duplicate_release_ids')
    return [target('current', current), target('rollback', rollback)]


def decode_hold(data):
    if data is None:
        return None
    try:
        value = json.loads(data.decode('utf-8'))
    except (UnicodeDecodeError, json.JSONDecodeError):
        reject('invalid_hold')
    if canonical(value) != data or not isinstance(value, dict):
        reject('invalid_hold')
    if (
        set(value) != {'schema', 'targets'}
        or value.get('schema') != HOLD_SCHEMA
        or not isinstance(value.get('targets'), list)
        or len(value['targets']) != 2
    ):
        reject('invalid_hold')
    expected = ('current', 'rollback')
    for item, role in zip(value['targets'], expected):
        if set(item) != {'archive', 'capacity_bounds', 'release_id', 'role_at_provisioning'} or item.get('role_at_provisioning') != role:
            reject('invalid_hold')
        archive_meta = item['archive']
        bounds = item['capacity_bounds']
        if (
            set(archive_meta) != {'name', 'sha256', 'size_bytes'}
            or archive_meta['name'] != item['release_id'] + '.tar.gz'
            or SHA.fullmatch(str(archive_meta['sha256'])) is None
            or isinstance(archive_meta['size_bytes'], bool)
            or not isinstance(archive_meta['size_bytes'], int)
            or archive_meta['size_bytes'] <= 0
            or archive_meta['size_bytes'] > MAX_ARCHIVE_BYTES
            or set(bounds) != {'stage_file_count', 'stage_inode_count', 'stage_unpacked_bytes', 'temp_scratch_bytes'}
            or any(isinstance(bounds[field], bool) or not isinstance(bounds[field], int) or bounds[field] <= 0 for field in bounds)
            or bounds['stage_file_count'] > MAX_STAGE_FILE_COUNT
            or bounds['stage_inode_count'] > MAX_STAGE_INODE_COUNT
            or bounds['stage_unpacked_bytes'] > MAX_UNPACKED
            or bounds['temp_scratch_bytes'] != TEMP_SCRATCH_BYTES
            or RID.fullmatch(item['release_id'] or '') is None
        ):
            reject('invalid_hold')
    if value['targets'][0]['release_id'] == value['targets'][1]['release_id']:
        reject('invalid_hold')
    return value


def hold_bytes(targets):
    return canonical({'schema': HOLD_SCHEMA, 'targets': targets})


def open_lock(path):
    record = open_stable_regular(path, 0, minimum=0, empty_ok=True)
    if record['identity'][6] != 0:
        close_record(record)
        reject('unsafe_file')
    return record


def write_all(fd, data):
    offset = 0
    while offset < len(data):
        written = os.write(fd, data[offset:])
        if written <= 0:
            reject('write_failed')
        offset += written


def provision(targets):
    parent = os.path.dirname(HOLD_PATH)
    try:
        parent_meta = os.stat(parent, follow_symlinks=False)
    except FileNotFoundError:
        reject('hold_parent_missing')
    if (
        not stat.S_ISDIR(parent_meta.st_mode)
        or parent_meta.st_uid != 0
        or parent_meta.st_gid != 0
        or stat.S_IMODE(parent_meta.st_mode) != 0o700
    ):
        reject('unsafe_hold_parent')
    parent_fd = os.open(parent, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW)
    opened_parent = os.fstat(parent_fd)
    if (
        opened_parent.st_dev != parent_meta.st_dev
        or opened_parent.st_ino != parent_meta.st_ino
        or opened_parent.st_mode != parent_meta.st_mode
        or opened_parent.st_uid != parent_meta.st_uid
        or opened_parent.st_gid != parent_meta.st_gid
    ):
        os.close(parent_fd)
        reject('hold_parent_changed', 75)
    locks = []
    try:
        list(enumerate_temps(parent_fd))
        for path in (GLOBAL_LOCK, PUBLICATION_LOCK):
            record = open_lock(path)
            try:
                fcntl.flock(record['fd'], fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                close_record(record)
                reject('lock_busy', 75)
            ensure_file_stable(record)
            locks.append(record)
        data = hold_bytes(targets)
        # Revalidate all fixed facts after lock acquisition and before mutation.
        if hold_bytes(preflight()) != data:
            reject('preflight_changed', 75)
        matching_temps = []
        for candidate in enumerate_temps(parent_fd):
            descriptor = os.open(candidate, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW, dir_fd=parent_fd)
            try:
                metadata = os.fstat(descriptor)
                if (not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != 0 or metadata.st_gid != 0
                        or stat.S_IMODE(metadata.st_mode) != 0o600 or metadata.st_nlink != 1
                        or metadata.st_size != len(data)):
                    reject('unsafe_helper_temp')
                candidate_data = os.read(descriptor, len(data) + 1)
                if candidate_data != data:
                    reject('foreign_helper_temp')
                matching_temps.append((candidate, file_identity(metadata)))
            finally:
                os.close(descriptor)
        matching_temp = matching_temps[0][0] if matching_temps else None
        existing = safe_file_at(parent_fd, os.path.basename(HOLD_PATH), 64 * 1024, allow_missing=True)
        ensure_directory_stable(parent, parent_fd, parent_meta)
        if existing is not None:
            if existing[0] == data:
                for stale_name, stale_identity in matching_temps:
                    unlink_owned_temp(parent_fd, stale_name, stale_identity)
                ensure_directory_stable(parent, parent_fd, parent_meta)
                for record in reversed(locks):
                    fcntl.flock(record['fd'], fcntl.LOCK_UN)
                    close_record(record)
                os.close(parent_fd)
                return {'published': 0, 'existing': 1}
            reject('hold_conflict')
        for stale_name, stale_identity in matching_temps[1:]:
            unlink_owned_temp(parent_fd, stale_name, stale_identity)
    except FileNotFoundError:
        reject('lock_missing')
    temp = TEMP_PREFIX + secrets.token_hex(16) + '.tmp'
    temp_identity = None
    fd = -1
    try:
        if matching_temp is None:
            flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | os.O_NOFOLLOW
            LEDGER.begin()
            fd = os.open(temp, flags, 0o600, dir_fd=parent_fd)
            # Bind the trusted descriptor/path identity immediately after
            # O_EXCL creation.  If writing or fsync fails, the outer finally
            # can remove only this exact file, without an unsafe name-only
            # cleanup window.
            temp_identity = file_identity(os.fstat(fd))
            LEDGER.confirm('temp_files_created')
            try:
                write_all(fd, data)
                os.fsync(fd)
            except BaseException:
                # A partial write changes size and timestamps.  Refresh the
                # descriptor-bound identity before the outer cleanup closes
                # the descriptor, so unlink_owned_temp can still prove that
                # this is exactly the O_EXCL-created file.
                temp_identity = file_identity(os.fstat(fd))
                raise
            temp_identity = file_identity(os.fstat(fd))
            os.close(fd)
            fd = -1
        else:
            temp = matching_temp
            temp_identity = next(item[1] for item in matching_temps if item[0] == matching_temp)
        ensure_directory_stable(parent, parent_fd, parent_meta)
        source_descriptor = os.open(temp, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW, dir_fd=parent_fd)
        try:
            source = os.fstat(source_descriptor)
            source_path = os.stat(temp, dir_fd=parent_fd, follow_symlinks=False)
            if file_identity(source) != temp_identity or file_identity(source_path) != temp_identity:
                reject('temp_changed', 75)
            LEDGER.begin()
            LEDGER.begin()
            rename_noreplace(parent_fd, temp, os.path.basename(HOLD_PATH))
            LEDGER.confirm('hold_published')
            LEDGER.confirm('temp_files_removed')
            descriptor = os.open(os.path.basename(HOLD_PATH), os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW, dir_fd=parent_fd)
            try:
                attached = os.fstat(descriptor)
                if (not stat.S_ISREG(attached.st_mode) or attached.st_uid != 0 or attached.st_gid != 0
                        or stat.S_IMODE(attached.st_mode) != 0o600 or attached.st_nlink != 1
                        or attach_identity(attached) != attach_identity(source)
                        or attach_identity(os.fstat(source_descriptor)) != attach_identity(source)
                        or read_all({'dir_fd': parent_fd, 'fd': descriptor, 'name': os.path.basename(HOLD_PATH), 'identity': file_identity(attached)}, len(data)) != data):
                    reject('hold_attach_invalid')
            finally:
                os.close(descriptor)
        finally:
            os.close(source_descriptor)
        os.fsync(parent_fd)
        ensure_directory_stable(parent, parent_fd, parent_meta)
        return {'published': 1, 'existing': 0}
    except FileExistsError:
        while LEDGER.in_flight:
            LEDGER.cancel()
        current = safe_file_at(parent_fd, os.path.basename(HOLD_PATH), 64 * 1024)
        if current[0] != data:
            reject('hold_conflict')
        return {'published': 0, 'existing': 1}
    finally:
        if fd >= 0:
            os.close(fd)
        try:
            if temp_identity is not None:
                unlink_owned_temp(parent_fd, temp, temp_identity)
        except FileNotFoundError:
            pass
        for record in reversed(locks):
            try:
                fcntl.flock(record['fd'], fcntl.LOCK_UN)
            except OSError:
                pass
            close_record(record)
        os.close(parent_fd)


def result(mode, status='pass', **fields):
    counts, outcome = LEDGER.fields()
    value = {
        'mode': mode,
        'mutation_counts': counts,
        'mutation_outcome': outcome,
        'schema': RESULT_SCHEMA,
        'status': status,
    }
    value.update(fields)
    return value


def main():
    if os.geteuid() != 0:
        reject('root_required')
    if len(sys.argv) == 1:
        mode = 'inspect'
    elif len(sys.argv) == 3 and sys.argv[1] == 'provision' and sys.argv[2] == TOKEN:
        mode = 'provision'
    else:
        reject('invalid_arguments')
    if mode == 'inspect':
        targets = preflight()
        record = safe_file(HOLD_PATH, 64 * 1024, allow_missing=True)
        hold = decode_hold(record[0] if record else None)
        attached = bool(hold and hold_bytes(targets) == record[0])
        if hold is not None and not attached:
            reject('hold_conflict')
        emit(result(mode, attached=attached, pending=not attached, targets_preflighted=len(targets)))
        return
    initial = preflight()
    published = provision(initial)
    emit(
        {
            'mode': mode,
            'mutation_counts': LEDGER.fields()[0],
            'mutation_outcome': LEDGER.fields()[1],
            'schema': RESULT_SCHEMA,
            'status': 'pass',
        }
    )


if __name__ == '__main__':
    try:
        main()
    except HoldError as error:
        error_mode = 'inspect' if len(sys.argv) == 1 else 'provision'
        error_value = {
            'mode': error_mode,
            'mutation_counts': LEDGER.fields()[0],
            'mutation_outcome': LEDGER.fields()[1],
            'reason': error.reason,
            'schema': RESULT_SCHEMA,
            'status': 'blocked',
        }
        if error_mode == 'inspect':
            error_value.update({'attached': False, 'pending': True, 'targets_preflighted': 0})
        emit(
            error_value
        )
        sys.exit(error.code)
    except BaseException:
        error_mode = 'provision' if len(sys.argv) == 3 else 'inspect'
        error_value = {
                'mode': error_mode,
                'mutation_counts': LEDGER.fields()[0],
                'mutation_outcome': LEDGER.fields()[1],
                'reason': 'internal_error',
                'schema': RESULT_SCHEMA,
                'status': 'blocked',
            }
        if error_mode == 'inspect':
            error_value.update({'attached': False, 'pending': True, 'targets_preflighted': 0})
        emit(error_value)
        sys.exit(70)
