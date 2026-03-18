# Booking Confirmation PDF Gate (Read-only Synthetic)

This release gate validates the parent-facing PDF export on the booking confirmation page by driving a real browser via Playwright CLI:

1. Open `booking_confirmation/of/<hash>` (or an explicit confirmation URL)
2. Click the `Save PDF` button (`[data-generate-pdf]`)
3. Assert that a PDF file is downloaded and structurally valid

It is intentionally read-only: no booking create/reschedule/cancel endpoints are called.

## Run

```bash
composer release:gate:booking-confirmation-pdf -- \
  --base-url=http://localhost \
  --index-page=index.php \
  --confirmation-hash=REPLACE_WITH_APPOINTMENT_HASH
```

Alternative with explicit URL:

```bash
composer release:gate:booking-confirmation-pdf -- \
  --base-url=http://localhost \
  --confirmation-url=http://localhost/index.php/booking_confirmation/of/REPLACE_WITH_APPOINTMENT_HASH
```

## Options

- `--base-url` (required): App base URL, e.g. `http://localhost`.
- `--confirmation-hash` (required unless `--confirmation-url`): Existing appointment hash for `booking_confirmation/of/<hash>`.
- `--confirmation-url` (required unless `--confirmation-hash`): Absolute confirmation URL.
- `--index-page` (optional): URL index segment. Default `index.php`. Use empty value in rewrite mode.
- `--pwcli-path` (optional): Path to Playwright wrapper script. Default:
  - `scripts/release-gate/playwright/playwright_cli.sh`
- `--bootstrap-timeout` (optional): Timeout (seconds) for initial Playwright CLI warmup (`pwcli --help`). Default `90`.
- `--open-timeout` (optional): Timeout (seconds) for open/snapshot/screenshots. Default `20`.
- `--download-timeout` (optional): Timeout (seconds) for PDF click/download. Default `20`.
- `--min-pdf-bytes` (optional): Minimum PDF size in bytes. Default `1024`.
- `--artifacts-dir` (optional): Output folder for screenshots/network/PDF copy. Default:
  - `output/playwright/booking-confirmation-pdf/<UTC>`
- `--headed` (optional flag/bool): Run browser headed for debugging.
- `--output-json` (optional): Report path. Default:
  - `storage/logs/release-gate/booking-confirmation-pdf-<UTC>.json`

## Assertions

- Playwright dependencies are available (`pwcli`, `npx`).
- Playwright CLI startup is warmed up before flow checks (avoids first-run `npx` bootstrap timing out the page-open step).
- Confirmation page opens and can be snapshotted.
- PDF button click triggers a download event.
- Downloaded file exists, starts with `%PDF-`, and is at least `--min-pdf-bytes`.
- No JavaScript runtime errors are captured during PDF generation (`pageerror` / `console.error`).

## Exit Codes

- `0`: All checks passed.
- `1`: Assertion failure (flow/output regression).
- `2`: Runtime/configuration error.

## Report Output

The gate writes a JSON report with:

- `meta`: timestamps and duration.
- `config`: non-secret runtime options (includes only `confirmation_source`, never raw confirmation hash/URL).
- `checks`: pass/fail records with timing and details.
- `summary`: pass/fail counts and exit code.
- `failure`: present on failure with exception details.
- `cleanup_warnings`: optional Playwright session cleanup warnings.

The bundled Playwright wrapper auto-installs the configured Playwright browser
(Firefox by default, overridable via `PLAYWRIGHT_MCP_BROWSER`) and prepares the
required Linux browser dependencies inside the validation container. Local and
CI gate runs therefore use the same browser path across Linux architectures.

The `run-code` snippet used by this gate emits its JSON result with the
repo-owned `__BOOKING_CONFIRMATION_PDF_GATE__` prefix. The parser reads that
marker directly instead of depending on undocumented stdout framing from the
upstream Playwright CLI. The wrapper pins `PLAYWRIGHT_MCP_OUTPUT_MODE=stdout`
for these gate runs so the sentinel payload stays on stdout even if the host
environment configures Playwright CLI output differently.
