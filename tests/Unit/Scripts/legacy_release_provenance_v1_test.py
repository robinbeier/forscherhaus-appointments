#!/usr/bin/python3
import fcntl
import hashlib
import importlib.util
import io
import json
import os
import pathlib
import shutil
import stat
import subprocess
import sys
import tarfile
import tempfile
import unittest
from unittest import mock


ROOT = pathlib.Path(__file__).resolve().parents[3]


def resolve_python_executable():
    executable = sys.executable or shutil.which('python3')
    if not executable:
        raise RuntimeError('python3 executable is unavailable')
    return executable


def load_module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


legacy = load_module('legacy_release_provenance_v1', ROOT / 'scripts/ops/libexec/legacy_release_provenance_v1.py')
retention = load_module('release_archive_dump_retention_v1', ROOT / 'scripts/ops/libexec/release_archive_dump_retention_v1.py')


class LegacyReleaseProvenanceV1Test(unittest.TestCase):
    def setUp(self):
        self.workspace = pathlib.Path(tempfile.mkdtemp(prefix='rob468-helper-'))
        os.chmod(self.workspace, 0o700)
        self.original_uid = legacy.TRUSTED_UID
        self.original_gid = legacy.TRUSTED_GID
        legacy.TRUSTED_UID = self.workspace.stat().st_uid
        legacy.TRUSTED_GID = self.workspace.stat().st_gid
        self.fixture_index = 0
        self.member_bytes = {
            'build_release.sh': b'build-script\n',
            'composer.lock': b'{"composer":true}\n',
            'deploy_ea.sh': b'#!/usr/bin/env bash\necho deploy\n',
            'package-lock.json': b'{"packages":{}}\n',
        }

    def tearDown(self):
        legacy.TRUSTED_UID = self.original_uid
        legacy.TRUSTED_GID = self.original_gid
        shutil.rmtree(self.workspace)

    def test_preflight_is_read_only_and_sidecars_match_retention_contract(self):
        fixture = self.fixture()
        archive_before = self.archive_identities(fixture)
        context = self.context(fixture)
        try:
            plans = legacy.preflight_targets(context)
            self.assertEqual(['current', 'rollback'], [plan['role'] for plan in plans])
            self.assertTrue(all(plan['existing'] is None for plan in plans))
            self.assertEqual([], list(fixture['releases'].glob('*.build-provenance.json')))
            self.assertEqual(archive_before, self.archive_identities(fixture))
            for plan in plans:
                value = json.loads(plan['data'])
                archive = fixture['releases'] / (plan['release_id'] + '.tar.gz')
                accepted = retention.validate_provenance(
                    plan['data'],
                    plan['release_id'],
                    hashlib.sha256(archive.read_bytes()).hexdigest(),
                    archive.stat().st_size,
                )
                self.assertEqual('release_build_provenance.v1', value['schema'])
                self.assertEqual(value, accepted)
                self.assertEqual(value['expected_commit'], value['observed_commit'])
        finally:
            legacy.close_context(context)

    def test_execute_publishes_both_no_replace_and_replay_is_mutation_free(self):
        fixture = self.fixture()
        archive_before = self.archive_identities(fixture)
        context = self.context(fixture)
        mutations = legacy.MutationLedger()
        try:
            plans = legacy.preflight_targets(context)
            published, attached = legacy.execute_plans(context, plans, mutations)
            self.assertEqual((2, 0), (published, attached))
            self.assertEqual('known', mutations.fields()['mutation_outcome'])
            self.assertEqual(2, mutations.fields()['mutation_counts']['sidecars_published'])
        finally:
            legacy.close_context(context)
        self.assertEqual(archive_before, self.archive_identities(fixture))
        self.assertEqual([], list(fixture['releases'].glob('*.rob468-*.tmp')))
        for sidecar in fixture['releases'].glob('*.build-provenance.json'):
            self.assertEqual(0o600, stat.S_IMODE(sidecar.stat().st_mode))

        replay_context = self.context(fixture)
        replay_mutations = legacy.MutationLedger()
        try:
            replay = legacy.preflight_targets(replay_context)
            self.assertEqual((0, 2), legacy.execute_plans(replay_context, replay, replay_mutations))
            self.assertEqual('none', replay_mutations.fields()['mutation_outcome'])
        finally:
            legacy.close_context(replay_context)

    def test_both_targets_preflight_before_first_mutation(self):
        fixture = self.fixture()
        rollback = fixture['releases'] / 'rollback.tar.gz'
        rollback.write_bytes(b'not-a-tar')
        os.chmod(rollback, 0o600)
        context = self.context(fixture)
        with self.assertRaises(legacy.LegacyProvenanceError):
            legacy.preflight_targets(context)
        legacy.close_context(context)
        self.assertEqual([], list(fixture['releases'].glob('*.build-provenance.json')))
        self.assertEqual([], list(fixture['releases'].glob('*.rob468-*.tmp')))

    def test_authorization_is_canonical_exact_and_mode_bound(self):
        fixture = self.fixture()
        authorization = fixture['etc'] / pathlib.Path(legacy.AUTHORIZATION).name
        value = json.loads(authorization.read_text())
        authorization.write_text(json.dumps(value) + '\n')
        os.chmod(authorization, 0o600)
        context = self.context(fixture)
        with self.assertRaisesRegex(legacy.LegacyProvenanceError, 'invalid_authorization'):
            legacy.preflight_targets(context)
        legacy.close_context(context)

        authorization.write_bytes(legacy.canonical_json(value))
        os.chmod(authorization, 0o644)
        context = self.context(fixture)
        with self.assertRaisesRegex(legacy.LegacyProvenanceError, 'unsafe_file'):
            legacy.preflight_targets(context)
        legacy.close_context(context)

        symlink_fixture = self.fixture()
        symlink_authorization = symlink_fixture['etc'] / pathlib.Path(legacy.AUTHORIZATION).name
        target = symlink_fixture['etc'] / 'authorization-target'
        symlink_authorization.rename(target)
        symlink_authorization.symlink_to(target.name)
        context = self.context(symlink_fixture)
        with self.assertRaisesRegex(legacy.LegacyProvenanceError, 'unsafe_file'):
            legacy.preflight_targets(context)
        legacy.close_context(context)

        owner_fixture = self.fixture()
        context = self.context(owner_fixture)
        expected_uid = legacy.TRUSTED_UID
        legacy.TRUSTED_UID = expected_uid + 1
        try:
            with self.assertRaisesRegex(legacy.LegacyProvenanceError, 'unsafe_file'):
                legacy.preflight_targets(context)
        finally:
            legacy.TRUSTED_UID = expected_uid
            legacy.close_context(context)

    def test_authorization_marker_and_installed_deploy_bindings_fail_closed(self):
        for mutation in ('marker', 'commit', 'deploy'):
            with self.subTest(mutation=mutation):
                fixture = self.fixture()
                authorization = fixture['etc'] / pathlib.Path(legacy.AUTHORIZATION).name
                value = json.loads(authorization.read_text())
                if mutation == 'marker':
                    (fixture['current'] / '_RELEASE').write_text('different\n')
                    os.chmod(fixture['current'] / '_RELEASE', 0o600)
                elif mutation == 'commit':
                    value['targets'][0]['expected_commit'] = 'x' * 40
                    authorization.write_bytes(legacy.canonical_json(value))
                else:
                    (fixture['root'] / 'deploy_ea.sh').write_bytes(b'drifted\n')
                    os.chmod(fixture['root'] / 'deploy_ea.sh', 0o755)
                context = self.context(fixture)
                with self.assertRaises(legacy.LegacyProvenanceError):
                    legacy.preflight_targets(context)
                legacy.close_context(context)

    def test_authorization_binds_complete_archive_digest(self):
        fixture = self.fixture()
        archive = fixture['releases'] / 'current.tar.gz'
        archive.write_bytes(archive.read_bytes() + b'drift')
        os.chmod(archive, 0o600)
        context = self.context(fixture)
        with self.assertRaisesRegex(legacy.LegacyProvenanceError, 'archive_digest_mismatch'):
            legacy.preflight_targets(context)
        legacy.close_context(context)

    def test_tar_traversal_links_devices_duplicates_metadata_and_hash_drift_reject(self):
        cases = (
            'traversal', 'symlink', 'device', 'duplicate', 'missing', 'hash', 'appledouble', 'long_component', 'collision',
        )
        for case in cases:
            with self.subTest(case=case):
                fixture = self.fixture(current_tar_case=case)
                context = self.context(fixture)
                with self.assertRaises(legacy.LegacyProvenanceError):
                    legacy.preflight_targets(context)
                legacy.close_context(context)
                self.assertEqual([], list(fixture['releases'].glob('*.build-provenance.json')))

    def test_nested_member_paths_count_implicit_parent_directories_once(self):
        fixture = self.fixture(current_tar_case='nested')
        context = self.context(fixture)
        plans = legacy.preflight_targets(context)
        archive_value = json.loads(plans[0]['data'])['capacity_bounds']
        self.assertEqual(8, archive_value['stage_inode_count'])
        self.assertEqual(8 * legacy.BLOCK_BYTES, archive_value['stage_unpacked_bytes'])
        legacy.close_context(context)

    def test_conflicting_sidecar_and_unsafe_or_foreign_temps_block_without_archive_change(self):
        for case in ('sidecar', 'unsafe_temp', 'foreign_temp'):
            with self.subTest(case=case):
                fixture = self.fixture()
                archive_before = self.archive_identities(fixture)
                if case == 'sidecar':
                    path = fixture['releases'] / 'current.build-provenance.json'
                elif case == 'unsafe_temp':
                    path = fixture['releases'] / ('.current.build-provenance.json.rob468-' + 'a' * 32 + '.tmp')
                else:
                    path = fixture['releases'] / ('.foreign.build-provenance.json.rob468-' + 'b' * 32 + '.tmp')
                path.write_bytes(b'conflict\n')
                os.chmod(path, 0o600)
                context = self.context(fixture)
                with self.assertRaises(legacy.LegacyProvenanceError):
                    legacy.preflight_targets(context)
                legacy.close_context(context)
                self.assertEqual(archive_before, self.archive_identities(fixture))

    def test_exact_stale_temp_is_reconciled_only_in_execute(self):
        fixture = self.fixture()
        context = self.context(fixture)
        plans = legacy.preflight_targets(context)
        current = plans[0]
        legacy.close_context(context)
        temp = fixture['releases'] / ('.current.build-provenance.json.rob468-' + 'c' * 32 + '.tmp')
        temp.write_bytes(current['data'])
        os.chmod(temp, 0o600)

        inspect_context = self.context(fixture)
        inspected = legacy.preflight_targets(inspect_context)
        self.assertEqual(1, len(inspected[0]['temps']))
        legacy.close_context(inspect_context)
        self.assertTrue(temp.exists(), 'read-only preflight must not clean an owned temp')

        execute_context = self.context(fixture)
        mutations = legacy.MutationLedger()
        executed = legacy.preflight_targets(execute_context)
        self.assertEqual((2, 0), legacy.execute_plans(execute_context, executed, mutations))
        legacy.close_context(execute_context)
        self.assertFalse(temp.exists())
        self.assertEqual('known', mutations.fields()['mutation_outcome'])

    def test_global_and_publication_locks_are_nonblocking_and_orderable(self):
        directory = self.workspace / 'locks'
        directory.mkdir(mode=0o700)
        lock_path = directory / 'lock'
        lock_path.touch(mode=0o600)
        directory_fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
        holder = os.open(lock_path, os.O_RDWR)
        fcntl.flock(holder, fcntl.LOCK_EX | fcntl.LOCK_NB)
        try:
            for reason in ('global_lock_busy', 'publication_lock_busy'):
                with self.subTest(reason=reason):
                    with self.assertRaises(legacy.LegacyProvenanceError) as raised:
                        legacy.open_existing_lock(directory_fd, 'lock', reason)
                    self.assertEqual(75, raised.exception.code)
                    self.assertEqual(reason, raised.exception.reason)
        finally:
            fcntl.flock(holder, fcntl.LOCK_UN)
            os.close(holder)
            os.close(directory_fd)

    def test_crash_boundary_is_unknown_and_confirmed_mutation_is_known(self):
        ledger = legacy.MutationLedger()
        self.assertEqual('none', ledger.fields()['mutation_outcome'])
        ledger.begin()
        self.assertEqual('unknown', ledger.fields()['mutation_outcome'])
        ledger.confirm('temp_files_created')
        self.assertEqual('known', ledger.fields()['mutation_outcome'])

        fixture = self.fixture()
        context = self.context(fixture)
        plans = legacy.preflight_targets(context)
        temp = legacy.create_temp(context['releases'], plans[0], ledger)
        with mock.patch.object(legacy, 'renameat2_noreplace', side_effect=RuntimeError('injected')):
            with self.assertRaises(RuntimeError):
                legacy.exact_attach(context['releases'], plans[0], temp, ledger)
        self.assertEqual('unknown', ledger.fields()['mutation_outcome'])
        legacy.close_context(context)

    def test_directory_fsync_failure_leaves_publication_outcome_unknown(self):
        fixture = self.fixture()
        context = self.context(fixture)
        plans = legacy.preflight_targets(context)
        ledger = legacy.MutationLedger()
        temp = legacy.create_temp(context['releases'], plans[0], ledger)
        real_fsync = legacy.os.fsync

        def fail_directory_fsync(descriptor):
            if descriptor == context['releases']:
                raise OSError('injected directory fsync failure')
            return real_fsync(descriptor)

        with mock.patch.object(legacy.os, 'fsync', side_effect=fail_directory_fsync):
            with self.assertRaises(OSError):
                legacy.exact_attach(context['releases'], plans[0], temp, ledger)
        self.assertEqual('unknown', ledger.fields()['mutation_outcome'])
        self.assertEqual(0, ledger.fields()['mutation_counts']['sidecars_published'])
        legacy.close_context(context)

    def test_failed_new_temp_is_removed_when_write_fails(self):
        fixture = self.fixture()
        context = self.context(fixture)
        plans = legacy.preflight_targets(context)
        ledger = legacy.MutationLedger()
        with mock.patch.object(legacy.os, 'write', side_effect=OSError('injected temp write failure')):
            with self.assertRaises(OSError):
                legacy.create_temp(context['releases'], plans[0], ledger)
        self.assertEqual([], list(fixture['releases'].glob('*.rob468-*.tmp')))
        self.assertEqual('known', ledger.fields()['mutation_outcome'])
        self.assertEqual(1, ledger.fields()['mutation_counts']['temp_files_created'])
        self.assertEqual(1, ledger.fields()['mutation_counts']['temp_files_removed'])
        legacy.close_context(context)

    def test_failed_new_temp_close_is_cleaned_by_exact_created_identity(self):
        fixture = self.fixture()
        context = self.context(fixture)
        plans = legacy.preflight_targets(context)
        ledger = legacy.MutationLedger()
        real_close = legacy.os.close
        close_calls = 0

        def fail_first_close(descriptor):
            nonlocal close_calls
            close_calls += 1
            if close_calls == 1:
                raise OSError('injected temp close failure')
            return real_close(descriptor)

        with mock.patch.object(legacy.os, 'close', side_effect=fail_first_close):
            with self.assertRaises(OSError):
                legacy.create_temp(context['releases'], plans[0], ledger)
        self.assertEqual([], list(fixture['releases'].glob('*.rob468-*.tmp')))
        self.assertEqual('known', ledger.fields()['mutation_outcome'])
        self.assertEqual(1, ledger.fields()['mutation_counts']['temp_files_removed'])
        legacy.close_context(context)

    def test_new_temp_directory_fsync_failure_keeps_unknown_outcome(self):
        fixture = self.fixture()
        context = self.context(fixture)
        plans = legacy.preflight_targets(context)
        ledger = legacy.MutationLedger()
        real_fsync = legacy.os.fsync

        def fail_directory_fsync(descriptor):
            if descriptor == context['releases']:
                raise OSError('injected temp directory fsync failure')
            return real_fsync(descriptor)

        with mock.patch.object(legacy.os, 'fsync', side_effect=fail_directory_fsync):
            with self.assertRaises(OSError):
                legacy.create_temp(context['releases'], plans[0], ledger)
        self.assertEqual([], list(fixture['releases'].glob('*.rob468-*.tmp')))
        self.assertEqual('unknown', ledger.fields()['mutation_outcome'])
        legacy.close_context(context)

    def test_fd_path_swap_and_exact_attach_race_fail_or_attach_without_clobber(self):
        fixture = self.fixture()
        context = self.context(fixture)
        plans = legacy.preflight_targets(context)
        current_archive = fixture['releases'] / 'current.tar.gz'
        replacement = fixture['releases'] / 'replacement.tar.gz'
        shutil.copyfile(current_archive, replacement)
        os.chmod(replacement, 0o600)
        os.replace(replacement, current_archive)
        with self.assertRaisesRegex(legacy.LegacyProvenanceError, 'file_changed'):
            legacy.ensure_file_stable(plans[0]['archive'])
        legacy.close_context(context)

        race_fixture = self.fixture()
        race_context = self.context(race_fixture)
        race_plans = legacy.preflight_targets(race_context)
        mutations = legacy.MutationLedger()
        temp = legacy.create_temp(race_context['releases'], race_plans[0], mutations)
        final = race_fixture['releases'] / race_plans[0]['leaf']
        final.write_bytes(race_plans[0]['data'])
        os.chmod(final, 0o600)
        final_inode = final.stat().st_ino
        self.assertEqual('attached', legacy.exact_attach(
            race_context['releases'], race_plans[0], temp, mutations,
        ))
        self.assertEqual(final_inode, final.stat().st_ino)
        self.assertEqual(race_plans[0]['data'], final.read_bytes())
        legacy.close_context(race_context)

    def test_partial_and_unknown_results_retain_confirmed_aggregate_counts(self):
        ledger = legacy.MutationLedger()
        ledger.begin()
        ledger.confirm('sidecars_published')
        ledger.begin()
        payload = legacy.result_payload('execute', 'blocked', 'internal_error', 2, 1, 0, 0, ledger)
        self.assertEqual('unknown', payload['mutation_outcome'])
        self.assertEqual(1, payload['mutation_counts']['sidecars_published'])
        self.assertEqual(1, payload['targets']['published'])

    def test_output_and_invalid_cli_are_aggregate_only(self):
        with mock.patch.object(sys, 'executable', ''), mock.patch.object(
            shutil, 'which', return_value='/usr/bin/python3',
        ):
            self.assertEqual('/usr/bin/python3', resolve_python_executable())

        python = resolve_python_executable()
        payload = legacy.result_payload(
            'execute', 'blocked', 'installed_deploy_ea_mismatch', 0, 0, 0, 0, legacy.MutationLedger(),
        )
        encoded = legacy.canonical_json(payload).decode()
        for forbidden in ('current.tar.gz', '/root/releases', 'a' * 40, 'b' * 64, 'deploy_ea.sh'):
            self.assertNotIn(forbidden, encoded)
        self.assertEqual('authorization_invalid', payload['reason'])
        self.assertTrue(set(legacy.PUBLIC_REASONS.values()).issubset({
            'archive_invalid',
            'authorization_invalid',
            'host_contract_invalid',
            'internal_error',
            'invalid_arguments',
            'lock_busy',
            'metadata_invalid',
            'publication_blocked',
        }))
        self.assertEqual(
            {'attached', 'pending', 'preflighted', 'published'},
            set(payload['targets']),
        )

        result = subprocess.run(
            [python, str(ROOT / 'scripts/ops/libexec/legacy_release_provenance_v1.py'), '--path', '/tmp/secret'],
            check=False,
            capture_output=True,
            text=True,
        )
        self.assertEqual(64, result.returncode)
        self.assertNotIn('/tmp/secret', result.stdout + result.stderr)
        self.assertEqual('invalid_arguments', json.loads(result.stdout)['reason'])

        for arguments in (['execute'], ['execute', 'ROB-467'], ['execute', 'ROB-468', 'extra']):
            result = subprocess.run(
                [python, str(ROOT / 'scripts/ops/libexec/legacy_release_provenance_v1.py'), *arguments],
                check=False,
                capture_output=True,
                text=True,
            )
            self.assertEqual(64, result.returncode)
            self.assertEqual('invalid_arguments', json.loads(result.stdout)['reason'])

    def fixture(self, current_tar_case='valid'):
        self.fixture_index += 1
        fixture_root = self.workspace / ('fixture-' + str(self.fixture_index))
        fixture_root.mkdir(mode=0o700)
        paths = {}
        for name in ('etc', 'root', 'web', 'releases'):
            path = fixture_root / name
            path.mkdir(mode=0o700)
            paths[name] = path
        paths['current'] = paths['web'] / 'easyappointments'
        paths['rollback'] = paths['web'] / 'easyappointments_prev_current'
        paths['current'].mkdir(mode=0o700)
        paths['rollback'].mkdir(mode=0o700)
        for path, release in ((paths['current'], 'current'), (paths['rollback'], 'rollback')):
            (path / '_RELEASE').write_text(release + '\n')
            os.chmod(path / '_RELEASE', 0o600)
        (paths['root'] / 'deploy_ea.sh').write_bytes(self.member_bytes['deploy_ea.sh'])
        os.chmod(paths['root'] / 'deploy_ea.sh', 0o755)

        target_values = []
        for release, case, commit in (
            ('current', current_tar_case, '1' * 40),
            ('rollback', 'valid', '2' * 40),
        ):
            observed = self.write_archive(paths['releases'] / (release + '.tar.gz'), case)
            required = {name: hashlib.sha256(data).hexdigest() for name, data in self.member_bytes.items()}
            if case == 'hash':
                required['composer.lock'] = 'f' * 64
            target_values.append({
                'archive_sha256': hashlib.sha256((paths['releases'] / (release + '.tar.gz')).read_bytes()).hexdigest(),
                'expected_commit': commit,
                'release_id': release,
                'required_members': required,
                'role': release,
            })
            self.assertGreater(observed, 0)
        authorization = {
            'schema': legacy.AUTHORIZATION_SCHEMA,
            'targets': target_values,
        }
        auth_path = paths['etc'] / pathlib.Path(legacy.AUTHORIZATION).name
        auth_path.write_bytes(legacy.canonical_json(authorization))
        os.chmod(auth_path, 0o600)
        return paths

    def write_archive(self, path, case):
        with tarfile.open(path, 'w:gz') as archive:
            for name, data in self.member_bytes.items():
                if case == 'missing' and name == 'composer.lock':
                    continue
                info = tarfile.TarInfo('./' + name)
                info.mode = 0o644
                info.size = len(data)
                archive.addfile(info, io.BytesIO(data))
                if case == 'duplicate' and name == 'composer.lock':
                    archive.addfile(info, io.BytesIO(data))
            extra = tarfile.TarInfo('./application/')
            extra.type = tarfile.DIRTYPE
            extra.mode = 0o755
            archive.addfile(extra)
            if case == 'traversal':
                info = tarfile.TarInfo('../escape')
                info.size = 1
                archive.addfile(info, io.BytesIO(b'x'))
            elif case == 'symlink':
                info = tarfile.TarInfo('./link')
                info.type = tarfile.SYMTYPE
                info.linkname = '/etc/passwd'
                archive.addfile(info)
            elif case == 'device':
                info = tarfile.TarInfo('./device')
                info.type = tarfile.CHRTYPE
                archive.addfile(info)
            elif case == 'appledouble':
                info = tarfile.TarInfo('./application/._metadata')
                info.size = 1
                archive.addfile(info, io.BytesIO(b'x'))
            elif case == 'long_component':
                info = tarfile.TarInfo('./' + 'x' * 256)
                info.size = 1
                archive.addfile(info, io.BytesIO(b'x'))
            elif case == 'nested':
                info = tarfile.TarInfo('./application/nested/file')
                info.size = 1
                archive.addfile(info, io.BytesIO(b'x'))
            elif case == 'collision':
                info = tarfile.TarInfo('./collision')
                info.size = 1
                archive.addfile(info, io.BytesIO(b'x'))
                info = tarfile.TarInfo('./collision/child')
                info.size = 1
                archive.addfile(info, io.BytesIO(b'x'))
        os.chmod(path, 0o600)
        return path.stat().st_size

    def context(self, fixture):
        opened = []
        values = {}
        for key in ('etc', 'root', 'web', 'current', 'releases'):
            descriptor = os.open(fixture[key], os.O_RDONLY | os.O_DIRECTORY)
            opened.append(descriptor)
            values[key] = descriptor
        return {
            'current': values['current'],
            'directories': [],
            'etc_fh': values['etc'],
            'opened': opened,
            'records': [],
            'releases': values['releases'],
            'root': values['root'],
            'web': values['web'],
        }

    def archive_identities(self, fixture):
        return {
            path.name: (path.stat().st_ino, path.stat().st_size, hashlib.sha256(path.read_bytes()).hexdigest())
            for path in fixture['releases'].glob('*.tar.gz')
        }


if __name__ == '__main__':
    unittest.main(verbosity=2)
