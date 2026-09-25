#!/usr/bin/env python3
"""Emit a deterministic, machine-readable receipt for PHPUnit JUnit XML.

The parser is intentionally strict: a missing, unreadable, malformed, or
structurally empty report is an error.  A skipped test is represented as
``skip`` and is never included in the passed count.
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import xml.etree.ElementTree as ET
from pathlib import Path
from typing import Iterable


SCHEMA_VERSION = 1
_WARNING = re.compile(r"\b(?:warning|warn|deprecation|notice|error|fatal)\b", re.IGNORECASE)
_DATASET_SUFFIX = re.compile(r"\s+(?:with data set|#\d+|\(.*\)).*$", re.IGNORECASE)
_MAX_CONTEXT_LINES = 20


class ReceiptError(ValueError):
    """Raised when a JUnit report cannot be trusted as a receipt."""


def _text(element: ET.Element | None) -> str:
    if element is None:
        return ""
    return "".join(element.itertext()).strip()


def _warning_context(testcase: ET.Element) -> list[str]:
    lines: list[str] = []
    for child_name in ("system-out", "system-err"):
        for line in _text(testcase.find(child_name)).splitlines():
            line = line.strip()
            if line and _WARNING.search(line) and line not in lines:
                lines.append(line)
            if len(lines) == _MAX_CONTEXT_LINES:
                return lines
    return lines


def _status(testcase: ET.Element) -> tuple[str, str | None]:
    # PHPUnit may emit more than one diagnostic child.  A failure/error is
    # always more actionable than an incidental skip marker.
    error = testcase.find("error")
    if error is not None:
        return "error", _text(error) or error.get("message")
    failure = testcase.find("failure")
    if failure is not None:
        return "fail", _text(failure) or failure.get("message")
    skipped = testcase.find("skipped")
    if skipped is not None:
        return "skip", _text(skipped) or skipped.get("message")
    return "pass", None


def _local_name(tag: str) -> str:
    return tag.rsplit("}", 1)[-1]


def _attribute(element: ET.Element, *names: str) -> str:
    wanted = {name.lower() for name in names}
    for name, value in element.attrib.items():
        if name.rsplit("}", 1)[-1].lower() in wanted:
            return value.strip()
    return ""


def _source_attribute(element: ET.Element, source_name: str, attribute_name: str) -> str:
    """Read an attribute from a namespaced PHPUnit OTR source element."""
    for child in element.iter():
        if _local_name(child.tag) == source_name:
            value = _attribute(child, attribute_name)
            if value:
                return value
    return ""


def _method_name(value: str) -> str:
    value = value.strip()
    if "::" in value:
        value = value.rsplit("::", 1)[-1]
    return _DATASET_SUFFIX.sub("", value).strip()


def _parse_otr(path: Path) -> list[tuple[str, str, str]]:
    """Return (class, method, reason) for skipped OTR events."""
    try:
        root = ET.parse(path).getroot()
    except (ET.ParseError, OSError, UnicodeError) as exc:
        raise ReceiptError(f"cannot parse OTR report {path}: {exc}") from exc

    started: list[dict[str, str]] = []
    consumed: set[int] = set()
    skipped: list[tuple[str, str, str]] = []
    for element in root.iter():
        name = _local_name(element.tag)
        if name == "started":
            class_name = _attribute(element, "className", "classname", "class")
            method_name = _attribute(
                element,
                "methodName",
                "methodname",
                "methodSource",
                "methodsource",
                "name",
            )
            class_name = class_name or _source_attribute(element, "methodSource", "className")
            class_name = class_name or _source_attribute(element, "classSource", "className")
            method_name = method_name or _source_attribute(element, "methodSource", "methodName")
            started.append(
                {
                    "id": _attribute(element, "id", "testId", "testid"),
                    "class": class_name,
                    "method": method_name,
                }
            )
            continue
        if name != "finished":
            continue
        result_status = _attribute(element, "result", "status")
        if not result_status:
            for child in element.iter():
                if _local_name(child.tag) == "result":
                    result_status = _attribute(child, "status")
                    break
        if result_status.upper() != "SKIPPED":
            continue
        event_id = _attribute(element, "id", "testId", "testid")
        candidates = [index for index, event in enumerate(started) if event_id and event["id"] == event_id]
        if not candidates and not event_id:
            candidates = [index for index in range(len(started) - 1, -1, -1) if index not in consumed]
        if len(candidates) != 1:
            raise ReceiptError(f"OTR skip event is unmatched or ambiguous: {path}")
        index = candidates[0]
        if not event_id:
            consumed.add(index)
        event = started[index]
        reason = _attribute(element, "reason")
        if not reason:
            for child in element.iter():
                if _local_name(child.tag) == "reason":
                    reason = _text(child)
                    break
        if not event["class"] or not event["method"] or not reason:
            raise ReceiptError(f"OTR skip event lacks class, method, or reason: {path}")
        skipped.append((event["class"], _method_name(event["method"]), reason))
    if not started:
        raise ReceiptError(f"OTR report contains no started events: {path}")
    return skipped


def _iter_testcases(element: ET.Element, suite_name: str = "") -> Iterable[tuple[str, ET.Element]]:
    tag = element.tag.rsplit("}", 1)[-1]
    current_suite = element.get("name", suite_name) if tag == "testsuite" else suite_name
    if tag == "testcase":
        yield current_suite, element
        return
    for child in list(element):
        yield from _iter_testcases(child, current_suite)


def parse_reports(
    paths: Iterable[str | Path],
    *,
    job: str,
    run: str,
    otr_paths: Iterable[str | Path] | None = None,
) -> dict[str, object]:
    """Parse one or more reports into a stable receipt dictionary."""
    if not job.strip() or not run.strip():
        raise ReceiptError("job and run labels must be non-empty")

    junit_paths = list(paths)
    otr_paths_list = list(otr_paths or [])
    if len(junit_paths) == 0 or len(junit_paths) != len(otr_paths_list):
        raise ReceiptError("each JUnit report requires exactly one matching OTR report")

    tests: list[dict[str, object]] = []
    source_paths: list[str] = []
    saw_report = False
    for raw_path, raw_otr_path in zip(junit_paths, otr_paths_list):
        path = Path(raw_path)
        otr_path = Path(raw_otr_path)
        source_paths.append(str(path))
        if not path.is_file():
            raise ReceiptError(f"JUnit report does not exist: {path}")
        try:
            root = ET.parse(path).getroot()
        except (ET.ParseError, OSError, UnicodeError) as exc:
            raise ReceiptError(f"cannot parse JUnit report {path}: {exc}") from exc
        if root.tag.rsplit("}", 1)[-1] not in {"testsuite", "testsuites"}:
            raise ReceiptError(f"unexpected JUnit root in {path}: {root.tag}")
        report_tests = list(_iter_testcases(root))
        if not report_tests:
            raise ReceiptError(f"JUnit report contains no testcases: {path}")
        saw_report = True
        otr_skips = _parse_otr(otr_path)
        junit_skip_entries: dict[tuple[str, str], list[dict[str, object]]] = {}
        junit_by_class: dict[str, list[dict[str, object]]] = {}
        for suite_name, testcase in report_tests:
            status, reason = _status(testcase)
            test_class = testcase.get("class") or testcase.get("classname", suite_name or "unspecified")
            if not testcase.get("class") and "." in test_class:
                test_class = test_class.replace(".", "\\")
            entry: dict[str, object] = {
                "job": job,
                "run": run,
                "source": str(path),
                "suite": suite_name or "unspecified",
                "class": test_class,
                "name": testcase.get("name", "unspecified"),
                "status": status,
            }
            if reason is not None:
                entry["reason"] = reason
            context = _warning_context(testcase)
            if context:
                entry["warning_context"] = context
            tests.append(entry)

            if status == "skip":
                key = (entry["class"], _method_name(str(entry["name"])))
                junit_skip_entries.setdefault(key, []).append(entry)
                junit_by_class.setdefault(str(entry["class"]), []).append(entry)

        otr_by_key: dict[tuple[str, str], list[str]] = {}
        for class_name, method_name, reason in otr_skips:
            otr_by_key.setdefault((class_name, method_name), []).append(reason)
        otr_by_class: dict[str, list[tuple[str, str]]] = {}
        for (class_name, method), reasons in otr_by_key.items():
            otr_by_class.setdefault(class_name, []).extend((method, reason) for reason in reasons)
        if set(otr_by_class) != set(junit_by_class):
            raise ReceiptError(f"OTR and JUnit skipped tests do not match: {path} / {otr_path}")

        assignments: list[tuple[dict[str, object], str]] = []
        for class_name, entries in junit_by_class.items():
            events = otr_by_class[class_name]
            if len(entries) != len(events):
                raise ReceiptError(f"OTR skip evidence is unmatched or ambiguous: {path} / {otr_path}")
            exact = all(
                len(junit_skip_entries.get((class_name, _method_name(str(entry["name"]))), []))
                == len(otr_by_key.get((class_name, _method_name(str(entry["name"]))), []))
                for entry in entries
            )
            if exact:
                for key, grouped_entries in junit_skip_entries.items():
                    if key[0] != class_name:
                        continue
                    assignments.extend(
                        (entry, reason)
                        for entry, reason in zip(grouped_entries, otr_by_key[key])
                    )
            elif (
                len(entries) > 1
                and len(events) > 1
                and len({method for method, _reason in events}) == 1
                and len({reason for _method, reason in events}) == 1
            ):
                # PHPUnit 13 can reuse one OTR id for a whole skipped class
                # group. Only a shared reason can be safely attributed to
                # each JUnit case when OTR omits the individual method names.
                assignments.extend((entry, events[0][1]) for entry in entries)
            else:
                raise ReceiptError(f"OTR skip evidence is unmatched or ambiguous: {path} / {otr_path}")

        for entry, reason in assignments:
            existing = entry.get("reason")
            if existing is not None and existing != reason:
                raise ReceiptError(f"JUnit and OTR skip reasons differ: {path} / {otr_path}")
            entry["reason"] = reason

    if not saw_report:
        raise ReceiptError("at least one JUnit report is required")
    counts = {status: sum(test["status"] == status for test in tests) for status in ("pass", "fail", "error", "skip")}
    return {
        "schema": "fh-junit-receipt",
        "version": SCHEMA_VERSION,
        "job": job,
        "run": run,
        "sources": source_paths,
        "summary": {"total": len(tests), **counts},
        "tests": tests,
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--input", action="append", required=True, metavar="XML")
    parser.add_argument("--otr-input", action="append", required=True, metavar="XML")
    parser.add_argument("--job", required=True)
    parser.add_argument("--run", required=True)
    args = parser.parse_args(argv)
    try:
        receipt = parse_reports(args.input, otr_paths=args.otr_input, job=args.job, run=args.run)
    except ReceiptError as exc:
        print(f"junit receipt error: {exc}", file=sys.stderr)
        return 2
    print(json.dumps(receipt, ensure_ascii=False, sort_keys=True, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
