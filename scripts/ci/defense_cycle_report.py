#!/usr/bin/env python3
"""Write the small, non sensitive receipt for an isolated Defense cycle."""

from __future__ import annotations

import argparse
import json
import math
import time
import re
import xml.etree.ElementTree as ET
from pathlib import Path

PHASES = (
    "compose_start", "php_ready", "synthetic_config", "mysql_ready",
    "php_ready_after_mysql", "app_db_ready", "seed_install", "phpunit",
    "ordinary_operator_entrypoint",
    "cleanup",
)


def parse_events(path: Path) -> list[dict[str, object]]:
    starts: dict[str, int] = {}
    result: list[dict[str, object]] = []
    try:
        lines = path.read_text(encoding="utf-8").splitlines()
    except OSError:
        lines = []
    for line in lines:
        fields = line.split("|")
        if len(fields) != 3 or fields[0] not in PHASES:
            continue
        phase, stamp, status = fields
        try:
            now = int(stamp)
        except ValueError:
            continue
        if status == "started":
            starts[phase] = now
        elif status in ("passed", "failed") and phase in starts:
            result.append({"name": phase, "status": status, "duration_ms": max(0, now - starts.pop(phase))})
    for phase, started in starts.items():
        result.append({"name": phase, "status": "failed", "duration_ms": max(0, time.monotonic_ns() // 1_000_000 - started)})
    by_name = {item["name"]: item for item in result}
    return [by_name.get(phase, {"name": phase, "status": "unavailable", "duration_ms": 0}) for phase in PHASES]


def parse_junit(path: Path) -> tuple[bool, list[dict[str, object]]]:
    try:
        root = ET.parse(path).getroot()
    except (OSError, ET.ParseError):
        return False, []
    tests: list[dict[str, object]] = []
    for case in root.iter("testcase"):
        status = "passed"
        if case.find("failure") is not None:
            status = "failure"
        elif case.find("error") is not None:
            status = "error"
        elif case.find("skipped") is not None:
            status = "skipped"
        try:
            duration = float(case.attrib.get("time", "0"))
            assertions = int(case.attrib.get("assertions", "0"))
        except ValueError:
            return False, []
        name = case.attrib.get("name", "")
        class_name = case.attrib.get("classname", case.attrib.get("class", ""))
        if not math.isfinite(duration) or duration < 0 or assertions < 0 or not name or not class_name:
            return False, []
        tests.append({
            "class": class_name,
            "name": name,
            "assertions": assertions,
            "duration": duration,
            "status": status,
        })
    return bool(tests), tests


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--events", required=True)
    parser.add_argument("--junit", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--commit", required=True)
    parser.add_argument("--dirty", required=True, choices=("true", "false"))
    parser.add_argument("--runner-status", required=True, type=int)
    parser.add_argument("--cleanup-status", required=True, type=int)
    args = parser.parse_args()
    junit_available, tests = parse_junit(Path(args.junit))
    phases = parse_events(Path(args.events))
    valid_commit = bool(re.fullmatch(r"[0-9a-f]{40}", args.commit))
    gate_ok = args.runner_status == 0 and args.cleanup_status == 0 and junit_available
    gate_ok = gate_ok and valid_commit and all(phase["status"] == "passed" for phase in phases)
    gate_ok = gate_ok and bool(tests)
    if junit_available:
        gate_ok = gate_ok and all(test["status"] in ("passed", "skipped") for test in tests)
    counts = {status: sum(test["status"] == status for test in tests)
              for status in ("passed", "skipped", "failure", "error")}
    overall = "failed"
    if gate_ok:
        overall = "passed_with_skips" if counts["skipped"] else "passed"
    report = {
        "schema": "defense-cycle-summary.v1",
        "source": {"commit": args.commit, "dirty": args.dirty == "true"},
        "environment": "isolated",
        "phases": phases,
        "junit": {"status": "available" if junit_available else "unavailable", "counts": counts, "tests": tests},
        "cleanup": {"scope": "owned_docker_stack", "status": "passed" if args.cleanup_status == 0 else "failed", "exit_code": args.cleanup_status},
        "runner_exit_code": args.runner_status,
        "overall_status": overall,
    }
    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
