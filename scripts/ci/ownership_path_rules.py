"""Python consumer for the language-neutral ownership path-rule contract.

The trusted PHP lane validator and the Python CI/documentation consumers use
different runtimes. Both therefore execute the same exact contract fixture at
startup instead of maintaining untested independent semantics.
"""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any

CONTRACT_PATH = Path(__file__).resolve().parents[2] / ".codex/contracts/ownership-path-rules.json"


def _load_contract() -> dict[str, Any]:
    with CONTRACT_PATH.open("r", encoding="utf-8") as stream:
        contract = json.load(stream)
    expected_modes = {
        "directory": "descendants_only",
        "exact_file": "exact_path_only",
        "filename_prefix": "same_directory_filename_prefix",
    }
    if (
        not isinstance(contract, dict)
        or set(contract) != {
            "schema_version",
            "candidate_path_policy",
            "match_modes",
            "match_cases",
            "invalid_rule_cases",
        }
        or contract.get("schema_version") != 1
        or contract.get("candidate_path_policy") != "strict_normalized_repository_relative"
        or contract.get("match_modes") != expected_modes
        or not isinstance(contract.get("match_cases"), list)
        or not contract["match_cases"]
        or not isinstance(contract.get("invalid_rule_cases"), list)
        or not contract["invalid_rule_cases"]
    ):
        raise RuntimeError("Ownership path-rule contract is invalid")
    return contract


CONTRACT = _load_contract()
MATCH_MODES = frozenset(CONTRACT["match_modes"])


def normalize_path(value: str) -> str:
    """Return the repository-relative, POSIX spelling used by the map."""
    normalized = value.replace("\\", "/").strip()
    if normalized.startswith("./"):
        normalized = normalized[2:]
    return normalized.lstrip("/")


def _is_normalized_path(path: str) -> bool:
    segments = path.split("/")
    return bool(path) and not (
        path.startswith("/")
        or path.endswith("/")
        or "\\" in path
        or any(segment in {"", ".", ".."} for segment in segments)
        or any(character in path for character in "*?[]")
        or any(ord(character) < 32 or ord(character) == 127 for character in path)
    )


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

    if not _is_normalized_path(path):
        raise ValueError(f"{context}.path must be a normalized repository-relative path")

    if match == "filename_prefix" and not path.rsplit("/", 1)[-1]:
        raise ValueError(f"{context}.path must contain a non-empty filename prefix")

    return {"path": path, "match": match}


def parse_path_rules(rules: Any, context: str = "path_rules") -> list[dict[str, str]]:
    """Validate and normalize a non-empty path-rule list."""
    if not isinstance(rules, list) or not rules:
        raise ValueError(f"{context} must be a non-empty list")
    return [parse_path_rule(rule, f"{context}[{index}]") for index, rule in enumerate(rules)]


def path_rule_matches(rule: dict[str, str], repo_path: str) -> bool:
    """Apply canonical exact-file or directory-descendant matching semantics."""
    normalized_rule = parse_path_rule(rule)
    if not isinstance(repo_path, str) or not _is_normalized_path(repo_path):
        raise ValueError("candidate path must be a normalized repository-relative path")
    if normalized_rule["match"] == "exact_file":
        return repo_path == normalized_rule["path"]
    if normalized_rule["match"] == "filename_prefix":
        rule_directory, _, filename_prefix = normalized_rule["path"].rpartition("/")
        candidate_directory, _, candidate_name = repo_path.rpartition("/")
        return candidate_directory == rule_directory and candidate_name.startswith(filename_prefix)
    return repo_path.startswith(normalized_rule["path"] + "/")


def codeowners_pattern(rule: dict[str, str]) -> str:
    """Render a canonical map rule as its corresponding CODEOWNERS pattern."""
    normalized_rule = parse_path_rule(rule)
    if normalized_rule["match"] == "directory":
        return f"/{normalized_rule['path']}/**"
    if normalized_rule["match"] == "filename_prefix":
        return f"/{normalized_rule['path']}*"
    return f"/{normalized_rule['path']}"


def _assert_contract_conformance() -> None:
    seen: set[str] = set()
    for case in CONTRACT["match_cases"]:
        if not isinstance(case, dict) or set(case) != {"name", "rule", "candidate", "matches"}:
            raise RuntimeError("Ownership path-rule match case is invalid")
        name = case["name"]
        if not isinstance(name, str) or not name or name in seen or not isinstance(case["matches"], bool):
            raise RuntimeError("Ownership path-rule match case is invalid")
        seen.add(name)
        if path_rule_matches(case["rule"], case["candidate"]) is not case["matches"]:
            raise RuntimeError(f"Ownership path-rule match case failed: {name}")

    invalid_seen: set[str] = set()
    for case in CONTRACT["invalid_rule_cases"]:
        if not isinstance(case, dict) or set(case) != {"name", "rule"}:
            raise RuntimeError("Ownership path-rule invalid case is invalid")
        name = case["name"]
        if not isinstance(name, str) or not name or name in invalid_seen:
            raise RuntimeError("Ownership path-rule invalid case is invalid")
        invalid_seen.add(name)
        try:
            parse_path_rule(case["rule"], name)
        except ValueError:
            continue
        raise RuntimeError(f"Ownership path-rule invalid case was accepted: {name}")


_assert_contract_conformance()
