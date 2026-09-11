"""Small, lossless parser for pre-PR gate logs.

The parser deliberately has no knowledge of whether a gate passed.  It only
summarises observations; the caller remains responsible for exit decisions.
"""

from __future__ import annotations

import hashlib
import json
import re
from pathlib import Path
from typing import Any

SCHEMA_VERSION = 1
_BASELINE_KEYS = {"version", "source_commit", "blocks", "phpunit_aggregate_counts"}
_BLOCK_START = re.compile(r"^DEPRECATION WARNING \[([^]]+)\]:")
_ROOT = re.compile(r"root stylesheet\s*$")
_PHPUNIT_HEAD = re.compile(r"^\s*Tests:\s*(\d+),\s*Assertions:\s*(\d+)(?:,|\.)")
_PHPUNIT_METRIC = re.compile(
    r"(?:PHPUnit\s+)?(Deprecations|Notices|Skipped|Errors|Failures):\s*(\d+)"
)
_PHPUNIT_OK = re.compile(r"\bOK \((\d+) tests?,\s*(\d+) assertions?\)")
_SIGNAL = re.compile(r"\[(PASS|FAIL)\]", re.IGNORECASE)


def _block_candidates(text: str) -> list[tuple[int, int, str, str]]:
    """Return complete Sass blocks as (start, end, class, exact_text)."""
    lines = text.splitlines(keepends=True)
    offsets: list[int] = []
    offset = 0
    for line in lines:
        offsets.append(offset)
        offset += len(line)
    result: list[tuple[int, int, str, str]] = []
    for index, line in enumerate(lines):
        match = _BLOCK_START.match(line.rstrip("\r\n"))
        if not match:
            continue
        root_index = None
        for candidate in range(index + 1, len(lines)):
            if _BLOCK_START.match(lines[candidate].rstrip("\r\n")):
                break
            if _ROOT.search(lines[candidate].rstrip("\r\n")):
                # The blank line is part of the block.  Requiring it prevents
                # truncating malformed or incomplete diagnostics.
                if candidate + 1 < len(lines) and lines[candidate + 1].strip() == "":
                    root_index = candidate
                break
        if root_index is None:
            continue
        start = offsets[index]
        end = offsets[root_index + 1] + len(lines[root_index + 1])
        result.append((start, end, match.group(1), text[start:end]))
    return result


def _baseline_entries(baseline: dict[str, Any]) -> set[tuple[str, str]]:
    return {(entry["class"], entry["sha256"]) for entry in baseline["blocks"]}


def load_baseline(path: str | Path) -> dict[str, Any]:
    """Load and strictly validate a warning baseline JSON document."""
    with Path(path).open(encoding="utf-8") as handle:
        value = json.load(handle)
    _validate_baseline(value)
    return value


def _validate_baseline(value: Any) -> None:
    if not isinstance(value, dict) or set(value) != _BASELINE_KEYS:
        raise ValueError("warning baseline must contain exactly version, source_commit, blocks, and phpunit_aggregate_counts")
    if isinstance(value["version"], bool) or value["version"] != SCHEMA_VERSION or not isinstance(value["source_commit"], str):
        raise ValueError("unsupported warning baseline version or source commit")
    if not re.fullmatch(r"[0-9a-f]{40}", value["source_commit"]):
        raise ValueError("warning baseline source commit must be a 40-character SHA")
    if not isinstance(value["blocks"], list):
        raise ValueError("warning baseline blocks must be a list")
    counts = value["phpunit_aggregate_counts"]
    if not isinstance(counts, dict) or set(counts) - {"phpunit_deprecations", "phpunit_notices", "phpunit_skipped"}:
        raise ValueError("invalid PHPUnit aggregate baseline")
    if any(type(count) is not int or count < 0 for count in counts.values()):
        raise ValueError("invalid PHPUnit aggregate count")
    for entry in value["blocks"]:
        if not isinstance(entry, dict) or set(entry) != {"class", "sha256"}:
            raise ValueError("warning baseline block must contain exactly class and sha256")
        if not isinstance(entry["class"], str) or not isinstance(entry["sha256"], str) or not re.fullmatch(r"[0-9a-f]{64}", entry["sha256"]):
            raise ValueError("warning baseline block has invalid class or sha256")


def _warning_lines(text: str) -> list[str]:
    return [
        line
        for line in text.splitlines()
        if re.search(r"(?:\bWARN(?:ING)?\b|\bERROR\b|\bFATAL\b)", line, re.IGNORECASE)
    ]


def _tests(text: str) -> tuple[list[dict[str, int | None]], dict[str, int]]:
    records: list[dict[str, int | None]] = []
    aggregate: dict[str, int] = {}
    for line in text.splitlines():
        match = _PHPUNIT_HEAD.search(line)
        if match:
            values = {metric.lower(): int(value) for metric, value in _PHPUNIT_METRIC.findall(line)}
            records.append(
                {
                    "tests": int(match.group(1)),
                    "assertions": int(match.group(2)),
                    "skipped": values.get("skipped"),
                    "deprecations": values.get("deprecations"),
                    "notices": values.get("notices"),
                }
            )
            for metric, output_key in (
                ("deprecations", "phpunit_deprecations"),
                ("notices", "phpunit_notices"),
                ("skipped", "phpunit_skipped"),
            ):
                if metric in values:
                    aggregate[output_key] = aggregate.get(output_key, 0) + values[metric]
            continue
        match = _PHPUNIT_OK.search(line)
        if match:
            records.append(
                {
                    "tests": int(match.group(1)),
                    "assertions": int(match.group(2)),
                    "skipped": None,
                    "deprecations": None,
                    "notices": None,
                }
            )
    return records, aggregate


def _signals(text: str) -> list[dict[str, str]]:
    signals: list[dict[str, str]] = []
    for line in text.splitlines():
        if re.fullmatch(r"\s*(Violations|Skipped violations|Uncovered|Allowed|Warnings|Errors)\s+\d+\s*", line):
            signals.append({"category": "architecture", "status": "OBSERVED", "line": line.strip()})
            continue
        status = _SIGNAL.search(line)
        if not status:
            continue
        lowered = line.lower()
        if "coverage" in lowered:
            category = "coverage"
        elif "architecture" in lowered or "deptrac" in lowered or "ownership" in lowered:
            category = "architecture"
        elif "deep-runtime" in lowered or "deep runtime" in lowered:
            category = "deep-runtime"
        else:
            continue
        signals.append({"category": category, "status": status.group(1).upper(), "line": line})
    return signals


def analyze(text: str, baseline: dict[str, Any]) -> dict[str, Any]:
    """Summarise *text* while suppressing only exact, baselined Sass blocks."""
    if not isinstance(text, str):
        raise TypeError("text must be a string")
    # Validate caller-provided baselines with the same strict rules as files.
    _validate_baseline(baseline)
    entries = _baseline_entries(baseline)
    candidates = _block_candidates(text)
    suppressed: list[tuple[int, int, str]] = []
    known_counts: dict[str, int] = {}
    for start, end, warning_class, block in candidates:
        digest = hashlib.sha256(block.encode()).hexdigest()
        if (warning_class, digest) in entries:
            suppressed.append((start, end, block))
            known_counts[warning_class] = known_counts.get(warning_class, 0) + 1

    chunks: list[str] = []
    cursor = 0
    known_text_parts: list[str] = []
    for start, end, block in suppressed:
        chunks.append(text[cursor:start])
        known_text_parts.append(block)
        cursor = end
    chunks.append(text[cursor:])
    visible_text = "".join(chunks)
    test_records, aggregate_counts = _tests(text)
    known_text_parts.extend(
        line + "\n"
        for line in text.splitlines()
        if _PHPUNIT_HEAD.search(line)
    )
    known_counts.update(aggregate_counts)
    baseline_counts: dict[str, int] = {}
    for entry in baseline["blocks"]:
        warning_class = entry["class"]
        baseline_counts[warning_class] = baseline_counts.get(warning_class, 0) + 1
    baseline_counts.update(baseline["phpunit_aggregate_counts"])
    classes = sorted(set(baseline_counts) | set(known_counts))
    delta = {warning_class: known_counts.get(warning_class, 0) - baseline_counts.get(warning_class, 0) for warning_class in classes}
    total_baseline = sum(baseline_counts.values())
    total_current = sum(known_counts.values())
    return {
        "visible_text": visible_text,
        "known_text": "".join(known_text_parts),
        "tests": test_records,
        "signals": _signals(text),
        "known_warning_counts": known_counts,
        "new_warning_lines": _warning_lines(visible_text),
        "warning_delta": {
            "reference_scope": "full gate at " + baseline["source_commit"],
            "comparison": "aggregate counts only; different command scopes and individual issue identities are not comparable",
            "baseline": baseline_counts,
            "current": known_counts,
            "delta": delta,
            "total_baseline": total_baseline,
            "total_current": total_current,
            "total_delta": total_current - total_baseline,
        },
    }
