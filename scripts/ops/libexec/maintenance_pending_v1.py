#!/usr/bin/python3
"""Fail-closed durable admission state for coordinated maintenance.

This module is deliberately only a local protocol core.  It does not dispatch
maintenance work and it does not infer completion from a process, PID, timeout,
or an empty lock.  Callers must provide the terminal proof used to clear a
record.
"""

import errno
import fcntl
import json
import os
import re
import stat
import tempfile
from contextlib import contextmanager
from datetime import datetime, timezone


SCHEMA = 'maintenance_pending.v1'
PROTOCOL_EPOCH = 'maintenance-admission.v1'
STATE_ROOT = '/var/lib/fh-maintenance-admission'
EPOCH_PATH = STATE_ROOT + '/epoch'
PENDING_PATH = STATE_ROOT + '/pending.json'
PENDING_TEMP_PREFIX = 'pending.json.tmp.'
CLEAR_MARKER_PATH = STATE_ROOT + '/clear-state.json'
CLEAR_MARKER_TEMP_PREFIX = 'clear-state.json.tmp.'
SHARED_LOCK_PATH = '/var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock'
MAX_RECORD_BYTES = 4096
MAX_RESOURCE_BYTES = 1024
TOKEN = re.compile(r'\A[a-z0-9][a-z0-9._-]{0,63}\Z')
BOOT = re.compile(r'\A[0-9a-f-]{1,128}\Z')


class PendingError(Exception):
    """A refusal class suitable for a bounded operator result."""

    def __init__(self, reason, code=75):
        super().__init__(reason)
        self.reason = reason
        self.code = code


def reject(reason, code=75):
    raise PendingError(reason, code)


def _identity(st):
    return (st.st_dev, st.st_ino, st.st_mode, st.st_uid, st.st_gid,
            st.st_nlink, st.st_size, st.st_mtime_ns, st.st_ctime_ns)


def _inode_binding(st):
    """Identity fields stable across hardlink creation (excluding nlink/ctime)."""
    return (st.st_dev, st.st_ino, st.st_mode, st.st_uid, st.st_gid,
            st.st_size, st.st_mtime_ns)


def _directory_identity(st):
    """Stable identity for a trusted directory whose entries may change."""
    return (st.st_dev, st.st_ino, st.st_mode, st.st_uid, st.st_gid)


def _exact_regular(path, mode, maximum=None):
    """Return an lstat identity for a root-owned, non-linked regular file."""
    try:
        st = os.lstat(path)
    except OSError:
        reject('state_missing')
    if (not stat.S_ISREG(st.st_mode) or st.st_uid != 0 or st.st_gid != 0 or
            stat.S_IMODE(st.st_mode) != mode or st.st_nlink != 1 or
            (maximum is not None and not 0 <= st.st_size <= maximum)):
        reject('state_identity_invalid')
    return st


def _trusted_directory(path, mode=None):
    try:
        st = os.lstat(path)
    except OSError:
        reject('state_ancestor_invalid')
    if (not stat.S_ISDIR(st.st_mode) or st.st_uid != 0 or st.st_gid != 0 or
            st.st_nlink < 1 or stat.S_IMODE(st.st_mode) & 0o022 or
            (mode is not None and stat.S_IMODE(st.st_mode) != mode)):
        reject('state_ancestor_invalid')
    return st


def validate_state_layout():
    """Validate the fixed, root-controlled state path without creating it."""
    if os.geteuid() != 0:
        reject('root_required', 77)
    # The state path is intentionally fixed.  Validate every ancestor rather
    # than trusting a path string supplied by a caller.
    _trusted_directory('/')
    _trusted_directory('/var')
    _trusted_directory('/var/lib')
    _trusted_directory(STATE_ROOT, 0o700)
    epoch = _exact_regular(EPOCH_PATH, 0o600, 128)
    return epoch


def _read_bounded(path, maximum, allowed_nlinks=(1,)):
    fd = os.open(path, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW)
    try:
        before = os.fstat(fd)
        if (not stat.S_ISREG(before.st_mode) or before.st_uid != 0 or
                before.st_gid != 0 or before.st_nlink not in allowed_nlinks or
                stat.S_IMODE(before.st_mode) != 0o600 or
                before.st_size > maximum):
            reject('state_identity_invalid')
        data = bytearray()
        while len(data) <= maximum:
            part = os.read(fd, min(4096, maximum + 1 - len(data)))
            if not part:
                break
            data.extend(part)
        after = os.fstat(fd)
        if len(data) > maximum or _identity(before) != _identity(after):
            reject('state_identity_changed')
        return bytes(data), before
    finally:
        os.close(fd)


def _canonical(value):
    try:
        raw = (json.dumps(value, sort_keys=True, separators=(',', ':'),
                          ensure_ascii=True, allow_nan=False) + '\n').encode('ascii')
    except (TypeError, ValueError, UnicodeError):
        reject('state_json_invalid')
    if len(raw) > MAX_RECORD_BYTES:
        reject('state_oversized')
    return raw


def _pairs(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('duplicate key')
        result[key] = value
    return result


def _decode(raw):
    if not raw.endswith(b'\n'):
        reject('state_noncanonical')
    try:
        value = json.loads(raw.decode('ascii'), object_pairs_hook=_pairs,
                           parse_constant=lambda _: (_ for _ in ()).throw(ValueError()))
    except (UnicodeDecodeError, ValueError, json.JSONDecodeError):
        reject('state_json_invalid')
    if not isinstance(value, dict) or _canonical(value) != raw:
        reject('state_noncanonical')
    return value


def _validate_epoch():
    raw, _ = _read_bounded(EPOCH_PATH, 128)
    if raw != (PROTOCOL_EPOCH + '\n').encode('ascii'):
        reject('epoch_unknown')


def validate_shared_lock():
    """Validate the existing shared lock; never create or replace it."""
    path = SHARED_LOCK_PATH
    st = _exact_regular(path, 0o600, 0)
    if st.st_size != 0:
        reject('shared_lock_identity_invalid')
    fd = os.open(path, os.O_RDWR | os.O_CLOEXEC | os.O_NOFOLLOW)
    try:
        opened = os.fstat(fd)
        if _identity(st) != _identity(opened):
            reject('shared_lock_identity_changed')
        return _identity(opened)
    finally:
        os.close(fd)


def _open_shared_lock():
    """Open and hold the pre-existing shared lock until the caller closes it."""
    path = SHARED_LOCK_PATH
    _trusted_directory('/var')
    _trusted_directory('/var/lib')
    parent = os.path.dirname(path)
    _trusted_directory(os.path.dirname(parent))
    _trusted_directory(parent, 0o700)
    before = _exact_regular(path, 0o600, 0)
    fd = os.open(path, os.O_RDWR | os.O_CLOEXEC | os.O_NOFOLLOW)
    try:
        opened = os.fstat(fd)
        if _identity(before) != _identity(opened):
            reject('shared_lock_identity_changed')
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError as exc:
            if exc.errno in (errno.EACCES, errno.EAGAIN):
                reject('shared_lock_busy')
            reject('shared_lock_failed')
        current = os.lstat(path)
        if _identity(opened) != _identity(current):
            reject('shared_lock_identity_changed')
        return fd, _identity(opened)
    except Exception:
        os.close(fd)
        raise


def _validate_existing_lock_fd(lock_fd):
    """Validate a caller-owned descriptor for the canonical shared lock.

    This intentionally does not open, duplicate, lock, unlock, or close the
    descriptor.  The caller must already hold the shared flock and retains
    ownership of its descriptor for the whole capability lifetime.
    """
    path = SHARED_LOCK_PATH
    try:
        flags = fcntl.fcntl(lock_fd, fcntl.F_GETFL)
        original = os.fstat(lock_fd)
    except (OSError, TypeError, ValueError):
        reject('shared_lock_fd_invalid')
    if (flags & os.O_ACCMODE) != os.O_RDWR:
        reject('shared_lock_fd_not_rw')
    if (not stat.S_ISREG(original.st_mode) or original.st_uid != 0 or
            original.st_gid != 0 or stat.S_IMODE(original.st_mode) != 0o600 or
            original.st_nlink != 1 or original.st_size != 0):
        reject('shared_lock_fd_identity_invalid')
    try:
        before = os.lstat(path)
    except OSError:
        reject('state_missing')
    if (not stat.S_ISREG(before.st_mode) or before.st_uid != 0 or
            before.st_gid != 0 or stat.S_IMODE(before.st_mode) != 0o600 or
            before.st_nlink != 1 or before.st_size != 0 or
            _identity(original) != _identity(before)):
        reject('shared_lock_identity_changed')
    return _identity(original)


def _exclusive_flock_present(identity):
    """Return whether Linux currently reports a write flock for identity."""
    device = f'{os.major(identity[0]):02x}:{os.minor(identity[0]):02x}'
    needle = f'{device}:{identity[1]}'
    try:
        with open('/proc/locks', 'rt', encoding='ascii') as handle:
            for line in handle:
                fields = line.split()
                if (len(fields) >= 6 and fields[1] == 'FLOCK' and
                        fields[3] == 'WRITE' and fields[5] == needle):
                    return True
    except (OSError, UnicodeError):
        reject('shared_lock_state_unknown')
    return False


def _verify_existing_flock(lock_fd, lock_identity):
    """Prove a caller-owned descriptor already holds the exclusive flock."""
    # A nonblocking flock on a duplicated descriptor confirms that the
    # descriptor still refers to the same open-file-description.  The
    # /proc/locks observation before that operation is essential: flocking an
    # otherwise unlocked descriptor would acquire a new lock and would not
    # prove that the caller entered with the required lock already held.
    if not _exclusive_flock_present(lock_identity):
        reject('shared_lock_fd_not_held')
    try:
        duplicate = os.dup(lock_fd)
        os.set_inheritable(duplicate, False)
    except (OSError, ValueError):
        reject('shared_lock_fd_invalid')
    try:
        duplicate_identity = os.fstat(duplicate)
        if _identity(duplicate_identity) != lock_identity:
            reject('shared_lock_identity_changed')
        try:
            fcntl.flock(duplicate, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError as exc:
            if exc.errno in (errno.EACCES, errno.EAGAIN):
                reject('shared_lock_busy')
            reject('shared_lock_failed')
        if not _exclusive_flock_present(lock_identity):
            reject('shared_lock_fd_not_held')
    finally:
        os.close(duplicate)


def admit_existing_lock_fd(lock_fd, expected_boot_id=None):
    """Admit through a trusted caller-owned canonical lock descriptor.

    The caller must pass the descriptor opened for ``SHARED_LOCK_PATH`` and
    must retain it until all later mutation work has finished.  This function
    never opens the path, creates state, or unlocks the caller descriptor.  A
    duplicate descriptor is used only to renew the exclusive flock on the
    same open-file-description; callers must close their descriptor on every
    failure and after successful mutation completion.
    """
    validate_state_layout()
    _validate_epoch()
    path = SHARED_LOCK_PATH
    _trusted_directory('/var')
    _trusted_directory('/var/lib')
    parent = os.path.dirname(path)
    _trusted_directory(os.path.dirname(parent))
    _trusted_directory(parent, 0o700)
    original_identity = _validate_existing_lock_fd(lock_fd)
    try:
        duplicate = os.dup(lock_fd)
        os.set_inheritable(duplicate, False)
    except (OSError, ValueError):
        reject('shared_lock_fd_invalid')
    try:
        duplicate_identity = os.fstat(duplicate)
        if original_identity != _identity(duplicate_identity):
            reject('shared_lock_identity_changed')
        try:
            fcntl.flock(duplicate, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError as exc:
            if exc.errno in (errno.EACCES, errno.EAGAIN):
                reject('shared_lock_busy')
            reject('shared_lock_failed')
        after_original = os.fstat(lock_fd)
        after_duplicate = os.fstat(duplicate)
        after_path = os.lstat(path)
        if (original_identity != _identity(after_original) or
                original_identity != _identity(after_duplicate) or
                original_identity != _identity(after_path)):
            reject('shared_lock_identity_changed')
        return _admit_locked(expected_boot_id)
    finally:
        os.close(duplicate)


def _close_shared_lock(fd):
    try:
        fcntl.flock(fd, fcntl.LOCK_UN)
    finally:
        os.close(fd)


@contextmanager
def shared_lock():
    """Compatibility context for read-only callers; writers use Admission."""
    fd, identity = _open_shared_lock()
    try:
        yield identity
    finally:
        _close_shared_lock(fd)


def current_boot_id(path='/proc/sys/kernel/random/boot_id'):
    with open(path, 'rt', encoding='ascii') as handle:
        value = handle.read().strip()
    if not BOOT.fullmatch(value):
        reject('boot_identity_invalid')
    return value


def _validate_record(record):
    if not isinstance(record, dict) or set(record) != {
            'schema', 'epoch', 'operation', 'run_id', 'boot_id',
            'resource_identity', 'registered_at_utc'}:
        reject('state_schema_unknown')
    if record['schema'] != SCHEMA or record['epoch'] != PROTOCOL_EPOCH:
        reject('state_epoch_unknown')
    for key in ('operation', 'run_id'):
        if not isinstance(record[key], str) or not TOKEN.fullmatch(record[key]):
            reject('state_identity_invalid')
    if not isinstance(record['boot_id'], str) or not BOOT.fullmatch(record['boot_id']):
        reject('boot_identity_invalid')
    if not isinstance(record['registered_at_utc'], str):
        reject('state_timestamp_invalid')
    try:
        datetime.fromisoformat(record['registered_at_utc'].replace('Z', '+00:00'))
    except ValueError:
        reject('state_timestamp_invalid')
    resource = record['resource_identity']
    if not isinstance(resource, dict) or not resource or any(not isinstance(k, str) for k in resource):
        reject('resource_identity_invalid')
    if any(not isinstance(v, (str, int, bool, type(None))) for v in resource.values()):
        reject('resource_identity_invalid')
    if len(_canonical(resource)) > MAX_RESOURCE_BYTES:
        reject('resource_identity_oversized')
    return record


def make_record(operation, run_id, boot_id, resource_identity, registered_at_utc=None):
    if registered_at_utc is None:
        registered_at_utc = datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace('+00:00', 'Z')
    record = {'schema': SCHEMA, 'epoch': PROTOCOL_EPOCH, 'operation': operation,
              'run_id': run_id, 'boot_id': boot_id,
              'resource_identity': resource_identity,
              'registered_at_utc': registered_at_utc}
    _validate_record(record)
    _canonical(record)
    return record


def _temp_names():
    try:
        return [name for name in os.listdir(STATE_ROOT)
                if name.startswith(PENDING_TEMP_PREFIX)]
    except OSError:
        reject('state_directory_unreadable')


def _path_exists_or_refuse(path):
    """Return existence without turning non-ENOENT errors into absence."""
    try:
        os.lstat(path)
        return True
    except FileNotFoundError:
        return False
    except OSError:
        reject('state_path_unreadable')


def _pending_exists():
    """Return whether pending exists; only ENOENT means safely absent."""
    try:
        os.lstat(PENDING_PATH)
        return True
    except FileNotFoundError:
        return False
    except OSError:
        reject('pending_state_unreadable')


def _clear_marker_exists():
    try:
        os.lstat(CLEAR_MARKER_PATH)
        return True
    except FileNotFoundError:
        return False
    except OSError:
        reject('clear_marker_unreadable')


def _clear_temp_names():
    try:
        return [name for name in os.listdir(STATE_ROOT)
                if name.startswith(CLEAR_MARKER_TEMP_PREFIX)]
    except OSError:
        reject('state_directory_unreadable')


def _fsync_state_directory():
    try:
        directory_fd = os.open(STATE_ROOT, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
    except OSError:
        reject('state_directory_sync_unknown')
    try:
        os.fsync(directory_fd)
    except OSError:
        reject('state_directory_sync_unknown')
    finally:
        os.close(directory_fd)


def _publish_pending_locked(record):
    """Publish one record while the caller retains the shared lock."""
    # A clear marker is a durable recovery veto.  Do not allow a new record
    # to appear beside it while the same lock-held capability is still active;
    # otherwise recovery could no longer identify which work it settles.
    if _clear_marker_exists() or _clear_temp_names():
        reject('pending_clear_unsettled')
    if _pending_exists() or _temp_names():
        reject('pending_already_present')
    raw = _canonical(_validate_record(record))
    fd, temp_path = tempfile.mkstemp(prefix=PENDING_TEMP_PREFIX, dir=STATE_ROOT)
    try:
        os.fchmod(fd, 0o600)
        offset = 0
        while offset < len(raw):
            written = os.write(fd, raw[offset:])
            if not isinstance(written, int) or written <= 0:
                reject('state_write_incomplete')
            offset += written
        os.fsync(fd)
        os.close(fd)
        fd = -1
        # link() is the no-clobber publication primitive: it cannot replace
        # an existing pending record.  Rename is intentionally not used.
        os.link(temp_path, PENDING_PATH)
        directory_fd = os.open(STATE_ROOT, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
        try:
            os.fsync(directory_fd)
        finally:
            os.close(directory_fd)
        os.unlink(temp_path)
    except FileExistsError:
        reject('pending_already_present')
    finally:
        if fd >= 0:
            os.close(fd)
        if _path_exists_or_refuse(temp_path):
            # Preserve a failed publication's temp evidence.  Admission will
            # refuse it; recovery must inspect it explicitly.
            pass
    return record


def _read_clear_marker():
    if not _clear_marker_exists():
        return None
    raw, identity = _read_bounded(CLEAR_MARKER_PATH, MAX_RECORD_BYTES, (1, 2))
    return _validate_record(_decode(raw)), identity, raw


def _matching_clear_pair():
    """Validate marker and pending as the same exact hardlinked inode."""
    marker_info = _read_clear_marker()
    if marker_info is None:
        return None
    marker_record, marker_stat, marker_raw = marker_info
    if not _pending_exists():
        if marker_stat.st_nlink != 1:
            reject('clear_marker_identity_invalid')
        return marker_info, None
    pending_raw, pending_stat = _read_bounded(PENDING_PATH, MAX_RECORD_BYTES, (1, 2))
    if (pending_raw != marker_raw or marker_stat.st_nlink != 2 or
            pending_stat.st_nlink != 2 or
            _inode_binding(marker_stat) != _inode_binding(pending_stat)):
        reject('clear_marker_conflict')
    return marker_info, (pending_raw, pending_stat)


def _link_clear_marker_locked(expected_raw, expected_stat):
    """Create the immutable marker as a no-clobber hardlink to pending."""
    if _clear_marker_exists() or _clear_temp_names():
        reject('pending_clear_unsettled')
    try:
        os.link(PENDING_PATH, CLEAR_MARKER_PATH, follow_symlinks=False)
    except FileExistsError:
        reject('pending_clear_unsettled')
    marker_raw, marker_stat = _read_bounded(CLEAR_MARKER_PATH, MAX_RECORD_BYTES, (2,))
    pending_raw, pending_stat = _read_bounded(PENDING_PATH, MAX_RECORD_BYTES, (2,))
    if (marker_raw != expected_raw or pending_raw != expected_raw or
            marker_stat.st_nlink != 2 or pending_stat.st_nlink != 2 or
            _inode_binding(marker_stat) != _inode_binding(pending_stat) or
            _inode_binding(pending_stat) != _inode_binding(expected_stat)):
        reject('clear_marker_identity_invalid')
    _fsync_state_directory()


def read_pending(expected_boot_id=None):
    """Read and validate the exact pending record, if present."""
    validate_state_layout()
    _validate_epoch()
    if _clear_temp_names() or _clear_marker_exists():
        reject('pending_clear_unsettled')
    if _temp_names():
        reject('pending_temp_present')
    if not _pending_exists():
        return None
    raw, identity = _read_bounded(PENDING_PATH, MAX_RECORD_BYTES)
    record = _validate_record(_decode(raw))
    if expected_boot_id is not None and record['boot_id'] != expected_boot_id:
        reject('pending_boot_changed')
    return record, identity, raw


def _admit_locked(expected_boot_id=None):
    """Perform read-only admission while the caller holds the shared lock."""
    _validate_epoch()
    if _clear_temp_names():
        reject('pending_clear_temp_present')
    marker = _matching_clear_pair()
    if marker is not None:
        # Even a validated terminal marker remains a recovery veto.  A
        # separate recovery capability must prove the terminal result and
        # remove it; ordinary admission never silently consumes that proof.
        reject('pending_clear_unsettled')
    if _temp_names():
        reject('pending_temp_present')
    if _pending_exists():
        # Parse it for a specific refusal class, but never turn a valid pending
        # record into permission to proceed.
        read_pending(expected_boot_id)
        reject('pending_present')
    # A marker may have just been removed by a proved recovery.  Confirm the
    # directory's durable view before accepting an empty state as authoritative.
    _fsync_state_directory()
    return {'schema': SCHEMA, 'epoch': PROTOCOL_EPOCH, 'status': 'admitted'}


class MaintenanceAdmission:
    """One lock-held admission/register/settle capability.

    The descriptor is acquired once and remains held across the caller's work.
    ``__exit__`` never clears a record, including on exceptions.  A caller must
    invoke :meth:`clear_pending` with an explicit terminal proof.
    """

    def __init__(self, expected_boot_id=None, recovery=False):
        self.expected_boot_id = expected_boot_id
        self.recovery = recovery
        self._fd = None
        self._caller_owned_fd = False
        self._closed = False
        self._state_identity = None
        self._epoch_identity = None
        self.lock_identity = None
        self.admission = None

    @classmethod
    def _from_existing_lock_fd(cls, lock_fd, expected_boot_id=None, recovery=False):
        """Create a capability over an already-held lock descriptor."""
        capability = cls(expected_boot_id, recovery=recovery)
        capability._caller_owned_fd = True
        try:
            epoch = validate_state_layout()
            _validate_epoch()
            _trusted_directory('/var')
            _trusted_directory('/var/lib')
            parent = os.path.dirname(SHARED_LOCK_PATH)
            _trusted_directory(os.path.dirname(parent))
            _trusted_directory(parent, 0o700)
            # Directory contents change during publication and recovery, so
            # bind only its stable inode and trust-boundary fields.
            capability._state_identity = _directory_identity(
                _trusted_directory(STATE_ROOT, 0o700))
            capability._epoch_identity = _identity(epoch)
            capability._fd = lock_fd
            capability.lock_identity = _validate_existing_lock_fd(lock_fd)
            _verify_existing_flock(lock_fd, capability.lock_identity)
            capability.admission = ({'schema': SCHEMA, 'epoch': PROTOCOL_EPOCH,
                                     'status': 'recovery'} if recovery else
                                    _admit_locked(expected_boot_id))
            return capability
        except Exception:
            capability._fd = None
            raise

    @classmethod
    def from_existing_lock_fd(cls, lock_fd, expected_boot_id=None):
        """Create a mutation capability over an already-held lock descriptor.

        This is the writer counterpart to :func:`admit_existing_lock_fd`.
        The caller must already hold the canonical shared flock.  The method
        validates the descriptor and state identity without opening a second
        lock description.  A duplicate of the same open-file-description is
        used only to verify the existing flock.  The caller retains the
        descriptor and must keep it open until the capability context exits.
        """
        return cls._from_existing_lock_fd(lock_fd, expected_boot_id)

    @classmethod
    def recovery_from_existing_lock_fd(cls, lock_fd, expected_boot_id=None):
        """Create recovery capability over an already-held lock descriptor."""
        return cls._from_existing_lock_fd(lock_fd, expected_boot_id, recovery=True)

    def __enter__(self):
        if self._closed:
            reject('lock_capability_closed')
        if self._caller_owned_fd and self._fd is not None:
            # from_existing_lock_fd has already admitted state while holding
            # the caller's lock. Do not acquire a second lock description or
            # run admission a second time when used as a context manager.
            self._assert_held()
            return self
        validate_state_layout()
        self._validate_epoch_and_lock()
        self._state_identity = _directory_identity(_trusted_directory(STATE_ROOT, 0o700))
        self._epoch_identity = _identity(_exact_regular(EPOCH_PATH, 0o600, 128))
        self._fd, self.lock_identity = _open_shared_lock()
        try:
            self.admission = ({'schema': SCHEMA, 'epoch': PROTOCOL_EPOCH,
                               'status': 'recovery'} if self.recovery else
                              _admit_locked(self.expected_boot_id))
            return self
        except Exception:
            _close_shared_lock(self._fd)
            self._fd = None
            raise

    def _validate_epoch_and_lock(self):
        _validate_epoch()
        validate_shared_lock()

    def _assert_held(self):
        if self._fd is None:
            reject('lock_capability_required')
        current_state = _trusted_directory(STATE_ROOT, 0o700)
        if (self._state_identity is not None and
                _directory_identity(current_state) != self._state_identity):
            reject('state_identity_changed')
        _validate_epoch()
        current_epoch = _exact_regular(EPOCH_PATH, 0o600, 128)
        if (self._epoch_identity is not None and
                _identity(current_epoch) != self._epoch_identity):
            reject('epoch_identity_changed')
        current = os.lstat(SHARED_LOCK_PATH)
        opened = os.fstat(self._fd)
        if _identity(opened) != self.lock_identity or _identity(current) != self.lock_identity:
            reject('shared_lock_identity_changed')
        if self._caller_owned_fd:
            # Re-prove the caller still holds the exclusive flock immediately
            # before every capability operation. This must observe absence
            # before attempting the duplicate-fd verification so an unlocked
            # descriptor is never silently reacquired.
            _verify_existing_flock(self._fd, self.lock_identity)

    def admit(self):
        self._assert_held()
        if self.admission is None:
            reject('admission_not_initialized')
        return dict(self.admission)

    def publish_pending(self, record):
        self._assert_held()
        if self.recovery:
            reject('recovery_operation_forbidden')
        return _publish_pending_locked(record)

    def clear_pending(self, expected_record, terminal_proof=None):
        self._assert_held()
        if self.recovery:
            reject('recovery_operation_forbidden')
        return _clear_pending_locked(expected_record, terminal_proof)

    def recover_clear_marker(self, terminal_proof=None):
        """Remove a clearing or terminal marker after fresh terminal proof."""
        self._assert_held()
        if not self.recovery:
            reject('recovery_capability_required')
        if not callable(terminal_proof):
            reject('terminal_proof_missing')
        pair = _matching_clear_pair()
        if pair is None:
            reject('clear_marker_missing')
        marker_info, pending_info = pair
        marker, before, marker_raw = marker_info
        pending_present = pending_info is not None
        try:
            proven = terminal_proof(marker)
        except Exception:
            reject('terminal_proof_unknown')
        if proven is not True:
            reject('terminal_proof_unknown')
        current = os.lstat(CLEAR_MARKER_PATH)
        if _identity(before) != _identity(current):
            reject('clear_marker_identity_changed')
        # For a marker+pending pair, first confirm that the hardlink pair was
        # durably published. Only then may recovery remove pending. For
        # marker-only state, the earlier unlink is already durable or admission
        # would have remained blocked.
        if pending_present:
            _fsync_state_directory()
            os.unlink(PENDING_PATH)
        _fsync_state_directory()
        os.unlink(CLEAR_MARKER_PATH)
        # If this fsync is unknown, the next admission fsyncs the directory
        # before accepting an absent marker, and otherwise remains blocked.
        _fsync_state_directory()
        return {'schema': SCHEMA, 'epoch': PROTOCOL_EPOCH, 'status': 'recovered',
                'run_id': marker['run_id']}

    def __exit__(self, exc_type, exc_value, traceback):
        if self._fd is not None:
            if not self._caller_owned_fd:
                _close_shared_lock(self._fd)
            self._fd = None
            self._closed = True
        return False


@contextmanager
def maintenance_admission(expected_boot_id=None):
    """Yield one lock-held :class:`MaintenanceAdmission` capability."""
    with MaintenanceAdmission(expected_boot_id) as capability:
        yield capability


@contextmanager
def maintenance_admission_from_lock_fd(lock_fd, expected_boot_id=None):
    """Yield a capability over a caller-owned, already-held lock descriptor.

    The caller retains responsibility for releasing ``lock_fd`` after this
    context exits.  No second lock description is opened; a duplicate of the
    same open-file-description is used only to verify the existing flock. The
    descriptor is never unlocked or closed by this module.
    """
    capability = MaintenanceAdmission.from_existing_lock_fd(lock_fd, expected_boot_id)
    try:
        yield capability
    finally:
        capability.__exit__(None, None, None)


@contextmanager
def maintenance_recovery_from_lock_fd(lock_fd, expected_boot_id=None):
    """Yield recovery capability over a caller-owned lock descriptor."""
    capability = MaintenanceAdmission.recovery_from_existing_lock_fd(
        lock_fd, expected_boot_id)
    try:
        yield capability
    finally:
        capability.__exit__(None, None, None)


@contextmanager
def maintenance_recovery(expected_boot_id=None):
    """Yield the same lock-held capability for explicit marker recovery."""
    with MaintenanceAdmission(expected_boot_id, recovery=True) as capability:
        yield capability


def admit_read_only(expected_boot_id=None):
    """Take a read-only admission snapshot while briefly holding the lock.

    Releasing the lock invalidates this snapshot for mutation. Writers must
    use MaintenanceAdmission through their entire operation, and this module
    alone does not fence legacy writers.
    """
    validate_state_layout()
    with MaintenanceAdmission(expected_boot_id) as capability:
        return dict(capability.admission)


def _clear_pending_locked(expected_record, terminal_proof=None):
    """Clear only an unchanged record while the shared lock remains held."""
    if not callable(terminal_proof):
        reject('terminal_proof_missing')
    try:
        proven = terminal_proof(expected_record)
    except Exception:
        reject('terminal_proof_unknown')
    if proven is not True:
        reject('terminal_proof_unknown')
    expected_raw = _canonical(_validate_record(expected_record))
    if _clear_temp_names() or _clear_marker_exists():
        reject('pending_clear_unsettled')
    if not _pending_exists():
        reject('pending_missing')
    raw, before = _read_bounded(PENDING_PATH, MAX_RECORD_BYTES)
    if raw != expected_raw:
        reject('pending_binding_changed')
    current = os.lstat(PENDING_PATH)
    if _identity(before) != _identity(current):
        reject('pending_binding_changed')
    _link_clear_marker_locked(raw, before)
    # Once the same inode is durably linked as the marker, every failure below
    # leaves an explicit recovery veto. Pending is never removed before that
    # exact marker link exists.
    os.unlink(PENDING_PATH)
    _fsync_state_directory()
    # Keep the nlink-1 marker until explicit recovery proves the terminal
    # result and removes it.
    return {'schema': SCHEMA, 'epoch': PROTOCOL_EPOCH, 'status': 'cleared',
            'run_id': expected_record['run_id'], 'clear_marker': 'recovery_required'}
