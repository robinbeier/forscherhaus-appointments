"""Focused admission and single-invocation regressions for the deploy entry."""

import argparse
import contextlib
import importlib.util
import json
import os
import tempfile
import unittest
from unittest import mock


PATH = os.path.abspath(os.path.join(os.path.dirname(__file__), '../../../scripts/ops/libexec/bound_release_deploy_v1.py'))
SPEC = importlib.util.spec_from_file_location('bound_release_deploy_v1', PATH)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)
ORIGINAL_CONFIG_BINDINGS = MODULE.config_bindings
ORIGINAL_NO_RECOVERY = MODULE.no_recovery
ORIGINAL_RESERVE_GUARD = MODULE.reserve_recovery_guard
ORIGINAL_RETIRE_GUARD = MODULE.retire_recovery_guard
ORIGINAL_LEXISTS = os.path.lexists


def arguments():
    return argparse.Namespace(
        release='ea_checked_candidate', expected_active_release='ea_previous',
        run_id='a' * 32, commit='b' * 40, archive_sha='c' * 64,
        provenance_sha='d' * 64, continuity_sha='e' * 64,
        deploy_sha='f' * 64, pair_helper_sha='1' * 64, backup_helper_sha='2' * 64,
        archive_size=123, provenance_size=45,
    )


class BoundReleaseDeployTest(unittest.TestCase):
    def setUp(self):
        self.stack = contextlib.ExitStack()
        self.addCleanup(self.stack.close)
        self.pair = mock.Mock()
        self.pair.PairAdmissionError = type('PairAdmissionError', (Exception,), {})
        self.backup = mock.Mock()
        self.backup.AdmissionError = type('BackupAdmissionError', (Exception,), {})
        self.backup.admit.return_value = {'dump_path': '/private/synthetic.sql.gz'}
        self.module_sequence = iter((self.pair, self.backup))
        self.stack.enter_context(mock.patch.object(MODULE.os, 'geteuid', return_value=0))
        self.stack.enter_context(mock.patch.object(MODULE.os, 'uname', return_value=argparse.Namespace(nodename='booking-server')))
        self.stack.enter_context(mock.patch.object(MODULE, 'checked_module', side_effect=lambda *_: next(self.module_sequence)))
        self.stack.enter_context(mock.patch.object(MODULE, 'trusted_parent'))
        self.stack.enter_context(mock.patch.object(MODULE, 'bound_hash'))
        self.no_recovery = self.stack.enter_context(mock.patch.object(MODULE, 'no_recovery'))
        self.guard = self.stack.enter_context(mock.patch.object(MODULE, 'reserve_recovery_guard'))
        self.retire_guard = self.stack.enter_context(mock.patch.object(MODULE, 'retire_recovery_guard'))
        self.stack.enter_context(mock.patch.object(MODULE, 'active_release'))
        self.lexists = self.stack.enter_context(mock.patch.object(MODULE.os.path, 'lexists', return_value=False))
        self.stack.enter_context(mock.patch.object(MODULE, 'config_bindings', return_value=tuple(
            (((1, 2), b'\0' * 32) for _ in MODULE.CONFIGS))))
        self.reserve = self.stack.enter_context(mock.patch.object(MODULE, 'reserve_intent'))
        self.receipt = self.stack.enter_context(mock.patch.object(MODULE, 'checked_receipt', return_value={
            'schema': 'deploy_result.v1', 'outcome': 'succeeded', 'exit_code': 0,
        }))
        self.child = self.stack.enter_context(mock.patch.object(MODULE.subprocess, 'run', return_value=argparse.Namespace(returncode=0)))
        self.lock_fd = os.open(os.devnull, os.O_RDONLY)
        self.open_lock = self.stack.enter_context(mock.patch.object(MODULE, 'open_lock', return_value=self.lock_fd))

    def test_verified_inputs_invoke_existing_deploy_once_with_bound_dump(self):
        self.assertEqual(('deployed', 0), MODULE.run(arguments()))
        self.child.assert_called_once()
        command = self.child.call_args.args[0]
        self.assertEqual(MODULE.DEPLOY, command[0])
        self.assertEqual('/private/synthetic.sql.gz', command[command.index('--zero-surprise-dump-file') + 1])
        self.assertEqual(1, self.reserve.call_count)
        self.assertEqual(1, self.receipt.call_count)

    def test_pair_drift_blocks_before_reservation_or_child(self):
        error = self.pair.PairAdmissionError('pair_mismatch')
        error.result_class = 'pair_mismatch'
        self.pair.verify_pair.side_effect = error
        with self.assertRaisesRegex(MODULE.AdmissionError, 'pair_pair_mismatch'):
            MODULE.run(arguments())
        self.reserve.assert_not_called()
        self.child.assert_not_called()

    def test_active_release_cannot_be_redeployed(self):
        same = arguments()
        same.release = same.expected_active_release
        with self.assertRaisesRegex(MODULE.AdmissionError, 'same_release_invalid'):
            MODULE.run(same)
        self.reserve.assert_not_called()
        self.child.assert_not_called()

    def test_occupied_receipt_blocks_before_reservation_or_child(self):
        with mock.patch.object(MODULE.os.path, 'lexists', side_effect=[True]):
            with self.assertRaisesRegex(MODULE.AdmissionError, 'receipt_or_intent_occupied'):
                MODULE.run(arguments())
        self.reserve.assert_not_called()
        self.child.assert_not_called()

    def test_new_run_id_cannot_relaunch_reserved_release(self):
        reserved = set()
        self.reserve.side_effect = lambda path, *_: reserved.add(path)
        with mock.patch.object(MODULE, 'open_lock', side_effect=lambda: os.open(os.devnull, os.O_RDONLY)):
            with mock.patch.object(MODULE, 'checked_module', side_effect=lambda name, *_: self.pair if name == 'release_pair_admission_v1' else self.backup):
                with mock.patch.object(MODULE.os.path, 'lexists', side_effect=lambda path: path in reserved):
                    self.assertEqual(('deployed', 0), MODULE.run(arguments()))
                    second = arguments()
                    second.run_id = '3' * 32
                    with self.assertRaisesRegex(MODULE.AdmissionError, 'receipt_or_intent_occupied'):
                        MODULE.run(second)
        self.reserve.assert_called_once()
        self.assertEqual('/root/fh-deploy-intent-ea_checked_candidate.json', self.reserve.call_args.args[0])
        self.child.assert_called_once()

    def test_receipt_exit_disagreement_is_unknown(self):
        self.receipt.side_effect = MODULE.AdmissionError('result_unknown')
        with self.assertRaisesRegex(MODULE.AdmissionError, 'result_unknown'):
            MODULE.run(arguments())
        self.child.assert_called_once()
        self.reserve.assert_called_once()

    def test_uncertain_rollback_keeps_global_guard_and_blocks_next_release(self):
        with tempfile.TemporaryDirectory() as directory:
            guard = os.path.join(directory, 'recovery-pending.json')
            with mock.patch.object(MODULE, 'RECOVERY_GUARD', guard), \
                    mock.patch.object(MODULE, 'RECOVERY', (guard,)):
                self.guard.side_effect = ORIGINAL_RESERVE_GUARD
                self.no_recovery.side_effect = ORIGINAL_NO_RECOVERY
                self.lexists.side_effect = ORIGINAL_LEXISTS
                self.child.return_value = argparse.Namespace(returncode=32)
                self.receipt.return_value = {
                    'schema': 'deploy_result.v1', 'outcome': 'switch_recovery_required', 'exit_code': 32,
                }
                self.assertEqual(('recovery_required', 32), MODULE.run(arguments()))
                self.assertTrue(os.path.isfile(guard))
                self.retire_guard.assert_not_called()

                blocked = arguments()
                blocked.release = 'ea_second_candidate'
                self.module_sequence = iter((self.pair, self.backup))
                self.open_lock.return_value = os.open(os.devnull, os.O_RDONLY)
                with self.assertRaisesRegex(MODULE.AdmissionError, 'recovery_pending'):
                    MODULE.run(blocked)
                self.child.assert_called_once()

                os.unlink(guard)

    def test_safe_terminal_guard_is_retired_with_exact_payload(self):
        with tempfile.TemporaryDirectory() as directory:
            guard = os.path.join(directory, 'recovery-pending.json')
            with mock.patch.object(MODULE, 'RECOVERY_GUARD', guard):
                ORIGINAL_RESERVE_GUARD(
                    'ea_checked_candidate', 'ea_previous', 'a' * 32,
                    '/root/fh-deploy-intent-ea_checked_candidate.json',
                    '/root/fh-deploy-result-' + 'a' * 32 + '.json',
                )
                self.assertTrue(os.path.isfile(guard))
                payload = json.dumps({
                    'schema': 'bound_release_deploy_recovery_guard.v1',
                    'release': 'ea_checked_candidate',
                    'expected_active_release': 'ea_previous',
                    'run_id': 'a' * 32,
                    'intent_path': '/root/fh-deploy-intent-ea_checked_candidate.json',
                    'result_path': '/root/fh-deploy-result-' + 'a' * 32 + '.json',
                }, sort_keys=True, separators=(',', ':')).encode('ascii') + b'\n'
                observed = MODULE.identity(os.lstat(guard))
                with mock.patch.object(MODULE, 'read_bound_file', return_value=(payload, observed)):
                    ORIGINAL_RETIRE_GUARD(
                        'ea_checked_candidate', 'ea_previous', 'a' * 32,
                        '/root/fh-deploy-intent-ea_checked_candidate.json',
                        '/root/fh-deploy-result-' + 'a' * 32 + '.json',
                    )
                self.assertFalse(os.path.exists(guard))

    def test_safe_terminal_receipts_retire_global_guard(self):
        self.assertEqual(('deployed', 0), MODULE.run(arguments()))
        self.retire_guard.assert_called_once()

        self.retire_guard.reset_mock()
        self.module_sequence = iter((self.pair, self.backup))
        self.open_lock.return_value = os.open(os.devnull, os.O_RDONLY)
        self.receipt.return_value = {
            'schema': 'deploy_result.v1', 'outcome': 'failed_pre_switch', 'exit_code': 30,
        }
        self.assertEqual(('confirmed_failed', 30), MODULE.run(arguments()))
        self.assertEqual(1, self.retire_guard.call_count)

    def test_stale_config_binding_blocks_before_reservation(self):
        old = tuple((((1, 2), b'\0' * 32) for _ in MODULE.CONFIGS))
        new = tuple((((2, 3), b'\0' * 32) for _ in MODULE.CONFIGS))
        with mock.patch.object(MODULE, 'config_bindings', side_effect=[old, new]):
            with self.assertRaisesRegex(MODULE.AdmissionError, 'config_drift'):
                MODULE.run(arguments())
        self.reserve.assert_not_called()
        self.child.assert_not_called()

    def test_config_binding_uses_runtime_primary_gid_instead_of_legacy_33(self):
        observed_gids = []

        def read_bound_file(path, maximum, mode, uid, gid):
            observed_gids.append(gid)
            return b'config', (1, 2)

        runtime = argparse.Namespace(pw_uid=33, pw_gid=44)
        with mock.patch.object(MODULE.pwd, 'getpwnam', return_value=runtime), \
                mock.patch.object(MODULE, 'read_bound_file', side_effect=read_bound_file):
            ORIGINAL_CONFIG_BINDINGS()

        self.assertEqual([44, 0, 0, 0, 0], observed_gids)

    def test_config_binding_fails_closed_when_runtime_user_is_missing_or_root(self):
        with mock.patch.object(MODULE.pwd, 'getpwnam', side_effect=KeyError):
            with self.assertRaisesRegex(MODULE.AdmissionError, 'runtime_user_missing'):
                MODULE.runtime_user_primary_gid()

        root_account = argparse.Namespace(pw_uid=0, pw_gid=0)
        with mock.patch.object(MODULE.pwd, 'getpwnam', return_value=root_account):
            with self.assertRaisesRegex(MODULE.AdmissionError, 'runtime_user_invalid'):
                MODULE.runtime_user_primary_gid()

    def test_interrupted_backup_timer_restore_blocks_admission(self):
        transition_marker = '/var/lib/fh-deploy-orchestrator/backup-timer-transition.v1.json'
        with mock.patch.object(MODULE.os.path, 'lexists', side_effect=lambda path: path == transition_marker):
            with self.assertRaisesRegex(MODULE.AdmissionError, 'recovery_pending'):
                ORIGINAL_NO_RECOVERY()


if __name__ == '__main__':
    unittest.main()
