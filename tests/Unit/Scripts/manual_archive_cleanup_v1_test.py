"""Bounded tests for the archive/provenance-pair-only operator."""

import hashlib
import importlib.util
import json
import os
import tempfile
import unittest
from unittest import mock


ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '../../../'))
SPEC = importlib.util.spec_from_file_location(
    'manual_archive_cleanup_v1', os.path.join(ROOT, 'scripts/ops/libexec/manual_archive_cleanup_v1.py'))
CLEANUP = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(CLEANUP)
RETENTION_SPEC = importlib.util.spec_from_file_location(
    'release_archive_dump_retention_v1',
    os.path.join(ROOT, 'scripts/ops/libexec/release_archive_dump_retention_v1.py'))
RETENTION = importlib.util.module_from_spec(RETENTION_SPEC)
RETENTION_SPEC.loader.exec_module(RETENTION)


class FakeHelper:
    MAX_ARCHIVE_BYTES = 1024 * 1024
    MAX_SIDECAR_BYTES = 4096
    MAX_CLASS_SCAN = 10_000
    ORCHESTRATOR_ROOT = 'orchestrator'

    def __init__(self, root):
        self.root = root
        self.active = 0
        self.nonterminal = False
        self.fail_sidecar = False
        self.markers = {}
        self.state_fd = None

    @staticmethod
    def file_identity(value):
        return [value.st_dev, value.st_ino, value.st_mode, value.st_uid,
                value.st_gid, value.st_nlink, value.st_size,
                value.st_mtime_ns, value.st_ctime_ns]

    directory_identity = file_identity

    def stable_hash(self, directory, leaf, *_args):
        try:
            location = os.readlink('/proc/self/fd/' + str(directory))
        except OSError:
            location = ''
        base = 'state' if directory == self.state_fd or location.endswith('/state') else 'releases'
        path = os.path.join(self.root, base, leaf)
        with open(path, 'rb') as stream:
            data = stream.read()
        value = os.stat(path)
        if value.st_nlink != 1:
            CLEANUP.reject('unsafe_file')
        return hashlib.sha256(data).hexdigest(), len(data), self.file_identity(value), value

    def stable_regular(self, directory, leaf, *_args, **_kwargs):
        path = os.path.join(self.root, 'releases', leaf)
        try:
            location = os.readlink('/proc/self/fd/' + str(directory))
        except OSError:
            location = ''
        if directory == self.state_fd or location.endswith('/state'):
            path = os.path.join(self.root, 'state', leaf)
        with open(path, 'rb') as stream:
            data = stream.read()
        value = os.stat(path)
        if value.st_nlink != 1:
            CLEANUP.reject('unsafe_file')
        return data, self.file_identity(value), value

    def validate_provenance(self, data, release_id, archive_sha, archive_size):
        value = json.loads(data)
        if value['release_id'] != release_id or value['archive']['sha256'] != archive_sha or value['archive']['size_bytes'] != archive_size:
            CLEANUP.reject('invalid_release_sidecar')

    def read_legacy_hold(self):
        return getattr(self, 'holds', {})

    def open_global_lock(self):
        return os.open(os.devnull, os.O_RDONLY)

    def activity_count(self):
        return self.active

    def assert_no_nonterminal_runs(self):
        if self.nonterminal:
            CLEANUP.reject('nonterminal_run')

    def assert_no_nested_mounts(self, _names, _orchestrator):
        return None

    def open_absolute_directory(self, path, exact_mode=None):
        leaf = {CLEANUP.WEB_ROOT: 'web', CLEANUP.RELEASES_ROOT: 'releases', CLEANUP.STATE_ROOT: 'state'}.get(path, path)
        fd = os.open(os.path.join(self.root, leaf), os.O_RDONLY | os.O_DIRECTORY)
        if path == CLEANUP.STATE_ROOT:
            self.state_fd = fd
        return fd

    def open_child_directory(self, parent, name):
        fd = os.open(name, os.O_RDONLY | os.O_DIRECTORY, dir_fd=parent)
        if name == 'easyappointments':
            self.markers[fd] = 'current'
        elif name == 'easyappointments_prev_current':
            self.markers[fd] = 'rollback'
        return fd

    def read_release_marker(self, fd):
        return self.markers[fd]

    def open_file_identities(self, _items):
        if getattr(self, 'late_open', False) and any(
                name.startswith('.pending-archive-') for name in os.listdir(os.path.join(self.root, 'state'))):
            return 1
        return 0


class ManualArchiveCleanupTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = self.tmp.name
        for leaf in ('web', 'releases', 'state', 'orchestrator'):
            os.mkdir(os.path.join(self.root, leaf), 0o700)
        lock_path = os.path.join(self.root, 'releases', '.release-pair.lock')
        with open(lock_path, 'wb'):
            pass
        os.chmod(lock_path, 0o600)
        self.helper = FakeHelper(self.root)
        self._release('easyappointments', 'current')
        self._release('easyappointments_prev_current', 'rollback')
        self._pair('current')
        self._pair('rollback')

    def test_pinned_helper_sha_matches_real_retention_source(self):
        with open(os.path.join(ROOT, 'scripts/ops/libexec/release_archive_dump_retention_v1.py'), 'rb') as source:
            self.assertEqual(CLEANUP.HELPER_SHA256, hashlib.sha256(source.read()).hexdigest())

    def tearDown(self):
        self.tmp.cleanup()

    def _release(self, name, marker):
        path = os.path.join(self.root, 'web', name)
        os.mkdir(path)
        with open(os.path.join(path, '_RELEASE'), 'w', encoding='ascii') as stream:
            stream.write(marker + '\n')

    def _pair(self, release_id):
        data = (release_id + '-archive').encode()
        archive = os.path.join(self.root, 'releases', release_id + '.tar.gz')
        with open(archive, 'wb') as stream:
            stream.write(data)
        payload = {'release_id': release_id, 'archive': {'sha256': hashlib.sha256(data).hexdigest(), 'size_bytes': len(data)}}
        with open(os.path.join(self.root, 'releases', release_id + '.build-provenance.json'), 'w') as stream:
            json.dump(payload, stream)

    def _collect(self):
        fd = os.open(os.path.join(self.root, 'releases'), os.O_RDONLY | os.O_DIRECTORY)
        try:
            self.helper.state_path = os.path.join(self.root, 'state')
            return CLEANUP.collect(self.helper, fd, 'current', 'rollback')
        finally:
            os.close(fd)

    def test_plan_protects_current_rollback_and_foreign_entries(self):
        self._pair('old-a')
        self._pair('old-b')
        with open(os.path.join(self.root, 'releases', 'backup.sql.gz'), 'wb') as stream:
            stream.write(b'foreign')
        plan, selected = self._collect()
        self.assertEqual(['old-a', 'old-b'], [item['release_id'] for item in selected])
        self.assertEqual(1, plan['foreign_entry_count'])
        self.assertNotIn('current', [item['release_id'] for item in selected])
        self.assertNotIn('rollback', [item['release_id'] for item in selected])

    def test_digest_drift_blocks_execute_before_any_mutation(self):
        self._pair('old')
        plan, _ = self._collect()
        digest = hashlib.sha256(CLEANUP.canonical(plan)).hexdigest()
        with open(os.path.join(self.root, 'releases', 'old.tar.gz'), 'ab') as stream:
            stream.write(b'drift')
        with mock.patch.object(CLEANUP, 'socket') as sock, mock.patch.object(CLEANUP.os, 'geteuid', return_value=0):
            sock.gethostname.return_value = 'booking-server'
            with self.assertRaises(CLEANUP.CleanupError) as error:
                CLEANUP.run('execute', digest, self.helper)
        self.assertIn(error.exception.reason, {'plan_identity_changed', 'invalid_release_sidecar'})
        self.assertTrue(os.path.exists(os.path.join(self.root, 'releases', 'old.tar.gz')))

    def test_positive_execute_removes_exactly_one_complete_pair(self):
        self._pair('old')
        plan, _ = self._collect()
        digest = hashlib.sha256(CLEANUP.canonical(plan)).hexdigest()
        with mock.patch.object(CLEANUP, 'socket') as sock, mock.patch.object(CLEANUP.os, 'geteuid', return_value=0):
            sock.gethostname.return_value = 'booking-server'
            result = CLEANUP.run('execute', digest, self.helper)
        self.assertEqual(1, result['deleted_archive_pairs'])
        self.assertEqual(2, result['mutation_counts']['deleted_files'])
        self.assertFalse(os.path.exists(os.path.join(self.root, 'releases', 'old.tar.gz')))
        self.assertFalse(os.path.exists(os.path.join(self.root, 'releases', 'old.build-provenance.json')))

    def test_pending_state_blocks_plan_without_retry(self):
        os.mkdir(os.path.join(self.root, 'state', '.pending-release-' + 'a' * 32))
        with mock.patch.object(CLEANUP, 'socket') as sock, mock.patch.object(CLEANUP.os, 'geteuid', return_value=0):
            sock.gethostname.return_value = 'booking-server'
            with self.assertRaisesRegex(CLEANUP.CleanupError, 'pending_cleanup_unresolved'):
                CLEANUP.run('plan', helper=self.helper)

    def test_release_pair_writer_lock_blocks_plan(self):
        lock = os.open(os.path.join(self.root, 'releases', '.release-pair.lock'), os.O_RDWR)
        try:
            CLEANUP.fcntl.flock(lock, CLEANUP.fcntl.LOCK_EX | CLEANUP.fcntl.LOCK_NB)
            with mock.patch.object(CLEANUP, 'socket') as sock, mock.patch.object(CLEANUP.os, 'geteuid', return_value=0):
                sock.gethostname.return_value = 'booking-server'
                with self.assertRaisesRegex(CLEANUP.CleanupError, 'release_pair_lock_busy'):
                    CLEANUP.run('plan', helper=self.helper)
        finally:
            CLEANUP.fcntl.flock(lock, CLEANUP.fcntl.LOCK_UN)
            os.close(lock)

    def test_archive_inventory_stops_at_entry_limit(self):
        with mock.patch.object(CLEANUP, 'MAX_CLASS_SCAN', 4):
            with self.assertRaisesRegex(CLEANUP.CleanupError, 'archive_scan_limit'):
                self._collect()

    def test_web_inventory_stops_before_unbounded_mount_scan(self):
        self.helper.MAX_CLASS_SCAN = 1
        with mock.patch.object(CLEANUP, 'socket') as sock, mock.patch.object(CLEANUP.os, 'geteuid', return_value=0):
            sock.gethostname.return_value = 'booking-server'
            with self.assertRaisesRegex(CLEANUP.CleanupError, 'web_scan_limit'):
                CLEANUP.run('plan', helper=self.helper)

    def test_protected_pair_drift_blocks_execute(self):
        self._pair('old')
        plan, _ = self._collect()
        digest = hashlib.sha256(CLEANUP.canonical(plan)).hexdigest()
        with open(os.path.join(self.root, 'releases', 'current.tar.gz'), 'ab') as stream:
            stream.write(b'drift')
        with mock.patch.object(CLEANUP, 'socket') as sock, mock.patch.object(CLEANUP.os, 'geteuid', return_value=0):
            sock.gethostname.return_value = 'booking-server'
            with self.assertRaises(Exception):
                CLEANUP.run('execute', digest, self.helper)
        self.assertTrue(os.path.exists(os.path.join(self.root, 'releases', 'old.tar.gz')))

    def test_sidecar_drift_blocks_execute_before_quarantine(self):
        self._pair('old')
        plan, _ = self._collect()
        digest = hashlib.sha256(CLEANUP.canonical(plan)).hexdigest()
        with open(os.path.join(self.root, 'releases', 'old.build-provenance.json'), 'a') as stream:
            stream.write('drift')
        with mock.patch.object(CLEANUP, 'socket') as sock, mock.patch.object(CLEANUP.os, 'geteuid', return_value=0):
            sock.gethostname.return_value = 'booking-server'
            with self.assertRaises(Exception):
                CLEANUP.run('execute', digest, self.helper)
        self.assertTrue(os.path.exists(os.path.join(self.root, 'releases', 'old.tar.gz')))

    def test_mid_hardlink_exception_preserves_pending_and_marks_unknown(self):
        self._pair('old')
        releases = os.open(os.path.join(self.root, 'releases'), os.O_RDONLY | os.O_DIRECTORY)
        state = os.open(os.path.join(self.root, 'state'), os.O_RDONLY | os.O_DIRECTORY)
        self.helper.state_fd = state
        try:
            pair = CLEANUP.pair_identity(self.helper, releases, 'old')
            original_link = os.link
            def link_then_fail(*args, **kwargs):
                original_link(*args, **kwargs)
                raise OSError('simulated post-link failure')
            with mock.patch.object(CLEANUP.os, 'link', side_effect=link_then_fail):
                with self.assertRaises(OSError):
                    CLEANUP._quarantine_file(self.helper, releases, state, 'old.tar.gz',
                                             pair['archive_identity'], 'archive',
                                             pair['archive_sha256'], pair['archive_size_bytes'])
        finally:
            os.close(state); os.close(releases)
        self.assertEqual('unknown', CLEANUP.MUTATIONS.outcome)
        self.assertTrue(any(name.startswith('.pending-archive-archive-') for name in os.listdir(os.path.join(self.root, 'state'))))

    def test_pending_directory_is_synced_before_source_unlink(self):
        self._pair('old')
        releases = os.open(os.path.join(self.root, 'releases'), os.O_RDONLY | os.O_DIRECTORY)
        state = os.open(os.path.join(self.root, 'state'), os.O_RDONLY | os.O_DIRECTORY)
        self.helper.state_fd = state
        events = []
        original_sync, original_unlink = os.fsync, os.unlink

        def record_sync(fd):
            events.append(('sync', fd))
            return original_sync(fd)

        def record_unlink(leaf, *args, **kwargs):
            events.append(('unlink', leaf))
            return original_unlink(leaf, *args, **kwargs)

        try:
            pair = CLEANUP.pair_identity(self.helper, releases, 'old')
            with mock.patch.object(CLEANUP.os, 'fsync', side_effect=record_sync), \
                    mock.patch.object(CLEANUP.os, 'unlink', side_effect=record_unlink):
                CLEANUP._quarantine_file(self.helper, releases, state, 'old.tar.gz',
                                         pair['archive_identity'], 'archive',
                                         pair['archive_sha256'], pair['archive_size_bytes'])
        finally:
            os.close(state); os.close(releases)
        self.assertLess(events.index(('sync', state)), events.index(('unlink', 'old.tar.gz')))
        self.assertLess(events.index(('unlink', 'old.tar.gz')), events.index(('sync', releases)))

    def test_interrupted_pair_leaves_pending_recovery_state(self):
        self._pair('old')
        releases = os.open(os.path.join(self.root, 'releases'), os.O_RDONLY | os.O_DIRECTORY)
        state = os.open(os.path.join(self.root, 'state'), os.O_RDONLY | os.O_DIRECTORY)
        self.helper.state_fd = state
        try:
            pair = CLEANUP.pair_identity(self.helper, releases, 'old')
            original = CLEANUP._quarantine_file
            def interrupted(*args):
                if args[3] == 'old.build-provenance.json':
                    CLEANUP.reject('interrupted_pair')
                return original(*args)
            with mock.patch.object(CLEANUP, '_quarantine_file', side_effect=interrupted):
                with self.assertRaises(CLEANUP.CleanupError):
                    CLEANUP.execute_pair(self.helper, releases, state, {'release_id': 'old', 'pair': pair})
        finally:
            os.close(state); os.close(releases)
        self.assertTrue(any(name.startswith('.pending-archive-archive-') for name in os.listdir(os.path.join(self.root, 'state'))))
        self.assertTrue(os.path.exists(os.path.join(self.root, 'releases', 'old.build-provenance.json')))

    def test_late_open_file_leaves_quarantined_pair_pending(self):
        self._pair('old')
        self.helper.late_open = True
        plan, _ = self._collect()
        digest = hashlib.sha256(CLEANUP.canonical(plan)).hexdigest()
        with mock.patch.object(CLEANUP, 'socket') as sock, mock.patch.object(CLEANUP.os, 'geteuid', return_value=0):
            sock.gethostname.return_value = 'booking-server'
            with self.assertRaisesRegex(CLEANUP.CleanupError, 'quarantined_candidate_open'):
                CLEANUP.run('execute', digest, self.helper)
        self.assertEqual(0, CLEANUP.MUTATIONS.counts['deleted_files'])
        self.assertEqual(2, len([name for name in os.listdir(os.path.join(self.root, 'state'))
                                 if name.startswith('.pending-archive-')]))

    def test_ambiguous_pair_blocks_and_does_not_touch_unrelated_files(self):
        self._pair('old')
        os.unlink(os.path.join(self.root, 'releases', 'old.build-provenance.json'))
        with self.assertRaisesRegex(CLEANUP.CleanupError, 'ambiguous_archive_pair'):
            self._collect()
        with open(os.path.join(self.root, 'releases', 'unrelated.dump'), 'wb') as stream:
            stream.write(b'keep')
        self.assertTrue(os.path.exists(os.path.join(self.root, 'releases', 'unrelated.dump')))

    def test_valid_archive_only_legacy_hold_is_protected(self):
        self._pair('held')
        os.unlink(os.path.join(self.root, 'releases', 'held.build-provenance.json'))
        held_archive = b'held-archive'
        self.helper.holds = {'held': {'sha256': hashlib.sha256(held_archive).hexdigest(),
                                      'size_bytes': len(held_archive)}}
        self._pair('old')
        plan, selected = self._collect()
        self.assertEqual(3, plan['protected_pair_count'])
        self.assertEqual(['old'], [item['release_id'] for item in selected])

    def test_legacy_hold_archive_mismatch_blocks_plan(self):
        self._pair('held')
        os.unlink(os.path.join(self.root, 'releases', 'held.build-provenance.json'))
        self.helper.holds = {'held': {'sha256': '0' * 64, 'size_bytes': len(b'held-archive')}}
        with self.assertRaisesRegex(CLEANUP.CleanupError, 'protected_archive_changed'):
            self._collect()

    def test_coherent_held_archive_change_invalidates_approved_plan(self):
        self._pair('held')
        os.unlink(os.path.join(self.root, 'releases', 'held.build-provenance.json'))
        held_archive = b'held-archive'
        self.helper.holds = {'held': {'sha256': hashlib.sha256(held_archive).hexdigest(),
                                      'size_bytes': len(held_archive)}}
        self._pair('old')
        plan, _ = self._collect()
        digest = hashlib.sha256(CLEANUP.canonical(plan)).hexdigest()
        changed = b'replaced-held-archive'
        with open(os.path.join(self.root, 'releases', 'held.tar.gz'), 'wb') as stream:
            stream.write(changed)
        self.helper.holds = {'held': {'sha256': hashlib.sha256(changed).hexdigest(),
                                      'size_bytes': len(changed)}}
        with mock.patch.object(CLEANUP, 'socket') as sock, mock.patch.object(CLEANUP.os, 'geteuid', return_value=0):
            sock.gethostname.return_value = 'booking-server'
            with self.assertRaisesRegex(CLEANUP.CleanupError, 'plan_identity_changed'):
                CLEANUP.run('execute', digest, self.helper)
        self.assertTrue(os.path.exists(os.path.join(self.root, 'releases', 'old.tar.gz')))

    def test_archive_only_held_active_release_remains_protected(self):
        os.unlink(os.path.join(self.root, 'releases', 'current.build-provenance.json'))
        held_archive = b'current-archive'
        self.helper.holds = {'current': {'sha256': hashlib.sha256(held_archive).hexdigest(),
                                         'size_bytes': len(held_archive)}}
        self._pair('old')
        plan, selected = self._collect()
        self.assertEqual(['old'], [item['release_id'] for item in selected])
        self.assertIn('current', plan['protected_hold_archives'])
        digest = hashlib.sha256(CLEANUP.canonical(plan)).hexdigest()
        with mock.patch.object(CLEANUP, 'socket') as sock, mock.patch.object(CLEANUP.os, 'geteuid', return_value=0):
            sock.gethostname.return_value = 'booking-server'
            result = CLEANUP.run('execute', digest, self.helper)
        self.assertEqual(1, result['deleted_archive_pairs'])
        self.assertTrue(os.path.exists(os.path.join(self.root, 'releases', 'current.tar.gz')))

    def test_complete_held_pair_change_invalidates_approved_plan(self):
        self._pair('held')
        held_archive = b'held-archive'
        self.helper.holds = {'held': {'sha256': hashlib.sha256(held_archive).hexdigest(),
                                      'size_bytes': len(held_archive)}}
        self._pair('old')
        plan, _ = self._collect()
        digest = hashlib.sha256(CLEANUP.canonical(plan)).hexdigest()
        sidecar = os.path.join(self.root, 'releases', 'held.build-provenance.json')
        with open(sidecar, encoding='utf-8') as stream:
            payload = json.load(stream)
        with open(sidecar, 'w', encoding='utf-8') as stream:
            json.dump(payload, stream, indent=2)
        with mock.patch.object(CLEANUP, 'socket') as sock, mock.patch.object(CLEANUP.os, 'geteuid', return_value=0):
            sock.gethostname.return_value = 'booking-server'
            with self.assertRaisesRegex(CLEANUP.CleanupError, 'plan_identity_changed'):
                CLEANUP.run('execute', digest, self.helper)
        self.assertTrue(os.path.exists(os.path.join(self.root, 'releases', 'old.tar.gz')))


class ActualRetentionPrimitiveTest(unittest.TestCase):
    """Exercise the real stable readers and provenance contract without root."""

    def test_complete_pair_is_rechecked_after_hardlink_transition(self):
        with tempfile.TemporaryDirectory() as root:
            releases_path = os.path.join(root, 'releases')
            state_path = os.path.join(root, 'state')
            os.mkdir(releases_path, 0o700)
            os.mkdir(state_path, 0o700)
            release_id = 'test-release'
            archive_data = b'isolated-synthetic-release'
            archive_path = os.path.join(releases_path, release_id + '.tar.gz')
            with open(archive_path, 'wb') as stream:
                stream.write(archive_data)
            os.chmod(archive_path, 0o600)
            sidecar = {
                'archive': {
                    'name': release_id + '.tar.gz',
                    'sha256': hashlib.sha256(archive_data).hexdigest(),
                    'size_bytes': len(archive_data),
                },
                'capacity_bounds': {
                    'stage_file_count': 1, 'stage_inode_count': 1,
                    'stage_unpacked_bytes': 1, 'temp_scratch_bytes': 1,
                },
                'expected_commit': 'a' * 40,
                'observed_commit': 'a' * 40,
                'release_id': release_id,
                'schema': 'release_build_provenance.v1',
                'source': {field: 'b' * 64 for field in (
                    'build_script_sha256', 'composer_lock_sha256',
                    'deploy_ea_sha256', 'package_lock_sha256')},
            }
            sidecar_path = os.path.join(releases_path, release_id + '.build-provenance.json')
            with open(sidecar_path, 'wb') as stream:
                stream.write(RETENTION.canonical_json(sidecar))
            os.chmod(sidecar_path, 0o600)

            class OwnerAdaptedHelper:
                MAX_ARCHIVE_BYTES = RETENTION.MAX_ARCHIVE_BYTES
                MAX_SIDECAR_BYTES = RETENTION.MAX_SIDECAR_BYTES
                file_identity = staticmethod(RETENTION.file_identity)
                validate_provenance = staticmethod(RETENTION.validate_provenance)
                # macOS has no /proc; the late-open guard is covered by the
                # separate synthetic test while these readers remain real.
                open_file_identities = staticmethod(lambda _items: 0)

                @staticmethod
                def stable_hash(directory, leaf, _uid, _gid, modes, maximum):
                    return RETENTION.stable_hash(
                        directory, leaf, os.geteuid(), os.getegid(), modes, maximum)

                @staticmethod
                def stable_regular(directory, leaf, _uid, _gid, modes, maximum):
                    return RETENTION.stable_regular(
                        directory, leaf, os.geteuid(), os.getegid(), modes, maximum)

            releases = os.open(releases_path, os.O_RDONLY | os.O_DIRECTORY)
            state = os.open(state_path, os.O_RDONLY | os.O_DIRECTORY)
            try:
                helper = OwnerAdaptedHelper()
                pair = CLEANUP.pair_identity(helper, releases, release_id)
                CLEANUP.MUTATIONS.reset()
                CLEANUP.execute_pair(helper, releases, state, {'release_id': release_id, 'pair': pair})
            finally:
                os.close(state)
                os.close(releases)
            self.assertFalse(os.listdir(releases_path))
            self.assertFalse(os.listdir(state_path))
            self.assertEqual(2, CLEANUP.MUTATIONS.counts['deleted_files'])


if __name__ == '__main__':
    unittest.main()
