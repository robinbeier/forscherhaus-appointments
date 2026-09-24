"""Focused admission and single-invocation regressions for the deploy entry."""

import argparse
import contextlib
import importlib.util
import os
import unittest
from unittest import mock


PATH = os.path.abspath(os.path.join(os.path.dirname(__file__), '../../../scripts/ops/libexec/bound_release_deploy_v1.py'))
SPEC = importlib.util.spec_from_file_location('bound_release_deploy_v1', PATH)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


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
        self.stack.enter_context(mock.patch.object(MODULE, 'no_recovery'))
        self.stack.enter_context(mock.patch.object(MODULE, 'active_release'))
        self.stack.enter_context(mock.patch.object(MODULE.os.path, 'lexists', return_value=False))
        self.stack.enter_context(mock.patch.object(MODULE, 'config_bindings', return_value=tuple(
            (((1, 2), b'\0' * 32) for _ in MODULE.CONFIGS))))
        self.reserve = self.stack.enter_context(mock.patch.object(MODULE, 'reserve_intent'))
        self.receipt = self.stack.enter_context(mock.patch.object(MODULE, 'checked_receipt', return_value={
            'schema': 'deploy_result.v1', 'outcome': 'succeeded', 'exit_code': 0,
        }))
        self.child = self.stack.enter_context(mock.patch.object(MODULE.subprocess, 'run', return_value=argparse.Namespace(returncode=0)))
        self.lock_fd = os.open(os.devnull, os.O_RDONLY)
        self.stack.enter_context(mock.patch.object(MODULE, 'open_lock', return_value=self.lock_fd))

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

    def test_stale_config_binding_blocks_before_reservation(self):
        old = tuple((((1, 2), b'\0' * 32) for _ in MODULE.CONFIGS))
        new = tuple((((2, 3), b'\0' * 32) for _ in MODULE.CONFIGS))
        with mock.patch.object(MODULE, 'config_bindings', side_effect=[old, new]):
            with self.assertRaisesRegex(MODULE.AdmissionError, 'config_drift'):
                MODULE.run(arguments())
        self.reserve.assert_not_called()
        self.child.assert_not_called()


if __name__ == '__main__':
    unittest.main()
