"""Regression cases for CI test-route admission."""

import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest


SCRIPT = Path(__file__).parents[2] / "scripts/ci/check_test_routing.py"
spec = importlib.util.spec_from_file_location("check_test_routing", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class TestRoutingGuardTest(unittest.TestCase):
    def test_push_uses_event_before_and_requires_a_valid_commit(self):
        before = "a" * 40
        self.assertEqual(before, module.comparison_base(None, "push", before))
        self.assertEqual("origin/main", module.comparison_base(None, "pull_request", None))
        self.assertEqual("HEAD~2", module.comparison_base("HEAD~2", "push", None))
        for invalid in (None, "", "0" * 40, "not-a-commit"):
            with self.subTest(invalid=invalid), self.assertRaises(SystemExit):
                module.comparison_base(None, "push", invalid)

    def test_comparison_includes_tests_from_earlier_commit_in_push(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)

            def git(*arguments):
                return subprocess.run(
                    ["git", *arguments], cwd=root, check=True, capture_output=True, text=True
                ).stdout.strip()

            git("init", "--initial-branch=main")
            git("config", "user.email", "test@example.invalid")
            git("config", "user.name", "Routing Test")
            (root / "README.md").write_text("initial\n")
            git("add", ".")
            git("commit", "-m", "initial")
            before = git("rev-parse", "HEAD")

            test_path = root / "tests/Integration/EarlierTest.php"
            test_path.parent.mkdir(parents=True)
            test_path.write_text("<?php\n")
            git("add", ".")
            git("commit", "-m", "add unrouted test")
            (root / "README.md").write_text("later\n")
            git("commit", "-am", "later change")

            self.assertEqual([], module.added_test_files("HEAD^", root))
            self.assertEqual(
                ["tests/Integration/EarlierTest.php"],
                module.added_test_files(module.comparison_base(None, "push", before), root),
            )

    def test_rename_destination_is_checked_as_a_new_route(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)

            def git(*arguments):
                subprocess.run(["git", *arguments], cwd=root, check=True, capture_output=True)

            git("init", "--initial-branch=main")
            git("config", "user.email", "test@example.invalid")
            git("config", "user.name", "Routing Test")
            source = root / "tests/Unit/MovedTest.php"
            source.parent.mkdir(parents=True)
            source.write_text("<?php\n")
            git("add", ".")
            git("commit", "-m", "add routed test")

            destination = root / "tests/Integration/MovedTest.php"
            destination.parent.mkdir(parents=True)
            git("mv", str(source), str(destination))
            git("commit", "-m", "move to unrouted suite")

            self.assertEqual(
                ["tests/Integration/MovedTest.php"],
                module.added_test_files("HEAD^", root),
            )
            self.assertFalse(
                module.has_route("tests/Integration/MovedTest.php", "", set(), ["tests/Unit/"], root)
            )

    def test_excluded_root_group_requires_an_explicit_root_script_route(self):
        path = "tests/Unit/Scripts/NewRootTest.php"
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "tests/Unit/Scripts").mkdir(parents=True)
            (root / path).write_text("<?php #[Group('root-deployment')]\n")
            script = root / module.ROOT_DEPLOYMENT_SCRIPT
            script.parent.mkdir(parents=True)
            script.write_text("#!/bin/bash\nphp vendor/bin/phpunit existing.php # " + path + "\n")
            workflow = "  root-deployment-tests:\n    steps:\n      - run: bash " + module.ROOT_DEPLOYMENT_SCRIPT + "\n"
            self.assertFalse(module.has_route(path, workflow, set(), ["tests/Unit/"], root))

            script.write_text("#!/bin/bash\nphp vendor/bin/phpunit " + path + "\n")
            self.assertTrue(module.has_route(path, workflow, set(), ["tests/Unit/"], root))

    def test_non_php_reference_must_be_in_a_run_step(self):
        path = "pdf-renderer/new.test.js"
        workflow = """  js-test:
    steps:
      - uses: dorny/paths-filter@v3
        with:
          filters: |
            - 'pdf-renderer/new.test.js'
      - name: Receipt
        with:
          path: pdf-renderer/new.test.js
      - run: |
          # pdf-renderer/new.test.js
          node --test existing.test.js # pdf-renderer/new.test.js
"""
        self.assertFalse(module.has_route(path, workflow, set(), []))
        self.assertTrue(module.has_route(path, workflow + "      - run: node pdf-renderer/new.test.js\n", set(), []))

    def test_shell_comment_filter_preserves_quoted_hashes(self):
        self.assertEqual(
            "node --test 'fixture#one.test.js' ",
            module.without_shell_comment("node --test 'fixture#one.test.js' # ignored.test.js"),
        )


if __name__ == "__main__":
    unittest.main()
