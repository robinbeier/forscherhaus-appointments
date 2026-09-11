import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest


MODULE_PATH = Path(__file__).parents[2] / "scripts/ci/gate_log_summary.py"
spec = importlib.util.spec_from_file_location("gate_log_summary", MODULE_PATH)
module = importlib.util.module_from_spec(spec)
assert spec.loader is not None
spec.loader.exec_module(module)


def block(message="old warning", warning_class="import"):
    return (
        f"DEPRECATION WARNING [{warning_class}]: {message}.\n\n"
        "More info: https://sass-lang.com/d/import\n\n"
        "   ╷\n"
        "1 │ @import \"x\";\n"
        "  │         ^\n"
        "  ╵\n"
        "    source.scss 1:1  root stylesheet\n\n"
    )


def baseline_for(*blocks):
    return {
        "version": 1,
        "phpunit_aggregate_counts": {},
        "source_commit": "350ca506e0aeaa0218317809191d3b608f03b2b3",
        "blocks": [
            {"class": "import", "sha256": hashlib.sha256(item.encode()).hexdigest()}
            for item in blocks
        ],
    }


class GateLogSummaryTest(unittest.TestCase):
    def test_exact_blocks_are_separated_but_other_text_is_lossless(self):
        known = block()
        result = module.analyze("before\n" + known + "after\n", baseline_for(known))
        self.assertEqual(result["visible_text"], "before\nafter\n")
        self.assertEqual(result["known_text"], known)
        self.assertEqual(result["known_warning_counts"], {"import": 1})

    def test_changed_nested_warning_and_incomplete_block_stay_visible(self):
        known = block()
        changed = block("new warning")
        changed = changed.replace("source.scss 1:1", "source.scss 2:1")
        incomplete = known[: known.index("root stylesheet")]
        result = module.analyze(changed + incomplete, baseline_for(known))
        self.assertEqual(result["visible_text"], changed + incomplete)
        self.assertEqual(result["known_text"], "")
        self.assertTrue(result["new_warning_lines"])

    def test_duplicate_blocks_are_counted_and_unobserved_metrics_are_not_zero(self):
        known = block()
        result = module.analyze(
            known + known + "Tests: 3, Assertions: 4, PHPUnit Deprecations: 1, PHPUnit Notices: 2, Skipped: 1.\n"
            "OK (2 tests, 3 assertions)\n",
            baseline_for(known),
        )
        self.assertEqual(result["known_warning_counts"]["import"], 2)
        self.assertEqual(result["tests"], [
            {"tests": 3, "assertions": 4, "skipped": 1, "deprecations": 1, "notices": 2},
            {"tests": 2, "assertions": 3, "skipped": None, "deprecations": None, "notices": None},
        ])
        self.assertIn("Tests: 3, Assertions: 4", result["known_text"])
        self.assertEqual(result["known_warning_counts"]["phpunit_deprecations"], 1)

    def test_phpunit_aggregate_metrics_are_optional_and_order_independent(self):
        result = module.analyze(
            "Tests: 404, Assertions: 2454, PHPUnit Deprecations: 8, PHPUnit Notices: 29.\n"
            "Tests: 3, Assertions: 7, Skipped: 1.\n",
            baseline_for(),
        )
        self.assertEqual(result["tests"], [
            {"tests": 404, "assertions": 2454, "skipped": None, "deprecations": 8, "notices": 29},
            {"tests": 3, "assertions": 7, "skipped": 1, "deprecations": None, "notices": None},
        ])
        self.assertEqual(result["known_warning_counts"]["phpunit_deprecations"], 8)
        self.assertEqual(result["known_warning_counts"]["phpunit_notices"], 29)
        self.assertEqual(result["known_warning_counts"]["phpunit_skipped"], 1)

    def test_signals_and_warning_delta_are_explicit(self):
        result = module.analyze(
            "[PASS] coverage-delta current=1\n[FAIL] deep-runtime-suite sample\nWARNING: unknown\n",
            baseline_for(),
        )
        self.assertEqual(result["signals"], [
            {"category": "coverage", "status": "PASS", "line": "[PASS] coverage-delta current=1"},
            {"category": "deep-runtime", "status": "FAIL", "line": "[FAIL] deep-runtime-suite sample"},
        ])
        self.assertEqual(result["new_warning_lines"], ["WARNING: unknown"])
        self.assertEqual(result["warning_delta"]["total_delta"], 0)

    def test_load_baseline_rejects_schema_drift(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "baseline.json"
            path.write_text(json.dumps({"version": 1, "source_commit": "x", "blocks": [], "extra": 1}), encoding="utf-8")
            with self.assertRaises(ValueError):
                module.load_baseline(path)
