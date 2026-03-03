#!/usr/bin/env python3
"""Generate .github/CODEOWNERS from docs/maps/component_ownership_map.json."""

from __future__ import annotations

import argparse
import json
from collections import defaultdict
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
MAP_PATH = ROOT / "docs/maps/component_ownership_map.json"
CODEOWNERS_PATH = ROOT / ".github/CODEOWNERS"


def display_path(path: Path) -> str:
    try:
        return str(path.relative_to(ROOT))
    except ValueError:
        return str(path)


def normalize_prefix(prefix: str) -> str:
    normalized = prefix.replace("\\", "/").strip()
    if normalized.startswith("./"):
        normalized = normalized[2:]
    return normalized.lstrip("/")


def codeowners_pattern(prefix: str) -> str:
    normalized = normalize_prefix(prefix)

    if normalized.endswith("/"):
        return f"/{normalized}**"

    if normalized.endswith("_"):
        return f"/{normalized}*"

    return f"/{normalized}"


def load_map(path: Path) -> dict[str, Any]:
    with path.open("r", encoding="utf-8") as handle:
        payload = json.load(handle)

    if not isinstance(payload, dict):
        raise ValueError("Component map must be a JSON object")

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

        folder_prefixes = component.get("folder_prefixes")
        if not isinstance(folder_prefixes, list):
            component_id = component.get("component_id", "<unknown>")
            raise ValueError(f"Component {component_id} has invalid folder_prefixes")

        owners = unique_handles(component)

        for prefix in folder_prefixes:
            if not isinstance(prefix, str) or not prefix.strip():
                continue
            pattern = codeowners_pattern(prefix)
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
