"""Focused admission and single-invocation regressions for the deploy entry."""

import argparse
import contextlib
import fcntl
import importlib.util
import json
import os
import subprocess
import sys
import tempfile
import unittest
from unittest import mock


PATH = os.path.abspath(os.path.join(os.path.dirname(__file__), '../../../scripts/ops/libexec/bound_release_deploy_v1.py'))
WRAPPER_PATH = os.path.abspath(os.path.join(os.path.dirname(__file__), '../../../scripts/ops/prod_deploy_bound_release.sh'))
SPEC = importlib.util.spec_from_file_location('bound_release_deploy_v1', PATH)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)
ORIGINAL_CONFIG_BINDINGS = MODULE.config_bindings
ORIGINAL_ADMIT_MAINTENANCE = MODULE.admit_maintenance
ORIGINAL_OPEN_LOCK = MODULE.open_lock
REAL_EUID = os.geteuid()
REAL_SUBPROCESS_RUN = subprocess.run
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
        self.admit = self.stack.enter_context(mock.patch.object(MODULE, 'admit_maintenance'))
        self.stack.enter_context(mock.patch.object(MODULE, 'trusted_parent'))
        self.stack.enter_context(mock.patch.object(MODULE, 'bound_hash'))
        self.no_recovery = self.stack.enter_context(mock.patch.object(MODULE, 'no_recovery'))
        self.systemctl = self.stack.enter_context(mock.patch.object(
            MODULE.subprocess,
            'check_output',
            return_value='LoadState=not-found\nActiveState=inactive\nSubState=dead\nUnitFileState=\nResult=success\n',
        ))
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
        self.admit.assert_called_once_with(self.lock_fd)
        self.child.assert_called_once()
        command = self.child.call_args.args[0]
        self.assertEqual(MODULE.DEPLOY, command[0])
        self.assertEqual('/private/synthetic.sql.gz', command[command.index('--zero-surprise-dump-file') + 1])
        self.assertEqual(arguments().run_id, self.child.call_args.kwargs['env']['BOUND_RELEASE_RUN_ID'])
        self.assertEqual(1, self.reserve.call_count)
        self.assertEqual(1, self.receipt.call_count)

    def test_pending_admission_refuses_before_reservation_or_child(self):
        self.admit.side_effect = MODULE.AdmissionError('maintenance_pending_present', 75)
        with self.assertRaisesRegex(MODULE.AdmissionError, 'maintenance_pending_present'):
            MODULE.run(arguments())
        self.reserve.assert_not_called()
        self.guard.assert_not_called()
        self.child.assert_not_called()

    def test_missing_admission_core_maps_to_stable_result_class(self):
        MODULE.bound_hash.side_effect = FileNotFoundError('/usr/local/libexec/fh/maintenance_pending_v1.py')
        with self.assertRaisesRegex(MODULE.AdmissionError, 'maintenance_core_invalid'):
            ORIGINAL_ADMIT_MAINTENANCE(self.lock_fd)

    def test_admission_core_binding_is_fixed_and_precedes_reservation(self):
        with open(PATH, encoding='utf-8') as handle:
            source = handle.read()
        self.assertIn("ADMISSION_CORE = '/usr/local/libexec/fh/maintenance_pending_v1.py'", source)
        self.assertIn(
            "ADMISSION_CORE_SHA256 = '13e20fc733060cf7ec28a80ca40fc8e19a7b2da74bc76a34ffaba00af4839267'",
            source,
        )
        run = source[source.index('def run(args):'):source.index('def main():')]
        self.assertLess(run.index('admit_maintenance(lock_fd)'), run.index('reserve_intent(intent_path'))

    def test_open_lock_returns_read_write_descriptor_for_durable_admission(self):
        if REAL_EUID != 0:
            self.skipTest('root is required for the root-owned lock contract')
        with tempfile.TemporaryDirectory() as directory:
            lock = os.path.join(directory, 'shared.lock')
            with open(lock, 'wb'):
                pass
            os.chmod(lock, 0o600)
            with mock.patch.object(MODULE, 'LOCK', lock), \
                    mock.patch.object(MODULE, 'trusted_parent'):
                fd = ORIGINAL_OPEN_LOCK()
            try:
                self.assertEqual(os.O_RDWR, os.O_ACCMODE & fcntl.fcntl(fd, fcntl.F_GETFL))
            finally:
                os.close(fd)

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

    def test_late_canary_journal_blocks_admission_before_reservation_or_child(self):
        appeared = {'value': False}

        def earlier_preflight_then_state_appears():
            appeared['value'] = True

        self.no_recovery.side_effect = earlier_preflight_then_state_appears
        self.lexists.side_effect = lambda path: appeared['value'] and path == MODULE.CANARY_STATE
        with self.assertRaisesRegex(MODULE.AdmissionError, 'canary_recovery_pending'):
            MODULE.run(arguments())
        self.reserve.assert_not_called()
        self.guard.assert_not_called()
        self.child.assert_not_called()

    def test_loaded_canary_cleanup_unit_blocks_admission(self):
        self.systemctl.return_value = (
            'LoadState=loaded\nActiveState=inactive\nSubState=dead\n'
            'UnitFileState=disabled\nResult=success\n'
        )
        with self.assertRaisesRegex(MODULE.AdmissionError, 'canary_cleanup_unresolved'):
            MODULE.run(arguments())
        self.reserve.assert_not_called()
        self.guard.assert_not_called()
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

    def test_safe_terminal_receipts_retain_global_guard_until_acknowledged(self):
        self.assertEqual(('deployed', 0), MODULE.run(arguments()))
        self.retire_guard.assert_not_called()

        self.module_sequence = iter((self.pair, self.backup))
        self.open_lock.return_value = os.open(os.devnull, os.O_RDONLY)
        self.receipt.return_value = {
            'schema': 'deploy_result.v1', 'outcome': 'failed_pre_switch', 'exit_code': 30,
        }
        self.assertEqual(('confirmed_failed', 30), MODULE.run(arguments()))
        self.retire_guard.assert_not_called()

    def test_acknowledgement_revalidates_exact_guard_intent_result_and_active_marker(self):
        args = arguments()
        observed = (1, 2, 0, 0, 0, 1, 1, 1, 1)
        intent_path = '/root/fh-deploy-intent-' + args.release + '.json'
        result_path = '/root/fh-deploy-result-' + args.run_id + '.json'
        guard_path = MODULE.RECOVERY_GUARD
        guard = {
            'schema': 'bound_release_deploy_recovery_guard.v1',
            'release': args.release,
            'expected_active_release': args.expected_active_release,
            'run_id': args.run_id,
            'intent_path': intent_path,
            'result_path': result_path,
        }
        intent = {
            'schema': 'bound_release_deploy_intent.v1',
            'release': args.release,
            'commit': args.commit,
            'run_id': args.run_id,
            'bindings': {
                'archive_sha256': args.archive_sha,
                'provenance_sha256': args.provenance_sha,
                'continuity_sha256': args.continuity_sha,
                'deploy_sha256': args.deploy_sha,
                'pair_helper_sha256': args.pair_helper_sha,
                'backup_helper_sha256': args.backup_helper_sha,
            },
        }
        result = {'schema': 'deploy_result.v1', 'outcome': 'succeeded', 'exit_code': 0}

        def read_bound_file(path, *_):
            values = {
                guard_path: guard,
                intent_path: intent,
                result_path: result,
            }
            return (json.dumps(values[path]).encode() + b'\n', observed)

        with mock.patch.object(MODULE, 'read_bound_file', side_effect=read_bound_file), \
                mock.patch.object(MODULE.os, 'lstat', return_value=argparse.Namespace(
                    st_dev=1, st_ino=2, st_mode=0, st_uid=0, st_gid=0, st_nlink=1,
                    st_size=1, st_mtime_ns=1, st_ctime_ns=1,
                )), \
                mock.patch.object(MODULE, 'active_release') as active:
            self.assertEqual(('acknowledged', 0), MODULE.acknowledge(args))

        active.assert_called_once_with(args.release)
        self.retire_guard.assert_called_once_with(
            args.release, args.expected_active_release, args.run_id, intent_path, result_path,
        )

    def test_acknowledgement_admission_refusal_precedes_guard_reads_and_retirement(self):
        self.admit.side_effect = MODULE.AdmissionError('maintenance_pending_present', 75)
        with self.assertRaisesRegex(MODULE.AdmissionError, 'maintenance_pending_present'):
            MODULE.acknowledge(arguments())
        self.retire_guard.assert_not_called()

    def test_acknowledgement_rejects_intent_identity_or_hash_mismatch(self):
        args = arguments()
        observed = (1, 2, 0, 0, 0, 1, 1, 1, 1)
        intent_path = '/root/fh-deploy-intent-' + args.release + '.json'
        result_path = '/root/fh-deploy-result-' + args.run_id + '.json'
        guard_path = MODULE.RECOVERY_GUARD
        guard = {
            'schema': 'bound_release_deploy_recovery_guard.v1',
            'release': args.release,
            'expected_active_release': args.expected_active_release,
            'run_id': args.run_id,
            'intent_path': intent_path,
            'result_path': result_path,
        }
        bindings = {
            'archive_sha256': args.archive_sha,
            'provenance_sha256': args.provenance_sha,
            'continuity_sha256': args.continuity_sha,
            'deploy_sha256': args.deploy_sha,
            'pair_helper_sha256': args.pair_helper_sha,
            'backup_helper_sha256': args.backup_helper_sha,
        }
        intent = {
            'schema': 'bound_release_deploy_intent.v1',
            'release': args.release,
            'commit': args.commit,
            'run_id': args.run_id,
            'bindings': bindings,
        }
        result = {'schema': 'deploy_result.v1', 'outcome': 'succeeded', 'exit_code': 0}
        for field, value in [('run_id', 'f' * 32), ('release', 'ea_other_candidate'), ('bindings', {
            **bindings,
            'archive_sha256': '0' * 64,
        })]:
            mismatched = dict(intent)
            mismatched[field] = value

            def read_bound_file(path, *_, mismatched=mismatched):
                values = {guard_path: guard, intent_path: mismatched, result_path: result}
                return (json.dumps(values[path]).encode() + b'\n', observed)

            self.open_lock.return_value = os.open(os.devnull, os.O_RDONLY)
            with mock.patch.object(MODULE, 'read_bound_file', side_effect=read_bound_file), \
                    mock.patch.object(MODULE.os, 'lstat', return_value=argparse.Namespace(
                        st_dev=1, st_ino=2, st_mode=0, st_uid=0, st_gid=0, st_nlink=1,
                        st_size=1, st_mtime_ns=1, st_ctime_ns=1,
                    )):
                with self.assertRaisesRegex(MODULE.AdmissionError, 'intent_mismatch'):
                    MODULE.acknowledge(args)
            self.retire_guard.assert_not_called()

    def test_acknowledgement_rejects_nonterminal_receipt_without_retiring_guard(self):
        args = arguments()
        observed = (1, 2, 0, 0, 0, 1, 1, 1, 1)
        intent_path = '/root/fh-deploy-intent-' + args.release + '.json'
        result_path = '/root/fh-deploy-result-' + args.run_id + '.json'
        guard_path = MODULE.RECOVERY_GUARD
        guard = {
            'schema': 'bound_release_deploy_recovery_guard.v1',
            'release': args.release,
            'expected_active_release': args.expected_active_release,
            'run_id': args.run_id,
            'intent_path': intent_path,
            'result_path': result_path,
        }
        intent = {
            'schema': 'bound_release_deploy_intent.v1', 'release': args.release,
            'commit': args.commit, 'run_id': args.run_id, 'bindings': {
                'archive_sha256': args.archive_sha, 'provenance_sha256': args.provenance_sha,
                'continuity_sha256': args.continuity_sha, 'deploy_sha256': args.deploy_sha,
                'pair_helper_sha256': args.pair_helper_sha, 'backup_helper_sha256': args.backup_helper_sha,
            },
        }
        result = {'schema': 'deploy_result.v1', 'outcome': 'rollback_failed_or_unverifiable', 'exit_code': 31}

        def read_bound_file(path, *_):
            values = {guard_path: guard, intent_path: intent, result_path: result}
            return (json.dumps(values[path]).encode() + b'\n', observed)

        self.open_lock.return_value = os.open(os.devnull, os.O_RDONLY)
        with mock.patch.object(MODULE, 'read_bound_file', side_effect=read_bound_file), \
                mock.patch.object(MODULE.os, 'lstat', return_value=argparse.Namespace(
                    st_dev=1, st_ino=2, st_mode=0, st_uid=0, st_gid=0, st_nlink=1,
                    st_size=1, st_mtime_ns=1, st_ctime_ns=1,
                )):
            with self.assertRaisesRegex(MODULE.AdmissionError, 'ack_result_not_terminal'):
                MODULE.acknowledge(args)
        self.retire_guard.assert_not_called()

    def test_acknowledgement_rejects_active_marker_mismatch_without_retiring_guard(self):
        args = arguments()
        observed = (1, 2, 0, 0, 0, 1, 1, 1, 1)
        intent_path = '/root/fh-deploy-intent-' + args.release + '.json'
        result_path = '/root/fh-deploy-result-' + args.run_id + '.json'
        guard_path = MODULE.RECOVERY_GUARD
        guard = {
            'schema': 'bound_release_deploy_recovery_guard.v1', 'release': args.release,
            'expected_active_release': args.expected_active_release, 'run_id': args.run_id,
            'intent_path': intent_path, 'result_path': result_path,
        }
        intent = {
            'schema': 'bound_release_deploy_intent.v1', 'release': args.release,
            'commit': args.commit, 'run_id': args.run_id, 'bindings': {
                'archive_sha256': args.archive_sha, 'provenance_sha256': args.provenance_sha,
                'continuity_sha256': args.continuity_sha, 'deploy_sha256': args.deploy_sha,
                'pair_helper_sha256': args.pair_helper_sha, 'backup_helper_sha256': args.backup_helper_sha,
            },
        }
        result = {'schema': 'deploy_result.v1', 'outcome': 'succeeded', 'exit_code': 0}

        def read_bound_file(path, *_):
            values = {guard_path: guard, intent_path: intent, result_path: result}
            return (json.dumps(values[path]).encode() + b'\n', observed)

        self.open_lock.return_value = os.open(os.devnull, os.O_RDONLY)
        marker_error = MODULE.AdmissionError('active_release_mismatch')
        with mock.patch.object(MODULE, 'read_bound_file', side_effect=read_bound_file), \
                mock.patch.object(MODULE.os, 'lstat', return_value=argparse.Namespace(
                    st_dev=1, st_ino=2, st_mode=0, st_uid=0, st_gid=0, st_nlink=1,
                    st_size=1, st_mtime_ns=1, st_ctime_ns=1,
                )), \
                mock.patch.object(MODULE, 'active_release', side_effect=marker_error):
            with self.assertRaisesRegex(MODULE.AdmissionError, 'active_release_mismatch'):
                MODULE.acknowledge(args)
        self.retire_guard.assert_not_called()

    def test_wrapper_ack_parser_rejects_unknown_transport_exit_as_definite_failure(self):
        with open(WRAPPER_PATH, encoding='utf-8') as handle:
            source = handle.read()
        self.assertIn("if code == 0 and value['status'] == 'passed' and value['result_class'] == 'acknowledged':", source)
        self.assertNotIn("if code in (70, 75) and value['status'] == 'failed':", source)
        self.assertNotIn("if code != 0 and value['status'] == 'failed':", source)

    def test_wrapper_preserves_known_maintenance_refusal_but_rejects_unknown_exit_75(self):
        with open(WRAPPER_PATH, encoding='utf-8') as handle:
            source = handle.read()
        # Exit 75 is a known no-mutation veto only for lock_busy or a bounded
        # maintenance_* result.  Unknown classes must stay fail-closed.
        self.assertIn("re.fullmatch(r'maintenance_[a-z0-9_]+', value['result_class'])", source)
        self.assertIn('refusal_class="${validated_result##*result_class=}"', source)
        self.assertIn('deployment_class="$refusal_class"', source)
        self.assertIn('if [[ "$refusal_class" == lock_busy || "$refusal_class" == maintenance_* ]]; then', source)

        validator_start = source.index('if validated_result=')
        marker = "<<'PY'\n"
        marker_start = source.index(marker, validator_start)
        validator = source[marker_start + len(marker):source.index("\nPY\n )", marker_start)]
        with tempfile.TemporaryDirectory() as directory:
            receipt = os.path.join(directory, 'receipt')

            def run_validator(result_class, code=75):
                with open(receipt, 'w', encoding='utf-8') as handle:
                    json.dump({
                        'schema': 'bound_release_deploy.v1',
                        'status': 'failed',
                        'result_class': result_class,
                    }, handle)
                return REAL_SUBPROCESS_RUN(
                    [sys.executable, '-I', '-B', '-', receipt, str(code)],
                    input=validator,
                    text=True,
                    capture_output=True,
                    check=False,
                )

            known = run_validator('maintenance_pending_present')
            self.assertEqual(70, known.returncode)
            self.assertIn('result_class=maintenance_pending_present', known.stdout)

            unknown = run_validator('unexpected_refusal')
            self.assertEqual(70, unknown.returncode)
            self.assertIn('result_class=transport_or_receipt_unknown', unknown.stdout)
            swapped = run_validator('maintenance_core_invalid')
            self.assertEqual(70, swapped.returncode)
            self.assertIn('result_class=transport_or_receipt_unknown', swapped.stdout)
            known = run_validator('maintenance_admission_unknown', 70)
            self.assertEqual(70, known.returncode)
            self.assertIn('result_class=maintenance_admission_unknown', known.stdout)
            swapped = run_validator('maintenance_admission_unknown', 75)
            self.assertEqual(70, swapped.returncode)
            self.assertIn('result_class=transport_or_receipt_unknown', swapped.stdout)
            swapped = run_validator('maintenance_pending_present', 70)
            self.assertEqual(70, swapped.returncode)
            self.assertIn('result_class=transport_or_receipt_unknown', swapped.stdout)

    def test_copied_wrapper_preserves_deploy_and_ack_refusal_receipts(self):
        """Run unchanged deploy and acknowledgement refusal paths with isolated doubles."""
        with tempfile.TemporaryDirectory() as directory:
            project = os.path.join(directory, 'project')
            bin_dir = os.path.join(directory, 'bin')
            fixture = os.path.join(directory, 'fixture')
            os.makedirs(os.path.join(project, 'scripts/ops/libexec'))
            os.makedirs(os.path.join(bin_dir))
            for relative in (
                'build_release.sh', 'composer.lock', 'package-lock.json', 'deploy_ea.sh',
                'scripts/ops/verify_local_release_pair.php',
                'scripts/ops/lib/ReleaseBuildProvenanceProducerV1.php',
                'scripts/ops/lib/DeploymentEvidenceAuthorityV1.php',
                'scripts/ops/lib/DeploymentContractV1.php',
                'scripts/ops/libexec/inspect_release_archive_v1.py',
                'scripts/release-gate/validate_release_artifact.php',
                'scripts/release-gate/lib/ReleaseArtifactValidator.php',
                'scripts/ops/prod_release_readiness_preflight.sh',
                'scripts/ops/lib/prod_common.sh',
                'scripts/ops/libexec/backup_set_producer_v1.py',
                'scripts/ops/libexec/backup_timer_transition_v1.py',
                'scripts/ops/libexec/deployment_dump_attestation_v1.py',
                'scripts/ops/libexec/bound_release_deploy_v1.py',
                'scripts/ops/libexec/release_pair_admission_v1.py',
                'scripts/ops/libexec/backup_handoff_admission_v1.py',
            ):
                for root in (project, fixture):
                    path = os.path.join(root, relative)
                    os.makedirs(os.path.dirname(path), exist_ok=True)
                    with open(path, 'w', encoding='utf-8') as handle:
                        if relative.endswith('prod_release_readiness_preflight.sh'):
                            handle.write("printf 'schema=production_release_readiness.v1\\nstatus=passed\\nresult_class=readiness_verified\\nextra=isolated\\n'\n")
                        else:
                            handle.write('placeholder\n')
            wrapper = os.path.join(project, 'scripts/ops/prod_deploy_bound_release.sh')
            with open(WRAPPER_PATH, encoding='utf-8') as source, open(wrapper, 'w', encoding='utf-8') as target:
                target.write(source.read())
            archive = os.path.join(directory, 'archive.tar')
            provenance = os.path.join(directory, 'provenance.json')
            with open(archive, 'wb') as handle:
                handle.write(b'archive')
            with open(provenance, 'wb') as handle:
                handle.write(b'provenance')

            def command(name, body):
                path = os.path.join(bin_dir, name)
                with open(path, 'w', encoding='utf-8') as handle:
                    handle.write('#!/bin/bash\nset -eu\n' + body)
                os.chmod(path, 0o755)

            command('git', r'''
case " $* " in
  *" symbolic-ref --short HEAD "*) printf 'main\n' ;;
  *" rev-parse HEAD "*) printf '%s\n' "${FH_COMMIT}" ;;
  *" rev-parse --verify "*) printf '%s\n' "${FH_COMMIT}" ;;
  *" rev-parse --absolute-git-dir "*) printf '/tmp/fh-test-git\n' ;;
  *" diff --quiet "*) exit 0 ;;
  *" ls-files --error-unmatch "*) exit 0 ;;
  *" archive "*) tar -cf - -C "${FH_FIXTURE}" . ;;
  *" cat-file blob "*) printf 'bound-test-source' ;;
  *) exit 0 ;;
esac
''')
            command('php', "printf 'verified\\n'\n")
            command('openssl', "printf '0123456789abcdef0123456789abcdef\\n'\n")
            command('shasum', "cat >/dev/null; printf 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa  file\\n'\n")
            command('ssh', r'''
cat >/dev/null
if [[ " $* " == *" /usr/bin/sha256sum "* ]]; then
  printf 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb  /root/backups/easyappointments/backup_continuity_state.json\n'
  exit 0
fi
if [[ " $* " == *" --ack "* ]]; then
  printf '%s\n' ack-attempted > "${FH_ACK_MARKER}"
  printf '%s\n' "${FH_ACK_RECEIPT}"
  exit "${FH_ACK_RC}"
fi
printf '%s\n' "${FH_DEPLOY_RECEIPT}"
exit "${FH_DEPLOY_RC}"
''')

            environment = os.environ.copy()
            environment.update({
                'PATH': bin_dir + os.pathsep + environment['PATH'],
                'FH_COMMIT': 'a' * 40,
                'FH_FIXTURE': fixture,
            })
            bash_env = os.path.join(directory, 'bash-env')
            with open(bash_env, 'w', encoding='utf-8') as handle:
                handle.write("bash() { printf 'schema=production_release_readiness.v1\\nstatus=passed\\nresult_class=readiness_verified\\nextra=isolated\\n'; }\n")
            environment['BASH_ENV'] = bash_env
            args = [
                '/bin/bash', wrapper, '--rel', 'ea_candidate', '--expected-commit', 'a' * 40,
                '--expected-active-release', 'ea_previous', '--archive', archive,
                '--provenance', provenance, '--execute', '--confirm-live-deploy', 'ROB-618',
            ]

            # Preserve the deployment-side veto regression: no acknowledgement
            # is attempted when the deployment itself returns exit 75.
            deployment_cases = (
                ('maintenance_pending_present', 75),
                ('lock_busy', 75),
                ('maintenance_core_invalid', 70),
                ('maintenance_admission_unknown', 70),
                ('unexpected_refusal', 70),
            )
            for result_class, deploy_rc in deployment_cases:
                marker = os.path.join(directory, 'ack-marker')
                environment.update({
                    'FH_DEPLOY_RECEIPT': json.dumps({'schema': 'bound_release_deploy.v1', 'status': 'failed', 'result_class': result_class}),
                    'FH_DEPLOY_RC': str(deploy_rc),
                    'FH_ACK_RECEIPT': json.dumps({'schema': 'bound_release_deploy_ack.v1', 'status': 'passed', 'result_class': 'acknowledged'}),
                    'FH_ACK_MARKER': marker,
                })
                completed = REAL_SUBPROCESS_RUN(
                    args, env=environment, stdin=subprocess.DEVNULL, text=True,
                    capture_output=True, check=False,
                )
                self.assertEqual(70, completed.returncode, completed.stderr)
                if result_class == 'unexpected_refusal':
                    self.assertIn('deployment_status=unknown', completed.stdout)
                    self.assertIn('deployment_result_class=transport_or_receipt_unknown', completed.stdout)
                else:
                    self.assertIn('deployment_status=failed', completed.stdout)
                    self.assertIn('deployment_result_class=' + result_class, completed.stdout)
                self.assertIn('ack_status=not_attempted', completed.stdout)
                self.assertFalse(os.path.exists(marker))

            ack_cases = (
                ('maintenance_pending_present', 75, True),
                ('lock_busy', 75, True),
                ('maintenance_core_invalid', 70, True),
                ('maintenance_admission_unknown', 70, True),
                ('unexpected_refusal', 70, False),
                ('maintenance_core_invalid', 75, False),
                ('maintenance_admission_unknown', 75, False),
                ('maintenance_pending_present', 70, False),
            )
            for result_class, ack_rc, known_refusal in ack_cases:
                marker = os.path.join(directory, 'ack-marker')
                environment.update({
                    'FH_DEPLOY_RECEIPT': json.dumps({'schema': 'bound_release_deploy.v1', 'status': 'passed', 'result_class': 'deployed'}),
                    'FH_DEPLOY_RC': '0',
                    'FH_ACK_RECEIPT': json.dumps({'schema': 'bound_release_deploy_ack.v1', 'status': 'failed', 'result_class': result_class}),
                    'FH_ACK_RC': str(ack_rc),
                    'FH_ACK_MARKER': marker,
                })
                completed = REAL_SUBPROCESS_RUN(
                    args, env=environment, stdin=subprocess.DEVNULL, text=True,
                    capture_output=True, check=False,
                )
                self.assertEqual(70, completed.returncode, completed.stderr)
                self.assertIn('deployment_status=passed', completed.stdout, completed.stderr)
                self.assertIn('deployment_result_class=deployed', completed.stdout)
                self.assertIn('status=failed', completed.stdout)
                if not known_refusal:
                    self.assertIn('ack_status=uncertain', completed.stdout, f'{result_class}/{ack_rc}: {completed.stdout}')
                    self.assertIn('ack_result_class=transport_or_receipt_unknown', completed.stdout)
                    self.assertIn('result_class=acknowledgment_unknown', completed.stdout)
                else:
                    self.assertIn('ack_status=refused', completed.stdout)
                    self.assertIn('ack_result_class=' + result_class, completed.stdout)
                    self.assertNotIn('status=passed\nresult_class=deployed\n', completed.stdout)
                self.assertTrue(os.path.exists(marker))

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
