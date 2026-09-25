"""Bounded read-only recovery inspection regressions for ROB-621."""

import argparse
import contextlib
import importlib.util
import json
import os
import pathlib
import shutil
import stat
import subprocess
import tempfile
import unittest
from unittest import mock


ROOT = pathlib.Path(__file__).resolve().parents[3]
INSPECTOR_PATH = ROOT / 'scripts/ops/libexec/bound_release_recovery_inspect_v1.py'
WRAPPER_PATH = ROOT / 'scripts/ops/prod_inspect_bound_release_recovery.sh'
SPEC = importlib.util.spec_from_file_location('bound_release_recovery_inspect_v1', INSPECTOR_PATH)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)
ORIGINAL_MARKER_RELEASE = MODULE.marker_release


def arguments(mode='recovery'):
    return argparse.Namespace(
        mode=mode, expected_active_release='ea_previous', release='ea_candidate',
        commit='b' * 40, run_id='a' * 32,
        archive_sha='c' * 64, provenance_sha='d' * 64,
        continuity_sha='e' * 64, deploy_sha='f' * 64,
        pair_helper_sha='1' * 64, backup_helper_sha='2' * 64,
        archive_sha256='c' * 64, provenance_sha256='d' * 64,
        continuity_sha256='e' * 64, deploy_sha256='f' * 64,
        pair_helper_sha256='1' * 64, backup_helper_sha256='2' * 64,
    )


def valid_intent(args):
    return {
        'schema': 'bound_release_deploy_intent.v1', 'release': args.release,
        'commit': args.commit, 'run_id': args.run_id, 'bindings': {
            **{field: getattr(args, field) for field in MODULE.BOUND_HASH_FIELDS},
            'config': [
                {'path': path, 'identity': [1] * 9, 'sha256': '3' * 64}
                for path in MODULE.CONFIG_PATHS
            ],
        },
    }


def valid_guard(args):
    return {
        'schema': 'bound_release_deploy_recovery_guard.v1',
        'release': args.release, 'expected_active_release': args.expected_active_release,
        'run_id': args.run_id,
        'intent_path': '/root/fh-deploy-intent-' + args.release + '.json',
        'result_path': '/root/fh-deploy-result-' + args.run_id + '.json',
    }


class BoundReleaseRecoveryInspectTest(unittest.TestCase):
    def setUp(self):
        self.stack = contextlib.ExitStack()
        self.addCleanup(self.stack.close)
        self.temp = self.stack.enter_context(tempfile.TemporaryDirectory())
        self.root = pathlib.Path(self.temp)
        self.lock = self.root / 'change.lock'
        self.lock.touch()
        os.chmod(self.lock, 0o600)
        self.guard = self.root / 'recovery-pending.json'
        self.marker = self.root / 'RELEASE'
        self.args = arguments()
        self.stack.enter_context(mock.patch.object(MODULE.os, 'geteuid', return_value=0))
        self.stack.enter_context(mock.patch.object(
            MODULE.os, 'uname', return_value=argparse.Namespace(nodename='booking-server')))
        self.stack.enter_context(mock.patch.object(MODULE, 'trusted_parent'))
        self.stack.enter_context(mock.patch.object(MODULE, 'LOCK', str(self.lock)))
        self.stack.enter_context(mock.patch.object(MODULE, 'GUARD', str(self.guard)))
        self.stack.enter_context(mock.patch.object(MODULE, 'MARKER', str(self.marker)))
        self.marker_patch = self.stack.enter_context(mock.patch.object(
            MODULE, 'marker_release', return_value=('ea_candidate', (1, 2, 0, 0, 0, 1, 1, 1, 1))))

    def open_test_lock(self):
        return os.open(self.lock, os.O_RDONLY)

    def marker_bytes(self, release='ea_previous'):
        self.marker.write_bytes((release + '  2026-09-25T10:00:00Z\n').encode())

    def test_no_guard_uses_lock_checks_marker_and_creates_no_state(self):
        self.marker_bytes()
        self.marker_patch.return_value = ('ea_previous', (1, 2, 0, 0, 0, 1, 1, 1, 1))
        before = set(self.root.iterdir())
        args = arguments('no-guard')
        for field in ('release', 'commit', 'run_id', *MODULE.BOUND_HASH_FIELDS):
            setattr(args, field, None)
        with mock.patch.object(MODULE, 'open_lock', side_effect=self.open_test_lock), \
                mock.patch.object(MODULE, 'still_same'):
            self.assertEqual(('no_pending_guard', 0), MODULE.inspect(args))
        self.assertFalse(self.guard.exists())
        self.assertEqual(before, set(self.root.iterdir()))

    def test_present_guard_blocks_no_guard_mode(self):
        self.marker_bytes()
        self.guard.write_text('{}')
        args = arguments('no-guard')
        for field in ('release', 'commit', 'run_id', *MODULE.BOUND_HASH_FIELDS):
            setattr(args, field, None)
        with mock.patch.object(MODULE, 'open_lock', side_effect=self.open_test_lock):
            with self.assertRaisesRegex(MODULE.InspectionError, 'guard_present'):
                MODULE.inspect(args)

    def test_marker_parser_accepts_bound_shape_and_rejects_extra_bytes(self):
        observed = (1, 2, 0, 0, 0, 1, 1, 1, 1)
        with mock.patch.object(MODULE, 'read_bound_file', return_value=(
                b'ea_previous  2026-09-25T10:00:00Z\n', observed)):
            self.assertEqual(('ea_previous', observed), ORIGINAL_MARKER_RELEASE())
        with mock.patch.object(MODULE, 'read_bound_file', return_value=(
                b'ea_previous  2026-09-25T10:00:00Z\nextra', observed)):
            with self.assertRaisesRegex(MODULE.InspectionError, 'marker_invalid'):
                ORIGINAL_MARKER_RELEASE()

    def recovery_reads(self, receipt, marker='ea_candidate'):
        self.marker_patch.return_value = (marker, (1, 2, 0, 0, 0, 1, 1, 1, 1))
        values = {
            str(self.guard): valid_guard(self.args),
            str(self.root / 'intent.json'): valid_intent(self.args),
            str(self.root / 'receipt.json'): receipt,
        }
        # The inspector derives these paths under /root; redirect its reads while
        # keeping the production validators and marker parser in use.
        intent_path = '/root/fh-deploy-intent-' + self.args.release + '.json'
        result_path = '/root/fh-deploy-result-' + self.args.run_id + '.json'
        guard = valid_guard(self.args)
        guard['intent_path'], guard['result_path'] = intent_path, result_path
        values = {MODULE.GUARD: guard, intent_path: valid_intent(self.args), result_path: receipt}

        def read_json(path, *_):
            if path not in values:
                raise AssertionError(path)
            return values[path], (1, 2, 0, 0, 0, 1, 1, 1, 1)

        return mock.patch.object(MODULE, 'read_json', side_effect=read_json)

    def test_recovery_validates_guard_intent_all_six_bindings_receipt_and_marker(self):
        receipt = {'schema': 'deploy_result.v1', 'outcome': 'succeeded', 'exit_code': 0}
        with self.recovery_reads(receipt), \
                mock.patch.object(MODULE, 'open_lock', side_effect=self.open_test_lock), \
                mock.patch.object(MODULE, 'still_same'):
            self.assertEqual(('terminal_deployed', 0), MODULE.inspect(self.args))

        bad = valid_intent(self.args)
        bad['bindings']['backup_helper_sha256'] = '0' * 64
        with self.recovery_reads(receipt) as read_json_patch:
            original_read_json = read_json_patch.side_effect
            def mismatched(path, *args):
                value, observed = original_read_json(path, *args)
                if path.startswith('/root/fh-deploy-intent-'):
                    value = bad
                return value, observed
            read_json_patch.side_effect = mismatched
            with mock.patch.object(MODULE, 'open_lock', side_effect=self.open_test_lock), \
                    mock.patch.object(MODULE, 'still_same'):
                with self.assertRaisesRegex(MODULE.InspectionError, 'binding_mismatch'):
                    MODULE.inspect(self.args)

    def test_terminal_confirmed_failed_requires_expected_active_marker(self):
        receipt = {'schema': 'deploy_result.v1', 'outcome': 'failed_pre_switch', 'exit_code': 30}
        with self.recovery_reads(receipt, marker='ea_previous'), \
                mock.patch.object(MODULE, 'open_lock', side_effect=self.open_test_lock), \
                mock.patch.object(MODULE, 'still_same'):
            self.assertEqual(('terminal_confirmed_failed', 0), MODULE.inspect(self.args))

        with self.recovery_reads(receipt, marker='ea_candidate'), \
                mock.patch.object(MODULE, 'open_lock', side_effect=self.open_test_lock), \
                mock.patch.object(MODULE, 'still_same'):
            with self.assertRaisesRegex(MODULE.InspectionError, 'marker_conflict'):
                MODULE.inspect(self.args)

    def test_exit31_32_and_143_are_each_recovery_required(self):
        outcomes = {
            31: 'rollback_failed_or_unverifiable',
            32: 'switch_recovery_required',
            143: 'interrupted_pre_switch',
        }
        for exit_code, outcome in outcomes.items():
            with self.subTest(exit_code=exit_code):
                receipt = {'schema': 'deploy_result.v1', 'outcome': outcome, 'exit_code': exit_code}
                marker = 'ea_previous' if exit_code == 143 else 'ea_candidate'
                with self.recovery_reads(receipt, marker=marker), \
                        mock.patch.object(MODULE, 'open_lock', side_effect=self.open_test_lock), \
                        mock.patch.object(MODULE, 'still_same'):
                    self.assertEqual(('recovery_required_exit' + str(exit_code), 70), MODULE.inspect(self.args))

    def test_receipt_outcome_and_exit_types_must_be_exact(self):
        for receipt in (
                {'schema': 'deploy_result.v1', 'outcome': 0, 'exit_code': 0},
                {'schema': 'deploy_result.v1', 'outcome': 'succeeded', 'exit_code': True},
                {'schema': 'deploy_result.v1', 'outcome': 'unknown', 'exit_code': 0}):
            with self.subTest(receipt=receipt):
                with self.assertRaisesRegex(MODULE.InspectionError, 'receipt_invalid'):
                    MODULE.validate_receipt(receipt)

    def test_cli_maps_all_six_sha_options_and_emits_inspection_json(self):
        argv = [
            'inspector', '--mode', 'recovery', '--expected-active-release', 'ea_previous',
            '--release', 'ea_candidate', '--commit', 'b' * 40, '--run-id', 'a' * 32,
            '--archive-sha', 'c' * 64, '--provenance-sha', 'd' * 64,
            '--continuity-sha', 'e' * 64, '--deploy-sha', 'f' * 64,
            '--pair-helper-sha', '1' * 64, '--backup-helper-sha', '2' * 64,
        ]
        with mock.patch.object(MODULE.sys, 'argv', argv), \
                mock.patch.object(MODULE, 'inspect', return_value=('terminal_deployed', 0)) as inspect:
            with mock.patch('sys.stdout') as stdout:
                self.assertEqual(0, MODULE.main())
        parsed = inspect.call_args.args[0]
        for field in MODULE.BOUND_HASH_FIELDS:
            self.assertEqual(getattr(self.args, field), getattr(parsed, field))
        output = json.loads(''.join(call.args[0] for call in stdout.write.call_args_list))
        self.assertEqual({'schema': 'bound_release_recovery_inspect.v1', 'status': 'passed',
                          'result_class': 'terminal_deployed'}, output)

    def test_real_temp_files_reject_symlink_and_wrong_mode(self):
        regular = self.root / 'regular.json'
        regular.write_bytes(b'{}')
        os.chmod(regular, 0o644)
        link = self.root / 'link.json'
        link.symlink_to(regular)
        with mock.patch.object(MODULE, 'trusted_parent'):
            with self.assertRaisesRegex(MODULE.InspectionError, 'fixture_unsafe'):
                MODULE.read_bound_file(str(link), 128, 0o600, 'fixture')
            with self.assertRaisesRegex(MODULE.InspectionError, 'fixture_unsafe'):
                MODULE.read_bound_file(str(regular), 128, 0o600, 'fixture')

    def test_duplicate_json_keys_are_rejected_from_real_temp_payload(self):
        payload = self.root / 'duplicate.json'
        payload.write_bytes(b'{"schema":"x","schema":"y"}\n')
        observed = (1, 2, 0, 0, 0, 1, 1, 1, 1)
        with mock.patch.object(MODULE, 'read_bound_file', return_value=(payload.read_bytes(), observed)), \
                mock.patch.object(MODULE, 'trusted_parent'):
            with self.assertRaisesRegex(MODULE.InspectionError, 'fixture_invalid'):
                MODULE.read_json(str(payload), 128, 'fixture')

    def test_real_flock_collision_is_reported_as_lock_busy(self):
        held = os.open(self.lock, os.O_RDONLY)
        import fcntl
        fcntl.flock(held, fcntl.LOCK_EX | fcntl.LOCK_NB)
        self.addCleanup(lambda: (fcntl.flock(held, fcntl.LOCK_UN), os.close(held)))
        original_lstat, original_fstat = MODULE.os.lstat, MODULE.os.fstat

        def root_metadata(value):
            fields = {
                name: getattr(value, name) for name in dir(value)
                if name.startswith('st_') and not name.endswith(('times',))
            }
            fields.update(st_uid=0, st_gid=0)
            return argparse.Namespace(**fields)

        with mock.patch.object(MODULE, 'trusted_parent'), \
                mock.patch.object(MODULE.os, 'lstat', side_effect=lambda path: root_metadata(original_lstat(path))), \
                mock.patch.object(MODULE.os, 'fstat', side_effect=lambda fd: root_metadata(original_fstat(fd))):
            with self.assertRaisesRegex(MODULE.InspectionError, 'lock_busy') as raised:
                MODULE.open_lock()
        self.assertEqual(75, raised.exception.code)

    def test_missing_receipt_after_transport_loss_is_unknown_and_evidence_conflicts_block(self):
        with self.recovery_reads({'schema': 'deploy_result.v1', 'outcome': 'succeeded', 'exit_code': 0}) as read_json_patch:
            original_read_json = read_json_patch.side_effect
            def missing_receipt(path, *args):
                if path.startswith('/root/fh-deploy-result-'):
                    raise MODULE.InspectionError('receipt_missing')
                return original_read_json(path, *args)
            read_json_patch.side_effect = missing_receipt
            with mock.patch.object(MODULE, 'open_lock', side_effect=self.open_test_lock):
                with self.assertRaisesRegex(MODULE.InspectionError, 'receipt_missing'):
                    MODULE.inspect(self.args)

        for result_class in ('guard_mismatch', 'intent_mismatch', 'receipt_invalid', 'marker_conflict'):
            with self.subTest(result_class=result_class):
                if result_class == 'marker_conflict':
                    receipt = {'schema': 'deploy_result.v1', 'outcome': 'succeeded', 'exit_code': 0}
                    marker = 'ea_previous'
                elif result_class == 'receipt_invalid':
                    receipt = {'schema': 'deploy_result.v1', 'outcome': 'succeeded', 'exit_code': 30}
                    marker = 'ea_candidate'
                else:
                    receipt = {'schema': 'deploy_result.v1', 'outcome': 'succeeded', 'exit_code': 0}
                    marker = 'ea_candidate'
                with self.recovery_reads(receipt, marker=marker) as read_json_patch:
                    original_read_json = read_json_patch.side_effect
                    if result_class == 'guard_mismatch':
                        def bad_guard(path, *args):
                            value, observed = original_read_json(path, *args)
                            if path == MODULE.GUARD:
                                value = dict(value, run_id='f' * 32)
                            return value, observed
                        read_json_patch.side_effect = bad_guard
                    elif result_class == 'intent_mismatch':
                        def bad_intent(path, *args):
                            value, observed = original_read_json(path, *args)
                            if path.startswith('/root/fh-deploy-intent-'):
                                value = dict(value, commit='f' * 40)
                            return value, observed
                        read_json_patch.side_effect = bad_intent
                    with mock.patch.object(MODULE, 'open_lock', side_effect=self.open_test_lock), \
                            mock.patch.object(MODULE, 'still_same'):
                        with self.assertRaisesRegex(MODULE.InspectionError, result_class):
                            MODULE.inspect(self.args)

    def test_lock_conflict_is_distinct(self):
        args = arguments('no-guard')
        for field in ('release', 'commit', 'run_id', *MODULE.BOUND_HASH_FIELDS):
            setattr(args, field, None)
        with mock.patch.object(MODULE, 'open_lock', side_effect=MODULE.InspectionError('lock_busy', 75)):
            with self.assertRaisesRegex(MODULE.InspectionError, 'lock_busy') as raised:
                MODULE.inspect(args)
        self.assertEqual(75, raised.exception.code)


class RecoveryWrapperTransportTest(unittest.TestCase):
    def test_transport_loss_blocks_without_deploy_ack_or_retry(self):
        with tempfile.TemporaryDirectory() as directory:
            repo = pathlib.Path(directory)
            (repo / 'scripts/ops').mkdir(parents=True)
            (repo / 'scripts/ops/libexec').mkdir()
            shutil.copy2(WRAPPER_PATH, repo / 'scripts/ops/prod_inspect_bound_release_recovery.sh')
            (repo / 'scripts/ops/prod_inspect_bound_release_recovery.sh').chmod(0o755)
            shutil.copy2(INSPECTOR_PATH, repo / 'scripts/ops/libexec/bound_release_recovery_inspect_v1.py')
            subprocess.run(['git', 'init', '-q', '-b', 'main'], cwd=repo, check=True)
            subprocess.run(['git', 'config', 'user.email', 'test@example.invalid'], cwd=repo, check=True)
            subprocess.run(['git', 'config', 'user.name', 'Test'], cwd=repo, check=True)
            subprocess.run(['git', 'add', '.'], cwd=repo, check=True)
            subprocess.run(['git', 'commit', '-qm', 'fixture'], cwd=repo, check=True)
            source_commit = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=repo, text=True).strip()
            fake_bin = repo / 'bin'
            fake_bin.mkdir()
            log = repo / 'ssh.log'
            fake_ssh = fake_bin / 'ssh'
            fake_ssh.write_text('#!/bin/sh\nprintf "%s\\n" "$*" >> "$SSH_LOG"\nexit 143\n')
            fake_ssh.chmod(0o755)
            env = dict(os.environ, PATH=str(fake_bin) + ':' + os.environ['PATH'], SSH_LOG=str(log))
            command = [str(repo / 'scripts/ops/prod_inspect_bound_release_recovery.sh'),
                       '--source-commit', source_commit, '--mode', 'recovery',
                       '--expected-active-release', 'ea_previous', '--release', 'ea_candidate',
                       '--commit', 'b' * 40, '--run-id', 'a' * 32,
                       '--archive-sha', 'c' * 64, '--provenance-sha', 'd' * 64,
                       '--continuity-sha', 'e' * 64, '--deploy-sha', 'f' * 64,
                       '--pair-helper-sha', '1' * 64, '--backup-helper-sha', '2' * 64,
                       '--run-read-only', '--confirm-read-only', 'ROB-621']
            completed = subprocess.run(command, cwd=repo, env=env, text=True,
                                       stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            self.assertEqual(70, completed.returncode)
            self.assertIn('status=blocked', completed.stdout)
            self.assertIn('result_class=transport_or_receipt_unknown', completed.stdout)
            invocation = log.read_text()
            self.assertIn('--mode recovery', invocation)
            self.assertNotRegex(invocation, r'\s(deploy|ack|retry)(\s|$)')


if __name__ == '__main__':
    unittest.main()
