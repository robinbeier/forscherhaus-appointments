#!/usr/bin/python3
import errno
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
        self.lock = os.path.join(self.lock_dir, 'fh-production-change.lock')
        self._write(self.epoch, (MODULE.PROTOCOL_EPOCH + '\n').encode(), 0o600)
        self._write(self.lock, b'', 0o600)
        self.old = (MODULE.STATE_ROOT, MODULE.EPOCH_PATH, MODULE.PENDING_PATH,
                    MODULE.SHARED_LOCK_PATH)
        MODULE.STATE_ROOT, MODULE.EPOCH_PATH, MODULE.PENDING_PATH, MODULE.SHARED_LOCK_PATH = (
            self.state, self.epoch, self.pending, self.lock)

    def tearDown(self):
        (MODULE.STATE_ROOT, MODULE.EPOCH_PATH, MODULE.PENDING_PATH,
         MODULE.SHARED_LOCK_PATH) = self.old
        self.tmp.cleanup()

    @staticmethod
    def _write(path, data, mode=0o600):
        with open(path, 'wb') as handle:
            handle.write(data)
        os.chmod(path, mode)

    def record(self, run='run001', boot='11111111-1111-1111-1111-111111111111'):
        return MODULE.make_record('deploy', run, boot, {'kind': 'synthetic', 'id': run},
                                  '2026-10-10T00:00:00Z')

    def test_empty_state_admits_read_only(self):
        self.assertEqual('admitted', MODULE.admit_read_only()['status'])
        self.assertFalse(os.path.lexists(self.pending))

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
        self.assertFalse(os.path.lexists(self.pending))
        self.assertEqual('admitted', MODULE.admit_read_only()['status'])

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

    def test_resource_identity_is_bounded_and_immutable(self):
        with self.assertRaisesRegex(MODULE.PendingError, 'resource_identity_oversized'):
            MODULE.make_record('deploy', 'run001', '11111111-1111-1111-1111-111111111111',
                               {'value': 'x' * 2000})


if __name__ == '__main__':
    unittest.main()
