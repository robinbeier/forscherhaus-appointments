import importlib.util
import json
from pathlib import Path
import shlex
import subprocess
import tempfile
import unittest
from unittest import mock


SCRIPT = Path(__file__).parents[2] / "scripts/ci/worktree_inventory.py"
spec = importlib.util.spec_from_file_location("worktree_inventory", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


def git(cwd: Path, *args: str) -> str:
    result = subprocess.run(["git", *args], cwd=cwd, text=True, capture_output=True, check=True)
    return result.stdout.strip()


class WorktreeInventoryTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.remote = self.root / "remote.git"
        self.primary = self.root / "primary"
        subprocess.run(["git", "init", "--bare", str(self.remote)], check=True, capture_output=True)
        subprocess.run(["git", "init", "--initial-branch=main", str(self.primary)], check=True, capture_output=True)
        git(self.primary, "config", "user.email", "test@example.invalid")
        git(self.primary, "config", "user.name", "Inventory Test")
        (self.primary / "README.md").write_text("initial\n")
        git(self.primary, "add", "README.md")
        git(self.primary, "commit", "-m", "initial")
        git(self.primary, "remote", "add", "origin", str(self.remote))
        git(self.primary, "push", "-u", "origin", "main")
        git(self.remote, "symbolic-ref", "HEAD", "refs/heads/main")
        self.external = self.root / "external"
        subprocess.run(["git", "clone", "-q", str(self.remote), str(self.external)], check=True)
        git(self.external, "config", "user.email", "test@example.invalid")
        git(self.external, "config", "user.name", "Inventory Test")

    def tearDown(self):
        self.temp.cleanup()

    def advance_remote(self, fetch_primary=True):
        (self.external / "README.md").write_text("remote advance\n")
        git(self.external, "add", "README.md")
        git(self.external, "commit", "-m", "advance remote")
        git(self.external, "push", "origin", "main")
        if fetch_primary:
            git(self.primary, "fetch", "origin", "main")

    def test_reports_stale_primary_and_prunable_registration_without_mutation(self):
        self.advance_remote()
        missing = self.root / "missing-worktree"
        git(self.primary, "worktree", "add", "-b", "codex/old", str(missing))
        import shutil
        shutil.rmtree(missing)

        code, report = module.inventory(["--repo", str(self.primary)])

        self.assertEqual(code, 1)
        self.assertEqual(report["status"], "blocked")
        self.assertEqual(report["primary"]["freshness"], "stale")
        self.assertEqual(report["counts"]["prunable"], 1)
        self.assertEqual(report["counts"]["dirty"], 0)
        self.assertTrue(report["safe_primary_refresh"]["safe_ff_only"])
        self.assertIn(f"merge --ff-only {git(self.external, 'rev-parse', 'HEAD')}", report["safe_primary_refresh"]["commands"][1])
        self.assertNotIn(str(self.primary), " ".join(report["safe_primary_refresh"]["commands"]))
        self.assertNotIn("--prune", " ".join(report["safe_primary_refresh"]["commands"]))
        self.assertIsNone(report["authority"]["source"])
        self.assertEqual(len(report["prunable_suggestions"]), 1)
        self.assertIn("no automatic prune", report["prunable_suggestions"][0]["action"])
        self.assertTrue(missing.parent.exists())
        self.assertFalse(missing.exists())

    def test_unfetched_remote_commit_does_not_produce_refresh_command(self):
        self.advance_remote(fetch_primary=False)

        code, report = module.inventory(["--repo", str(self.primary)])

        self.assertEqual(code, 1)
        self.assertEqual(report["primary"]["freshness"], "unknown")
        self.assertFalse(report["safe_primary_refresh"]["safe_ff_only"])
        self.assertEqual(report["safe_primary_refresh"]["commands"], [])

    def test_local_main_ahead_of_remote_does_not_produce_refresh_command(self):
        (self.primary / "README.md").write_text("local advance\n")
        git(self.primary, "add", "README.md")
        git(self.primary, "commit", "-m", "local advance")

        code, report = module.inventory(["--repo", str(self.primary)])

        self.assertEqual(code, 1)
        self.assertEqual(report["primary"]["freshness"], "ahead")
        self.assertFalse(report["safe_primary_refresh"]["safe_ff_only"])
        self.assertEqual(report["safe_primary_refresh"]["commands"], [])

    def test_diverged_main_does_not_produce_refresh_command(self):
        self.advance_remote()
        (self.primary / "README.md").write_text("local divergence\n")
        git(self.primary, "add", "README.md")
        git(self.primary, "commit", "-m", "local divergence")

        code, report = module.inventory(["--repo", str(self.primary)])

        self.assertEqual(code, 1)
        self.assertEqual(report["primary"]["freshness"], "diverged")
        self.assertFalse(report["safe_primary_refresh"]["safe_ff_only"])
        self.assertEqual(report["safe_primary_refresh"]["commands"], [])

    def test_human_output_for_unavailable_inventory_is_compact_unknown(self):
        result = subprocess.run(
            ["python3", str(SCRIPT), "--repo", str(self.root / "not-a-checkout")],
            text=True,
            capture_output=True,
            check=False,
        )

        self.assertEqual(result.returncode, 1)
        self.assertIn("status: unknown", result.stdout)
        self.assertIn("error: worktree inventory unavailable", result.stdout)
        self.assertNotIn("Traceback", result.stderr)

    def test_reports_dirty_primary_and_blocks_refresh(self):
        (self.primary / "local-note.txt").write_text("uncommitted\n")
        code, report = module.inventory(["--repo", str(self.primary)])

        self.assertEqual(code, 1)
        self.assertEqual(report["counts"]["dirty"], 1)
        self.assertEqual(report["primary"]["freshness"], "current")
        self.assertFalse(report["safe_primary_refresh"]["safe_ff_only"])
        self.assertEqual(report["safe_primary_refresh"]["commands"], [])

    def test_current_clean_primary_is_the_only_source_authority_candidate(self):
        code, report = module.inventory(["--repo", str(self.primary), "--show-paths"])

        self.assertEqual(code, 0)
        self.assertEqual(report["primary"]["freshness"], "current")
        self.assertEqual(report["authority"]["source"], str(self.primary.resolve()))

    def test_clean_non_main_primary_is_unknown_and_nonzero(self):
        git(self.primary, "checkout", "-b", "codex/non-main")

        code, report = module.inventory(["--repo", str(self.primary)])

        self.assertEqual(code, 1)
        self.assertEqual(report["status"], "unknown")
        self.assertEqual(report["primary"]["freshness"], "unknown")
        self.assertIsNone(report["authority"]["source"])

    def test_detached_primary_is_unknown_and_nonzero(self):
        git(self.primary, "checkout", "--detach", "HEAD")

        code, report = module.inventory(["--repo", str(self.primary)])

        self.assertEqual(code, 1)
        self.assertEqual(report["status"], "unknown")
        self.assertEqual(report["primary"]["freshness"], "unknown")

    def test_unreadable_remote_is_unknown_and_nonzero(self):
        real_runner = module._run_git

        def failing_remote_runner(repo, arguments, timeout=module.TIMEOUT):
            if arguments[:1] == ["ls-remote"]:
                return 128, b"", b"remote unavailable"
            return real_runner(repo, arguments, timeout)

        code, report = module.inventory(["--repo", str(self.primary)], runner=failing_remote_runner)

        self.assertEqual(code, 1)
        self.assertEqual(report["status"], "unknown")
        self.assertEqual(report["primary"]["freshness"], "unknown")
        self.assertIsNone(report["primary"]["remote_main_sha"])

    def test_malformed_remote_identity_is_unknown_and_nonzero(self):
        real_runner = module._run_git

        def malformed_remote_runner(repo, arguments, timeout=module.TIMEOUT):
            if arguments[:1] == ["ls-remote"]:
                return 0, b"not-a-commit\trefs/heads/main\n", b""
            return real_runner(repo, arguments, timeout)

        code, report = module.inventory(["--repo", str(self.primary)], runner=malformed_remote_runner)

        self.assertEqual(code, 1)
        self.assertEqual(report["status"], "unknown")
        self.assertIsNone(report["primary"]["remote_main_sha"])
        self.assertFalse(report["safe_primary_refresh"]["safe_ff_only"])

    def test_custom_remote_path_is_redacted_from_default_report_and_commands(self):
        self.advance_remote()

        code, report = module.inventory(["--repo", str(self.primary), "--remote", str(self.remote)])
        serialized = json.dumps(report)

        self.assertEqual(code, 1)
        self.assertEqual(report["remote"], "<remote>")
        self.assertNotIn(str(self.remote), serialized)
        self.assertNotIn(str(self.remote), " ".join(report["safe_primary_refresh"]["commands"]))
        self.assertIn("fetch '<remote>' main", report["safe_primary_refresh"]["commands"][0])

    def test_custom_remote_refresh_commands_fast_forward_the_primary(self):
        self.advance_remote()

        code, report = module.inventory(
            ["--repo", str(self.primary), "--remote", str(self.remote), "--show-paths"]
        )

        self.assertEqual(code, 1)
        commands = report["safe_primary_refresh"]["commands"]
        self.assertEqual(len(commands), 2)
        self.assertIn(f"merge --ff-only {git(self.external, 'rev-parse', 'HEAD')}", commands[1])
        for command in commands:
            subprocess.run(shlex.split(command), check=True, capture_output=True)
        self.assertEqual(git(self.primary, "rev-parse", "HEAD"), git(self.external, "rev-parse", "HEAD"))

    def test_remote_freshness_uses_registered_primary_context_from_linked_worktree(self):
        self.advance_remote()
        git(self.primary, "config", "extensions.worktreeConfig", "true")
        linked = self.root / "linked"
        git(self.primary, "worktree", "add", "-b", "codex/linked", str(linked))
        git(linked, "config", "--worktree", "remote.origin.url", str(self.root / "missing.git"))

        code, report = module.inventory(["--repo", str(linked), "--show-paths"])

        self.assertEqual(code, 1)
        self.assertEqual(report["status"], "blocked")
        self.assertEqual(report["primary"]["path"], str(self.primary.resolve()))
        self.assertEqual(report["primary"]["freshness"], "stale")
        self.assertEqual(report["primary"]["remote_main_sha"], git(self.external, "rev-parse", "HEAD"))
        commands = report["safe_primary_refresh"]["commands"]
        self.assertEqual(len(commands), 2)
        self.assertIn(f"git -C {shlex.quote(str(self.primary.resolve()))} fetch origin main", commands[0])
        self.assertIn(f"git -C {shlex.quote(str(self.primary.resolve()))} merge --ff-only", commands[1])

    def test_in_progress_operation_blocks_clean_worktree_candidate(self):
        secondary = self.root / "in-progress"
        git(self.primary, "worktree", "add", "-b", "codex/in-progress", str(secondary))
        marker = Path(git(secondary, "rev-parse", "--git-path", "CHERRY_PICK_HEAD"))
        marker.write_text("0" * 40 + "\n")

        code, report = module.inventory(["--repo", str(self.primary)])

        self.assertEqual(code, 1)
        self.assertEqual(report["status"], "blocked")
        self.assertEqual(report["counts"]["in_progress"], 1)
        self.assertIsNone(report["authority"]["pull_request"])
        candidate = next(item for item in report["worktrees"] if item["role"] == "pull-request-candidate")
        self.assertEqual(candidate["operation_state"], ["CHERRY_PICK_HEAD"])
        self.assertFalse(candidate["dirty"])

    def test_primary_operation_state_suppresses_refresh_command(self):
        self.advance_remote()
        marker = Path(git(self.primary, "rev-parse", "--git-path", "MERGE_HEAD"))
        if not marker.is_absolute():
            marker = self.primary / marker
        marker.write_text("0" * 40 + "\n")

        code, report = module.inventory(["--repo", str(self.primary)])

        self.assertEqual(code, 1)
        self.assertEqual(report["primary"]["freshness"], "stale")
        self.assertEqual(report["primary"]["operation_state"], ["MERGE_HEAD"])
        self.assertFalse(report["safe_primary_refresh"]["safe_ff_only"])
        self.assertEqual(report["safe_primary_refresh"]["commands"], [])

    def test_clean_primary_with_cherry_pick_state_is_not_authority(self):
        marker = Path(git(self.primary, "rev-parse", "--git-path", "CHERRY_PICK_HEAD"))
        if not marker.is_absolute():
            marker = self.primary / marker
        marker.write_text("0" * 40 + "\n")

        code, report = module.inventory(["--repo", str(self.primary)])

        self.assertEqual(code, 1)
        self.assertEqual(report["status"], "blocked")
        self.assertFalse(report["primary"]["dirty"])
        self.assertEqual(report["primary"]["operation_state"], ["CHERRY_PICK_HEAD"])
        self.assertIsNone(report["authority"]["source"])

    def test_active_bisect_in_linked_worktree_is_not_candidate(self):
        secondary = self.root / "bisect"
        git(self.primary, "worktree", "add", "-b", "codex/bisect", str(secondary))
        git(secondary, "bisect", "start")

        code, report = module.inventory(["--repo", str(self.primary)])

        self.assertEqual(code, 1)
        self.assertEqual(report["status"], "blocked")
        candidate = next(item for item in report["worktrees"] if item["role"] == "pull-request-candidate")
        self.assertEqual(candidate["operation_state"], ["BISECT_START", "BISECT_LOG", "BISECT_NAMES"])
        self.assertIsNone(report["authority"]["pull_request"])

    def test_submodule_changes_are_dirty_even_when_ignore_all_is_configured(self):
        subrepo = self.root / "subrepo"
        subprocess.run(["git", "init", "--initial-branch=main", str(subrepo)], check=True, capture_output=True)
        git(subrepo, "config", "user.email", "test@example.invalid")
        git(subrepo, "config", "user.name", "Inventory Test")
        (subrepo / "tracked.txt").write_text("initial\n")
        git(subrepo, "add", "tracked.txt")
        git(subrepo, "commit", "-m", "submodule initial")
        git(self.primary, "-c", "protocol.file.allow=always", "submodule", "add", str(subrepo), "vendor/child")
        git(self.primary, "commit", "-m", "add submodule fixture")
        git(self.primary, "config", "submodule.vendor/child.ignore", "all")
        (self.primary / "vendor/child/tracked.txt").write_text("changed\n")

        code, report = module.inventory(["--repo", str(self.primary)])

        self.assertEqual(code, 1)
        self.assertTrue(report["primary"]["dirty"])
        self.assertEqual(report["status"], "blocked")

    def test_git_repository_environment_cannot_override_repo_argument(self):
        other = self.root / "other"
        subprocess.run(["git", "init", "--initial-branch=main", str(other)], check=True, capture_output=True)
        git(other, "config", "user.email", "test@example.invalid")
        git(other, "config", "user.name", "Inventory Test")
        (other / "other.txt").write_text("other\n")
        git(other, "add", "other.txt")
        git(other, "commit", "-m", "other")

        with mock.patch.dict(
            module.os.environ,
            {
                "GIT_DIR": str(other / ".git"),
                "GIT_WORK_TREE": str(other),
                "GIT_INDEX_FILE": str(other / ".git/index"),
            },
            clear=False,
        ):
            code, output, _ = module._run_git(self.primary, ["rev-parse", "--show-toplevel"])

        self.assertEqual(code, 0)
        self.assertEqual(Path(output.decode().strip()).resolve(), self.primary.resolve())

    def test_global_git_config_cannot_redirect_remote_freshness(self):
        alternate = self.root / "alternate.git"
        subprocess.run(["git", "init", "--bare", str(alternate)], check=True, capture_output=True)
        git(self.primary, "push", str(alternate), "main")
        self.advance_remote()
        global_config = self.root / "global.gitconfig"
        subprocess.run(
            [
                "git",
                "config",
                "--file",
                str(global_config),
                f"url.{alternate}.insteadOf",
                str(self.remote),
            ],
            check=True,
            capture_output=True,
        )

        with mock.patch.dict(module.os.environ, {"GIT_CONFIG_GLOBAL": str(global_config)}, clear=False):
            code, report = module.inventory(["--repo", str(self.primary)])

        self.assertEqual(code, 1)
        self.assertEqual(report["primary"]["freshness"], "stale")
        self.assertEqual(report["primary"]["remote_main_sha"], git(self.external, "rev-parse", "HEAD"))

    def test_replaced_registered_path_cannot_masquerade_as_same_head_worktree(self):
        candidate = self.root / "release-candidate"
        decoy = self.root / "other-decoy"
        git(self.primary, "worktree", "add", "--detach", str(candidate), "HEAD")
        git(self.primary, "worktree", "add", "--detach", str(decoy), "HEAD")
        import shutil
        shutil.rmtree(candidate)
        candidate.symlink_to(decoy, target_is_directory=True)

        code, report = module.inventory(["--repo", str(self.primary)])

        self.assertEqual(code, 1)
        self.assertEqual(report["status"], "unknown")
        self.assertIsNone(report["authority"]["release"])
        replacement = next(item for item in report["worktrees"] if item["role"] == "release-candidate")
        self.assertFalse(replacement["identity_verified"])

    def test_partial_clone_does_not_lazy_fetch_remote_commit(self):
        git(self.remote, "config", "uploadpack.allowFilter", "true")
        git(self.remote, "config", "uploadpack.allowAnySHA1InWant", "true")
        partial = self.root / "partial"
        subprocess.run(
            ["git", "clone", "--filter=blob:none", f"file://{self.remote}", str(partial)],
            check=True,
            capture_output=True,
        )
        self.advance_remote(fetch_primary=False)
        pack_dir = partial / git(partial, "rev-parse", "--git-dir") / "objects" / "pack"
        before = sorted(path.name for path in pack_dir.glob("*") if path.is_file())

        code, report = module.inventory(["--repo", str(partial)])

        after = sorted(path.name for path in pack_dir.glob("*") if path.is_file())
        self.assertEqual(code, 1)
        self.assertEqual(report["status"], "unknown")
        self.assertEqual(report["primary"]["freshness"], "unknown")
        self.assertEqual(report["primary"]["remote_main_sha"], git(self.external, "rev-parse", "HEAD"))
        self.assertEqual(after, before)

    def test_git_probes_disable_lazy_fetch(self):
        real_run = module.subprocess.run
        captured = {}

        def capture_run(*args, **kwargs):
            captured.update(kwargs)
            return real_run(*args, **kwargs)

        module.subprocess.run = capture_run
        try:
            code, _, _ = module._run_git(self.primary, ["rev-parse", "HEAD"])
        finally:
            module.subprocess.run = real_run

        self.assertEqual(code, 0)
        self.assertEqual(captured["env"]["GIT_NO_LAZY_FETCH"], "1")
        self.assertEqual(captured["env"]["GIT_NO_REPLACE_OBJECTS"], "1")
        for name in module.GIT_REPOSITORY_ENV:
            if name == "GIT_NO_REPLACE_OBJECTS":
                continue
            self.assertNotIn(name, captured["env"])

    def test_remote_url_credentials_are_redacted_from_default_report(self):
        secret_url = "https://user:secret@example.invalid/repo.git"

        code, report = module.inventory(["--repo", str(self.primary), "--remote", secret_url])
        serialized = json.dumps(report)

        self.assertEqual(code, 1)
        self.assertEqual(report["remote"], "<remote>")
        self.assertNotIn("secret", serialized)
        self.assertNotIn(secret_url, serialized)

    def test_remote_url_credentials_remain_redacted_with_show_paths(self):
        self.advance_remote()
        secret_url = "https://user:secret@example.invalid/repo.git"
        remote_sha = git(self.external, "rev-parse", "HEAD")
        real_runner = module._run_git

        def credential_remote_runner(repo, arguments, timeout=module.TIMEOUT):
            if arguments[:1] == ["ls-remote"]:
                return 0, f"{remote_sha}\trefs/heads/main\n".encode(), b""
            return real_runner(repo, arguments, timeout)

        code, report = module.inventory(
            ["--repo", str(self.primary), "--remote", secret_url, "--show-paths"],
            runner=credential_remote_runner,
        )
        serialized = json.dumps(report)

        self.assertEqual(code, 1)
        self.assertTrue(report["safe_primary_refresh"]["safe_ff_only"])
        self.assertEqual(report["remote"], "<remote>")
        self.assertNotIn("secret", serialized)
        self.assertNotIn(secret_url, serialized)
        self.assertIn("fetch '<remote>' main", report["safe_primary_refresh"]["commands"][0])

    def test_status_failure_blocks_refresh_without_claiming_dirty(self):
        real_runner = module._run_git

        def failing_runner(repo, arguments, timeout=module.TIMEOUT):
            if Path(repo).resolve() == self.primary.resolve() and arguments[:1] == ["status"]:
                return 1, b"", b"status unavailable"
            return real_runner(repo, arguments, timeout)

        code, report = module.inventory(["--repo", str(self.primary)], runner=failing_runner)

        self.assertEqual(code, 1)
        self.assertEqual(report["status"], "unknown")
        self.assertIsNone(report["primary"]["dirty"])
        self.assertFalse(report["safe_primary_refresh"]["safe_ff_only"])
        self.assertEqual(report["safe_primary_refresh"]["commands"], [])

    def test_failed_prune_dry_run_blocks_ready_source_claim(self):
        real_runner = module._run_git

        def failing_prune_runner(repo, arguments, timeout=module.TIMEOUT):
            if arguments[:3] == ["worktree", "prune", "--dry-run"]:
                return 1, b"", b"prune unavailable"
            return real_runner(repo, arguments, timeout)

        code, report = module.inventory(["--repo", str(self.primary)], runner=failing_prune_runner)

        self.assertEqual(code, 1)
        self.assertEqual(report["status"], "unknown")
        self.assertIsNone(report["authority"]["source"])
        self.assertIn("prunable registration check unavailable", report["observations"])

    def test_unreadable_secondary_worktree_is_not_an_authority_candidate(self):
        secondary = self.root / "pr-candidate"
        git(self.primary, "worktree", "add", "-b", "codex/candidate", str(secondary))
        real_runner = module._run_git

        def failing_secondary_runner(repo, arguments, timeout=module.TIMEOUT):
            if Path(repo).resolve() == secondary.resolve() and arguments[:1] == ["status"]:
                return 1, b"", b"status unavailable"
            return real_runner(repo, arguments, timeout)

        code, report = module.inventory(["--repo", str(self.primary)], runner=failing_secondary_runner)

        self.assertEqual(code, 1)
        self.assertEqual(report["status"], "unknown")
        self.assertIsNone(report["authority"]["pull_request"])

    def test_dirty_secondary_worktree_is_not_an_authority_candidate(self):
        secondary = self.root / "release-candidate"
        git(self.primary, "worktree", "add", "-b", "codex/release-candidate", str(secondary))
        (secondary / "local-note.txt").write_text("uncommitted\n")

        code, report = module.inventory(["--repo", str(self.primary)])

        self.assertEqual(code, 1)
        self.assertEqual(report["status"], "blocked")
        self.assertIsNone(report["authority"]["release"])

    def test_valid_clean_secondary_worktree_is_a_candidate(self):
        secondary = self.root / "pr-candidate"
        git(self.primary, "worktree", "add", "-b", "codex/candidate", str(secondary))

        code, report = module.inventory(["--repo", str(self.primary)])

        self.assertEqual(code, 0)
        self.assertEqual(report["status"], "ready")
        self.assertIsNotNone(report["authority"]["pull_request"])

    def test_newline_in_primary_path_is_preserved_by_porcelain_inventory(self):
        newline_primary = self.root / "primary\ncheckout"
        subprocess.run(["git", "clone", "-q", str(self.remote), str(newline_primary)], check=True)

        code, report = module.inventory(["--repo", str(newline_primary), "--show-paths"])

        self.assertEqual(code, 0)
        self.assertEqual(report["primary"]["path"], str(newline_primary.resolve()))
        self.assertTrue(report["primary"]["identity_verified"])

    def test_reused_registered_path_cannot_masquerade_as_worktree(self):
        secondary = self.root / "pr-candidate"
        git(self.primary, "worktree", "add", "-b", "codex/candidate", str(secondary))
        import shutil
        shutil.rmtree(secondary)
        subprocess.run(["git", "init", "--initial-branch=main", str(secondary)], check=True, capture_output=True)
        git(secondary, "config", "user.email", "test@example.invalid")
        git(secondary, "config", "user.name", "Replacement Repository")
        (secondary / "README.md").write_text("replacement\n")
        git(secondary, "add", "README.md")
        git(secondary, "commit", "-m", "replacement")

        code, report = module.inventory(["--repo", str(self.primary)])

        self.assertEqual(code, 1)
        self.assertEqual(report["status"], "unknown")
        self.assertIsNone(report["authority"]["pull_request"])
        replacement = next(item for item in report["worktrees"] if item["role"] == "pull-request-candidate")
        self.assertFalse(replacement["identity_verified"])

    def test_labels_are_unique_when_worktree_basenames_collide(self):
        first = self.root / "one" / "checkout"
        second = self.root / "two" / "checkout"
        first.parent.mkdir()
        second.parent.mkdir()
        git(self.primary, "worktree", "add", "-b", "codex/one", str(first))
        git(self.primary, "worktree", "add", "-b", "codex/two", str(second))

        code, report = module.inventory(["--repo", str(self.primary)])

        self.assertEqual(code, 0)
        labels = [entry["label"] for entry in report["worktrees"]]
        displays = [entry["display_path"] for entry in report["worktrees"]]
        self.assertEqual(len(labels), len(set(labels)))
        self.assertEqual(len(displays), len(set(displays)))
        self.assertTrue(all("checkout" not in value for value in displays))

    def test_labels_persist_when_a_new_registration_is_inserted(self):
        first = self.root / "one" / "checkout"
        second = self.root / "two" / "checkout"
        first.parent.mkdir()
        second.parent.mkdir()
        git(self.primary, "worktree", "add", "-b", "codex/one", str(first))
        git(self.primary, "worktree", "add", "-b", "codex/two", str(second))

        _, before = module.inventory(["--repo", str(self.primary), "--show-paths"])
        before_labels = {entry["path"]: entry["label"] for entry in before["worktrees"]}
        inserted = self.root / "inserted"
        git(self.primary, "worktree", "add", "-b", "codex/inserted", str(inserted))

        _, after = module.inventory(["--repo", str(self.primary), "--show-paths"])
        after_labels = {entry["path"]: entry["label"] for entry in after["worktrees"]}

        for path, label in before_labels.items():
            self.assertEqual(after_labels[path], label)


if __name__ == "__main__":
    unittest.main()
