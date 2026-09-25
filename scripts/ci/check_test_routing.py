#!/usr/bin/env python3
"""Flag new test files without a CI route; pin three previously missed routes."""

from __future__ import annotations

import argparse
import os
import re
import subprocess
from pathlib import Path
from xml.etree import ElementTree


ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github/workflows/ci.yml"
# Only suites selected by the current CI workflow or its called helpers. The
# broader phpunit.coverage.xml is not a CI route.
ACTIVE_PHPUNIT_CONFIGS = (
    "phpunit.xml",
    "phpunit.booking-flows.xml",
    "phpunit.coverage.integration.xml",
    "phpunit.coverage.unit.xml",
    "phpunit.defense-cycle.xml",
    "phpunit.request-contracts.xml",
    "phpunit.request-dto.xml",
)
REQUIRED_DIRECT_ROUTES = {
    "calendar-canary-regressions": (
        "tests/Integration/Controllers/CalendarCombinedAuthorizationTest.php",
        "tests/Integration/ZeroSurpriseCanaryFixtureTest.php",
        "FH_CANARY_INTEGRATION=1",
        "--fail-on-skipped",
    ),
    "python-worktree-inventory": ("tests.Python.test_worktree_inventory",),
}


def job_body(workflow: str, name: str) -> str:
    match = re.search(rf"(?ms)^  {re.escape(name)}:\n(.*?)(?=^  [a-z][a-z0-9-]*:\n|\Z)", workflow)
    return match.group(1) if match else ""


def added_test_files(base: str) -> list[str]:
    result = subprocess.run(
        ["git", "diff", "--name-only", "--diff-filter=A", f"{base}...HEAD", "--", "tests", "pdf-renderer"],
        cwd=ROOT,
        check=True,
        capture_output=True,
        text=True,
    )
    return [
        path for path in result.stdout.splitlines()
        if path.endswith(("Test.php", ".test.js", "_test.py"))
        or (path.startswith("tests/Python/test_") and path.endswith(".py"))
    ]


def configured_php_tests() -> tuple[set[str], list[str]]:
    files: set[str] = set()
    directories: list[str] = []
    for config_name in ACTIVE_PHPUNIT_CONFIGS:
        tree = ElementTree.parse(ROOT / config_name)
        for node in tree.findall(".//testsuite/file"):
            if node.text:
                files.add(node.text.strip().removeprefix("./"))
        for node in tree.findall(".//testsuite/directory"):
            if node.text and node.attrib.get("suffix", "Test.php") == "Test.php":
                directories.append(node.text.strip().removeprefix("./").rstrip("/") + "/")
    return files, directories


def has_route(path: str, workflow: str, files: set[str], directories: list[str]) -> bool:
    if path.endswith("Test.php") and (path in files or any(path.startswith(directory) for directory in directories)):
        return True
    # Other test types need an explicit CI invocation or a reviewed wrapper.
    # A new indirect wrapper is intentionally flagged for a routing decision.
    return path in workflow or (path.endswith(".py") and path.removesuffix(".py").replace("/", ".") in workflow)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", default=None)
    args = parser.parse_args()
    base = args.base or ("HEAD^" if os.getenv("GITHUB_EVENT_NAME") == "push" else "origin/main")

    workflow = WORKFLOW.read_text(encoding="utf-8")
    missing_required = [
        f"{job}: {token}"
        for job, tokens in REQUIRED_DIRECT_ROUTES.items()
        for token in tokens
        if token not in job_body(workflow, job)
    ]
    if missing_required:
        raise SystemExit("Required direct test routing missing: " + ", ".join(missing_required))

    files, directories = configured_php_tests()
    unrouted = [path for path in added_test_files(base) if not has_route(path, workflow, files, directories)]
    if unrouted:
        raise SystemExit("New test files need a reviewed CI route: " + ", ".join(unrouted))
    print("Required test routes declared; no newly added test file lacks a route candidate")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
