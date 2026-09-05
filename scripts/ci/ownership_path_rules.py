"""Python consumer for the language-neutral ownership path-rule contract.

CI and documentation consumers share this engine and its canonical matching
cases instead of maintaining independent path-rule semantics.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path
from typing import Any

CONTRACT_PATH = Path(__file__).resolve().parents[2] / ".codex/contracts/ownership-path-rules.json"
PROTOCOL_VERSION = 1


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
        or set(contract)
        != {
            "protocol_version",
            "schema_version",
            "candidate_path_policy",
            "match_modes",
            "match_cases",
            "invalid_rule_cases",
            "overlap_cases",
        }
        or contract.get("protocol_version") != PROTOCOL_VERSION
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


def validate_contract(contract: Any) -> list[str]:
    """Validate a supplied contract using this engine's canonical semantics."""
    if not isinstance(contract, dict):
        return ["invalid_ownership_path_rule_contract"]
    expected_modes = {
        "directory": "descendants_only",
        "exact_file": "exact_path_only",
        "filename_prefix": "same_directory_filename_prefix",
    }
    if (
        set(contract)
        != {
            "protocol_version",
            "schema_version",
            "candidate_path_policy",
            "match_modes",
            "match_cases",
            "invalid_rule_cases",
            "overlap_cases",
        }
        or contract.get("protocol_version") != PROTOCOL_VERSION
        or contract.get("schema_version") != 1
        or contract.get("candidate_path_policy") != "strict_normalized_repository_relative"
        or contract.get("match_modes") != expected_modes
    ):
        return ["invalid_ownership_path_rule_contract"]

    errors: list[str] = []
    match_cases = contract.get("match_cases")
    if not isinstance(match_cases, list) or not match_cases:
        errors.append("invalid_ownership_path_rule_match_cases")
    else:
        seen: set[str] = set()
        for index, case in enumerate(match_cases):
            if not isinstance(case, dict) or set(case) != {"name", "rule", "candidate", "matches"}:
                errors.append(f"invalid_ownership_path_rule_match_case:{index}")
                continue
            name = case["name"]
            if not isinstance(name, str) or not name or name in seen or not isinstance(case["matches"], bool):
                errors.append(f"invalid_ownership_path_rule_match_case:{index}")
                continue
            seen.add(name)
            try:
                actual = path_rule_matches(case["rule"], case["candidate"])
            except (TypeError, ValueError):
                errors.append(f"invalid_ownership_path_rule_match_case:{name}")
                continue
            if actual is not case["matches"]:
                errors.append(f"ownership_path_rule_match_case_failed:{name}")

    invalid_cases = contract.get("invalid_rule_cases")
    if not isinstance(invalid_cases, list) or not invalid_cases:
        errors.append("invalid_ownership_path_rule_invalid_cases")
    else:
        seen = set()
        for index, case in enumerate(invalid_cases):
            if not isinstance(case, dict) or set(case) != {"name", "rule"}:
                errors.append(f"invalid_ownership_path_rule_invalid_case:{index}")
                continue
            name = case["name"]
            if not isinstance(name, str) or not name or name in seen:
                errors.append(f"invalid_ownership_path_rule_invalid_case:{index}")
                continue
            seen.add(name)
            try:
                parse_path_rule(case["rule"], name)
            except (TypeError, ValueError):
                continue
            errors.append(f"ownership_path_rule_invalid_case_accepted:{name}")
    overlap_cases = contract.get("overlap_cases")
    if not isinstance(overlap_cases, list) or not overlap_cases:
        errors.append("invalid_ownership_path_rule_overlap_cases")
    else:
        seen = set()
        for index, case in enumerate(overlap_cases):
            if not isinstance(case, dict) or set(case) != {"name", "left", "right", "overlaps"}:
                errors.append(f"invalid_ownership_path_rule_overlap_case:{index}")
                continue
            name = case["name"]
            if not isinstance(name, str) or not name or name in seen or not isinstance(case["overlaps"], bool):
                errors.append(f"invalid_ownership_path_rule_overlap_case:{index}")
                continue
            seen.add(name)
            try:
                actual = path_rules_overlap(case["left"], case["right"])
            except (TypeError, ValueError):
                errors.append(f"invalid_ownership_path_rule_overlap_case:{name}")
                continue
            if actual is not case["overlaps"]:
                errors.append(f"ownership_path_rule_overlap_case_failed:{name}")
    return errors


def path_rules_overlap(left: Any, right: Any) -> bool:
    """Return whether two validated rules cover at least one common path."""
    left_rule = parse_path_rule(left, "left path rule")
    right_rule = parse_path_rule(right, "right path rule")
    left_mode, right_mode = left_rule["match"], right_rule["match"]
    if {left_mode, right_mode} == {"directory", "exact_file"} and left_rule["path"] == right_rule["path"]:
        return True
    if left_mode == "exact_file":
        return path_rule_matches(right_rule, left_rule["path"])
    if right_mode == "exact_file":
        return path_rule_matches(left_rule, right_rule["path"])
    if left_mode == "directory" and right_mode == "directory":
        return (
            left_rule["path"] == right_rule["path"]
            or left_rule["path"].startswith(right_rule["path"] + "/")
            or right_rule["path"].startswith(left_rule["path"] + "/")
        )
    if left_mode == "directory" and right_mode == "filename_prefix":
        right_directory = right_rule["path"].rpartition("/")[0]
        return right_directory == left_rule["path"] or right_directory.startswith(left_rule["path"] + "/")
    if right_mode == "directory" and left_mode == "filename_prefix":
        left_directory = left_rule["path"].rpartition("/")[0]
        return left_directory == right_rule["path"] or left_directory.startswith(right_rule["path"] + "/")
    left_dir, _, left_name = left_rule["path"].rpartition("/")
    right_dir, _, right_name = right_rule["path"].rpartition("/")
    return left_dir == right_dir and (left_name.startswith(right_name) or right_name.startswith(left_name))


def _assert_contract_conformance() -> None:
    errors = validate_contract(CONTRACT)
    if errors:
        raise RuntimeError(errors[0])


def _cli() -> None:
    """Process one JSON request; callers must treat malformed output as failure."""
    try:
        request = json.load(sys.stdin)
        operation = request.get("operation") if isinstance(request, dict) else None
        if operation == "validate_contract":
            result: Any = {"errors": validate_contract(request.get("contract"))}
        elif operation == "parse":
            try:
                result = {"valid": True, "rule": parse_path_rule(request.get("rule"))}
            except (TypeError, ValueError):
                result = {"valid": False, "rule": None}
        elif operation == "covers":
            result = {"matches": path_rule_matches(request.get("rule"), request.get("candidate"))}
        elif operation == "overlap":
            result = {"overlaps": path_rules_overlap(request.get("left"), request.get("right"))}
        else:
            raise ValueError("unknown operation")
        # Keep the transport contract stable independently of result field
        # ordering.  Consumers must dispatch on the authenticated operation
        # and validate the typed result rather than infer either from keys.
        response = {
            "protocol_version": PROTOCOL_VERSION,
            "operation": operation,
            "result": result,
        }
        print(json.dumps(response, sort_keys=True, separators=(",", ":")))
    except (OSError, TypeError, ValueError, json.JSONDecodeError) as error:
        print(json.dumps({"error": str(error)}, sort_keys=True, separators=(",", ":")))
        raise SystemExit(1)


_assert_contract_conformance()

if __name__ == "__main__":
    _cli()
