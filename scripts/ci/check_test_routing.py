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
ROOT_DEPLOYMENT_SCRIPT = "scripts/ci/run_root_deployment_regressions.sh"


def job_body(workflow: str, name: str) -> str:
    match = re.search(rf"(?ms)^  {re.escape(name)}:\n(.*?)(?=^  [a-z][a-z0-9-]*:\n|\Z)", workflow)
    return match.group(1) if match else ""


def without_shell_comment(command: str) -> str:
    quote: str | None = None
    escaped = False
    for index, character in enumerate(command):
        if escaped:
            escaped = False
        elif character == "\\" and quote != "'":
            escaped = True
        elif character in ("'", '"'):
            if quote is None:
                quote = character
            elif quote == character:
                quote = None
        elif character == "#" and quote is None and (
            index == 0 or command[index - 1].isspace() or command[index - 1] in ";|&()"
        ):
            return command[:index]
    return command


def run_commands(workflow: str) -> str:
    """Read workflow step commands, excluding filters, artifacts and comments."""
    lines = workflow.splitlines()
    commands: list[str] = []
    index = 0
    while index < len(lines):
        match = re.match(r"^(\s*)(?:-\s+)?run:\s*(.*)$", lines[index])
        if not match:
            index += 1
            continue
        indent = len(match.group(1))
        inline = match.group(2)
        if inline not in ("", "|", ">", "|-", ">-"):
            commands.append(without_shell_comment(inline))
        index += 1
        while index < len(lines):
            line = lines[index]
            if line.strip() and len(line) - len(line.lstrip()) <= indent:
                break
            if line.strip() and not line.lstrip().startswith("#"):
                commands.append(without_shell_comment(line))
            index += 1
    return "\n".join(commands)


def contains_test_argument(commands: str, name: str, *, module: bool = False) -> bool:
    """Find a complete path/module token, never a prefix of another test."""
    word_characters = r"A-Za-z0-9_." if module else r"A-Za-z0-9_./-"
    return re.search(rf"(?<![{word_characters}]){re.escape(name)}(?![{word_characters}])", commands) is not None


def added_test_files(base: str, root: Path = ROOT) -> list[str]:
    result = subprocess.run(
        ["git", "diff", "--no-renames", "--name-only", "--diff-filter=A", f"{base}...HEAD", "--", "tests", "pdf-renderer"],
        cwd=root,
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


def has_route(path: str, workflow: str, files: set[str], directories: list[str], root: Path = ROOT) -> bool:
    if path.endswith("Test.php") and (path in files or any(path.startswith(directory) for directory in directories)):
        source = (root / path).read_text(encoding="utf-8")
        # Treat any mention of the excluded group conservatively: PHPUnit may
        # spell its attribute through a namespace or import alias. A false
        # positive only asks for an explicit routing decision on a new file.
        if "root-deployment" in source:
            script = (root / ROOT_DEPLOYMENT_SCRIPT).read_text(encoding="utf-8")
            script_commands = "\n".join(without_shell_comment(line) for line in script.splitlines())
            return (
                contains_test_argument(run_commands(workflow), ROOT_DEPLOYMENT_SCRIPT)
                and contains_test_argument(script_commands, path)
            )
        return True
    # Other test types need an explicit CI invocation or a reviewed wrapper.
    # A new indirect wrapper is intentionally flagged for a routing decision.
    commands = run_commands(workflow)
    return contains_test_argument(commands, path) or (
        path.endswith(".py") and contains_test_argument(
            commands, path.removesuffix(".py").replace("/", "."), module=True
        )
    )


def comparison_base(explicit_base: str | None, event_name: str | None, event_before: str | None) -> str:
    if explicit_base:
        return explicit_base
    if event_name != "push":
        return "origin/main"
    if (
        not event_before
        or not re.fullmatch(r"[a-f0-9]{40}|[a-f0-9]{64}", event_before)
        or set(event_before) == {"0"}
    ):
        raise SystemExit("Push event lacks a valid before commit for the complete routing comparison")
    return event_before


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", default=None)
    args = parser.parse_args()
    base = comparison_base(args.base, os.getenv("GITHUB_EVENT_NAME"), os.getenv("GITHUB_EVENT_BEFORE"))

    workflow = WORKFLOW.read_text(encoding="utf-8")
    missing_required = [
        f"{job}: {token}"
        for job, tokens in REQUIRED_DIRECT_ROUTES.items()
        for token in tokens
        if not contains_test_argument(run_commands(job_body(workflow, job)), token)
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
