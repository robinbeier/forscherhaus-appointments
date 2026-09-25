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
            script.write_text("#!/bin/bash\n# " + path + "\n")
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
          npm test
"""
        self.assertFalse(module.has_route(path, workflow, set(), []))
        self.assertTrue(module.has_route(path, workflow + "      - run: node pdf-renderer/new.test.js\n", set(), []))


if __name__ == "__main__":
    unittest.main()
