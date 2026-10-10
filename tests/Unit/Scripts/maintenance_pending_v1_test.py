#!/usr/bin/python3
import errno
import fcntl
import json
import os
import stat
import tempfile
import unittest
from importlib.util import module_from_spec, spec_from_file_location


ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '../../../'))
SOURCE = os.path.join(ROOT, 'scripts/ops/libexec/maintenance_pending_v1.py')
SPEC = spec_from_file_location('maintenance_pending_v1', SOURCE)
MODULE = module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class MaintenancePendingTest(unittest.TestCase):
    def setUp(self):
        if os.geteuid() != 0:
            self.skipTest('root context is required for the trust-boundary fixture')
        self.tmp = tempfile.TemporaryDirectory(prefix='maintenance-pending-', dir='/root')
        self.state = os.path.join(self.tmp.name, 'state')
        self.lock_dir = os.path.join(self.tmp.name, 'locks')
        os.mkdir(self.state, 0o700)
        os.mkdir(self.lock_dir, 0o700)
        self.epoch = os.path.join(self.state, 'epoch')
        self.pending = os.path.join(self.state, 'pending.json')
        self.clear_marker = os.path.join(self.state, 'clear-state.json')
        self.lock = os.path.join(self.lock_dir, 'fh-production-change.lock')
        self._write(self.epoch, (MODULE.PROTOCOL_EPOCH + '\n').encode(), 0o600)
        self._write(self.lock, b'', 0o600)
        self.old = (MODULE.STATE_ROOT, MODULE.EPOCH_PATH, MODULE.PENDING_PATH,
                    MODULE.CLEAR_MARKER_PATH, MODULE.SHARED_LOCK_PATH)
        MODULE.STATE_ROOT, MODULE.EPOCH_PATH, MODULE.PENDING_PATH, MODULE.CLEAR_MARKER_PATH, MODULE.SHARED_LOCK_PATH = (
            self.state, self.epoch, self.pending, self.clear_marker, self.lock)

    def tearDown(self):
        (MODULE.STATE_ROOT, MODULE.EPOCH_PATH, MODULE.PENDING_PATH,
         MODULE.CLEAR_MARKER_PATH, MODULE.SHARED_LOCK_PATH) = self.old
        self.tmp.cleanup()

    @staticmethod
    def _write(path, data, mode=0o600):
        with open(path, 'wb') as handle:
            handle.write(data)
        os.chmod(path, mode)

    def record(self, run='run001', boot='11111111-1111-1111-1111-111111111111'):
        return MODULE.make_record('deploy', run, boot, {'kind': 'synthetic', 'id': run},
                                  '2026-10-10T00:00:00Z')

    def assert_lock_busy(self, fd):
        with self.assertRaises(OSError) as raised:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        self.assertIn(raised.exception.errno, (errno.EACCES, errno.EAGAIN))

    def test_empty_state_admits_read_only(self):
        self.assertEqual('admitted', MODULE.admit_read_only()['status'])
        self.assertFalse(os.path.lexists(self.pending))

    def test_existing_locked_fd_is_admitted_and_remains_held(self):
        caller = os.open(self.lock, os.O_RDWR)
        competitor = os.open(self.lock, os.O_RDWR)
        try:
            fcntl.flock(caller, fcntl.LOCK_EX | fcntl.LOCK_NB)
            self.assertEqual('admitted', MODULE.admit_existing_lock_fd(caller)['status'])
            self.assert_lock_busy(competitor)
        finally:
            os.close(competitor)
            fcntl.flock(caller, fcntl.LOCK_UN)
            os.close(caller)

    def test_existing_unlocked_fd_is_rejected_without_acquiring_lock(self):
        caller = os.open(self.lock, os.O_RDWR)
        competitor = os.open(self.lock, os.O_RDWR)
        try:
            with self.assertRaisesRegex(MODULE.PendingError, 'shared_lock_fd_not_held'):
                MODULE.admit_existing_lock_fd(caller)
            fcntl.flock(competitor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        finally:
            os.close(caller)
            os.close(competitor)

    def test_existing_fd_rejects_wrong_replaced_or_closed_descriptor(self):
        read_only = os.open(self.lock, os.O_RDONLY)
        try:
            with self.assertRaisesRegex(MODULE.PendingError, 'shared_lock_fd_not_rw'):
                MODULE.admit_existing_lock_fd(read_only)
        finally:
            os.close(read_only)
        wrong = os.open(self.epoch, os.O_RDWR)
        try:
            with self.assertRaisesRegex(MODULE.PendingError, 'shared_lock_fd_identity_invalid|shared_lock_identity_changed'):
                MODULE.admit_existing_lock_fd(wrong)
        finally:
            os.close(wrong)
        caller = os.open(self.lock, os.O_RDWR)
        os.unlink(self.lock)
        self._write(self.lock, b'', 0o600)
        try:
            with self.assertRaisesRegex(MODULE.PendingError, 'shared_lock_fd_identity_invalid|shared_lock_identity_changed'):
                MODULE.admit_existing_lock_fd(caller)
        finally:
            os.close(caller)
        closed = os.open(self.lock, os.O_RDWR)
        os.close(closed)
        with self.assertRaisesRegex(MODULE.PendingError, 'shared_lock_fd_invalid'):
            MODULE.admit_existing_lock_fd(closed)

    def test_existing_fd_refuses_foreign_lock_and_does_not_create_state(self):
        foreign = os.open(self.lock, os.O_RDWR)
        candidate = os.open(self.lock, os.O_RDWR)
        before = set(os.listdir(self.state))
        try:
            fcntl.flock(foreign, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with self.assertRaisesRegex(MODULE.PendingError, 'shared_lock_fd_not_held'):
                MODULE.admit_existing_lock_fd(candidate)
            self.assertEqual(before, set(os.listdir(self.state)))
        finally:
            os.close(candidate)
            fcntl.flock(foreign, fcntl.LOCK_UN)
            os.close(foreign)

    def test_read_only_admission_rejects_unlocked_fd_after_competing_handoff(self):
        caller = os.open(self.lock, os.O_RDWR)
        competitor = os.open(self.lock, os.O_RDWR)
        original_dup = MODULE.os.dup
        try:
            fcntl.flock(competitor, fcntl.LOCK_EX | fcntl.LOCK_NB)

            def releasing_dup(fd):
                # The old validator could acquire caller after this handoff.
                fcntl.flock(competitor, fcntl.LOCK_UN)
                return original_dup(fd)

            MODULE.os.dup = releasing_dup
            with self.assertRaisesRegex(MODULE.PendingError, 'shared_lock_fd_not_held'):
                MODULE.admit_existing_lock_fd(caller)
            self.assertFalse(MODULE._fdinfo_exclusive_flock_present(
                caller, MODULE._identity(os.fstat(caller))))
        finally:
            MODULE.os.dup = original_dup
            try:
                fcntl.flock(competitor, fcntl.LOCK_UN)
            except OSError:
                pass
            os.close(caller)
            os.close(competitor)

    def test_existing_fd_capability_publishes_and_clears_without_duplicating_or_owning_fd(self):
        caller = os.open(self.lock, os.O_RDWR)
        competitor = os.open(self.lock, os.O_RDWR)
        try:
            fcntl.flock(caller, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with MODULE.MaintenanceAdmission.from_existing_lock_fd(caller) as admission:
                record = self.record()
                self.assertEqual(record, admission.publish_pending(record))
                self.assertEqual('cleared',
                                 admission.clear_pending(record, lambda _: True)['status'])
            # Exiting the capability must leave the caller's flock and fd
            # untouched.  A competing descriptor remains blocked until the
            # caller explicitly releases its own lock.
            self.assert_lock_busy(competitor)
            fcntl.flock(caller, fcntl.LOCK_UN)
            fcntl.flock(competitor, fcntl.LOCK_EX | fcntl.LOCK_NB)
            fcntl.flock(competitor, fcntl.LOCK_UN)
        finally:
            os.close(caller)
            os.close(competitor)
        with MODULE.maintenance_recovery() as recovery:
            self.assertEqual('recovered',
                             recovery.recover_clear_marker(lambda _: True)['status'])

    def test_existing_fd_capability_rejects_unlocked_descriptor(self):
        caller = os.open(self.lock, os.O_RDWR)
        competitor = os.open(self.lock, os.O_RDWR)
        try:
            with self.assertRaisesRegex(MODULE.PendingError, 'shared_lock_fd_not_held'):
                MODULE.MaintenanceAdmission.from_existing_lock_fd(caller)
            fcntl.flock(competitor, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with self.assertRaisesRegex(MODULE.PendingError, 'shared_lock_fd_not_held'):
                MODULE.MaintenanceAdmission.from_existing_lock_fd(caller)
            self.assert_lock_busy(caller)
            # Deterministic handoff: the competing lock releases before the
            # validator runs, but the caller descriptor is still unlocked.
            fcntl.flock(competitor, fcntl.LOCK_UN)
            with self.assertRaisesRegex(MODULE.PendingError, 'shared_lock_fd_not_held'):
                MODULE.MaintenanceAdmission.from_existing_lock_fd(caller)
            self.assertFalse(MODULE._fdinfo_exclusive_flock_present(
                caller, MODULE._identity(os.fstat(caller))))
        finally:
            try:
                fcntl.flock(competitor, fcntl.LOCK_UN)
            except OSError:
                pass
            os.close(caller)
            os.close(competitor)

    def test_fdinfo_validator_rejects_handoff_race_without_dup_or_flock(self):
        """An inode-wide precheck must not turn a lock handoff into authority."""
        caller = os.open(self.lock, os.O_RDWR)
        competitor = os.open(self.lock, os.O_RDWR)
        original_dup = MODULE.os.dup
        try:
            fcntl.flock(competitor, fcntl.LOCK_EX | fcntl.LOCK_NB)

            def releasing_dup(fd):
                # This models the old /proc/locks check racing a competitor's
                # release between the precheck and flock(dup(caller)). The
                # descriptor-specific validator must never invoke this.
                fcntl.flock(competitor, fcntl.LOCK_UN)
                return original_dup(fd)

            MODULE.os.dup = releasing_dup
            identity = MODULE._identity(os.fstat(caller))
            with self.assertRaisesRegex(MODULE.PendingError, 'shared_lock_fd_not_held'):
                MODULE._verify_existing_flock(caller, identity)
            self.assertFalse(MODULE._fdinfo_exclusive_flock_present(caller, identity))
        finally:
            MODULE.os.dup = original_dup
            try:
                fcntl.flock(competitor, fcntl.LOCK_UN)
            except OSError:
                pass
            os.close(caller)
            os.close(competitor)

    def test_existing_fd_recovery_capability_settles_marker_without_reopening_lock(self):
        caller = os.open(self.lock, os.O_RDWR)
        competitor = os.open(self.lock, os.O_RDWR)
        record = self.record()
        try:
            fcntl.flock(caller, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with MODULE.MaintenanceAdmission.from_existing_lock_fd(caller) as admission:
                admission.publish_pending(record)
                self.assertEqual('cleared',
                                 admission.clear_pending(record, lambda _: True)['status'])
            with MODULE.MaintenanceAdmission.recovery_from_existing_lock_fd(caller) as recovery:
                self.assertEqual('recovered',
                                 recovery.recover_clear_marker(
                                     lambda value: value == record)['status'])
            self.assert_lock_busy(competitor)
            fcntl.flock(caller, fcntl.LOCK_UN)
            fcntl.flock(competitor, fcntl.LOCK_EX | fcntl.LOCK_NB)
            fcntl.flock(competitor, fcntl.LOCK_UN)
            self.assertEqual('admitted', MODULE.admit_read_only()['status'])
        finally:
            os.close(caller)
            os.close(competitor)

    def test_existing_fd_recovery_refuses_wrong_proof_and_lost_flock(self):
        caller = os.open(self.lock, os.O_RDWR)
        record = self.record()
        try:
            fcntl.flock(caller, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with MODULE.MaintenanceAdmission.from_existing_lock_fd(caller) as admission:
                admission.publish_pending(record)
                admission.clear_pending(record, lambda _: True)
            recovery = MODULE.MaintenanceAdmission.recovery_from_existing_lock_fd(caller)
            with recovery:
                with self.assertRaisesRegex(MODULE.PendingError, 'terminal_proof_unknown'):
                    recovery.recover_clear_marker(lambda _: False)
            self.assertTrue(os.path.lexists(self.clear_marker))
            fcntl.flock(caller, fcntl.LOCK_UN)
            with self.assertRaisesRegex(MODULE.PendingError,
                                         'shared_lock_fd_not_held|shared_lock_busy'):
                MODULE.MaintenanceAdmission.recovery_from_existing_lock_fd(caller)
        finally:
            if os.path.lexists(self.clear_marker):
                os.unlink(self.clear_marker)
            if os.path.lexists(self.pending):
                os.unlink(self.pending)
            os.close(caller)

    def test_existing_fd_capability_rejects_unlock_before_publish(self):
        caller = os.open(self.lock, os.O_RDWR)
        try:
            fcntl.flock(caller, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with MODULE.MaintenanceAdmission.from_existing_lock_fd(caller) as admission:
                fcntl.flock(caller, fcntl.LOCK_UN)
                with self.assertRaisesRegex(MODULE.PendingError,
                                             'shared_lock_fd_not_held|shared_lock_busy'):
                    admission.publish_pending(self.record())
            self.assertFalse(os.path.lexists(self.pending))
        finally:
            os.close(caller)

    def test_existing_fd_capability_rejects_unlock_before_clear_and_preserves_pending(self):
        caller = os.open(self.lock, os.O_RDWR)
        try:
            fcntl.flock(caller, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with MODULE.MaintenanceAdmission.from_existing_lock_fd(caller) as admission:
                record = self.record()
                admission.publish_pending(record)
                fcntl.flock(caller, fcntl.LOCK_UN)
                with self.assertRaisesRegex(MODULE.PendingError,
                                             'shared_lock_fd_not_held|shared_lock_busy'):
                    admission.clear_pending(record, lambda _: True)
            self.assertTrue(os.path.lexists(self.pending))
            os.unlink(self.pending)
        finally:
            os.close(caller)

    def test_existing_fd_capability_cannot_be_reentered_after_exit(self):
        caller = os.open(self.lock, os.O_RDWR)
        competitor = os.open(self.lock, os.O_RDWR)
        try:
            fcntl.flock(caller, fcntl.LOCK_EX | fcntl.LOCK_NB)
            capability = MODULE.MaintenanceAdmission.from_existing_lock_fd(caller)
            with capability:
                self.assertEqual('admitted', capability.admit()['status'])
            with self.assertRaisesRegex(MODULE.PendingError, 'lock_capability_closed'):
                with capability:
                    pass
            fcntl.flock(caller, fcntl.LOCK_UN)
            fcntl.flock(competitor, fcntl.LOCK_EX | fcntl.LOCK_NB)
            fcntl.flock(competitor, fcntl.LOCK_UN)
        finally:
            os.close(caller)
            os.close(competitor)

    def test_existing_fd_capability_pins_open_file_description_across_fd_reuse(self):
        caller = os.open(self.lock, os.O_RDWR)
        source_number = caller
        foreign_path = os.path.join(self.lock_dir, 'foreign.lock')
        foreign = None
        try:
            fcntl.flock(caller, fcntl.LOCK_EX | fcntl.LOCK_NB)
            capability = MODULE.MaintenanceAdmission.from_existing_lock_fd(caller)
            os.close(caller)
            caller = None
            self._write(foreign_path, b'', 0o600)
            foreign = os.open(foreign_path, os.O_RDWR)
            os.dup2(foreign, source_number)
            if foreign != source_number:
                os.close(foreign)
                foreign = None
            else:
                foreign = None
            with capability:
                self.assertEqual('admitted', capability.admit()['status'])
            os.close(source_number)
            source_number = None
        finally:
            if caller is not None:
                os.close(caller)
            if foreign is not None:
                os.close(foreign)
            if source_number is not None:
                os.close(source_number)

    def test_existing_fd_capability_pin_keeps_canonical_lock_across_source_close_and_reopen(self):
        caller = os.open(self.lock, os.O_RDWR)
        source_number = caller
        competitor = None
        competitor_initial = None
        reopened = None
        try:
            fcntl.flock(caller, fcntl.LOCK_EX | fcntl.LOCK_NB)
            capability = MODULE.MaintenanceAdmission.from_existing_lock_fd(caller)
            os.close(caller)
            caller = None

            competitor_initial = os.open(self.lock, os.O_RDWR)
            competitor = fcntl.fcntl(competitor_initial, fcntl.F_DUPFD_CLOEXEC,
                                     source_number + 10)
            os.close(competitor_initial)
            competitor_initial = None
            self.assert_lock_busy(competitor)
            reopened = os.open(self.lock, os.O_RDWR)
            os.dup2(reopened, source_number)
            if reopened != source_number:
                os.close(reopened)
            reopened = source_number
            self.assert_lock_busy(reopened)
            with capability:
                self.assertEqual('admitted', capability.admit()['status'])

            # Closing the retained pin releases the lock; the independent
            # canonical competitor can then acquire it.
            fcntl.flock(competitor, fcntl.LOCK_EX | fcntl.LOCK_NB)
            fcntl.flock(competitor, fcntl.LOCK_UN)
        finally:
            if caller is not None:
                os.close(caller)
            if competitor is not None:
                os.close(competitor)
            if competitor_initial is not None:
                os.close(competitor_initial)
            if reopened is not None:
                os.close(reopened)

    def test_existing_fd_capability_rejects_reused_descriptor(self):
        caller = os.open(self.lock, os.O_RDWR)
        try:
            fcntl.flock(caller, fcntl.LOCK_EX | fcntl.LOCK_NB)
            os.close(caller)
            caller = os.open(self.epoch, os.O_RDWR)
            with self.assertRaisesRegex(MODULE.PendingError,
                                         'shared_lock_fd_identity_invalid|shared_lock_identity_changed'):
                MODULE.MaintenanceAdmission.from_existing_lock_fd(caller)
        finally:
            os.close(caller)

    def test_existing_fd_capability_refuses_epoch_identity_drift(self):
        caller = os.open(self.lock, os.O_RDWR)
        replacement = self.epoch + '.replacement'
        try:
            fcntl.flock(caller, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with MODULE.MaintenanceAdmission.from_existing_lock_fd(caller) as admission:
                os.rename(self.epoch, replacement)
                self._write(self.epoch, (MODULE.PROTOCOL_EPOCH + '\n').encode())
                with self.assertRaisesRegex(MODULE.PendingError, 'epoch_identity_changed'):
                    admission.publish_pending(self.record())
                os.unlink(self.epoch)
                os.rename(replacement, self.epoch)
        finally:
            if os.path.lexists(replacement):
                os.unlink(replacement)
            os.close(caller)

    def test_existing_fd_capability_refuses_state_directory_drift(self):
        caller = os.open(self.lock, os.O_RDWR)
        moved = self.state + '.moved'
        try:
            fcntl.flock(caller, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with MODULE.MaintenanceAdmission.from_existing_lock_fd(caller) as admission:
                os.rename(self.state, moved)
                os.mkdir(self.state, 0o700)
                with self.assertRaisesRegex(MODULE.PendingError, 'state_identity_changed'):
                    admission.publish_pending(self.record())
                os.rmdir(self.state)
                os.rename(moved, self.state)
        finally:
            if os.path.isdir(self.state) and os.path.isdir(moved):
                os.rmdir(self.state)
            if os.path.isdir(moved) and not os.path.exists(self.state):
                os.rename(moved, self.state)
            os.close(caller)

    def test_existing_fd_blocks_pending_clear_marker_and_temp(self):
        cases = ('pending', 'clear_marker', 'clear_temp')
        for case in cases:
            with self.subTest(case=case):
                if case == 'pending':
                    self._write(self.pending, MODULE._canonical(self.record()))
                elif case == 'clear_marker':
                    self._write(self.clear_marker, b'blocked')
                else:
                    self._write(os.path.join(self.state, MODULE.CLEAR_MARKER_TEMP_PREFIX + 'blocked'), b'blocked')
                caller = os.open(self.lock, os.O_RDWR)
                try:
                    fcntl.flock(caller, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    with self.assertRaisesRegex(MODULE.PendingError, 'pending_|state_'):
                        MODULE.admit_existing_lock_fd(caller)
                finally:
                    os.close(caller)
                for path in (self.pending, self.clear_marker):
                    if os.path.lexists(path):
                        os.unlink(path)
                for name in os.listdir(self.state):
                    if '.tmp.' in name:
                        os.unlink(os.path.join(self.state, name))

    def test_missing_or_unsafe_epoch_refuses(self):
        os.unlink(self.epoch)
        with self.assertRaisesRegex(MODULE.PendingError, 'state_missing'):
            MODULE.admit_read_only()
        self._write(self.epoch, (MODULE.PROTOCOL_EPOCH + '\n').encode(), 0o644)
        with self.assertRaisesRegex(MODULE.PendingError, 'state_identity_invalid'):
            MODULE.admit_read_only()

    def test_epoch_symlink_is_refused(self):
        os.unlink(self.epoch)
        real = os.path.join(self.state, 'real-epoch')
        self._write(real, (MODULE.PROTOCOL_EPOCH + '\n').encode())
        os.symlink(real, self.epoch)
        with self.assertRaisesRegex(MODULE.PendingError, 'state_identity_invalid'):
            MODULE.admit_read_only()

    def test_replaced_shared_lock_is_refused(self):
        os.unlink(self.lock)
        os.symlink('/dev/null', self.lock)
        with self.assertRaisesRegex(MODULE.PendingError, 'state_identity_invalid'):
            MODULE.admit_read_only()

    def test_public_api_rejects_caller_supplied_alternate_lock_path(self):
        with self.assertRaises(TypeError):
            MODULE.MaintenanceAdmission(lock_path=self.lock)
        with self.assertRaises(TypeError):
            MODULE.maintenance_admission(lock_path=self.lock)
        with self.assertRaises(TypeError):
            MODULE.admit_read_only(lock_path=self.lock)
        with self.assertRaises(TypeError):
            MODULE.validate_shared_lock(self.lock)
        with self.assertRaises(TypeError):
            MODULE.shared_lock(self.lock)

    def test_publish_is_durable_and_duplicate_is_refused(self):
        record = self.record()
        with MODULE.maintenance_admission() as admission:
            self.assertEqual(record, admission.publish_pending(record))
            with open(self.pending, 'rb') as handle:
                self.assertEqual(MODULE._canonical(record), handle.read())
            with self.assertRaisesRegex(MODULE.PendingError, 'pending_already_present'):
                admission.publish_pending(record)

    def test_interrupted_temp_publication_blocks_admission(self):
        self._write(os.path.join(self.state, MODULE.PENDING_TEMP_PREFIX + 'interrupted'), b'partial')
        with self.assertRaisesRegex(MODULE.PendingError, 'pending_temp_present'):
            MODULE.admit_read_only()

    def test_pending_across_boot_change_is_not_admitted(self):
        record = self.record()
        with MODULE.maintenance_admission() as admission:
            admission.publish_pending(record)
        with self.assertRaisesRegex(MODULE.PendingError, 'pending_boot_changed'):
            MODULE.read_pending('22222222-2222-2222-2222-222222222222')
        with self.assertRaisesRegex(MODULE.PendingError, 'pending_present'):
            MODULE.admit_read_only(expected_boot_id=record['boot_id'])

    def test_malformed_or_truncated_pending_is_refused(self):
        self._write(self.pending, b'{"schema":"maintenance_pending.v1"')
        with self.assertRaisesRegex(MODULE.PendingError, 'state_noncanonical|state_json_invalid'):
            MODULE.admit_read_only()

    def test_pending_lstat_error_is_not_treated_as_absent(self):
        original_lstat = MODULE.os.lstat

        def failing_lstat(path):
            if path == self.pending:
                raise OSError(errno.EIO, 'synthetic I/O failure')
            return original_lstat(path)

        MODULE.os.lstat = failing_lstat
        try:
            with self.assertRaisesRegex(MODULE.PendingError, 'pending_state_unreadable'):
                MODULE.admit_read_only()
        finally:
            MODULE.os.lstat = original_lstat

    def test_clear_requires_terminal_proof(self):
        record = self.record()
        with MODULE.maintenance_admission() as admission:
            admission.publish_pending(record)
            with self.assertRaisesRegex(MODULE.PendingError, 'terminal_proof_missing'):
                admission.clear_pending(record)
            with self.assertRaisesRegex(MODULE.PendingError, 'terminal_proof_unknown'):
                admission.clear_pending(record, lambda _: None)
        self.assertTrue(os.path.lexists(self.pending))

    def test_short_or_zero_write_preserves_temporary_evidence(self):
        record = self.record()
        original_write = MODULE.os.write
        calls = []

        def partial_write(fd, data):
            calls.append(len(data))
            if len(calls) == 1:
                return max(1, len(data) // 2)
            return 0

        MODULE.os.write = partial_write
        try:
            with self.assertRaisesRegex(MODULE.PendingError, 'state_write_incomplete'):
                with MODULE.maintenance_admission() as admission:
                    admission.publish_pending(record)
        finally:
            MODULE.os.write = original_write
        self.assertFalse(os.path.lexists(self.pending))
        self.assertTrue(any(name.startswith(MODULE.PENDING_TEMP_PREFIX)
                            for name in os.listdir(self.state)))
        with self.assertRaisesRegex(MODULE.PendingError, 'pending_temp_present'):
            MODULE.admit_read_only()

    def test_clear_requires_exact_bound_record(self):
        record = self.record()
        with MODULE.maintenance_admission() as admission:
            admission.publish_pending(record)
            changed = dict(record, resource_identity={'kind': 'synthetic', 'id': 'other'})
            with self.assertRaisesRegex(MODULE.PendingError, 'pending_binding_changed'):
                admission.clear_pending(changed, lambda _: True)
        self.assertTrue(os.path.lexists(self.pending))

    def test_clear_after_terminal_proof_removes_exact_record(self):
        record = self.record()
        with MODULE.maintenance_admission() as admission:
            admission.publish_pending(record)
            result = admission.clear_pending(record, lambda value: value['run_id'] == 'run001')
        self.assertEqual('cleared', result['status'])
        self.assertTrue(os.path.lexists(MODULE.CLEAR_MARKER_PATH))
        self.assertFalse(os.path.lexists(self.pending))
        with self.assertRaisesRegex(MODULE.PendingError, 'pending_clear_unsettled'):
            MODULE.admit_read_only()
        with MODULE.maintenance_recovery() as recovery:
            recovered = recovery.recover_clear_marker(lambda value: value['run_id'] == 'run001')
        self.assertEqual('recovered', recovered['status'])
        self.assertFalse(os.path.lexists(MODULE.CLEAR_MARKER_PATH))
        self.assertEqual('admitted', MODULE.admit_read_only()['status'])

    def test_publish_after_clear_marker_is_refused_until_recovery(self):
        record = self.record()
        replacement = self.record(run='run002')
        with MODULE.maintenance_admission() as admission:
            admission.publish_pending(record)
            self.assertEqual('cleared',
                             admission.clear_pending(record, lambda _: True)['status'])
            with self.assertRaisesRegex(MODULE.PendingError,
                                         'pending_clear_unsettled'):
                admission.publish_pending(replacement)
            self.assertFalse(os.path.lexists(self.pending))
            self.assertTrue(os.path.lexists(self.clear_marker))
        with MODULE.maintenance_recovery() as recovery:
            self.assertEqual('recovered',
                             recovery.recover_clear_marker(lambda _: True)['status'])
        self.assertFalse(os.path.lexists(self.clear_marker))
        self.assertEqual('admitted', MODULE.admit_read_only()['status'])

    def test_fsync_after_pending_unlink_leaves_recovery_veto(self):
        record = self.record()
        original_fsync = MODULE.os.fsync
        state_dir_calls = []

        def fail_after_pending_unlink(fd):
            target = os.readlink('/proc/self/fd/' + str(fd))
            if target == self.state:
                state_dir_calls.append(fd)
            # Admission itself fsyncs once.  The fourth state-directory fsync
            # is the one after pending unlink: admission, publication, marker,
            # then pending removal.
            if target == self.state and len(state_dir_calls) == 4:
                raise OSError(errno.EIO, 'synthetic directory fsync failure')
            return original_fsync(fd)

        MODULE.os.fsync = fail_after_pending_unlink
        try:
            with self.assertRaisesRegex(MODULE.PendingError, 'state_directory_sync_unknown'):
                with MODULE.maintenance_admission() as admission:
                    admission.publish_pending(record)
                    admission.clear_pending(record, lambda _: True)
        finally:
            MODULE.os.fsync = original_fsync
        self.assertEqual(4, len(state_dir_calls))
        self.assertFalse(os.path.lexists(self.pending))
        self.assertTrue(os.path.lexists(MODULE.CLEAR_MARKER_PATH))
        with self.assertRaisesRegex(MODULE.PendingError, 'pending_clear_unsettled'):
            MODULE.admit_read_only()

        # Explicit recovery may settle and remove the marker only after the
        # caller supplies terminal proof and the directory is durable again.
        with MODULE.maintenance_recovery() as recovery:
            self.assertEqual('recovered',
                             recovery.recover_clear_marker(lambda _: True)['status'])
        self.assertEqual('admitted', MODULE.admit_read_only()['status'])

    def test_recovery_settles_matching_marker_and_pending_pair(self):
        record = self.record()
        with MODULE.maintenance_admission() as admission:
            admission.publish_pending(record)
            _, pending_identity = MODULE._read_bounded(self.pending, MODULE.MAX_RECORD_BYTES)
            MODULE._link_clear_marker_locked(MODULE._canonical(record), pending_identity)
            self.assertEqual(2, os.lstat(self.pending).st_nlink)
            self.assertEqual(2, os.lstat(self.clear_marker).st_nlink)
        with self.assertRaisesRegex(MODULE.PendingError, 'pending_clear_unsettled'):
            MODULE.admit_read_only()
        with MODULE.maintenance_recovery() as recovery:
            self.assertEqual('recovered',
                             recovery.recover_clear_marker(lambda _: True)['status'])
        self.assertFalse(os.path.lexists(self.pending))
        self.assertFalse(os.path.lexists(self.clear_marker))

    def test_marker_link_failure_leaves_pending_and_no_marker(self):
        record = self.record()
        original_link = MODULE.os.link

        def failing_link(*args, **kwargs):
            if args[1] == self.clear_marker:
                raise OSError(errno.EIO, 'synthetic hardlink failure')
            return original_link(*args, **kwargs)

        MODULE.os.link = failing_link
        try:
            with self.assertRaisesRegex(OSError, 'synthetic hardlink failure'):
                with MODULE.maintenance_admission() as admission:
                    admission.publish_pending(record)
                    admission.clear_pending(record, lambda _: True)
        finally:
            MODULE.os.link = original_link
        self.assertTrue(os.path.lexists(self.pending))
        self.assertFalse(os.path.lexists(self.clear_marker))
        with self.assertRaisesRegex(MODULE.PendingError, 'pending_present'):
            MODULE.admit_read_only()

    def test_recovery_keeps_hardlink_pair_when_publication_or_first_recovery_fsync_fails(self):
        """An unconfirmed hardlink pair remains a recovery veto until durable."""
        record = self.record()
        original_fsync = MODULE.os.fsync
        state_dir_calls = []

        def fail_marker_publication_fsync(fd):
            target = os.readlink('/proc/self/fd/' + str(fd))
            if target == self.state:
                state_dir_calls.append(fd)
                # Admission and pending publication have already synced the
                # directory.  Fail the first sync that confirms the marker.
                if len(state_dir_calls) == 3:
                    raise OSError(errno.EIO, 'synthetic marker publication fsync failure')
            return original_fsync(fd)

        MODULE.os.fsync = fail_marker_publication_fsync
        try:
            with self.assertRaisesRegex(MODULE.PendingError,
                                         'state_directory_sync_unknown'):
                with MODULE.maintenance_admission() as admission:
                    admission.publish_pending(record)
                    _, pending_identity = MODULE._read_bounded(
                        self.pending, MODULE.MAX_RECORD_BYTES)
                    MODULE._link_clear_marker_locked(
                        MODULE._canonical(record), pending_identity)
        finally:
            MODULE.os.fsync = original_fsync

        self.assertEqual(3, len(state_dir_calls))
        self.assertEqual(2, os.lstat(self.pending).st_nlink)
        self.assertEqual(2, os.lstat(self.clear_marker).st_nlink)
        with self.assertRaisesRegex(MODULE.PendingError,
                                     'pending_clear_unsettled'):
            MODULE.admit_read_only()

        def fail_first_recovery_fsync(fd):
            target = os.readlink('/proc/self/fd/' + str(fd))
            if target == self.state:
                raise OSError(errno.EIO, 'synthetic recovery fsync failure')
            return original_fsync(fd)

        MODULE.os.fsync = fail_first_recovery_fsync
        try:
            with self.assertRaisesRegex(MODULE.PendingError,
                                         'state_directory_sync_unknown'):
                with MODULE.maintenance_recovery() as recovery:
                    recovery.recover_clear_marker(lambda _: True)
        finally:
            MODULE.os.fsync = original_fsync

        self.assertTrue(os.path.lexists(self.pending))
        self.assertTrue(os.path.lexists(self.clear_marker))
        self.assertEqual(2, os.lstat(self.pending).st_nlink)
        self.assertEqual(2, os.lstat(self.clear_marker).st_nlink)
        with self.assertRaisesRegex(MODULE.PendingError,
                                     'pending_clear_unsettled'):
            MODULE.admit_read_only()

        with MODULE.maintenance_recovery() as recovery:
            result = recovery.recover_clear_marker(lambda _: True)
        self.assertEqual('recovered', result['status'])
        self.assertFalse(os.path.lexists(self.pending))
        self.assertFalse(os.path.lexists(self.clear_marker))
        self.assertEqual('admitted', MODULE.admit_read_only()['status'])

    def test_recovery_rejects_marker_and_pending_identity_mismatch(self):
        record = self.record()
        other = self.record(run='run002')
        with MODULE.maintenance_admission() as admission:
            admission.publish_pending(record)
            _, pending_identity = MODULE._read_bounded(self.pending, MODULE.MAX_RECORD_BYTES)
            MODULE._link_clear_marker_locked(MODULE._canonical(record), pending_identity)
            os.unlink(self.pending)
            self._write(self.pending, MODULE._canonical(other))
        with MODULE.maintenance_recovery() as recovery:
            with self.assertRaisesRegex(MODULE.PendingError, 'clear_marker_conflict'):
                recovery.recover_clear_marker(lambda _: True)
        self.assertTrue(os.path.lexists(self.pending))
        self.assertTrue(os.path.lexists(self.clear_marker))

    def test_pending_replacement_after_read_refuses_clear(self):
        record = self.record()
        with MODULE.maintenance_admission() as admission:
            admission.publish_pending(record)
            replacement = dict(record, run_id='run002')
            raw = MODULE._canonical(replacement)
            os.unlink(self.pending)
            self._write(self.pending, raw)
            with self.assertRaisesRegex(MODULE.PendingError, 'pending_binding_changed'):
                admission.clear_pending(record, lambda _: True)

    def test_exception_does_not_clear_pending_record(self):
        record = self.record()
        with self.assertRaisesRegex(RuntimeError, 'work failed'):
            with MODULE.maintenance_admission() as admission:
                admission.publish_pending(record)
                raise RuntimeError('work failed')
        self.assertTrue(os.path.lexists(self.pending))
        with self.assertRaisesRegex(MODULE.PendingError, 'pending_present'):
            MODULE.admit_read_only()

    def test_second_writer_is_blocked_while_capability_is_held(self):
        record = self.record()
        with MODULE.maintenance_admission() as admission:
            admission.publish_pending(record)
            # A second writer cannot even enter admission while the first
            # writer retains its descriptor across the work phase.
            with self.assertRaisesRegex(MODULE.PendingError, 'shared_lock_busy'):
                with MODULE.MaintenanceAdmission() as _second:
                    pass

    def test_recovery_capability_cannot_publish_or_clear_work(self):
        record = self.record()
        with MODULE.maintenance_recovery() as recovery:
            with self.assertRaisesRegex(MODULE.PendingError, 'recovery_operation_forbidden'):
                recovery.publish_pending(record)
            with self.assertRaisesRegex(MODULE.PendingError, 'recovery_operation_forbidden'):
                recovery.clear_pending(record, lambda _: True)

    def test_normal_capability_cannot_recover_clear_marker(self):
        with MODULE.maintenance_admission() as admission:
            with self.assertRaisesRegex(MODULE.PendingError, 'recovery_capability_required'):
                admission.recover_clear_marker(lambda _: True)

    def test_resource_identity_is_bounded_and_immutable(self):
        with self.assertRaisesRegex(MODULE.PendingError, 'resource_identity_oversized'):
            MODULE.make_record('deploy', 'run001', '11111111-1111-1111-1111-111111111111',
                               {'value': 'x' * 2000})


if __name__ == '__main__':
    unittest.main()
