#!/usr/bin/env python3
"""Generate .github/CODEOWNERS from docs/maps/component_ownership_map.json."""

from __future__ import annotations

import argparse
import json
import sys
from collections import defaultdict
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts/ci"))
from ownership_path_rules import codeowners_pattern, parse_path_rule
MAP_PATH = ROOT / "docs/maps/component_ownership_map.json"
CODEOWNERS_PATH = ROOT / ".github/CODEOWNERS"


def display_path(path: Path) -> str:
    try:
        return str(path.relative_to(ROOT))
    except ValueError:
        return str(path)


def load_map(path: Path) -> dict[str, Any]:
    with path.open("r", encoding="utf-8") as handle:
        payload = json.load(handle)

    if not isinstance(payload, dict):
        raise ValueError("Component map must be a JSON object")
    if payload.get("schema_version") != 3:
        raise ValueError('"schema_version" must be 3')

    components = payload.get("components")
    if not isinstance(components, list) or not components:
        raise ValueError('"components" must be a non-empty list')

    return payload


def unique_handles(component: dict[str, Any]) -> list[str]:
    handles: list[str] = []

    for key in ("primary_handle", "secondary_handle"):
        handle = component.get(key)
        if isinstance(handle, str) and handle and handle not in handles:
            handles.append(handle)

    if not handles:
        component_id = component.get("component_id", "<unknown>")
        raise ValueError(f"Component {component_id} has no valid ownership handles")

    return handles


def render_codeowners(payload: dict[str, Any]) -> str:
    components = payload["components"]
    entries: defaultdict[str, set[str]] = defaultdict(set)

    for component in components:
        if not isinstance(component, dict):
            raise ValueError("Each component must be an object")

        component_id = component.get("component_id", "<unknown>")
        path_rules = component.get("path_rules")
        if not isinstance(path_rules, list):
            raise ValueError(f"Component {component_id} has invalid path_rules")

        owners = unique_handles(component)

        for rule in path_rules:
            parsed_rule = parse_path_rule(rule, f"Component {component_id}.path_rules")
            pattern = codeowners_pattern(parsed_rule)
            entries[pattern].update(owners)

    lines: list[str] = [
        "# Generated from docs/maps/component_ownership_map.json; do not edit manually.",
        "# Run: python3 scripts/docs/generate_codeowners_from_map.py",
        "",
    ]

    for pattern in sorted(entries.keys()):
        owners = " ".join(sorted(entries[pattern]))
        lines.append(f"{pattern} {owners}")

    return "\n".join(lines).rstrip() + "\n"


def run_check(path: Path, expected: str) -> tuple[bool, str]:
    if not path.exists():
        return False, f"Missing generated file: {display_path(path)}"

    actual = path.read_text(encoding="utf-8")
    if actual != expected:
        return False, f"Out-of-date generated file: {display_path(path)}"

    return True, ""


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true", help="Fail if CODEOWNERS is not up-to-date.")
    parser.add_argument("--map", default=str(MAP_PATH), help="Path to component ownership map JSON.")
    parser.add_argument("--output", default=str(CODEOWNERS_PATH), help="Path to generated CODEOWNERS file.")
    args = parser.parse_args()

    map_path = (ROOT / args.map).resolve() if not Path(args.map).is_absolute() else Path(args.map)
    output_path = (ROOT / args.output).resolve() if not Path(args.output).is_absolute() else Path(args.output)

    payload = load_map(map_path)
    rendered = render_codeowners(payload)

    if args.check:
        ok, message = run_check(output_path, rendered)
        if not ok:
            print(message)
            print("Run: python3 scripts/docs/generate_codeowners_from_map.py")
            return 1

        print("Generated CODEOWNERS is up-to-date.")
        return 0

    output_path.parent.mkdir(parents=True, exist_ok=True)

    current = output_path.read_text(encoding="utf-8") if output_path.exists() else None
    if current != rendered:
        output_path.write_text(rendered, encoding="utf-8")

    print(f"Generated {display_path(output_path)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
