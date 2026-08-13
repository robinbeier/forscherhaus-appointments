#!/usr/bin/python3
"""Create one closed, durable Easy!Appointments backup set (ROB-466)."""

import ctypes
import datetime
import errno
import fcntl
import gzip
import hashlib
import json
import os
import re
import resource
import select
import signal
import stat
import subprocess
import sys
import time


SCHEMA = 'production_backup_set_result.v1'
BACKUP_ROOT = '/root/backups/easyappointments'
ORCHESTRATOR_ROOT = '/var/lib/fh-deploy-orchestrator'
GLOBAL_LOCK_LEAF = 'fh-production-change.lock'
PRIVATE_LOCK_LEAF = '.backup-set-producer.lock'
CREDENTIALS = '/etc/fh/backup-set-producer.cnf'
MARIADB_DUMP = '/usr/bin/mariadb-dump'
CONFIG_PATH = CREDENTIALS
DUMP_PATH = MARIADB_DUMP
DATABASE = 'easyappointments'
MARKER_LEAF = 'last_backup_success.utc'
HANDOFF_LEAF = 'last_backup_set.json'
PHP = '/usr/bin/php'
TERMINAL_VALIDATOR = '/usr/local/libexec/fh/validate_deployment_terminal_bundle_v1.php'
MAX_CONFIG_BYTES = 16 * 1024
CONFIG_PASSWORD = re.compile(r'[A-Za-z0-9_-]{32,128}\Z')
MAX_COMPRESSED = 16 * 1024 * 1024 * 1024
MAX_COMPRESSED_BYTES = MAX_COMPRESSED
MAX_UNCOMPRESSED_BYTES = 64 * 1024 * 1024 * 1024
MAX_COMPRESSION_RATIO = 100
MIN_FREE_BYTES = 512 * 1024 * 1024
DUMP_TIMEOUT_SECONDS = 3600
READ_CHUNK = 1024 * 1024
BACKUP_ID = re.compile(r'20[0-9]{6}T[0-9]{6}Z\Z')
STAGING_LEAF = re.compile(r'\.backup-set-producer-[0-9a-f]{32}\.tmp\Z')
MARKER_TEMP = re.compile(r'\.last_backup_success\.utc\.tmp-[0-9a-f]{32}\Z')
HANDOFF_TEMP = re.compile(r'\.last_backup_set\.json\.tmp-[0-9a-f]{32}\Z')
RENAME_NOREPLACE = 1
LIBC = ctypes.CDLL(None, use_errno=True)


def bind_to_parent_death():
    parent = os.getppid()
    if parent == 1 or LIBC.prctl(1, signal.SIGKILL, 0, 0, 0) != 0:
        reject()
    if os.getppid() != parent:
        os.kill(os.getpid(), signal.SIGKILL)


class ProducerError(Exception):
    def __init__(self, code=70):
        super().__init__('backup set producer rejected')
        self.code = code


def reject(code=70):
    raise ProducerError(code)


def emit(status, **values):
    payload = {'schema': SCHEMA, 'status': status}
    payload.update(values)
    sys.stdout.write(json.dumps(payload, sort_keys=True, separators=(',', ':')) + '\n')
    sys.stdout.flush()


def file_identity(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid,
            value.st_nlink, value.st_size, value.st_mtime_ns, value.st_ctime_ns)


def directory_identity(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid, value.st_nlink)


def write_all(descriptor, data):
    offset = 0
    while offset < len(data):
        written = os.write(descriptor, data[offset:])
        if written <= 0:
            reject()
        offset += written


def validate_directory(value, exact_mode=None):
    mode = stat.S_IMODE(value.st_mode)
    if (not stat.S_ISDIR(value.st_mode) or value.st_uid != 0 or value.st_gid != 0 or
            value.st_nlink < 1 or mode & 0o022 or (exact_mode is not None and mode != exact_mode)):
        reject()


def open_absolute_directory(path, exact_mode=None):
    if not path.startswith('/') or path == '/' or '//' in path:
        reject()
    descriptor = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
    try:
        components = path[1:].split('/')
        for index, component in enumerate(components):
            if not component or component in ('.', '..'):
                reject()
            before = os.stat(component, dir_fd=descriptor, follow_symlinks=False)
            child = os.open(component, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW,
                            dir_fd=descriptor)
            opened = os.fstat(child)
            after = os.stat(component, dir_fd=descriptor, follow_symlinks=False)
            if directory_identity(before) != directory_identity(opened) or directory_identity(opened) != directory_identity(after):
                os.close(child)
                reject()
            validate_directory(opened, exact_mode if index == len(components) - 1 else None)
            os.close(descriptor)
            descriptor = child
        return descriptor
    except BaseException:
        os.close(descriptor)
        raise


def open_child_directory(parent, leaf, exact_mode):
    before = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
    descriptor = os.open(leaf, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW,
                         dir_fd=parent)
    try:
        opened = os.fstat(descriptor)
        after = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
        if directory_identity(before) != directory_identity(opened) or directory_identity(opened) != directory_identity(after):
            reject()
        validate_directory(opened, exact_mode)
        return descriptor
    except BaseException:
        os.close(descriptor)
        raise


def open_trusted_file(path, exact_mode=None, executable=False, maximum=None):
    parent_path, leaf = os.path.split(path)
    if not parent_path or not leaf:
        reject()
    parent = open_absolute_directory(parent_path)
    try:
        before = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
        flags = os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK
        descriptor = os.open(leaf, flags, dir_fd=parent)
        opened = os.fstat(descriptor)
        after = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
        mode = stat.S_IMODE(opened.st_mode)
        valid_mode = mode == exact_mode if exact_mode is not None else not mode & 0o022
        if (file_identity(before) != file_identity(opened) or file_identity(opened) != file_identity(after) or
                not stat.S_ISREG(opened.st_mode) or opened.st_uid != 0 or opened.st_gid != 0 or
                opened.st_nlink != 1 or not valid_mode or (executable and not mode & 0o111) or
                (maximum is not None and (opened.st_size <= 0 or opened.st_size > maximum))):
            os.close(descriptor)
            reject()
        return descriptor, file_identity(opened)
    finally:
        os.close(parent)


def verify_trusted_path(path, expected):
    descriptor, observed = open_trusted_file(
        path,
        exact_mode=0o600 if path == CONFIG_PATH else None,
        executable=path == DUMP_PATH,
        maximum=MAX_CONFIG_BYTES if path == CONFIG_PATH else None,
    )
    os.close(descriptor)
    if observed != expected:
        reject()


def validate_connection_config(descriptor):
    os.lseek(descriptor, 0, os.SEEK_SET)
    data = os.read(descriptor, MAX_CONFIG_BYTES + 1)
    if len(data) == 0 or len(data) > MAX_CONFIG_BYTES or os.read(descriptor, 1) != b'':
        reject()
    try:
        lines = data.decode('ascii').splitlines(keepends=True)
    except UnicodeDecodeError:
        reject()
    if (len(lines) != 6 or lines[0] != '[client]\n' or lines[1] != 'user=fh_backup\n' or
            not lines[2].startswith('password=') or not lines[2].endswith('\n') or
            CONFIG_PASSWORD.fullmatch(lines[2][len('password='):-1]) is None or
            lines[3] != 'protocol=tcp\n' or lines[4] != 'host=127.0.0.1\n' or
            lines[5] != 'port=3306\n'):
        reject()
    os.lseek(descriptor, 0, os.SEEK_SET)


def open_lock(parent, leaf, create=False):
    flags = os.O_RDWR | os.O_CLOEXEC | os.O_NOFOLLOW
    if create:
        flags |= os.O_CREAT
    descriptor = os.open(leaf, flags, 0o600, dir_fd=parent)
    opened = os.fstat(descriptor)
    after = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
    if (file_identity(opened) != file_identity(after) or not stat.S_ISREG(opened.st_mode) or
            opened.st_uid != 0 or opened.st_gid != 0 or opened.st_nlink != 1 or
            stat.S_IMODE(opened.st_mode) != 0o600):
        os.close(descriptor)
        reject()
    try:
        fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        os.close(descriptor)
        reject(75)
    after_lock = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
    if file_identity(opened) != file_identity(after_lock):
        os.close(descriptor)
        reject()
    return descriptor


def activity_count():
    patterns = (
        re.compile(r'(^|/)(?:deploy_ea\.sh|deployment_host_runner_v1\.php|zero_surprise_replay\.php)(?:\s|$)'),
        re.compile(r'(^|/)(?:prod_(?:customers|provider)_ui_smoke\.sh|traffic_gate_v1\.php)(?:\s|$)'),
        re.compile(r'(^|/)(?:mysqldump|mariadb-dump|backup_easyappointments\.sh|import_prod_backup\.sh)(?:\s|$)'),
        re.compile(r'(^|/)(?:prod_(?:session|build_cache|release_archive_dump)_retention\.sh)(?:\s|$)'),
    )
    count = 0
    try:
        entries = os.scandir('/proc')
    except OSError:
        reject()
    with entries:
        for entry in entries:
            if not entry.name.isdigit() or int(entry.name) == os.getpid():
                continue
            try:
                descriptor = os.open('/proc/' + entry.name + '/cmdline', os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW)
                try:
                    raw = os.read(descriptor, 131_073)
                finally:
                    os.close(descriptor)
            except (FileNotFoundError, ProcessLookupError):
                continue
            except (PermissionError, OSError):
                reject()
            if len(raw) > 131_072:
                reject()
            command = raw.replace(b'\0', b' ').decode('utf-8', 'replace').strip()
            if command and any(pattern.search(command) for pattern in patterns):
                count += 1
    return count


def assert_activity_gate(orchestrator):
    if activity_count() != 0:
        reject(75)
    try:
        os.stat('active-run.json', dir_fd=orchestrator, follow_symlinks=False)
    except FileNotFoundError:
        pass
    else:
        reject(75)
    try:
        runs = open_child_directory(orchestrator, 'runs', 0o700)
    except FileNotFoundError:
        return
    try:
        names = os.listdir(runs)
        if len(names) > 10_000:
            reject()
        for name in names:
            if re.fullmatch(r'[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}', name) is None:
                reject()
            run = open_child_directory(runs, name, 0o700)
            try:
                state = read_stable_file(run, 'state.json', 4096)
                events = read_stable_file(run, 'events.jsonl', 1_048_576)
                evidence = read_stable_file(run, 'evidence.json', 65_536)
                validate_terminal_history(name, state, events, evidence)
            finally:
                os.close(run)
    finally:
        os.close(runs)


def read_stable_file(parent, leaf, maximum):
    before = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
    descriptor = os.open(leaf, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
    try:
        data = os.read(descriptor, maximum + 1)
        opened = os.fstat(descriptor)
    finally:
        os.close(descriptor)
    after = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
    if (file_identity(before) != file_identity(opened) or file_identity(opened) != file_identity(after) or
            not stat.S_ISREG(opened.st_mode) or opened.st_uid != 0 or opened.st_gid != 0 or
            opened.st_nlink != 1 or stat.S_IMODE(opened.st_mode) != 0o600 or
            len(data) == 0 or len(data) > maximum):
        reject(75)
    return data


def validate_terminal_history(run_id, state_bytes, events, evidence):
    try:
        state = json.loads(state_bytes)
    except json.JSONDecodeError:
        reject(75)
    if (not isinstance(state, dict) or
            (json.dumps(state, sort_keys=True, separators=(',', ':')) + '\n').encode() != state_bytes):
        reject(75)
    terminal = state.get('terminal')
    if (state.get('schema') != 'deployment_host_runner_state.v1' or state.get('run_id') != run_id or
            not isinstance(terminal, dict) or set(terminal) != {'exit_code', 'reason', 'state'} or
            terminal.get('state') != state.get('state') or
            hashlib.sha256(events).hexdigest() != state.get('events_sha256') or
            hashlib.sha256(evidence).hexdigest() != state.get('evidence_sha256')):
        reject(75)
    validator, validator_identity = open_trusted_file(TERMINAL_VALIDATOR, exact_mode=0o555, maximum=1024 * 1024)
    os.close(validator)
    envelope = json.dumps({
        'events': __import__('base64').b64encode(events).decode('ascii'),
        'evidence': __import__('base64').b64encode(evidence).decode('ascii'),
    }, sort_keys=True, separators=(',', ':')).encode('ascii')
    try:
        result = subprocess.run([PHP, '-n', TERMINAL_VALIDATOR], input=envelope, stdout=subprocess.PIPE,
                                stderr=subprocess.DEVNULL, timeout=30, check=False,
                                env={'PATH': '/usr/bin:/bin', 'LANG': 'C', 'LC_ALL': 'C'})
    except (OSError, subprocess.SubprocessError):
        reject(75)
    verify_trusted_path(TERMINAL_VALIDATOR, validator_identity)
    try:
        validated = json.loads(result.stdout)
    except json.JSONDecodeError:
        reject(75)
    expected = {'intent_sha256': state.get('intent_sha256'), 'records': state.get('sequence'),
                'run_id': run_id, 'schema': 'deployment_terminal_bundle_validation.v1',
                'state': state.get('state')}
    if (result.returncode != 0 or len(result.stdout) > 4096 or validated != expected or
            (json.dumps(validated, sort_keys=True, separators=(',', ':')) + '\n').encode() != result.stdout):
        reject(75)


def stable_marker(backups):
    try:
        before = os.stat(MARKER_LEAF, dir_fd=backups, follow_symlinks=False)
    except FileNotFoundError:
        return None
    descriptor = os.open(MARKER_LEAF, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK,
                         dir_fd=backups)
    try:
        data = os.read(descriptor, 64)
        opened = os.fstat(descriptor)
    finally:
        os.close(descriptor)
    after = os.stat(MARKER_LEAF, dir_fd=backups, follow_symlinks=False)
    if (file_identity(before) != file_identity(opened) or file_identity(opened) != file_identity(after) or
            not stat.S_ISREG(opened.st_mode) or opened.st_uid != 0 or opened.st_gid != 0 or
            opened.st_nlink != 1 or stat.S_IMODE(opened.st_mode) != 0o600 or len(data) != 21 or
            data[-1:] != b'\n'):
        reject()
    value = data[:-1].decode('ascii', 'strict')
    try:
        parsed = datetime.datetime.strptime(value, '%Y-%m-%dT%H:%M:%SZ')
    except ValueError:
        reject()
    return parsed, file_identity(opened)


def publish_marker(backups, marker_value, nonce, expected_marker):
    candidate = datetime.datetime.strptime(marker_value, '%Y-%m-%dT%H:%M:%SZ')
    current = stable_marker(backups)
    if expected_marker is None:
        if current is not None:
            reject()
    elif current is None or current[1] != expected_marker[1]:
        reject()
    if current is not None and current[0] > candidate:
        reject()
    temporary = '.last_backup_success.utc.tmp-' + nonce
    if MARKER_TEMP.fullmatch(temporary) is None:
        reject()
    descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | os.O_NOFOLLOW,
                         0o600, dir_fd=backups)
    try:
        write_all(descriptor, (marker_value + '\n').encode('ascii'))
        os.fsync(descriptor)
    finally:
        os.close(descriptor)
    os.replace(temporary, MARKER_LEAF, src_dir_fd=backups, dst_dir_fd=backups)
    os.fsync(backups)


def stream_dump(target, dump_descriptor, dump_identity, config_descriptor, config_identity):
    executable = '/proc/self/fd/' + str(dump_descriptor)
    config = '/proc/self/fd/' + str(config_descriptor)
    arguments = [
        DUMP_PATH,
        '--defaults-file=' + config,
        '--single-transaction',
        '--quick',
        '--skip-lock-tables',
        '--skip-triggers',
        '--skip-routines',
        '--skip-events',
        '--no-tablespaces',
        '--hex-blob',
        '--default-character-set=utf8mb4',
        DATABASE,
    ]
    process = None
    raw_bytes = 0
    deadline = time.monotonic() + DUMP_TIMEOUT_SECONDS
    try:
        process = subprocess.Popen(
            arguments,
            executable=executable,
            pass_fds=(dump_descriptor, config_descriptor),
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
            env={'HOME': '/nonexistent', 'LANG': 'C', 'LC_ALL': 'C', 'PATH': '/usr/bin:/bin'},
            close_fds=True,
        )
        os.set_blocking(process.stdout.fileno(), False)
        with os.fdopen(os.dup(target), 'wb', closefd=True) as raw_target:
            with gzip.GzipFile(filename='', mode='wb', compresslevel=9, fileobj=raw_target, mtime=0) as compressed:
                while True:
                    remaining = deadline - time.monotonic()
                    if remaining <= 0:
                        reject()
                    ready, _, _ = select.select([process.stdout.fileno()], [], [], min(1.0, remaining))
                    if ready:
                        chunk = os.read(process.stdout.fileno(), READ_CHUNK)
                        if chunk:
                            raw_bytes += len(chunk)
                            if raw_bytes > MAX_UNCOMPRESSED_BYTES:
                                reject()
                            compressed.write(chunk)
                            compressed.flush()
                            capacity = os.fstatvfs(target)
                            if (os.fstat(target).st_size > MAX_COMPRESSED_BYTES or
                                    capacity.f_bavail * capacity.f_frsize < MIN_FREE_BYTES):
                                reject()
                            continue
                        break
                    if process.poll() is not None:
                        chunk = os.read(process.stdout.fileno(), READ_CHUNK)
                        if chunk:
                            raw_bytes += len(chunk)
                            if raw_bytes > MAX_UNCOMPRESSED_BYTES:
                                reject()
                            compressed.write(chunk)
                            compressed.flush()
                            capacity = os.fstatvfs(target)
                            if (os.fstat(target).st_size > MAX_COMPRESSED_BYTES or
                                    capacity.f_bavail * capacity.f_frsize < MIN_FREE_BYTES):
                                reject()
                            continue
                        break
        remaining = max(0.01, deadline - time.monotonic())
        try:
            return_code = process.wait(timeout=remaining)
        except subprocess.TimeoutExpired:
            reject()
        if return_code != 0 or raw_bytes == 0:
            reject()
    except (OSError, subprocess.SubprocessError):
        reject()
    finally:
        if process is not None and process.poll() is None:
            process.kill()
            try:
                process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                reject()
        verify_trusted_path(DUMP_PATH, dump_identity)
        verify_trusted_path(CONFIG_PATH, config_identity)
    return raw_bytes


def validate_dump(descriptor, expected_uncompressed):
    opened = os.fstat(descriptor)
    if (not stat.S_ISREG(opened.st_mode) or opened.st_uid != 0 or opened.st_gid != 0 or
            opened.st_nlink != 1 or stat.S_IMODE(opened.st_mode) != 0o600 or opened.st_size <= 0 or
            opened.st_size > MAX_COMPRESSED_BYTES):
        reject()
    digest = hashlib.sha256()
    os.lseek(descriptor, 0, os.SEEK_SET)
    while True:
        chunk = os.read(descriptor, READ_CHUNK)
        if not chunk:
            break
        digest.update(chunk)
    unpacked = 0
    os.lseek(descriptor, 0, os.SEEK_SET)
    try:
        with os.fdopen(os.dup(descriptor), 'rb', closefd=True) as raw:
            with gzip.GzipFile(fileobj=raw, mode='rb') as stream:
                while True:
                    chunk = stream.read(READ_CHUNK)
                    if not chunk:
                        break
                    unpacked += len(chunk)
                    if (unpacked > MAX_UNCOMPRESSED_BYTES or
                            unpacked > opened.st_size * MAX_COMPRESSION_RATIO):
                        reject()
    except (OSError, EOFError, gzip.BadGzipFile):
        reject()
    if unpacked != expected_uncompressed:
        reject()
    return digest.hexdigest(), opened.st_size


def rename_directory_noreplace(parent, source, destination):
    if BACKUP_ID.fullmatch(destination) is None or STAGING_LEAF.fullmatch(source) is None:
        reject()
    result = LIBC.renameat2(parent, source.encode('ascii'), parent, destination.encode('ascii'), RENAME_NOREPLACE)
    if result != 0:
        error = ctypes.get_errno()
        if error in (errno.EEXIST, errno.ENOTEMPTY):
            reject()
        reject()


def cleanup_current_staging(backups, staging, marker_temporary):
    if marker_temporary is not None and MARKER_TEMP.fullmatch(marker_temporary):
        try:
            metadata = os.stat(marker_temporary, dir_fd=backups, follow_symlinks=False)
            if (stat.S_ISREG(metadata.st_mode) and metadata.st_uid == 0 and metadata.st_gid == 0 and
                    metadata.st_nlink == 1 and stat.S_IMODE(metadata.st_mode) == 0o600):
                os.unlink(marker_temporary, dir_fd=backups)
                os.fsync(backups)
        except FileNotFoundError:
            pass
    if staging is None or STAGING_LEAF.fullmatch(staging) is None:
        return
    try:
        stage = open_child_directory(backups, staging, 0o700)
    except FileNotFoundError:
        return
    try:
        entries = set(os.listdir(stage))
        if not entries.issubset({'db', 'meta'}):
            reject()
        for directory_leaf, file_leaf in (('db', 'easyappointments.sql.gz'), ('meta', 'backup.env')):
            if directory_leaf not in entries:
                continue
            directory = open_child_directory(stage, directory_leaf, 0o700)
            try:
                children = set(os.listdir(directory))
                if not children.issubset({file_leaf}):
                    reject()
                if children:
                    metadata = os.stat(file_leaf, dir_fd=directory, follow_symlinks=False)
                    if (not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != 0 or metadata.st_gid != 0 or
                            metadata.st_nlink != 1 or stat.S_IMODE(metadata.st_mode) != 0o600):
                        reject()
                    os.unlink(file_leaf, dir_fd=directory)
                os.fsync(directory)
            finally:
                os.close(directory)
            os.rmdir(directory_leaf, dir_fd=stage)
        os.fsync(stage)
    finally:
        os.close(stage)
    os.rmdir(staging, dir_fd=backups)
    os.fsync(backups)


def create_backup(backups, backup_id, nonce, dump_descriptor, dump_identity, config_descriptor, config_identity):
    staging = '.backup-set-producer-' + nonce + '.tmp'
    if STAGING_LEAF.fullmatch(staging) is None:
        reject()
    os.mkdir(staging, 0o700, dir_fd=backups)
    stage = open_child_directory(backups, staging, 0o700)
    try:
        os.mkdir('db', 0o700, dir_fd=stage)
        database = open_child_directory(stage, 'db', 0o700)
        try:
            target = os.open('easyappointments.sql.gz', os.O_RDWR | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | os.O_NOFOLLOW,
                             0o600, dir_fd=database)
            try:
                unpacked = stream_dump(target, dump_descriptor, dump_identity, config_descriptor, config_identity)
                os.fsync(target)
                digest, compressed = validate_dump(target, unpacked)
                if file_identity(os.fstat(target)) != file_identity(
                        os.stat('easyappointments.sql.gz', dir_fd=database, follow_symlinks=False)):
                    reject()
            finally:
                os.close(target)
            os.fsync(database)
        finally:
            os.close(database)
        os.mkdir('meta', 0o700, dir_fd=stage)
        meta = open_child_directory(stage, 'meta', 0o700)
        try:
            metadata_bytes = (
                'schema=production_backup_set.v1\n'
                'backup_set_id=' + backup_id + '\n'
                'created_at_utc=' + datetime.datetime.strptime(backup_id, '%Y%m%dT%H%M%SZ').strftime('%Y-%m-%dT%H:%M:%SZ') + '\n'
                'dump_sha256=' + digest + '\n'
                'compressed_size_bytes=' + str(compressed) + '\n'
                'uncompressed_size_bytes=' + str(unpacked) + '\n'
            ).encode('ascii')
            descriptor = os.open('backup.env', os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | os.O_NOFOLLOW,
                                 0o600, dir_fd=meta)
            try:
                write_all(descriptor, metadata_bytes)
                os.fsync(descriptor)
            finally:
                os.close(descriptor)
            os.fsync(meta)
        finally:
            os.close(meta)
        os.fsync(stage)
    finally:
        os.close(stage)
    rename_directory_noreplace(backups, staging, backup_id)
    os.fsync(backups)
    return digest, compressed, unpacked


def handoff_bytes(backup_id, digest, compressed, unpacked):
    return (json.dumps({'backup_set_id': backup_id, 'compressed_size_bytes': compressed,
                        'dump_sha256': digest, 'schema': 'production_backup_set_handoff.v1',
                        'uncompressed_size_bytes': unpacked}, sort_keys=True, separators=(',', ':')) + '\n').encode('ascii')


def stable_handoff(backups, missing_ok=False):
    try:
        before = os.stat(HANDOFF_LEAF, dir_fd=backups, follow_symlinks=False)
        descriptor = os.open(HANDOFF_LEAF, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK,
                             dir_fd=backups)
    except FileNotFoundError:
        if missing_ok:
            return None
        reject()
    try:
        data = os.read(descriptor, 4097)
        opened = os.fstat(descriptor)
    finally:
        os.close(descriptor)
    after = os.stat(HANDOFF_LEAF, dir_fd=backups, follow_symlinks=False)
    if (file_identity(before) != file_identity(opened) or file_identity(opened) != file_identity(after) or
            not stat.S_ISREG(opened.st_mode) or opened.st_uid != 0 or opened.st_gid != 0 or
            opened.st_nlink != 1 or stat.S_IMODE(opened.st_mode) != 0o600 or
            len(data) == 0 or len(data) > 4096):
        reject()
    try:
        value = json.loads(data)
    except json.JSONDecodeError:
        reject()
    if (not isinstance(value, dict) or
            set(value) != {'backup_set_id', 'compressed_size_bytes', 'dump_sha256', 'schema',
                           'uncompressed_size_bytes'} or
            value.get('schema') != 'production_backup_set_handoff.v1' or
            not isinstance(value.get('backup_set_id'), str) or
            BACKUP_ID.fullmatch(value['backup_set_id']) is None or
            not isinstance(value.get('dump_sha256'), str) or
            re.fullmatch(r'[0-9a-f]{64}', value['dump_sha256']) is None or
            isinstance(value.get('compressed_size_bytes'), bool) or
            not isinstance(value.get('compressed_size_bytes'), int) or
            value['compressed_size_bytes'] <= 0 or value['compressed_size_bytes'] > MAX_COMPRESSED_BYTES or
            isinstance(value.get('uncompressed_size_bytes'), bool) or
            not isinstance(value.get('uncompressed_size_bytes'), int) or
            value['uncompressed_size_bytes'] <= 0 or value['uncompressed_size_bytes'] > MAX_UNCOMPRESSED_BYTES or
            handoff_bytes(value['backup_set_id'], value['dump_sha256'], value['compressed_size_bytes'],
                          value['uncompressed_size_bytes']) != data):
        reject()
    return value, file_identity(opened), data


def publish_handoff(backups, backup_id, digest, compressed, unpacked, nonce, expected):
    data = handoff_bytes(backup_id, digest, compressed, unpacked)
    current = stable_handoff(backups, missing_ok=True)
    if current is not None and current[2] == data:
        return
    if expected is None:
        if current is not None:
            reject()
    elif current is None or current[1] != expected[1] or current[2] != expected[2]:
        reject()
    temporary = '.last_backup_set.json.tmp-' + nonce
    descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | os.O_NOFOLLOW,
                         0o600, dir_fd=backups)
    try:
        write_all(descriptor, data)
        os.fsync(descriptor)
    finally:
        os.close(descriptor)
    os.replace(temporary, HANDOFF_LEAF, src_dir_fd=backups, dst_dir_fd=backups)
    os.fsync(backups)


def reconcile_temporary_files(backups):
    names = os.listdir(backups)
    if len(names) > 10_000:
        reject()
    for name in names:
        if STAGING_LEAF.fullmatch(name):
            cleanup_current_staging(backups, name, None)
        elif MARKER_TEMP.fullmatch(name) or HANDOFF_TEMP.fullmatch(name):
            metadata = os.stat(name, dir_fd=backups, follow_symlinks=False)
            if (not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != 0 or metadata.st_gid != 0 or
                    metadata.st_nlink != 1 or stat.S_IMODE(metadata.st_mode) != 0o600 or metadata.st_size > 4096):
                reject()
            os.unlink(name, dir_fd=backups)
            os.fsync(backups)
        elif name.startswith('.backup-set-producer-') or name.startswith('.last_backup_success.utc.tmp-') or name.startswith('.last_backup_set.json.tmp-'):
            reject()


def validate_backup_set(backups, backup_id):
    backup = open_child_directory(backups, backup_id, 0o700)
    try:
        if set(os.listdir(backup)) != {'db', 'meta'}:
            reject()
        database = open_child_directory(backup, 'db', 0o700)
        meta = open_child_directory(backup, 'meta', 0o700)
        try:
            if set(os.listdir(database)) != {'easyappointments.sql.gz'} or set(os.listdir(meta)) != {'backup.env'}:
                reject()
            metadata_bytes = read_stable_file(meta, 'backup.env', 4096)
            lines = metadata_bytes.decode('ascii').splitlines()
            if len(lines) != 6 or any('=' not in line for line in lines):
                reject()
            values = dict(line.split('=', 1) for line in lines)
            expected_keys = {'schema', 'backup_set_id', 'created_at_utc', 'dump_sha256',
                             'compressed_size_bytes', 'uncompressed_size_bytes'}
            if set(values) != expected_keys or values['schema'] != 'production_backup_set.v1' or values['backup_set_id'] != backup_id:
                reject()
            descriptor = os.open('easyappointments.sql.gz', os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK,
                                 dir_fd=database)
            try:
                unpacked_expected = int(values['uncompressed_size_bytes'])
                digest, compressed = validate_dump(descriptor, unpacked_expected)
            finally:
                os.close(descriptor)
            created = datetime.datetime.strptime(backup_id, '%Y%m%dT%H%M%SZ').strftime('%Y-%m-%dT%H:%M:%SZ')
            if (values['created_at_utc'] != created or values['dump_sha256'] != digest or
                    values['compressed_size_bytes'] != str(compressed)):
                reject()
        finally:
            os.close(meta)
            os.close(database)
    finally:
        os.close(backup)
    return digest, compressed, unpacked_expected, created


def attach_unmarked_set(backups, current_marker, nonce):
    marker_id = None if current_marker is None else current_marker[0].strftime('%Y%m%dT%H%M%SZ')
    current_handoff = stable_handoff(backups, missing_ok=True)
    if current_handoff is not None and marker_id is not None and current_handoff[0]['backup_set_id'] < marker_id:
        reject()
    candidates = sorted(name for name in os.listdir(backups)
                        if BACKUP_ID.fullmatch(name) and (marker_id is None or name > marker_id))
    if not candidates:
        if current_handoff is None:
            # Migration from the legacy backup job: its global success marker
            # predates the protected ROB-466 handoff. The next fresh set will
            # establish the new marker+handoff pair under both locks.
            return None
        if marker_id != current_handoff[0]['backup_set_id']:
            reject()
        digest, compressed, unpacked, _ = validate_backup_set(backups, marker_id)
        if current_handoff[2] != handoff_bytes(marker_id, digest, compressed, unpacked):
            reject()
        return None
    if len(candidates) != 1:
        reject()
    backup_id = candidates[0]
    digest, compressed, unpacked_expected, created = validate_backup_set(backups, backup_id)
    if current_handoff is not None and current_handoff[0]['backup_set_id'] not in {marker_id, backup_id}:
        reject()
    publish_handoff(backups, backup_id, digest, compressed, unpacked_expected, nonce, current_handoff)
    publish_marker(backups, created, nonce, current_marker)
    return compressed, unpacked_expected


def main():
    bind_to_parent_death()
    if len(sys.argv) != 1 or os.geteuid() != 0 or os.getuid() != 0 or os.getegid() != 0 or os.getgid() != 0:
        reject()
    os.umask(0o077)
    resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
    backup_id = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    marker_value = datetime.datetime.strptime(backup_id, '%Y%m%dT%H%M%SZ').strftime('%Y-%m-%dT%H:%M:%SZ')
    nonce = os.urandom(16).hex()
    orchestrator = open_absolute_directory(ORCHESTRATOR_ROOT, 0o700)
    backups = None
    global_lock = None
    private_lock = None
    dump_descriptor = None
    config_descriptor = None
    staging = None
    marker_temporary = None
    try:
        locks = open_child_directory(orchestrator, 'locks', 0o700)
        try:
            global_lock = open_lock(locks, GLOBAL_LOCK_LEAF)
        finally:
            os.close(locks)
        backups = open_absolute_directory(BACKUP_ROOT)
        private_lock = open_lock(backups, PRIVATE_LOCK_LEAF, create=True)
        assert_activity_gate(orchestrator)
        reconcile_temporary_files(backups)
        expected_marker = stable_marker(backups)
        attached = attach_unmarked_set(backups, expected_marker, nonce)
        if attached is not None:
            emit('attached', backup_sets_published=0, compressed_size_bytes=attached[0],
                 uncompressed_size_bytes=attached[1])
            return
        expected_handoff = stable_handoff(backups, missing_ok=True)
        candidate_marker = datetime.datetime.strptime(marker_value, '%Y-%m-%dT%H:%M:%SZ')
        if expected_marker is not None and expected_marker[0] > candidate_marker:
            reject()
        dump_descriptor, dump_identity = open_trusted_file(DUMP_PATH, executable=True)
        config_descriptor, config_identity = open_trusted_file(
            CONFIG_PATH, exact_mode=0o600, maximum=MAX_CONFIG_BYTES)
        validate_connection_config(config_descriptor)
        capacity = os.fstatvfs(backups)
        if capacity.f_bavail * capacity.f_frsize < MIN_FREE_BYTES:
            reject()
        staging = '.backup-set-producer-' + nonce + '.tmp'
        marker_temporary = '.last_backup_success.utc.tmp-' + nonce
        digest, compressed, unpacked = create_backup(
            backups, backup_id, nonce, dump_descriptor, dump_identity, config_descriptor, config_identity)
        staging = None
        publish_handoff(backups, backup_id, digest, compressed, unpacked, nonce, expected_handoff)
        publish_marker(backups, marker_value, nonce, expected_marker)
        marker_temporary = None
        emit('published', backup_sets_published=1, compressed_size_bytes=compressed,
             uncompressed_size_bytes=unpacked)
    finally:
        if backups is not None:
            cleanup_current_staging(backups, staging, marker_temporary)
        for descriptor in (config_descriptor, dump_descriptor, private_lock, backups, global_lock, orchestrator):
            if descriptor is not None:
                os.close(descriptor)


if __name__ == '__main__':
    try:
        main()
    except ProducerError as error:
        emit('busy' if error.code == 75 else 'rejected')
        raise SystemExit(error.code)
    except (OSError, ValueError, UnicodeError):
        emit('rejected')
        raise SystemExit(70)
