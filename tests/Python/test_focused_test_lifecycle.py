import os
import shutil
import signal
import subprocess
import tempfile
import time
import unittest
from pathlib import Path


ROOT = Path(__file__).parents[2]
WRAPPER = ROOT / "scripts/ci/run_focused_test.sh"
HELPER = ROOT / "scripts/ci/docker_compose_helpers.sh"


class FocusedTestLifecycleTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="focused-test-lifecycle-")
        self.root = Path(self.temp.name)
        (self.root / "scripts/ci").mkdir(parents=True)
        shutil.copy(WRAPPER, self.root / "scripts/ci/run_focused_test.sh")
        shutil.copy(HELPER, self.root / "scripts/ci/docker_compose_helpers.sh")
        os.chmod(self.root / "scripts/ci/run_focused_test.sh", 0o755)
        subprocess.run(["git", "init", "-q"], cwd=self.root, check=True)
        self.log = self.root / "docker.log"
        fakebin = self.root / "fakebin"
        fakebin.mkdir()
        fake = fakebin / "docker"
        fake.write_text(
            """#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$FAKE_DOCKER_LOG"
if [[ "$1" == container || "$1" == network || "$1" == volume ]]; then
  [[ "${FAKE_EXISTING_RESOURCES:-0}" == 1 ]] && printf 'existing-id\n'
  exit 0
fi
if [[ "$1" == compose && "$*" == *' version'* ]]; then exit 0; fi
if [[ "$1" == compose && "$*" == *' down '* ]]; then exit "${FAKE_CLEANUP_STATUS:-0}"; fi
if [[ "$1" == compose && "$*" == *' run '* ]]; then
  if [[ "${FAKE_RUN_MODE:-}" == signal ]]; then exec sleep 30; fi
  exit "${FAKE_RUN_STATUS:-0}"
fi
exit 0
"""
        )
        os.chmod(fake, 0o755)
        self.env = dict(os.environ)
        self.env.update(
            PATH=f"{fakebin}:{self.env.get('PATH', '')}",
            FAKE_DOCKER_LOG=str(self.log),
            EA_LOCAL_CI_PORTLESS_COMPOSE="1",
        )

    def tearDown(self):
        self.temp.cleanup()

    def run_wrapper(self, *args, **env):
        run_env = dict(self.env)
        run_env.update(env)
        return subprocess.run(
            [str(self.root / "scripts/ci/run_focused_test.sh"), *args],
            cwd=self.root,
            env=run_env,
            capture_output=True,
            text=True,
        )

    def log_lines(self):
        return self.log.read_text().splitlines() if self.log.exists() else []

    def wait_for_runs(self, count):
        deadline = time.monotonic() + 5
        while time.monotonic() < deadline:
            if sum(" run " in f" {line} " for line in self.log_lines()) >= count:
                return
            time.sleep(0.01)
        self.fail("Docker run entry was not observed")

    def test_success_cleans_exactly_once(self):
        result = self.run_wrapper("php-fpm", "php", "-v")
        self.assertEqual(result.returncode, 0, result.stderr)
        down = [line for line in self.log_lines() if " down " in f" {line} "]
        self.assertEqual(len(down), 1)
        self.assertEqual(len({line.split(" -p ")[1].split()[0] for line in down}), 1)

    def test_command_failure_preserves_status_and_still_cleans(self):
        result = self.run_wrapper("php-fpm", "php", "-v", FAKE_RUN_STATUS="23")
        self.assertEqual(result.returncode, 23, result.stderr)
        self.assertEqual(sum(" down " in f" {line} " for line in self.log_lines()), 1)

    def test_cleanup_failure_is_nonzero_after_success(self):
        result = self.run_wrapper("php-fpm", "php", "-v", FAKE_CLEANUP_STATUS="19")
        self.assertEqual(result.returncode, 1, result.stderr)
        self.assertIn("Compose stack cleanup failed", result.stderr)

    def test_term_cleans_once_and_returns_signal_status(self):
        process = subprocess.Popen(
            [str(self.root / "scripts/ci/run_focused_test.sh"), "php-fpm", "php", "-v"],
            cwd=self.root,
            env=dict(self.env, FAKE_RUN_MODE="signal"),
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        try:
            self.wait_for_runs(1)
            process.send_signal(signal.SIGTERM)
            process.wait(timeout=5)
        finally:
            if process.poll() is None:
                process.kill()
                process.wait()
        self.assertEqual(process.returncode, 143)
        self.assertEqual(sum(" down " in f" {line} " for line in self.log_lines()), 1)

    def test_existing_stopped_resources_refuse_cleanup(self):
        result = self.run_wrapper("php-fpm", "php", "-v", FAKE_EXISTING_RESOURCES="1")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Refusing to adopt", result.stderr)
        self.assertEqual(sum(" down " in f" {line} " for line in self.log_lines()), 0)

    def test_caller_overrides_are_rejected(self):
        result = self.run_wrapper("php-fpm", "php", "-v", CI_DOCKER_COMPOSE_PROJECT_NAME="foreign")
        self.assertEqual(result.returncode, 2)
        self.assertEqual(self.log_lines(), [])

    def test_parallel_runs_have_distinct_project_names(self):
        first = subprocess.Popen(
            [str(self.root / "scripts/ci/run_focused_test.sh"), "php-fpm", "php", "-v"],
            cwd=self.root, env=dict(self.env, FAKE_RUN_MODE="signal"),
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
        second = subprocess.Popen(
            [str(self.root / "scripts/ci/run_focused_test.sh"), "php-fpm", "php", "-v"],
            cwd=self.root, env=dict(self.env, FAKE_RUN_MODE="signal"),
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
        self.wait_for_runs(2)
        first.terminate()
        second.terminate()
        first.wait(timeout=5)
        second.wait(timeout=5)
        projects = {
            line.split(" -p ")[1].split()[0]
            for line in self.log_lines()
            if " run " in f" {line} "
        }
        self.assertEqual(len(projects), 2)

    def test_failed_preparation_cleans_only_its_own_empty_directory(self):
        command = (
            "set -e; source scripts/ci/docker_compose_helpers.sh; "
            "CI_DOCKER_COMPOSE_PROJECT_NAME=failed-build; "
            "ci_docker_claim_fresh_project; ci_docker_configure_mysql_data_path; "
            "ci_docker_cleanup_stack; "
            'test ! -e "$CI_DOCKER_EPHEMERAL_MYSQL_DATA_PATH"'
        )
        result = subprocess.run(["bash", "-c", command], cwd=self.root,
                                env=self.env, capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(any(" down " in f" {line} " for line in self.log_lines()))

    def test_existing_bind_data_is_not_adopted_or_removed(self):
        data = self.root / "docker/.ci-mysql/retained-test"
        data.mkdir(parents=True)
        sentinel = data / "keep.txt"
        sentinel.write_text("retained data")
        command = (
            "source scripts/ci/docker_compose_helpers.sh; "
            "CI_DOCKER_COMPOSE_PROJECT_NAME=retained-test; "
            "ci_docker_claim_fresh_project"
        )
        result = subprocess.run(["bash", "-c", command], cwd=self.root,
                                env=self.env, capture_output=True, text=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Refusing to adopt", result.stderr)
        self.assertEqual(sentinel.read_text(), "retained data")
        self.assertFalse(any(" down " in f" {line} " for line in self.log_lines()))

    def test_data_created_during_own_build_can_be_claimed(self):
        command = (
            "set -e; source scripts/ci/docker_compose_helpers.sh; "
            "CI_DOCKER_COMPOSE_PROJECT_NAME=own-build; "
            "ci_docker_configure_mysql_data_path; ci_docker_claim_fresh_project; "
            'test "$CI_DOCKER_MYSQL_DATA_CREATED" = 1; '
            'test "$CI_DOCKER_PROJECT_OWNED" = 1'
        )
        result = subprocess.run(["bash", "-c", command], cwd=self.root,
                                env=self.env, capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_cleanup_before_start_does_not_initialize_or_call_docker(self):
        command = (
            "source scripts/ci/docker_compose_helpers.sh; "
            "ci_docker_cleanup_stack"
        )
        result = subprocess.run(["bash", "-lc", command], cwd=self.root, env=self.env, capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.log_lines(), [])


if __name__ == "__main__":
    unittest.main()
