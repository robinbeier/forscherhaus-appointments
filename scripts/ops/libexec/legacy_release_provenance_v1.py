#!/usr/bin/python3
"""Attach canonical provenance to the two authorized legacy release archives."""

import base64
import ctypes
import errno
import fcntl
import hashlib
import json
import os
import re
import secrets
import stat
import subprocess
import sys
import tarfile


RESULT_SCHEMA = 'legacy_release_provenance_result.v1'
AUTH_RESULT_SCHEMA = 'legacy_release_provenance_authorization_result.v1'
AUTHORIZATION_SCHEMA = 'legacy_release_provenance_authorization.v1'
SIDECAR_SCHEMA = 'release_build_provenance.v1'
EXECUTE_TOKEN = 'ROB-468'
AUTHORIZATION_TOKEN = 'ROB-468-AUTHORIZATION'
AUTHORIZATION = '/etc/fh/legacy-release-provenance-authorization.v1.json'
# The fixed authorization, sidecars, locks, and helper-owned temps are canonical 0600 files.
WEB_ROOT = '/var/www/html'
APP_ROOT = '/var/www/html/easyappointments'
RELEASES_ROOT = '/root/releases'
INSTALLED_DEPLOY_EA = '/root/deploy_ea.sh'
ORCHESTRATOR_ROOT = '/var/lib/fh-deploy-orchestrator'
RUNS_ROOT = '/var/lib/fh-deploy-orchestrator/runs'
TERMINAL_VALIDATOR = '/usr/local/libexec/fh/validate_deployment_terminal_bundle_v1.php'
TERMINAL_CONTRACT = '/usr/local/libexec/fh/DeploymentContractV1.php'
PHP = '/usr/bin/php'
GLOBAL_PRODUCTION_LOCK = 'fh-production-change.lock'
PUBLICATION_LOCK = '.release-pair.lock'
RENAME_NOREPLACE = 1
TRUSTED_UID = 0
TRUSTED_GID = 0
BLOCK_BYTES = 4096
TEMP_SCRATCH_BYTES = 67_108_864
MAX_AUTHORIZATION_BYTES = 16 * 1024
MAX_ARCHIVE_BYTES = 16 * 1024 * 1024 * 1024
MAX_SIDECAR_BYTES = 4096
MAX_TERMINAL_ENVELOPE_BYTES = 1_500_000
MAX_TERMINAL_EVENTS_BYTES = 1_048_576
MAX_TERMINAL_EVIDENCE_BYTES = 65_536
MAX_REQUIRED_MEMBER_BYTES = 16 * 1024 * 1024
MAX_ARCHIVE_ENTRIES = 1_000_000
MAX_UNPACKED_BYTES = 68_719_476_736
RELEASE_ID = re.compile(r'[A-Za-z0-9][A-Za-z0-9._-]{0,127}\Z')
COMMIT = re.compile(r'[0-9a-f]{40}\Z')
SHA256 = re.compile(r'[0-9a-f]{64}\Z')
NONCE = re.compile(r'[0-9a-f]{32}\Z')
REQUIRED_MEMBERS = ('build_release.sh', 'composer.lock', 'deploy_ea.sh', 'package-lock.json')
MUTATION_KEYS = ('sidecars_published', 'temp_files_created', 'temp_files_removed')
AUTH_MUTATION_KEYS = ('authorization_published', 'temp_files_created', 'temp_files_removed')
PUBLIC_REASONS = {
    'archive_digest_mismatch': 'authorization_invalid',
    'authorization_marker_mismatch': 'authorization_invalid',
    'directory_changed': 'host_contract_invalid',
    'file_changed': 'host_contract_invalid',
    'file_too_large': 'host_contract_invalid',
    'foreign_helper_temp': 'publication_blocked',
    'global_lock_busy': 'lock_busy',
    'installed_deploy_ea_mismatch': 'authorization_invalid',
    'invalid_arguments': 'invalid_arguments',
    'invalid_authorization': 'authorization_invalid',
    'invalid_release_marker': 'metadata_invalid',
    'missing_exact_rollback': 'metadata_invalid',
    'mutation_ledger_invalid': 'internal_error',
    'noncanonical_fixed_path': 'host_contract_invalid',
    'publication_conflict': 'publication_blocked',
    'publication_lock_busy': 'lock_busy',
    'publication_missing': 'publication_blocked',
    'release_scan_failed': 'host_contract_invalid',
    'renameat2_unavailable': 'host_contract_invalid',
    'required_member_invalid': 'archive_invalid',
    'required_member_mismatch': 'archive_invalid',
    'required_member_missing': 'archive_invalid',
    'root_required': 'host_contract_invalid',
    'sidecar_conflict': 'publication_blocked',
    'sidecar_too_large': 'publication_blocked',
    'temp_verify_failed': 'publication_blocked',
    'temp_write_failed': 'publication_blocked',
    'unsafe_directory': 'host_contract_invalid',
    'unsafe_file': 'host_contract_invalid',
    'unsafe_helper_temp': 'publication_blocked',
    'unsafe_lock': 'host_contract_invalid',
    'unsafe_tar': 'archive_invalid',
    'authorization_authority_missing': 'authorization_invalid',
    'authorization_authority_ambiguous': 'authorization_invalid',
    'authorization_conflict': 'authorization_invalid',
    'terminal_bundle_invalid': 'authorization_invalid',
    'terminal_bundle_not_succeeded': 'authorization_invalid',
    'validator_unavailable': 'host_contract_invalid',
}


class LegacyProvenanceError(Exception):
    def __init__(self, reason, code=70):
        super().__init__(reason)
        self.reason = reason
        self.code = code


class MutationLedger:
    """Track only aggregate namespace mutations and uncertain boundaries."""

    def __init__(self, keys=MUTATION_KEYS):
        self.keys = tuple(keys)
        self.counts = {key: 0 for key in self.keys}
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
        if self.in_flight:
            outcome = 'unknown'
        elif sum(self.counts.values()) > 0:
            outcome = 'known'
        else:
            outcome = 'none'
        return {'mutation_counts': self.counts.copy(), 'mutation_outcome': outcome}


MUTATIONS = MutationLedger()
RUN_STATE = {'attached': 0, 'pending': 0, 'preflighted': 0, 'published': 0}
AUTH_RUN_STATE = {'attached': 0, 'pending': 0, 'preflighted': 0, 'published': 0}


def reject(reason='rejected', code=70):
    raise LegacyProvenanceError(reason, code)


def canonical_json(value):
    return (json.dumps(value, sort_keys=True, separators=(',', ':')) + '\n').encode('utf-8')


def preflight_targets(context):
    """Complete both role preflights before callers cross a mutation boundary."""
    return _preflight_targets(context)


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


def directory_identity(value):
    return value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid, value.st_nlink


def read_all(fd, maximum):
    os.lseek(fd, 0, os.SEEK_SET)
    chunks = []
    total = 0
    while True:
        chunk = os.read(fd, min(64 * 1024, maximum + 1 - total))
        if not chunk:
            return b''.join(chunks)
        total += len(chunk)
        if total > maximum:
            reject('file_too_large')
        chunks.append(chunk)


def digest_fd(fd):
    os.lseek(fd, 0, os.SEEK_SET)
    digest = hashlib.sha256()
    while True:
        chunk = os.read(fd, 1024 * 1024)
        if not chunk:
            return digest.hexdigest()
        digest.update(chunk)


def open_child_directory(parent, leaf, exact_mode=None):
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
            or opened.st_uid != TRUSTED_UID
            or opened.st_gid != TRUSTED_GID
            or (exact_mode is not None and stat.S_IMODE(opened.st_mode) != exact_mode)
            or (exact_mode is None and stat.S_IMODE(opened.st_mode) & 0o022)
        ):
            reject('unsafe_directory')
        return descriptor, {'fd': descriptor, 'parent': parent, 'leaf': leaf, 'identity': directory_identity(opened)}
    except BaseException:
        os.close(descriptor)
        raise


def open_absolute_directory(path, exact_mode=None):
    if not path.startswith('/') or path == '/':
        reject('unsafe_directory')
    descriptor = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW)
    try:
        root = os.fstat(descriptor)
        if root.st_uid != TRUSTED_UID or root.st_gid != TRUSTED_GID or stat.S_IMODE(root.st_mode) & 0o022:
            reject('unsafe_directory')
        parts = [part for part in path.split('/') if part]
        for index, leaf in enumerate(parts):
            next_fd, _ = open_child_directory(
                descriptor,
                leaf,
                exact_mode if index == len(parts) - 1 else None,
            )
            os.close(descriptor)
            descriptor = next_fd
        opened = os.fstat(descriptor)
        return descriptor, {'fd': descriptor, 'path': path, 'identity': directory_identity(opened)}
    except BaseException:
        os.close(descriptor)
        raise


def ensure_directory_stable(record):
    opened = os.fstat(record['fd'])
    if 'path' in record:
        current = os.stat(record['path'], follow_symlinks=False)
    else:
        current = os.stat(record['leaf'], dir_fd=record['parent'], follow_symlinks=False)
    if record['identity'] != directory_identity(opened) or record['identity'] != directory_identity(current):
        reject('directory_changed', 75)


def open_stable_regular(parent, leaf, modes, maximum, minimum=1, missing_ok=False):
    try:
        before = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
    except FileNotFoundError:
        if missing_ok:
            return None
        raise
    if stat.S_ISLNK(before.st_mode):
        os.readlink(leaf, dir_fd=parent)
        reject('unsafe_file')
    descriptor = os.open(
        leaf,
        os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK,
        dir_fd=parent,
    )
    try:
        opened = os.fstat(descriptor)
        if (
            file_identity(before) != file_identity(opened)
            or not stat.S_ISREG(opened.st_mode)
            or opened.st_uid != TRUSTED_UID
            or opened.st_gid != TRUSTED_GID
            or stat.S_IMODE(opened.st_mode) not in modes
            or opened.st_nlink != 1
            or opened.st_size < minimum
            or opened.st_size > maximum
        ):
            reject('unsafe_file')
        return {
            'fd': descriptor,
            'parent': parent,
            'leaf': leaf,
            'identity': file_identity(opened),
        }
    except BaseException:
        os.close(descriptor)
        raise


def ensure_file_stable(record):
    opened = os.fstat(record['fd'])
    current = os.stat(record['leaf'], dir_fd=record['parent'], follow_symlinks=False)
    if record['identity'] != file_identity(opened) or record['identity'] != file_identity(current):
        reject('file_changed', 75)


def close_record(record):
    if record is not None:
        try:
            os.close(record['fd'])
        except OSError:
            pass


def open_existing_lock(parent, leaf, busy_reason):
    record = open_stable_regular(parent, leaf, {0o600}, 0, minimum=0)
    try:
        if record['identity'][6] != 0:
            reject('unsafe_lock')
        try:
            fcntl.flock(record['fd'], fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            reject(busy_reason, 75)
        ensure_file_stable(record)
        return record
    except BaseException:
        close_record(record)
        raise


def decode_canonical(data, maximum, reason):
    if len(data) > maximum or not data.endswith(b'\n'):
        reject(reason)
    try:
        value = json.loads(data.decode('utf-8'))
    except (UnicodeDecodeError, json.JSONDecodeError):
        reject(reason)
    if canonical_json(value) != data:
        reject(reason)
    return value


def decode_authorization(data):
    value = decode_canonical(data, MAX_AUTHORIZATION_BYTES, 'invalid_authorization')
    if not isinstance(value, dict) or set(value) != {'schema', 'targets'}:
        reject('invalid_authorization')
    targets = value.get('targets')
    if value.get('schema') != AUTHORIZATION_SCHEMA or not isinstance(targets, list) or len(targets) != 2:
        reject('invalid_authorization')
    decoded = {}
    for index, target in enumerate(targets):
        role = 'current' if index == 0 else 'rollback'
        if (
            not isinstance(target, dict)
            or set(target) != {'archive_sha256', 'expected_commit', 'release_id', 'required_members', 'role'}
            or target.get('role') != role
            or not isinstance(target.get('release_id'), str)
            or RELEASE_ID.fullmatch(target.get('release_id', '')) is None
            or not isinstance(target.get('expected_commit'), str)
            or COMMIT.fullmatch(target.get('expected_commit', '')) is None
            or not isinstance(target.get('archive_sha256'), str)
            or SHA256.fullmatch(target.get('archive_sha256', '')) is None
        ):
            reject('invalid_authorization')
        required_members = target.get('required_members')
        if not isinstance(required_members, dict) or tuple(sorted(required_members)) != REQUIRED_MEMBERS:
            reject('invalid_authorization')
        if any(
            not isinstance(required_members.get(member), str)
            or SHA256.fullmatch(required_members.get(member, '')) is None
            for member in REQUIRED_MEMBERS
        ):
            reject('invalid_authorization')
        decoded[role] = target
    if decoded['current']['release_id'] == decoded['rollback']['release_id']:
        reject('invalid_authorization')
    return decoded


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
        reject('unsafe_tar')
    return normalized


def inspect_archive(record, member_hashes):
    if not isinstance(member_hashes, dict) or tuple(sorted(member_hashes)) != REQUIRED_MEMBERS or any(
        not isinstance(value, str) or SHA256.fullmatch(value) is None for value in member_hashes.values()
    ):
        reject('required_member_invalid')
    return _inspect_archive(record, member_hashes)


def observe_archive(record):
    """Observe a stable archive without weakening authorized hash checks."""
    return _inspect_archive(record, {member: '0' * 64 for member in REQUIRED_MEMBERS}, observe_only=True)


def _inspect_archive(record, member_hashes, observe_only=False):
    archive_sha256 = digest_fd(record['fd'])
    os.lseek(record['fd'], 0, os.SEEK_SET)
    member_names = set()
    stage_types = {}
    file_count = 0
    unpacked_bytes = BLOCK_BYTES
    observed_required = {}
    member_count = 0
    try:
        with os.fdopen(os.dup(record['fd']), 'rb', closefd=True) as source:
            with tarfile.open(fileobj=source, mode='r:gz') as archive:
                # Stream entries and enforce the cap before retaining an
                # attacker-controlled full getmembers result in memory.
                for member in archive:
                    member_count += 1
                    if member_count > MAX_ARCHIVE_ENTRIES:
                        reject('unsafe_tar')
                    if member.name in ('.', './') and member.isdir():
                        continue
                    name = normalize_member_name(member.name)
                    if name in member_names or not (member.isfile() or member.isdir()):
                        reject('unsafe_tar')
                    member_names.add(name)
                    parts = name.split('/')
                    for index in range(1, len(parts)):
                        parent = '/'.join(parts[:index])
                        if parent in stage_types and stage_types[parent] == 'file':
                            reject('unsafe_tar')
                        if parent not in stage_types:
                            stage_types[parent] = 'directory'
                            unpacked_bytes += BLOCK_BYTES
                            if len(stage_types) > MAX_ARCHIVE_ENTRIES:
                                reject('unsafe_tar')
                            if unpacked_bytes > MAX_UNPACKED_BYTES:
                                reject('unsafe_tar')
                    member_type = 'directory' if member.isdir() else 'file'
                    previous_type = stage_types.get(name)
                    if previous_type is not None and previous_type != member_type:
                        reject('unsafe_tar')
                    if previous_type is None:
                        stage_types[name] = member_type
                        if len(stage_types) > MAX_ARCHIVE_ENTRIES:
                            reject('unsafe_tar')
                    if member.isdir() and previous_type is None:
                        unpacked_bytes += BLOCK_BYTES
                        if unpacked_bytes > MAX_UNPACKED_BYTES:
                            reject('unsafe_tar')
                    elif member.isfile():
                        if member.size < 0:
                            reject('unsafe_tar')
                        file_count += 1
                        unpacked_bytes += max(BLOCK_BYTES, ((member.size + BLOCK_BYTES - 1) // BLOCK_BYTES) * BLOCK_BYTES)
                        if unpacked_bytes > MAX_UNPACKED_BYTES:
                            reject('unsafe_tar')
                        if name in member_hashes:
                            if member.size > MAX_REQUIRED_MEMBER_BYTES:
                                reject('required_member_invalid')
                            extracted = archive.extractfile(member)
                            if extracted is None:
                                reject('required_member_invalid')
                            digest = hashlib.sha256()
                            total = 0
                            while True:
                                chunk = extracted.read(64 * 1024)
                                if not chunk:
                                    break
                                total += len(chunk)
                                if total > MAX_REQUIRED_MEMBER_BYTES:
                                    reject('required_member_invalid')
                                digest.update(chunk)
                            if total != member.size:
                                reject('required_member_invalid')
                            observed_required[name] = digest.hexdigest()
    except LegacyProvenanceError:
        raise
    except (OSError, EOFError, tarfile.TarError, ValueError):
        reject('unsafe_tar')
    if set(observed_required) != set(member_hashes):
        reject('required_member_missing')
    if not observe_only and any(not secrets.compare_digest(observed_required[name], member_hashes[name]) for name in member_hashes):
        reject('required_member_mismatch')
    ensure_file_stable(record)
    return {
        'archive_sha256': archive_sha256,
        'entry_count': file_count,
        'stage_inode_count': len(stage_types) + 1,
        'stage_unpacked_bytes': unpacked_bytes,
        'required_members': observed_required,
    }


def read_release_marker(directory):
    record = open_stable_regular(directory, '_RELEASE', {0o600, 0o640, 0o644}, 512)
    try:
        data = read_all(record['fd'], 512)
        ensure_file_stable(record)
        try:
            release_id = data.decode('ascii').split()[0]
        except (UnicodeDecodeError, IndexError):
            reject('invalid_release_marker')
        if RELEASE_ID.fullmatch(release_id) is None:
            reject('invalid_release_marker')
        return release_id, record
    except BaseException:
        close_record(record)
        raise


def build_sidecar(target, archive_record, archive_observation):
    required_members = target['required_members']
    value = {
        'archive': {
            'name': target['release_id'] + '.tar.gz',
            'sha256': archive_observation['archive_sha256'],
            'size_bytes': archive_record['identity'][6],
        },
        'capacity_bounds': {
            'stage_file_count': archive_observation['entry_count'],
            'stage_inode_count': archive_observation['stage_inode_count'],
            'stage_unpacked_bytes': archive_observation['stage_unpacked_bytes'],
            'temp_scratch_bytes': TEMP_SCRATCH_BYTES,
        },
        'expected_commit': target['expected_commit'],
        'observed_commit': target['expected_commit'],
        'release_id': target['release_id'],
        'schema': SIDECAR_SCHEMA,
        'source': {
            'build_script_sha256': required_members['build_release.sh'],
            'composer_lock_sha256': required_members['composer.lock'],
            'deploy_ea_sha256': required_members['deploy_ea.sh'],
            'package_lock_sha256': required_members['package-lock.json'],
        },
    }
    data = canonical_json(value)
    if len(data) > MAX_SIDECAR_BYTES:
        reject('sidecar_too_large')
    return data


def open_existing_sidecar(releases, leaf, expected):
    record = open_stable_regular(releases, leaf, {0o600}, MAX_SIDECAR_BYTES, missing_ok=True)
    if record is None:
        return None
    try:
        data = read_all(record['fd'], MAX_SIDECAR_BYTES)
        ensure_file_stable(record)
        if not secrets.compare_digest(data, expected):
            reject('sidecar_conflict')
        return record
    except BaseException:
        close_record(record)
        raise


def open_owned_temps(releases, expected_by_release):
    generic = re.compile(r'\.([A-Za-z0-9][A-Za-z0-9._-]{0,127})\.build-provenance\.json\.rob468-([0-9a-f]{32})\.tmp\Z')
    result = {release_id: [] for release_id in expected_by_release}
    try:
        names = os.listdir(releases)
    except OSError:
        reject('release_scan_failed')
    for leaf in names:
        match = generic.fullmatch(leaf)
        if match is None:
            continue
        release_id, nonce = match.groups()
        if release_id not in expected_by_release or NONCE.fullmatch(nonce) is None:
            reject('foreign_helper_temp')
        record = open_stable_regular(releases, leaf, {0o600}, MAX_SIDECAR_BYTES)
        try:
            data = read_all(record['fd'], MAX_SIDECAR_BYTES)
            ensure_file_stable(record)
            if not secrets.compare_digest(data, expected_by_release[release_id]):
                reject('unsafe_helper_temp')
            result[release_id].append(record)
        except BaseException:
            close_record(record)
            raise
    for records in result.values():
        records.sort(key=lambda item: item['leaf'])
    return result


def _preflight_targets(context):
    try:
        authorization_record = open_stable_regular(
            context['etc_fh'], os.path.basename(AUTHORIZATION), {0o600}, MAX_AUTHORIZATION_BYTES,
        )
    except FileNotFoundError:
        reject('invalid_authorization')
    context['records'].append(authorization_record)
    authorization = decode_authorization(read_all(authorization_record['fd'], MAX_AUTHORIZATION_BYTES))
    ensure_file_stable(authorization_record)

    deploy_record = open_stable_regular(context['root'], os.path.basename(INSTALLED_DEPLOY_EA), {0o555, 0o755}, MAX_REQUIRED_MEMBER_BYTES)
    context['records'].append(deploy_record)
    installed_deploy_sha256 = digest_fd(deploy_record['fd'])
    ensure_file_stable(deploy_record)

    current_release, current_marker = read_release_marker(context['current'])
    context['records'].append(current_marker)
    rollback_leaf = 'easyappointments_prev_' + current_release
    try:
        rollback, rollback_directory_record = open_child_directory(context['web'], rollback_leaf)
    except FileNotFoundError:
        reject('missing_exact_rollback')
    context['directories'].append(rollback_directory_record)
    context['opened'].append(rollback)
    rollback_release, rollback_marker = read_release_marker(rollback)
    context['records'].append(rollback_marker)
    if current_release != authorization['current']['release_id'] or rollback_release != authorization['rollback']['release_id']:
        reject('authorization_marker_mismatch')

    plans = []
    expected_by_release = {}
    for role in ('current', 'rollback'):
        target = authorization[role]
        if not secrets.compare_digest(target['required_members']['deploy_ea.sh'], installed_deploy_sha256):
            reject('installed_deploy_ea_mismatch')
        archive_leaf = target['release_id'] + '.tar.gz'
        archive_record = open_stable_regular(context['releases'], archive_leaf, {0o600}, MAX_ARCHIVE_BYTES)
        context['records'].append(archive_record)
        archive_observation = inspect_archive(archive_record, target['required_members'])
        if not secrets.compare_digest(archive_observation['archive_sha256'], target['archive_sha256']):
            reject('archive_digest_mismatch')
        sidecar_data = build_sidecar(target, archive_record, archive_observation)
        sidecar_leaf = target['release_id'] + '.build-provenance.json'
        sidecar_record = open_existing_sidecar(context['releases'], sidecar_leaf, sidecar_data)
        if sidecar_record is not None:
            context['records'].append(sidecar_record)
        expected_by_release[target['release_id']] = sidecar_data
        plans.append({
            'archive': archive_record,
            'data': sidecar_data,
            'existing': sidecar_record,
            'leaf': sidecar_leaf,
            'release_id': target['release_id'],
            'role': role,
            'temps': [],
        })

    temps = open_owned_temps(context['releases'], expected_by_release)
    for plan in plans:
        plan['temps'] = temps[plan['release_id']]
        context['records'].extend(plan['temps'])
    ensure_context_stable(context)
    return plans


def _read_json_record(parent, leaf, maximum):
    record = open_stable_regular(parent, leaf, {0o600}, maximum)
    try:
        data = read_all(record['fd'], maximum)
        ensure_file_stable(record)
        return data, record
    except BaseException:
        close_record(record)
        raise


def _terminal_commit_authority(context, release_id):
    """Derive one commit only from a validated, successful host run bundle."""
    try:
        runs, runs_record = open_absolute_directory(RUNS_ROOT, exact_mode=0o700)
    except FileNotFoundError:
        reject('authorization_authority_missing')
    candidates = []
    try:
        names = os.listdir(runs)
        if len(names) > 10000:
            reject('authorization_authority_ambiguous')
        for run_id in names:
            if re.fullmatch(r'[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}', run_id) is None:
                reject('terminal_bundle_invalid')
            try:
                run_fd, run_record = open_child_directory(runs, run_id, exact_mode=0o700)
            except FileNotFoundError:
                reject('terminal_bundle_invalid')
            intent = events_record = evidence_record = None
            try:
                intent_bytes, intent = _read_json_record(run_fd, 'intent.json', 65536)
                events, events_record = _read_json_record(run_fd, 'events.jsonl', MAX_TERMINAL_EVENTS_BYTES)
                evidence, evidence_record = _read_json_record(
                    run_fd, 'evidence.json', MAX_TERMINAL_EVIDENCE_BYTES,
                )
                try:
                    first_line = events.split(b'\n', 1)[0]
                    intent_value = decode_canonical(first_line + b'\n', 65536, 'terminal_bundle_invalid')
                    intent_cache = decode_canonical(intent_bytes, 65536, 'terminal_bundle_invalid')
                except LegacyProvenanceError:
                    raise
                if (
                    not isinstance(intent_value, dict)
                    or not isinstance(intent_cache, dict)
                    or intent_cache != intent_value
                    or intent_value.get('record_type') != 'intent'
                    or intent_value.get('sequence') != 1
                ):
                    reject('terminal_bundle_invalid')
                if intent_value.get('release_id') != release_id:
                    ensure_file_stable(intent)
                    ensure_file_stable(events_record)
                    ensure_file_stable(evidence_record)
                    ensure_directory_stable(run_record)
                    continue
                commit = intent_value.get('expected_commit')
                if not isinstance(commit, str) or COMMIT.fullmatch(commit) is None:
                    reject('terminal_bundle_invalid')
                envelope = canonical_json({
                    'events': base64.b64encode(events).decode('ascii'),
                    'evidence': base64.b64encode(evidence).decode('ascii'),
                })
                if len(envelope) > MAX_TERMINAL_ENVELOPE_BYTES:
                    reject('terminal_bundle_invalid')
                validator = os.path.dirname(TERMINAL_VALIDATOR)
                validator_fd, validator_record = open_absolute_directory(validator, exact_mode=0o755)
                validator_file = None
                contract_file = None
                try:
                    validator_leaf = os.path.basename(TERMINAL_VALIDATOR)
                    validator_file = open_stable_regular(validator_fd, validator_leaf, {0o555}, 1048576)
                    contract_file = open_stable_regular(
                        validator_fd, os.path.basename(TERMINAL_CONTRACT), {0o444}, 1048576,
                    )
                    validator_identity = validator_file['identity']
                    contract_identity = contract_file['identity']
                    result = subprocess.run(
                        [PHP, '-n', TERMINAL_VALIDATOR], input=envelope,
                        stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                        timeout=30, check=False,
                        env={'PATH': '/usr/bin:/bin', 'LANG': 'C', 'LC_ALL': 'C'},
                    )
                    ensure_file_stable(validator_file)
                    if validator_file['identity'] != validator_identity:
                        reject('validator_unavailable')
                    ensure_file_stable(contract_file)
                    if contract_file['identity'] != contract_identity:
                        reject('validator_unavailable')
                    ensure_directory_stable(validator_record)
                except (OSError, subprocess.SubprocessError):
                    reject('validator_unavailable')
                finally:
                    close_record(validator_file)
                    close_record(contract_file)
                    os.close(validator_fd)
                if result.returncode != 0:
                    reject('terminal_bundle_invalid')
                validated = decode_canonical(result.stdout, 4096, 'terminal_bundle_invalid')
                if (
                    not isinstance(validated, dict)
                    or set(validated) != {'intent_sha256', 'records', 'run_id', 'schema', 'state'}
                    or validated.get('schema') != 'deployment_terminal_bundle_validation.v1'
                    or validated.get('run_id') != run_id
                    or validated.get('intent_sha256') != intent_value.get('intent_sha256')
                    or validated.get('records') != len(events.rstrip(b'\n').split(b'\n'))
                ):
                    reject('terminal_bundle_invalid')
                ensure_file_stable(intent)
                ensure_file_stable(events_record)
                ensure_file_stable(evidence_record)
                ensure_directory_stable(run_record)
                if validated.get('state') != 'succeeded':
                    continue
                candidates.append(commit)
            finally:
                for record in (intent, events_record, evidence_record):
                    close_record(record)
                os.close(run_fd)
        ensure_directory_stable(runs_record)
        unique = sorted(set(candidates))
        if len(unique) != 1:
            reject('authorization_authority_missing' if not unique else 'authorization_authority_ambiguous')
        return unique[0]
    finally:
        os.close(runs)


def derive_authorization(context):
    """Build canonical authorization solely from fixed host-local facts."""
    current_release, current_marker = read_release_marker(context['current'])
    rollback_leaf = 'easyappointments_prev_' + current_release
    try:
        rollback, rollback_directory_record = open_child_directory(context['web'], rollback_leaf)
    except FileNotFoundError:
        reject('missing_exact_rollback')
    context['directories'].append(rollback_directory_record)
    context['opened'].append(rollback)
    rollback_release, rollback_marker = read_release_marker(rollback)
    context['records'].extend((current_marker, rollback_marker))
    if current_release == rollback_release:
        reject('invalid_release_marker')
    deploy_record = open_stable_regular(context['root'], os.path.basename(INSTALLED_DEPLOY_EA), {0o555, 0o755}, MAX_REQUIRED_MEMBER_BYTES)
    context['records'].append(deploy_record)
    deploy_digest = digest_fd(deploy_record['fd'])
    ensure_file_stable(deploy_record)
    targets = []
    for role, release_id in (('current', current_release), ('rollback', rollback_release)):
        archive_record = open_stable_regular(context['releases'], release_id + '.tar.gz', {0o600}, MAX_ARCHIVE_BYTES)
        context['records'].append(archive_record)
        observed = observe_archive(archive_record)
        members = observed['required_members']
        if not secrets.compare_digest(members['deploy_ea.sh'], deploy_digest):
            reject('installed_deploy_ea_mismatch')
        targets.append({
            'archive_sha256': observed['archive_sha256'],
            'expected_commit': _terminal_commit_authority(context, release_id),
            'release_id': release_id,
            'required_members': {member: members[member] for member in REQUIRED_MEMBERS},
            'role': role,
        })
    return {'schema': AUTHORIZATION_SCHEMA, 'targets': targets}


def preflight_authorization(context):
    value = derive_authorization(context)
    encoded = canonical_json(value)
    if len(encoded) > MAX_AUTHORIZATION_BYTES:
        reject('invalid_authorization')
    try:
        existing = open_stable_regular(context['etc_fh'], os.path.basename(AUTHORIZATION), {0o600}, MAX_AUTHORIZATION_BYTES, missing_ok=True)
    except OSError:
        reject('authorization_conflict')
    if existing is not None:
        try:
            data = read_all(existing['fd'], MAX_AUTHORIZATION_BYTES)
            ensure_file_stable(existing)
            if not secrets.compare_digest(data, encoded):
                reject('authorization_conflict')
        finally:
            close_record(existing)
    prefix = '.' + os.path.basename(AUTHORIZATION) + '.rob468-'
    pattern = re.compile(re.escape(prefix) + r'([0-9a-f]{32})\.tmp\Z')
    context['authorization_temps'] = []
    for leaf in os.listdir(context['etc_fh']):
        if not leaf.startswith(prefix):
            continue
        match = pattern.fullmatch(leaf)
        if match is None:
            reject('foreign_helper_temp')
        record = open_stable_regular(context['etc_fh'], leaf, {0o600}, MAX_AUTHORIZATION_BYTES)
        try:
            if not secrets.compare_digest(read_all(record['fd'], MAX_AUTHORIZATION_BYTES), encoded):
                reject('unsafe_helper_temp')
            ensure_file_stable(record)
            context['authorization_temps'].append(record)
            context['records'].append(record)
        except BaseException:
            close_record(record)
            raise
    ensure_context_stable(context)
    return encoded, existing is not None


def provision_authorization(context, encoded, existing, mutations):
    stale = context.get('authorization_temps', [])
    for record in stale:
        ensure_file_stable(record)
        mutations.begin()
        try:
            os.unlink(record['leaf'], dir_fd=context['etc_fh'])
            os.fsync(context['etc_fh'])
            mutations.confirm('temp_files_removed')
        except FileNotFoundError:
            mutations.cancel()
    if existing:
        return False
    plan = {'data': encoded, 'leaf': os.path.basename(AUTHORIZATION)}
    temp = create_temp(context['etc_fh'], plan, mutations, MAX_AUTHORIZATION_BYTES)
    try:
        ensure_file_stable(temp)
        os.fsync(temp['fd'])
        mutations.begin()
        try:
            renameat2_noreplace(context['etc_fh'], temp['leaf'], os.path.basename(AUTHORIZATION))
        except OSError as error:
            if error.errno != errno.EEXIST:
                raise
            mutations.cancel()
            existing_record = open_stable_regular(
                context['etc_fh'], os.path.basename(AUTHORIZATION), {0o600}, MAX_AUTHORIZATION_BYTES,
            )
            try:
                exact = secrets.compare_digest(read_all(existing_record['fd'], MAX_AUTHORIZATION_BYTES), encoded)
                ensure_file_stable(existing_record)
            finally:
                close_record(existing_record)
            remove_temp(context['etc_fh'], temp, mutations)
            if not exact:
                reject('authorization_conflict')
            return False
        os.fsync(context['etc_fh'])
        attached = open_stable_regular(
            context['etc_fh'], os.path.basename(AUTHORIZATION), {0o600}, MAX_AUTHORIZATION_BYTES,
        )
        try:
            if not secrets.compare_digest(read_all(attached['fd'], MAX_AUTHORIZATION_BYTES), encoded):
                reject('authorization_conflict')
            ensure_file_stable(attached)
        finally:
            close_record(attached)
        mutations.confirm('authorization_published')
        return True
    finally:
        close_record(temp)


def ensure_context_stable(context):
    for directory in context['directories']:
        ensure_directory_stable(directory)
    for record in context['records']:
        ensure_file_stable(record)


def renameat2_noreplace(directory, source, target):
    libc = ctypes.CDLL(None, use_errno=True)
    if hasattr(libc, 'renameat2'):
        result = libc.renameat2(
            directory,
            source.encode(),
            directory,
            target.encode(),
            RENAME_NOREPLACE,
        )
    elif sys.platform == 'darwin' and hasattr(libc, 'renameatx_np'):
        # Local contract-test path. Darwin RENAME_EXCL has the same no-replace
        # namespace guarantee; production Linux remains bound to renameat2.
        result = libc.renameatx_np(
            directory,
            source.encode(),
            directory,
            target.encode(),
            0x00000004,
        )
    else:
        reject('renameat2_unavailable')
    if result != 0:
        error = ctypes.get_errno()
        raise OSError(error, os.strerror(error))


def remove_temp(releases, record, mutations):
    ensure_file_stable(record)
    mutations.begin()
    try:
        os.unlink(record['leaf'], dir_fd=releases)
    except FileNotFoundError:
        mutations.cancel()
        return
    try:
        os.fsync(releases)
    except BaseException:
        raise
    mutations.confirm('temp_files_removed')


def create_temp(releases, plan, mutations, maximum=MAX_SIDECAR_BYTES):
    leaf = '.' + plan['leaf'] + '.rob468-' + secrets.token_hex(16) + '.tmp'
    mutations.begin()
    try:
        descriptor = os.open(
            leaf,
            os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK,
            0o600,
            dir_fd=releases,
        )
    except BaseException:
        mutations.cancel()
        raise
    mutations.confirm('temp_files_created')
    created_stat = os.fstat(descriptor)
    created_identity = (
        created_stat.st_dev,
        created_stat.st_ino,
        created_stat.st_mode,
        created_stat.st_uid,
        created_stat.st_gid,
        created_stat.st_nlink,
    )
    try:
        offset = 0
        while offset < len(plan['data']):
            written = os.write(descriptor, plan['data'][offset:])
            if written <= 0:
                reject('temp_write_failed')
            offset += written
        os.fsync(descriptor)
        os.fsync(releases)
        os.close(descriptor)
    except BaseException:
        try:
            os.close(descriptor)
        except OSError:
            pass
        try:
            record = open_stable_regular(releases, leaf, {0o600}, maximum, minimum=0)
            try:
                if record['identity'][:6] != created_identity:
                    reject('temp_verify_failed')
                remove_temp(releases, record, mutations)
            finally:
                close_record(record)
        except FileNotFoundError:
            pass
        raise
    record = open_stable_regular(releases, leaf, {0o600}, maximum)
    if not secrets.compare_digest(read_all(record['fd'], maximum), plan['data']):
        close_record(record)
        reject('temp_verify_failed')
    ensure_file_stable(record)
    return record


def exact_attach(releases, plan, temp, mutations):
    ensure_file_stable(temp)
    os.fsync(temp['fd'])
    mutations.begin()
    try:
        renameat2_noreplace(releases, temp['leaf'], plan['leaf'])
    except OSError as error:
        if error.errno != errno.EEXIST:
            raise
        mutations.cancel()
        existing = open_existing_sidecar(releases, plan['leaf'], plan['data'])
        if existing is None:
            reject('publication_conflict')
        close_record(existing)
        remove_temp(releases, temp, mutations)
        return 'attached'
    os.fsync(releases)
    existing = open_existing_sidecar(releases, plan['leaf'], plan['data'])
    if existing is None:
        reject('publication_missing')
    close_record(existing)
    mutations.confirm('sidecars_published')
    return 'published'


def execute_plans(context, plans, mutations):
    global RUN_STATE
    published = 0
    attached = 0
    for plan in plans:
        if plan['existing'] is not None:
            attached += 1
            RUN_STATE['attached'] = attached
            for temp in plan['temps']:
                remove_temp(context['releases'], temp, mutations)
            continue
        temp = plan['temps'][0] if plan['temps'] else create_temp(context['releases'], plan, mutations)
        for extra in plan['temps'][1:]:
            remove_temp(context['releases'], extra, mutations)
        status_value = exact_attach(context['releases'], plan, temp, mutations)
        if status_value == 'published':
            published += 1
            RUN_STATE['published'] = published
        else:
            attached += 1
            RUN_STATE['attached'] = attached
        RUN_STATE['pending'] = max(0, RUN_STATE['pending'] - 1)
    return published, attached


def open_context():
    context = {'directories': [], 'opened': [], 'records': []}
    try:
        orchestrator, orchestrator_record = open_absolute_directory(ORCHESTRATOR_ROOT, exact_mode=0o700)
        context['opened'].append(orchestrator)
        context['directories'].append(orchestrator_record)
        locks, locks_record = open_child_directory(orchestrator, 'locks', exact_mode=0o700)
        context['opened'].append(locks)
        context['directories'].append(locks_record)
        global_lock = open_existing_lock(locks, GLOBAL_PRODUCTION_LOCK, 'global_lock_busy')
        context['records'].append(global_lock)

        releases, releases_record = open_absolute_directory(RELEASES_ROOT, exact_mode=0o700)
        context['releases'] = releases
        context['opened'].append(releases)
        context['directories'].append(releases_record)
        publication_lock = open_existing_lock(releases, PUBLICATION_LOCK, 'publication_lock_busy')
        context['records'].append(publication_lock)

        etc_fh, etc_record = open_absolute_directory(os.path.dirname(AUTHORIZATION), exact_mode=0o700)
        context['etc_fh'] = etc_fh
        context['opened'].append(etc_fh)
        context['directories'].append(etc_record)
        root, root_record = open_absolute_directory('/root')
        context['root'] = root
        context['opened'].append(root)
        context['directories'].append(root_record)
        web, web_record = open_absolute_directory(WEB_ROOT)
        context['web'] = web
        context['opened'].append(web)
        context['directories'].append(web_record)
        current, current_record = open_child_directory(web, os.path.basename(APP_ROOT))
        context['current'] = current
        context['opened'].append(current)
        context['directories'].append(current_record)
        return context
    except BaseException:
        close_context(context)
        raise


def close_context(context):
    seen = set()
    for record in reversed(context.get('records', [])):
        descriptor = record.get('fd')
        if descriptor not in seen:
            seen.add(descriptor)
            close_record(record)
    for descriptor in reversed(context.get('opened', [])):
        if descriptor not in seen:
            seen.add(descriptor)
            try:
                os.close(descriptor)
            except OSError:
                pass


def result_payload(mode, status_value, reason, preflighted, pending, published, attached, mutations):
    mutation_fields = mutations.fields()
    published = max(published, mutation_fields['mutation_counts']['sidecars_published'])
    payload = {
        'mode': mode,
        'mutation_counts': mutation_fields['mutation_counts'],
        'mutation_outcome': mutation_fields['mutation_outcome'],
        'schema': RESULT_SCHEMA,
        'status': status_value,
        'targets': {
            'attached': attached,
            'pending': pending,
            'preflighted': preflighted,
            'published': published,
        },
    }
    if reason is not None:
        payload['reason'] = PUBLIC_REASONS.get(reason, 'internal_error')
    return payload


def authorization_result_payload(mode, status_value, reason, state, mutations):
    fields = mutations.fields()
    payload = {
        'mode': mode,
        'mutation_counts': fields['mutation_counts'],
        'mutation_outcome': fields['mutation_outcome'],
        'schema': AUTH_RESULT_SCHEMA,
        'status': status_value,
        'authorization': {
            'attached': state['attached'], 'pending': state['pending'], 'published': state['published'],
        },
        'targets': {'preflighted': state['preflighted']},
    }
    if reason is not None:
        payload['reason'] = PUBLIC_REASONS.get(reason, 'internal_error')
    return payload


def emit(payload):
    sys.stdout.write(canonical_json(payload).decode('utf-8'))
    sys.stdout.flush()


def run(mode):
    global RUN_STATE, AUTH_RUN_STATE, MUTATIONS
    if os.geteuid() != TRUSTED_UID:
        reject('root_required')
    if os.path.realpath(AUTHORIZATION) != AUTHORIZATION or os.path.realpath(INSTALLED_DEPLOY_EA) != INSTALLED_DEPLOY_EA:
        reject('noncanonical_fixed_path')
    context = open_context()
    try:
        if mode in ('inspect-authorization', 'provision-authorization'):
            auth_mutations = MutationLedger(AUTH_MUTATION_KEYS)
            MUTATIONS = auth_mutations
            encoded, existing = preflight_authorization(context)
            AUTH_RUN_STATE = {'attached': 1 if existing else 0, 'pending': 0 if existing else 1, 'preflighted': 2, 'published': 0}
            if mode == 'provision-authorization':
                ensure_context_stable(context)
                was_published = provision_authorization(context, encoded, existing, auth_mutations)
                if was_published:
                    AUTH_RUN_STATE.update({'attached': 0, 'pending': 0, 'published': 1})
                else:
                    AUTH_RUN_STATE.update({'attached': 1, 'pending': 0, 'published': 0})
            return authorization_result_payload(mode, 'pass', None, AUTH_RUN_STATE, auth_mutations)
        plans = preflight_targets(context)
        pending = sum(1 for plan in plans if plan['existing'] is None)
        attached = len(plans) - pending
        RUN_STATE.update({'attached': attached, 'pending': pending, 'preflighted': len(plans), 'published': 0})
        if mode == 'inspect':
            return result_payload(mode, 'pass', None, len(plans), pending, 0, attached, MUTATIONS)
        ensure_context_stable(context)
        published, attached = execute_plans(context, plans, MUTATIONS)
        return result_payload(mode, 'pass', None, len(plans), 0, published, attached, MUTATIONS)
    finally:
        close_context(context)


def main():
    global MUTATIONS, RUN_STATE, AUTH_RUN_STATE
    MUTATIONS = MutationLedger()
    RUN_STATE = {'attached': 0, 'pending': 0, 'preflighted': 0, 'published': 0}
    AUTH_RUN_STATE = {'attached': 0, 'pending': 0, 'preflighted': 0, 'published': 0}
    arguments = sys.argv[1:]
    if arguments == [] or arguments == ['inspect']:
        mode = 'inspect'
    elif arguments == ['execute', EXECUTE_TOKEN]:
        mode = 'execute'
    elif arguments == ['inspect-authorization']:
        mode = 'inspect-authorization'
    elif arguments == ['provision-authorization', AUTHORIZATION_TOKEN]:
        mode = 'provision-authorization'
    else:
        emit(result_payload('invalid', 'blocked', 'invalid_arguments', 0, 0, 0, 0, MUTATIONS))
        raise SystemExit(64)
    try:
        emit(run(mode))
    except LegacyProvenanceError as error:
        if mode in ('inspect-authorization', 'provision-authorization'):
            emit(authorization_result_payload(mode, 'busy' if error.code == 75 else 'blocked', error.reason, AUTH_RUN_STATE, MUTATIONS))
        else:
            emit(result_payload(mode, 'busy' if error.code == 75 else 'blocked', error.reason, RUN_STATE['preflighted'], RUN_STATE['pending'], RUN_STATE['published'], RUN_STATE['attached'], MUTATIONS))
        raise SystemExit(error.code)
    except BaseException:
        if mode in ('inspect-authorization', 'provision-authorization'):
            emit(authorization_result_payload(mode, 'blocked', 'internal_error', AUTH_RUN_STATE, MUTATIONS))
        else:
            emit(result_payload(mode, 'blocked', 'internal_error', RUN_STATE['preflighted'], RUN_STATE['pending'], RUN_STATE['published'], RUN_STATE['attached'], MUTATIONS))
        raise SystemExit(70)


if __name__ == '__main__':
    os.umask(0o077)
    main()
