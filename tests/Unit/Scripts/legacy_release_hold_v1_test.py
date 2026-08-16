import importlib.util
import io
import json
import os
import tarfile
import tempfile
import unittest

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
        with tempfile.NamedTemporaryFile(suffix='.tar.gz') as handle:
            with tarfile.open(fileobj=handle, mode='w:gz') as archive:
                nested = tarfile.TarInfo('app/config/settings.json')
                nested.size = 1
                archive.addfile(nested, io.BytesIO(b'x'))
                empty_dir = tarfile.TarInfo('app/storage')
                empty_dir.type = tarfile.DIRTYPE
                archive.addfile(empty_dir)
            os.chmod(handle.name, 0o600)
            record = HOLD.open_stable_regular(handle.name, HOLD.MAX_ARCHIVE_BYTES)
            try:
                bounds = HOLD.tar_bounds(record)
            finally:
                HOLD.close_record(record)
        self.assertEqual(1, bounds['stage_file_count'])
        self.assertEqual(5, bounds['stage_inode_count'])
        self.assertEqual(5 * HOLD.BLOCK_BYTES, bounds['stage_unpacked_bytes'])
        self.assertEqual(HOLD.TEMP_SCRATCH_BYTES, bounds['temp_scratch_bytes'])

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
