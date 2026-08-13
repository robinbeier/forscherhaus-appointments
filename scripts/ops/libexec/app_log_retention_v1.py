#!/usr/bin/python3
"""Fail-closed retention for the daily CodeIgniter application log class."""

import datetime
import fcntl
import json
import os
import pwd
import re
import secrets
import stat
import sys


SCHEMA = 'prod_app_log_retention.v1'
MARKER_SCHEMA = 'prod_app_log_retention_marker.v1'
MARKER_STATUS_SCHEMA = 'prod_app_log_retention_marker_status.v1'
LOG_ROOT = '/var/www/html/easyappointments/storage/logs'
STATE_ROOT = '/var/lib/fh-app-log-retention'
GLOBAL_STATE_ROOT = '/var/lib/fh-deploy-orchestrator'
GLOBAL_LOCK_LEAF = 'fh-production-change.lock'
MARKER_LEAF = 'last-success.json'
MARKER_MAX_BYTES = 4096
RETENTION_SECONDS = 60 * 86_400
MAX_DELETE = 1000
MAX_DELETE_BYTES = 512 * 1024 * 1024
MAX_LOG_BYTES = 128 * 1024 * 1024
MAX_SCAN = 10_000
LOG_NAME = re.compile(r'log-20[0-9]{2}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])\.php\Z')
PROTECTED_FILES = {
    '.htaccess',
    'index.html',
    'dashboard_principal_pdf_dump.html',
    'dashboard_teacher_pdf_dump.html',
    'provider_parent_appointments_pdf_dump.html',
    'provider_preparation_pdf_dump.html',
}
PROTECTED_DIRECTORIES = {'ci', 'ops', 'release-gate'}


class RetentionError(Exception):
    def __init__(self, reason, code=70):
        super().__init__(reason)
        self.reason = reason
        self.code = code


def reject(reason='rejected', code=70):
    raise RetentionError(reason, code)


def emit(payload):
    sys.stdout.write(json.dumps(payload, sort_keys=True, separators=(',', ':')) + '\n')
    sys.stdout.flush()


def directory_identity(value):
    return value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid, value.st_nlink


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


def open_child_directory(parent, leaf, owners, exact_modes):
    before = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
    descriptor = os.open(
        leaf,
        os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW,
        dir_fd=parent,
    )
    try:
        opened = os.fstat(descriptor)
        if (
            directory_identity(before) != directory_identity(opened)
            or not stat.S_ISDIR(opened.st_mode)
            or (opened.st_uid, opened.st_gid) not in owners
            or stat.S_IMODE(opened.st_mode) not in exact_modes
        ):
            reject('unsafe_directory')
        return descriptor
    except BaseException:
        os.close(descriptor)
        raise


def root_owner():
    return {(0, 0)}


def web_identity():
    try:
        web = pwd.getpwnam('www-data')
    except KeyError:
        reject('missing_web_user')
    return web.pw_uid, web.pw_gid


def open_root_owned_directory(path, exact_mode=None):
    if not path.startswith('/') or path == '/':
        reject('unsafe_directory')
    descriptor = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW)
    try:
        slash = os.fstat(descriptor)
        if slash.st_uid != 0 or slash.st_gid != 0 or stat.S_IMODE(slash.st_mode) & 0o022:
            reject('unsafe_directory')
        parts = [part for part in path.split('/') if part]
        for index, leaf in enumerate(parts):
            modes = {exact_mode} if index == len(parts) - 1 and exact_mode is not None else {
                0o700, 0o710, 0o711, 0o750, 0o751, 0o755,
            }
            next_descriptor = open_child_directory(descriptor, leaf, root_owner(), modes)
            os.close(descriptor)
            descriptor = next_descriptor
        return descriptor
    except BaseException:
        os.close(descriptor)
        raise


def open_log_directory():
    web_uid, web_gid = web_identity()
    descriptor = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW)
    try:
        slash = os.fstat(descriptor)
        if slash.st_uid != 0 or slash.st_gid != 0 or stat.S_IMODE(slash.st_mode) & 0o022:
            reject('unsafe_directory')
        for leaf in ('var', 'www', 'html', 'easyappointments'):
            next_descriptor = open_child_directory(
                descriptor,
                leaf,
                root_owner(),
                {0o700, 0o710, 0o711, 0o750, 0o751, 0o755},
            )
            os.close(descriptor)
            descriptor = next_descriptor
        owners = {(web_uid, web_gid)}
        for leaf in ('storage', 'logs'):
            next_descriptor = open_child_directory(descriptor, leaf, owners, {0o750, 0o755})
            os.close(descriptor)
            descriptor = next_descriptor
        return descriptor, web_uid, web_gid
    except BaseException:
        os.close(descriptor)
        raise


def prepare_state_directory():
    parent = open_root_owned_directory('/var/lib')
    try:
        try:
            os.mkdir('fh-app-log-retention', 0o700, dir_fd=parent)
            os.fsync(parent)
        except FileExistsError:
            pass
        return open_child_directory(parent, 'fh-app-log-retention', root_owner(), {0o700})
    finally:
        os.close(parent)


def assert_log_file(metadata, web_uid, web_gid):
    if (
        not stat.S_ISREG(metadata.st_mode)
        or (metadata.st_uid, metadata.st_gid) != (web_uid, web_gid)
        or stat.S_IMODE(metadata.st_mode) != 0o644
        or metadata.st_nlink != 1
        or metadata.st_size > MAX_LOG_BYTES
    ):
        reject('unsafe_log_entry')


def assert_protected_file(metadata, web_uid, web_gid):
    if (
        not stat.S_ISREG(metadata.st_mode)
        or (metadata.st_uid, metadata.st_gid) not in {(0, 0), (web_uid, web_gid)}
        or stat.S_IMODE(metadata.st_mode) not in {0o600, 0o640, 0o644, 0o755}
        or metadata.st_nlink != 1
    ):
        reject('unsafe_protected_entry')


def assert_protected_directory(directory, name, web_uid, web_gid):
    metadata = os.stat(name, dir_fd=directory, follow_symlinks=False)
    if (
        not stat.S_ISDIR(metadata.st_mode)
        or (metadata.st_uid, metadata.st_gid) not in {(0, 0), (web_uid, web_gid)}
        or stat.S_IMODE(metadata.st_mode) not in {0o700, 0o750, 0o755}
        or metadata.st_nlink < 2
    ):
        reject('unsafe_protected_entry')


def scan_logs(directory, web_uid, web_gid, cutoff_ns):
    names = os.listdir(directory)
    if len(names) > MAX_SCAN:
        reject('scan_limit_exceeded')
    candidates = []
    current_count = 0
    protected_count = 0
    protected_bytes = 0
    valid_bytes = 0
    for name in names:
        try:
            metadata = os.stat(name, dir_fd=directory, follow_symlinks=False)
        except FileNotFoundError:
            continue
        if LOG_NAME.fullmatch(name):
            try:
                datetime.datetime.strptime(name[4:14], '%Y-%m-%d')
            except ValueError:
                reject('invalid_log_date')
            assert_log_file(metadata, web_uid, web_gid)
            valid_bytes += metadata.st_size
            if metadata.st_mtime_ns <= cutoff_ns:
                candidates.append((metadata.st_mtime_ns, name, file_identity(metadata), metadata.st_size))
            else:
                current_count += 1
            continue
        if name in PROTECTED_FILES:
            assert_protected_file(metadata, web_uid, web_gid)
            protected_count += 1
            protected_bytes += metadata.st_size
            continue
        if name in PROTECTED_DIRECTORIES:
            assert_protected_directory(directory, name, web_uid, web_gid)
            protected_count += 1
            continue
        reject('unclassified_log_entry')
    candidates.sort(key=lambda value: (value[0], value[1]))
    return {
        'candidate_records': candidates,
        'current_count': current_count,
        'eligible_count': len(candidates),
        'protected_bytes': protected_bytes,
        'protected_count': protected_count,
        'scanned_count': len(names),
        'valid_logical_bytes': valid_bytes,
    }


def select_candidates(records):
    selected = []
    selected_bytes = 0
    for record in records:
        size = record[3]
        if len(selected) >= MAX_DELETE or selected_bytes + size > MAX_DELETE_BYTES:
            break
        selected.append(record)
        selected_bytes += size
    return selected, selected_bytes


def open_stable_log(directory, name, expected_identity, web_uid, web_gid):
    before = os.stat(name, dir_fd=directory, follow_symlinks=False)
    descriptor = os.open(
        name,
        os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK,
        dir_fd=directory,
    )
    try:
        opened = os.fstat(descriptor)
        assert_log_file(opened, web_uid, web_gid)
        if file_identity(before) != file_identity(opened) or file_identity(opened) != expected_identity:
            reject('log_entry_changed', 75)
        return descriptor
    except BaseException:
        os.close(descriptor)
        raise


def inspect_candidate(directory, record, web_uid, web_gid, cutoff_ns, delete):
    _, name, expected, _ = record
    try:
        descriptor = open_stable_log(directory, name, expected, web_uid, web_gid)
    except FileNotFoundError:
        return 'changed', 0
    try:
        try:
            fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return 'locked', 0
        opened = os.fstat(descriptor)
        try:
            current = os.stat(name, dir_fd=directory, follow_symlinks=False)
        except FileNotFoundError:
            return 'changed', 0
        if file_identity(opened) != file_identity(current):
            return 'changed', 0
        if opened.st_mtime_ns > cutoff_ns:
            return 'rejuvenated', 0
        if not delete:
            return 'eligible', opened.st_size
        os.unlink(name, dir_fd=directory)
        return 'deleted', opened.st_size
    finally:
        os.close(descriptor)


def stable_regular_file(directory, leaf, missing_ok=False):
    try:
        before = os.stat(leaf, dir_fd=directory, follow_symlinks=False)
        descriptor = os.open(
            leaf,
            os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK,
            dir_fd=directory,
        )
    except FileNotFoundError:
        if missing_ok:
            return None
        raise
    try:
        opened = os.fstat(descriptor)
        if (
            file_identity(before) != file_identity(opened)
            or not stat.S_ISREG(opened.st_mode)
            or opened.st_uid != 0
            or opened.st_gid != 0
            or stat.S_IMODE(opened.st_mode) != 0o600
            or opened.st_nlink != 1
            or opened.st_size <= 0
            or opened.st_size > MARKER_MAX_BYTES
        ):
            reject('unsafe_marker')
        data = bytearray()
        while len(data) <= MARKER_MAX_BYTES:
            chunk = os.read(descriptor, min(65_536, MARKER_MAX_BYTES + 1 - len(data)))
            if not chunk:
                break
            data.extend(chunk)
        after = os.fstat(descriptor)
        post = os.stat(leaf, dir_fd=directory, follow_symlinks=False)
        if (
            file_identity(opened) != file_identity(after)
            or file_identity(after) != file_identity(post)
            or len(data) != opened.st_size
        ):
            reject('marker_changed')
        return bytes(data)
    finally:
        os.close(descriptor)


def open_global_lock():
    root = open_root_owned_directory(GLOBAL_STATE_ROOT, 0o700)
    try:
        locks = open_child_directory(root, 'locks', root_owner(), {0o700})
    finally:
        os.close(root)
    try:
        before = os.stat(GLOBAL_LOCK_LEAF, dir_fd=locks, follow_symlinks=False)
        descriptor = os.open(
            GLOBAL_LOCK_LEAF,
            os.O_RDWR | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK,
            dir_fd=locks,
        )
        opened = os.fstat(descriptor)
        if (
            file_identity(before) != file_identity(opened)
            or not stat.S_ISREG(opened.st_mode)
            or opened.st_uid != 0
            or opened.st_gid != 0
            or stat.S_IMODE(opened.st_mode) != 0o600
            or opened.st_nlink != 1
            or opened.st_size != 0
        ):
            os.close(descriptor)
            reject('unsafe_global_lock')
        try:
            fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            os.close(descriptor)
            reject('active_production_work', 75)
        after = os.stat(GLOBAL_LOCK_LEAF, dir_fd=locks, follow_symlinks=False)
        if file_identity(opened) != file_identity(after):
            os.close(descriptor)
            reject('unsafe_global_lock')
        return descriptor
    finally:
        os.close(locks)


def canonical_json(payload):
    return (json.dumps(payload, sort_keys=True, separators=(',', ':')) + '\n').encode('utf-8')


def clean_marker_temps(state):
    for name in os.listdir(state):
        if re.fullmatch(r'\.last-success\.json\.tmp-[0-9a-f]{32}', name) is None:
            continue
        metadata = os.stat(name, dir_fd=state, follow_symlinks=False)
        if (
            not stat.S_ISREG(metadata.st_mode)
            or metadata.st_uid != 0
            or metadata.st_gid != 0
            or stat.S_IMODE(metadata.st_mode) != 0o600
            or metadata.st_nlink != 1
            or metadata.st_size > MARKER_MAX_BYTES
        ):
            reject('unsafe_marker_temp')
        os.unlink(name, dir_fd=state)
        os.fsync(state)


def write_marker(state, deleted_count):
    payload = {
        'completed_at_utc': datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0).isoformat().replace('+00:00', 'Z'),
        'deleted_count': deleted_count,
        'max_delete': MAX_DELETE,
        'max_delete_bytes': MAX_DELETE_BYTES,
        'remaining_eligible_count': 0,
        'retention_seconds': RETENTION_SECONDS,
        'schema': MARKER_SCHEMA,
    }
    data = canonical_json(payload)
    stable_regular_file(state, MARKER_LEAF, missing_ok=True)
    temp = '.last-success.json.tmp-' + secrets.token_hex(16)
    descriptor = os.open(
        temp,
        os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK,
        0o600,
        dir_fd=state,
    )
    try:
        offset = 0
        while offset < len(data):
            offset += os.write(descriptor, data[offset:])
        os.fsync(descriptor)
        os.replace(temp, MARKER_LEAF, src_dir_fd=state, dst_dir_fd=state)
        os.fsync(state)
    finally:
        os.close(descriptor)
        try:
            os.unlink(temp, dir_fd=state)
        except FileNotFoundError:
            pass
    if stable_regular_file(state, MARKER_LEAF) != data:
        reject('marker_publish_failed')


def cutoff_now_ns():
    return int(datetime.datetime.now(datetime.timezone.utc).timestamp() * 1_000_000_000) - RETENTION_SECONDS * 1_000_000_000


def dry_run():
    directory, web_uid, web_gid = open_log_directory()
    try:
        cutoff_ns = cutoff_now_ns()
        snapshot = scan_logs(directory, web_uid, web_gid, cutoff_ns)
        eligible = 0
        locked = 0
        changed = 0
        eligible_bytes = 0
        eligible_records = []
        for record in snapshot['candidate_records']:
            disposition, size = inspect_candidate(directory, record, web_uid, web_gid, cutoff_ns, False)
            if disposition == 'eligible':
                eligible += 1
                eligible_bytes += size
                eligible_records.append(record)
            elif disposition == 'locked':
                locked += 1
            else:
                changed += 1
        selected, selected_bytes = select_candidates(eligible_records)
        cap_exceeded = eligible > MAX_DELETE or eligible_bytes > MAX_DELETE_BYTES
        emit({
            'cap_exceeded': cap_exceeded,
            'changed_count': changed,
            'current_count': snapshot['current_count'],
            'deletion_performed': False,
            'eligible_count': eligible,
            'eligible_logical_bytes': eligible_bytes,
            'locked_count': locked,
            'max_delete': MAX_DELETE,
            'max_delete_bytes': MAX_DELETE_BYTES,
            'mode': 'dry-run',
            'protected_count': snapshot['protected_count'],
            'retention_seconds': RETENTION_SECONDS,
            'schema': SCHEMA,
            'scanned_count': snapshot['scanned_count'],
            'status': 'pass',
            'valid_logical_bytes': snapshot['valid_logical_bytes'],
            'would_delete_count': min(len(selected), eligible),
            'would_delete_logical_bytes': min(selected_bytes, eligible_bytes),
        })
    finally:
        os.close(directory)


def execute():
    state = prepare_state_directory()
    global_lock = None
    directory = None
    try:
        try:
            fcntl.flock(state, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            reject('cleanup_lock_busy', 75)
        clean_marker_temps(state)
        global_lock = open_global_lock()
        directory, web_uid, web_gid = open_log_directory()
        cutoff_ns = cutoff_now_ns()
        snapshot = scan_logs(directory, web_uid, web_gid, cutoff_ns)
        selected, _ = select_candidates(snapshot['candidate_records'])
        deleted = 0
        deleted_bytes = 0
        locked = 0
        changed = 0
        for record in selected:
            disposition, size = inspect_candidate(directory, record, web_uid, web_gid, cutoff_ns, True)
            if disposition == 'deleted':
                deleted += 1
                deleted_bytes += size
            elif disposition == 'locked':
                locked += 1
            else:
                changed += 1
        if deleted:
            os.fsync(directory)
        remaining = scan_logs(directory, web_uid, web_gid, cutoff_ns)['eligible_count']
        result = {
            'cap_exceeded': snapshot['eligible_count'] > len(selected),
            'changed_count': changed,
            'current_count': snapshot['current_count'],
            'deleted_count': deleted,
            'deleted_logical_bytes': deleted_bytes,
            'deletion_performed': deleted > 0,
            'eligible_before_count': snapshot['eligible_count'],
            'locked_count': locked,
            'max_delete': MAX_DELETE,
            'max_delete_bytes': MAX_DELETE_BYTES,
            'mode': 'execute',
            'protected_count': snapshot['protected_count'],
            'remaining_eligible_count': remaining,
            'retention_seconds': RETENTION_SECONDS,
            'schema': SCHEMA,
            'scanned_count': snapshot['scanned_count'],
            'status': 'pass' if remaining == 0 else 'partial',
        }
        if remaining == 0:
            write_marker(state, deleted)
            emit(result)
            return
        emit(result)
        raise SystemExit(75)
    finally:
        if directory is not None:
            os.close(directory)
        if global_lock is not None:
            os.close(global_lock)
        os.close(state)


def marker_status(max_age_seconds):
    if not re.fullmatch(r'[1-9][0-9]{0,6}', max_age_seconds):
        reject('invalid_marker_age')
    max_age = int(max_age_seconds)
    parent = open_root_owned_directory('/var/lib')
    try:
        try:
            state = open_child_directory(parent, 'fh-app-log-retention', root_owner(), {0o700})
        except FileNotFoundError:
            emit({'age_seconds': None, 'schema': MARKER_STATUS_SCHEMA, 'status': 'missing'})
            return
    finally:
        os.close(parent)
    try:
        try:
            data = stable_regular_file(state, MARKER_LEAF, missing_ok=True)
        except RetentionError:
            emit({'age_seconds': None, 'schema': MARKER_STATUS_SCHEMA, 'status': 'invalid'})
            return
        if data is None:
            emit({'age_seconds': None, 'schema': MARKER_STATUS_SCHEMA, 'status': 'missing'})
            return
        try:
            decoded = json.loads(data)
            if canonical_json(decoded) != data or set(decoded) != {
                'completed_at_utc', 'deleted_count', 'max_delete', 'max_delete_bytes',
                'remaining_eligible_count', 'retention_seconds', 'schema',
            }:
                raise ValueError
            if (
                decoded['schema'] != MARKER_SCHEMA
                or decoded['retention_seconds'] != RETENTION_SECONDS
                or decoded['max_delete'] != MAX_DELETE
                or decoded['max_delete_bytes'] != MAX_DELETE_BYTES
                or decoded['remaining_eligible_count'] != 0
                or not isinstance(decoded['deleted_count'], int)
                or isinstance(decoded['deleted_count'], bool)
                or not 0 <= decoded['deleted_count'] <= MAX_DELETE
            ):
                raise ValueError
            completed = datetime.datetime.strptime(decoded['completed_at_utc'], '%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=datetime.timezone.utc)
            age = int((datetime.datetime.now(datetime.timezone.utc) - completed).total_seconds())
            if age < -300:
                raise ValueError
            age = max(0, age)
        except (KeyError, TypeError, ValueError, json.JSONDecodeError):
            emit({'age_seconds': None, 'schema': MARKER_STATUS_SCHEMA, 'status': 'invalid'})
            return
        emit({'age_seconds': age, 'schema': MARKER_STATUS_SCHEMA, 'status': 'pass' if age <= max_age else 'stale'})
    finally:
        os.close(state)


def main():
    if os.name != 'posix' or os.geteuid() != 0:
        reject('root_required')
    if len(sys.argv) == 2 and sys.argv[1] == 'dry-run':
        dry_run()
        return
    if len(sys.argv) == 2 and sys.argv[1] == 'execute':
        execute()
        return
    if len(sys.argv) == 3 and sys.argv[1] == 'marker-status':
        marker_status(sys.argv[2])
        return
    reject('usage', 64)


try:
    main()
except SystemExit:
    raise
except RetentionError as error:
    emit({'reason': error.reason, 'schema': SCHEMA, 'status': 'blocked'})
    raise SystemExit(error.code)
except (OSError, TypeError, ValueError):
    emit({'reason': 'internal_rejection', 'schema': SCHEMA, 'status': 'blocked'})
    raise SystemExit(70)
