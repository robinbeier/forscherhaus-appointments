# Zero-Surprise Release Gate (100% rollout)

Zero-Surprise now protects deploys end-to-end:

1. `deploy_ea.sh` runs a deterministic pre-deploy restore-dump replay (`mode=predeploy`) from the staged release tree.
2. `deploy_ea.sh` validates the generated pre-deploy report before `perform_atomic_switch`.
3. `deploy_ea.sh` runs a live post-deploy canary (`mode=postdeploy_canary`) after renderer and deep-health checks.
4. Canary failure triggers `rollback_after_failure "zero-surprise canary failed"`.
5. Rollback and breakglass usage emit non-blocking operational incident webhooks.

Both gates enforce the same invariants:

- `unexpected_5xx`
- `overbooking`
- `fill_rate_math`
- `pdf_exports`

`unexpected_5xx` has no allowlist. Any unexpected HTTP `5xx` is a hard failure.

## School-Day Digital Twin profile

Replay and canary default to the versioned profile `school-day-default`.

Profile defaults:

- `timezone = Europe/Berlin`
- `window.type = trailing_days`
- `window.days = 30`
- `booking_search_days = 14`
- `retry_count = 1`
- `max_pdf_duration_ms = 30000`

Resolution order is fixed:

1. explicit CLI override
2. explicit value in credentials INI
3. profile default

## Credentials INI

The same INI shape is used by the automated predeploy replay and the postdeploy canary.

Required keys:

- `base_url`
- `index_page` (use an empty value for rewrite-mode, e.g. `index_page =`)
- `username`
- `password`

Optional keys:

- `start_date`
- `end_date`
- `booking_search_days`
- `retry_count`
- `max_pdf_duration_ms`
- `timezone`
- `pdf_health_url`

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

## Replay CLI

Manual replay is still available, but date/tuning flags now default from the named profile.

```bash
php scripts/release-gate/zero_surprise_replay.php \
  --release-id=ea_YYYYMMDD_HHMM \
  --dump-file=/absolute/path/to/easyappointments_YYYY-MM-DD_HHMMSSZ.sql.gz \
  --credentials-file=/etc/fh/zero-surprise-predeploy.ini \
  --profile=school-day-default
```

Explicit overrides remain supported:

- `--base-url`
- `--index-page`
- `--username`
- `--password`
- `--start-date`
- `--end-date`
- `--booking-search-days`
- `--retry-count`
- `--max-pdf-duration-ms`
- `--timezone`

## Deploy flow

Pre-switch order in `deploy_ea.sh`:

1. unpack release archive and detect `STAGE_ROOT`
2. validate breakglass policy if any zero-surprise bypass is requested
3. run `zero_surprise_replay.php` from `STAGE_ROOT`
4. validate the generated predeploy report
5. continue with stage preparation and `perform_atomic_switch`

Post-switch order in `deploy_ea.sh`:

1. `perform_atomic_switch`
2. `restart_renderer_service`
3. `probe_renderer_health`
4. `probe_deep_health_contract`
5. `run_zero_surprise_live_canary`

Canary failure path is hard-wired to:

- `rollback_after_failure "zero-surprise canary failed"`

## Deploy options

Relevant `deploy_ea.sh` options:

- `--zero-surprise-dump-file PATH`
- `--zero-surprise-predeploy-credentials-file PATH`
- `--zero-surprise-report PATH` (optional output override for the generated predeploy report)
- `--zero-surprise-profile NAME` (default `school-day-default`)
- `--zero-surprise-max-age-minutes N` (default `240`)
- `--require-zero-surprise 0|1` (default `1`)
- `--zero-surprise-breakglass-file PATH`
- `--zero-surprise-canary-enabled 0|1` (default `1`)
- `--zero-surprise-canary-timeout N` (default `300`)
- `--zero-surprise-canary-credentials-file PATH`
- `--zero-surprise-incident-webhook-file PATH`
- `--zero-surprise-incident-timeout N` (default `10`)

## Breakglass policy

`--require-zero-surprise=0` and `--zero-surprise-canary-enabled=0` are only valid with a readable breakglass JSON.

Required JSON shape:

```json
{
  "release_id": "ea_20260320_1200",
  "ticket": "INC-1234",
  "reason": "temporary deploy bypass because ...",
  "approved_by": "name",
  "expires_at_utc": "2026-03-20T12:30:00Z",
  "allow_disable_predeploy": true,
  "allow_disable_canary": false
}
```

Validation rules:

- file exists, readable, valid JSON
- `release_id == --rel`
- `ticket`, `reason`, `approved_by` are non-empty
- `expires_at_utc` is valid and in the future
- the ack explicitly allows only the bypasses requested on the command line

Breakglass usage is logged and emits a warning incident webhook.

## Incident webhook config

Operational alerts are sent by `scripts/release-gate/zero_surprise_incident_notify.php`.

INI shape:

```ini
url = https://example.invalid/zero-surprise
authorization_header = Bearer secret
report_url_template = https://ops.example.invalid/release-gate/{relative_path}
timeout_seconds = 10
```

Supported placeholders in `report_url_template`:

- `{relative_path}`
- `{basename}`
- `{release_id}`

Payload fields:

- `event`
- `severity`
- `release_id`
- `reason`
- `rollback_result`
- `report_path`
- `report_url`
- `failed_invariants`
- `log_path`
- `breakglass_used`
- `ticket`
- `timestamp_utc`

Notifier failures are non-blocking. They never change deploy or rollback exit codes.

## Booking conflict contract

`POST /booking/register` returns `409 Conflict` for slot collisions.

Response shape stays:

```json
{"success": false, "message": "..."}
```

## Outputs

Predeploy reports:

- `storage/logs/release-gate/zero-surprise-<UTC>.json`
- deploy default override: `storage/logs/release-gate/zero-surprise-predeploy-<REL>-<UTC>.json`
- `storage/logs/release-gate/zero-surprise-booking-<UTC>.json`
- `storage/logs/release-gate/zero-surprise-dashboard-<UTC>.json`

Postdeploy canary reports:

- `storage/logs/release-gate/zero-surprise-live-canary-<UTC>.json`
- deploy default override: `storage/logs/release-gate/zero-surprise-live-canary-<REL>-<UTC>.json`
- `storage/logs/release-gate/zero-surprise-live-canary-booking-<UTC>.json`
- `storage/logs/release-gate/zero-surprise-live-canary-dashboard-<UTC>.json`

## Exit codes

Replay and live canary:

- `0`: all steps and invariants passed
- `1`: assertion or invariant failure
- `2`: runtime, config, or infrastructure failure

Deploy:

- `0`: deploy completed
- `30`: deploy failed, automatic rollback succeeded
- `31`: deploy failed, rollback failed or was not fully verifiable
