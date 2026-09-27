"""Exercise the live canary wrapper's shared production lock boundary."""

import fcntl
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


SOURCE = Path(__file__).resolve().parents[3] / 'scripts/ops/zero_surprise_canary_fixture.sh'
PRODUCTION_LOCK = "lock_path='/var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock'"


@unittest.skipUnless(os.geteuid() == 0, 'isolated root fixture required')
class CanaryFixtureLockTest(unittest.TestCase):
    def setUp(self):
        self.root = Path(tempfile.mkdtemp(prefix='canary-fixture-lock-', dir='/root'))
        self.addCleanup(shutil.rmtree, self.root)
        lock_dir = self.root / 'locks'
        lock_dir.mkdir(mode=0o700)
        self.lock = lock_dir / 'fh-production-change.lock'
        self.lock.touch(mode=0o600)
        self.lock.chmod(0o600)
        (self.root / 'app').mkdir()
        bin_dir = self.root / 'bin'
        bin_dir.mkdir()
        self.sentinel = self.root / 'called'
        for command, script in {
            'systemctl': '#!/bin/sh\nprintf "not-found\\n"\n',
            'systemd-run': '#!/bin/sh\nprintf "systemd-run\\n" >> "$CANARY_SENTINEL"\n',
            'php': '#!/bin/sh\nprintf "php\\n" >> "$CANARY_SENTINEL"\n',
        }.items():
            path = bin_dir / command
            path.write_text(script)
            path.chmod(0o755)
        source = SOURCE.read_text()
        self.assertIn(PRODUCTION_LOCK, source)
        self.script = self.root / 'zero_surprise_canary_fixture.sh'
        self.script.write_text(source.replace(PRODUCTION_LOCK, f"lock_path='{self.lock}'", 1))
        self.environment = os.environ.copy()
        self.environment.update({
            'PATH': f'{bin_dir}:{self.environment["PATH"]}',
            'APP_ROOT': str(self.root / 'app'),
            'CANARY_SENTINEL': str(self.sentinel),
        })
        self.environment.pop('ORDINARY_CHANGE_LOCK_FD', None)

    def run_activate(self, *, inherited_fd=None):
        environment = self.environment.copy()
        if inherited_fd is not None:
            environment['ORDINARY_CHANGE_LOCK_FD'] = str(inherited_fd)
        return subprocess.run(
            ['bash', str(self.script), 'activate'],
            env=environment,
            pass_fds=() if inherited_fd is None else (inherited_fd,),
            capture_output=True,
            text=True,
            check=False,
        )

    def test_contended_lock_prevents_activation_before_any_side_effect(self):
        with self.lock.open('rb') as locked:
            fcntl.flock(locked, fcntl.LOCK_EX | fcntl.LOCK_NB)
            result = self.run_activate()
        self.assertEqual(75, result.returncode)
        self.assertFalse(self.sentinel.exists())

    def test_inherited_matching_lock_allows_deploy_canary(self):
        with self.lock.open('rb') as locked:
            fcntl.flock(locked, fcntl.LOCK_EX | fcntl.LOCK_NB)
            result = self.run_activate(inherited_fd=locked.fileno())
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual('systemd-run\nphp\n', self.sentinel.read_text())

    def test_inherited_unrelated_descriptor_is_rejected(self):
        other = self.root / 'other.lock'
        other.touch(mode=0o600)
        with other.open('rb') as unrelated:
            result = self.run_activate(inherited_fd=unrelated.fileno())
        self.assertEqual(75, result.returncode)
        self.assertFalse(self.sentinel.exists())


if __name__ == '__main__':
    unittest.main()
