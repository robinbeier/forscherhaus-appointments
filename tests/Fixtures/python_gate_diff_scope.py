"""Ordinary temporary-Git contracts for the real Python gate range readers."""

import importlib.util
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch


SOURCE_ROOT = Path(sys.argv.pop(1))
sys.path.insert(0, str(SOURCE_ROOT / "scripts/ci"))


def load_gate(name):
    spec = importlib.util.spec_from_file_location(name, SOURCE_ROOT / "scripts/ci" / f"{name}.py")
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


class PythonGateDiffScope(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="python-gate-scope-")
        self.addCleanup(self.temporary.cleanup)
        self.repo = Path(self.temporary.name)
        self.ownership = load_gate("check_architecture_ownership_map")
        self.component = load_gate("check_component_boundaries")
        self.ownership.ROOT = self.repo
        self.component.ROOT = self.repo
        self.git("init", "-b", "main")
        self.git("config", "user.name", "Synthetic Gate Test")
        self.git("config", "user.email", "gate@example.invalid")
        self.write_commit("README.md", "base", "base")
        self.git("checkout", "-b", "feature")
        self.early = "application/controllers/Unmapped.php"
        self.write_commit(self.early, "<?php // synthetic source fixture\n", "early source")
        self.write_commit("notes.txt", "unrelated tip", "unrelated")
        self.git("checkout", "main")
        self.write_commit("README.md", "upstream", "upstream change")
        self.git("branch", "release/stable")
        self.git("checkout", "feature")
        self.git("merge", "--no-ff", "main", "-m", "integrate upstream")
        # This local self-remote permits real fetch/merge-base without network access.
        self.git("remote", "add", "origin", str(self.repo))
        self.env = patch.dict(os.environ, {"GITHUB_EVENT_NAME": "pull_request"})
        self.env.start()
        self.addCleanup(self.env.stop)
        for name in ["ARCH_OWNERSHIP_DIFF_RANGE", "COMPONENT_TEST_RANGE"]:
            os.environ.pop(name, None)

    def git(self, *args):
        result = subprocess.run(
            ["git", "-c", "core.hooksPath=/dev/null", *args],
            cwd=self.repo, text=True, capture_output=True, check=True,
        )
        return result.stdout.strip()

    def write_commit(self, name, contents, message):
        path = self.repo / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(contents)
        self.git("add", name)
        self.git("commit", "-m", message)

    def range_for(self, module):
        if module is self.ownership:
            return module.detect_diff_range()
        return module.detect_diff_range(None, "COMPONENT_TEST_RANGE")

    def test_merge_history_retains_early_source_for_both_base_forms_and_slash_branch(self):
        self.assertNotIn(self.early, self.git("diff", "--name-only", "HEAD~1...HEAD").splitlines())
        for base in ["main", "origin/main", "release/stable", "origin/release/stable"]:
            with self.subTest(base=base):
                os.environ["GITHUB_BASE_REF"] = base
                for module in [self.ownership, self.component]:
                    changed = module.get_changed_files(self.range_for(module))
                    self.assertEqual([self.early, "notes.txt"], changed)
                errors = self.ownership.validate_changed_file_coverage({"components": []})
                self.assertEqual(1, len(errors))
                self.assertIn(f"not mapped to any component: {self.early}", errors[0])

    def test_missing_pr_base_is_an_error_even_when_previous_commit_exists(self):
        os.environ["GITHUB_BASE_REF"] = "missing-base"
        for module in [self.ownership, self.component]:
            with self.subTest(module=module.__name__):
                with self.assertRaisesRegex(RuntimeError, "Unable to resolve pull-request base"):
                    self.range_for(module)

    def test_explicit_valid_range_is_preserved_and_invalid_range_is_not_empty_success(self):
        os.environ["GITHUB_BASE_REF"] = "missing-base"
        for module, key in [(self.ownership, "ARCH_OWNERSHIP_DIFF_RANGE"), (self.component, "COMPONENT_TEST_RANGE")]:
            with self.subTest(module=module.__name__):
                os.environ[key] = "main...HEAD"
                self.assertEqual("main...HEAD", self.range_for(module))
                self.assertIn(self.early, module.get_changed_files(self.range_for(module)))
                os.environ[key] = "missing-revision...HEAD"
                with self.assertRaisesRegex(RuntimeError, "Failed to compute changed files"):
                    module.get_changed_files(self.range_for(module))


if __name__ == "__main__":
    unittest.main()
