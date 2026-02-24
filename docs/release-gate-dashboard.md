# Dashboard Release Gate (MVP)

This release gate runs a strict end-to-end replay of the critical dashboard chain before deployment:

1. `GET /login`
2. `POST /login/validate`
3. `POST /dashboard/metrics`
4. `POST /dashboard/heatmap`
5. `GET /dashboard/export/principal.pdf`
6. `GET /dashboard/export/teacher.zip`
7. `GET /dashboard/export/teacher.pdf`

It is designed to catch runtime regressions that unit tests can miss, without changing production request handlers.

## Scope

- Read-mostly replay only.
- No booking create/reschedule/cancel mutations.
- Structural and invariant assertions (not fixed snapshot counts).

## Run

```bash
composer release:gate:dashboard -- \
  --base-url=http://localhost \
  --index-page=index.php \
  --username="$EA_GATE_USERNAME" \
  --password="$EA_GATE_PASSWORD" \
  --start-date=2026-02-01 \
  --end-date=2026-03-03 \
  --statuses=Booked \
  --pdf-health-url=http://localhost:3003/healthz
```

## Options

- `--base-url` (required): App base URL, e.g. `http://localhost`.
- `--index-page` (optional): URL index segment. Default `index.php`. Use empty value in rewrite mode.
- `--username` (required): Admin username.
- `--password` (required): Admin password.
- `--start-date` (required): `YYYY-MM-DD`.
- `--end-date` (required): `YYYY-MM-DD`.
- `--statuses` (optional): Comma-separated list, default `Booked`.
- `--service-id` (optional): Positive integer.
- `--provider-ids` (optional): Comma-separated positive integers.
- `--pdf-health-url` (optional): Renderer health endpoint.
- `--http-timeout` (optional): JSON-check timeout seconds, default `15`.
- `--export-timeout` (optional): Export timeout seconds, default `60`.
- `--require-nonempty-metrics` (optional flag/bool): Fail if metrics payload is empty.
- `--output-json` (optional): Report path. Default:
  - `storage/logs/release-gate/dashboard-gate-<UTC>.json`

## Exit Codes

- `0`: All checks passed.
- `1`: Assertion failure (behavioral regression or invalid output).
- `2`: Runtime/configuration error.

## Report Output

The gate always writes a JSON report containing:

- `meta`: start/end timestamps and total duration.
- `config`: non-secret run configuration.
- `checks`: pass/fail records per check with duration and details.
- `summary`: pass/fail counts and exit code.
- `failure`: present on failure with message + exception class.
