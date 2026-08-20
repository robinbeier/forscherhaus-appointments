import importlib.util
import io
import json
import os
import tarfile
import tempfile
import unittest
from unittest import mock

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))) )
SPEC = importlib.util.spec_from_file_location('hold', os.path.join(ROOT, 'scripts/ops/libexec/legacy_release_hold_v1.py'))
HOLD = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(HOLD)
RETENTION_SPEC = importlib.util.spec_from_file_location(
    'retention', os.path.join(ROOT, 'scripts/ops/libexec/release_archive_dump_retention_v1.py')
)
RETENTION = importlib.util.module_from_spec(RETENTION_SPEC)
RETENTION_SPEC.loader.exec_module(RETENTION)


class LegacyReleaseHoldTest(unittest.TestCase):
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

    def _assert_both_scanners_reject(self, directory, path):
        record = HOLD.open_stable_regular(path, HOLD.MAX_ARCHIVE_BYTES)
        try:
            with self.assertRaises(HOLD.HoldError):
                HOLD.tar_bounds(record)
        finally:
            HOLD.close_record(record)
        directory_fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
        try:
            with self.assertRaises(RETENTION.RetentionError):
                RETENTION.inspect_legacy_archive(directory_fd, os.path.basename(path))
        finally:
            os.close(directory_fd)

    def test_canonical_schema_is_two_ordered_targets_without_commit(self):
        targets = [
            {'archive': {'name': 'current.tar.gz', 'sha256': 'a' * 64, 'size_bytes': 1}, 'capacity_bounds': {'stage_file_count': 1, 'stage_inode_count': 1, 'stage_unpacked_bytes': 1, 'temp_scratch_bytes': HOLD.TEMP_SCRATCH_BYTES}, 'release_id': 'current', 'role_at_provisioning': 'current'},
            {'archive': {'name': 'rollback.tar.gz', 'sha256': 'b' * 64, 'size_bytes': 2}, 'capacity_bounds': {'stage_file_count': 1, 'stage_inode_count': 1, 'stage_unpacked_bytes': 1, 'temp_scratch_bytes': HOLD.TEMP_SCRATCH_BYTES}, 'release_id': 'rollback', 'role_at_provisioning': 'rollback'},
        ]
        value = {'schema': HOLD.HOLD_SCHEMA, 'targets': targets}
        decoded = HOLD.decode_hold(HOLD.canonical(value))
        self.assertEqual(['current', 'rollback'], [item['role_at_provisioning'] for item in decoded['targets']])
        self.assertNotIn('commit', decoded)

    def test_tar_bounds_rejects_links_and_path_traversal(self):
        with tempfile.NamedTemporaryFile(suffix='.tar.gz') as handle:
            with tarfile.open(fileobj=handle, mode='w:gz') as archive:
                info = tarfile.TarInfo('../escape')
                info.size = 1
                archive.addfile(info, io.BytesIO(b'x'))
            with self.assertRaises(HOLD.HoldError):
                record = HOLD.open_stable_regular(handle.name, HOLD.MAX_ARCHIVE_BYTES)
                try:
                    HOLD.tar_bounds(record)
                finally:
                    HOLD.close_record(record)

    def test_tar_bounds_rejects_duplicate_member_names(self):
        with tempfile.NamedTemporaryFile(suffix='.tar.gz') as handle:
            with tarfile.open(fileobj=handle, mode='w:gz') as archive:
                for content in (b'a', b'b'):
                    info = tarfile.TarInfo('duplicate.txt')
                    info.size = 1
                    archive.addfile(info, io.BytesIO(content))
            with self.assertRaises(HOLD.HoldError):
                record = HOLD.open_stable_regular(handle.name, HOLD.MAX_ARCHIVE_BYTES)
                try:
                    HOLD.tar_bounds(record)
                finally:
                    HOLD.close_record(record)

    def test_tar_scanners_accept_explicit_directory_after_child(self):
        if os.geteuid() != 0:
            self.skipTest('stable root-owned archive fixture requires root')
        with tempfile.TemporaryDirectory() as directory:
            path = os.path.join(directory, 'ordered.tar.gz')
            with tarfile.open(path, mode='w:gz') as archive:
                data = b'x'
                child = tarfile.TarInfo('app/file.txt')
                child.size = len(data)
                archive.addfile(child, io.BytesIO(data))
                app = tarfile.TarInfo('app')
                app.type = tarfile.DIRTYPE
                archive.addfile(app)
            os.chown(path, 0, 0)
            os.chmod(path, 0o600)
            record = HOLD.open_stable_regular(path, HOLD.MAX_ARCHIVE_BYTES)
            try:
                bounds = HOLD.tar_bounds(record)
            finally:
                HOLD.close_record(record)
            directory_fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
            try:
                _, _, _, _, retention_bounds = RETENTION.inspect_legacy_archive(directory_fd, 'ordered.tar.gz')
            finally:
                os.close(directory_fd)
            self.assertEqual(1, bounds['stage_file_count'])
            self.assertEqual(bounds['stage_inode_count'], retention_bounds['stage_inode_count'])
            self.assertEqual(bounds['stage_unpacked_bytes'], retention_bounds['stage_unpacked_bytes'])

    def test_tar_scanners_use_aggregate_bound_for_large_regular_member(self):
        if os.geteuid() != 0:
            self.skipTest('stable root-owned archive fixture requires root')
        with tempfile.TemporaryDirectory() as directory:
            path = os.path.join(directory, 'large.tar.gz')
            with tarfile.open(path, mode='w:gz') as archive:
                data = b'x' * (17 * 1024 * 1024)
                member = tarfile.TarInfo('app/large.bin')
                member.size = len(data)
                archive.addfile(member, io.BytesIO(data))
            os.chown(path, 0, 0)
            os.chmod(path, 0o600)
            record = HOLD.open_stable_regular(path, HOLD.MAX_ARCHIVE_BYTES)
            try:
                bounds = HOLD.tar_bounds(record)
            finally:
                HOLD.close_record(record)
            directory_fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
            try:
                _, _, _, _, retention_bounds = RETENTION.inspect_legacy_archive(directory_fd, 'large.tar.gz')
            finally:
                os.close(directory_fd)
            self.assertGreater(bounds['stage_unpacked_bytes'], 16 * 1024 * 1024)
            self.assertEqual(bounds['stage_unpacked_bytes'], retention_bounds['stage_unpacked_bytes'])

    def test_tar_scanners_reject_duplicate_explicit_directories(self):
        if os.geteuid() != 0:
            self.skipTest('stable root-owned archive fixture requires root')
        with tempfile.TemporaryDirectory() as directory:
            path = os.path.join(directory, 'duplicate-directory.tar.gz')
            with tarfile.open(path, mode='w:gz') as archive:
                for _ in range(2):
                    app = tarfile.TarInfo('app')
                    app.type = tarfile.DIRTYPE
                    archive.addfile(app)
            os.chown(path, 0, 0)
            os.chmod(path, 0o600)
            record = HOLD.open_stable_regular(path, HOLD.MAX_ARCHIVE_BYTES)
            try:
                with self.assertRaises(HOLD.HoldError):
                    HOLD.tar_bounds(record)
            finally:
                HOLD.close_record(record)
            directory_fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
            try:
                with self.assertRaises(RETENTION.RetentionError):
                    RETENTION.inspect_legacy_archive(directory_fd, 'duplicate-directory.tar.gz')
            finally:
                os.close(directory_fd)

    def test_tar_scanners_reject_file_directory_type_conflicts(self):
        if os.geteuid() != 0:
            self.skipTest('stable root-owned archive fixture requires root')
        with tempfile.TemporaryDirectory() as directory:
            for filename, members in (
                (
                    'file-then-child.tar.gz',
                    (
                        ('app', 'file', b'x'),
                        ('app/child', 'file', b'x'),
                    ),
                ),
                (
                    'child-then-file.tar.gz',
                    (
                        ('app/child', 'file', b'x'),
                        ('app', 'file', b'x'),
                    ),
                ),
            ):
                self._assert_both_scanners_reject(directory, self._write_tar(directory, filename, members))

    def test_tar_scanners_reject_aggregate_unpack_limit(self):
        if os.geteuid() != 0:
            self.skipTest('stable root-owned archive fixture requires root')
        with tempfile.TemporaryDirectory() as directory:
            path = self._write_tar(directory, 'aggregate-limit.tar.gz', (('app/file', 'file', b'x'),))
            with mock.patch.object(HOLD, 'MAX_UNPACKED', 3 * HOLD.BLOCK_BYTES - 1), mock.patch.object(
                RETENTION, 'MAX_LEGACY_HOLD_STAGE_UNPACKED_BYTES', 3 * HOLD.BLOCK_BYTES - 1
            ):
                self._assert_both_scanners_reject(directory, path)

    def test_tar_scanners_reject_entry_limit(self):
        if os.geteuid() != 0:
            self.skipTest('stable root-owned archive fixture requires root')
        with tempfile.TemporaryDirectory() as directory:
            path = self._write_tar(
                directory,
                'entry-limit.tar.gz',
                (('app/first', 'file', b'x'), ('app/second', 'file', b'x')),
            )
            with mock.patch.object(HOLD, 'MAX_ENTRIES', 1), mock.patch.object(
                RETENTION, 'MAX_LEGACY_HOLD_STAGE_ENTRIES', 1
            ):
                self._assert_both_scanners_reject(directory, path)

    def test_tar_bounds_counts_implicit_directories_and_block_rounded_bytes(self):
        if os.geteuid() != 0:
            self.skipTest('stable root-owned archive fixture requires root')
        with tempfile.NamedTemporaryFile(suffix='.tar.gz', delete=False) as handle:
            path = handle.name
            with tarfile.open(fileobj=handle, mode='w:gz') as archive:
                nested = tarfile.TarInfo('app/config/settings.json')
                nested.size = 1
                archive.addfile(nested, io.BytesIO(b'x'))
                empty_dir = tarfile.TarInfo('app/storage')
                empty_dir.type = tarfile.DIRTYPE
                archive.addfile(empty_dir)
            handle.flush()
            os.fsync(handle.fileno())
        os.chown(path, 0, 0)
        os.chmod(path, 0o600)
        try:
            record = HOLD.open_stable_regular(path, HOLD.MAX_ARCHIVE_BYTES)
            try:
                bounds = HOLD.tar_bounds(record)
            finally:
                HOLD.close_record(record)
        finally:
            os.unlink(path)
        self.assertEqual(1, bounds['stage_file_count'])
        self.assertEqual(5, bounds['stage_inode_count'])
        self.assertEqual(5 * HOLD.BLOCK_BYTES, bounds['stage_unpacked_bytes'])
        self.assertEqual(HOLD.TEMP_SCRATCH_BYTES, bounds['temp_scratch_bytes'])

    def test_archive_scan_rewinds_descriptor_after_digest(self):
        if os.geteuid() != 0:
            self.skipTest('stable root-owned archive fixture requires root')
        with tempfile.NamedTemporaryFile(suffix='.tar.gz', delete=False) as handle:
            path = handle.name
            with tarfile.open(fileobj=handle, mode='w:gz') as archive:
                info = tarfile.TarInfo('app/config/settings.json')
                info.size = 1
                archive.addfile(info, io.BytesIO(b'x'))
            handle.flush()
            os.fsync(handle.fileno())
        os.chown(path, 0, 0)
        os.chmod(path, 0o600)
        try:
            record = HOLD.open_stable_regular(path, HOLD.MAX_ARCHIVE_BYTES)
            try:
                self.assertTrue(HOLD.digest_fd(record['fd']))
                bounds = HOLD.tar_bounds(record)
            finally:
                HOLD.close_record(record)
        finally:
            os.unlink(path)
        self.assertEqual(1, bounds['stage_file_count'])

    def test_write_failure_cleans_only_the_bound_new_temp(self):
        if os.geteuid() != 0:
            self.skipTest('root-owned fixture requires root')
        with tempfile.TemporaryDirectory() as directory:
            web_root = os.path.join(directory, 'web')
            rollback_prefix = os.path.join(directory, 'rollback-')
            rollback_root = rollback_prefix + 'current'
            releases_root = os.path.join(directory, 'releases')
            lock_root = os.path.join(directory, 'locks')
            hold_parent = os.path.join(directory, 'etc-fh')
            os.makedirs(web_root, mode=0o755)
            os.makedirs(rollback_root, mode=0o755)
            os.makedirs(releases_root, mode=0o700)
            os.makedirs(lock_root, mode=0o700)
            os.makedirs(hold_parent, mode=0o700)
            for path, value in (
                (os.path.join(web_root, HOLD.CURRENT_MARKER), 'current'),
                (os.path.join(rollback_root, HOLD.CURRENT_MARKER), 'rollback'),
            ):
                with open(path, 'wb') as marker:
                    marker.write((value + '\n').encode())
                os.chmod(path, 0o600)
            for path, member_name in (
                (os.path.join(releases_root, 'current.tar.gz'), 'current.txt'),
                (os.path.join(releases_root, 'rollback.tar.gz'), 'rollback.txt'),
            ):
                with tarfile.open(path, mode='w:gz') as archive:
                    info = tarfile.TarInfo(member_name)
                    info.size = 1
                    archive.addfile(info, io.BytesIO(b'x'))
                os.chmod(path, 0o600)
            global_lock = os.path.join(lock_root, 'global.lock')
            publication_lock = os.path.join(releases_root, '.publication.lock')
            for path in (global_lock, publication_lock):
                open(path, 'wb').close()
                os.chmod(path, 0o600)

            names = ('WEB_ROOT', 'ROLLBACK_PREFIX', 'RELEASES_ROOT', 'GLOBAL_LOCK', 'PUBLICATION_LOCK', 'HOLD_PATH')
            values = (HOLD.WEB_ROOT, HOLD.ROLLBACK_PREFIX, HOLD.RELEASES_ROOT, HOLD.GLOBAL_LOCK, HOLD.PUBLICATION_LOCK, HOLD.HOLD_PATH)
            HOLD.WEB_ROOT = web_root
            HOLD.ROLLBACK_PREFIX = rollback_prefix
            HOLD.RELEASES_ROOT = releases_root
            HOLD.GLOBAL_LOCK = global_lock
            HOLD.PUBLICATION_LOCK = publication_lock
            HOLD.HOLD_PATH = os.path.join(hold_parent, 'legacy-release-hold.v1.json')
            try:
                targets = HOLD.preflight()
                for failure in ('write_failed', 'fsync_failed'):
                    HOLD.LEDGER = HOLD.MutationLedger()
                    if failure == 'write_failed':
                        def partial_write_then_fail(fd, data):
                            os.write(fd, data[:1])
                            raise HOLD.HoldError(failure)

                        patcher = mock.patch.object(HOLD, 'write_all', side_effect=partial_write_then_fail)
                    else:
                        original_fsync = HOLD.os.fsync
                        calls = 0

                        def fail_first_fsync(fd):
                            nonlocal calls
                            calls += 1
                            if calls == 1:
                                raise HOLD.HoldError(failure)
                            return original_fsync(fd)

                        patcher = mock.patch.object(HOLD.os, 'fsync', side_effect=fail_first_fsync)
                    with patcher:
                        with self.assertRaisesRegex(HOLD.HoldError, failure):
                            HOLD.provision(targets)
                    self.assertEqual([], list(os.listdir(hold_parent)))
                    counts, outcome = HOLD.LEDGER.fields()
                    self.assertEqual(1, counts['temp_files_created'])
                    self.assertEqual(1, counts['temp_files_removed'])
                    self.assertEqual('known', outcome)
            finally:
                for name, value in zip(names, values):
                    setattr(HOLD, name, value)

    def test_matching_temp_is_fsynced_before_publication(self):
        if os.geteuid() != 0:
            self.skipTest('root-owned fixture requires root')
        with tempfile.TemporaryDirectory() as directory:
            web_root = os.path.join(directory, 'web')
            rollback_prefix = os.path.join(directory, 'rollback-')
            rollback_root = rollback_prefix + 'current'
            releases_root = os.path.join(directory, 'releases')
            lock_root = os.path.join(directory, 'locks')
            hold_parent = os.path.join(directory, 'etc-fh')
            os.makedirs(web_root, mode=0o755)
            os.makedirs(rollback_root, mode=0o755)
            os.makedirs(releases_root, mode=0o700)
            os.makedirs(lock_root, mode=0o700)
            os.makedirs(hold_parent, mode=0o700)
            for path, value in (
                (os.path.join(web_root, HOLD.CURRENT_MARKER), 'current'),
                (os.path.join(rollback_root, HOLD.CURRENT_MARKER), 'rollback'),
            ):
                with open(path, 'wb') as marker:
                    marker.write((value + '\n').encode())
                os.chmod(path, 0o600)
            for path, member_name in (
                (os.path.join(releases_root, 'current.tar.gz'), 'current.txt'),
                (os.path.join(releases_root, 'rollback.tar.gz'), 'rollback.txt'),
            ):
                with tarfile.open(path, mode='w:gz') as archive:
                    info = tarfile.TarInfo(member_name)
                    info.size = 1
                    archive.addfile(info, io.BytesIO(b'x'))
                os.chmod(path, 0o600)
            global_lock = os.path.join(lock_root, 'global.lock')
            publication_lock = os.path.join(releases_root, '.publication.lock')
            for path in (global_lock, publication_lock):
                open(path, 'wb').close()
                os.chmod(path, 0o600)

            names = ('WEB_ROOT', 'ROLLBACK_PREFIX', 'RELEASES_ROOT', 'GLOBAL_LOCK', 'PUBLICATION_LOCK', 'HOLD_PATH')
            values = (HOLD.WEB_ROOT, HOLD.ROLLBACK_PREFIX, HOLD.RELEASES_ROOT, HOLD.GLOBAL_LOCK, HOLD.PUBLICATION_LOCK, HOLD.HOLD_PATH)
            HOLD.WEB_ROOT = web_root
            HOLD.ROLLBACK_PREFIX = rollback_prefix
            HOLD.RELEASES_ROOT = releases_root
            HOLD.GLOBAL_LOCK = global_lock
            HOLD.PUBLICATION_LOCK = publication_lock
            HOLD.HOLD_PATH = os.path.join(hold_parent, 'legacy-release-hold.v1.json')
            try:
                targets = HOLD.preflight()
                payload = HOLD.hold_bytes(targets)
                temp_path = os.path.join(hold_parent, HOLD.TEMP_PREFIX + ('a' * 32) + '.tmp')
                with open(temp_path, 'wb') as handle:
                    handle.write(payload)
                os.chown(temp_path, 0, 0)
                os.chmod(temp_path, 0o600)

                original_fsync = HOLD.os.fsync
                calls = 0

                def fail_reused_temp_fsync(fd):
                    nonlocal calls
                    calls += 1
                    if calls == 1:
                        raise HOLD.HoldError('reused_temp_fsync_failed')
                    return original_fsync(fd)

                HOLD.LEDGER = HOLD.MutationLedger()
                with mock.patch.object(HOLD.os, 'fsync', side_effect=fail_reused_temp_fsync):
                    with self.assertRaisesRegex(HOLD.HoldError, 'reused_temp_fsync_failed'):
                        HOLD.provision(targets)
                self.assertFalse(os.path.exists(HOLD.HOLD_PATH))
                self.assertEqual([], list(os.listdir(hold_parent)))
                counts, outcome = HOLD.LEDGER.fields()
                self.assertEqual({'hold_published': 0, 'temp_files_created': 0, 'temp_files_removed': 1}, counts)
                self.assertEqual('known', outcome)
            finally:
                for name, value in zip(names, values):
                    setattr(HOLD, name, value)

    def test_stage_inode_limit_includes_staging_root(self):
        self.assertEqual(HOLD.MAX_ENTRIES + 1, HOLD.MAX_STAGE_INODE_COUNT)

    def test_archive_rejects_path_replacement_between_hash_and_tar_scan(self):
        if os.geteuid() != 0:
            self.skipTest('stable root-owned archive fixture requires root')
        with tempfile.TemporaryDirectory() as directory:
            path = os.path.join(directory, 'release.tar.gz')
            replacement = os.path.join(directory, 'replacement.tar.gz')
            for target, member_name in ((path, 'safe.txt'), (replacement, 'drifted.txt')):
                with tarfile.open(target, mode='w:gz') as archive:
                    info = tarfile.TarInfo(member_name)
                    info.size = 1
                    archive.addfile(info, io.BytesIO(b'x'))
                os.chmod(target, 0o600)
            record = HOLD.open_stable_regular(path, HOLD.MAX_ARCHIVE_BYTES)
            try:
                os.replace(replacement, path)
                with self.assertRaisesRegex(HOLD.HoldError, 'file_changed'):
                    HOLD.tar_bounds(record)
            finally:
                HOLD.close_record(record)

    def test_open_lock_requires_zero_byte_single_link_file(self):
        if os.geteuid() != 0:
            self.skipTest('lock trust-boundary test requires root-owned fixture')
        with tempfile.TemporaryDirectory() as directory:
            path = os.path.join(directory, 'lock')
            with open(path, 'wb') as handle:
                handle.write(b'busy')
            os.chmod(path, 0o600)
            with self.assertRaisesRegex(HOLD.HoldError, 'unsafe_file'):
                HOLD.open_lock(path)

    def test_exact_arguments_are_closed(self):
        with self.assertRaises(HOLD.HoldError):
            HOLD.reject('invalid_arguments')

    def test_mutation_ledger_distinguishes_confirmed_and_inflight(self):
        ledger = HOLD.MutationLedger()
        self.assertEqual(({'hold_published': 0, 'temp_files_created': 0, 'temp_files_removed': 0}, 'none'), ledger.fields())
        ledger.begin()
        self.assertEqual('unknown', ledger.fields()[1])
        ledger.confirm('temp_files_created')
        self.assertEqual((1, 'known'), (ledger.fields()[0]['temp_files_created'], ledger.fields()[1]))

    def test_only_exact_owned_temp_names_are_accepted(self):
        self.assertTrue(HOLD.TEMP_PATTERN.fullmatch('.legacy-release-hold.v1.json.rob470-' + 'a' * 32 + '.tmp'))
        self.assertFalse(HOLD.TEMP_PATTERN.fullmatch('.legacy-release-hold.v1.json.rob470-' + 'a' * 31 + '.tmp'))
        self.assertFalse(HOLD.TEMP_PATTERN.fullmatch('.legacy-release-hold.v1.json.tmp-' + 'a' * 32))

    def test_marker_uses_fixed_release_id_token(self):
        if os.geteuid() != 0:
            self.skipTest('marker trust-boundary test requires root-owned fixture')
        with tempfile.TemporaryDirectory() as directory:
            path = os.path.join(directory, '_RELEASE')
            with open(path, 'wb') as handle:
                handle.write(b'legacy-1  2026-08-16T12:00:00Z\n')
            os.chmod(path, 0o600)
            self.assertEqual('legacy-1', HOLD.marker(path))


if __name__ == '__main__':
    unittest.main()
