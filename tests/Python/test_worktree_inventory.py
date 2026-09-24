import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest


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

    def advance_remote(self):
        (self.external / "README.md").write_text("remote advance\n")
        git(self.external, "add", "README.md")
        git(self.external, "commit", "-m", "advance remote")
        git(self.external, "push", "origin", "main")

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
        self.assertIn("merge --ff-only origin/main", report["safe_primary_refresh"]["commands"][1])
        self.assertNotIn(str(self.primary), " ".join(report["safe_primary_refresh"]["commands"]))
        self.assertNotIn("--prune", " ".join(report["safe_primary_refresh"]["commands"]))
        self.assertIsNone(report["authority"]["source"])
        self.assertEqual(len(report["prunable_suggestions"]), 1)
        self.assertIn("no automatic prune", report["prunable_suggestions"][0]["action"])
        self.assertTrue(missing.parent.exists())
        self.assertFalse(missing.exists())

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

    def test_custom_remote_path_is_redacted_from_default_report_and_commands(self):
        self.advance_remote()

        code, report = module.inventory(["--repo", str(self.primary), "--remote", str(self.remote)])
        serialized = json.dumps(report)

        self.assertEqual(code, 1)
        self.assertEqual(report["remote"], "<remote>")
        self.assertNotIn(str(self.remote), serialized)
        self.assertNotIn(str(self.remote), " ".join(report["safe_primary_refresh"]["commands"]))
        self.assertIn("git fetch '<remote>' main", report["safe_primary_refresh"]["commands"][0])

    def test_remote_url_credentials_are_redacted_from_default_report(self):
        secret_url = "https://user:secret@example.invalid/repo.git"

        code, report = module.inventory(["--repo", str(self.primary), "--remote", secret_url])
        serialized = json.dumps(report)

        self.assertEqual(code, 1)
        self.assertEqual(report["remote"], "<remote>")
        self.assertNotIn("secret", serialized)
        self.assertNotIn(secret_url, serialized)

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
