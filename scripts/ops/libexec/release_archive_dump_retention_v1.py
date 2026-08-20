#!/usr/bin/python3
"""Fail-closed retention for production releases, archive pairs, and verified dumps."""

import datetime
import fcntl
import hashlib
import json
import os
import pwd
import re
import secrets
import stat
import sys
import tarfile


SCHEMA = 'prod_release_archive_dump_retention.v3'
MARKER_SCHEMA = 'prod_release_archive_dump_retention_marker.v1'
MARKER_STATUS_SCHEMA = 'prod_release_archive_dump_retention_marker_status.v1'
WEB_ROOT = '/var/www/html'
APP_ROOT = '/var/www/html/easyappointments'
RELEASES_ROOT = '/root/releases'
HOLD_PATH = '/etc/fh/legacy-release-hold.v1.json'
BACKUP_ROOT = '/root/backups/easyappointments'
ATTESTATION_ROOT = '/var/lib/fh-deploy-evidence/dump-attestations'
RESTORE_IMAGE = 'mariadb@sha256:2f2b6bbcdbaf88afe53b76cb8d73927b623559180c5ab15db2049736f32ec590'
STATE_ROOT = '/var/lib/fh-release-retention'
ORCHESTRATOR_ROOT = '/var/lib/fh-deploy-orchestrator'
GLOBAL_LOCK_LEAF = 'fh-production-change.lock'
MARKER_LEAF = 'last-success.json'
MARKER_MAX_BYTES = 4096
RELEASE_DIR_MIN_AGE = 7 * 86_400
ARCHIVE_MIN_AGE = 30 * 86_400
DUMP_MIN_AGE = 30 * 86_400
KEEP_ARCHIVE_PAIRS = 4
KEEP_VERIFIED_DUMPS = 2
MAX_RELEASE_DIR_DELETE = 4
MAX_ARCHIVE_PAIR_DELETE = 8
MAX_DUMP_SET_DELETE = 4
MAX_PENDING_ENTRIES = 32
MAX_CLASS_SCAN = 10_000
MAX_TREE_ENTRIES = 1_000_000
MAX_ARCHIVE_BYTES = 16 * 1024 * 1024 * 1024
MAX_LEGACY_HOLD_STAGE_ENTRIES = 1_000_000
MAX_LEGACY_HOLD_STAGE_UNPACKED_BYTES = 68_719_476_736
LEGACY_HOLD_TEMP_SCRATCH_BYTES = 67_108_864
MAX_SIDECAR_BYTES = 4096
MAX_ATTESTATION_BYTES = 4096
MAX_DUMP_UNCOMPRESSED_BYTES = 64 * 1024 * 1024 * 1024
ABSOLUTE_FREE_BYTES = 2 * 1024 * 1024 * 1024
MAX_USED_PERCENT = 85
FIXED_HEADROOM_BYTES = 512 * 1024 * 1024
RELEASE_ID = re.compile(r'[A-Za-z0-9._-]{1,128}\Z')
LEGACY_HOLD_RELEASE_ID = re.compile(r'[A-Za-z0-9][A-Za-z0-9._-]{0,127}\Z')
RUN_ID = re.compile(r'[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\Z')
SHA256 = re.compile(r'[0-9a-f]{64}\Z')
BACKUP_SET = re.compile(r'20[0-9]{6}T[0-9]{6}Z\Z')
TERMINAL_STATES = {
    'succeeded',
    'failed_before_write',
    'failed_pre_switch',
    'failed_post_switch_rollback_succeeded',
    'failed_post_switch_rollback_failed',
    'manual_recovery_required',
}
MUTATION_COUNT_KEYS = (
    'archive_files',
    'dump_sets',
    'release_dirs',
    'pending_archive_files',
    'pending_dump_sets',
    'pending_release_dirs',
    'marker_temp_files',
)


class RetentionError(Exception):
    def __init__(self, reason, code=70):
        super().__init__(reason)
        self.reason = reason
        self.code = code


class MutationLedger:
    """Aggregate irreversible namespace removals without exposing artifact names."""

    def __init__(self):
        self.counts = {key: 0 for key in MUTATION_COUNT_KEYS}
        self.in_flight = 0

    def begin(self):
        self.in_flight += 1

    def confirm(self, key):
        if key not in self.counts or self.in_flight < 1:
            reject('mutation_ledger_invalid')
        self.finish()
        self.counts[key] += 1

    def finish(self):
        if self.in_flight < 1:
            reject('mutation_ledger_invalid')
        self.in_flight -= 1

    def fields(self):
        if self.in_flight:
            return {
                'deletion_performed': None,
                'mutation_counts': self.counts.copy(),
                'mutation_outcome': 'unknown',
            }
        known = sum(self.counts.values()) > 0
        return {
            'deletion_performed': known,
            'mutation_counts': self.counts.copy(),
            'mutation_outcome': 'known' if known else 'none',
        }


MUTATIONS = MutationLedger()


def reject(reason='rejected', code=70):
    raise RetentionError(reason, code)


def emit(payload):
    sys.stdout.write(json.dumps(payload, sort_keys=True, separators=(',', ':')) + '\n')
    sys.stdout.flush()


def canonical_json(payload):
    return (json.dumps(payload, sort_keys=True, separators=(',', ':')) + '\n').encode('utf-8')


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


def open_child_directory(parent, leaf, owner_uid=0, owner_gid=0, exact_mode=None, writable_ok=False):
    before = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
    descriptor = os.open(
        leaf,
        os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW,
        dir_fd=parent,
    )
    try:
        opened = os.fstat(descriptor)
        mode = stat.S_IMODE(opened.st_mode)
        mode_ok = mode == exact_mode if exact_mode is not None else (writable_ok or mode & 0o022 == 0)
        if directory_identity(before) != directory_identity(opened) or not stat.S_ISDIR(opened.st_mode):
            reject('unsafe_directory_identity')
        if opened.st_uid != owner_uid or opened.st_gid != owner_gid:
            reject('unsafe_directory_owner')
        if not mode_ok:
            reject('unsafe_directory_mode')
        return descriptor
    except BaseException:
        os.close(descriptor)
        raise


def open_absolute_directory(path, final_uid=0, final_gid=0, exact_mode=None, writable_ok=False):
    if not path.startswith('/') or path == '/':
        reject('unsafe_directory')
    parts = [part for part in path.split('/') if part]
    descriptor = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW)
    try:
        slash = os.fstat(descriptor)
        if not stat.S_ISDIR(slash.st_mode) or slash.st_uid != 0 or stat.S_IMODE(slash.st_mode) & 0o022:
            reject('unsafe_directory')
        for index, leaf in enumerate(parts):
            final = index == len(parts) - 1
            next_descriptor = open_child_directory(
                descriptor,
                leaf,
                final_uid if final else 0,
                final_gid if final else 0,
                exact_mode if final else None,
                writable_ok if final else False,
            )
            os.close(descriptor)
            descriptor = next_descriptor
        return descriptor
    except BaseException:
        os.close(descriptor)
        raise


def prepare_state_directory():
    parent = open_absolute_directory('/var/lib')
    try:
        try:
            os.mkdir('fh-release-retention', 0o700, dir_fd=parent)
            os.fsync(parent)
        except FileExistsError:
            pass
        return open_child_directory(parent, 'fh-release-retention', exact_mode=0o700)
    finally:
        os.close(parent)


def stable_regular(directory, leaf, uid, gid, modes, max_bytes, missing_ok=False, empty_ok=False):
    try:
        before = os.stat(leaf, dir_fd=directory, follow_symlinks=False)
    except FileNotFoundError:
        if missing_ok:
            return None
        raise
    descriptor = os.open(
        leaf,
        os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK,
        dir_fd=directory,
    )
    try:
        opened = os.fstat(descriptor)
        if (
            file_identity(before) != file_identity(opened)
            or not stat.S_ISREG(opened.st_mode)
            or opened.st_uid != uid
            or opened.st_gid != gid
            or stat.S_IMODE(opened.st_mode) not in modes
            or opened.st_nlink != 1
            or (not empty_ok and opened.st_size <= 0)
            or opened.st_size > max_bytes
        ):
            reject('unsafe_file')
        data = bytearray()
        while len(data) <= max_bytes:
            chunk = os.read(descriptor, min(65_536, max_bytes + 1 - len(data)))
            if not chunk:
                break
            data.extend(chunk)
        after = os.fstat(descriptor)
        post = os.stat(leaf, dir_fd=directory, follow_symlinks=False)
        if file_identity(opened) != file_identity(after) or file_identity(after) != file_identity(post):
            reject('file_changed', 75)
        if len(data) != opened.st_size:
            reject('file_changed', 75)
        return bytes(data), file_identity(opened), opened
    finally:
        os.close(descriptor)


def stable_hash(directory, leaf, uid, gid, modes, max_bytes):
    before = os.stat(leaf, dir_fd=directory, follow_symlinks=False)
    descriptor = os.open(
        leaf,
        os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK,
        dir_fd=directory,
    )
    try:
        opened = os.fstat(descriptor)
        if (
            file_identity(before) != file_identity(opened)
            or not stat.S_ISREG(opened.st_mode)
            or opened.st_uid != uid
            or opened.st_gid != gid
            or stat.S_IMODE(opened.st_mode) not in modes
            or opened.st_nlink != 1
            or opened.st_size <= 0
            or opened.st_size > max_bytes
        ):
            reject('unsafe_file')
        digest = hashlib.sha256()
        total = 0
        while True:
            chunk = os.read(descriptor, 1024 * 1024)
            if not chunk:
                break
            digest.update(chunk)
            total += len(chunk)
            if total > max_bytes:
                reject('file_too_large')
        after = os.fstat(descriptor)
        post = os.stat(leaf, dir_fd=directory, follow_symlinks=False)
        if (
            file_identity(opened) != file_identity(after)
            or file_identity(after) != file_identity(post)
            or total != opened.st_size
        ):
            reject('file_changed', 75)
        return digest.hexdigest(), opened.st_size, file_identity(opened), opened
    finally:
        os.close(descriptor)


def normalize_legacy_tar_member(name):
    normalized = name[2:] if name.startswith('./') else name
    parts = normalized.split('/')
    if (
        not normalized
        or normalized.startswith('/')
        or len(normalized.encode('utf-8')) > 4096
        or '\x00' in normalized
        or '\\' in normalized
        or any(part in ('', '.', '..') or part.startswith('._') or len(part.encode('utf-8')) > 255 for part in parts)
        or any(ord(character) < 32 or ord(character) == 127 for character in normalized)
    ):
        reject('unsafe_legacy_archive')
    return normalized


def inspect_legacy_archive(directory, leaf):
    before = os.stat(leaf, dir_fd=directory, follow_symlinks=False)
    descriptor = os.open(
        leaf,
        os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK,
        dir_fd=directory,
    )
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
            or opened.st_size > MAX_ARCHIVE_BYTES
        ):
            reject('unsafe_file')
        digest = hashlib.sha256()
        total = 0
        while True:
            chunk = os.read(descriptor, 1024 * 1024)
            if not chunk:
                break
            digest.update(chunk)
            total += len(chunk)
            if total > MAX_ARCHIVE_BYTES:
                reject('file_too_large')
        physical_entries = entries = file_count = 0
        unpacked = 4096
        stage_types = {}
        explicit_directories = set()
        os.lseek(descriptor, 0, os.SEEK_SET)
        try:
            with os.fdopen(os.dup(descriptor), 'rb', closefd=True) as source:
                with tarfile.open(fileobj=source, mode='r:gz') as archive:
                    for member in archive:
                        physical_entries += 1
                        if physical_entries > MAX_LEGACY_HOLD_STAGE_ENTRIES + 1:
                            reject('unsafe_legacy_archive')
                        if member.name in ('.', './') and member.isdir():
                            continue
                        entries += 1
                        if entries > MAX_LEGACY_HOLD_STAGE_ENTRIES:
                            reject('unsafe_legacy_archive')
                        name = normalize_legacy_tar_member(member.name)
                        if member.issym() or member.islnk() or member.isdev() or not (member.isfile() or member.isdir()):
                            reject('unsafe_legacy_archive')
                        if name in stage_types:
                            # Permit a directory entry that follows a child
                            # which implicitly created that directory, while
                            # retaining duplicate and type-conflict rejection.
                            if member.isdir() and stage_types[name] == 'directory' and name not in explicit_directories:
                                explicit_directories.add(name)
                                continue
                            reject('unsafe_legacy_archive')
                        parts = name.split('/')
                        for index in range(1, len(parts)):
                            parent = '/'.join(parts[:index])
                            if parent in stage_types:
                                if stage_types[parent] == 'file':
                                    reject('unsafe_legacy_archive')
                                continue
                            stage_types[parent] = 'directory'
                            unpacked += 4096
                        stage_types[name] = 'directory' if member.isdir() else 'file'
                        if member.isdir():
                            explicit_directories.add(name)
                        if member.isdir():
                            unpacked += 4096
                        else:
                            if member.size < 0:
                                reject('unsafe_legacy_archive')
                            file_count += 1
                            unpacked += max(4096, ((member.size + 4095) // 4096) * 4096)
                        if len(stage_types) > MAX_LEGACY_HOLD_STAGE_ENTRIES or unpacked > MAX_LEGACY_HOLD_STAGE_UNPACKED_BYTES:
                            reject('unsafe_legacy_archive')
        except RetentionError:
            raise
        except (OSError, EOFError, tarfile.TarError, ValueError):
            reject('unsafe_legacy_archive')
        after = os.fstat(descriptor)
        post = os.stat(leaf, dir_fd=directory, follow_symlinks=False)
        if file_identity(opened) != file_identity(after) or file_identity(after) != file_identity(post) or total != opened.st_size:
            reject('file_changed', 75)
        return (
            digest.hexdigest(),
            opened.st_size,
            file_identity(opened),
            opened,
            {
                'stage_file_count': file_count,
                'stage_inode_count': len(stage_types) + 1,
                'stage_unpacked_bytes': unpacked,
                'temp_scratch_bytes': LEGACY_HOLD_TEMP_SCRATCH_BYTES,
            },
        )
    finally:
        os.close(descriptor)


def decode_canonical(data, max_bytes):
    if not isinstance(data, bytes) or len(data) <= 0 or len(data) > max_bytes:
        reject('invalid_json')
    try:
        decoded = json.loads(data.decode('utf-8'))
    except (UnicodeDecodeError, json.JSONDecodeError):
        reject('invalid_json')
    if not isinstance(decoded, dict) or canonical_json(decoded) != data:
        reject('noncanonical_json')
    return decoded


def read_release_marker(directory):
    record = stable_regular(directory, '_RELEASE', 0, 0, {0o644, 0o640, 0o600}, 512)
    data = record[0]
    try:
        release_id = data.decode('ascii').split()[0]
    except (UnicodeDecodeError, IndexError):
        reject('invalid_release_marker')
    if RELEASE_ID.fullmatch(release_id) is None:
        reject('invalid_release_marker')
    return release_id


def validate_provenance(data, expected_release, archive_sha, archive_size):
    value = decode_canonical(data, MAX_SIDECAR_BYTES)
    if set(value) != {
        'archive', 'capacity_bounds', 'expected_commit', 'observed_commit',
        'release_id', 'schema', 'source',
    }:
        reject('invalid_release_sidecar')
    archive = value.get('archive')
    bounds = value.get('capacity_bounds')
    source = value.get('source')
    if (
        value.get('schema') != 'release_build_provenance.v1'
        or value.get('release_id') != expected_release
        or not isinstance(archive, dict)
        or set(archive) != {'name', 'sha256', 'size_bytes'}
        or archive.get('name') != expected_release + '.tar.gz'
        or archive.get('sha256') != archive_sha
        or archive.get('size_bytes') != archive_size
        or SHA256.fullmatch(str(archive.get('sha256'))) is None
        or not isinstance(bounds, dict)
        or set(bounds) != {'stage_file_count', 'stage_inode_count', 'stage_unpacked_bytes', 'temp_scratch_bytes'}
        or not isinstance(source, dict)
        or set(source) != {'build_script_sha256', 'composer_lock_sha256', 'deploy_ea_sha256', 'package_lock_sha256'}
        or any(SHA256.fullmatch(str(source.get(field))) is None for field in source)
        or re.fullmatch(r'[0-9a-f]{40}', str(value.get('expected_commit'))) is None
        or value.get('observed_commit') != value.get('expected_commit')
    ):
        reject('invalid_release_sidecar')
    for field in ('stage_file_count', 'stage_inode_count', 'stage_unpacked_bytes', 'temp_scratch_bytes'):
        if isinstance(bounds.get(field), bool) or not isinstance(bounds.get(field), int) or bounds[field] <= 0:
            reject('invalid_release_sidecar')
    return value


def read_legacy_hold():
    """Read the permanent host-local hold, without exposing its identities."""
    try:
        parent = open_absolute_directory('/etc/fh', exact_mode=0o700)
    except FileNotFoundError:
        return None
    try:
        record = stable_regular(parent, 'legacy-release-hold.v1.json', 0, 0, {0o600}, 65_536)
        data = record[0]
    except FileNotFoundError:
        return None
    finally:
        os.close(parent)
    try:
        value = json.loads(data.decode('utf-8'))
    except (UnicodeDecodeError, json.JSONDecodeError):
        reject('unsafe_legacy_hold')
    if canonical_json(value) != data or not isinstance(value, dict) or value.get('schema') != 'legacy_release_hold.v1':
        reject('unsafe_legacy_hold')
    targets = value.get('targets')
    if not isinstance(targets, list) or len(targets) != 2:
        reject('unsafe_legacy_hold')
    result = {}
    for role, target in zip(('current', 'rollback'), targets):
        if (not isinstance(target, dict) or set(target) != {'archive', 'capacity_bounds', 'release_id', 'role_at_provisioning'}
                or target.get('role_at_provisioning') != role):
            reject('unsafe_legacy_hold')
        archive = target['archive']; bounds = target['capacity_bounds']; release_id = target['release_id']
        if (not isinstance(release_id, str) or LEGACY_HOLD_RELEASE_ID.fullmatch(release_id) is None
                or not isinstance(archive, dict) or set(archive) != {'name', 'sha256', 'size_bytes'}
                or archive['name'] != release_id + '.tar.gz' or SHA256.fullmatch(str(archive['sha256'])) is None
                or isinstance(archive['size_bytes'], bool) or not isinstance(archive['size_bytes'], int)
                or archive['size_bytes'] <= 0 or archive['size_bytes'] > MAX_ARCHIVE_BYTES
                or not isinstance(bounds, dict) or set(bounds) != {'stage_file_count', 'stage_inode_count', 'stage_unpacked_bytes', 'temp_scratch_bytes'}
                or any(isinstance(bounds[field], bool) or not isinstance(bounds[field], int) or bounds[field] <= 0 for field in bounds)
                or bounds['stage_file_count'] > MAX_LEGACY_HOLD_STAGE_ENTRIES
                or bounds['stage_inode_count'] > MAX_LEGACY_HOLD_STAGE_ENTRIES + 1
                or bounds['stage_unpacked_bytes'] > MAX_LEGACY_HOLD_STAGE_UNPACKED_BYTES
                or bounds['temp_scratch_bytes'] != LEGACY_HOLD_TEMP_SCRATCH_BYTES):
            reject('unsafe_legacy_hold')
        result[release_id] = {'sha256': archive['sha256'], 'size_bytes': archive['size_bytes'], 'capacity_bounds': bounds, 'role': role}
    if len(result) != 2:
        reject('unsafe_legacy_hold')
    return result


def validate_attestation(data, expected_sha, expected_size):
    value = decode_canonical(data, MAX_ATTESTATION_BYTES)
    if set(value) != {'attested_at_utc', 'dump', 'schema', 'verification'}:
        reject('invalid_dump_attestation')
    dump = value.get('dump')
    verification = value.get('verification')
    if (
        value.get('schema') != 'deployment_dump_attestation.v1'
        or not isinstance(dump, dict)
        or set(dump) != {'created_at_utc', 'sha256', 'size_bytes', 'uncompressed_size_bytes'}
        or dump.get('sha256') != expected_sha
        or dump.get('size_bytes') != expected_size
        or SHA256.fullmatch(str(dump.get('sha256'))) is None
        or isinstance(dump.get('uncompressed_size_bytes'), bool)
        or not isinstance(dump.get('uncompressed_size_bytes'), int)
        or dump.get('uncompressed_size_bytes') <= 0
        or dump.get('uncompressed_size_bytes') > MAX_DUMP_UNCOMPRESSED_BYTES
        or not isinstance(verification, dict)
        or set(verification) != {
            'gzip_verified', 'image', 'method', 'restore_verified', 'restored_at_utc',
            'restored_datadir_allocated_bytes', 'restored_datadir_inode_count', 'sha256_verified',
        }
        or verification.get('method') != 'mariadb_10_11_isolated_restore_v1'
        or verification.get('sha256_verified') is not True
        or verification.get('gzip_verified') is not True
        or verification.get('restore_verified') is not True
        or verification.get('image') != RESTORE_IMAGE
    ):
        reject('invalid_dump_attestation')
    for field in ('restored_datadir_allocated_bytes', 'restored_datadir_inode_count'):
        if isinstance(verification.get(field), bool) or not isinstance(verification.get(field), int) or verification[field] <= 0:
            reject('invalid_dump_attestation')
    created = parse_utc(dump.get('created_at_utc'))
    restored = parse_utc(verification.get('restored_at_utc'))
    attested = parse_utc(value.get('attested_at_utc'))
    if created > restored or restored > attested:
        reject('invalid_dump_attestation')
    return value


def parse_utc(value):
    if not isinstance(value, str) or re.fullmatch(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z', value) is None:
        reject('invalid_timestamp')
    try:
        return int(datetime.datetime.strptime(value, '%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=datetime.timezone.utc).timestamp())
    except ValueError:
        reject('invalid_timestamp')


def decode_mount_field(value):
    try:
        return re.sub(
            r'\\([0-7]{3})',
            lambda match: chr(int(match.group(1), 8)),
            value,
        )
    except (TypeError, ValueError):
        reject('mount_state_unknown')


def assert_no_nested_mounts(web_names):
    protected_prefixes = (
        APP_ROOT + '/',
        RELEASES_ROOT + '/',
        BACKUP_ROOT + '/',
        ATTESTATION_ROOT + '/',
        ORCHESTRATOR_ROOT + '/',
    )
    protected_release_paths = {
        WEB_ROOT + '/' + name
        for name in web_names
        if name.startswith('easyappointments_')
    }
    try:
        with open('/proc/self/mountinfo', 'r', encoding='utf-8') as handle:
            for line in handle:
                fields = line.rstrip('\n').split(' ')
                if len(fields) < 7 or '-' not in fields:
                    reject('mount_state_unknown')
                mount_point = decode_mount_field(fields[4])
                if any(mount_point.startswith(prefix) for prefix in protected_prefixes):
                    reject('nested_mount_boundary')
                if any(
                    mount_point == path or mount_point.startswith(path + '/')
                    for path in protected_release_paths
                ):
                    reject('nested_mount_boundary')
    except (OSError, UnicodeError):
        reject('mount_state_unknown')
def open_global_lock():
    root = open_absolute_directory(ORCHESTRATOR_ROOT, exact_mode=0o700)
    try:
        locks = open_child_directory(root, 'locks', exact_mode=0o700)
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


def activity_count():
    patterns = (
        re.compile(r'(^|/)(?:deploy_ea\.sh|deployment_host_runner_v1\.php|zero_surprise_replay\.php)(?:\s|$)'),
        re.compile(r'(^|/)(?:prod_(?:customers|provider)_ui_smoke\.sh|traffic_gate_v1\.php)(?:\s|$)'),
        re.compile(r'(^|/)(?:mysqldump|mariadb-dump|backup_easyappointments\.sh|backup_set_producer_v1\.py|fh-backup-set-producer-v1|prod_backup_set_producer\.sh|import_prod_backup\.sh)(?:\s|$)'),
        re.compile(r'(^|/)(?:prod_(?:session|build_cache|release_archive_dump)_retention\.sh)(?:\s|$)'),
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


def assert_no_nonterminal_runs():
    root = open_absolute_directory(ORCHESTRATOR_ROOT, exact_mode=0o700)
    try:
        if stable_regular(root, 'active-run.json', 0, 0, {0o600}, 4096, missing_ok=True) is not None:
            reject('active_host_runner', 75)
        try:
            runs = open_child_directory(root, 'runs', exact_mode=0o700)
        except FileNotFoundError:
            return
    finally:
        os.close(root)
    try:
        names = os.listdir(runs)
        if len(names) > MAX_CLASS_SCAN:
            reject('run_scan_limit')
        for name in names:
            if RUN_ID.fullmatch(name) is None:
                reject('unsafe_run_entry')
            run = open_child_directory(runs, name, exact_mode=0o700)
            try:
                state_record = stable_regular(run, 'state.json', 0, 0, {0o600}, 4096, missing_ok=True)
                events_record = stable_regular(run, 'events.jsonl', 0, 0, {0o600}, 1_048_576, missing_ok=True)
                if state_record is None:
                    if events_record is not None:
                        reject('unreconciled_host_runner', 75)
                    continue
                state_value = decode_canonical(state_record[0], 4096)
                terminal = state_value.get('terminal')
                if (
                    not isinstance(terminal, dict)
                    or set(terminal) != {'exit_code', 'reason', 'state'}
                    or terminal.get('state') not in TERMINAL_STATES
                ):
                    reject('active_host_runner', 75)
                if events_record is None:
                    reject('unreconciled_host_runner', 75)
            finally:
                os.close(run)
    finally:
        os.close(runs)


def scan_tree(directory, allowed_uids, expected_device):
    identities = set()
    logical = 0
    allocated = 0
    inodes = 0
    stack = [os.dup(directory)]
    try:
        while stack:
            current = stack.pop()
            try:
                root_meta = os.fstat(current)
                if root_meta.st_dev != expected_device or root_meta.st_uid not in allowed_uids:
                    reject('unsafe_tree')
                identities.add((root_meta.st_dev, root_meta.st_ino))
                inodes += 1
                names = os.listdir(current)
                if inodes + len(names) > MAX_TREE_ENTRIES:
                    reject('tree_scan_limit')
                for name in names:
                    before = os.stat(name, dir_fd=current, follow_symlinks=False)
                    if before.st_dev != expected_device or before.st_uid not in allowed_uids:
                        reject('unsafe_tree')
                    if stat.S_ISDIR(before.st_mode):
                        if stat.S_IMODE(before.st_mode) & 0o022:
                            reject('unsafe_tree')
                        child = os.open(
                            name,
                            os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW,
                            dir_fd=current,
                        )
                        if directory_identity(before) != directory_identity(os.fstat(child)):
                            os.close(child)
                            reject('tree_changed', 75)
                        stack.append(child)
                        continue
                    if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or stat.S_IMODE(before.st_mode) & 0o022:
                        reject('unsafe_tree')
                    descriptor = os.open(
                        name,
                        os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK,
                        dir_fd=current,
                    )
                    try:
                        opened = os.fstat(descriptor)
                        if file_identity(before) != file_identity(opened):
                            reject('tree_changed', 75)
                        identities.add((opened.st_dev, opened.st_ino))
                        logical += opened.st_size
                        allocated += opened.st_blocks * 512
                        inodes += 1
                    finally:
                        os.close(descriptor)
            finally:
                os.close(current)
    except BaseException:
        for descriptor in stack:
            os.close(descriptor)
        raise
    return {'allocated': allocated, 'identities': identities, 'inodes': inodes, 'logical': logical}


def validate_candidate_tree(parent, leaf, allowed_uids, expected_device):
    before = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
    descriptor = os.open(
        leaf,
        os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW,
        dir_fd=parent,
    )
    try:
        opened = os.fstat(descriptor)
        if directory_identity(before) != directory_identity(opened):
            reject('candidate_directory_changed', 75)
        if opened.st_dev != expected_device:
            reject('candidate_mount_boundary')
        if opened.st_uid not in allowed_uids:
            reject('candidate_directory_owner')
        if stat.S_IMODE(opened.st_mode) & 0o022:
            reject('candidate_directory_mode')
        result = scan_tree(descriptor, allowed_uids, expected_device)
        result['identity'] = directory_identity(opened)
        result['mtime_ns'] = opened.st_mtime_ns
        return result
    finally:
        os.close(descriptor)


def open_file_identities(items):
    targets = set()
    for item in items:
        targets.update(item['identities'])
    if not targets:
        return 0
    open_count = 0
    try:
        processes = os.scandir('/proc')
    except OSError:
        reject('open_file_state_unknown')
    with processes:
        for process in processes:
            if not process.name.isdigit() or int(process.name) == os.getpid():
                continue
            fd_root = os.path.join('/proc', process.name, 'fd')
            for special in ('cwd', 'root'):
                try:
                    metadata = os.stat(os.path.join('/proc', process.name, special))
                except (FileNotFoundError, ProcessLookupError):
                    continue
                except PermissionError:
                    reject('open_file_state_unknown')
                if (metadata.st_dev, metadata.st_ino) in targets:
                    open_count += 1
            try:
                descriptors = os.scandir(fd_root)
            except (FileNotFoundError, ProcessLookupError):
                continue
            except PermissionError:
                reject('open_file_state_unknown')
            with descriptors:
                for entry in descriptors:
                    try:
                        metadata = os.stat(entry.path)
                    except (FileNotFoundError, ProcessLookupError):
                        continue
                    except PermissionError:
                        reject('open_file_state_unknown')
                    if (metadata.st_dev, metadata.st_ino) in targets:
                        open_count += 1
            try:
                with open(os.path.join('/proc', process.name, 'maps'), 'r', encoding='ascii') as maps:
                    for index, line in enumerate(maps):
                        if index > 1_000_000:
                            reject('open_file_state_unknown')
                        fields = line.split(None, 5)
                        if len(fields) < 5 or ':' not in fields[3] or not fields[4].isdigit():
                            continue
                        major, minor = fields[3].split(':', 1)
                        try:
                            identity = (os.makedev(int(major, 16), int(minor, 16)), int(fields[4]))
                        except ValueError:
                            reject('open_file_state_unknown')
                        if identity in targets:
                            open_count += 1
            except (FileNotFoundError, ProcessLookupError):
                continue
            except (PermissionError, UnicodeError):
                reject('open_file_state_unknown')
    return open_count


def remove_tree(parent, leaf, expected_identity, allowed_uids, expected_device):
    before = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
    descriptor = os.open(
        leaf,
        os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW,
        dir_fd=parent,
    )
    try:
        opened = os.fstat(descriptor)
        if directory_identity(before) != expected_identity or directory_identity(opened) != expected_identity:
            reject('candidate_changed', 75)
        names = os.listdir(descriptor)
        for name in names:
            metadata = os.stat(name, dir_fd=descriptor, follow_symlinks=False)
            if metadata.st_dev != expected_device or metadata.st_uid not in allowed_uids:
                reject('candidate_changed', 75)
            if stat.S_ISDIR(metadata.st_mode):
                remove_tree(descriptor, name, directory_identity(metadata), allowed_uids, expected_device)
            elif stat.S_ISREG(metadata.st_mode) and metadata.st_nlink == 1 and not stat.S_IMODE(metadata.st_mode) & 0o022:
                os.unlink(name, dir_fd=descriptor)
            else:
                reject('candidate_changed', 75)
        os.fsync(descriptor)
    finally:
        os.close(descriptor)
    os.rmdir(leaf, dir_fd=parent)


def filesystem_snapshot(paths, projected_growth):
    devices = {os.fstat(descriptor).st_dev for descriptor in paths}
    if len(devices) != 1:
        reject('filesystem_mismatch')
    statvfs = os.fstatvfs(paths[0])
    total = statvfs.f_blocks * statvfs.f_frsize
    available = statvfs.f_bavail * statvfs.f_frsize
    if total <= 0 or available < 0 or available > total:
        reject('capacity_unknown')
    used = total - available
    observed_percent = (100 * used + total - 1) // total
    projected_percent = (100 * (used + projected_growth) + total - 1) // total
    required_free = max(ABSOLUTE_FREE_BYTES, projected_growth)
    return {
        'available_bytes': available,
        'capacity_passed': available >= required_free and observed_percent < MAX_USED_PERCENT and projected_percent < MAX_USED_PERCENT,
        'max_used_percent': MAX_USED_PERCENT,
        'observed_used_percent': observed_percent,
        'projected_required_bytes': projected_growth,
        'projected_used_percent': projected_percent,
        'required_free_bytes': required_free,
    }


def scan_release_directories(web, current_release, rollback_release, now_ns, web_uid, device):
    names = os.listdir(web)
    if len(names) > MAX_CLASS_SCAN:
        reject('release_directory_scan_limit')
    exact_rollback = 'easyappointments_prev_' + current_release
    if exact_rollback not in names:
        reject('missing_exact_rollback')
    rollback = open_child_directory(web, exact_rollback)
    try:
        if read_release_marker(rollback) != rollback_release:
            reject('rollback_identity_mismatch')
    finally:
        os.close(rollback)
    classes = {'previous': [], 'stage': [], 'failed': []}
    foreign = 0
    identities = []
    patterns = {
        'previous': re.compile(r'easyappointments_prev_([A-Za-z0-9._-]{1,128})\Z'),
        'stage': re.compile(r'easyappointments_([A-Za-z0-9._-]{1,128})_stage\Z'),
        'failed': re.compile(r'easyappointments_failed_([A-Za-z0-9._-]{1,128})\Z'),
    }
    for name in names:
        if name == 'easyappointments' or name == exact_rollback:
            continue
        kind = next((label for label, pattern in patterns.items() if pattern.fullmatch(name)), None)
        if kind is None:
            if name.startswith('easyappointments_'):
                foreign += 1
            continue
        record = validate_candidate_tree(web, name, {0, web_uid}, device)
        identities.append(record)
        age = (now_ns - record['mtime_ns']) // 1_000_000_000
        record.update({'eligible': age >= RELEASE_DIR_MIN_AGE, 'kind': kind, 'leaf': name})
        classes[kind].append(record)
    for records in classes.values():
        records.sort(key=lambda item: (item['mtime_ns'], item['leaf']))
    return classes, foreign, identities


def scan_archive_pairs(releases, current_release, rollback_release, now_ns, legacy_hold):
    names = os.listdir(releases)
    if len(names) > MAX_CLASS_SCAN:
        reject('archive_scan_limit')
    allowed = {'.release-pair.lock'}
    pair_ids = set()
    foreign = 0
    for name in names:
        if name in allowed:
            continue
        match = re.fullmatch(r'([A-Za-z0-9._-]{1,128})\.(tar\.gz|build-provenance\.json)', name)
        if match is None:
            foreign += 1
            continue
        pair_ids.add(match.group(1))
    if legacy_hold:
        for held_release in legacy_hold:
            if held_release not in pair_ids or held_release + '.tar.gz' not in names:
                reject('legacy_hold_target_missing')
    records = []
    for release_id in pair_ids:
        archive_leaf = release_id + '.tar.gz'
        sidecar_leaf = release_id + '.build-provenance.json'
        if sidecar_leaf in names and archive_leaf not in names:
            reject('unsafe_incomplete_archive_pair')
        held = legacy_hold.get(release_id) if legacy_hold else None
        if held is None:
            archive_sha, archive_size, archive_identity, archive_meta = stable_hash(
                releases, archive_leaf, 0, 0, {0o600}, MAX_ARCHIVE_BYTES,
            )
            observed_hold_bounds = None
        else:
            archive_sha, archive_size, archive_identity, archive_meta, observed_hold_bounds = inspect_legacy_archive(
                releases,
                archive_leaf,
            )
            if held['capacity_bounds'] != observed_hold_bounds:
                reject('legacy_hold_bounds_drift')
        if sidecar_leaf not in names:
            if held is None:
                reject('unheld_archive_only')
            if held['sha256'] != archive_sha or held['size_bytes'] != archive_size:
                reject('legacy_hold_drift')
            records.append({
                'archive_identity': archive_identity,
                'archive_leaf': archive_leaf,
                'archive_size': archive_size,
                'identities': {(archive_meta.st_dev, archive_meta.st_ino)},
                'incomplete': True,
                'eligible': False,
                'mtime_ns': archive_meta.st_mtime_ns,
                'projected': held['capacity_bounds'],
                'protected': True,
                'legacy_hold': True,
                'release_id': release_id,
                'sidecar_identity': None,
                'sidecar_leaf': None,
            })
            continue
        sidecar = stable_regular(releases, sidecar_leaf, 0, 0, {0o600}, MAX_SIDECAR_BYTES)
        provenance = validate_provenance(sidecar[0], release_id, archive_sha, archive_size)
        if held is not None and provenance['capacity_bounds'] != observed_hold_bounds:
            reject('legacy_hold_bounds_drift')
        if held is not None and (held['sha256'] != archive_sha or held['size_bytes'] != archive_size):
            reject('legacy_hold_drift')
        mtime_ns = max(archive_meta.st_mtime_ns, sidecar[2].st_mtime_ns)
        records.append({
            'archive_identity': archive_identity,
            'archive_leaf': archive_leaf,
            'archive_size': archive_size,
            'eligible': False,
            'identities': {(archive_meta.st_dev, archive_meta.st_ino), (sidecar[2].st_dev, sidecar[2].st_ino)},
            'incomplete': False,
            'mtime_ns': mtime_ns,
            'projected': observed_hold_bounds if held is not None else provenance['capacity_bounds'],
            'protected': held is not None,
            'release_id': release_id,
            'sidecar_identity': sidecar[1],
            'sidecar_leaf': sidecar_leaf,
            'legacy_hold': held is not None,
        })
    records.sort(key=lambda item: (item['mtime_ns'], item['release_id']), reverse=True)
    protected = {current_release, rollback_release}
    for record in records:
        if record['incomplete'] or record.get('legacy_hold'):
            continue
        if len(protected) >= KEEP_ARCHIVE_PAIRS:
            break
        protected.add(record['release_id'])
    for record in records:
        if record['incomplete'] or record.get('legacy_hold'):
            continue
        age = (now_ns - record['mtime_ns']) // 1_000_000_000
        record['eligible'] = record['release_id'] not in protected and age >= ARCHIVE_MIN_AGE
        record['protected'] = record['release_id'] in protected
    by_id = {record['release_id']: record for record in records}
    if current_release not in by_id or rollback_release not in by_id:
        reject('protected_archive_missing')
    return records, foreign, by_id[current_release]


def scan_backup_sets(backups, attestations, now_ns, device):
    names = os.listdir(backups)
    if len(names) > MAX_CLASS_SCAN:
        reject('dump_scan_limit')
    records = []
    foreign = 0
    producer_handoff = None
    for name in names:
        if name in {'last_backup_success.utc', 'last_verify_success.utc'}:
            continue
        if name == '.backup-set-producer.lock':
            stable_regular(backups, name, 0, 0, {0o600}, 0, empty_ok=True)
            continue
        if name == 'last_backup_set.json':
            data = stable_regular(backups, name, 0, 0, {0o600}, 4096)[0]
            producer_handoff = decode_canonical(data, 4096)
            if (set(producer_handoff) != {'backup_set_id', 'compressed_size_bytes', 'dump_sha256',
                                         'schema', 'uncompressed_size_bytes'} or
                    producer_handoff.get('schema') != 'production_backup_set_handoff.v1' or
                    not isinstance(producer_handoff.get('backup_set_id'), str) or
                    BACKUP_SET.fullmatch(producer_handoff['backup_set_id']) is None or
                    not isinstance(producer_handoff.get('dump_sha256'), str) or
                    SHA256.fullmatch(producer_handoff['dump_sha256']) is None or
                    isinstance(producer_handoff.get('compressed_size_bytes'), bool) or
                    not isinstance(producer_handoff.get('compressed_size_bytes'), int) or
                    producer_handoff['compressed_size_bytes'] <= 0 or
                    producer_handoff['compressed_size_bytes'] > MAX_ARCHIVE_BYTES or
                    isinstance(producer_handoff.get('uncompressed_size_bytes'), bool) or
                    not isinstance(producer_handoff.get('uncompressed_size_bytes'), int) or
                    producer_handoff['uncompressed_size_bytes'] <= 0 or
                    producer_handoff['uncompressed_size_bytes'] > MAX_DUMP_UNCOMPRESSED_BYTES):
                reject('invalid_backup_set_handoff')
            continue
        if name == 'install-snapshots':
            # ROB-405 installer snapshots are a separate explicitly preserved
            # class and never enter dump-set selection or deletion.
            continue
        if BACKUP_SET.fullmatch(name) is None:
            foreign += 1
            continue
        try:
            backup = open_child_directory(backups, name)
        except FileNotFoundError:
            foreign += 1
            continue
        try:
            try:
                db = open_child_directory(backup, 'db')
            except FileNotFoundError:
                foreign += 1
                continue
            try:
                dump_sha, dump_size, _, dump_meta = stable_hash(
                    db, 'easyappointments.sql.gz', 0, 0, {0o600, 0o640}, MAX_ARCHIVE_BYTES,
                )
            except FileNotFoundError:
                foreign += 1
                os.close(db)
                continue
            os.close(db)
            try:
                attestation_record = stable_regular(
                    attestations, dump_sha + '.json', 0, 0, {0o600}, MAX_ATTESTATION_BYTES,
                )
            except FileNotFoundError:
                foreign += 1
                continue
            attestation = validate_attestation(attestation_record[0], dump_sha, dump_size)
            tree = validate_candidate_tree(backups, name, {0}, device)
            attested_epoch = parse_utc(attestation['attested_at_utc'])
            records.append({
                'attestation_identity': attestation_record[1],
                'attestation_leaf': dump_sha + '.json',
                'attested_epoch': attested_epoch,
                'dump_sha': dump_sha,
                'dump_size': dump_size,
                'dump_uncompressed_size': attestation['dump']['uncompressed_size_bytes'],
                'identities': tree['identities'] | {(attestation_record[2].st_dev, attestation_record[2].st_ino)},
                'leaf': name,
                'tree': tree,
            })
        finally:
            os.close(backup)
    if producer_handoff is not None:
        matches = [record for record in records if record['leaf'] == producer_handoff['backup_set_id']]
        if (len(matches) != 1 or matches[0]['dump_sha'] != producer_handoff['dump_sha256'] or
                matches[0]['dump_size'] != producer_handoff['compressed_size_bytes'] or
                matches[0]['dump_uncompressed_size'] != producer_handoff['uncompressed_size_bytes']):
            reject('backup_set_handoff_mismatch')
    records.sort(key=lambda item: (item['attested_epoch'], item['dump_sha']), reverse=True)
    protected = {record['dump_sha'] for record in records[:KEEP_VERIFIED_DUMPS]}
    if producer_handoff is not None:
        protected.add(producer_handoff['dump_sha256'])
    for record in records:
        age = now_ns // 1_000_000_000 - record['attested_epoch']
        record['eligible'] = record['dump_sha'] not in protected and age >= DUMP_MIN_AGE
        record['protected'] = record['dump_sha'] in protected
    if len(protected) < KEEP_VERIFIED_DUMPS:
        reject('insufficient_verified_restore_paths')
    return records, foreign


def candidate_counts(release_classes, archives, dumps):
    return {
        'archive_pairs': sum(1 for record in archives if record['eligible']),
        'dump_sets': sum(1 for record in dumps if record['eligible']),
        'failed_dirs': sum(1 for record in release_classes['failed'] if record['eligible']),
        'previous_dirs': sum(1 for record in release_classes['previous'] if record['eligible']),
        'stage_dirs': sum(1 for record in release_classes['stage'] if record['eligible']),
    }


def gather():
    try:
        web_user = pwd.getpwnam('www-data')
    except KeyError:
        reject('missing_web_user')
    web = open_absolute_directory(WEB_ROOT)
    current = open_child_directory(web, 'easyappointments')
    releases = open_absolute_directory(RELEASES_ROOT, exact_mode=0o700)
    backups = open_absolute_directory(BACKUP_ROOT, exact_mode=0o700)
    attestations = open_absolute_directory(ATTESTATION_ROOT, exact_mode=0o700)
    orchestrator = open_absolute_directory(ORCHESTRATOR_ROOT, exact_mode=0o700)
    descriptors = [web, current, releases, backups, attestations, orchestrator]
    try:
        device = os.fstat(web).st_dev
        assert_no_nested_mounts(os.listdir(web))
        current_release = read_release_marker(current)
        exact_rollback = open_child_directory(web, 'easyappointments_prev_' + current_release)
        try:
            rollback_release = read_release_marker(exact_rollback)
        finally:
            os.close(exact_rollback)
        now_ns = int(datetime.datetime.now(datetime.timezone.utc).timestamp() * 1_000_000_000)
        legacy_hold = read_legacy_hold()
        release_classes, release_foreign, release_identities = scan_release_directories(
            web, current_release, rollback_release, now_ns, web_user.pw_uid, device,
        )
        archives, archive_foreign, current_archive = scan_archive_pairs(
            releases, current_release, rollback_release, now_ns, legacy_hold,
        )
        dumps, dump_foreign = scan_backup_sets(backups, attestations, now_ns, device)
        live_storage = open_child_directory(current, 'storage', web_user.pw_uid, web_user.pw_gid, writable_ok=True)
        try:
            storage = scan_tree(live_storage, {0, web_user.pw_uid}, device)
        finally:
            os.close(live_storage)
        bounds = current_archive['projected']
        base_growth = (
            current_archive['archive_size']
            + bounds['stage_unpacked_bytes']
            + bounds['temp_scratch_bytes']
            + max(storage['allocated'], storage['logical'])
        )
        projected_growth = base_growth + max(FIXED_HEADROOM_BYTES, (base_growth + 9) // 10)
        capacity = filesystem_snapshot(descriptors, projected_growth)
        return {
            'archive_foreign': archive_foreign,
            'archives': archives,
            'attestations': attestations,
            'backups': backups,
            'capacity': capacity,
            'current_release': current_release,
            'legacy_hold': legacy_hold,
            'descriptors': descriptors,
            'dump_foreign': dump_foreign,
            'dumps': dumps,
            'release_classes': release_classes,
            'release_foreign': release_foreign,
            'release_identities': release_identities,
            'releases': releases,
            'rollback_release': rollback_release,
            'web': web,
        }
    except BaseException:
        for descriptor in descriptors:
            try:
                os.close(descriptor)
            except OSError:
                pass
        raise


def close_gathered(value):
    seen = set()
    for descriptor in value['descriptors']:
        if descriptor not in seen:
            seen.add(descriptor)
            try:
                os.close(descriptor)
            except OSError:
                pass


def result_payload(mode, gathered, mutations, deleted=None, remaining=None):
    candidates = candidate_counts(gathered['release_classes'], gathered['archives'], gathered['dumps'])
    payload = {
        'archive_foreign_count': gathered['archive_foreign'],
        'archive_pair_count': len(gathered['archives']),
        'capacity': gathered['capacity'],
        'candidates': candidates,
        'dump_foreign_count': gathered['dump_foreign'],
        'execution_ready': (
            gathered['archive_foreign'] == 0
            and gathered['dump_foreign'] == 0
            and gathered['release_foreign'] == 0
        ),
        'mode': mode,
        'legacy_hold_count': sum(1 for item in gathered['archives'] if item.get('legacy_hold')),
        'protected_archive_pair_count': sum(1 for item in gathered['archives'] if item['protected']),
        'protected_verified_dump_count': sum(1 for item in gathered['dumps'] if item['protected']),
        'release_directory_foreign_count': gathered['release_foreign'],
        'schema': SCHEMA,
        'status': 'pass',
        'verified_dump_count': len(gathered['dumps']),
    }
    payload.update(mutations.fields())
    if deleted is None:
        payload['would_delete'] = {
            'archive_pairs': min(candidates['archive_pairs'], MAX_ARCHIVE_PAIR_DELETE),
            'dump_sets': min(candidates['dump_sets'], MAX_DUMP_SET_DELETE),
            'failed_dirs': min(candidates['failed_dirs'], MAX_RELEASE_DIR_DELETE),
            'previous_dirs': min(candidates['previous_dirs'], MAX_RELEASE_DIR_DELETE),
            'stage_dirs': min(candidates['stage_dirs'], MAX_RELEASE_DIR_DELETE),
        }
    else:
        payload['deleted'] = deleted
        payload['remaining'] = remaining
        payload['status'] = 'pass' if sum(remaining.values()) == 0 and gathered['capacity']['capacity_passed'] else 'partial'
    return payload


def clean_marker_temps(state, mutations):
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
        mutations.begin()
        os.unlink(name, dir_fd=state)
        mutations.confirm('marker_temp_files')
        os.fsync(state)


def clean_pending_entries(state, web_uid, mutations):
    names = [name for name in os.listdir(state) if name.startswith('.pending-')]
    if len(names) > MAX_PENDING_ENTRIES:
        reject('pending_cleanup_limit')
    device = os.fstat(state).st_dev
    for name in sorted(names):
        directory_match = re.fullmatch(r'\.pending-(release|dump)-[0-9a-f]{32}', name)
        file_match = re.fullmatch(r'\.pending-archive-(archive|sidecar)-[0-9a-f]{32}', name)
        if directory_match is not None:
            metadata = os.stat(name, dir_fd=state, follow_symlinks=False)
            allowed = {0, web_uid} if directory_match.group(1) == 'release' else {0}
            tree = validate_candidate_tree(state, name, allowed, device)
            mutations.begin()
            remove_tree(state, name, tree['identity'], allowed, device)
            mutation_key = 'pending_release_dirs' if directory_match.group(1) == 'release' else 'pending_dump_sets'
            mutations.confirm(mutation_key)
            os.fsync(state)
            continue
        if file_match is not None:
            maximum = MAX_ARCHIVE_BYTES if file_match.group(1) == 'archive' else MAX_SIDECAR_BYTES
            record = stable_regular(state, name, 0, 0, {0o600}, maximum)
            current = os.stat(name, dir_fd=state, follow_symlinks=False)
            if file_identity(current) != record[1]:
                reject('candidate_changed', 75)
            mutations.begin()
            os.unlink(name, dir_fd=state)
            mutations.confirm('pending_archive_files')
            os.fsync(state)
            continue
        reject('unsafe_pending_entry')


def detach_tree(source, state, record, kind, allowed_uids, mutations):
    if os.fstat(source).st_dev != os.fstat(state).st_dev:
        reject('filesystem_mismatch')
    pending = '.pending-' + kind + '-' + secrets.token_hex(16)
    mutations.begin()
    os.rename(record['leaf'], pending, src_dir_fd=source, dst_dir_fd=state)
    mutations.confirm('release_dirs' if kind == 'release' else 'dump_sets')
    os.fsync(source)
    os.fsync(state)
    mutations.begin()
    remove_tree(state, pending, record['identity'], allowed_uids, os.fstat(state).st_dev)
    mutations.finish()
    os.fsync(state)


def detach_file(source, state, leaf, expected_identity, kind, mutations):
    if os.fstat(source).st_dev != os.fstat(state).st_dev:
        reject('filesystem_mismatch')
    current = os.stat(leaf, dir_fd=source, follow_symlinks=False)
    if file_identity(current) != expected_identity:
        reject('candidate_changed', 75)
    pending = '.pending-archive-' + kind + '-' + secrets.token_hex(16)
    mutations.begin()
    os.rename(leaf, pending, src_dir_fd=source, dst_dir_fd=state)
    mutations.confirm('archive_files')
    os.fsync(source)
    os.fsync(state)
    mutations.begin()
    os.unlink(pending, dir_fd=state)
    mutations.finish()
    os.fsync(state)


def write_marker(state, deleted, capacity):
    marker = {
        'completed_at_utc': datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0).isoformat().replace('+00:00', 'Z'),
        'deleted_archive_pairs': deleted['archive_pairs'],
        'deleted_dump_sets': deleted['dump_sets'],
        'deleted_release_dirs': deleted['previous_dirs'] + deleted['stage_dirs'] + deleted['failed_dirs'],
        'projected_required_bytes': capacity['projected_required_bytes'],
        'schema': MARKER_SCHEMA,
    }
    data = canonical_json(marker)
    stable_regular(state, MARKER_LEAF, 0, 0, {0o600}, MARKER_MAX_BYTES, missing_ok=True)
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
    if stable_regular(state, MARKER_LEAF, 0, 0, {0o600}, MARKER_MAX_BYTES)[0] != data:
        reject('marker_publish_failed')


def dry_run():
    gathered = gather()
    try:
        payload = result_payload('dry-run', gathered, MUTATIONS)
        emit(payload)
    finally:
        close_gathered(gathered)


def unlink_pair(directory, state, record, mutations):
    if record['sidecar_leaf'] is None:
        detach_file(directory, state, record['archive_leaf'], record['archive_identity'], 'archive', mutations)
        return
    # The sidecar is the publication/availability marker. Removing it first
    # makes a crash leave an explicitly undeployable archive-only prefix that
    # the next bounded pass can safely resume after the same age/protection checks.
    detach_file(directory, state, record['sidecar_leaf'], record['sidecar_identity'], 'sidecar', mutations)
    detach_file(directory, state, record['archive_leaf'], record['archive_identity'], 'archive', mutations)


def unlink_dump(backups, state, record, mutations):
    # The root-protected attestation is retained as small audit evidence after
    # the bulky verified dump set is removed.
    detach_tree(
        backups,
        state,
        {'identity': record['tree']['identity'], 'leaf': record['leaf']},
        'dump',
        {0},
        mutations,
    )


def execute():
    state = prepare_state_directory()
    global_lock = None
    first = None
    second = None
    try:
        try:
            fcntl.flock(state, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            reject('cleanup_lock_busy', 75)
        clean_marker_temps(state, MUTATIONS)
        global_lock = open_global_lock()
        if activity_count() != 0:
            reject('active_production_work', 75)
        assert_no_nonterminal_runs()
        first = gather()
        if os.fstat(state).st_dev != os.fstat(first['web']).st_dev:
            reject('filesystem_mismatch')
        if first['archive_foreign'] or first['dump_foreign'] or first['release_foreign']:
            reject('unclassified_retention_entry')
        web_uid = pwd.getpwnam('www-data').pw_uid
        clean_pending_entries(state, web_uid, MUTATIONS)
        candidates_for_open_check = []
        for records in first['release_classes'].values():
            candidates_for_open_check.extend(record for record in records if record['eligible'])
        candidates_for_open_check.extend(record for record in first['archives'] if record['eligible'])
        candidates_for_open_check.extend(record for record in first['dumps'] if record['eligible'])
        if open_file_identities(candidates_for_open_check) != 0:
            reject('candidate_open', 75)
        if activity_count() != 0:
            reject('active_production_work', 75)

        deleted = {'archive_pairs': 0, 'dump_sets': 0, 'failed_dirs': 0, 'previous_dirs': 0, 'stage_dirs': 0}
        for kind in ('previous', 'stage', 'failed'):
            for record in [item for item in first['release_classes'][kind] if item['eligible']][:MAX_RELEASE_DIR_DELETE]:
                detach_tree(first['web'], state, record, 'release', {0, web_uid}, MUTATIONS)
                deleted[kind + '_dirs'] += 1
        for record in [item for item in first['archives'] if item['eligible']][:MAX_ARCHIVE_PAIR_DELETE]:
            unlink_pair(first['releases'], state, record, MUTATIONS)
            deleted['archive_pairs'] += 1
        for record in [item for item in first['dumps'] if item['eligible']][:MAX_DUMP_SET_DELETE]:
            unlink_dump(first['backups'], state, record, MUTATIONS)
            deleted['dump_sets'] += 1
        for descriptor in (first['web'], first['releases'], first['backups'], first['attestations']):
            os.fsync(descriptor)
        close_gathered(first)
        first = None

        if activity_count() != 0:
            reject('active_production_work', 75)
        assert_no_nonterminal_runs()
        second = gather()
        remaining = candidate_counts(second['release_classes'], second['archives'], second['dumps'])
        payload = result_payload('execute', second, MUTATIONS, deleted, remaining)
        if payload['status'] == 'pass':
            write_marker(state, deleted, second['capacity'])
            emit(payload)
            return
        emit(payload)
        raise SystemExit(75)
    finally:
        if first is not None:
            close_gathered(first)
        if second is not None:
            close_gathered(second)
        if global_lock is not None:
            os.close(global_lock)
        os.close(state)


def marker_status(max_age_seconds):
    if re.fullmatch(r'[1-9][0-9]{0,6}', max_age_seconds) is None:
        reject('invalid_marker_age')
    max_age = int(max_age_seconds)
    parent = open_absolute_directory('/var/lib')
    try:
        try:
            state = open_child_directory(parent, 'fh-release-retention', exact_mode=0o700)
        except FileNotFoundError:
            emit({'age_seconds': None, 'schema': MARKER_STATUS_SCHEMA, 'status': 'missing'})
            return
    finally:
        os.close(parent)
    try:
        try:
            record = stable_regular(state, MARKER_LEAF, 0, 0, {0o600}, MARKER_MAX_BYTES, missing_ok=True)
            if record is None:
                emit({'age_seconds': None, 'schema': MARKER_STATUS_SCHEMA, 'status': 'missing'})
                return
            value = decode_canonical(record[0], MARKER_MAX_BYTES)
            if set(value) != {
                'completed_at_utc', 'deleted_archive_pairs', 'deleted_dump_sets',
                'deleted_release_dirs', 'projected_required_bytes', 'schema',
            } or value.get('schema') != MARKER_SCHEMA:
                reject('invalid_marker')
            completed = parse_utc(value['completed_at_utc'])
            now = int(datetime.datetime.now(datetime.timezone.utc).timestamp())
            age = now - completed
            status_value = 'invalid' if age < 0 else ('stale' if age > max_age else 'pass')
            emit({'age_seconds': age if age >= 0 else None, 'schema': MARKER_STATUS_SCHEMA, 'status': status_value})
        except (RetentionError, KeyError, TypeError):
            emit({'age_seconds': None, 'schema': MARKER_STATUS_SCHEMA, 'status': 'invalid'})
    finally:
        os.close(state)


def main():
    if os.name != 'posix' or os.geteuid() != 0:
        reject('root_required')
    try:
        if len(sys.argv) == 2 and sys.argv[1] == 'dry-run':
            dry_run()
        elif len(sys.argv) == 2 and sys.argv[1] == 'execute':
            execute()
        elif len(sys.argv) == 3 and sys.argv[1] == 'marker-status':
            marker_status(sys.argv[2])
        else:
            reject('invalid_arguments')
    except RetentionError as error:
        payload = {'reason': error.reason, 'schema': SCHEMA, 'status': 'blocked'}
        payload.update(MUTATIONS.fields())
        emit(payload)
        raise SystemExit(error.code)


if __name__ == '__main__':
    try:
        main()
    except SystemExit:
        raise
    except RetentionError as error:
        payload = {'reason': error.reason, 'schema': SCHEMA, 'status': 'blocked'}
        payload.update(MUTATIONS.fields())
        emit(payload)
        raise SystemExit(error.code)
    except (OSError, TypeError, ValueError):
        payload = {'reason': 'internal_rejection', 'schema': SCHEMA, 'status': 'blocked'}
        payload.update(MUTATIONS.fields())
        emit(payload)
        raise SystemExit(70)
