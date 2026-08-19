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


class LegacyReleaseHoldTest(unittest.TestCase):
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
