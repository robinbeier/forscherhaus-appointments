#!/usr/bin/env python3
"""Check component boundaries for changed PHP files using the canonical ownership map."""

from __future__ import annotations

import argparse
import json
import os
import posixpath
import re
import subprocess
import sys
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_MAP_PATH = ROOT / "docs/maps/component_ownership_map.json"
DEFAULT_SCOPE_CONFIG = ROOT / "scripts/ci/config/component_boundary_scope.php"
DEFAULT_OUTPUT_JSON = ROOT / "storage/logs/ci/component-boundary-latest.json"

LOADER_LITERAL_RE = re.compile(
    r"\bload->(?P<kind>model|library|helper)\s*\(\s*(?P<quote>['\"])(?P<value>[^'\"]+)(?P=quote)",
    re.IGNORECASE,
)

LOADER_CALL_RE = re.compile(
    r"\bload->(?P<kind>model|library|helper)\s*\((?P<args>.*?)\)",
    re.IGNORECASE | re.DOTALL,
)

REQUIRE_LITERAL_RE = re.compile(
    r"\brequire(?:_once)?\s*\(?\s*APPPATH\s*\.\s*(?P<quote>['\"])(?P<path>[^'\"]+)(?P=quote)\s*\)?",
    re.IGNORECASE,
)

REQUIRE_CALL_RE = re.compile(
    r"\brequire(?:_once)?\s*\(?\s*APPPATH(?P<expr>[^;]*);",
    re.IGNORECASE | re.DOTALL,
)


def run(cmd: list[str], check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(cmd, cwd=ROOT, check=check, text=True, capture_output=True)


def normalize_path(value: str) -> str:
    path = value.replace("\\", "/").strip()
    if path.startswith("./"):
        path = path[2:]
    return path.lstrip("/")


def line_number(content: str, index: int) -> int:
    return content.count("\n", 0, index) + 1


def build_casefold_index(paths: set[str]) -> dict[str, str]:
    index: dict[str, str] = {}

    for path in sorted(paths):
        key = path.casefold()
        existing = index.get(key)
        if existing is not None and existing != path:
            raise ValueError(
                "Case-insensitive path collision detected: "
                f"{existing} and {path} map to the same key."
            )
        index[key] = path

    return index


def load_json(path: Path) -> dict[str, Any]:
    with path.open("r", encoding="utf-8") as handle:
        data = json.load(handle)

    if not isinstance(data, dict):
        raise ValueError(f"JSON payload must be an object: {path}")

    return data


def load_scope_config(path: Path) -> dict[str, Any]:
    php_code = (
        "$config = require $argv[1];"
        "if (!is_array($config)) { fwrite(STDERR, 'Scope config must return array.'); exit(2); }"
        "echo json_encode($config, JSON_THROW_ON_ERROR);"
    )

    proc = subprocess.run(
        ["php", "-r", php_code, str(path)],
        cwd=ROOT,
        check=False,
        text=True,
        capture_output=True,
    )

    if proc.returncode != 0:
        stderr = proc.stderr.strip() or "Unknown PHP scope-config loading failure"
        raise RuntimeError(f"Failed to load scope config {path}: {stderr}")

    try:
        config = json.loads(proc.stdout)
    except json.JSONDecodeError as exc:
        raise RuntimeError(f"Invalid JSON output from scope config {path}: {exc}") from exc

    if not isinstance(config, dict):
        raise RuntimeError(f"Scope config {path} must decode to a JSON object")

    return config


def prefix_matches(prefix: str, repo_path: str) -> bool:
    normalized_prefix = normalize_path(prefix)
    normalized_path = normalize_path(repo_path)

    if normalized_path == normalized_prefix:
        return True

    if normalized_prefix.endswith("/") or normalized_prefix.endswith("_"):
        return normalized_path.startswith(normalized_prefix)

    return False


def build_component_index(map_payload: dict[str, Any]) -> tuple[list[dict[str, Any]], dict[str, set[str]]]:
    components = map_payload.get("components")
    if not isinstance(components, list) or not components:
        raise ValueError('"components" must be a non-empty array')

    normalized_components: list[dict[str, Any]] = []
    dependency_map: dict[str, set[str]] = {}

    for component in components:
        if not isinstance(component, dict):
            raise ValueError("Each component entry must be an object")

        component_id = component.get("component_id")
        if not isinstance(component_id, str) or not component_id:
            raise ValueError("Each component must include a non-empty component_id")

        folder_prefixes = component.get("folder_prefixes")
        if not isinstance(folder_prefixes, list):
            raise ValueError(f"{component_id}.folder_prefixes must be a list")

        depends_on = component.get("depends_on", [])
        if not isinstance(depends_on, list):
            raise ValueError(f"{component_id}.depends_on must be a list")

        normalized_components.append(
            {
                "component_id": component_id,
                "folder_prefixes": [normalize_path(str(prefix)) for prefix in folder_prefixes],
            }
        )
        dependency_map[component_id] = {str(dep) for dep in depends_on if isinstance(dep, str)}

    return normalized_components, dependency_map


def match_components(repo_path: str, components: list[dict[str, Any]]) -> list[str]:
    matches: list[str] = []

    for component in components:
        component_id = str(component["component_id"])
        for prefix in component["folder_prefixes"]:
            if prefix_matches(prefix, repo_path):
                matches.append(component_id)
                break

    return sorted(set(matches))


def detect_diff_range(explicit: str | None, env_var_name: str) -> str:
    if explicit:
        return explicit

    env_range = os.getenv(env_var_name, "").strip()
    if env_range:
        return env_range

    event_name = os.getenv("GITHUB_EVENT_NAME", "local")

    if event_name == "pull_request":
        base_ref = os.getenv("GITHUB_BASE_REF", "main")
        run(["git", "fetch", "--no-tags", "origin", base_ref], check=False)

        merge_base = run(["git", "merge-base", "HEAD", f"origin/{base_ref}"], check=False)
        base_sha = merge_base.stdout.strip()
        if merge_base.returncode == 0 and base_sha:
            return f"{base_sha}...HEAD"

        return "HEAD~1...HEAD"

    if event_name == "push":
        before_sha = os.getenv("GITHUB_EVENT_BEFORE", "").strip()
        if before_sha and before_sha != "0" * 40:
            return f"{before_sha}...HEAD"

    return "HEAD~1...HEAD"


def get_changed_files(diff_range: str) -> list[str]:
    proc = run(["git", "diff", "--name-only", "--diff-filter=ACMR", diff_range], check=False)
    if proc.returncode != 0:
        return []

    return [normalize_path(line) for line in proc.stdout.splitlines() if line.strip()]


def parse_loader_target(loader_roots: dict[str, str], kind: str, value: str) -> str | None:
    root = loader_roots.get(kind)
    if not root:
        return None

    normalized_root = normalize_path(root)
    normalized_value = normalize_path(value)

    if kind == "helper":
        if normalized_value.endswith(".php"):
            helper_file = normalized_value
        else:
            helper_base = normalized_value if normalized_value.endswith("_helper") else f"{normalized_value}_helper"
            helper_file = f"{helper_base}.php"
        target = f"{normalized_root}/{helper_file}"
    else:
        target_file = normalized_value if normalized_value.endswith(".php") else f"{normalized_value}.php"
        target = f"{normalized_root}/{target_file}"

    return normalize_path(posixpath.normpath(target))


def parse_require_target(path_literal: str) -> str | None:
    normalized = normalize_path(path_literal)
    if normalized.startswith(".."):
        return None

    target = normalize_path(posixpath.normpath(f"application/{normalized}"))
    return target


def parse_file_dependencies(
    file_path: str,
    content: str,
    loader_roots: dict[str, str],
) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    resolved: list[dict[str, Any]] = []
    unresolved: list[dict[str, Any]] = []

    literal_loader_starts: set[int] = set()
    for match in LOADER_LITERAL_RE.finditer(content):
        literal_loader_starts.add(match.start())
        loader_kind = match.group("kind").lower()
        loader_value = match.group("value")
        target_path = parse_loader_target(loader_roots, loader_kind, loader_value)

        if not target_path:
            unresolved.append(
                {
                    "file": file_path,
                    "line": line_number(content, match.start()),
                    "kind": loader_kind,
                    "expression": loader_value,
                    "reason": "loader_target_unresolvable",
                }
            )
            continue

        resolved.append(
            {
                "file": file_path,
                "line": line_number(content, match.start()),
                "kind": loader_kind,
                "expression": loader_value,
                "target_path": target_path,
                "source": "loader_literal",
            }
        )

    for match in LOADER_CALL_RE.finditer(content):
        if match.start() in literal_loader_starts:
            continue

        unresolved.append(
            {
                "file": file_path,
                "line": line_number(content, match.start()),
                "kind": match.group("kind").lower(),
                "expression": match.group("args").strip(),
                "reason": "loader_non_literal_expression",
            }
        )

    literal_require_starts: set[int] = set()
    for match in REQUIRE_LITERAL_RE.finditer(content):
        literal_require_starts.add(match.start())
        require_path = match.group("path")
        target_path = parse_require_target(require_path)

        if not target_path:
            unresolved.append(
                {
                    "file": file_path,
                    "line": line_number(content, match.start()),
                    "kind": "require",
                    "expression": require_path,
                    "reason": "require_target_unresolvable",
                }
            )
            continue

        resolved.append(
            {
                "file": file_path,
                "line": line_number(content, match.start()),
                "kind": "require",
                "expression": require_path,
                "target_path": target_path,
                "source": "require_literal",
            }
        )

    for match in REQUIRE_CALL_RE.finditer(content):
        if match.start() in literal_require_starts:
            continue

        unresolved.append(
            {
                "file": file_path,
                "line": line_number(content, match.start()),
                "kind": "require",
                "expression": match.group("expr").strip(),
                "reason": "require_non_literal_expression",
            }
        )

    return resolved, unresolved


def ensure_output_dir(path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--map-path", default=str(DEFAULT_MAP_PATH))
    parser.add_argument("--scope-config", default=str(DEFAULT_SCOPE_CONFIG))
    parser.add_argument("--output-json", default=str(DEFAULT_OUTPUT_JSON))
    parser.add_argument("--diff-range", default="")
    args = parser.parse_args()

    map_path = (ROOT / args.map_path).resolve() if not Path(args.map_path).is_absolute() else Path(args.map_path)
    scope_config_path = (
        (ROOT / args.scope_config).resolve() if not Path(args.scope_config).is_absolute() else Path(args.scope_config)
    )
    output_json_path = (
        (ROOT / args.output_json).resolve() if not Path(args.output_json).is_absolute() else Path(args.output_json)
    )

    ensure_output_dir(output_json_path)

    try:
        map_payload = load_json(map_path)
        components, dependency_map = build_component_index(map_payload)
        scope_config = load_scope_config(scope_config_path)

        scope_prefixes_raw = scope_config.get("scope_prefixes", [])
        loader_roots_raw = scope_config.get("loader_roots", {})
        diff_env_var = str(scope_config.get("diff_env_var", "COMPONENT_BOUNDARY_DIFF_RANGE"))

        if not isinstance(scope_prefixes_raw, list) or not scope_prefixes_raw:
            raise ValueError("scope_config.scope_prefixes must be a non-empty list")
        if not isinstance(loader_roots_raw, dict):
            raise ValueError("scope_config.loader_roots must be an object")

        scope_prefixes = [normalize_path(str(prefix)) for prefix in scope_prefixes_raw]
        loader_roots = {str(kind): normalize_path(str(root)) for kind, root in loader_roots_raw.items()}

        diff_range = detect_diff_range(args.diff_range.strip() or None, diff_env_var)
        changed_files = get_changed_files(diff_range)
        scoped_php_files = [
            path for path in changed_files if path.endswith(".php") and any(path.startswith(prefix) for prefix in scope_prefixes)
        ]

        tracked_files = {
            normalize_path(line)
            for line in run(["git", "ls-files"]).stdout.splitlines()
            if line.strip()
        }
        tracked_files_casefold = build_casefold_index(tracked_files)

        report: dict[str, Any] = {
            "tool": "component-boundary-check",
            "status": "passed",
            "diff_range": diff_range,
            "scope_prefixes": scope_prefixes,
            "scoped_changed_php_files": scoped_php_files,
            "checked_dependency_count": 0,
            "allowed_dependency_count": 0,
            "violation_count": 0,
            "unresolved_count": 0,
            "violations": [],
            "unresolved": [],
        }

        if not scoped_php_files:
            report["status"] = "skipped"
            report["reason"] = "No changed PHP files in boundary scope."
            output_json_path.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
            print("Component boundary check skipped: no changed scoped PHP files.")
            return 0

        violations: list[dict[str, Any]] = []
        source_mapping_errors: list[dict[str, Any]] = []
        unresolved: list[dict[str, Any]] = []
        checked_dependency_count = 0
        allowed_dependency_count = 0

        for source_file in scoped_php_files:
            source_components = match_components(source_file, components)
            if len(source_components) != 1:
                source_mapping_error = {
                    "file": source_file,
                    "line": 1,
                    "kind": "source",
                    "expression": source_file,
                    "reason": "source_component_not_unique",
                    "matched_components": source_components,
                }
                source_mapping_errors.append(source_mapping_error)
                unresolved.append(source_mapping_error)
                continue

            source_component = source_components[0]
            depends_on = dependency_map.get(source_component, set())
            file_path = ROOT / source_file

            if not file_path.exists():
                unresolved.append(
                    {
                        "file": source_file,
                        "line": 1,
                        "kind": "source",
                        "expression": source_file,
                        "reason": "source_file_missing",
                    }
                )
                continue

            content = file_path.read_text(encoding="utf-8", errors="ignore")
            resolved_dependencies, unresolved_dependencies = parse_file_dependencies(source_file, content, loader_roots)
            unresolved.extend(unresolved_dependencies)

            for dependency in resolved_dependencies:
                checked_dependency_count += 1
                target_path = str(dependency["target_path"])
                canonical_target_path = tracked_files_casefold.get(target_path.casefold(), target_path)

                if canonical_target_path not in tracked_files:
                    unresolved.append(
                        {
                            "file": source_file,
                            "line": dependency["line"],
                            "kind": dependency["kind"],
                            "expression": dependency["expression"],
                            "reason": "target_file_not_found",
                            "target_path": target_path,
                        }
                    )
                    continue

                target_components = match_components(canonical_target_path, components)
                if len(target_components) != 1:
                    unresolved.append(
                        {
                            "file": source_file,
                            "line": dependency["line"],
                            "kind": dependency["kind"],
                            "expression": dependency["expression"],
                            "reason": "target_component_not_unique",
                            "target_path": canonical_target_path,
                            "matched_components": target_components,
                        }
                    )
                    continue

                target_component = target_components[0]
                is_allowed = target_component == source_component or target_component in depends_on

                if is_allowed:
                    allowed_dependency_count += 1
                    continue

                violations.append(
                    {
                        "source_file": source_file,
                        "source_component": source_component,
                        "target_file": canonical_target_path,
                        "target_component": target_component,
                        "line": dependency["line"],
                        "kind": dependency["kind"],
                        "expression": dependency["expression"],
                        "rule": f"{source_component} may not depend on {target_component}",
                    }
                )

        report["checked_dependency_count"] = checked_dependency_count
        report["allowed_dependency_count"] = allowed_dependency_count
        report["source_mapping_error_count"] = len(source_mapping_errors)
        report["violation_count"] = len(violations)
        report["unresolved_count"] = len(unresolved)
        report["violations"] = violations
        report["unresolved"] = unresolved

        if violations or source_mapping_errors:
            report["status"] = "failed"

        output_json_path.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")

        if violations or source_mapping_errors:
            print(
                "Component boundary check failed with "
                f"{len(violations)} violation(s) and "
                f"{len(source_mapping_errors)} source-mapping error(s)."
            )
            return 1

        print(
            "Component boundary check passed: "
            f"{checked_dependency_count} dependency edges checked, {len(unresolved)} unresolved."
        )
        return 0
    except Exception as exc:  # noqa: BLE001
        error_report = {
            "tool": "component-boundary-check",
            "status": "error",
            "error": str(exc),
        }
        output_json_path.write_text(json.dumps(error_report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        print(f"[ERROR] {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
