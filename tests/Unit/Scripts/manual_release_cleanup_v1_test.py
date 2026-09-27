"""Bounded regression coverage for the one-time manual release cleanup path."""

import hashlib
import importlib.util
import json
import os
import shutil
import tempfile
import unittest
from types import SimpleNamespace
from unittest import mock


ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '../../../'))
SPEC = importlib.util.spec_from_file_location(
    'manual_release_cleanup_v1',
    os.path.join(ROOT, 'scripts/ops/libexec/manual_release_cleanup_v1.py'),
)
CLEANUP = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(CLEANUP)
RETENTION_SPEC = importlib.util.spec_from_file_location(
    'release_archive_dump_retention_v1',
    os.path.join(ROOT, 'scripts/ops/libexec/release_archive_dump_retention_v1.py'),
)
RETENTION = importlib.util.module_from_spec(RETENTION_SPEC)
RETENTION_SPEC.loader.exec_module(RETENTION)


class FixtureHelper:
    """Small controlled double for the audited filesystem primitives."""

    ORCHESTRATOR_ROOT = 'orchestrator'
    MAX_CLASS_SCAN = 10_000
    class _Ledger:
        def __init__(self):
            self.count = 0
            self.in_flight = 0

        def begin(self):
            self.in_flight += 1

        def confirm(self, _kind):
            self.in_flight -= 1
            self.count += 1

        def finish(self):
            self.in_flight -= 1

        def fields(self):
            return {'deletion_performed': self.count > 0, 'mutation_count': self.count}

    MUTATIONS = _Ledger()

    def __init__(self, root):
        self.root = root
        self.open_candidates = False
        self.active = 0
        self.nonterminal = False
        self.drift_after_quarantine = False
        self.open_after_quarantine = False
        self.drift_during_open_scan = False
        self.held_ids = set()

    @staticmethod
    def _identity(stat_result):
        return [stat_result.st_dev, stat_result.st_ino, stat_result.st_mode,
                stat_result.st_uid, stat_result.st_gid, stat_result.st_nlink]

    def file_identity(self, stat_result):
        return self._identity(stat_result)

    def directory_identity(self, stat_result):
        return self._identity(stat_result)

    def validate_candidate_tree(self, web_fd, name, owners, device):
        stat_result = os.stat(name, dir_fd=web_fd, follow_symlinks=False)
        candidate = os.path.join(self.root, 'web', name)
        if not os.path.exists(candidate):
            candidate = os.path.join(self.root, 'state', name)
        inode_count = sum(1 + len(files) for _, _, files in os.walk(candidate))
        result = {
            'identity': self._identity(stat_result),
            'mtime_ns': stat_result.st_mtime_ns,
            'allocated': stat_result.st_blocks * 512,
            'inodes': inode_count,
        }
        if self.drift_after_quarantine and os.path.isdir(os.path.join(self.root, 'state', name)):
            with open(os.path.join(self.root, 'state', name, 'nested', 'race-after-quarantine'), 'w', encoding='ascii') as stream:
                stream.write('changed-after-quarantine\n')
            self.drift_after_quarantine = False
        return result

    def open_child_directory(self, parent_fd, name):
        return os.open(name, os.O_RDONLY | os.O_DIRECTORY, dir_fd=parent_fd)

    def read_release_marker(self, directory_fd):
        fd = os.open('release', os.O_RDONLY, dir_fd=directory_fd)
        try:
            return os.read(fd, 256).decode().strip()
        finally:
            os.close(fd)

    def open_file_identities(self, trees):
        if self.drift_during_open_scan:
            pending_names = [name for name in os.listdir(os.path.join(self.root, 'state'))
                             if name.startswith('.pending-release-')]
            if pending_names:
                pending = os.path.join(self.root, 'state', pending_names[0])
                with open(os.path.join(pending, 'nested', 'race-during-open-scan'), 'w', encoding='ascii') as stream:
                    stream.write('changed-during-open-scan\n')
            self.drift_during_open_scan = False
        return 1 if self.open_after_quarantine else self.open_candidates

    def activity_count(self):
        return self.active

    def read_legacy_hold(self):
        return {release_id: {'sha256': 'a' * 64, 'size_bytes': 7} for release_id in self.held_ids}

    def assert_no_nonterminal_runs(self):
        if self.nonterminal:
            CLEANUP.reject('nonterminal_run')

    def open_global_lock(self):
        return os.open(os.devnull, os.O_RDONLY)

    def open_absolute_directory(self, path, exact_mode=None):
        actual = {
            CLEANUP.WEB_ROOT: os.path.join(self.root, 'web'),
            CLEANUP.RELEASES_ROOT: os.path.join(self.root, 'releases'),
            CLEANUP.STATE_ROOT: os.path.join(self.root, 'state'),
            'orchestrator': os.path.join(self.root, 'orchestrator'),
        }.get(path, path)
        return os.open(actual, os.O_RDONLY | os.O_DIRECTORY)

    def assert_no_nested_mounts(self, names, orchestrator):
        return None

    def remove_tree(self, parent, leaf, expected_identity, allowed_uids, expected_device):
        shutil.rmtree(os.path.join(self.root, 'state', leaf))


class ManualReleaseCleanupTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = self.tmp.name
        for leaf in ('web', 'releases', 'state', 'orchestrator'):
            os.mkdir(os.path.join(self.root, leaf), 0o700)
        self.helper = FixtureHelper(self.root)
        self._mkdir_release('easyappointments', 'current')
        self._mkdir_release('easyappointments_prev_current', 'rollback')
        os.mkdir(os.path.join(self.root, 'web', 'stage-unsafe'), 0o777)
        os.mkdir(os.path.join(self.root, 'web', 'failed-unsafe'), 0o777)

    def tearDown(self):
        self.tmp.cleanup()

    def _mkdir_release(self, name, marker, age_days=0):
        path = os.path.join(self.root, 'web', name)
        os.mkdir(path, 0o755)
        with open(os.path.join(path, 'release'), 'w', encoding='ascii') as stream:
            stream.write(marker + '\n')
        if age_days:
            old = int(os.path.getmtime(path) - age_days * 86400)
            os.utime(path, (old, old))
        return path

    def _archive(self, release_id):
        path = os.path.join(self.root, 'releases', release_id + '.tar.gz')
        with open(path, 'wb') as stream:
            stream.write(b'archive')
        os.chmod(path, 0o600)
        sidecar = os.path.join(self.root, 'releases', release_id + '.build-provenance.json')
        payload = {
            'archive': {'name': release_id + '.tar.gz', 'sha256': hashlib.sha256(b'archive').hexdigest(), 'size_bytes': 7},
            'capacity_bounds': {'stage_file_count': 1, 'stage_inode_count': 1, 'stage_unpacked_bytes': 1, 'temp_scratch_bytes': 1},
            'expected_commit': 'a' * 40, 'observed_commit': 'a' * 40,
            'release_id': release_id, 'schema': 'release_build_provenance.v1',
            'source': {field: 'b' * 64 for field in ('build_script_sha256', 'composer_lock_sha256', 'deploy_ea_sha256', 'package_lock_sha256')},
        }
        with open(sidecar, 'w', encoding='ascii') as stream:
            json.dump(payload, stream, sort_keys=True, separators=(',', ':'))
        os.chmod(sidecar, 0o600)

    def _collect(self):
        web = os.open(os.path.join(self.root, 'web'), os.O_RDONLY | os.O_DIRECTORY)
        releases = os.open(os.path.join(self.root, 'releases'), os.O_RDONLY | os.O_DIRECTORY)
        state = os.open(os.path.join(self.root, 'state'), os.O_RDONLY | os.O_DIRECTORY)
        try:
            with mock.patch.object(CLEANUP, 'archive_pair_identity', side_effect=self._archive_identity):
                return CLEANUP.collect(self.helper, web, releases, state, 'current', 'rollback', os.geteuid())
        finally:
            os.close(state)
            os.close(releases)
            os.close(web)

    def _archive_identity(self, _helper, releases_fd, release_id):
        leaf = release_id + '.tar.gz'
        try:
            stat_result = os.stat(leaf, dir_fd=releases_fd, follow_symlinks=False)
        except FileNotFoundError:
            CLEANUP.reject('candidate_archive_missing')
        if stat_result.st_mode & 0o777 != 0o600:
            CLEANUP.reject('candidate_archive_invalid')
        sidecar = release_id + '.build-provenance.json'
        try:
            sidecar_stat = os.stat(sidecar, dir_fd=releases_fd, follow_symlinks=False)
            with open(os.path.join(self.root, 'releases', sidecar), encoding='ascii') as stream:
                payload = json.load(stream)
        except (FileNotFoundError, ValueError, TypeError):
            CLEANUP.reject('candidate_archive_provenance_missing')
        if payload['archive']['sha256'] != hashlib.sha256(b'archive').hexdigest() or payload['archive']['size_bytes'] != 7:
            CLEANUP.reject('candidate_archive_provenance_invalid')
        return {'archive_identity': self.helper.file_identity(stat_result), 'archive_sha256': payload['archive']['sha256'],
                'archive_size_bytes': payload['archive']['size_bytes'], 'provenance_identity': self.helper.file_identity(sidecar_stat),
                'provenance_sha256': hashlib.sha256(json.dumps(payload, sort_keys=True, separators=(',', ':')).encode()).hexdigest()}
    def test_plan_selects_only_old_previous_dirs_and_caps_at_four(self):
        for index in range(6):
            release_id = 'old-' + str(index)
            self._mkdir_release('easyappointments_prev_' + release_id, release_id, age_days=8 + index)
            self._archive(release_id)
        self._mkdir_release('easyappointments_prev_recent', 'recent', age_days=2)
        self._archive('recent')
        plan, selected = self._collect()
        self.assertEqual(6, plan['eligible_count'])
        self.assertEqual(4, len(selected))
        self.assertEqual(['old-5', 'old-4', 'old-3', 'old-2'], [item['contained_release'] for item in selected])
        self.assertNotIn('easyappointments_prev_current', [item['name'] for item in selected])
        self.assertNotIn('stage-unsafe', [item['name'] for item in selected])
        self.assertNotIn('failed-unsafe', [item['name'] for item in selected])

    def test_missing_archive_and_pending_cleanup_fail_closed(self):
        self._mkdir_release('easyappointments_prev_old', 'old', age_days=8)
        with self.assertRaisesRegex(CLEANUP.CleanupError, 'candidate_archive_missing'):
            self._collect()

    def test_archive_without_provenance_sidecar_blocks_unheld_candidate(self):
        self._mkdir_release('easyappointments_prev_unprovenanced', 'unprovenanced', age_days=8)
        self._archive('unprovenanced')
        os.unlink(os.path.join(self.root, 'releases', 'unprovenanced.build-provenance.json'))
        with self.assertRaisesRegex(CLEANUP.CleanupError, 'candidate_archive_provenance_missing'):
            self._collect()

    def test_wrong_provenance_digest_or_size_blocks_candidate(self):
        self._mkdir_release('easyappointments_prev_bad-provenance', 'bad-provenance', age_days=8)
        self._archive('bad-provenance')
        path = os.path.join(self.root, 'releases', 'bad-provenance.build-provenance.json')
        with open(path, encoding='ascii') as stream:
            payload = json.load(stream)
        payload['archive']['sha256'] = 'f' * 64
        payload['archive']['size_bytes'] = 999
        with open(path, 'w', encoding='ascii') as stream:
            json.dump(payload, stream, sort_keys=True, separators=(',', ':'))
        with self.assertRaisesRegex(CLEANUP.CleanupError, 'candidate_archive_provenance_invalid'):
            self._collect()

    def test_valid_archive_pair_binds_archive_and_provenance_identities_in_plan(self):
        self._mkdir_release('easyappointments_prev_valid-pair', 'valid-pair', age_days=8)
        self._archive('valid-pair')
        plan, selected = self._collect()
        pair = plan['selected'][0]['archive_pair']
        self.assertEqual(1, len(selected))
        self.assertEqual(hashlib.sha256(b'archive').hexdigest(), pair['archive_sha256'])
        self.assertEqual(7, pair['archive_size_bytes'])
        self.assertIn('archive_identity', pair)
        self.assertIn('provenance_identity', pair)

    def test_held_release_with_archive_is_preserved_and_excluded(self):
        self._mkdir_release('easyappointments_prev_held-release', 'held-release', age_days=8)
        self._archive('held-release')
        self.helper.held_ids.add('held-release')
        plan, selected = self._collect()
        self.assertEqual(0, plan['eligible_count'])
        self.assertEqual([], selected)
        os.mkdir(os.path.join(self.root, 'state', '.pending-cleanup'))
        with self.assertRaisesRegex(CLEANUP.CleanupError, 'pending_cleanup_unresolved'):
            self._collect()

    def test_scan_limits_stop_before_recursive_candidate_validation(self):
        self.helper.MAX_CLASS_SCAN = 3
        with mock.patch.object(self.helper, 'validate_candidate_tree') as validate:
            with self.assertRaisesRegex(CLEANUP.CleanupError, 'release_directory_scan_limit'):
                self._collect()
            validate.assert_not_called()

        self.helper.MAX_CLASS_SCAN = 10_000
        self._mkdir_release('easyappointments_prev_old1', 'old1', age_days=8)
        self._mkdir_release('easyappointments_prev_old2', 'old2', age_days=8)
        with mock.patch.object(CLEANUP, 'MAX_PREVIOUS_SCAN', 2), \
                mock.patch.object(self.helper, 'validate_candidate_tree') as validate:
            with self.assertRaisesRegex(CLEANUP.CleanupError, 'previous_scan_limit'):
                self._collect()
            validate.assert_not_called()

    def test_open_candidate_and_missing_rollback_are_blocked(self):
        self._mkdir_release('easyappointments_prev_old', 'old', age_days=8)
        self._archive('old')
        self.helper.open_candidates = True
        with self.assertRaisesRegex(CLEANUP.CleanupError, 'candidate_open'):
            self._collect()
        shutil.rmtree(os.path.join(self.root, 'web', 'easyappointments_prev_current'))
        self.helper.open_candidates = False
        with self.assertRaisesRegex(CLEANUP.CleanupError, 'rollback_missing'):
            self._collect()

    def test_plan_digest_changes_when_selected_identity_changes(self):
        self._mkdir_release('easyappointments_prev_old', 'old', age_days=8)
        self._archive('old')
        plan, _ = self._collect()
        digest = hashlib.sha256(CLEANUP.canonical(plan)).hexdigest()
        plan['selected'][0]['name'] = 'easyappointments_prev-other'
        self.assertNotEqual(digest, hashlib.sha256(CLEANUP.canonical(plan)).hexdigest())

    def test_nested_file_replacement_invalidates_original_plan(self):
        candidate = self._mkdir_release('easyappointments_prev_old', 'old')
        nested = os.path.join(candidate, 'nested')
        os.mkdir(nested)
        target = os.path.join(nested, 'same-size.txt')
        with open(target, 'wb') as stream:
            stream.write(b'first')
        self._archive('old')
        old = int(os.path.getmtime(candidate) - 8 * 86400)
        os.utime(candidate, (old, old))
        plan, _ = self._collect()
        digest = hashlib.sha256(CLEANUP.canonical(plan)).hexdigest()
        top_mtime = os.stat(candidate).st_mtime_ns

        replacement = os.path.join(nested, 'replacement')
        with open(replacement, 'wb') as stream:
            stream.write(b'later')
        os.replace(replacement, target)
        self.assertEqual(top_mtime, os.stat(candidate).st_mtime_ns)
        revised, _ = self._collect()
        self.assertEqual(plan['selected'][0]['allocated_bytes'], revised['selected'][0]['allocated_bytes'])
        self.assertEqual(plan['selected'][0]['inode_count'], revised['selected'][0]['inode_count'])
        self.assertNotEqual(plan['selected'][0]['tree_metadata_sha256'],
                            revised['selected'][0]['tree_metadata_sha256'])

        with mock.patch.object(CLEANUP.os, 'geteuid', return_value=0), \
                mock.patch.object(CLEANUP.socket, 'gethostname', return_value='booking-server'), \
                mock.patch.object(CLEANUP.pwd, 'getpwnam', return_value=SimpleNamespace(pw_uid=os.geteuid())), \
                mock.patch.object(CLEANUP.fcntl, 'flock'), \
                mock.patch.object(CLEANUP, 'archive_pair_identity', side_effect=self._archive_identity):
            with self.assertRaisesRegex(CLEANUP.CleanupError, 'plan_identity_changed'):
                CLEANUP.run('execute', digest, self.helper)
        self.assertTrue(os.path.isfile(target))

    def test_nested_in_place_change_with_restored_mtime_changes_fingerprint(self):
        candidate = self._mkdir_release('easyappointments_prev_old', 'old')
        nested = os.path.join(candidate, 'nested')
        os.mkdir(nested)
        target = os.path.join(nested, 'same-size.txt')
        with open(target, 'wb') as stream:
            stream.write(b'first')
        web = os.open(os.path.join(self.root, 'web'), os.O_RDONLY | os.O_DIRECTORY)
        try:
            identity = self.helper.directory_identity(os.stat(candidate))
            before, _ = CLEANUP.tree_metadata_sha256(web, 'easyappointments_prev_old', identity)
            original = os.stat(target)
            with open(target, 'r+b') as stream:
                stream.write(b'later')
            os.utime(target, ns=(original.st_atime_ns, original.st_mtime_ns))
            after, _ = CLEANUP.tree_metadata_sha256(web, 'easyappointments_prev_old', identity)
        finally:
            os.close(web)
        self.assertNotEqual(before, after)

    def test_nested_change_after_plan_recheck_blocks_before_detach(self):
        candidate = self._mkdir_release('easyappointments_prev_old', 'old')
        nested = os.path.join(candidate, 'nested')
        os.mkdir(nested)
        target = os.path.join(nested, 'same-size.txt')
        with open(target, 'wb') as stream:
            stream.write(b'first')
        self._archive('old')
        old = int(os.path.getmtime(candidate) - 8 * 86400)
        os.utime(candidate, (old, old))
        plan, _ = self._collect()
        digest = hashlib.sha256(CLEANUP.canonical(plan)).hexdigest()
        archive_checks = 0

        def change_after_collect(*args):
            nonlocal archive_checks
            archive_checks += 1
            if archive_checks == 2:
                replacement = os.path.join(nested, 'replacement')
                with open(replacement, 'wb') as stream:
                    stream.write(b'later')
                os.replace(replacement, target)
            return self._archive_identity(*args)

        with mock.patch.object(CLEANUP.os, 'geteuid', return_value=0), \
                mock.patch.object(CLEANUP.socket, 'gethostname', return_value='booking-server'), \
                mock.patch.object(CLEANUP.pwd, 'getpwnam', return_value=SimpleNamespace(pw_uid=os.geteuid())), \
                mock.patch.object(CLEANUP.fcntl, 'flock'), \
                mock.patch.object(CLEANUP, 'archive_pair_identity', side_effect=change_after_collect):
            with self.assertRaisesRegex(CLEANUP.CleanupError, 'tree_changed'):
                CLEANUP.run('execute', digest, self.helper)
        self.assertEqual(2, archive_checks)
        self.assertTrue(os.path.isdir(candidate))
        self.assertTrue(os.path.isfile(target))
        self.assertTrue(os.path.isfile(os.path.join(self.root, 'releases', 'old.tar.gz')))

    def test_execute_removes_only_selected_previous_dirs(self):
        self._mkdir_release('easyappointments_prev_old', 'old', age_days=8)
        self._archive('old')
        plan, _ = self._collect()
        digest = hashlib.sha256(CLEANUP.canonical(plan)).hexdigest()
        with mock.patch.object(CLEANUP.os, 'geteuid', return_value=0), \
                mock.patch.object(CLEANUP.socket, 'gethostname', return_value='booking-server'), \
                mock.patch.object(CLEANUP.pwd, 'getpwnam', return_value=SimpleNamespace(pw_uid=os.geteuid())), \
                mock.patch.object(CLEANUP.fcntl, 'flock'), \
                mock.patch.object(CLEANUP, 'archive_pair_identity', side_effect=self._archive_identity):
            with self.assertRaisesRegex(CLEANUP.CleanupError, 'plan_identity_changed'):
                CLEANUP.run('execute', '0' * 64, self.helper)
            result = CLEANUP.run('execute', digest, self.helper)
        self.assertEqual('pass', result['status'])
        self.assertEqual(1, result['deleted_release_dirs'])
        self.assertFalse(os.path.exists(os.path.join(self.root, 'web', 'easyappointments_prev_old')))
        self.assertFalse(any(name.startswith('.pending-release-')
                             for name in os.listdir(os.path.join(self.root, 'state'))))
        for leaf in ('easyappointments', 'easyappointments_prev_current', 'stage-unsafe', 'failed-unsafe'):
            self.assertTrue(os.path.exists(os.path.join(self.root, 'web', leaf)))
        self.assertTrue(os.path.exists(os.path.join(self.root, 'releases', 'old.tar.gz')))

    def test_pinned_helper_sha_matches_real_retention_source(self):
        with open(os.path.join(ROOT, 'scripts/ops/libexec/release_archive_dump_retention_v1.py'), 'rb') as source:
            self.assertEqual(CLEANUP.HELPER_SHA256, hashlib.sha256(source.read()).hexdigest())

    @unittest.skipUnless(os.geteuid() == 0, 'root-owned archive pair fixture required')
    def test_real_archive_pair_identity_validates_sidecar_and_declared_digest(self):
        releases = os.path.join(self.root, 'real-releases')
        os.mkdir(releases, 0o700)
        os.chown(releases, 0, 0)
        release_id = 'real-pair'
        archive = os.path.join(releases, release_id + '.tar.gz')
        sidecar = os.path.join(releases, release_id + '.build-provenance.json')
        archive_bytes = b'root-owned archive fixture\n'
        with open(archive, 'wb') as stream:
            stream.write(archive_bytes)
        os.chown(archive, 0, 0)
        os.chmod(archive, 0o600)
        payload = {
            'archive': {'name': release_id + '.tar.gz', 'sha256': hashlib.sha256(archive_bytes).hexdigest(), 'size_bytes': len(archive_bytes)},
            'capacity_bounds': {'stage_file_count': 1, 'stage_inode_count': 1, 'stage_unpacked_bytes': 1, 'temp_scratch_bytes': 1},
            'expected_commit': 'a' * 40, 'observed_commit': 'a' * 40,
            'release_id': release_id, 'schema': 'release_build_provenance.v1',
            'source': {field: 'b' * 64 for field in ('build_script_sha256', 'composer_lock_sha256', 'deploy_ea_sha256', 'package_lock_sha256')},
        }
        sidecar_bytes = (json.dumps(payload, sort_keys=True, separators=(',', ':')) + '\n').encode('ascii')
        with open(sidecar, 'wb') as stream:
            stream.write(sidecar_bytes)
        os.chown(sidecar, 0, 0)
        os.chmod(sidecar, 0o600)
        fd = os.open(releases, os.O_RDONLY | os.O_DIRECTORY)
        try:
            pair = CLEANUP.archive_pair_identity(RETENTION, fd, release_id)
            self.assertEqual(hashlib.sha256(archive_bytes).hexdigest(), pair['archive_sha256'])
            self.assertEqual(len(archive_bytes), pair['archive_size_bytes'])
            self.assertEqual(hashlib.sha256(sidecar_bytes).hexdigest(), pair['provenance_sha256'])
            os.unlink(sidecar)
            with self.assertRaisesRegex(CLEANUP.CleanupError, 'candidate_provenance_missing'):
                CLEANUP.archive_pair_identity(RETENTION, fd, release_id)
            with open(sidecar, 'wb') as stream:
                broken = dict(payload)
                broken['archive'] = dict(payload['archive'], sha256='f' * 64, size_bytes=999)
                stream.write((json.dumps(broken, sort_keys=True, separators=(',', ':')) + '\n').encode('ascii'))
            os.chown(sidecar, 0, 0)
            os.chmod(sidecar, 0o600)
            with self.assertRaisesRegex(RETENTION.RetentionError, 'invalid_release_sidecar'):
                CLEANUP.archive_pair_identity(RETENTION, fd, release_id)
        finally:
            os.close(fd)

    @unittest.skipUnless(os.geteuid() == 0, 'root-owned helper fixture required')
    def test_pinned_helper_loads_real_source_without_running_its_cli(self):
        source = os.path.join(ROOT, 'scripts/ops/libexec/release_archive_dump_retention_v1.py')
        installed = os.path.join(self.root, 'fh-release-archive-dump-retention-v1')
        shutil.copyfile(source, installed)
        os.chown(installed, 0, 0)
        os.chmod(installed, 0o555)
        helper = CLEANUP.load_pinned_helper(installed)
        self.assertTrue(callable(helper.validate_candidate_tree))
        self.assertTrue(callable(helper.detach_tree))

    @unittest.skipUnless(os.geteuid() == 0, 'root-owned filesystem primitive fixture required')
    def test_real_candidate_validator_rejects_unsafe_nested_entries(self):
        candidate = os.path.join(self.root, 'real-candidate')
        os.mkdir(candidate, 0o755)
        os.chown(candidate, 0, 0)
        parent = os.open(self.root, os.O_RDONLY | os.O_DIRECTORY)
        try:
            nested = os.path.join(candidate, 'nested')
            os.mkdir(nested, 0o777)
            os.chmod(nested, 0o777)
            os.chown(nested, 0, 0)
            with self.assertRaisesRegex(RETENTION.RetentionError, 'unsafe_tree'):
                RETENTION.validate_candidate_tree(parent, 'real-candidate', {0}, os.fstat(parent).st_dev)
            shutil.rmtree(nested)
            os.symlink('/tmp', os.path.join(candidate, 'symlink'))
            with self.assertRaisesRegex(RETENTION.RetentionError, 'unsafe_tree'):
                RETENTION.validate_candidate_tree(parent, 'real-candidate', {0}, os.fstat(parent).st_dev)
        finally:
            os.close(parent)

    @unittest.skipUnless(os.geteuid() == 0, 'root-owned filesystem primitive fixture required')
    def test_real_detach_removes_admitted_candidate_only(self):
        source_path = os.path.join(self.root, 'real-web')
        state_path = os.path.join(self.root, 'real-state')
        os.mkdir(source_path, 0o700)
        os.mkdir(state_path, 0o700)
        candidate = os.path.join(source_path, 'easyappointments_prev_old')
        sibling = os.path.join(source_path, 'easyappointments_prev_current')
        os.mkdir(candidate, 0o755)
        os.mkdir(sibling, 0o755)
        os.chown(candidate, 0, 0)
        os.chown(sibling, 0, 0)
        with open(os.path.join(candidate, 'release'), 'w', encoding='ascii') as stream:
            stream.write('old\n')
        os.chown(os.path.join(candidate, 'release'), 0, 0)
        os.chmod(os.path.join(candidate, 'release'), 0o600)
        archive = os.path.join(self.root, 'old.tar.gz')
        with open(archive, 'wb') as stream:
            stream.write(b'archive')
        os.chown(archive, 0, 0)
        os.chmod(archive, 0o600)
        source = os.open(source_path, os.O_RDONLY | os.O_DIRECTORY)
        state = os.open(state_path, os.O_RDONLY | os.O_DIRECTORY)
        try:
            record = {'leaf': 'easyappointments_prev_old', 'identity': RETENTION.directory_identity(os.stat(candidate))}
            RETENTION.MUTATIONS.counts = {key: 0 for key in RETENTION.MUTATION_COUNT_KEYS}
            RETENTION.MUTATIONS.in_flight = 0
            RETENTION.detach_tree(source, state, record, 'release', {0}, RETENTION.MUTATIONS)
        finally:
            os.close(state)
            os.close(source)
        self.assertFalse(os.path.exists(candidate))
        self.assertTrue(os.path.isdir(sibling))
        self.assertTrue(os.path.isfile(archive))

    def test_active_production_work_blocks_before_filesystem_mutation(self):
        self.helper.active = 1
        with mock.patch.object(CLEANUP.os, 'geteuid', return_value=0), \
                mock.patch.object(CLEANUP.socket, 'gethostname', return_value='booking-server'):
            with self.assertRaisesRegex(CLEANUP.CleanupError, 'active_production_work'):
                CLEANUP.run('execute', '0' * 64, self.helper)

    def _quarantine_fixture(self, name='easyappointments_prev_race'):
        for pending in os.listdir(os.path.join(self.root, 'state')):
            if pending.startswith('.pending-release-'):
                shutil.rmtree(os.path.join(self.root, 'state', pending))
        candidate = self._mkdir_release(name, 'race')
        os.mkdir(os.path.join(candidate, 'nested'))
        old = int(os.path.getmtime(candidate) - 8 * 86400)
        os.utime(candidate, (old, old))
        self._archive('race')
        _, selected = self._collect()
        item = selected[0]
        web = os.open(os.path.join(self.root, 'web'), os.O_RDONLY | os.O_DIRECTORY)
        state = os.open(os.path.join(self.root, 'state'), os.O_RDONLY | os.O_DIRECTORY)
        releases = os.open(os.path.join(self.root, 'releases'), os.O_RDONLY | os.O_DIRECTORY)
        return item, web, state, releases

    def test_quarantine_preserves_pending_on_pre_quarantine_race(self):
        item, web, state, releases = self._quarantine_fixture()
        candidate = os.path.join(self.root, 'web', item['name'])
        original_rename = CLEANUP.os.rename

        def mutate_then_rename(src, dst, **kwargs):
            with open(os.path.join(candidate, 'race'), 'w', encoding='ascii') as stream:
                stream.write('changed-before-quarantine\n')
            return original_rename(src, dst, **kwargs)

        with mock.patch.object(CLEANUP.os, 'rename', side_effect=mutate_then_rename):
            with self.assertRaisesRegex(CLEANUP.CleanupError, 'quarantined_tree_changed'):
                CLEANUP.quarantine_and_delete(self.helper, web, state, releases, item, os.geteuid())
        os.close(state)
        os.close(web)
        os.close(releases)
        self.assertTrue(any(name.startswith('.pending-release-') for name in os.listdir(self.root + '/state')))
        self.assertFalse(os.path.exists(candidate))

    def test_quarantine_preserves_pending_on_post_quarantine_drift_or_open_file(self):
        for flag in ('drift_after_quarantine', 'open_after_quarantine'):
            with self.subTest(flag=flag):
                item, web, state, releases = self._quarantine_fixture('easyappointments_prev_' + flag)
                setattr(self.helper, flag, True)
                if flag == 'open_after_quarantine':
                    self.helper.open_after_quarantine = True
                reason = 'quarantined_candidate_open' if flag == 'open_after_quarantine' else 'quarantined_tree_changed'
                with self.assertRaisesRegex(CLEANUP.CleanupError, reason):
                    CLEANUP.quarantine_and_delete(self.helper, web, state, releases, item, os.geteuid())
                os.close(state)
                os.close(web)
                os.close(releases)
                self.assertTrue(any(name.startswith('.pending-release-') for name in os.listdir(self.root + '/state')))
                for pending in os.listdir(self.root + '/state'):
                    if pending.startswith('.pending-release-'):
                        shutil.rmtree(os.path.join(self.root, 'state', pending))
                setattr(self.helper, flag, False)
                self.helper.open_after_quarantine = False

    def test_quarantine_preserves_pending_on_drift_during_open_file_scan(self):
        item, web, state, releases = self._quarantine_fixture('easyappointments_prev_open_scan')
        self.helper.drift_during_open_scan = True
        with self.assertRaisesRegex(CLEANUP.CleanupError, 'quarantined_tree_changed'):
            CLEANUP.quarantine_and_delete(self.helper, web, state, releases, item, os.geteuid())
        os.close(state)
        os.close(web)
        os.close(releases)
        self.assertTrue(any(name.startswith('.pending-release-') for name in os.listdir(self.root + '/state')))

    def test_quarantine_rechecks_archive_pair_after_move_and_preserves_pending_on_drift(self):
        item, web, state, releases = self._quarantine_fixture('easyappointments_prev_archive_drift')
        changed_pair = dict(item['archive_pair'], archive_sha256='f' * 64)
        with mock.patch.object(CLEANUP, 'archive_pair_identity', return_value=changed_pair):
            with self.assertRaisesRegex(CLEANUP.CleanupError, 'candidate_archive_changed'):
                CLEANUP.quarantine_and_delete(self.helper, web, state, releases, item, os.geteuid())
        os.close(releases)
        os.close(state)
        os.close(web)
        pending = [name for name in os.listdir(self.root + '/state') if name.startswith('.pending-release-')]
        self.assertEqual(1, len(pending))
        self.assertTrue(os.path.isdir(os.path.join(self.root, 'state', pending[0])))


if __name__ == '__main__':
    unittest.main()
