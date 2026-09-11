import json
import os
import signal
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

SCRIPT = Path(__file__).parents[2] / 'scripts/ci/run_gate_with_summary.py'


class GateRunnerTest(unittest.TestCase):
    def test_command_exit_and_complete_raw_failure_context_are_preserved(self):
        with tempfile.TemporaryDirectory() as root:
            output = Path(root) / 'logs'
            result = subprocess.run([sys.executable, '-B', str(SCRIPT), '--output-dir', str(output), '--label', 'fixture', '--', sys.executable, '-c',
                                     'import sys; print("Tests: 3, Assertions: 7, Skipped: 1."); print("SECURITY WARNING: new diagnostic", file=sys.stderr); print("sentinel context"); sys.exit(23)'], capture_output=True, text=True)
            self.assertEqual(result.returncode, 23, result.stderr + result.stdout)
            self.assertIn('SECURITY WARNING: new diagnostic', result.stdout)
            self.assertIn('sentinel context', result.stdout)
            records = list(output.glob('*.json'))
            self.assertEqual(len(records), 1)
            record = json.loads(records[0].read_text())
            self.assertEqual(record['exit_code'], 23)
            self.assertIn('sentinel context', Path(record['raw_log']).read_text())
            self.assertEqual(Path(record['raw_log']).stat().st_mode & 0o777, 0o600)
            self.assertIn('FAIL', result.stdout)

    def test_success_and_ci_summary_use_same_record_and_do_not_invent_metrics(self):
        with tempfile.TemporaryDirectory() as root:
            output = Path(root) / 'logs'
            command = [sys.executable, '-B', str(SCRIPT), '--output-dir', str(output)]
            result = subprocess.run(command + ['--', sys.executable, '-c', 'print("benign fixture")'], capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            summary = Path(root) / 'summary.md'
            env = dict(os.environ, GITHUB_STEP_SUMMARY=str(summary))
            final = subprocess.run(command + ['--summarize', '--job-status', 'success'], env=env, capture_output=True, text=True)
            self.assertEqual(final.returncode, 0, final.stderr)
            self.assertEqual(summary.read_text(), final.stdout)
            self.assertIn('PASS', final.stdout)
            self.assertIn('not observed', final.stdout)
            failed = subprocess.run(command + ['--summarize', '--job-status', 'failure'], capture_output=True, text=True)
            self.assertIn('FAIL', failed.stdout)
            self.assertNotEqual(failed.returncode, 0)

    def test_ci_heredoc_input_and_bash_failure_semantics_are_preserved(self):
        with tempfile.TemporaryDirectory() as root:
            command = [sys.executable, '-B', str(SCRIPT), '--output-dir', root, '--', 'bash', '--noprofile', '--norc', '-e']
            result = subprocess.run(command, input='echo before\nfalse\necho must-not-run\n', capture_output=True, text=True)
            self.assertEqual(result.returncode, 1)
            self.assertIn('before', result.stdout)
            self.assertNotIn('must-not-run', result.stdout)
            result = subprocess.run(command, input='echo after\nexit 0\n', capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn('after', result.stdout)
            result = subprocess.run(command, input='set -o pipefail\nfalse | cat\necho must-not-run\n', capture_output=True, text=True)
            self.assertNotEqual(result.returncode, 0)
            self.assertNotIn('must-not-run', result.stdout)
            result = subprocess.run(command, input='false | cat\necho original-default-behavior\n', capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn('original-default-behavior', result.stdout)

    def test_termination_is_forwarded_and_cannot_report_success(self):
        with tempfile.TemporaryDirectory() as root:
            process = subprocess.Popen([sys.executable, '-B', str(SCRIPT), '--output-dir', root, '--', sys.executable, '-c',
                                        'import time; print("ready", flush=True); time.sleep(30)'], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            try:
                self.assertEqual(process.stdout.readline().strip(), 'ready')
                process.send_signal(signal.SIGTERM)
                stdout, stderr = process.communicate(timeout=5)
                self.assertEqual(process.returncode, 143, stderr)
                self.assertIn('FAIL', stdout)
            finally:
                if process.poll() is None:
                    process.kill()
                    process.wait()

    def test_termination_during_finalization_invalidates_already_written_record(self):
        with tempfile.TemporaryDirectory() as root:
            driver = """import os,signal,sys
sys.path.insert(0, sys.argv[1])
import run_gate_with_summary as runner
original = runner.finish_record
def interrupted(*args):
    code = original(*args)
    os.kill(os.getpid(), signal.SIGTERM)
    return code
runner.finish_record = interrupted
sys.argv = ['runner', '--output-dir', sys.argv[2], '--', sys.executable, '-c', 'print("fixture")']
sys.exit(runner.main())
"""
            result = subprocess.run([sys.executable, '-B', '-c', driver, str(SCRIPT.parent), root], capture_output=True, text=True)
            self.assertEqual(result.returncode, 143, result.stderr)
            summary = subprocess.run([sys.executable, '-B', str(SCRIPT), '--output-dir', root, '--summarize'], capture_output=True, text=True)
            self.assertNotEqual(summary.returncode, 0)
            self.assertIn('FAIL', summary.stdout)
            self.assertNotIn('exit 0;', summary.stdout)

    def test_missing_command_and_unwritable_artifact_location_cannot_pass(self):
        with tempfile.TemporaryDirectory() as root:
            result = subprocess.run([sys.executable, '-B', str(SCRIPT), '--output-dir', root, '--', '/nonexistent-gate-fixture'], capture_output=True, text=True)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('FAIL', result.stderr)
            obstacle = Path(root) / 'file'
            obstacle.write_text('fixture')
            result = subprocess.run([sys.executable, '-B', str(SCRIPT), '--output-dir', str(obstacle / 'logs'), '--', sys.executable, '-c', 'print("must not run")'], capture_output=True, text=True)
            self.assertNotEqual(result.returncode, 0)
            self.assertNotIn('must not run', result.stdout)


if __name__ == '__main__':
    unittest.main()
