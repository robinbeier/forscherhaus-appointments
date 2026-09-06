"""Regression coverage for the retention helper's legacy archive scanner."""

import importlib.util
import io
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

if __name__ == '__main__':
    unittest.main()
