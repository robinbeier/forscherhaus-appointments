#!/usr/bin/python3
import datetime
import fcntl
import json
import os
import pwd
import re
import secrets
import stat
import sys


SCHEMA = 'prod_session_retention.v1'
MARKER_SCHEMA = 'prod_session_retention_marker.v1'
MARKER_STATUS_SCHEMA = 'prod_session_retention_marker_status.v1'
SESSION_ROOT = '/var/www/html/easyappointments/storage/sessions'
STATE_ROOT = '/var/lib/fh-session-retention'
GLOBAL_STATE_ROOT = '/var/lib/fh-deploy-orchestrator'
GLOBAL_LOCK_LEAF = 'fh-production-change.lock'
MARKER_LEAF = 'last-success.json'
MARKER_MAX_BYTES = 4096
MIN_AGE_SECONDS = 86_400
MAX_DELETE = 10_000
MAX_SCAN = 1_000_000
MAX_SESSION_ID_BYTES = 256


class RetentionError(Exception):
    def __init__(self, reason, code=70):
        super().__init__(reason)
        self.reason = reason
        self.code = code


def emit(payload):
    sys.stdout.write(json.dumps(payload, sort_keys=True, separators=(',', ':')) + '\n')
    sys.stdout.flush()


def reject(reason='rejected', code=70):
    raise RetentionError(reason, code)


def directory_identity(value):
    return (
        value.st_dev,
        value.st_ino,
        value.st_mode,
        value.st_uid,
        value.st_gid,
        value.st_nlink,
    )


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


def open_child_directory(parent, leaf, owner_uid, owner_gid, exact_mode=None, root_safe=False):
    before = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
    descriptor = os.open(
        leaf,
        os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW,
        dir_fd=parent,
    )
    try:
        opened = os.fstat(descriptor)
        mode = stat.S_IMODE(opened.st_mode)
        mode_safe = mode == exact_mode if exact_mode is not None else (mode & 0o022) == 0
        if (
            directory_identity(before) != directory_identity(opened)
            or not stat.S_ISDIR(opened.st_mode)
            or opened.st_uid != owner_uid
            or opened.st_gid != owner_gid
            or not mode_safe
            or (root_safe and opened.st_uid != 0)
        ):
            reject('unsafe_directory')
        return descriptor
    except BaseException:
        os.close(descriptor)
        raise


def open_root_owned_directory(path, exact_mode=None):
    if not path.startswith('/') or path == '/':
        reject('unsafe_directory')
    descriptor = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW)
    try:
        for index, leaf in enumerate(part for part in path.split('/') if part):
            final = index == len([part for part in path.split('/') if part]) - 1
            next_descriptor = open_child_directory(
                descriptor,
                leaf,
                0,
                0,
                exact_mode if final else None,
                root_safe=True,
            )
            os.close(descriptor)
            descriptor = next_descriptor
        return descriptor
    except BaseException:
        os.close(descriptor)
        raise


def prepare_state_directory():
    parent = open_root_owned_directory('/var/lib')
    try:
        try:
            os.mkdir('fh-session-retention', 0o700, dir_fd=parent)
            os.fsync(parent)
        except FileExistsError:
            pass
        return open_child_directory(
            parent,
            'fh-session-retention',
            0,
            0,
            exact_mode=0o700,
            root_safe=True,
        )
    finally:
        os.close(parent)


def open_session_directory():
    try:
        web = pwd.getpwnam('www-data')
    except KeyError:
        reject('missing_web_user')

    descriptor = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW)
    try:
        for leaf in ('var', 'www', 'html', 'easyappointments'):
            next_descriptor = open_child_directory(descriptor, leaf, 0, 0, root_safe=True)
            os.close(descriptor)
            descriptor = next_descriptor
        for leaf in ('storage', 'sessions'):
            next_descriptor = open_child_directory(
                descriptor,
                leaf,
                web.pw_uid,
                web.pw_gid,
                exact_mode=0o755,
            )
            os.close(descriptor)
            descriptor = next_descriptor
        return descriptor, web.pw_uid, web.pw_gid
    except BaseException:
        os.close(descriptor)
        raise


def valid_session_name(name):
    prefix = 'ea_session'
    if not name.startswith(prefix):
        return False
    session_id = name[len(prefix):]
    if not (27 <= len(session_id) <= MAX_SESSION_ID_BYTES):
        return False
    return (
        (len(session_id) >= 40 and re.fullmatch(r'[0-9a-f]+', session_id) is not None)
        or (len(session_id) >= 32 and re.fullmatch(r'[0-9a-v]+', session_id) is not None)
        or re.fullmatch(r'[0-9a-zA-Z,-]+', session_id) is not None
    )


def assert_session_file(metadata, web_uid, web_gid):
    if (
        not stat.S_ISREG(metadata.st_mode)
        or metadata.st_uid != web_uid
        or metadata.st_gid != web_gid
        or stat.S_IMODE(metadata.st_mode) != 0o600
        or metadata.st_nlink != 1
    ):
        reject('unsafe_session_entry')


def scan_sessions(directory, web_uid, web_gid, cutoff_ns):
    names = os.listdir(directory)
    if len(names) > MAX_SCAN:
        reject('scan_limit_exceeded')

    foreign_count = 0
    valid_count = 0
    newer_count = 0
    logical_bytes = 0
    candidates = []
    for name in names:
        if not valid_session_name(name):
            foreign_count += 1
            continue
        try:
            metadata = os.stat(name, dir_fd=directory, follow_symlinks=False)
        except FileNotFoundError:
            continue
        assert_session_file(metadata, web_uid, web_gid)
        valid_count += 1
        logical_bytes += metadata.st_size
        if metadata.st_mtime_ns <= cutoff_ns:
            candidates.append((metadata.st_mtime_ns, name, file_identity(metadata)))
        else:
            newer_count += 1

    candidates.sort(key=lambda value: (value[0], value[1]))
    return {
        'candidate_records': candidates,
        'eligible_count': len(candidates),
        'foreign_count': foreign_count,
        'logical_bytes': logical_bytes,
        'newer_count': newer_count,
        'scanned_count': len(names),
        'valid_count': valid_count,
    }


def open_stable_session(directory, name, expected_identity, web_uid, web_gid):
    before = os.stat(name, dir_fd=directory, follow_symlinks=False)
    descriptor = os.open(
        name,
        os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK,
        dir_fd=directory,
    )
    try:
        opened = os.fstat(descriptor)
        assert_session_file(opened, web_uid, web_gid)
        if file_identity(before) != file_identity(opened) or file_identity(opened) != expected_identity:
            reject('session_entry_changed', 75)
        return descriptor
    except BaseException:
        os.close(descriptor)
        raise


def lock_nonblocking(descriptor):
    try:
        fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        return True
    except BlockingIOError:
        return False


def activity_count():
    patterns = (
        re.compile(r'(^|/)(?:deploy_ea\.sh|deployment_host_runner_v1\.php|zero_surprise_replay\.php)(?:\s|$)'),
        re.compile(r'(^|/)(?:prod_(?:customers|provider)_ui_smoke\.sh|traffic_gate_v1\.php)(?:\s|$)'),
        re.compile(r'(^|/)(?:mysqldump|mariadb-dump|backup_easyappointments\.sh)(?:\s|$)'),
    )
    count = 0
    try:
        entries = os.scandir('/proc')
    except OSError:
        reject('activity_unknown')
    with entries:
        for entry in entries:
            if not entry.name.isdigit() or int(entry.name) == os.getpid():
                continue
            try:
                with open(os.path.join('/proc', entry.name, 'cmdline'), 'rb') as handle:
                    raw = handle.read(131_073)
            except (FileNotFoundError, ProcessLookupError):
                continue
            except PermissionError:
                reject('activity_unknown')
            if len(raw) > 131_072:
                reject('activity_unknown')
            command = raw.replace(b'\0', b' ').decode('utf-8', 'replace').strip()
            if command and any(pattern.search(command) for pattern in patterns):
                count += 1
    return count


def stable_regular_file(directory, leaf, uid, gid, mode, max_bytes, missing_ok=False):
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
            or opened.st_uid != uid
            or opened.st_gid != gid
            or stat.S_IMODE(opened.st_mode) != mode
            or opened.st_nlink != 1
            or opened.st_size <= 0
            or opened.st_size > max_bytes
        ):
            reject('unsafe_marker')
        data = bytearray()
        while len(data) <= max_bytes:
            chunk = os.read(descriptor, min(65_536, max_bytes + 1 - len(data)))
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
        locks = open_child_directory(root, 'locks', 0, 0, exact_mode=0o700, root_safe=True)
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


def write_marker(state, result):
    completed = datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0)
    marker = {
        'completed_at_utc': completed.isoformat().replace('+00:00', 'Z'),
        'cutoff_seconds': MIN_AGE_SECONDS,
        'deleted_count': result['deleted_count'],
        'max_delete': MAX_DELETE,
        'remaining_eligible_count': 0,
        'schema': MARKER_SCHEMA,
    }
    data = canonical_json(marker)
    # Existing markers are replaceable only when the leaf itself still has the
    # protected regular-file identity. The state-directory lock serializes
    # trusted writers after this check.
    stable_regular_file(state, MARKER_LEAF, 0, 0, 0o600, MARKER_MAX_BYTES, missing_ok=True)
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
        metadata = os.fstat(descriptor)
        if (
            not stat.S_ISREG(metadata.st_mode)
            or metadata.st_uid != 0
            or metadata.st_gid != 0
            or stat.S_IMODE(metadata.st_mode) != 0o600
            or metadata.st_nlink != 1
        ):
            reject('unsafe_marker_temp')
        os.replace(temp, MARKER_LEAF, src_dir_fd=state, dst_dir_fd=state)
        os.fsync(state)
    finally:
        os.close(descriptor)
        try:
            os.unlink(temp, dir_fd=state)
        except FileNotFoundError:
            pass
    current = stable_regular_file(state, MARKER_LEAF, 0, 0, 0o600, MARKER_MAX_BYTES)
    if current != data:
        reject('marker_publish_failed')


def inspect_candidate(directory, record, web_uid, web_gid, cutoff_ns, delete):
    _, name, expected = record
    try:
        descriptor = open_stable_session(directory, name, expected, web_uid, web_gid)
    except FileNotFoundError:
        return 'changed', 0
    try:
        if not lock_nonblocking(descriptor):
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


def dry_run():
    directory, web_uid, web_gid = open_session_directory()
    try:
        now_ns = datetime.datetime.now(datetime.timezone.utc).timestamp() * 1_000_000_000
        cutoff_ns = int(now_ns) - MIN_AGE_SECONDS * 1_000_000_000
        snapshot = scan_sessions(directory, web_uid, web_gid, cutoff_ns)
        locked = 0
        changed = 0
        eligible_now = 0
        for record in snapshot['candidate_records']:
            disposition, _ = inspect_candidate(directory, record, web_uid, web_gid, cutoff_ns, False)
            if disposition == 'locked':
                locked += 1
            elif disposition in ('changed', 'rejuvenated'):
                changed += 1
            else:
                eligible_now += 1
        emit({
            'cap_exceeded': eligible_now > MAX_DELETE,
            'changed_count': changed,
            'cutoff_seconds': MIN_AGE_SECONDS,
            'deletion_performed': False,
            'eligible_count': eligible_now,
            'foreign_count': snapshot['foreign_count'],
            'locked_count': locked,
            'max_delete': MAX_DELETE,
            'mode': 'dry-run',
            'newer_count': snapshot['newer_count'],
            'schema': SCHEMA,
            'scanned_count': snapshot['scanned_count'],
            'status': 'pass',
            'valid_logical_bytes': snapshot['logical_bytes'],
            'would_delete_count': min(eligible_now, MAX_DELETE),
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
        if activity_count() != 0:
            reject('active_production_work', 75)

        directory, web_uid, web_gid = open_session_directory()
        now_ns = datetime.datetime.now(datetime.timezone.utc).timestamp() * 1_000_000_000
        cutoff_ns = int(now_ns) - MIN_AGE_SECONDS * 1_000_000_000
        snapshot = scan_sessions(directory, web_uid, web_gid, cutoff_ns)
        if activity_count() != 0:
            reject('active_production_work', 75)

        deleted = 0
        deleted_bytes = 0
        locked = 0
        changed = 0
        for index, record in enumerate(snapshot['candidate_records'][:MAX_DELETE]):
            if index > 0 and index % 1000 == 0:
                if activity_count() != 0:
                    reject('active_production_work', 75)
            disposition, logical_bytes = inspect_candidate(
                directory,
                record,
                web_uid,
                web_gid,
                cutoff_ns,
                True,
            )
            if disposition == 'deleted':
                deleted += 1
                deleted_bytes += logical_bytes
            elif disposition == 'locked':
                locked += 1
            else:
                changed += 1
        if deleted > 0:
            os.fsync(directory)

        remaining = scan_sessions(directory, web_uid, web_gid, cutoff_ns)['eligible_count']
        result = {
            'cap_exceeded': snapshot['eligible_count'] > MAX_DELETE,
            'changed_count': changed,
            'cutoff_seconds': MIN_AGE_SECONDS,
            'deleted_count': deleted,
            'deleted_logical_bytes': deleted_bytes,
            'deletion_performed': deleted > 0,
            'eligible_before_count': snapshot['eligible_count'],
            'foreign_count': snapshot['foreign_count'],
            'locked_count': locked,
            'max_delete': MAX_DELETE,
            'mode': 'execute',
            'remaining_eligible_count': remaining,
            'schema': SCHEMA,
            'scanned_count': snapshot['scanned_count'],
            'status': 'pass' if remaining == 0 else 'partial',
        }
        if remaining == 0:
            write_marker(state, result)
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
            state = open_child_directory(parent, 'fh-session-retention', 0, 0, exact_mode=0o700, root_safe=True)
        except FileNotFoundError:
            emit({'age_seconds': None, 'schema': MARKER_STATUS_SCHEMA, 'status': 'missing'})
            return
    finally:
        os.close(parent)
    try:
        try:
            data = stable_regular_file(state, MARKER_LEAF, 0, 0, 0o600, MARKER_MAX_BYTES, missing_ok=True)
        except RetentionError:
            emit({'age_seconds': None, 'schema': MARKER_STATUS_SCHEMA, 'status': 'invalid'})
            return
        if data is None:
            emit({'age_seconds': None, 'schema': MARKER_STATUS_SCHEMA, 'status': 'missing'})
            return
        try:
            decoded = json.loads(data)
            if canonical_json(decoded) != data or set(decoded) != {
                'completed_at_utc',
                'cutoff_seconds',
                'deleted_count',
                'max_delete',
                'remaining_eligible_count',
                'schema',
            }:
                raise ValueError
            if (
                decoded['schema'] != MARKER_SCHEMA
                or decoded['cutoff_seconds'] != MIN_AGE_SECONDS
                or decoded['max_delete'] != MAX_DELETE
                or decoded['remaining_eligible_count'] != 0
                or not isinstance(decoded['deleted_count'], int)
                or isinstance(decoded['deleted_count'], bool)
                or decoded['deleted_count'] < 0
                or decoded['deleted_count'] > MAX_DELETE
            ):
                raise ValueError
            completed = datetime.datetime.strptime(decoded['completed_at_utc'], '%Y-%m-%dT%H:%M:%SZ').replace(
                tzinfo=datetime.timezone.utc,
            )
            age = int((datetime.datetime.now(datetime.timezone.utc) - completed).total_seconds())
            if age < -300:
                raise ValueError
            age = max(0, age)
        except (KeyError, TypeError, ValueError, json.JSONDecodeError):
            emit({'age_seconds': None, 'schema': MARKER_STATUS_SCHEMA, 'status': 'invalid'})
            return
        emit({
            'age_seconds': age,
            'schema': MARKER_STATUS_SCHEMA,
            'status': 'pass' if age <= max_age else 'stale',
        })
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
