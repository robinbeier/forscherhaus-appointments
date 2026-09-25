import importlib.util
import io
import json
from pathlib import Path
import tempfile
import unittest
from contextlib import redirect_stdout


MODULE_PATH = Path(__file__).parents[2] / "scripts/ci/summarize_junit.py"
spec = importlib.util.spec_from_file_location("summarize_junit", MODULE_PATH)
module = importlib.util.module_from_spec(spec)
assert spec.loader is not None
spec.loader.exec_module(module)


class SummarizeJunitTest(unittest.TestCase):
    def write_report(self, text: str) -> Path:
        directory = Path(tempfile.mkdtemp())
        path = directory / "junit.xml"
        path.write_text(text, encoding="utf-8")
        self.addCleanup(lambda: directory.rmdir())
        self.addCleanup(lambda: path.unlink(missing_ok=True))
        return path

    def write_otr(self, text: str) -> Path:
        directory = Path(tempfile.mkdtemp())
        path = directory / "otr.xml"
        path.write_text(text, encoding="utf-8")
        self.addCleanup(lambda: directory.rmdir())
        self.addCleanup(lambda: path.unlink(missing_ok=True))
        return path

    def test_all_statuses_are_explicit_and_skip_is_not_pass(self):
        path = self.write_report(
            """<testsuites><testsuite name="root"><testcase classname="A" name="ok"/>
            <testcase classname="A" name="bad"><failure message="assertion">expected x</failure>
              <system-err>WARNING: assertion context</system-err></testcase>
            <testcase classname="B" name="broken"><error>database unavailable</error></testcase>
            <testcase classname="C" name="guarded"><skipped message="Docker unavailable"/></testcase>
            </testsuite></testsuites>"""
        )
        otr = self.write_otr(
            """<events xmlns:e="urn:phpunit"><e:started id="1" className="A" methodName="ok"/>
            <e:finished id="1" result="PASSED"/><e:started id="2" className="A" methodName="bad"/>
            <e:finished id="2" result="FAILED"/><e:started id="3" className="B" methodName="broken"/>
            <e:finished id="3" result="ERROR"/><e:started id="4" className="C" methodName="guarded"/>
            <e:finished id="4" result="SKIPPED"><e:reason>Docker unavailable</e:reason></e:finished>
            </events>"""
        )
        receipt = module.parse_reports([path], otr_paths=[otr], job="build-test", run="123")
        self.assertEqual(receipt["summary"], {"total": 4, "pass": 1, "fail": 1, "error": 1, "skip": 1})
        self.assertEqual([test["status"] for test in receipt["tests"]], ["pass", "fail", "error", "skip"])
        self.assertEqual(receipt["tests"][3]["reason"], "Docker unavailable")
        self.assertEqual(receipt["tests"][1]["warning_context"], ["WARNING: assertion context"])
        self.assertEqual(receipt["tests"][0]["job"], "build-test")
        self.assertEqual(receipt["tests"][0]["run"], "123")

    def test_multiple_reports_preserve_input_and_test_order(self):
        first = self.write_report('<testsuite name="one"><testcase classname="A" name="first"/></testsuite>')
        second = self.write_report('<testsuite name="two"><testcase classname="B" name="second"/></testsuite>')
        otr1 = self.write_otr('<events xmlns:e="urn:phpunit"><e:started id="1" className="A" methodName="first"/><e:finished id="1" result="PASSED"/></events>')
        otr2 = self.write_otr('<events xmlns:e="urn:phpunit"><e:started id="2" className="B" methodName="second"/><e:finished id="2" result="PASSED"/></events>')
        receipt = module.parse_reports([first, second], otr_paths=[otr1, otr2], job="job", run="run")
        self.assertEqual([test["name"] for test in receipt["tests"]], ["first", "second"])
        self.assertEqual(receipt["sources"], [str(first), str(second)])

    def test_missing_empty_and_malformed_reports_fail_closed(self):
        with self.assertRaises(module.ReceiptError):
            module.parse_reports(["/does/not/exist.xml"], otr_paths=["/does/not/otr.xml"], job="job", run="run")
        empty = self.write_report('<testsuite name="empty"/>')
        with self.assertRaises(module.ReceiptError):
            otr = self.write_otr('<events xmlns:e="urn:phpunit"/>')
            module.parse_reports([empty], otr_paths=[otr], job="job", run="run")
        malformed = self.write_report("<testsuite>")
        with self.assertRaises(module.ReceiptError):
            otr = self.write_otr('<events xmlns:e="urn:phpunit"/>')
            module.parse_reports([malformed], otr_paths=[otr], job="job", run="run")

    def test_cli_output_is_stable_json(self):
        path = self.write_report('<testsuite name="one"><testcase classname="A" name="first"/></testsuite>')
        otr = self.write_otr('<events xmlns:e="urn:phpunit"><e:started id="1" className="A" methodName="first"/><e:finished id="1" result="PASSED"/></events>')
        output = io.StringIO()
        with redirect_stdout(output):
            result = module.main(["--input", str(path), "--otr-input", str(otr), "--job", "job", "--run", "run"])
        self.assertEqual(result, 0)
        self.assertEqual(json.loads(output.getvalue())["summary"]["pass"], 1)

    def test_empty_labels_fail_closed(self):
        path = self.write_report('<testsuite name="one"><testcase name="first"/></testsuite>')
        with self.assertRaises(module.ReceiptError):
            module.parse_reports([path], otr_paths=[self.write_otr('<events xmlns:e="urn:phpunit"/>')], job="", run="run")

    def test_dataset_skip_reasons_are_correlated_in_order(self):
        path = self.write_report(
            '<testsuite name="suite"><testcase classname="A" name="testGuard with data set #0"><skipped/></testcase>'
            '<testcase classname="A" name="testGuard with data set #1"><skipped/></testcase></testsuite>'
        )
        otr = self.write_otr(
            '<events xmlns:e="urn:phpunit"><e:started id="1" className="A" methodName="testGuard"/>'
            '<e:finished id="1" result="SKIPPED"><e:reason>first reason</e:reason></e:finished>'
            '<e:started id="2" className="A" methodName="testGuard"/><e:finished id="2" result="SKIPPED">'
            '<e:reason>second reason</e:reason></e:finished></events>'
        )
        receipt = module.parse_reports([path], otr_paths=[otr], job="job", run="run")
        self.assertEqual([test["reason"] for test in receipt["tests"]], ["first reason", "second reason"])

    def test_phpunit_13_namespaced_nested_otr_sources_and_result(self):
        path = self.write_report(
            '<testsuites><testsuite name="Rob624SkipSampleTest" tests="1" skipped="1">'
            '<testcase name="testGuarded" class="Rob624SkipSampleTest" classname="Rob624SkipSampleTest"><skipped/></testcase>'
            '</testsuite></testsuites>'
        )
        otr = self.write_otr(
            """<?xml version="1.0"?>
            <e:events xmlns="https://schemas.opentest4j.org/reporting/core/0.2.0"
              xmlns:e="https://schemas.opentest4j.org/reporting/events/0.2.0"
              xmlns:phpunit="https://schema.phpunit.de/otr/phpunit/0.0.1">
              <e:started id="1" name="Rob624SkipSampleTest"><sources><phpunit:classSource className="Rob624SkipSampleTest"/></sources></e:started>
              <e:started id="2" parentId="1" name="testGuarded"><sources><phpunit:methodSource className="Rob624SkipSampleTest" methodName="testGuarded"/></sources></e:started>
              <e:finished id="2"><result status="SKIPPED"><reason>requires owned synthetic stack</reason></result></e:finished>
              <e:finished id="1"/>
            </e:events>"""
        )
        receipt = module.parse_reports([path], otr_paths=[otr], job="calendar", run="456")
        self.assertEqual(receipt["summary"], {"total": 1, "pass": 0, "fail": 0, "error": 0, "skip": 1})
        self.assertEqual(receipt["tests"][0]["reason"], "requires owned synthetic stack")

    def test_phpunit_13_reuses_an_event_id_for_multiple_class_skips(self):
        path = self.write_report(
            '<testsuite name="Tests\\Unit\\RootFixtures" tests="2" skipped="2">'
            '<testcase name="testFirst" class="Tests\\Unit\\RootFixtures" '
            'classname="Tests.Unit.RootFixtures"><skipped/></testcase>'
            '<testcase name="testSecond" class="Tests\\Unit\\RootFixtures" '
            'classname="Tests.Unit.RootFixtures"><skipped/></testcase></testsuite>'
        )
        otr = self.write_otr(
            '<events xmlns:e="urn:events" xmlns:phpunit="urn:phpunit">'
            '<e:started id="2" name="testFirst"><sources><phpunit:methodSource '
            'className="Tests\\Unit\\RootFixtures" methodName="testFirst"/></sources></e:started>'
            '<e:finished id="2"><result status="SKIPPED"><reason>first reason</reason></result></e:finished>'
            '<e:finished id="2"><result status="SKIPPED"><reason>second reason</reason></result></e:finished>'
            '</events>'
        )
        receipt = module.parse_reports([path], otr_paths=[otr], job="build-test", run="789")
        self.assertEqual(receipt["summary"]["skip"], 2)
        self.assertEqual([test["reason"] for test in receipt["tests"]], ["first reason", "second reason"])
        self.assertEqual(receipt["tests"][1]["class"], "Tests\\Unit\\RootFixtures")

    def test_unmatched_or_ambiguous_skip_evidence_fails_closed(self):
        path = self.write_report('<testsuite name="suite"><testcase classname="A" name="guard"><skipped/></testcase></testsuite>')
        unmatched = self.write_otr('<events xmlns:e="urn:phpunit"><e:started id="1" className="A" methodName="other"/><e:finished id="1" result="SKIPPED"><e:reason>no match</e:reason></e:finished></events>')
        with self.assertRaises(module.ReceiptError):
            module.parse_reports([path], otr_paths=[unmatched], job="job", run="run")
        ambiguous = self.write_otr(
            '<events xmlns:e="urn:phpunit"><e:started id="1" className="A" methodName="guard"/>'
            '<e:finished id="1" result="SKIPPED"><e:reason>one</e:reason></e:finished>'
            '<e:started id="2" className="A" methodName="guard"/><e:finished id="2" result="SKIPPED">'
            '<e:reason>two</e:reason></e:finished></events>'
        )
        with self.assertRaises(module.ReceiptError):
            module.parse_reports([path], otr_paths=[ambiguous], job="job", run="run")


if __name__ == "__main__":
    unittest.main()
