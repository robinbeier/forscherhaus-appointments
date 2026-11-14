import importlib.util
import os
import tempfile
import unittest
from unittest.mock import patch
from pathlib import Path

SPEC = importlib.util.spec_from_file_location('helper_sync_v1', Path(__file__).parents[3] / 'scripts/ops/libexec/helper_sync_v1.py')
mod = importlib.util.module_from_spec(SPEC); SPEC.loader.exec_module(mod)

class HelperSyncV1Test(unittest.TestCase):
    def setUp(self):
        self.real_euid = mod.os.geteuid; mod.os.geteuid = lambda: 0
        self.tmp = tempfile.TemporaryDirectory(dir='/root' if self.real_euid() == 0 else None)
        root = Path(self.tmp.name)
        self.target, self.candidate = root/'deploy_ea.sh', root/'candidate'
        self.lock, self.tx = root/'lock', root/'tx'
        self.lock.parent.mkdir(exist_ok=True); self.tx.mkdir(mode=0o700); self.lock.touch()
        os.chmod(self.lock, 0o600)
        self.target.write_text('old\n'); self.candidate.write_text('new\n')
        os.chmod(self.target, 0o700); os.chmod(self.candidate, 0o700)
        mod.TARGET, mod.CANDIDATE, mod.LOCK, mod.TX_ROOT = self.target, self.candidate, self.lock, self.tx
        mod.PENDING = self.tx/'pending.json'
        mod.OLD_SHA256, mod.OLD_SIZE = mod.sha256(self.target), self.target.stat().st_size
        mod.NEW_SHA256, mod.NEW_SIZE = mod.sha256(self.candidate), self.candidate.stat().st_size
        self.canary_units = patch.object(mod, 'check_canary_cleanup_units_clear', return_value=None)
        self.canary_units.start()

    def tearDown(self):
        self.canary_units.stop()
        mod.os.geteuid = self.real_euid
        self.tmp.cleanup()

    @unittest.skipUnless(os.geteuid() == 0, 'requires root-owned fixture')
    def test_postflight_failure_restores_old_and_clears_pending(self):
        def fail_after_replacement():
            self.assertEqual(self.target.read_text(), 'new\n')
            self.assertTrue(mod.PENDING.exists())
            raise mod.SyncError('synthetic_postflight_failure')

        with patch.object(mod, 'internal_postflight', side_effect=fail_after_replacement):
            with self.assertRaisesRegex(mod.SyncError, 'synthetic_postflight_failure'):
                mod.execute(mod.sha256(self.candidate), self.candidate.stat().st_size, 'a' * 32)
        self.assertEqual(self.target.read_text(), 'old\n')
        self.assertFalse(mod.PENDING.exists())
        self.assertTrue((self.tx / ('a' * 32) / 'rolled-back.json').is_file())

    @unittest.skipUnless(os.geteuid() == 0, 'requires root-owned fixture')
    def test_execute_then_ack_is_run_bound_and_durable(self):
        run_id = 'b' * 32
        with patch.object(mod, 'internal_postflight', return_value=None) as health:
            result = mod.execute(mod.sha256(self.candidate), self.candidate.stat().st_size, run_id)
            self.assertEqual(result['status'], 'committed_pending_ack')
            self.assertEqual(result['run_id'], run_id)
            self.assertEqual(self.target.read_text(), 'new\n')
            self.assertTrue(mod.PENDING.exists())
            self.assertTrue((self.tx / run_id / 'committed.json').is_file())
            self.assertEqual(mod.inspect_ack(run_id)['status'], 'acknowledged')
            self.assertFalse(mod.PENDING.exists())
            self.assertTrue((self.tx / run_id / 'acknowledged.json').is_file())
            self.assertEqual(mod.inspect_ack(run_id)['status'], 'acknowledged')
            self.assertEqual(health.call_count, 2)

    @unittest.skipUnless(os.geteuid() == 0, 'requires root-owned fixture')
    def test_foreign_recovery_blocks_before_pending_rollback(self):
        with patch.object(mod, 'internal_postflight', side_effect=SystemExit(99)):
            with self.assertRaises(SystemExit):
                mod.execute(mod.sha256(self.candidate), self.candidate.stat().st_size, 'c' * 32)
        self.assertEqual(self.target.read_text(), 'new\n')
        self.assertTrue(mod.PENDING.exists())
        marker = Path(self.tmp.name) / 'foreign-recovery'
        marker.write_text('pending\n')
        with patch.object(mod, 'FOREIGN_MARKERS', (marker,)):
            with self.assertRaisesRegex(mod.SyncError, 'foreign_recovery_marker'):
                mod.execute(mod.sha256(self.candidate), self.candidate.stat().st_size, 'd' * 32)
        self.assertEqual(self.target.read_text(), 'new\n')
        self.assertTrue(mod.PENDING.exists())

    @unittest.skipUnless(os.geteuid() == 0, 'requires root-owned fixture')
    def test_partial_restore_staging_is_rebuilt_during_recovery(self):
        with patch.object(mod, 'internal_postflight', side_effect=SystemExit(99)):
            with self.assertRaises(SystemExit):
                mod.execute(mod.sha256(self.candidate), self.candidate.stat().st_size, 'e' * 32)
        partial = self.tx / ('e' * 32) / 'restore-helper'
        partial.write_text('partial\n')
        partial.chmod(0o700)
        lock_fd = mod.open_lock()
        try:
            mod.recover_pending()
        finally:
            os.close(lock_fd)
        self.assertEqual(self.target.read_text(), 'old\n')
        self.assertFalse(mod.PENDING.exists())
        self.assertTrue((self.tx / ('e' * 32) / 'rolled-back.json').is_file())

    @unittest.skipUnless(os.geteuid() == 0, 'requires root-owned fixture')
    def test_recovery_after_restored_predecessor_preserves_rollback_phase(self):
        run_id = '2' * 32
        with patch.object(mod, 'internal_postflight', side_effect=SystemExit(99)):
            with self.assertRaises(SystemExit):
                mod.execute(mod.sha256(self.candidate), self.candidate.stat().st_size, run_id)
        tx = self.tx / run_id
        mod.ensure_run_receipt(tx, run_id, 'rollback-started.json', 'rollback_started')
        mod.restore_copy(tx / 'old-helper', tx / 'restore-helper', 0o700)
        os.replace(tx / 'restore-helper', self.target)
        lock_fd = mod.open_lock()
        try:
            mod.recover_pending()
        finally:
            os.close(lock_fd)
        self.assertEqual(self.target.read_text(), 'old\n')
        self.assertFalse(mod.PENDING.exists())
        self.assertTrue((tx / 'rolled-back.json').is_file())
        self.assertFalse((tx / 'aborted-before-replace.json').exists())

    @unittest.skipUnless(os.geteuid() == 0, 'requires root-owned fixture')
    def test_recovery_after_rollback_receipt_does_not_claim_no_replacement(self):
        run_id = '3' * 32
        with patch.object(mod, 'internal_postflight', side_effect=SystemExit(99)):
            with self.assertRaises(SystemExit):
                mod.execute(mod.sha256(self.candidate), self.candidate.stat().st_size, run_id)
        tx = self.tx / run_id
        mod.ensure_run_receipt(tx, run_id, 'rollback-started.json', 'rollback_started')
        mod.restore_copy(tx / 'old-helper', tx / 'restore-helper', 0o700)
        os.replace(tx / 'restore-helper', self.target)
        mod.ensure_run_receipt(tx, run_id, 'rolled-back.json', 'rolled_back')
        lock_fd = mod.open_lock()
        try:
            mod.recover_pending()
        finally:
            os.close(lock_fd)
        self.assertEqual(self.target.read_text(), 'old\n')
        self.assertFalse(mod.PENDING.exists())
        self.assertTrue((tx / 'rolled-back.json').is_file())
        self.assertFalse((tx / 'aborted-before-replace.json').exists())

    @unittest.skipUnless(os.geteuid() == 0, 'requires root-owned fixture')
    def test_conflicting_abort_receipt_blocks_pending_recovery(self):
        run_id = '4' * 32
        with patch.object(mod, 'internal_postflight', side_effect=SystemExit(99)):
            with self.assertRaises(SystemExit):
                mod.execute(mod.sha256(self.candidate), self.candidate.stat().st_size, run_id)
        tx = self.tx / run_id
        mod.ensure_run_receipt(tx, run_id, 'aborted-before-replace.json', 'aborted_before_replace')
        lock_fd = mod.open_lock()
        try:
            with self.assertRaisesRegex(mod.SyncError, 'rollback_receipt_conflict'):
                mod.recover_pending()
        finally:
            os.close(lock_fd)
        self.assertEqual(self.target.read_text(), 'new\n')
        self.assertTrue(mod.PENDING.exists())

    @unittest.skipUnless(os.geteuid() == 0, 'requires root-owned fixture')
    def test_busy_canonical_lock_refuses_second_writer(self):
        lock_fd = mod.open_lock()
        try:
            with self.assertRaisesRegex(mod.SyncError, 'lock_busy'):
                mod.open_lock()
        finally:
            os.close(lock_fd)

    @unittest.skipUnless(os.geteuid() == 0, 'requires root-owned fixture')
    def test_corrupt_pending_keeps_changed_target_and_marker(self):
        run_id = '5' * 32
        with patch.object(mod, 'internal_postflight', side_effect=SystemExit(99)):
            with self.assertRaises(SystemExit):
                mod.execute(mod.sha256(self.candidate), self.candidate.stat().st_size, run_id)
        mod.PENDING.write_text('{invalid-json')
        lock_fd = mod.open_lock()
        try:
            with self.assertRaisesRegex(mod.SyncError, 'pending_state_unreadable'):
                mod.recover_pending()
        finally:
            os.close(lock_fd)
        self.assertEqual(self.target.read_text(), 'new\n')
        self.assertTrue(mod.PENDING.exists())

    @unittest.skipUnless(os.geteuid() == 0, 'requires root-owned fixture')
    def test_ack_refuses_invalid_lock_without_retiring_pending(self):
        run_id = 'f' * 32
        with patch.object(mod, 'internal_postflight', return_value=None):
            mod.execute(mod.sha256(self.candidate), self.candidate.stat().st_size, run_id)
        self.lock.chmod(0o644)
        with self.assertRaisesRegex(mod.SyncError, 'lock_identity_invalid'):
            mod.inspect_ack(run_id)
        self.assertTrue(mod.PENDING.exists())

    @unittest.skipUnless(os.geteuid() == 0, 'requires root-owned fixture')
    def test_repeated_ack_refuses_target_metadata_drift(self):
        run_id = '1' * 32
        with patch.object(mod, 'internal_postflight', return_value=None):
            mod.execute(mod.sha256(self.candidate), self.candidate.stat().st_size, run_id)
            mod.inspect_ack(run_id)
        self.target.chmod(0o644)
        with self.assertRaisesRegex(mod.SyncError, 'pending_missing'):
            mod.inspect_ack(run_id)

    def test_symlink_target_rejected(self):
        self.target.unlink(); self.target.symlink_to(self.candidate)
        with self.assertRaises(mod.SyncError): mod.execute(mod.sha256(self.candidate), self.candidate.stat().st_size, 'a' * 32)

    def test_size_is_integer_cli(self):
        parser = __import__('argparse').ArgumentParser(); parser.add_argument('--candidate-size', type=int, required=True)
        self.assertEqual(parser.parse_args(['--candidate-size', '4']).candidate_size, 4)

    def test_caller_run_id_is_exact_transaction_correlation(self):
        run_id = 'a' * 32
        self.assertEqual(mod.transaction_path(run_id), mod.TX_ROOT / run_id)
        with self.assertRaises(mod.SyncError): mod.transaction_path('b' * 31)

    def test_cli_ack_does_not_require_execute_arguments(self):
        self.assertEqual(mod.main(['--ack-run-id', 'a' * 32]), 20)

    @patch.object(mod.Path, 'read_text', return_value='token')
    @patch.object(mod.subprocess, 'run')
    def test_internal_postflight_is_bound_to_fixed_checks(self, run, _read):
        def result(args, **kwargs):
            value = type('Result', (), {})(); value.returncode = 0
            if args[0].endswith('sqlite3'): value.stdout = b'0'
            elif any('monitor.dasforscherhaus-leg.de' in str(item) for item in args): value.stdout = b'302'
            else: value.stdout = b'200'
            return value
        run.side_effect = result
        mod.internal_postflight()
        calls = [call.args[0] for call in run.call_args_list]
        self.assertEqual([c[:3] for c in calls[:4]], [['/usr/bin/systemctl','is-active','--quiet']]*4)
        self.assertEqual(calls[-1][0:2], ['/usr/bin/sqlite3','/var/lib/uptime-kuma-data/kuma.db'])
        curl_codes = [call.args[0][call.args[0].index('--write-out') + 1] for call in run.call_args_list if call.args[0][0].endswith('curl')]
        self.assertIn('%{http_code}', curl_codes)

    @patch.object(mod.Path, 'read_text', return_value='token')
    @patch.object(mod.subprocess, 'run')
    def test_internal_postflight_fails_closed(self, run, _read):
        run.return_value.returncode = 1
        with self.assertRaises(mod.SyncError): mod.internal_postflight()

    @unittest.skipUnless(os.geteuid() == 0, 'requires root-owned fixture')
    def test_transaction_root_rejects_foreign_content(self):
        foreign = Path(self.tmp.name) / 'foreign'; foreign.mkdir()
        (foreign / 'unexpected').write_text('x')
        with self.assertRaises(mod.SyncError): mod.ensure_tx_root(foreign)

if __name__ == '__main__': unittest.main()
