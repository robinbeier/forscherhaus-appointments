# Zero-Surprise Restore-Dump Replay (Shadow Gate)

This gate replays the critical release path against a restored database dump in an isolated Docker Compose stack.

Execution order is fixed:

1. Restore dump (`mysql` readiness -> import -> `php index.php console migrate`)
2. Booking write replay (`scripts/ci/booking_write_contract_smoke.php`)
3. Dashboard replay (`scripts/release-gate/dashboard_release_gate.php`)

The gate writes a consolidated report and child reports without touching the live deployment script.

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
