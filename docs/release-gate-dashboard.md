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

## Related Checks

- Parent confirmation PDF (read-only synthetic, Playwright):
  - `composer release:gate:booking-confirmation-pdf -- --help`
  - See `docs/release-gate-booking-confirmation-pdf.md` for usage and options.

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
- `--max-pdf-duration-ms` (optional): Maximum allowed duration per PDF export request, default `30000`.
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

## CI Integration Smoke (Quick Win #4)

The CI pipeline also runs a deterministic integration smoke test for four deterministic app/runtime chains:

1. `GET /login` + `POST /login/validate` + `POST /dashboard/metrics` + authenticated browser render of the dashboard summary card
2. `GET /booking` + `POST /booking/get_available_hours` + `POST /booking/get_unavailable_dates`
3. `GET /api/v1/appointments` (401 without auth), then authenticated `GET /api/v1/appointments` + `GET /api/v1/availabilities`
4. `POST /ldap_settings/search` (hit + miss) plus LDAP-backed `POST /login/validate` (success + wrong password)

Purpose:

- Catch runtime wiring regressions (session/csrf/auth/routing/db) that unit tests can miss.
- Keep checks deterministic and low-risk close to release.
- Keep scope read-only (no booking create/reschedule/cancel mutations).
- Avoid external dependencies such as PDF renderer.

Local repro command:

```bash
docker compose up -d mysql php-fpm nginx openldap
until docker compose exec -T mysql mysqladmin ping -h localhost -uroot -psecret --silent; do sleep 2; done
until docker compose exec -T mysql mysql -uuser -ppassword -e "USE easyappointments; SELECT 1;" >/dev/null 2>&1; do sleep 2; done
for attempt in 1 2 3; do docker compose exec -T php-fpm php index.php console install && break; [ "$attempt" -eq 3 ] && exit 1; sleep 3; done
docker compose exec -T php-fpm php scripts/ci/dashboard_integration_smoke.php \
  --base-url=http://nginx --index-page=index.php \
  --username=administrator --password=administrator \
  --start-date=2026-01-01 --end-date=2026-01-31
```

Smoke exit codes:

- `0`: All smoke checks passed.
- `1`: Assertion failure (behavioral regression).
- `2`: Runtime/configuration/infrastructure error.

## Agent-Friendly Browser Evidence

`dashboard_integration_smoke.php` supports a narrow Playwright-backed evidence
path for the public booking page:

- `--browser-evidence=off|on-failure|always`
- `--browser-evidence-dir=/path/to/artifacts`
- `--browser-pwcli-path=scripts/release-gate/playwright/playwright_cli.sh`
- `--browser-bootstrap-timeout=90`
- `--browser-open-timeout=20`
- `--browser-headed`

Recommended CI/deep-runtime mode is `--browser-evidence=on-failure`. On an
integration-smoke failure the script attempts to collect:

- `summary.json` with step-by-step outcome and artifact references
- `page.png` or `failure.png`
- `snapshot.txt`
- `trace.trace`
- `network.log`

The deep-runtime suite stores these artifacts under:

- `storage/logs/ci/deep-runtime-suite/integration-smoke-browser/`

This keeps the runtime/UI evidence path reproducible and narrow while giving
agents visual and trace artifacts for CI triage and rework.

The bundled Playwright wrapper bootstraps the configured Playwright browser
(Firefox by default, overridable via `PLAYWRIGHT_MCP_BROWSER`) via an explicit
`playwright` package install step and prepares the required Linux browser
dependencies inside the validation container. This keeps the dashboard browser
checks portable across Linux architectures, including local arm64 runs.

The dashboard summary browser check emits its `run-code` payload with the
repo-owned `__DASHBOARD_SUMMARY_BROWSER_CHECK__` prefix. The parser reads that
marker directly instead of relying on undocumented stdout framing from the
upstream Playwright CLI. The wrapper pins `PLAYWRIGHT_MCP_OUTPUT_MODE=stdout`
for these gate runs so the repo keeps reading the sentinel from stdout in the
current Playwright CLI behavior, even if the host environment configures
Playwright CLI output differently.
