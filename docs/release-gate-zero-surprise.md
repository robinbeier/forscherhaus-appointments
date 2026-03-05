# Zero-Surprise Restore-Dump Replay (Shadow Gate)

This gate replays the critical release path against a restored database dump in an isolated Docker Compose stack.

Execution order is fixed:

1. Restore dump (`mysql` readiness -> import -> `php index.php console migrate`)
2. Booking write replay (`scripts/ci/booking_write_contract_smoke.php`)
3. Dashboard replay (`scripts/release-gate/dashboard_release_gate.php`)

The gate writes a consolidated report and child reports.

As of phase 2, `deploy_ea.sh` enforces a hard pre-deploy validation before atomic switch.

## Mandatory Manual Runbook Step (Pre-Deploy)

Run this command before every deployment. It is intentionally manual in phase 1.

```bash
composer release:gate:zero-surprise -- \
  --release-id=ea_YYYYMMDD_HHMM \
  --dump-file=/absolute/path/to/easyappointments_YYYY-MM-DD_HHMMSSZ.sql.gz \
  --base-url=http://nginx \
  --index-page=index.php \
  --username=administrator \
  --password=administrator \
  --start-date=2026-01-01 \
  --end-date=2026-01-31
```

## Deploy Hard-Fail Integration (Phase 2)

Before `perform_atomic_switch`, `deploy_ea.sh` validates the zero-surprise report.

Deployment is aborted when the report:

- is missing or unreadable
- is too old
- has mismatched `release_id` or `mode`
- has `summary.exit_code != 0`
- has missing/failed invariants

New deploy options:

- `--zero-surprise-report PATH`
- `--zero-surprise-max-age-minutes N` (default `240`)
- `--require-zero-surprise 0|1` (default `1`)

Example:

```bash
/root/deploy_ea.sh \
  --rel ea_20260312_1200 \
  --healthz-token-file /etc/fh/healthz.token \
  --zero-surprise-report /var/log/fh/zero-surprise-ea_20260312_1200.json \
  --zero-surprise-max-age-minutes 240 \
  --require-zero-surprise 1
```

## Required Options

- `--release-id`
- `--dump-file`
- `--base-url`
- `--index-page` (use `--index-page=` for rewrite mode)
- `--username`
- `--password`
- `--start-date` (`YYYY-MM-DD`)
- `--end-date` (`YYYY-MM-DD`)

## Optional Options

- `--booking-search-days` (default `14`)
- `--retry-count` (default `1`)
- `--max-pdf-duration-ms` (default `30000`)
- `--timezone` (default `Europe/Berlin`)
- `--output-json` (default `storage/logs/release-gate/zero-surprise-<UTC>.json`)

## Isolation Rules

The orchestrator always uses:

- `docker-compose.yml`
- `docker/compose.zero-surprise.yml`

and a unique compose project name (`-p`) with this pattern:

- `zs-<sanitized_release_id>-<UTCstamp>-<rand4>`

The override ensures:

- MySQL data uses a dedicated named volume (not `./docker/mysql`)
- no host port bindings for `mysql`, `nginx`, `pdf-renderer`

## Output

### Consolidated report

- `storage/logs/release-gate/zero-surprise-<UTC>.json`

The report includes:

- `meta.release_id`
- `meta.mode` (always `predeploy` for this gate)
- `summary.exit_code`
- `invariants.*.status`

### Child reports

- `storage/logs/release-gate/zero-surprise-booking-<UTC>.json`
- `storage/logs/release-gate/zero-surprise-dashboard-<UTC>.json`

### Invariants

The consolidated report contains these invariant results:

- `unexpected_5xx`
- `overbooking`
- `fill_rate_math`
- `pdf_exports`

## Exit Codes

- `0`: all steps and invariants passed
- `1`: assertion/invariant failure
- `2`: runtime/configuration/infrastructure failure
