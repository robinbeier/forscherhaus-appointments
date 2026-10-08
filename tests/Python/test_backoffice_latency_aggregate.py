import importlib.util
import json
from datetime import datetime, timezone
from pathlib import Path
import subprocess
import sys
import unittest


MODULE_PATH = Path(__file__).parents[2] / "scripts/ops/backoffice_latency_aggregate.py"
spec = importlib.util.spec_from_file_location("backoffice_latency_aggregate", MODULE_PATH)
module = importlib.util.module_from_spec(spec)
assert spec.loader is not None
spec.loader.exec_module(module)


START = datetime(2026, 10, 8, 10, 0, tzinfo=timezone.utc)
END = datetime(2026, 10, 8, 11, 0, tzinfo=timezone.utc)


def line(path="/login", method="GET", status=200, duration="1234", stamp="08/Oct/2026:10:30:00 +0000"):
    suffix = "" if duration is None else f" {duration}"
    return f'203.0.113.7 - actor [{stamp}] "{method} {path}?secret=query HTTP/1.1" {status} 42 "https://ref.example/secret" "secret-agent"{suffix}\n'


class BackofficeLatencyAggregateTest(unittest.TestCase):
    def test_routes_status_methods_and_fixed_buckets(self):
        result = module.aggregate(
            [line("/login", "GET", 200, "99999"), line("/dashboard/metrics", "POST", 302, "100000"), line("/calendar/index", "PATCH", 503, "3000000")],
            START,
            END,
        )
        self.assertEqual(result["valid_measurements"], 3)
        self.assertEqual(result["operations"]["login_page"]["duration_buckets"]["0-99999us"], 1)
        self.assertEqual(result["operations"]["dashboard_data"]["duration_buckets"]["100000-249999us"], 1)
        self.assertEqual(result["operations"]["calendar_page"]["duration_buckets"]["3000000us+"], 1)
        self.assertEqual(result["operations"]["dashboard_data"]["method_status"]["POST"]["3xx"], 1)
        self.assertEqual(result["operations"]["calendar_page"]["method_status"]["OTHER"]["5xx"], 1)
        self.assertEqual(result["operations"]["calendar_page"]["result_class"], "insufficient_samples")

    def test_fixed_route_families_accept_aliases_and_count_parseable_lines(self):
        result = module.aggregate(
            [
                line("/index.php/login/validate", "POST", 401, "1000000"),
                line("/dashboard/metrics", "POST", 200, "250000"),
                line("/index.php/backend_api/ajax_get_calendar_events", "GET", 302, "500000"),
                line("/backend_api/ajax_save_unavailability", "POST", 302, "500000"),
                line("/calendar/save_unavailability", "POST", 204, "3000000"),
                line("/unknown", "GET", 404, "100000"),
            ],
            START,
            END,
        )
        self.assertEqual(result["lines_in_window"], 6)
        self.assertEqual(result["valid_measurements"], 5)
        self.assertEqual(result["excluded_routes"], 1)
        self.assertEqual(result["operations"]["login_validation"]["valid_measurements"], 1)
        self.assertEqual(result["operations"]["legacy_alias_redirect"]["valid_measurements"], 2)
        self.assertEqual(result["operations"]["dashboard_data"]["method_status"]["POST"]["2xx"], 1)
        self.assertEqual(result["operations"]["legacy_alias_redirect"]["method_status"]["GET"]["3xx"], 1)
        self.assertEqual(result["operations"]["calendar_unavailability_save"]["method_status"]["POST"]["2xx"], 1)

    def test_same_family_operations_keep_latency_buckets_separate(self):
        result = module.aggregate([line("/dashboard", duration="100000"), line("/dashboard/metrics", duration="3000000")], START, END)
        self.assertEqual(result["operations"]["dashboard_page"]["duration_buckets"]["100000-249999us"], 1)
        self.assertEqual(result["operations"]["dashboard_data"]["duration_buckets"]["3000000us+"], 1)
        self.assertEqual(result["operations"]["dashboard_page"]["duration_buckets"]["3000000us+"], 0)

    def test_calendar_link_routes_are_classified_without_exposing_tokens(self):
        token = "private-appointment-capability"
        for path in (f"/calendar/index/{token}", f"/calendar/reschedule/{token}", f"/index.php/calendar/reschedule/{token}"):
            reason, event = module.parse_line(line(path))
            self.assertEqual(reason, "valid")
            self.assertEqual(event["operation"], "calendar_page")
            self.assertNotIn(token, repr(event))
        self.assertEqual(module.parse_line(line(f"/calendar/reschedule/{token}/extra"))[0], "excluded_route")

    def test_missing_invalid_malformed_and_unknown_routes_are_separate(self):
        result = module.aggregate([line(duration=None), line(duration="oops"), line("/unknown", duration=None), line("/unknown", duration="oops"), "not an access line\n", line("/customers", duration="100")], START, END)
        self.assertEqual(result["missing_duration"], 1)
        self.assertEqual(result["invalid_duration"], 1)
        self.assertNotIn("malformed_lines", result)
        self.assertEqual(result["excluded_routes"], 3)
        self.assertEqual(result["lines_in_window"], 5)
        self.assertEqual(result["valid_measurements"], 0)
        self.assertEqual(result["result_class"], "incomplete_format")

    def test_window_is_explicit_and_low_sample_is_reported(self):
        result = module.aggregate([line(stamp="08/Oct/2026:09:59:59 +0000"), line(stamp="08/Oct/2026:11:00:00 +0000"), line(duration=None, stamp="08/Oct/2026:09:59:59 +0000")], START, END)
        self.assertEqual(result["lines_in_window"], 0)
        self.assertEqual(result["missing_duration"], 0)
        self.assertTrue(result["low_sample"])
        self.assertEqual(result["result_class"], "no_measurement")

    def test_cli_output_contains_aggregates_only_and_no_sensitive_log_fields(self):
        completed = subprocess.run([sys.executable, str(MODULE_PATH), "--start", "2026-10-08T10:00:00Z", "--end", "2026-10-08T11:00:00Z"], input=line().encode(), stdout=subprocess.PIPE, check=True)
        output = completed.stdout.decode()
        payload = json.loads(output)
        self.assertEqual(payload, {"window_start": "2026-10-08T10:00:00Z", "window_end": "2026-10-08T11:00:00Z", "result_class": "privacy_suppressed"})
        for secret in ("203.0.113.7", "/login?secret=query", "ref.example", "secret-agent", "actor"):
            self.assertNotIn(secret, output)

    def test_cli_suppresses_singleton_cell_even_with_twenty_requests(self):
        def run(lines):
            completed = subprocess.run(
                [sys.executable, str(MODULE_PATH), "--start", "2026-10-08T10:00:00Z", "--end", "2026-10-08T11:00:00Z"],
                input="".join(lines).encode(), stdout=subprocess.PIPE, check=True,
            )
            return json.loads(completed.stdout)

        measured = run([line()] * 20)
        self.assertEqual(measured["result_class"], "measured")
        self.assertEqual(measured["operations"]["login_page"]["valid_measurements"], 20)
        self.assertNotIn("lines_seen", measured)
        self.assertNotIn("malformed_lines", measured)
        self.assertEqual(run([line()] * 20 + [line(stamp="08/Oct/2026:09:59:59 +0000")]), measured)
        self.assertEqual(run([line()] * 20 + ["not an access line\n"]), measured)
        self.assertNotIn("secret", json.dumps(measured))
        sparse_error = run([line()] * 19 + [line(status=500)])
        self.assertEqual(set(sparse_error), {"window_start", "window_end", "result_class"})
        self.assertEqual(sparse_error["result_class"], "privacy_suppressed")


if __name__ == "__main__":
    unittest.main()
