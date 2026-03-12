#!/usr/bin/env python3
"""Run a Symphony soak gate against the optional state API."""

from __future__ import annotations

import argparse
import json
import time
import urllib.error
import urllib.request
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


def utc_now_iso() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z")


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run Symphony pilot soak gate.")
    parser.add_argument("--state-url", default=None, help="State API URL (e.g. http://127.0.0.1:8787/api/v1/state).")
    parser.add_argument("--sample-file", default=None, help="Optional static sample JSON payload for dry runs.")
    parser.add_argument("--duration-seconds", type=int, default=24 * 60 * 60, help="Soak duration in seconds.")
    parser.add_argument("--poll-seconds", type=int, default=60, help="Polling interval in seconds.")
    parser.add_argument("--stuck-threshold-polls", type=int, default=30, help="Consecutive polls before issue is stuck.")
    parser.add_argument("--max-running", type=int, default=2, help="Maximum acceptable concurrent running sessions.")
    parser.add_argument("--max-retrying", type=int, default=50, help="Maximum acceptable retry queue size.")
    parser.add_argument(
        "--output-json",
        default=None,
        help="Optional output report path. Default: storage/logs/symphony/soak-gate-<UTC>.json",
    )

    args = parser.parse_args()
    has_state_url = bool(args.state_url)
    has_sample_file = bool(args.sample_file)
    if has_state_url == has_sample_file:
        parser.error("Provide exactly one of --state-url or --sample-file.")

    if args.duration_seconds < 1:
        parser.error("--duration-seconds must be >= 1.")
    if args.poll_seconds < 1:
        parser.error("--poll-seconds must be >= 1.")
    if args.stuck_threshold_polls < 1:
        parser.error("--stuck-threshold-polls must be >= 1.")
    if args.max_running < 0:
        parser.error("--max-running must be >= 0.")
    if args.max_retrying < 0:
        parser.error("--max-retrying must be >= 0.")

    return args


def load_snapshot_from_url(state_url: str) -> dict[str, Any]:
    request = urllib.request.Request(state_url, method="GET")
    with urllib.request.urlopen(request, timeout=10) as response:
        if response.status != 200:
            raise RuntimeError(f"State API returned HTTP {response.status}")
        payload = json.loads(response.read().decode("utf-8"))
    return payload


def load_snapshot_from_file(sample_path: Path) -> dict[str, Any]:
    return json.loads(sample_path.read_text(encoding="utf-8"))


@dataclass
class SoakStats:
    sample_count: int = 0
    max_running: int = 0
    max_retrying: int = 0
    stuck_detected: bool = False


def main() -> int:
    args = parse_args()

    started_at_iso = utc_now_iso()
    started_monotonic = time.monotonic()
    deadline = started_monotonic + float(args.duration_seconds)

    issue_streaks: dict[str, int] = {}
    stats = SoakStats()
    findings: list[str] = []
    samples: list[dict[str, Any]] = []

    first_counts: dict[str, int] | None = None
    last_counts: dict[str, int] | None = None
    max_codex_totals: dict[str, int] = {}

    sample_path = Path(args.sample_file) if args.sample_file else None
    if sample_path and not sample_path.is_file():
        raise SystemExit(f"Sample file not found: {sample_path}")

    while time.monotonic() < deadline:
        polled_at_iso = utc_now_iso()

        try:
            payload = (
                load_snapshot_from_file(sample_path)
                if sample_path
                else load_snapshot_from_url(str(args.state_url))
            )
        except (urllib.error.URLError, RuntimeError, json.JSONDecodeError) as error:
            findings.append(f"state_fetch_error: {error}")
            break

        snapshot = payload.get("snapshot", {})
        running = snapshot.get("running", [])
        retrying = snapshot.get("retrying", [])
        counts = snapshot.get("counts", {})
        codex_totals = snapshot.get("codex_totals", {})

        if (
            not isinstance(running, list)
            or not isinstance(retrying, list)
            or not isinstance(counts, dict)
            or not isinstance(codex_totals, dict)
        ):
            findings.append("invalid_snapshot_shape")
            break

        stats.sample_count += 1
        stats.max_running = max(stats.max_running, len(running))
        stats.max_retrying = max(stats.max_retrying, len(retrying))

        running_ids = []
        for entry in running:
            if isinstance(entry, dict):
                issue_identifier = str(entry.get("issueIdentifier") or "").strip()
                if issue_identifier:
                    running_ids.append(issue_identifier)

        current_running_set = set(running_ids)
        for issue_identifier in list(issue_streaks.keys()):
            if issue_identifier in current_running_set:
                issue_streaks[issue_identifier] += 1
            else:
                del issue_streaks[issue_identifier]
        for issue_identifier in current_running_set:
            issue_streaks.setdefault(issue_identifier, 1)

        stuck_now = [issue for issue, streak in issue_streaks.items() if streak >= args.stuck_threshold_polls]
        if stuck_now:
            stats.stuck_detected = True
            findings.append(f"stuck_sessions_detected: {','.join(sorted(stuck_now))}")

        if first_counts is None:
            first_counts = {k: int(v) for k, v in counts.items() if isinstance(v, int)}
        last_counts = {k: int(v) for k, v in counts.items() if isinstance(v, int)}
        current_codex_totals = {k: int(v) for k, v in codex_totals.items() if isinstance(v, int)}
        for key, value in current_codex_totals.items():
            max_codex_totals[key] = max(max_codex_totals.get(key, 0), value)

        samples.append(
            {
                "polledAtIso": polled_at_iso,
                "runningCount": len(running),
                "retryingCount": len(retrying),
                "counts": last_counts,
                "codexTotals": current_codex_totals,
            }
        )

        sleep_seconds = min(args.poll_seconds, max(0.0, deadline - time.monotonic()))
        if sleep_seconds > 0:
            time.sleep(sleep_seconds)

    ended_at_iso = utc_now_iso()

    if stats.sample_count == 0:
        findings.append("no_samples_collected")

    if stats.max_running > args.max_running:
        findings.append(f"max_running_exceeded: observed={stats.max_running} allowed={args.max_running}")
    if stats.max_retrying > args.max_retrying:
        findings.append(f"max_retrying_exceeded: observed={stats.max_retrying} allowed={args.max_retrying}")

    counts_delta: dict[str, int] = {}
    if first_counts is not None and last_counts is not None:
        all_keys = set(first_counts.keys()) | set(last_counts.keys())
        for key in sorted(all_keys):
            counts_delta[key] = last_counts.get(key, 0) - first_counts.get(key, 0)

    verdict = "pass" if not findings else "fail"

    report = {
        "startedAtIso": started_at_iso,
        "endedAtIso": ended_at_iso,
        "durationSeconds": args.duration_seconds,
        "pollSeconds": args.poll_seconds,
        "verdict": verdict,
        "thresholds": {
            "maxRunning": args.max_running,
            "maxRetrying": args.max_retrying,
            "stuckThresholdPolls": args.stuck_threshold_polls,
        },
        "stats": {
            "sampleCount": stats.sample_count,
            "maxRunning": stats.max_running,
            "maxRetrying": stats.max_retrying,
            "stuckDetected": stats.stuck_detected,
            "countsDelta": counts_delta,
            "maxCodexTotalsObserved": max_codex_totals,
        },
        "findings": findings,
        "samples": samples,
    }

    if args.output_json:
        output_path = Path(args.output_json)
    else:
        timestamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
        output_path = Path("storage/logs/symphony") / f"soak-gate-{timestamp}.json"

    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(json.dumps(report, indent=2), encoding="utf-8")

    if verdict == "pass":
        print(f"[symphony-soak-gate] PASS report written to {output_path}")
        return 0

    print(f"[symphony-soak-gate] FAIL report written to {output_path}")
    for finding in findings:
        print(f" - {finding}")
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
