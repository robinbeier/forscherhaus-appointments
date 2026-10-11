#!/usr/bin/env python3
"""Durable admission coordinator for the Provider UI smoke.

This process owns no fixture state.  It only binds the existing application
lifecycle to the shared maintenance admission protocol.  A pending record is
published before a timer or fixture action and is cleared only through the
recovery capability after a fresh dormant/clean and systemd-terminal proof.
"""

from __future__ import annotations

import argparse
import contextlib
import errno
import fcntl
import importlib.util
import json
import os
import re
import secrets
import stat
import subprocess
import sys
import time
from pathlib import Path


CORE_PATH = '/usr/local/libexec/fh/maintenance_pending_v1.py'
OPERATION = 'provider_ui_smoke'
RUN_ID_BYTES = 16
ACTION_NAMES = {'install', 'remove', 'smoke'}
PRIVATE_CLEANUP_LOCK_PATH = '/run/lock/fh-provider-ui-smoke.lock'
ARM_ACK_LEAF = '.provider-ui-smoke-arm-ack.json'
MAX_ARM_ACK_BYTES = 4096
# The local browser gate has bounded bootstrap/open/download timeouts, and the
# cleanup service must still have enough time left to acquire the private lock,
# remove the fixture, and prove its terminal state.  Treat a timer with less
# than this much remaining time as unsafe, even when systemd reports it as
# active/waiting.
MIN_CLEANUP_REMAINING_SECONDS = 5 * 60
SAFE_PATH = re.compile(r'\A/[A-Za-z0-9_./-]+\Z')
SYSTEMD_UNIT_OBJECT_PATH = re.compile(
    r'\A/org/freedesktop/systemd1/unit/[A-Za-z0-9_]+\Z')


def fail(reason: str, code: int = 75) -> 'NoReturn':
    print(f'provider_ui_smoke_admission=result=error reason={reason}', file=sys.stderr)
    raise SystemExit(code)


def load_core():
    try:
        spec = importlib.util.spec_from_file_location('fh_maintenance_pending_v1', CORE_PATH)
        if spec is None or spec.loader is None:
            fail('admission_core_unavailable', 69)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module
    except SystemExit:
        raise
    except Exception:
        fail('admission_core_unavailable', 69)


def validate_path(value: str, label: str) -> str:
    path = Path(value)
    if (not path.is_absolute() or not SAFE_PATH.fullmatch(value) or
            value == '/' or '//' in value or '/./' in value or
            '/..' in value or value.endswith('/')):
        fail(f'{label}_invalid', 64)
    return value


def validate_unit(value: str) -> str:
    if not value or any(character not in 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789@._-' for character in value):
        fail('cleanup_unit_invalid', 64)
    return value


def resource_from(args: argparse.Namespace) -> dict[str, str]:
    resource = {
        'app_root': validate_path(args.app_root, 'app_root'),
        'credentials_file': validate_path(args.credentials_file, 'credentials_file'),
        'state_file': validate_path(args.state_file, 'state_file'),
        'cleanup_unit': validate_unit(args.cleanup_unit),
        'lifecycle': args.operation,
    }
    return resource


def expected_record(core, run_id: str, operation: str, resource: dict[str, str],
                    allow_prior_boot: bool = False):
    pending = core.read_pending(None if allow_prior_boot else core.current_boot_id())
    if pending is None:
        fail('pending_missing')
    record = pending[0]
    if record.get('schema') != core.SCHEMA or record.get('epoch') != core.PROTOCOL_EPOCH:
        fail('pending_epoch_unknown')
    if record.get('operation') != OPERATION or record.get('run_id') != run_id:
        fail('pending_binding_changed')
    if record.get('resource_identity') != resource:
        fail('pending_binding_changed')
    if operation == 'smoke' and record.get('resource_identity', {}).get('cleanup_unit') != resource['cleanup_unit']:
        fail('pending_binding_changed')
    return record


def begin(args: argparse.Namespace) -> None:
    core = load_core()
    resource = resource_from(args)
    run_id = secrets.token_hex(RUN_ID_BYTES)
    record = core.make_record(OPERATION, run_id, core.current_boot_id(), resource)
    try:
        with private_cleanup_lock():
            with core.maintenance_admission() as admission:
                _ensure_arm_ack_absent(resource)
                _ensure_credential_temp_absent(resource)
                if args.operation == 'smoke':
                    # A failed dormant check must not leave an unarmed Pending
                    # record. Both the check and publication hold the private
                    # and shared locks; activation independently rechecks the
                    # fixture under its transaction lock.
                    dormant_clean(resource, 'dormant')
                admission.publish_pending(record)
    except core.PendingError as error:
        fail(error.reason, error.code)
    print(run_id)


def assert_bound_fd(args: argparse.Namespace) -> None:
    core = load_core()
    resource = resource_from(args)
    # The run ID alone is never an admission capability.  This command must
    # inherit the caller's already-locked canonical descriptor and must not
    # open, acquire, or release that lock itself.  The PHP owner keeps the
    # descriptor locked for the whole fixture transaction after this check.
    if args.lock_fd != 3:
        fail('lock_fd_invalid', 64)
    expected_operation = {'install': 'install', 'activate': 'smoke',
                          'deactivate': 'smoke', 'remove': 'remove'}[args.lifecycle_action]
    if args.operation != expected_operation:
        fail('lifecycle_operation_mismatch', 64)
    try:
        with core.maintenance_recovery_from_lock_fd(args.lock_fd) as admission:
            record = expected_record(core, args.run_id, args.operation, resource,
                                     allow_prior_boot=args.lifecycle_action == 'deactivate')
            if args.lifecycle_action == 'activate':
                # This check runs on the server while PHP holds the canonical
                # shared lock through its fixture transaction. If the timer
                # already fired during a delayed SSH acknowledgement, refuse
                # activation. If it fires after this check, its cleanup
                # service cannot mutate until PHP releases the same lock.
                arm_ack = _read_arm_ack(resource)
                if arm_ack != _ack_record(record):
                    fail('arm_ack_binding_changed')
                unit_armed(args.cleanup_unit + '.timer', resource, args.run_id,
                           require_remaining=True)
                unit_armed(args.cleanup_unit + '.service', resource, args.run_id)
            admission.admit()
    except core.PendingError as error:
        fail(error.reason, error.code)
    print('provider_ui_smoke_admission=bound')


def _expected_cleanup_argv(resource: dict[str, str], run_id: str) -> str:
    state_dir = str(Path(resource['state_file']).parent)
    return ' '.join((
        '/bin/bash', resource['app_root'] + '/scripts/ops/provider_ui_smoke_principal.sh',
        'deactivate', '--app-root', resource['app_root'],
        '--credentials-file', resource['credentials_file'],
        '--state-dir', state_dir, '--admission-run-id', run_id,
    ))


def _assert_cleanup_unit_binding(values: dict[str, str], unit: str,
                                 resource: dict[str, str], run_id: str) -> None:
    if values.get('Transient') != 'yes':
        fail('systemd_identity_unknown')
    if unit.endswith('.timer'):
        if values.get('Triggers') != unit[:-6] + '.service':
            fail('systemd_identity_unknown')
        return
    expected_argv = _expected_cleanup_argv(resource, run_id)
    command = values.get('ExecStart', '')
    pattern = (r'\{ path=/bin/bash ; argv\[\]=' + re.escape(expected_argv) +
               r' ; [^{}]*\}')
    if re.fullmatch(pattern, command) is None:
        fail('systemd_identity_unknown')


def unit_terminal(unit: str, resource: dict[str, str], run_id: str) -> bool:
    result = subprocess.run(
        ['systemctl', 'show', '--no-pager',
         '--property=Id,LoadState,ActiveState,SubState,UnitFileState,Transient,Triggers,ExecStart', unit],
        stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
        text=True, check=False, timeout=10,
    )
    if result.returncode != 0:
        fail('systemd_state_unknown')
    values = {}
    for line in result.stdout.splitlines():
        if '=' in line:
            key, value = line.split('=', 1)
            values[key] = value
    if values.get('Id') != unit:
        fail('systemd_identity_unknown')
    if values.get('LoadState') == 'not-found':
        # settle holds the private lifecycle lock across this observation and
        # pending clear, so an arm cannot be between opening systemd-run and
        # publishing its units here.
        return True
    if values.get('LoadState') != 'loaded':
        fail('systemd_state_unknown')
    _assert_cleanup_unit_binding(values, unit, resource, run_id)
    if values.get('ActiveState') != 'inactive':
        fail('systemd_not_terminal')
    if values.get('SubState') != 'dead':
        fail('systemd_not_terminal')
    return True


def _raw_timer_deadline_monotonic_us(unit: str) -> int:
    """Read the timer deadline as a raw uint64 from systemd's D-Bus API.

    ``systemctl show`` humanizes ``*USec`` fields on some systemd versions,
    so its textual value is not a stable numeric interface.  Resolve the
    exact requested unit through the manager first, then query only the Timer
    property on that returned object.  Any unknown, malformed, or failed
    response remains fail-closed.
    """
    resolved = subprocess.run(
        ['busctl', '--system', 'call',
         'org.freedesktop.systemd1', '/org/freedesktop/systemd1',
         'org.freedesktop.systemd1.Manager', 'GetUnit', 's', unit],
        stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        text=True, check=False, timeout=10,
    )
    if resolved.returncode != 0 or resolved.stderr:
        fail('cleanup_deadline_unknown')
    match = re.fullmatch(r'o\s+"([^"]+)"', resolved.stdout.strip())
    if match is None or SYSTEMD_UNIT_OBJECT_PATH.fullmatch(match.group(1)) is None:
        fail('cleanup_deadline_unknown')
    object_path = match.group(1)
    deadline = subprocess.run(
        ['busctl', '--system', 'get-property',
         'org.freedesktop.systemd1', object_path,
         'org.freedesktop.systemd1.Timer', 'NextElapseUSecMonotonic'],
        stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        text=True, check=False, timeout=10,
    )
    if deadline.returncode != 0 or deadline.stderr:
        fail('cleanup_deadline_unknown')
    match = re.fullmatch(r't\s+([0-9]+)', deadline.stdout.strip())
    if match is None:
        fail('cleanup_deadline_unknown')
    return int(match.group(1))


def unit_armed(unit: str, resource: dict[str, str], run_id: str,
               require_remaining: bool = False) -> bool:
    result = subprocess.run(
        ['systemctl', 'show', '--no-pager',
         '--property=Id,LoadState,ActiveState,SubState,Transient,Triggers,ExecStart', unit],
        stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
        text=True, check=False, timeout=10,
    )
    if result.returncode != 0:
        fail('systemd_state_unknown')
    values = {}
    for line in result.stdout.splitlines():
        if '=' in line:
            key, value = line.split('=', 1)
            values[key] = value
    if values.get('Id') != unit or values.get('LoadState') != 'loaded':
        fail('systemd_identity_unknown')
    _assert_cleanup_unit_binding(values, unit, resource, run_id)
    if unit.endswith('.timer'):
        if values.get('ActiveState') != 'active' or values.get('SubState') != 'waiting':
            fail('systemd_not_armed')
        if not require_remaining:
            return True
        # An active/waiting transient timer may nevertheless be almost due.
        # Use systemd's monotonic deadline so wall-clock changes cannot extend
        # the lease. Missing, non-numeric, zero, or already-expired values are
        # deliberately unknown and fail closed.
        deadline_us = _raw_timer_deadline_monotonic_us(unit)
        now_us = time.monotonic_ns() // 1_000
        minimum_us = MIN_CLEANUP_REMAINING_SECONDS * 1_000_000
        if deadline_us <= now_us or deadline_us - now_us < minimum_us:
            fail('cleanup_deadline_insufficient')
    return True


def _private_lock_identity(metadata, allow_legacy=False):
    mode = stat.S_IMODE(metadata.st_mode)
    if (not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != 0 or
            metadata.st_gid != 0 or metadata.st_nlink != 1 or
            metadata.st_size != 0 or
            mode not in ((0o600, 0o644) if allow_legacy else (0o600,))):
        return None
    return (metadata.st_dev, metadata.st_ino, metadata.st_mode,
            metadata.st_uid, metadata.st_gid, metadata.st_nlink,
            metadata.st_size)


def _state_dir_identity(metadata):
    if (not stat.S_ISDIR(metadata.st_mode) or metadata.st_uid != 0 or
            metadata.st_gid != 0 or metadata.st_nlink < 2 or
            stat.S_IMODE(metadata.st_mode) != 0o700):
        return None
    return (metadata.st_dev, metadata.st_ino, metadata.st_mode,
            metadata.st_uid, metadata.st_gid, metadata.st_nlink)


def _ack_file_identity(metadata):
    if (not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != 0 or
            metadata.st_gid != 0 or metadata.st_nlink != 1 or
            metadata.st_size > MAX_ARM_ACK_BYTES or
            stat.S_IMODE(metadata.st_mode) != 0o600):
        return None
    return (metadata.st_dev, metadata.st_ino, metadata.st_mode,
            metadata.st_uid, metadata.st_gid, metadata.st_nlink,
            metadata.st_size)


def _ack_path(resource):
    return Path(resource['state_file']).parent / ARM_ACK_LEAF


def _ack_temp_prefix():
    return f'.{ARM_ACK_LEAF}.'


def _ensure_arm_ack_absent(resource):
    directory = Path(resource['state_file']).parent
    try:
        directory_metadata = os.stat(directory, follow_symlinks=False)
    except FileNotFoundError:
        if resource.get('lifecycle') != 'install':
            fail('arm_ack_directory_unsafe')
        # The install wrapper creates and validates this directory only after
        # pending registration.  Keep this preflight read-only.
        return
    except OSError:
        fail('arm_ack_directory_unsafe')
    if _state_dir_identity(directory_metadata) is None:
        fail('arm_ack_directory_unsafe')
    path = _ack_path(resource)
    try:
        os.stat(path, follow_symlinks=False)
    except FileNotFoundError:
        pass
    except OSError:
        fail('arm_ack_unsafe')
    else:
        fail('arm_ack_stale')
    _ensure_arm_ack_temporaries_absent(resource)


def _ensure_arm_ack_temporaries_absent(resource):
    directory, _identity = _require_state_directory(resource)
    try:
        interrupted = [entry for entry in directory.iterdir()
                       if entry.name.startswith(_ack_temp_prefix()) and entry.name.endswith('.tmp')]
    except OSError:
        fail('arm_ack_directory_unsafe')
    if interrupted:
        fail('arm_ack_interrupted')


def _require_state_directory(resource):
    directory = Path(resource['state_file']).parent
    try:
        metadata = os.stat(directory, follow_symlinks=False)
    except OSError:
        fail('arm_ack_directory_unsafe')
    identity = _state_dir_identity(metadata)
    if identity is None:
        fail('arm_ack_directory_unsafe')
    return directory, identity


def _ack_record(record):
    return {
        'schema': 'provider_ui_smoke_arm_ack_v1',
        'run_id': record['run_id'],
        'boot_id': record['boot_id'],
        'operation': record['operation'],
        'resource_identity': record['resource_identity'],
    }


def _read_arm_ack(resource):
    directory, directory_identity = _require_state_directory(resource)
    path = _ack_path(resource)
    try:
        metadata = os.stat(path, follow_symlinks=False)
    except FileNotFoundError:
        return None
    except OSError:
        fail('arm_ack_unsafe')
    identity = _ack_file_identity(metadata)
    if identity is None:
        fail('arm_ack_unsafe')
    try:
        fd = os.open(path, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK)
        with os.fdopen(fd, 'r', encoding='utf-8') as handle:
            value = json.load(handle)
    except (OSError, ValueError, UnicodeError):
        fail('arm_ack_unknown')
    try:
        path_after = os.stat(path, follow_symlinks=False)
        directory_after = os.stat(directory, follow_symlinks=False)
    except OSError:
        fail('arm_ack_unsafe')
    if (_ack_file_identity(path_after) != identity or
            _state_dir_identity(directory_after) != directory_identity):
        fail('arm_ack_unsafe')
    if not isinstance(value, dict):
        fail('arm_ack_unknown')
    return value


def _read_interrupted_arm_ack_pair(resource, record):
    """Read the exact hardlink pair left by a crash during ACK publication.

    The normal reader deliberately rejects a two-link ACK.  Recovery may
    inspect that state only when the final and run-specific temporary names
    are the same root-owned inode, contain identical bytes, and decode to the
    exact pending record.  Any extra link or disagreement remains unknown.
    """
    directory, directory_identity = _require_state_directory(resource)
    final_path = _ack_path(resource)
    temporary = directory / f'.{ARM_ACK_LEAF}.{record["run_id"]}.tmp'
    try:
        final_metadata = os.stat(final_path, follow_symlinks=False)
        temporary_metadata = os.stat(temporary, follow_symlinks=False)
    except (FileNotFoundError, OSError):
        fail('arm_ack_interrupted_unknown')
    pair_metadata_valid = lambda metadata: (
        stat.S_ISREG(metadata.st_mode) and metadata.st_uid == 0 and
        metadata.st_gid == 0 and metadata.st_nlink == 2 and
        metadata.st_size <= MAX_ARM_ACK_BYTES and
        stat.S_IMODE(metadata.st_mode) == 0o600)
    if (not pair_metadata_valid(final_metadata) or
            not pair_metadata_valid(temporary_metadata) or
            final_metadata.st_dev != temporary_metadata.st_dev or
            final_metadata.st_ino != temporary_metadata.st_ino or
            final_metadata.st_size != temporary_metadata.st_size):
        fail('arm_ack_interrupted_unknown')
    try:
        with os.fdopen(os.open(final_path, os.O_RDONLY | os.O_CLOEXEC |
                               os.O_NOFOLLOW | os.O_NONBLOCK), 'rb') as handle:
            final_bytes = handle.read(MAX_ARM_ACK_BYTES + 1)
        with os.fdopen(os.open(temporary, os.O_RDONLY | os.O_CLOEXEC |
                               os.O_NOFOLLOW | os.O_NONBLOCK), 'rb') as handle:
            temporary_bytes = handle.read(MAX_ARM_ACK_BYTES + 1)
        final_after = os.stat(final_path, follow_symlinks=False)
        temporary_after = os.stat(temporary, follow_symlinks=False)
        directory_after = os.stat(directory, follow_symlinks=False)
    except (OSError, ValueError):
        fail('arm_ack_interrupted_unknown')
    if (final_bytes != temporary_bytes or len(final_bytes) > MAX_ARM_ACK_BYTES or
            _state_dir_identity(directory_after) != directory_identity or
            final_after.st_dev != temporary_after.st_dev or
            final_after.st_ino != temporary_after.st_ino or
            final_after.st_nlink != 2 or temporary_after.st_nlink != 2):
        fail('arm_ack_interrupted_unknown')
    try:
        value = json.loads(final_bytes.decode('utf-8'))
    except (ValueError, UnicodeError):
        fail('arm_ack_interrupted_unknown')
    if value != _ack_record(record):
        fail('arm_ack_interrupted_unknown')
    return temporary


def _read_interrupted_arm_ack_temp(resource, record):
    """Read the sole run-bound temp left before ACK hardlink publication."""
    directory, directory_identity = _require_state_directory(resource)
    final_path = _ack_path(resource)
    temporary = directory / f'.{ARM_ACK_LEAF}.{record["run_id"]}.tmp'
    try:
        os.stat(final_path, follow_symlinks=False)
    except FileNotFoundError:
        pass
    except OSError:
        fail('arm_ack_interrupted_unknown')
    else:
        fail('arm_ack_interrupted_unknown')
    try:
        temporary_names = [entry for entry in directory.iterdir()
                           if entry.name.startswith(_ack_temp_prefix()) and
                           entry.name.endswith('.tmp')]
    except OSError:
        fail('arm_ack_interrupted_unknown')
    if temporary_names != [temporary]:
        fail('arm_ack_interrupted_unknown')
    try:
        metadata = os.stat(temporary, follow_symlinks=False)
    except OSError:
        fail('arm_ack_interrupted_unknown')
    identity = _ack_file_identity(metadata)
    if identity is None:
        fail('arm_ack_interrupted_unknown')
    try:
        fd = os.open(temporary, os.O_RDONLY | os.O_CLOEXEC |
                     os.O_NOFOLLOW | os.O_NONBLOCK)
        with os.fdopen(fd, 'rb') as handle:
            payload = handle.read(MAX_ARM_ACK_BYTES + 1)
        after = os.stat(temporary, follow_symlinks=False)
        directory_after = os.stat(directory, follow_symlinks=False)
    except (OSError, ValueError):
        fail('arm_ack_interrupted_unknown')
    if (len(payload) > MAX_ARM_ACK_BYTES or _ack_file_identity(after) != identity or
            _state_dir_identity(directory_after) != directory_identity):
        fail('arm_ack_interrupted_unknown')
    try:
        value = json.loads(payload.decode('utf-8'))
    except (ValueError, UnicodeError):
        fail('arm_ack_interrupted_unknown')
    if value != _ack_record(record):
        fail('arm_ack_interrupted_unknown')
    return temporary


def _write_arm_ack(resource, record):
    directory, directory_identity = _require_state_directory(resource)
    path = _ack_path(resource)
    try:
        existing = os.stat(path, follow_symlinks=False)
    except FileNotFoundError:
        existing = None
    except OSError:
        fail('arm_ack_unsafe')
    if existing is not None:
        if _ack_file_identity(existing) is None:
            fail('arm_ack_unsafe')
        fail('arm_ack_stale')
    temporary = directory / f'.{ARM_ACK_LEAF}.{record["run_id"]}.tmp'
    if temporary.exists() or temporary.is_symlink():
        fail('arm_ack_interrupted')
    payload = (json.dumps(_ack_record(record), sort_keys=True, separators=(',', ':')) + '\n').encode()
    fd = None
    try:
        fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL |
                     os.O_CLOEXEC | os.O_NOFOLLOW, 0o600)
        written = 0
        while written < len(payload):
            written += os.write(fd, payload[written:])
        os.fsync(fd)
        os.close(fd)
        fd = None
        try:
            # link() creates the final name only if it is absent; unlike
            # replace(), a target racing in after the preflight cannot be
            # clobbered.
            os.link(temporary, path, follow_symlinks=False)
        except FileExistsError:
            fail('arm_ack_stale')
        os.unlink(temporary)
        directory_fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW)
        try:
            os.fsync(directory_fd)
        finally:
            os.close(directory_fd)
    except OSError:
        fail('arm_ack_write_failed')
    finally:
        if fd is not None:
            os.close(fd)
        try:
            temporary.unlink()
        except FileNotFoundError:
            pass
    try:
        directory_after = os.stat(directory, follow_symlinks=False)
    except OSError:
        fail('arm_ack_directory_unsafe')
    if _state_dir_identity(directory_after) != directory_identity:
        fail('arm_ack_directory_unsafe')


def _remove_arm_ack(resource):
    directory, directory_identity = _require_state_directory(resource)
    path = _ack_path(resource)
    try:
        metadata = os.stat(path, follow_symlinks=False)
    except FileNotFoundError:
        return
    except OSError:
        fail('arm_ack_unsafe')
    if _ack_file_identity(metadata) is None:
        fail('arm_ack_unsafe')
    try:
        os.unlink(path)
    except OSError:
        fail('arm_ack_cleanup_failed')
    try:
        directory_fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW)
        try:
            os.fsync(directory_fd)
        finally:
            os.close(directory_fd)
        directory_after = os.stat(directory, follow_symlinks=False)
    except OSError:
        fail('arm_ack_cleanup_failed')
    if _state_dir_identity(directory_after) != directory_identity:
        fail('arm_ack_directory_unsafe')


def ack_arm(args: argparse.Namespace) -> None:
    core = load_core()
    resource = resource_from(args)
    try:
        with private_cleanup_lock():
            with core.maintenance_recovery() as admission:
                record = expected_record(core, args.run_id, args.operation, resource)
                if args.operation != 'smoke':
                    fail('arm_ack_operation_invalid', 64)
                unit_armed(args.cleanup_unit + '.timer', resource, args.run_id)
                unit_armed(args.cleanup_unit + '.service', resource, args.run_id)
                _write_arm_ack(resource, record)
    except core.PendingError as error:
        fail(error.reason, error.code)
    print('provider_ui_smoke_admission=result=arm-acknowledged')


@contextlib.contextmanager
def private_cleanup_lock():
    """Hold the exact production cleanup lock across proof and clear.

    The private lock is acquired before the shared maintenance lock.  This is
    the same order used by the lifecycle owner and avoids a private/shared
    lock inversion while systemd units are being armed or disarmed.
    """
    fd = None
    try:
        try:
            path_before = os.stat(PRIVATE_CLEANUP_LOCK_PATH, follow_symlinks=False)
            open_flags = os.O_RDWR | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK
        except FileNotFoundError:
            # The lifecycle owner creates this fixed lock with shell redirection
            # before flock.  Create it only at the exact fixed path and let the
            # identity checks below reject any unsafe result.
            open_flags = (os.O_RDWR | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC |
                          os.O_NOFOLLOW | os.O_NONBLOCK)
            try:
                fd = os.open(PRIVATE_CLEANUP_LOCK_PATH, open_flags, 0o600)
            except FileExistsError:
                fd = None
            if fd is not None:
                path_before = os.stat(PRIVATE_CLEANUP_LOCK_PATH, follow_symlinks=False)
            else:
                path_before = os.stat(PRIVATE_CLEANUP_LOCK_PATH, follow_symlinks=False)
                open_flags = os.O_RDWR | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK
        expected = _private_lock_identity(path_before, allow_legacy=True)
        if expected is None:
            fail('private_lock_unsafe')
        if fd is None:
            fd = os.open(PRIVATE_CLEANUP_LOCK_PATH, open_flags)
        opened = os.fstat(fd)
        if _private_lock_identity(opened, allow_legacy=True) != expected:
            fail('private_lock_unsafe')
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except (BlockingIOError, OSError) as error:
            if getattr(error, 'errno', None) in (errno.EAGAIN, errno.EWOULDBLOCK, errno.EACCES):
                fail('private_lock_busy', 75)
            raise
        path_after = os.stat(PRIVATE_CLEANUP_LOCK_PATH, follow_symlinks=False)
        held = os.fstat(fd)
        if (_private_lock_identity(path_after, allow_legacy=True) != expected or
                _private_lock_identity(held, allow_legacy=True) != expected):
            fail('private_lock_unsafe')
        if stat.S_IMODE(held.st_mode) == 0o644:
            # The previous root operator created this empty lock with a 022
            # umask. Narrow it only after owning that exact locked inode.
            os.fchmod(fd, 0o600)
            expected = _private_lock_identity(os.fstat(fd))
            if expected is None:
                fail('private_lock_unsafe')
        path_after = os.stat(PRIVATE_CLEANUP_LOCK_PATH, follow_symlinks=False)
        held = os.fstat(fd)
        if (_private_lock_identity(path_after) != expected or
                _private_lock_identity(held) != expected):
            fail('private_lock_unsafe')
        yield
    except SystemExit:
        raise
    except OSError:
        fail('private_lock_unsafe')
    finally:
        if fd is not None:
            try:
                fcntl.flock(fd, fcntl.LOCK_UN)
            finally:
                os.close(fd)


def fixture_terminal_state(resource: dict[str, str]) -> str:
    state = Path(resource['state_file'])
    if state.exists() or state.is_symlink():
        fail('fixture_state_present')
    app = Path(resource['app_root'])
    if not (app.is_dir() and (app / 'index.php').is_file()):
        fail('app_root_unavailable', 2)
    command = [
        '/usr/bin/php', 'index.php', 'console', 'provider_ui_smoke', 'verify',
        resource['credentials_file'], resource['state_file'],
    ]
    result = subprocess.run(
        command, cwd=resource['app_root'], stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, check=False,
        timeout=45,
    )
    if result.returncode != 0 or result.stderr:
        fail('fixture_terminal_unknown')
    for state_name in ('dormant', 'removed'):
        expected = f'provider_ui_smoke action=verify state={state_name} result=ok\n'
        if result.stdout == expected:
            return state_name
    fail('fixture_terminal_unknown')


def dormant_clean(resource: dict[str, str], expected_state: str) -> bool:
    if fixture_terminal_state(resource) != expected_state:
        fail('fixture_terminal_unknown')
    return True


def _ensure_credential_temp_absent(resource: dict[str, str]) -> None:
    directory = Path(resource['credentials_file']).parent
    try:
        metadata = os.stat(directory, follow_symlinks=False)
    except FileNotFoundError:
        return
    except OSError:
        fail('credential_directory_unsafe')
    if (not stat.S_ISDIR(metadata.st_mode) or metadata.st_uid != 0 or
            metadata.st_gid != 0 or stat.S_IMODE(metadata.st_mode) & 0o022):
        fail('credential_directory_unsafe')
    try:
        if any(entry.name.startswith('.provider-ui-smoke.env.') for entry in directory.iterdir()):
            fail('credential_temp_present')
    except OSError:
        fail('credential_directory_unsafe')


def settle(args: argparse.Namespace) -> None:
    core = load_core()
    resource = resource_from(args)
    try:
        with private_cleanup_lock():
            with core.maintenance_recovery() as admission:
                record = expected_record(core, args.run_id, args.operation, resource,
                                         allow_prior_boot=True)
                expected_state = 'removed' if args.operation == 'remove' else 'dormant'
                if args.operation == 'smoke':
                    arm_ack = _read_arm_ack(resource)
                    if arm_ack is None:
                        fail('arm_ack_missing')
                    if arm_ack != _ack_record(record):
                        fail('arm_ack_binding_changed')

                def terminal_proof(current):
                    if current != record:
                        return False
                    try:
                        if args.operation == 'smoke':
                            unit_terminal(args.cleanup_unit + '.timer', resource, args.run_id)
                            unit_terminal(args.cleanup_unit + '.service', resource, args.run_id)
                        _ensure_credential_temp_absent(resource)
                        dormant_clean(resource, expected_state)
                        return True
                    except BaseException:
                        return False

                # Perform the proof once before marker publication and again
                # from the recovery callback while both locks remain held.
                if not terminal_proof(record):
                    fail('terminal_proof_unknown')
                result = admission.recover_pending(record, terminal_proof)
                if result.get('status') != 'recovered':
                    fail('pending_recovery_unknown')
                if args.operation == 'smoke':
                    _remove_arm_ack(resource)
    except core.PendingError as error:
        fail(error.reason, error.code)
    print('provider_ui_smoke_admission=result=settled')


def recover_unarmed(args: argparse.Namespace) -> None:
    """Explicitly retire a bound, unacknowledged smoke after terminal proof.

    An interrupted arm response is never treated as success. The private
    lifecycle lock fences an in-flight arm while the shared maintenance lock
    holds the pending veto through both terminal proofs and its durable clear.
    """
    if args.operation != 'smoke':
        fail('recover_unarmed_operation_invalid', 64)
    core = load_core()
    resource = resource_from(args)
    try:
        with private_cleanup_lock():
            with core.maintenance_recovery() as admission:
                record = expected_record(core, args.run_id, args.operation, resource,
                                         allow_prior_boot=True)
                # Absence of the final sidecar alone is insufficient: a
                # crash during no-clobber publication can leave a temporary
                # acknowledgement that must remain a recovery veto.
                _ensure_arm_ack_absent(resource)

                def terminal_proof(current):
                    if current != record:
                        return False
                    try:
                        unit_terminal(args.cleanup_unit + '.timer', resource, args.run_id)
                        unit_terminal(args.cleanup_unit + '.service', resource, args.run_id)
                        dormant_clean(resource, 'dormant')
                        return True
                    except BaseException:
                        return False

                if not terminal_proof(record):
                    fail('terminal_proof_unknown')
                result = admission.recover_pending(record, terminal_proof)
                if result.get('status') != 'recovered':
                    fail('pending_recovery_unknown')
    except core.PendingError as error:
        fail(error.reason, error.code)
    print('provider_ui_smoke_admission=result=unarmed-recovered')


def recover_install_empty(args: argparse.Namespace) -> None:
    """Abort only an exact install run with a verified terminal fixture."""
    if args.operation != 'install':
        fail('recover_install_operation_invalid', 64)
    core = load_core()
    resource = resource_from(args)
    try:
        with private_cleanup_lock():
            with core.maintenance_recovery() as admission:
                record = expected_record(core, args.run_id, args.operation, resource,
                                         allow_prior_boot=True)

                def terminal_proof(current):
                    if current != record:
                        return False
                    try:
                        _ensure_arm_ack_absent(resource)
                        _ensure_credential_temp_absent(resource)
                        if fixture_terminal_state(resource) not in {'dormant', 'removed'}:
                            fail('fixture_terminal_unknown')
                        return True
                    except BaseException:
                        return False

                if not terminal_proof(record):
                    fail('terminal_proof_unknown')
                result = admission.recover_pending(record, terminal_proof)
                if result.get('status') != 'recovered':
                    fail('pending_recovery_unknown')
    except core.PendingError as error:
        fail(error.reason, error.code)
    print('provider_ui_smoke_admission=result=install-empty-recovered')


def recover_remove_dormant(args: argparse.Namespace) -> None:
    """Retire an exact remove run that failed before the fixture transaction.

    ``remove`` publishes its pending veto before entering the application
    transaction.  If that transaction never starts (or fails before changing
    state), the fixture is still dormant and ordinary settlement cannot be
    used because it deliberately requires ``removed``.  This recovery only
    clears the exact persisted remove record after proving that the fixture,
    credentials, acknowledgement sidecars, and any cleanup units are in the
    expected pre-run state.  It never removes or repairs fixture state.
    """
    if args.operation != 'remove':
        fail('recover_remove_operation_invalid', 64)
    core = load_core()
    resource = resource_from(args)
    try:
        with private_cleanup_lock():
            with core.maintenance_recovery() as admission:
                record = expected_record(core, args.run_id, args.operation, resource,
                                         allow_prior_boot=True)

                def terminal_proof(current):
                    if current != record:
                        return False
                    try:
                        # A remove run never arms these units.  Proving both
                        # terminal/absent states prevents a stale smoke timer
                        # or service from racing the dormant verification.
                        _ensure_arm_ack_absent(resource)
                        _ensure_credential_temp_absent(resource)
                        unit_terminal(args.cleanup_unit + '.timer', resource, args.run_id)
                        unit_terminal(args.cleanup_unit + '.service', resource, args.run_id)
                        dormant_clean(resource, 'dormant')
                        return True
                    except BaseException:
                        return False

                if not terminal_proof(record):
                    fail('terminal_proof_unknown')
                result = admission.recover_pending(record, terminal_proof)
                if result.get('status') != 'recovered':
                    fail('pending_recovery_unknown')
    except core.PendingError as error:
        fail(error.reason, error.code)
    print('provider_ui_smoke_admission=result=remove-dormant-recovered')


def recover_interrupted_pending_temp(args: argparse.Namespace) -> None:
    """Recover a torn core pending publication, without automatic admission.

    The core derives the run ID from the validated temp record.  This matters
    when ``begin`` stopped before returning its generated ID.  The operation
    proof is deliberately the same non-start/terminal proof used by the
    corresponding lifecycle and remains fail-closed for missing credentials.
    """
    core = load_core()
    resource = resource_from(args)
    if args.operation not in {'install', 'remove', 'smoke'}:
        fail('pending_temp_operation_invalid', 64)
    allowed_states = {
        # The begin step for smoke starts only from dormant, while install and
        # remove are explicitly idempotent and may have either verified end
        # state before their transaction starts.
        'smoke': {'dormant'},
        'install': {'dormant', 'removed'},
        'remove': {'dormant', 'removed'},
    }[args.operation]
    try:
        with private_cleanup_lock():
            with core.maintenance_recovery() as admission:
                def terminal_proof(record):
                    if (record.get('operation') != OPERATION or
                            record.get('resource_identity') != resource):
                        return False
                    try:
                        _ensure_arm_ack_absent(resource)
                        _ensure_credential_temp_absent(resource)
                        if args.operation in {'smoke', 'remove'}:
                            unit_terminal(args.cleanup_unit + '.timer', resource,
                                          record['run_id'])
                            unit_terminal(args.cleanup_unit + '.service', resource,
                                          record['run_id'])
                        if fixture_terminal_state(resource) not in allowed_states:
                            fail('fixture_terminal_unknown')
                        return True
                    except BaseException:
                        return False

                result = admission.recover_interrupted_pending_temp(
                    OPERATION, resource, terminal_proof)
                if result.get('status') != 'recovered':
                    fail('pending_temp_recovery_unknown')
    except core.PendingError as error:
        fail(error.reason, error.code)
    print('provider_ui_smoke_admission=result=pending-temp-recovered')


def recover_interrupted_pending(args: argparse.Namespace) -> None:
    """Recover a bound pending/marker state after its temp name is gone."""
    core = load_core()
    resource = resource_from(args)
    if args.operation not in {'install', 'remove', 'smoke'}:
        fail('pending_recovery_operation_invalid', 64)
    allowed_states = {
        'smoke': {'dormant'},
        'install': {'dormant', 'removed'},
        'remove': {'dormant', 'removed'},
    }[args.operation]
    try:
        with private_cleanup_lock():
            with core.maintenance_recovery() as admission:
                def terminal_proof(record):
                    if (record.get('operation') != OPERATION or
                            record.get('resource_identity') != resource):
                        return False
                    try:
                        _ensure_arm_ack_absent(resource)
                        _ensure_credential_temp_absent(resource)
                        if args.operation in {'smoke', 'remove'}:
                            unit_terminal(args.cleanup_unit + '.timer', resource,
                                          record['run_id'])
                            unit_terminal(args.cleanup_unit + '.service', resource,
                                          record['run_id'])
                        if fixture_terminal_state(resource) not in allowed_states:
                            fail('fixture_terminal_unknown')
                        return True
                    except BaseException:
                        return False

                result = admission.recover_interrupted_pending(
                    OPERATION, resource, terminal_proof)
                if result.get('status') != 'recovered':
                    fail('pending_recovery_unknown')
    except core.PendingError as error:
        fail(error.reason, error.code)
    print('provider_ui_smoke_admission=result=pending-recovered')


def recover_clear_marker(args: argparse.Namespace) -> None:
    """Finish only the exact terminal clear interrupted after marker creation."""
    core = load_core()
    resource = resource_from(args)
    expected_state = 'removed' if args.operation == 'remove' else 'dormant'
    try:
        with private_cleanup_lock():
            with core.maintenance_recovery() as admission:
                def terminal_proof(marker):
                    if (not isinstance(marker, dict) or
                            marker.get('schema') != core.SCHEMA or
                            marker.get('epoch') != core.PROTOCOL_EPOCH or
                            marker.get('operation') != OPERATION or
                            marker.get('run_id') != args.run_id or
                            marker.get('resource_identity') != resource):
                        return False
                    try:
                        _ensure_credential_temp_absent(resource)
                        if args.operation == 'smoke':
                            arm_ack = _read_arm_ack(resource)
                            if arm_ack is None:
                                _ensure_arm_ack_absent(resource)
                            else:
                                if arm_ack != _ack_record(marker):
                                    return False
                                _ensure_arm_ack_temporaries_absent(resource)
                            unit_terminal(args.cleanup_unit + '.timer', resource, args.run_id)
                            unit_terminal(args.cleanup_unit + '.service', resource, args.run_id)
                        else:
                            _ensure_arm_ack_absent(resource)
                        if args.operation == 'install':
                            # A marker may follow either successful install
                            # settlement (dormant) or the explicit empty
                            # install abort (removed). Verify once and accept
                            # only those two exact terminal states.
                            fixture_terminal_state(resource)
                        else:
                            dormant_clean(resource, expected_state)
                        return True
                    except BaseException:
                        return False

                result = admission.recover_clear_marker(terminal_proof)
                if result.get('status') != 'recovered':
                    fail('clear_marker_recovery_unknown')
                if args.operation == 'smoke' and _read_arm_ack(resource) is not None:
                    _remove_arm_ack(resource)
    except core.PendingError as error:
        fail(error.reason, error.code)
    print('provider_ui_smoke_admission=result=clear-marker-recovered')


def recover_arm_ack(args: argparse.Namespace) -> None:
    """Retire an orphan acknowledgement only after pending was durably cleared.

    A crash between the core's terminal clear and sidecar removal must not
    block later Provider UI runs forever or cause the next run to overwrite
    unexamined evidence. This separate recovery repeats the terminal proof.
    """
    if args.operation != 'smoke':
        fail('arm_ack_operation_invalid', 64)
    core = load_core()
    resource = resource_from(args)
    try:
        with private_cleanup_lock():
            with core.maintenance_recovery():
                if core.read_pending(core.current_boot_id()) is not None:
                    fail('pending_still_present')
                arm_ack = _read_arm_ack(resource)
                if arm_ack is None:
                    fail('arm_ack_missing')
                acknowledged_boot = arm_ack.get('boot_id')
                if (not isinstance(acknowledged_boot, str) or
                        not acknowledged_boot or len(acknowledged_boot) > 128):
                    fail('arm_ack_binding_changed')
                expected = _ack_record({
                    'run_id': args.run_id,
                    'boot_id': acknowledged_boot,
                    'operation': OPERATION,
                    'resource_identity': resource,
                })
                if arm_ack != expected:
                    fail('arm_ack_binding_changed')
                unit_terminal(args.cleanup_unit + '.timer', resource, args.run_id)
                unit_terminal(args.cleanup_unit + '.service', resource, args.run_id)
                dormant_clean(resource, 'dormant')
                _remove_arm_ack(resource)
    except core.PendingError as error:
        fail(error.reason, error.code)
    print('provider_ui_smoke_admission=result=arm-ack-recovered')


def recover_interrupted_arm_ack(args: argparse.Namespace) -> None:
    """Recover only a crash after ACK hardlink publication.

    The final and temporary names must first prove to be the exact two-link
    ACK for the pending run.  Terminal timer/service and dormant-fixture
    proofs are required before unlinking the temporary name.  The remaining
    final ACK is then settled through the normal pending recovery capability;
    if that last step is interrupted, ``recover-arm-ack`` can retire it.
    """
    if args.operation != 'smoke':
        fail('arm_ack_operation_invalid', 64)
    core = load_core()
    resource = resource_from(args)
    try:
        with private_cleanup_lock():
            with core.maintenance_recovery() as admission:
                record = expected_record(core, args.run_id, args.operation, resource,
                                         allow_prior_boot=True)
                temporary = _read_interrupted_arm_ack_pair(resource, record)

                def terminal_proof(current):
                    if current != record:
                        return False
                    try:
                        _ensure_credential_temp_absent(resource)
                        unit_terminal(args.cleanup_unit + '.timer', resource, args.run_id)
                        unit_terminal(args.cleanup_unit + '.service', resource, args.run_id)
                        dormant_clean(resource, 'dormant')
                        return True
                    except BaseException:
                        return False

                if not terminal_proof(record):
                    fail('terminal_proof_unknown')
                # Only the exact pair's temporary name may be removed.  The
                # final name remains available for the ordinary ACK reader.
                os.unlink(temporary)
                directory_fd = os.open(Path(resource['state_file']).parent,
                                       os.O_RDONLY | os.O_DIRECTORY |
                                       os.O_CLOEXEC | os.O_NOFOLLOW)
                try:
                    os.fsync(directory_fd)
                finally:
                    os.close(directory_fd)

                def settled_proof(current):
                    if current != record:
                        return False
                    try:
                        if _read_arm_ack(resource) != _ack_record(record):
                            return False
                        return terminal_proof(current)
                    except BaseException:
                        return False

                result = admission.recover_pending(record, settled_proof)
                if result.get('status') != 'recovered':
                    fail('pending_recovery_unknown')
                _remove_arm_ack(resource)
    except core.PendingError as error:
        fail(error.reason, error.code)
    except OSError:
        fail('arm_ack_cleanup_failed')
    print('provider_ui_smoke_admission=result=arm-ack-pair-recovered')


def recover_interrupted_arm_ack_temp(args: argparse.Namespace) -> None:
    """Recover a crash after temp ACK fsync and before final link creation."""
    if args.operation != 'smoke':
        fail('arm_ack_operation_invalid', 64)
    core = load_core()
    resource = resource_from(args)
    try:
        with private_cleanup_lock():
            with core.maintenance_recovery() as admission:
                record = expected_record(core, args.run_id, args.operation, resource,
                                         allow_prior_boot=True)
                temporary = _read_interrupted_arm_ack_temp(resource, record)

                def terminal_before_unlink(current):
                    if current != record:
                        return False
                    try:
                        _ensure_credential_temp_absent(resource)
                        unit_terminal(args.cleanup_unit + '.timer', resource, args.run_id)
                        unit_terminal(args.cleanup_unit + '.service', resource, args.run_id)
                        dormant_clean(resource, 'dormant')
                        return True
                    except BaseException:
                        return False

                if not terminal_before_unlink(record):
                    fail('terminal_proof_unknown')
                # Re-read immediately before unlinking so the exact temp and
                # the absence of the final name remain bound to this proof.
                if _read_interrupted_arm_ack_temp(resource, record) != temporary:
                    fail('arm_ack_interrupted_unknown')
                os.unlink(temporary)
                directory_fd = os.open(Path(resource['state_file']).parent,
                                       os.O_RDONLY | os.O_DIRECTORY |
                                       os.O_CLOEXEC | os.O_NOFOLLOW)
                try:
                    os.fsync(directory_fd)
                finally:
                    os.close(directory_fd)

                def terminal_after_unlink(current):
                    if current != record:
                        return False
                    try:
                        _ensure_arm_ack_absent(resource)
                        return terminal_before_unlink(current)
                    except BaseException:
                        return False

                result = admission.recover_pending(record, terminal_after_unlink)
                if result.get('status') != 'recovered':
                    fail('pending_recovery_unknown')
    except core.PendingError as error:
        fail(error.reason, error.code)
    except OSError:
        fail('arm_ack_cleanup_failed')
    print('provider_ui_smoke_admission=result=arm-ack-temp-recovered')


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser()
    parser.add_argument('action', choices=('begin', 'ack-arm', 'assert-bound-fd', 'settle', 'recover-arm-ack', 'recover-interrupted-arm-ack', 'recover-interrupted-arm-ack-temp', 'recover-interrupted-pending-temp', 'recover-interrupted-pending', 'recover-unarmed', 'recover-install-empty', 'recover-remove-dormant', 'recover-clear-marker'))
    parser.add_argument('--operation', choices=sorted(ACTION_NAMES), required=True)
    parser.add_argument('--run-id')
    parser.add_argument('--lock-fd', type=int)
    parser.add_argument('--lifecycle-action', choices=('install', 'activate', 'deactivate', 'remove'))
    parser.add_argument('--app-root', required=True)
    parser.add_argument('--credentials-file', required=True)
    parser.add_argument('--state-file', required=True)
    parser.add_argument('--cleanup-unit', required=True)
    return parser


def main() -> None:
    args = build_parser().parse_args()
    if (args.action == 'assert-bound-fd') != (args.lock_fd is not None):
        fail('lock_fd_scope_invalid', 64)
    if (args.action == 'assert-bound-fd') != (args.lifecycle_action is not None):
        fail('lifecycle_action_scope_invalid', 64)
    if args.action == 'begin' and args.run_id:
        fail('run_id_not_allowed', 64)
    if args.action in {'recover-interrupted-pending-temp', 'recover-interrupted-pending'} and args.run_id:
        fail('run_id_not_allowed', 64)
    if args.action != 'begin' and args.action not in {'recover-interrupted-pending-temp', 'recover-interrupted-pending'} and (not args.run_id or len(args.run_id) != RUN_ID_BYTES * 2):
        fail('run_id_required', 64)
    if args.action == 'begin':
        begin(args)
    elif args.action == 'ack-arm':
        ack_arm(args)
    elif args.action == 'assert-bound-fd':
        assert_bound_fd(args)
    elif args.action == 'recover-arm-ack':
        recover_arm_ack(args)
    elif args.action == 'recover-interrupted-arm-ack':
        recover_interrupted_arm_ack(args)
    elif args.action == 'recover-interrupted-arm-ack-temp':
        recover_interrupted_arm_ack_temp(args)
    elif args.action == 'recover-interrupted-pending-temp':
        recover_interrupted_pending_temp(args)
    elif args.action == 'recover-interrupted-pending':
        recover_interrupted_pending(args)
    elif args.action == 'recover-unarmed':
        recover_unarmed(args)
    elif args.action == 'recover-install-empty':
        recover_install_empty(args)
    elif args.action == 'recover-remove-dormant':
        recover_remove_dormant(args)
    elif args.action == 'recover-clear-marker':
        recover_clear_marker(args)
    else:
        settle(args)


if __name__ == '__main__':
    main()
