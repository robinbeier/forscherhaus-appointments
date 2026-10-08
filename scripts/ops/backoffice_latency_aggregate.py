#!/usr/bin/env python3
"""Aggregate bounded, privacy-safe backoffice latency observations from stdin."""

from __future__ import annotations

import argparse
import json
import re
import sys
from collections.abc import Iterable
from datetime import datetime, timezone
from typing import Any


OPERATIONS = (
    "login_page",
    "login_validation",
    "dashboard_page",
    "dashboard_data",
    "calendar_page",
    "calendar_event_read",
    "calendar_unavailability_save",
    "legacy_alias_redirect",
)
METHODS = ("GET", "POST", "OTHER")
STATUS_CLASSES = ("2xx", "3xx", "4xx", "5xx", "other")
DURATION_BUCKETS = ("0-99999us", "100000-249999us", "250000-499999us", "500000-999999us", "1000000-2999999us", "3000000us+")
# Descriptive sample guard only; it does not establish p95, identity, or improvement.
LOW_SAMPLE_THRESHOLD = 20

_COMBINED = re.compile(
    r'^\S+ \S+ \S+ \[(?P<timestamp>[^\]]+)\] '
    r'"(?P<method>\S+) (?P<target>\S+) HTTP/[^"]+" '
    r'(?P<status>\S+) \S+ "(?:[^"\\]|\\.)*" "(?:[^"\\]|\\.)*"'
    r'(?: (?P<duration>\S+))?$'
)
_ROUTE_PATHS = {
    "/login": "login_page",
    "/login/validate": "login_validation",
    "/dashboard": "dashboard_page",
    "/dashboard/index": "dashboard_page",
    "/dashboard/metrics": "dashboard_data",
    "/dashboard/heatmap": "dashboard_data",
    "/dashboard/threshold": "dashboard_data",
    "/dashboard/provider_metrics": "dashboard_data",
    "/calendar": "calendar_page",
    "/calendar/index": "calendar_page",
    "/calendar/get_calendar_appointments": "calendar_event_read",
    "/calendar/get_calendar_appointments_for_table_view": "calendar_event_read",
    "/calendar/save_unavailability": "calendar_unavailability_save",
    "/backend_api/ajax_get_calendar_events": "legacy_alias_redirect",
    "/backend_api/ajax_get_calendar_appointments": "legacy_alias_redirect",
}


def _empty_operation_counts() -> dict[str, dict[str, Any]]:
    return {
        operation: {
            "valid_measurements": 0,
            "missing_duration": 0,
            "invalid_duration": 0,
            "result_class": "no_measurement",
            "duration_buckets": _empty_duration_counts(),
            "method_status": {
                method: {status: 0 for status in STATUS_CLASSES}
                for method in METHODS
            },
            "low_sample": True,
        }
        for operation in OPERATIONS
    }


def _empty_duration_counts() -> dict[str, int]:
    return {bucket: 0 for bucket in DURATION_BUCKETS}


def _parse_window(value: str) -> datetime:
    if value.endswith("Z"):
        value = value[:-1] + "+00:00"
    parsed = datetime.fromisoformat(value)
    if parsed.tzinfo is None:
        raise ValueError("window timestamps must include a UTC offset")
    return parsed.astimezone(timezone.utc)


def _duration_bucket(duration: int) -> str:
    if duration < 100000:
        return DURATION_BUCKETS[0]
    if duration < 250000:
        return DURATION_BUCKETS[1]
    if duration < 500000:
        return DURATION_BUCKETS[2]
    if duration < 1000000:
        return DURATION_BUCKETS[3]
    if duration < 3000000:
        return DURATION_BUCKETS[4]
    return DURATION_BUCKETS[5]


def _status_class(status: int) -> str:
    prefix = status // 100
    return f"{prefix}xx" if f"{prefix}xx" in STATUS_CLASSES else "other"


def _result_class(valid: int, missing: int, invalid: int) -> str:
    if missing or invalid:
        return "incomplete_format"
    if valid == 0:
        return "no_measurement"
    if valid < LOW_SAMPLE_THRESHOLD:
        return "insufficient_samples"
    return "measured"


def parse_line(line: str) -> tuple[str, dict[str, Any] | None]:
    """Parse one line, returning a reason and a privacy-safe event when valid."""
    match = _COMBINED.fullmatch(line.rstrip("\n"))
    if not match:
        return "malformed", None
    try:
        timestamp = datetime.strptime(match["timestamp"], "%d/%b/%Y:%H:%M:%S %z").astimezone(timezone.utc)
        status = int(match["status"])
    except (TypeError, ValueError):
        return "malformed", None
    target = match["target"].split("?", 1)[0].rstrip("/") or "/"
    # CodeIgniter's index.php entrypoint is canonical on production. Only the
    # backend_api routes are redirect aliases, regardless of entrypoint.
    if target.startswith("/index.php/"):
        target = "/" + target[len("/index.php/"):]
    operation = _ROUTE_PATHS.get(target)
    if operation is None:
        return "excluded_route", {"timestamp": timestamp}
    duration_text = match["duration"]
    if duration_text is None:
        return "missing_duration", {"timestamp": timestamp, "operation": operation}
    if not duration_text.isdigit():
        return "invalid_duration", {"timestamp": timestamp, "operation": operation}
    method = match["method"] if match["method"] in ("GET", "POST") else "OTHER"
    return "valid", {"timestamp": timestamp, "operation": operation, "method": method, "status": _status_class(status), "duration": int(duration_text)}


def aggregate(lines: Iterable[str], start: datetime, end: datetime) -> dict[str, Any]:
    """Aggregate only allowlisted observations in [start, end), with fixed memory."""
    start = start.astimezone(timezone.utc)
    end = end.astimezone(timezone.utc)
    result: dict[str, Any] = {
        "window_start": start.isoformat().replace("+00:00", "Z"),
        "window_end": end.isoformat().replace("+00:00", "Z"),
        "lines_seen": 0,
        "lines_in_window": 0,
        "valid_measurements": 0,
        "missing_duration": 0,
        "invalid_duration": 0,
        "malformed_lines": 0,
        "excluded_routes": 0,
        "operations": _empty_operation_counts(),
        "low_sample": True,
        "result_class": "no_measurement",
    }
    for line in lines:
        result["lines_seen"] += 1
        reason, event = parse_line(line)
        if event is not None and not start <= event["timestamp"] < end:
            continue
        if event is not None:
            result["lines_in_window"] += 1
        if reason == "malformed":
            result["malformed_lines"] += 1
            continue
        if reason == "missing_duration":
            result["missing_duration"] += 1
            result["operations"][event["operation"]]["missing_duration"] += 1
            continue
        if reason == "invalid_duration":
            result["invalid_duration"] += 1
            result["operations"][event["operation"]]["invalid_duration"] += 1
            continue
        if reason == "excluded_route":
            result["excluded_routes"] += 1
            continue
        assert event is not None
        if not start <= event["timestamp"] < end:
            continue
        result["valid_measurements"] += 1
        operation = result["operations"][event["operation"]]
        operation["valid_measurements"] += 1
        operation["duration_buckets"][_duration_bucket(event["duration"])] += 1
        operation["method_status"][event["method"]][event["status"]] += 1
    for operation in result["operations"].values():
        operation["low_sample"] = operation["valid_measurements"] < LOW_SAMPLE_THRESHOLD
        operation["result_class"] = _result_class(
            operation["valid_measurements"], operation["missing_duration"], operation["invalid_duration"]
        )
    result["low_sample"] = result["valid_measurements"] < LOW_SAMPLE_THRESHOLD
    result["result_class"] = _result_class(
        result["valid_measurements"], result["missing_duration"], result["invalid_duration"]
    )
    return result


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--start", required=True, type=_parse_window, help="UTC window start (ISO-8601)")
    parser.add_argument("--end", required=True, type=_parse_window, help="UTC window end (ISO-8601, exclusive)")
    args = parser.parse_args(argv)
    if args.end <= args.start:
        parser.error("--end must be after --start")
    json.dump(aggregate(sys.stdin, args.start, args.end), sys.stdout, sort_keys=True)
    sys.stdout.write("\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
