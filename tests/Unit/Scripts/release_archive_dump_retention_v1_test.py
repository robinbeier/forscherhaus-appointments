"""Regression coverage for the retention helper's legacy archive scanner."""

import importlib.util
import io
import fcntl
import os
import pathlib
import tarfile
import tempfile
import unittest
from unittest import mock


ROOT = pathlib.Path(__file__).parents[3]
SPEC = importlib.util.spec_from_file_location(
    'release_archive_dump_retention',
    ROOT / 'scripts' / 'ops' / 'libexec' / 'release_archive_dump_retention_v1.py',
)
RETENTION = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(RETENTION)


class ReleaseArchiveDumpRetentionTest(unittest.TestCase):
    def setUp(self):
        if os.geteuid() != 0:
            self.skipTest('stable root-owned archive fixture requires root')

    def _write_tar(self, directory, filename, members):
        path = os.path.join(directory, filename)
        with tarfile.open(path, mode='w:gz') as archive:
            for name, member_type, data in members:
                info = tarfile.TarInfo(name)
                if member_type == 'directory':
                    info.type = tarfile.DIRTYPE
                    archive.addfile(info)
                else:
                    info.size = len(data)
                    archive.addfile(info, io.BytesIO(data))
        os.chown(path, 0, 0)
        os.chmod(path, 0o600)
        return path

    def _inspect(self, directory, path):
        directory_fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
        try:
            return RETENTION.inspect_legacy_archive(directory_fd, os.path.basename(path))
        finally:
            os.close(directory_fd)

    def test_accepts_explicit_directory_after_child(self):
        with tempfile.TemporaryDirectory() as directory:
            path = self._write_tar(
                directory,
                'ordered.tar.gz',
                (('app/file.txt', 'file', b'x'), ('app', 'directory', b'')),
            )
            _, _, _, _, bounds = self._inspect(directory, path)
            self.assertEqual(1, bounds['stage_file_count'])
            self.assertEqual(3, bounds['stage_inode_count'])
            self.assertEqual(3 * 4096, bounds['stage_unpacked_bytes'])

    def test_uses_aggregate_bound_for_large_regular_member(self):
        with tempfile.TemporaryDirectory() as directory:
            path = self._write_tar(
                directory,
                'large.tar.gz',
                (('app/large.bin', 'file', b'x' * (17 * 1024 * 1024)),),
            )
            _, _, _, _, bounds = self._inspect(directory, path)
            self.assertEqual(17 * 1024 * 1024 + 2 * 4096, bounds['stage_unpacked_bytes'])

    def test_rejects_duplicate_explicit_directories(self):
        with tempfile.TemporaryDirectory() as directory:
            path = self._write_tar(
                directory,
                'duplicate-directory.tar.gz',
                (('app', 'directory', b''), ('app', 'directory', b'')),
            )
            with self.assertRaises(RETENTION.RetentionError):
                self._inspect(directory, path)

    def test_rejects_file_directory_type_conflicts(self):
        with tempfile.TemporaryDirectory() as directory:
            for filename, members in (
                ('file-then-child.tar.gz', (('app', 'file', b'x'), ('app/child', 'file', b'x'))),
                ('child-then-file.tar.gz', (('app/child', 'file', b'x'), ('app', 'file', b'x'))),
            ):
                path = self._write_tar(directory, filename, members)
                with self.assertRaises(RETENTION.RetentionError):
                    self._inspect(directory, path)

    def test_rejects_aggregate_unpack_limit(self):
        with tempfile.TemporaryDirectory() as directory:
            path = self._write_tar(directory, 'aggregate-limit.tar.gz', (('app/file', 'file', b'x'),))
            with mock.patch.object(RETENTION, 'MAX_LEGACY_HOLD_STAGE_UNPACKED_BYTES', 3 * 4096 - 1):
                with self.assertRaises(RETENTION.RetentionError):
                    self._inspect(directory, path)

    def test_rejects_entry_limit(self):
        with tempfile.TemporaryDirectory() as directory:
            path = self._write_tar(
                directory,
                'entry-limit.tar.gz',
                (('app/first', 'file', b'x'), ('app/second', 'file', b'x')),
            )
            with mock.patch.object(RETENTION, 'MAX_LEGACY_HOLD_STAGE_ENTRIES', 1):
                with self.assertRaises(RETENTION.RetentionError):
                    self._inspect(directory, path)

    def test_skips_root_directory_before_entry_limit(self):
        with tempfile.TemporaryDirectory() as directory:
            with mock.patch.object(RETENTION, 'MAX_LEGACY_HOLD_STAGE_ENTRIES', 1):
                for index, root_name in enumerate(('.', './')):
                    path = self._write_tar(
                        directory,
                        f'root-entry-limit-{index}.tar.gz',
                        ((root_name, 'directory', b''), ('payload.txt', 'file', b'x')),
                    )
                    _, _, _, _, bounds = self._inspect(directory, path)
                    self.assertEqual(1, bounds['stage_file_count'])
                    self.assertEqual(2, bounds['stage_inode_count'])
                    self.assertEqual(2 * 4096, bounds['stage_unpacked_bytes'])

    def test_rejects_repeated_root_directories_before_entry_limit(self):
        with tempfile.TemporaryDirectory() as directory:
            path = self._write_tar(
                directory,
                'repeated-root-entry-limit.tar.gz',
                (('.', 'directory', b''), ('./', 'directory', b''), ('payload.txt', 'file', b'x')),
            )
            with mock.patch.object(RETENTION, 'MAX_LEGACY_HOLD_STAGE_ENTRIES', 1):
                with self.assertRaises(RETENTION.RetentionError):
                    self._inspect(directory, path)

    def _admission_core(self):
        path = ROOT / 'scripts' / 'ops' / 'libexec' / 'maintenance_pending_v1.py'
        core_spec = importlib.util.spec_from_file_location('maintenance_pending_retention_test', path)
        core = importlib.util.module_from_spec(core_spec)
        core_spec.loader.exec_module(core)
        return core

    def _run_admission(self, pending=None, assert_unchanged=False, core=None):
        core = core or self._admission_core()
        with tempfile.TemporaryDirectory(dir='/var/lib') as directory:
            state = os.path.join(directory, 'state')
            os.mkdir(state, 0o700)
            epoch = os.path.join(state, 'epoch')
            with open(epoch, 'wb') as handle:
                handle.write((core.PROTOCOL_EPOCH + '\n').encode('ascii'))
            os.chmod(epoch, 0o600)
            lock_path = os.path.join(directory, 'production-change.lock')
            open(lock_path, 'wb').close()
            os.chmod(lock_path, 0o600)
            if pending is not None:
                with open(os.path.join(state, 'pending.json'), 'wb') as handle:
                    handle.write(pending)
                os.chmod(os.path.join(state, 'pending.json'), 0o600)
            paths = {
                'STATE_ROOT': state,
                'EPOCH_PATH': epoch,
                'PENDING_PATH': os.path.join(state, 'pending.json'),
                'CLEAR_MARKER_PATH': os.path.join(state, 'clear-state.json'),
                'SHARED_LOCK_PATH': lock_path,
            }
            with mock.patch.multiple(core, **paths):
                lock_fd = os.open(lock_path, os.O_RDWR | os.O_CLOEXEC | os.O_NOFOLLOW)
                try:
                    fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    try:
                        result = core.admit_existing_lock_fd(lock_fd)
                    except Exception:
                        if assert_unchanged:
                            if pending is not None:
                                with open(os.path.join(state, 'pending.json'), 'rb') as handle:
                                    self.assertEqual(handle.read(), pending)
                            self.assertFalse(os.path.exists(os.path.join(state, 'clear-state.json')))
                        raise
                    return core, result, state
                finally:
                    fcntl.flock(lock_fd, fcntl.LOCK_UN)
                    os.close(lock_fd)

    def test_execute_admits_before_state_or_marker_cleanup(self):
        mount_safety = object()
        admission = mock.Mock()
        admission.admit_existing_lock_fd.side_effect = RETENTION.RetentionError(
            'pending_present', 75,
        )
        lock_fd = os.open('/dev/null', os.O_RDONLY)
        with (
            mock.patch.object(RETENTION, 'assert_pre_mutation_mount_safety', return_value=mount_safety),
            mock.patch.object(RETENTION, 'open_global_lock', return_value=lock_fd),
            mock.patch.object(RETENTION, 'load_admission_core', return_value=admission),
            mock.patch.object(RETENTION, 'prepare_state_directory') as prepare_state,
            mock.patch.object(RETENTION, 'clean_marker_temps') as clean_markers,
            mock.patch.object(RETENTION, 'close_pre_mutation_mount_safety'),
        ):
            with self.assertRaises(RETENTION.RetentionError) as caught:
                RETENTION.execute()
        self.assertEqual(caught.exception.reason, 'pending_present')
        prepare_state.assert_not_called()
        clean_markers.assert_not_called()

    def test_admission_accepts_root_controlled_absent_pending_state(self):
        _, result, _ = self._run_admission()
        self.assertEqual(result['status'], 'admitted')

    def test_admission_refuses_present_pending_state_without_mutating_it(self):
        core = self._admission_core()
        record = core.make_record(
            'retention',
            '01234567-89ab-4cde-8fab-0123456789ab',
            '01234567-89ab-4cde-8fab-0123456789ab',
            {'target': 'synthetic'},
        )
        pending = core._canonical(record)
        with self.assertRaises(core.PendingError) as caught:
            self._run_admission(pending, assert_unchanged=True, core=core)
        self.assertEqual(caught.exception.reason, 'pending_present')

    def test_admission_refuses_corrupt_pending_state_without_mutating_it(self):
        core = self._admission_core()
        with self.assertRaises(core.PendingError) as caught:
            self._run_admission(b'{"schema":"maintenance_pending.v1"}\n', assert_unchanged=True, core=core)
        self.assertIn(caught.exception.reason, {'state_schema_unknown', 'state_noncanonical'})

    def test_admission_refuses_unsupported_pending_protocol_without_mutating_it(self):
        core = self._admission_core()
        record = core.make_record(
            'retention',
            '01234567-89ab-4cde-8fab-0123456789ab',
            '01234567-89ab-4cde-8fab-0123456789ab',
            {'target': 'synthetic'},
        )
        record['epoch'] = 'maintenance-admission-v0'
        pending = core._canonical(record)
        with self.assertRaises(core.PendingError) as caught:
            self._run_admission(pending, assert_unchanged=True, core=core)
        self.assertEqual(caught.exception.reason, 'state_epoch_unknown')

if __name__ == '__main__':
    unittest.main()
