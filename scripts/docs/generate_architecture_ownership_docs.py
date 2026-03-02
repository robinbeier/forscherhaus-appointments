#!/usr/bin/env python3
"""Generate architecture and ownership documentation from a canonical JSON map."""

from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
MAP_PATH = ROOT / "docs/maps/component_ownership_map.json"
ARCH_PATH = ROOT / "docs/architecture-map.md"
OWNER_PATH = ROOT / "docs/ownership-map.md"


def load_map() -> dict[str, Any]:
    with MAP_PATH.open("r", encoding="utf-8") as fh:
        data = json.load(fh)

    if not isinstance(data, dict):
        raise ValueError("Top-level JSON payload must be an object.")

    components = data.get("components")
    if not isinstance(components, list) or not components:
        raise ValueError('"components" must be a non-empty list.')

    return data


def render_architecture(data: dict[str, Any]) -> str:
    components = data["components"]
    lines: list[str] = []
    lines.append("# Architecture Map")
    lines.append("")
    lines.append("Canonical source: `docs/maps/component_ownership_map.json`")
    lines.append("")
    lines.append("This map defines component boundaries, path ownership scope, and dependency edges.")
    lines.append("")
    lines.append("## Component Overview")
    lines.append("")
    lines.append("| Component | Role | Depends On | Path Prefixes | Key Files |")
    lines.append("|---|---|---|---:|---:|")

    for component in components:
        depends_on = component.get("depends_on", [])
        depends_display = ", ".join(depends_on) if depends_on else "None"
        lines.append(
            "| `{component_id}` | {role} | {depends_on} | {prefix_count} | {key_count} |".format(
                component_id=component["component_id"],
                role=component["role"],
                depends_on=depends_display,
                prefix_count=len(component.get("folder_prefixes", [])),
                key_count=len(component.get("key_files", [])),
            )
        )

    lines.append("")
    lines.append("## Component Details")
    lines.append("")

    for component in components:
        lines.append(f"### `{component['component_id']}` - {component['role']}")
        lines.append("")
        lines.append(component["summary"])
        lines.append("")

        depends_on = component.get("depends_on", [])
        if depends_on:
            lines.append("Dependencies:")
            for dep in depends_on:
                lines.append(f"- `{dep}`")
        else:
            lines.append("Dependencies:")
            lines.append("- None")

        lines.append("")
        lines.append("Path prefixes:")
        for prefix in component["folder_prefixes"]:
            lines.append(f"- `{prefix}`")

        lines.append("")
        lines.append("Key files:")
        for key_file in component["key_files"]:
            lines.append(f"- `{key_file}`")

        lines.append("")

    return "\n".join(lines).rstrip() + "\n"


def render_ownership(data: dict[str, Any]) -> str:
    components = data["components"]
    lines: list[str] = []
    lines.append("# Ownership Map")
    lines.append("")
    lines.append("Canonical source: `docs/maps/component_ownership_map.json`")
    lines.append("")
    lines.append("Ownership model: Role + Handles (primary/secondary).")
    lines.append("")
    lines.append("## Ownership Table")
    lines.append("")
    lines.append("| Component | Role | Primary | Secondary |")
    lines.append("|---|---|---|---|")

    for component in components:
        lines.append(
            "| `{component_id}` | {role} | {primary} | {secondary} |".format(
                component_id=component["component_id"],
                role=component["role"],
                primary=component["primary_handle"],
                secondary=component["secondary_handle"],
            )
        )

    lines.append("")
    lines.append("## Ownership Scope by Component")
    lines.append("")

    for component in components:
        lines.append(f"### `{component['component_id']}`")
        lines.append("")
        lines.append(f"- Role: {component['role']}")
        lines.append(f"- Primary: {component['primary_handle']}")
        lines.append(f"- Secondary: {component['secondary_handle']}")
        lines.append("- Key files:")
        for key_file in component["key_files"]:
            lines.append(f"  - `{key_file}`")
        lines.append("- Path prefixes:")
        for prefix in component["folder_prefixes"]:
            lines.append(f"  - `{prefix}`")
        lines.append("")

    return "\n".join(lines).rstrip() + "\n"


def write_if_changed(path: Path, content: str) -> None:
    current = path.read_text(encoding="utf-8") if path.exists() else None
    if current != content:
        path.write_text(content, encoding="utf-8")


def run_check(path: Path, expected: str) -> tuple[bool, str]:
    if not path.exists():
        return False, f"Missing generated file: {path.relative_to(ROOT)}"

    actual = path.read_text(encoding="utf-8")
    if actual != expected:
        return False, f"Out-of-date generated file: {path.relative_to(ROOT)}"

    return True, ""


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true", help="Fail if generated docs are out-of-date.")
    args = parser.parse_args()

    data = load_map()
    architecture_md = render_architecture(data)
    ownership_md = render_ownership(data)

    if args.check:
        ok_arch, err_arch = run_check(ARCH_PATH, architecture_md)
        ok_owner, err_owner = run_check(OWNER_PATH, ownership_md)

        errors = [msg for ok, msg in [(ok_arch, err_arch), (ok_owner, err_owner)] if not ok]
        if errors:
            for error in errors:
                print(error)
            print("Run: python3 scripts/docs/generate_architecture_ownership_docs.py")
            return 1

        print("Generated architecture/ownership docs are up-to-date.")
        return 0

    write_if_changed(ARCH_PATH, architecture_md)
    write_if_changed(OWNER_PATH, ownership_md)
    print("Generated docs/architecture-map.md and docs/ownership-map.md")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
