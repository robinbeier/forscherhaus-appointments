# Zero-Surprise Release Gate (Pre-Deploy Replay + Post-Deploy Canary)

Zero-Surprise protects deployments with two gates:

1. Pre-deploy restore-dump replay (`mode=predeploy`)
2. Post-deploy live canary (`mode=postdeploy_canary`)

Both gates produce JSON reports with shared invariants:

- `unexpected_5xx`
- `overbooking`
- `fill_rate_math`
- `pdf_exports`

`unexpected_5xx` has no allowlist in phase 3. Every unexpected HTTP 5xx is an invariant failure.

## Pre-Deploy Restore-Dump Replay

Execution order is fixed:

1. Restore dump (`mysql` readiness -> import -> `php index.php console migrate`)
2. Booking write replay (`scripts/ci/booking_write_contract_smoke.php`)
3. Dashboard replay (`scripts/release-gate/dashboard_release_gate.php`)

Run before every deploy:

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

Before `perform_atomic_switch`, `deploy_ea.sh` validates the pre-deploy report.

Deploy options:

- `--zero-surprise-report PATH`
- `--zero-surprise-max-age-minutes N` (default `240`)
- `--require-zero-surprise 0|1` (default `1`)

Validation fails when report is missing/stale/invalid or any required invariant is not `pass`.

## Post-Deploy Live Canary Integration (Phase 3)

After switch and post-switch health checks, deploy runs a live canary.

Order in `deploy_ea.sh`:

1. `perform_atomic_switch`
2. `restart_renderer_service`
3. `probe_renderer_health`
4. `probe_deep_health_contract`
5. `run_zero_surprise_live_canary`

Canary failure path is hard-wired to:

- `rollback_after_failure "zero-surprise canary failed"`

Deploy options:

- `--zero-surprise-canary-enabled 0|1` (default `1`)
- `--zero-surprise-canary-timeout N` (seconds, default `300`)
- `--zero-surprise-canary-credentials-file PATH` (required when canary enabled)

### Canary credentials file format (INI)

Required keys:

- `base_url`
- `index_page` (use an empty value for rewrite-mode, e.g. `index_page =`)
- `username`
- `password`

Optional keys:

- `start_date` (default: today minus 30 days in configured timezone)
- `end_date` (default: today in configured timezone)
- `booking_search_days` (default `14`)
- `retry_count` (default `1`)
- `max_pdf_duration_ms` (default `30000`)
- `timezone` (default `Europe/Berlin`)
- `pdf_health_url` (optional)

Example:

```ini
base_url = http://localhost
index_page = index.php
username = administrator
password = administrator
start_date = 2026-01-01
end_date = 2026-01-31
booking_search_days = 14
retry_count = 1
max_pdf_duration_ms = 30000
timezone = Europe/Berlin
pdf_health_url = http://127.0.0.1:3003/healthz
```

Canary CLI:

```bash
php scripts/release-gate/zero_surprise_live_canary.php \
  --release-id=ea_YYYYMMDD_HHMM \
  --credentials-file=/etc/fh/zero-surprise-canary.ini \
  --timeout-seconds=300
```

## Booking conflict contract (Phase 3)

`POST /booking/register` now returns `409 Conflict` for slot collisions.

Response shape stays:

```json
{"success": false, "message": "..."}
```

This removes the known 500 conflict-path exception and keeps `5xx => fail` strict.

## Outputs

Pre-deploy reports:

- `storage/logs/release-gate/zero-surprise-<UTC>.json`
- `storage/logs/release-gate/zero-surprise-booking-<UTC>.json`
- `storage/logs/release-gate/zero-surprise-dashboard-<UTC>.json`

Post-deploy canary reports:

- `storage/logs/release-gate/zero-surprise-live-canary-<UTC>.json`
- `storage/logs/release-gate/zero-surprise-live-canary-booking-<UTC>.json`
- `storage/logs/release-gate/zero-surprise-live-canary-dashboard-<UTC>.json`

## Exit codes

For replay and live canary:

- `0`: all steps and invariants passed
- `1`: assertion/invariant failure
- `2`: runtime/configuration/infrastructure failure
