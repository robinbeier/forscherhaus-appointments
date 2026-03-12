#!/usr/bin/env python3
"""Validate architecture/ownership component mapping and change coverage."""

from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
MAP_PATH = ROOT / "docs/maps/component_ownership_map.json"
GENERATOR_CHECK_CMD = [
    sys.executable,
    str(ROOT / "scripts/docs/generate_architecture_ownership_docs.py"),
    "--check",
]
HANDLE_RE = re.compile(r"^@[A-Za-z0-9-]+$")
OWNERSHIP_MODES = {"single-owner", "multi-owner"}
AGENT_POLICIES = {"conservative", "standard"}
REQUIRED_COMPONENT_FIELDS = {
    "component_id",
    "role",
    "summary",
    "primary_handle",
    "secondary_handle",
    "ownership_mode",
    "human_bus_factor",
    "agent_policy",
    "manual_approval_required",
    "ownership_notes",
    "folder_prefixes",
    "key_files",
    "depends_on",
}
CHANGE_SCOPE_PREFIXES = [
    "application/controllers/",
    "application/libraries/",
    "application/models/",
    "application/views/pages/",
    "application/views/components/",
    "assets/js/pages/",
    "assets/js/components/",
    "scripts/ci/",
    "scripts/release-gate/",
]


def run(cmd: list[str], check: bool = True) -> subprocess.CompletedProcess[str]:
    if cmd and cmd[0] == "git":
        cmd = ["git", "-c", f"safe.directory={ROOT}"] + cmd[1:]

    return subprocess.run(cmd, cwd=ROOT, check=check, text=True, capture_output=True)


def get_tracked_files() -> set[str]:
    proc = run(["git", "ls-files"])
    files = {line.strip() for line in proc.stdout.splitlines() if line.strip()}
    return files


def load_map(path: Path) -> dict[str, Any]:
    with path.open("r", encoding="utf-8") as fh:
        data = json.load(fh)

    if not isinstance(data, dict):
        raise ValueError("Top-level mapping must be a JSON object.")

    if not isinstance(data.get("schema_version"), int):
        raise ValueError('"schema_version" must be an integer.')

    components = data.get("components")
    if not isinstance(components, list) or not components:
        raise ValueError('"components" must be a non-empty list.')

    return data


def prefix_exists(prefix: str, tracked_files: set[str]) -> bool:
    path = ROOT / prefix
    if path.exists():
        return True

    return any(file_path == prefix or file_path.startswith(prefix) for file_path in tracked_files)


def validate_structure(data: dict[str, Any], tracked_files: set[str]) -> list[str]:
    errors: list[str] = []
    components = data["components"]

    seen_ids: set[str] = set()
    component_ids: set[str] = set()

    for index, component in enumerate(components):
        if not isinstance(component, dict):
            errors.append(f"components[{index}] must be an object")
            continue

        missing = REQUIRED_COMPONENT_FIELDS - set(component.keys())
        if missing:
            errors.append(f"components[{index}] missing required fields: {sorted(missing)}")
            continue

        component_id = component["component_id"]
        if not isinstance(component_id, str) or not component_id.strip():
            errors.append(f"components[{index}].component_id must be a non-empty string")
            continue

        if component_id in seen_ids:
            errors.append(f"Duplicate component_id: {component_id}")
        seen_ids.add(component_id)
        component_ids.add(component_id)

        for handle_key in ["primary_handle", "secondary_handle"]:
            handle = component[handle_key]
            if not isinstance(handle, str) or not HANDLE_RE.fullmatch(handle):
                errors.append(
                    f"{component_id}.{handle_key} must match pattern {HANDLE_RE.pattern}; got {handle!r}",
                )

        for text_key in ["role", "summary", "ownership_notes"]:
            value = component[text_key]
            if not isinstance(value, str) or not value.strip():
                errors.append(f"{component_id}.{text_key} must be a non-empty string")

        ownership_mode = component["ownership_mode"]
        if not isinstance(ownership_mode, str) or ownership_mode not in OWNERSHIP_MODES:
            errors.append(
                f"{component_id}.ownership_mode must be one of {sorted(OWNERSHIP_MODES)}; got {ownership_mode!r}",
            )

        human_bus_factor = component["human_bus_factor"]
        if not isinstance(human_bus_factor, int) or human_bus_factor < 1:
            errors.append(f"{component_id}.human_bus_factor must be an integer >= 1")

        agent_policy = component["agent_policy"]
        if not isinstance(agent_policy, str) or agent_policy not in AGENT_POLICIES:
            errors.append(
                f"{component_id}.agent_policy must be one of {sorted(AGENT_POLICIES)}; got {agent_policy!r}",
            )

        manual_approval_required = component["manual_approval_required"]
        if not isinstance(manual_approval_required, bool):
            errors.append(f"{component_id}.manual_approval_required must be a boolean")

        if ownership_mode == "single-owner":
            if component["primary_handle"] != component["secondary_handle"]:
                errors.append(
                    f"{component_id} uses single-owner mode and must keep identical primary/secondary handles",
                )
            if human_bus_factor != 1:
                errors.append(f"{component_id} uses single-owner mode and must declare human_bus_factor = 1")
            if agent_policy != "conservative":
                errors.append(f"{component_id} uses single-owner mode and must declare agent_policy = 'conservative'")
            if manual_approval_required is not True:
                errors.append(
                    f"{component_id} uses single-owner mode and must set manual_approval_required = true",
                )
        elif ownership_mode == "multi-owner":
            if component["primary_handle"] == component["secondary_handle"]:
                errors.append(
                    f"{component_id} uses multi-owner mode and must not duplicate primary/secondary handles",
                )
            if human_bus_factor < 2:
                errors.append(f"{component_id} uses multi-owner mode and must declare human_bus_factor >= 2")

        folder_prefixes = component["folder_prefixes"]
        if not isinstance(folder_prefixes, list) or not folder_prefixes:
            errors.append(f"{component_id}.folder_prefixes must be a non-empty list")
        else:
            for prefix in folder_prefixes:
                if not isinstance(prefix, str) or not prefix.strip():
                    errors.append(f"{component_id}.folder_prefixes contains an invalid empty entry")
                    continue

                if not prefix_exists(prefix, tracked_files):
                    errors.append(f"{component_id}.folder_prefix does not match existing repo path/prefix: {prefix}")

        key_files = component["key_files"]
        if not isinstance(key_files, list) or not key_files:
            errors.append(f"{component_id}.key_files must be a non-empty list")
        else:
            for key_file in key_files:
                if not isinstance(key_file, str) or not key_file.strip():
                    errors.append(f"{component_id}.key_files contains an invalid empty entry")
                    continue

                if key_file not in tracked_files:
                    errors.append(f"{component_id}.key_file does not exist in repo: {key_file}")

        depends_on = component["depends_on"]
        if not isinstance(depends_on, list):
            errors.append(f"{component_id}.depends_on must be a list")
        else:
            for dep in depends_on:
                if not isinstance(dep, str) or not dep.strip():
                    errors.append(f"{component_id}.depends_on contains invalid dependency: {dep!r}")

    # dependency existence validation in second pass
    for component in components:
        if not isinstance(component, dict) or "component_id" not in component:
            continue

        component_id = component["component_id"]
        depends_on = component.get("depends_on", [])
        if not isinstance(depends_on, list):
            continue

        for dep in depends_on:
            if isinstance(dep, str) and dep not in component_ids:
                errors.append(f"{component_id}.depends_on references unknown component_id: {dep}")

    return errors


def detect_diff_range() -> str:
    explicit_range = os.getenv("ARCH_OWNERSHIP_DIFF_RANGE", "").strip()
    if explicit_range:
        return explicit_range

    event_name = os.getenv("GITHUB_EVENT_NAME", "local")

    if event_name == "pull_request":
        base_ref = os.getenv("GITHUB_BASE_REF", "main")
        run(["git", "fetch", "--no-tags", "origin", base_ref], check=False)
        merge_base_proc = run(["git", "merge-base", "HEAD", f"origin/{base_ref}"], check=False)
        base_sha = merge_base_proc.stdout.strip()
        if merge_base_proc.returncode == 0 and base_sha:
            return f"{base_sha}...HEAD"
        return "HEAD~1...HEAD"

    if event_name == "push":
        before_sha = os.getenv("GITHUB_EVENT_BEFORE", "").strip()
        if before_sha and before_sha != "0" * 40:
            return f"{before_sha}...HEAD"
        return "HEAD~1...HEAD"

    return "HEAD~1...HEAD"


def get_changed_files(diff_range: str) -> list[str]:
    proc = run(["git", "diff", "--name-only", "--diff-filter=ACMR", diff_range], check=False)
    if proc.returncode != 0:
        # for edge cases like first commit in local contexts
        return []

    changed = [line.strip() for line in proc.stdout.splitlines() if line.strip()]
    return changed


def in_change_scope(file_path: str) -> bool:
    return any(file_path.startswith(prefix) for prefix in CHANGE_SCOPE_PREFIXES)


def match_components(file_path: str, components: list[dict[str, Any]]) -> list[str]:
    matches: list[str] = []
    for component in components:
        component_id = component["component_id"]
        for prefix in component["folder_prefixes"]:
            if file_path == prefix or file_path.startswith(prefix):
                matches.append(component_id)
                break
    return matches


def validate_changed_file_coverage(data: dict[str, Any]) -> list[str]:
    components = data["components"]
    diff_range = detect_diff_range()
    changed_files = get_changed_files(diff_range)

    scoped_files = [file_path for file_path in changed_files if in_change_scope(file_path)]
    if not scoped_files:
        return []

    errors: list[str] = []
    for file_path in scoped_files:
        matched = match_components(file_path, components)
        if not matched:
            errors.append(
                "Changed scoped file is not mapped to any component: "
                f"{file_path} (range {diff_range})"
            )

    return errors


def validate_generated_docs() -> list[str]:
    proc = run(GENERATOR_CHECK_CMD, check=False)
    if proc.returncode == 0:
        return []

    output = (proc.stdout + "\n" + proc.stderr).strip()
    if not output:
        output = "generate_architecture_ownership_docs.py --check failed"

    return [output]


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--map", default=str(MAP_PATH), help="Path to component ownership map JSON.")
    parser.add_argument(
        "--skip-diff-coverage",
        action="store_true",
        help="Skip validating changed-file coverage against the current diff range.",
    )
    parser.add_argument(
        "--skip-generated-docs-check",
        action="store_true",
        help="Skip checking generated architecture/ownership docs for custom-map validation flows.",
    )
    args = parser.parse_args()

    tracked_files = get_tracked_files()
    map_path = (ROOT / args.map).resolve() if not Path(args.map).is_absolute() else Path(args.map)

    try:
        data = load_map(map_path)
    except Exception as exc:  # noqa: BLE001
        print(f"[ERROR] Failed to load map: {exc}")
        return 1

    errors: list[str] = []
    errors.extend(validate_structure(data, tracked_files))
    if not args.skip_diff_coverage:
        errors.extend(validate_changed_file_coverage(data))
    if not args.skip_generated_docs_check:
        errors.extend(validate_generated_docs())

    if errors:
        print("[ERROR] Architecture/ownership map validation failed:")
        for error in errors:
            print(f"- {error}")
        return 1

    print("Architecture/ownership map validation passed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
