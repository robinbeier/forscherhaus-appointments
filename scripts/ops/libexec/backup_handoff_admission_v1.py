#!/usr/bin/python3
"""Read-only admission of one freshly restored backup handoff."""

import datetime
import hashlib
import hmac
import json
import os
import re
import stat
import sys

BACKUP_ROOT = '/root/backups/easyappointments'
EVIDENCE_ROOT = '/var/lib/fh-deploy-evidence/dump-attestations'
CONTINUITY_LEAF = 'backup_continuity_state.json'
HANDOFF_LEAF = 'last_backup_set.json'
MARKER_LEAF = 'last_backup_success.utc'
RESTORE_MARKER_LEAF = 'last_verify_success.utc'
MAX_JSON_BYTES = 4096
MAX_DUMP_BYTES = 16 * 1024 * 1024 * 1024
MAX_AGE_SECONDS = 3600
CHUNK = 1024 * 1024
SHA256 = re.compile(r'[0-9a-f]{64}\Z')
BACKUP_ID = re.compile(r'20[0-9]{6}T[0-9]{6}Z\Z')
UTC = datetime.timezone.utc


class AdmissionError(Exception):
    def __init__(self, result_class):
        super().__init__(result_class)
        self.result_class = result_class


def _identity(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid,
            value.st_nlink, value.st_size, value.st_mtime_ns, value.st_ctime_ns)


def _validate_directory(value, exact_mode=None):
    mode = stat.S_IMODE(value.st_mode)
    if (not stat.S_ISDIR(value.st_mode) or value.st_uid != 0 or value.st_gid != 0 or
            value.st_nlink < 1 or mode & 0o022 or
            (exact_mode is not None and mode != exact_mode)):
        raise AdmissionError('backup_root_unsafe')


def _open_directory(path, exact_mode=None):
    if not path.startswith('/') or path == '/' or '//' in path:
        raise AdmissionError('backup_root_unsafe')
    parent = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW)
    try:
        root = os.fstat(parent)
        _validate_directory(root)
        for index, component in enumerate(path[1:].split('/')):
            if component in ('', '.', '..'):
                raise AdmissionError('backup_root_unsafe')
            before = os.stat(component, dir_fd=parent, follow_symlinks=False)
            child = os.open(component, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW,
                            dir_fd=parent)
            try:
                opened = os.fstat(child)
                after = os.stat(component, dir_fd=parent, follow_symlinks=False)
                _validate_directory(opened, exact_mode if index == len(path[1:].split('/')) - 1 else None)
                if _identity(before) != _identity(opened) or _identity(opened) != _identity(after):
                    raise AdmissionError('identity_drift')
            except BaseException:
                os.close(child)
                raise
            os.close(parent)
            parent = child
        return parent
    except BaseException:
        os.close(parent)
        raise


def _read_file(path, maximum, result_class, collect=True):
    parent_path, leaf = os.path.split(path)
    if not parent_path or not leaf or leaf in ('.', '..') or '/' in leaf:
        raise AdmissionError(result_class)
    try:
        parent = _open_directory(parent_path, 0o700)
    except FileNotFoundError:
        raise AdmissionError(result_class)
    try:
        try:
            before = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
            if (not stat.S_ISREG(before.st_mode) or before.st_uid != 0 or before.st_gid != 0 or
                    before.st_nlink != 1 or stat.S_IMODE(before.st_mode) != 0o600 or
                    before.st_size <= 0 or before.st_size > maximum):
                raise AdmissionError(result_class)
            descriptor = os.open(leaf, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW,
                                 dir_fd=parent)
        except FileNotFoundError:
            raise AdmissionError(result_class)
        try:
            opened = os.fstat(descriptor)
            if _identity(before) != _identity(opened):
                raise AdmissionError('identity_drift')
            chunks = [] if collect else None
            digest = hashlib.sha256()
            total = 0
            while total < opened.st_size:
                chunk = os.read(descriptor, min(CHUNK, opened.st_size - total))
                if not chunk:
                    raise AdmissionError('identity_drift')
                digest.update(chunk)
                if collect:
                    chunks.append(chunk)
                total += len(chunk)
            if os.read(descriptor, 1):
                raise AdmissionError('identity_drift')
            after = os.fstat(descriptor)
            current = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
            if _identity(opened) != _identity(after) or _identity(after) != _identity(current):
                raise AdmissionError('identity_drift')
            return (b''.join(chunks) if collect else None), opened.st_size, digest.hexdigest()
        finally:
            os.close(descriptor)
    finally:
        os.close(parent)


def _decode(value, result_class):
    try:
        return json.loads(value.decode('utf-8'))
    except (UnicodeDecodeError, json.JSONDecodeError):
        raise AdmissionError(result_class)


def _timestamp(value, result_class):
    if not isinstance(value, str) or not re.fullmatch(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z', value):
        raise AdmissionError(result_class)
    try:
        return datetime.datetime.strptime(value, '%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=UTC)
    except ValueError:
        raise AdmissionError(result_class)


def _assert_exact_keys(value, keys, result_class):
    if not isinstance(value, dict) or set(value) != set(keys):
        raise AdmissionError(result_class)


def _stable_dump(path, expected_sha, expected_size):
    _, size, digest = _read_file(path, MAX_DUMP_BYTES, 'dump_mismatch', collect=False)
    if size != expected_size or not hmac.compare_digest(digest, expected_sha):
        raise AdmissionError('dump_mismatch')
    return size, digest


def _canonical(value, result_class='attestation_invalid'):
    try:
        return (json.dumps(value, sort_keys=True, separators=(',', ':')) + '\n').encode('ascii')
    except (TypeError, UnicodeEncodeError):
        raise AdmissionError(result_class)


def _read_json(path, result_class, maximum=MAX_JSON_BYTES):
    data, _, _ = _read_file(path, maximum, result_class)
    return data


def _read_marker(path):
    data = _read_json(path, 'marker_missing', 64)
    if len(data) != 21 or not re.fullmatch(rb'20[0-9]{2}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\n', data):
        raise AdmissionError('marker_mismatch')
    try:
        return datetime.datetime.strptime(data[:-1].decode('ascii'), '%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=UTC)
    except ValueError:
        raise AdmissionError('marker_mismatch')


def admit(expected_continuity_sha):
    """Return private admission details, including the concrete dump path."""
    if not isinstance(expected_continuity_sha, str) or not SHA256.fullmatch(expected_continuity_sha):
        raise AdmissionError('input_invalid')
    try:
        continuity_bytes = _read_json(os.path.join(BACKUP_ROOT, CONTINUITY_LEAF), 'continuity_missing', 8192)
        if not hmac.compare_digest(hashlib.sha256(continuity_bytes).hexdigest(), expected_continuity_sha):
            raise AdmissionError('continuity_mismatch')
        continuity = _decode(continuity_bytes, 'continuity_invalid')
        if _canonical(continuity, 'continuity_invalid') != continuity_bytes:
            raise AdmissionError('continuity_invalid')
        _assert_exact_keys(continuity, ('handoff', 'schema', 'status'), 'continuity_invalid')
        if continuity['schema'] != 'production_backup_continuity_state.v1' or continuity['status'] != 'verified':
            raise AdmissionError('continuity_invalid')

        handoff_bytes = _read_json(os.path.join(BACKUP_ROOT, HANDOFF_LEAF), 'handoff_missing')
        handoff = _decode(handoff_bytes, 'handoff_invalid')
        _assert_exact_keys(handoff, ('backup_set_id', 'compressed_size_bytes', 'dump_sha256', 'schema', 'uncompressed_size_bytes'), 'handoff_invalid')
        if (handoff['schema'] != 'production_backup_set_handoff.v1' or
                not isinstance(handoff['backup_set_id'], str) or not BACKUP_ID.fullmatch(handoff['backup_set_id']) or
                not isinstance(handoff['dump_sha256'], str) or not SHA256.fullmatch(handoff['dump_sha256']) or
                isinstance(handoff['compressed_size_bytes'], bool) or not isinstance(handoff['compressed_size_bytes'], int) or
                handoff['compressed_size_bytes'] <= 0 or handoff['compressed_size_bytes'] > MAX_DUMP_BYTES or
                isinstance(handoff['uncompressed_size_bytes'], bool) or not isinstance(handoff['uncompressed_size_bytes'], int) or
                handoff['uncompressed_size_bytes'] <= 0 or handoff['uncompressed_size_bytes'] > 64 * 1024 * 1024 * 1024 or
                _canonical(handoff, 'handoff_invalid') != handoff_bytes or
                continuity['handoff'] != handoff):
            raise AdmissionError('handoff_invalid')

        backup_id = handoff['backup_set_id']
        producer_marker = _read_marker(os.path.join(BACKUP_ROOT, MARKER_LEAF))
        expected_marker = datetime.datetime.strptime(backup_id, '%Y%m%dT%H%M%SZ').replace(tzinfo=UTC)
        if producer_marker != expected_marker:
            raise AdmissionError('marker_mismatch')

        dump_path = os.path.join(BACKUP_ROOT, backup_id, 'db', 'easyappointments.sql.gz')
        dump_size, dump_sha = _stable_dump(dump_path, handoff['dump_sha256'], handoff['compressed_size_bytes'])
        attestation_path = os.path.join(EVIDENCE_ROOT, handoff['dump_sha256'] + '.json')
        attestation_bytes = _read_json(attestation_path, 'attestation_missing')
        attestation = _decode(attestation_bytes, 'attestation_invalid')
        if _canonical(attestation) != attestation_bytes:
            raise AdmissionError('attestation_invalid')
        _assert_exact_keys(attestation, ('attested_at_utc', 'dump', 'schema', 'verification'), 'attestation_invalid')
        _assert_exact_keys(attestation['dump'], ('created_at_utc', 'sha256', 'size_bytes', 'uncompressed_size_bytes'), 'attestation_invalid')
        _assert_exact_keys(attestation['verification'], ('gzip_verified', 'image', 'method', 'restore_verified', 'restored_at_utc', 'restored_datadir_allocated_bytes', 'restored_datadir_inode_count', 'sha256_verified'), 'attestation_invalid')
        if (attestation['schema'] != 'deployment_dump_attestation.v1' or
                attestation['dump']['sha256'] != dump_sha or
                attestation['dump']['size_bytes'] != dump_size or
                attestation['dump']['uncompressed_size_bytes'] != handoff['uncompressed_size_bytes'] or
                attestation['verification']['sha256_verified'] is not True or
                attestation['verification']['gzip_verified'] is not True or
                attestation['verification']['restore_verified'] is not True or
                attestation['verification']['method'] != 'mariadb_10_11_isolated_restore_v1' or
                attestation['verification']['image'] != 'mariadb@sha256:2f2b6bbcdbaf88afe53b76cb8d73927b623559180c5ab15db2049736f32ec590'):
            raise AdmissionError('attestation_invalid')
        for field in ('restored_datadir_allocated_bytes', 'restored_datadir_inode_count'):
            if (isinstance(attestation['verification'][field], bool) or
                    not isinstance(attestation['verification'][field], int) or
                    attestation['verification'][field] <= 0):
                raise AdmissionError('attestation_invalid')
        attested = _timestamp(attestation['attested_at_utc'], 'attestation_invalid')
        restored = _timestamp(attestation['verification']['restored_at_utc'], 'attestation_invalid')
        created = _timestamp(attestation['dump']['created_at_utc'], 'attestation_invalid')
        now = datetime.datetime.now(UTC)
        restore_marker = _read_marker(os.path.join(BACKUP_ROOT, RESTORE_MARKER_LEAF))
        if (restore_marker != restored or created != expected_marker or
                any(value > now for value in (attested, restored, created)) or created > restored or restored > attested or
                (now - created).total_seconds() >= MAX_AGE_SECONDS or
                (now - attested).total_seconds() >= MAX_AGE_SECONDS or
                (now - restored).total_seconds() >= MAX_AGE_SECONDS):
            raise AdmissionError('stale_restore')
        return {
            'dump_path': dump_path,
            'dump_sha256': dump_sha,
            'dump_size_bytes': dump_size,
            'attested_at_utc': attestation['attested_at_utc'],
        }
    except FileNotFoundError:
        raise AdmissionError('backup_root_unsafe')


def main():
    if len(sys.argv) != 2:
        result_class = 'input_invalid'
    else:
        try:
            admit(sys.argv[1])
            result_class = 'handoff_verified'
        except AdmissionError as error:
            result_class = error.result_class
        except (OSError, TypeError, ValueError):
            result_class = 'handoff_rejected'
    status = 'passed' if result_class == 'handoff_verified' else 'failed'
    sys.stdout.write(json.dumps({'schema': 'backup_handoff_admission.v1', 'status': status, 'result_class': result_class}, separators=(',', ':')) + '\n')
    raise SystemExit(0 if status == 'passed' else 70)


if __name__ == '__main__':
    main()
