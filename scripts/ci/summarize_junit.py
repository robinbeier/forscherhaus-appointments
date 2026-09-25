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


def _parse_otr(path: Path) -> list[tuple[str, str, str, str | None]]:
    """Return (class, method, status, reason) for OTR test events."""
    try:
        root = ET.parse(path).getroot()
    except (ET.ParseError, OSError, UnicodeError) as exc:
        raise ReceiptError(f"cannot parse OTR report {path}: {exc}") from exc

    started: list[dict[str, str]] = []
    consumed: set[int] = set()
    results: list[tuple[str, str, str, str | None]] = []
    finished_ids: set[str] = set()
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
        event_id = _attribute(element, "id", "testId", "testid")
        if event_id:
            finished_ids.add(event_id)
        result_status = _attribute(element, "result", "status")
        if not result_status:
            for child in element.iter():
                if _local_name(child.tag) == "result":
                    result_status = _attribute(child, "status")
                    break
        if not result_status:
            continue
        candidates = [index for index, event in enumerate(started) if event_id and event["id"] == event_id]
        if not candidates and not event_id:
            candidates = [index for index in range(len(started) - 1, -1, -1) if index not in consumed]
        if len(candidates) != 1:
            raise ReceiptError(f"OTR test event is unmatched or ambiguous: {path}")
        index = candidates[0]
        if not event_id:
            consumed.add(index)
        event = started[index]
        reason = _attribute(element, "reason") or None
        if reason is None:
            for child in element.iter():
                if _local_name(child.tag) == "reason":
                    reason = _text(child)
                    break
        # Class/container events have no method source and are not testcases.
        # Every method event must have a class, method, and supported result.
        if not event["class"] or not event["method"]:
            continue
        status = result_status.upper()
        if status not in {"SUCCESSFUL", "PASSED", "SKIPPED", "FAILED", "FAILURE", "ERROR"}:
            raise ReceiptError(f"OTR test has unsupported result {status}: {path}")
        if status == "SKIPPED" and not reason:
            raise ReceiptError(f"OTR skip event lacks a reason: {path}")
        if status == "PASSED":
            status = "SUCCESSFUL"
        elif status in {"FAILED", "FAILURE"}:
            status = "FAIL"
        results.append((event["class"], _method_name(event["method"]), status, reason))
    if not started:
        raise ReceiptError(f"OTR report contains no started events: {path}")
    # PHPUnit 13 can emit a method start without a result for a small set of
    # successful tests.  Keep this explicit so the caller may accept it only
    # when the matching JUnit testcase is a pass; it must never mask a skip,
    # failure, or error.
    for event in started:
        if event["class"] and event["method"] and event["id"] not in finished_ids:
            results.append((event["class"], _method_name(event["method"]), "MISSING", None))
    return results


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
        otr_results = _parse_otr(otr_path)
        junit_entries: dict[tuple[str, str], list[dict[str, object]]] = {}
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

            key = (entry["class"], _method_name(str(entry["name"])))
            junit_entries.setdefault(key, []).append(entry)

        otr_by_key: dict[tuple[str, str], list[tuple[str, str | None]]] = {}
        for class_name, method_name, status, reason in otr_results:
            otr_by_key.setdefault((class_name, method_name), []).append((status, reason))
        junit_by_class: dict[str, list[dict[str, object]]] = {}
        otr_by_class: dict[str, list[tuple[str, str, str | None]]] = {}
        for (class_name, _method), entries in junit_entries.items():
            junit_by_class.setdefault(class_name, []).extend(entries)
        for (class_name, method), results in otr_by_key.items():
            otr_by_class.setdefault(class_name, []).extend((method, status, reason) for status, reason in results)
        if set(otr_by_class) != set(junit_by_class):
            raise ReceiptError(f"OTR and JUnit test classes do not match: {path} / {otr_path}")

        assignments: list[tuple[dict[str, object], str]] = []
        for class_name, entries in junit_by_class.items():
            events = otr_by_class[class_name]
            if len(entries) != len(events):
                raise ReceiptError(f"OTR test evidence is unmatched or ambiguous: {path} / {otr_path}")
            exact = all(
                len(junit_entries.get((class_name, _method_name(str(entry["name"]))), []))
                == len(otr_by_key.get((class_name, _method_name(str(entry["name"]))), []))
                for entry in entries
            )
            if exact:
                for key, grouped_entries in junit_entries.items():
                    if key[0] != class_name:
                        continue
                    assignments.extend(
                        (entry, result)
                        for entry, result in zip(grouped_entries, otr_by_key[key])
                    )
            elif (
                len(entries) > 1
                and len(events) > 1
                and all(entry["status"] == "skip" for entry in entries)
                and all(status == "SKIPPED" for _method, status, _reason in events)
                and len({method for method, _status, _reason in events}) == 1
                and len({reason for _method, _status, reason in events}) == 1
            ):
                # PHPUnit 13 can reuse one OTR id for a skipped class. The
                # individual method identity is then absent, so attribute
                # only a single shared reason to the JUnit class cases.
                assignments.extend((entry, ("SKIPPED", events[0][2])) for entry in entries)
            else:
                raise ReceiptError(f"OTR test evidence is unmatched or ambiguous: {path} / {otr_path}")

        for entry, (observed_status, reason) in assignments:
            if entry["status"] == "pass":
                expected_status = "SUCCESSFUL"
            elif entry["status"] == "skip":
                expected_status = "SKIPPED"
            elif entry["status"] == "fail":
                expected_status = "FAIL"
            else:
                expected_status = "ERROR"
            compatible_failure = expected_status in {"FAIL", "ERROR"} and observed_status in {"FAIL", "ERROR"}
            if observed_status != expected_status and not compatible_failure and not (
                observed_status == "MISSING" and expected_status == "SUCCESSFUL"
            ):
                raise ReceiptError(f"JUnit and OTR test results differ: {path} / {otr_path}")
            if observed_status == "MISSING":
                entry["otr_result"] = "started_without_finish"
            if entry["status"] == "skip":
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
