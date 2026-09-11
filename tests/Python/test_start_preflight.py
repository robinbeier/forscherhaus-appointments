import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

sys.dont_write_bytecode = True
SCRIPT = Path(__file__).parents[2] / "scripts/ci/start_preflight.py"
spec = importlib.util.spec_from_file_location("start_preflight", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class FixtureRunner:
    def __init__(self, root):
        self.repo = root / "worktree name!!"
        self.common = root / "common.git"
        self.git_dir = self.common / "worktrees" / "example"
        self.hooks = self.common / "hooks"
        for path in (self.repo / "docker", self.git_dir, self.hooks):
            path.mkdir(parents=True, exist_ok=True)
        (self.repo / "docker/compose.ci-local.yml").write_text("services: {}")
        self.hook = self.hooks / "pre-commit"
        self.hook.write_text("#!/bin/sh\n# managed-by-forscherhaus-precommit\n")
        self.hook.chmod(0o755)
        self.commands, self.environments = [], []
        self.failures = set()
        self.branch = b"codex/example"
        self.containers = b""
        self.listeners = b""
        self.shared_image = "forscherhaus-local/php-fpm:" + "a" * 64
        self.config = {
            "services": {
                "php-fpm": {"build": {"context": str(self.repo / "docker")}, "networks": {"default": {}}},
                "mysql": {"image": "mysql:fixture", "volumes": [{"type": "volume", "source": "db", "target": "/db"}]},
                "nginx": {"image": "nginx:fixture", "depends_on": {"php-fpm": {}}},
                "optional": {"image": "optional:fixture"},
            },
            "volumes": {"db": {"name": "resolved-data"}},
            "networks": {"default": {"name": "resolved-network"}},
        }

    def __call__(self, command, stdin=None, timeout=8, env=None):
        command = tuple(command)
        self.commands.append(command)
        self.environments.append(env)
        if command in self.failures or command[:3] in self.failures:
            return 124, b"PRIVATE stdout", b"PRIVATE stderr"
        git_values = {
            ("git", "rev-parse", "--show-toplevel"): str(self.repo).encode(),
            ("git", "rev-parse", "--absolute-git-dir"): str(self.git_dir).encode(),
            ("git", "rev-parse", "--git-common-dir"): str(self.common).encode(),
            ("git", "rev-parse", "--git-path", "hooks"): str(self.hooks).encode(),
            ("git", "symbolic-ref", "--quiet", "--short", "HEAD"): self.branch,
        }
        if command in git_values:
            return 0, git_values[command], b""
        if command[:3] == ("git", "rev-parse", "--verify"):
            return 0, b"a" * 40, b""
        if command == ("cksum",):
            # Independent POSIX calculation; the script must use the same bytes as the helper.
            result = subprocess.run(["cksum"], input=stdin, capture_output=True, check=True)
            return 0, result.stdout, b""
        if command == ("docker", "compose", "version"):
            return 0, b"v2", b""
        if command[:2] == ("docker", "compose") and command[-3:] == ("config", "--format", "json"):
            return 0, json.dumps(self.config).encode(), b""
        if command[:3] == ("docker", "context", "inspect"):
            return 0, b'"unix:///fixture/docker.sock"', b""
        if command[:2] == ("docker", "info"):
            return 0, b'{"OSType":"linux","Architecture":"aarch64"}', b""
        if command[:3] == ("docker", "ps", "-a"):
            return 0, self.containers, b""
        if command[0] == "lsof":
            return (0 if self.listeners else 1), self.listeners, b""
        if len(command) > 2 and command[0] == sys.executable and command[1] == "-B":
            return 0, self.shared_image.encode(), b""
        if command[:3] in [("docker", "image", "inspect"), ("docker", "network", "inspect"), ("docker", "volume", "inspect")]:
            return 0, b"[]", b""
        if command == ("gh", "api", "rate_limit"):
            return 0, b'{"PRIVATE":"account data"}', b""
        raise AssertionError("Unexpected command: " + repr(command))


class StartPreflightTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name).resolve()
        self.runner = FixtureRunner(self.root)
        environment = mock.patch.dict(os.environ, {}, clear=True)
        environment.start()
        self.addCleanup(environment.stop)

    def run_preflight(self, *args):
        return module.preflight(list(args), self.runner)

    def statuses(self, report, category):
        return [item["status"] for item in report["results"] if item["category"] == category]

    def test_external_common_directory_is_blocked_before_any_mutation(self):
        code, report = self.run_preflight("--writable-root", str(self.runner.repo))
        self.assertEqual(code, 1)
        self.assertEqual(self.statuses(report, "worktree-write"), ["ready"])
        self.assertEqual(self.statuses(report, "git-common-write"), ["blocked"])
        self.assertIn("Local/Git write permission", report["actions"])

    def test_nested_readonly_exclusion_wins_over_writable_parent(self):
        code, report = self.run_preflight("--writable-root", str(self.root), "--read-only-root", str(self.runner.common))
        self.assertEqual(code, 1)
        self.assertEqual(self.statuses(report, "worktree-write"), ["ready"])
        self.assertEqual(self.statuses(report, "git-dir-write"), ["blocked"])
        self.assertEqual(self.statuses(report, "git-common-write"), ["blocked"])

    def test_symlink_does_not_hide_outside_common_directory(self):
        link = self.runner.repo / "linked-git"
        link.symlink_to(self.runner.common, target_is_directory=True)
        self.runner.common = link
        code, report = self.run_preflight("--writable-root", str(self.runner.repo))
        self.assertEqual(code, 1)
        self.assertEqual(self.statuses(report, "git-common-write"), ["blocked"])

    def test_absent_runtime_policy_remains_unknown_even_with_healthy_probes(self):
        code, report = self.run_preflight()
        self.assertEqual(code, 0)
        self.assertEqual(report["status"], "unknown")
        for category in ("git-common-write", "network", "capacity", "authorization"):
            self.assertEqual(self.statuses(report, category), ["unknown"])

    def test_managed_executable_hook_is_required(self):
        self.runner.hook.chmod(0o644)
        self.assertEqual(self.statuses(self.run_preflight()[1], "hooks"), ["blocked"])
        self.runner.hook.chmod(0o755)
        self.runner.hook.write_text("#!/bin/sh\n# managed-by-forscherhaus-other\n")
        self.assertEqual(self.statuses(self.run_preflight()[1], "hooks"), ["blocked"])

    def test_detached_branch_and_missing_base_are_distinct(self):
        self.runner.branch = b""
        self.assertEqual(self.statuses(self.run_preflight()[1], "branch"), ["unknown"])
        self.runner.failures.add(("git", "rev-parse", "--verify"))
        self.assertEqual(self.statuses(self.run_preflight()[1], "base"), ["blocked"])

    def test_project_matches_shell_helper_slug_and_posix_cksum(self):
        report = self.run_preflight()[1]
        expected = subprocess.run(["bash", "-c", 'printf "%s" "$1" | tr "[:upper:]" "[:lower:]" | tr -cs "a-z0-9" "-"', "test", self.runner.repo.name], capture_output=True, check=True).stdout.decode()
        checksum = subprocess.run(["cksum"], input=str(self.runner.git_dir).encode(), capture_output=True, check=True).stdout.split()[0].decode()
        self.assertEqual(report["project"], expected + "-local-ci-" + checksum)
        compose_env = next(env for command, env in zip(self.runner.commands, self.runner.environments) if command[-3:] == ("config", "--format", "json"))
        self.assertEqual(compose_env["EA_MYSQL_DATA_PATH"], "./docker/.ci-mysql/" + module._slug(report["project"]))
        self.assertFalse((self.runner.repo / "docker/.ci-mysql").exists())

    def test_normalized_resources_and_shared_build_image_are_inspected(self):
        self.run_preflight()
        for command in [("docker", "volume", "inspect", "resolved-data"), ("docker", "network", "inspect", "resolved-network"), ("docker", "image", "inspect", self.runner.shared_image)]:
            self.assertIn(command, self.runner.commands)
        self.assertNotIn(("docker", "image", "inspect", "optional:fixture"), self.runner.commands)

    def test_missing_external_resource_blocks_but_internal_requires_creation(self):
        self.runner.failures.add(("docker", "volume", "inspect"))
        code, report = self.run_preflight()
        self.assertEqual(code, 0)
        self.assertEqual(self.statuses(report, "docker-volume"), ["unknown"])
        self.assertIn("Local Docker resource creation", report["actions"])
        self.runner.config["volumes"]["db"]["external"] = True
        code, report = self.run_preflight()
        self.assertEqual(code, 1)
        self.assertEqual(self.statuses(report, "docker-volume"), ["blocked"])

    def test_missing_build_and_pull_images_have_different_actions(self):
        self.runner.failures.add(("docker", "image", "inspect"))
        report = self.run_preflight()[1]
        self.assertIn("Docker build/dependency access", report["actions"])
        self.assertIn("Docker image pull/network access", report["actions"])

    def test_existing_project_and_visible_host_port_conflicts_are_blocked(self):
        self.runner.containers = b"PRIVATE container id"
        self.runner.config["services"]["nginx"]["ports"] = [{"published": "8080", "target": 80, "protocol": "tcp"}]
        self.runner.listeners = b"p123\nn*:8080\n"
        code, report = self.run_preflight()
        self.assertEqual(code, 1)
        self.assertEqual(self.statuses(report, "project-collision"), ["blocked"])
        self.assertEqual(self.statuses(report, "ports"), ["blocked"])
        self.assertNotIn("PRIVATE", json.dumps(report))

    def test_unresolved_port_requirements_never_report_ready(self):
        for port in ({"published": "8000-8010"}, {"published": "0"}, {"target": 80}, "8080:80"):
            for known_ports in ([], [{"published": "8080", "protocol": "tcp"}]):
                with self.subTest(port=port, known_ports=known_ports):
                    self.runner.config["services"]["nginx"]["ports"] = [port] + known_ports
                    report = self.run_preflight()[1]
                    self.assertIn("unknown", self.statuses(report, "ports"))
                    self.assertNotIn("ready", self.statuses(report, "ports"))
        self.runner.config["services"]["nginx"]["ports"] = []
        self.assertEqual(self.statuses(self.run_preflight()[1], "ports"), ["ready"])

    def test_invalid_service_and_malformed_config_fail_closed(self):
        code, report = self.run_preflight("--service", "missing")
        self.assertEqual(code, 1)
        for category in ("ports", "images"):
            self.assertEqual(self.statuses(report, category), ["unknown"])
        for configuration in (["PRIVATE malformed config"], {"services": {}}):
            self.runner.config = configuration
            code, report = self.run_preflight()
            self.assertEqual(code, 1)
            self.assertNotIn("PRIVATE", json.dumps(report))
            for category in ("ports", "images"):
                self.assertEqual(self.statuses(report, category), ["unknown"])

    def test_readonly_command_inventory_no_pycache_and_optional_github(self):
        before = set(self.root.rglob("*"))
        self.run_preflight()
        allowed = {"git", "cksum", "docker", "lsof", sys.executable}
        forbidden = {"up", "build", "pull", "push", "create", "rm", "login", "fetch", "prune", "mkdir"}
        for command in self.runner.commands:
            self.assertIn(command[0], allowed)
            self.assertFalse(forbidden.intersection(command))
        self.assertEqual(set(self.root.rglob("*")), before)
        self.assertNotIn(("gh", "api", "rate_limit"), self.runner.commands)
        report = self.run_preflight("--probe-github")[1]
        self.assertIn(("gh", "api", "rate_limit"), self.runner.commands)
        self.assertNotIn("PRIVATE", json.dumps(report))

    def test_remote_docker_endpoint_is_not_contacted_by_default(self):
        with mock.patch.dict(os.environ, {"DOCKER_HOST": "tcp://PRIVATE.example:2376"}):
            code, report = self.run_preflight()
        self.assertEqual(code, 1)
        self.assertEqual(self.statuses(report, "docker-endpoint"), ["blocked"])
        self.assertFalse(any(command[:2] == ("docker", "info") for command in self.runner.commands))
        self.assertFalse(any(command[:3] == ("docker", "image", "inspect") for command in self.runner.commands))
        self.assertNotIn("PRIVATE", json.dumps(report))

    def test_daemon_errors_and_timeouts_are_not_product_failures_or_raw_output(self):
        self.runner.failures.add(("docker", "info", "--format"))
        code, report = self.run_preflight()
        self.assertEqual(code, 1)
        self.assertEqual(self.statuses(report, "daemon"), ["blocked"])
        self.assertIn("Docker daemon access", report["actions"])
        self.assertNotIn("PRIVATE", json.dumps(report))
        with mock.patch.object(module.subprocess, "run", side_effect=subprocess.TimeoutExpired(["PRIVATE"], 8)) as run:
            self.assertEqual(module._run(["docker", "info"]), (124, b"", b""))
            self.assertEqual(run.call_args.kwargs["timeout"], 8)
            self.assertEqual(run.call_args.kwargs["env"]["GIT_OPTIONAL_LOCKS"], "0")


if __name__ == "__main__":
    unittest.main()
