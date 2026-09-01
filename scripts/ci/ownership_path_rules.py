"""Canonical parsing and matching for component ownership path rules.

The ownership map is consumed by CI checks and documentation generators.  Keep
normalisation and the two supported match modes here so those consumers cannot
silently drift apart.
"""

from __future__ import annotations

from typing import Any

MATCH_MODES = frozenset({"directory", "exact_file"})


def normalize_path(value: str) -> str:
    """Return the repository-relative, POSIX spelling used by the map."""
    normalized = value.replace("\\", "/").strip()
    if normalized.startswith("./"):
        normalized = normalized[2:]
    return normalized.lstrip("/")


def parse_path_rule(rule: Any, context: str = "path rule") -> dict[str, str]:
    """Validate and normalize one map rule, failing closed on malformed data."""
    if not isinstance(rule, dict) or set(rule) != {"path", "match"}:
        raise ValueError(f"{context} must contain exactly path and match")

    path = rule.get("path")
    match = rule.get("match")
    if not isinstance(path, str) or not path:
        raise ValueError(f"{context}.path must be a non-empty string")
    if not isinstance(match, str) or match not in MATCH_MODES:
        raise ValueError(f"{context}.match must be one of {sorted(MATCH_MODES)}")

    normalized_path = normalize_path(path)
    segments = normalized_path.split("/")
    if (
        normalized_path != path
        or path.endswith("/")
        or any(segment in {"", ".", ".."} for segment in segments)
        or any(character in path for character in "*?[]")
        or any(ord(character) < 32 or ord(character) == 127 for character in path)
    ):
        raise ValueError(f"{context}.path must be a normalized repository-relative path")

    return {"path": normalized_path, "match": match}


def parse_path_rules(rules: Any, context: str = "path_rules") -> list[dict[str, str]]:
    """Validate and normalize a non-empty path-rule list."""
    if not isinstance(rules, list) or not rules:
        raise ValueError(f"{context} must be a non-empty list")
    return [parse_path_rule(rule, f"{context}[{index}]") for index, rule in enumerate(rules)]


def path_rule_matches(rule: dict[str, str], repo_path: str) -> bool:
    """Apply canonical exact-file or directory-descendant matching semantics."""
    normalized_rule = parse_path_rule(rule)
    normalized_path = normalize_path(repo_path)
    if normalized_rule["match"] == "exact_file":
        return normalized_path == normalized_rule["path"]
    return normalized_path == normalized_rule["path"] or normalized_path.startswith(normalized_rule["path"] + "/")


def codeowners_pattern(rule: dict[str, str]) -> str:
    """Render a canonical map rule as its corresponding CODEOWNERS pattern."""
    normalized_rule = parse_path_rule(rule)
    if normalized_rule["match"] == "directory":
        return f"/{normalized_rule['path']}/**"
    return f"/{normalized_rule['path']}"
