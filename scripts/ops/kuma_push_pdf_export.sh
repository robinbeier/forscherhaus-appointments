#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/ops/lib/kuma_push_common.sh
source "$SCRIPT_DIR/lib/kuma_push_common.sh"

kuma_push_load_env_file

REPO_ROOT="${KUMA_PDF_EXPORT_REPO_ROOT:-$(cd "$SCRIPT_DIR/../.." && pwd)}"
GATE_OUTPUT_DIR="${KUMA_PDF_EXPORT_OUTPUT_DIR:-${REPO_ROOT}/storage/logs/ops}"
GATE_OUTPUT_FILE="${GATE_OUTPUT_DIR}/kuma-pdf-export-latest.json"
WINDOW_DAYS="${KUMA_PDF_EXPORT_WINDOW_DAYS:-30}"
BASE_URL="${KUMA_PDF_EXPORT_BASE_URL:-http://localhost}"
INDEX_PAGE="${KUMA_PDF_EXPORT_INDEX_PAGE:-index.php}"
PDF_HEALTH_URL="${KUMA_PDF_EXPORT_PDF_HEALTH_URL:-http://127.0.0.1:3003/healthz}"
HTTP_TIMEOUT="${KUMA_PDF_EXPORT_HTTP_TIMEOUT:-15}"
EXPORT_TIMEOUT="${KUMA_PDF_EXPORT_EXPORT_TIMEOUT:-60}"
MAX_PDF_DURATION_MS="${KUMA_PDF_EXPORT_MAX_PDF_DURATION_MS:-30000}"
REQUIRE_NONEMPTY_METRICS="${KUMA_PDF_EXPORT_REQUIRE_NONEMPTY_METRICS:-0}"
CREDENTIALS_FILE="${KUMA_PDF_EXPORT_CREDENTIALS_FILE:-/etc/fh/release-gate-admin.env}"

kuma_push_source_if_exists "$CREDENTIALS_FILE"
kuma_push_require_env KUMA_PUSH_URL_PDF_EXPORT

USERNAME="${KUMA_PDF_EXPORT_USERNAME:-${USERNAME:-}}"
PASSWORD="${KUMA_PDF_EXPORT_PASSWORD:-${PASSWORD:-}}"
[[ -n "$USERNAME" ]] || kuma_push_die "Missing KUMA_PDF_EXPORT_USERNAME or USERNAME"
[[ -n "$PASSWORD" ]] || kuma_push_die "Missing KUMA_PDF_EXPORT_PASSWORD or PASSWORD"

mkdir -p "$GATE_OUTPUT_DIR"

start_date="$(kuma_push_date_days_ago "$WINDOW_DAYS")"
end_date="$(date -u +%F)"
stdout_file="$(mktemp)"
stderr_file="$(mktemp)"

cleanup() {
  rm -f "$stdout_file" "$stderr_file"
}
trap cleanup EXIT

set +e
php "$REPO_ROOT/scripts/release-gate/dashboard_release_gate.php" \
  --base-url="$BASE_URL" \
  --index-page="$INDEX_PAGE" \
  --username="$USERNAME" \
  --password="$PASSWORD" \
  --start-date="$start_date" \
  --end-date="$end_date" \
  --pdf-health-url="$PDF_HEALTH_URL" \
  --http-timeout="$HTTP_TIMEOUT" \
  --export-timeout="$EXPORT_TIMEOUT" \
  --max-pdf-duration-ms="$MAX_PDF_DURATION_MS" \
  --require-nonempty-metrics="$REQUIRE_NONEMPTY_METRICS" \
  --output-json="$GATE_OUTPUT_FILE" \
  >"$stdout_file" 2>"$stderr_file"
gate_rc=$?
set -e

if [[ ! -f "$GATE_OUTPUT_FILE" ]]; then
  msg="CRIT dashboard gate did not write report"
  kuma_push_send "$KUMA_PUSH_URL_PDF_EXPORT" "down" "$msg" "0"
  kuma_push_log "$msg"
  exit 0
fi

summary="$(
  php -r '
    $report = json_decode((string) file_get_contents($argv[1]), true);
    $failed = [];
    foreach (($report["checks"] ?? []) as $check) {
        if (($check["status"] ?? null) === "fail") {
            $failed[] = $check["name"] ?? "unknown";
        }
    }
    $message = count($failed) > 0
        ? implode(",", $failed)
        : "all_checks_passed";
    echo $message;
  ' "$GATE_OUTPUT_FILE"
)"

if (( gate_rc == 0 )); then
  msg="OK dashboard_pdf_gate=${summary} window=${start_date}..${end_date}"
  kuma_push_send "$KUMA_PUSH_URL_PDF_EXPORT" "up" "$msg" "1"
else
  stderr_tail="$(tail -n 1 "$stderr_file" 2>/dev/null || true)"
  stderr_tail="$(kuma_push_trim "$stderr_tail" 180)"
  msg="CRIT dashboard_pdf_gate=${summary} rc=${gate_rc} detail=${stderr_tail}"
  kuma_push_send "$KUMA_PUSH_URL_PDF_EXPORT" "down" "$msg" "0"
fi

kuma_push_log "$msg"
