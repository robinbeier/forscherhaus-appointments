# Monitoring Target Concept

Date: 2026-05-20

This concept is intentionally built around the existing stack: Uptime Kuma,
Sentry, app health endpoints, release gates, logs, and repo-owned ops scripts.
No new monitoring platform is recommended at this stage.

## Target Architecture

### Uptime Kuma

Use Kuma for outside-in and host-local operational signals:

- public availability;
- dependency health exposed through app health endpoints;
- Push freshness from host scripts;
- resource/service thresholds;
- backup/restore freshness;
- carefully bounded live synthetics.

Kuma should answer: "Does Robin need to act because the service or an
operational dependency is down or stale?"

Kuma should not be used as generic error tracking. A business conflict,
validation error, bot 404, or single expected warning should not look like app
downtime.

### Sentry

Use Sentry for unexpected application failures:

- uncaught or explicitly captured exceptions;
- caught exceptions on critical flows when the app can continue but the user
  action failed;
- release-correlated regressions;
- safe context around area, operation, route, role, and export type.

Sentry should answer: "Which app code path failed, under which release and
environment, and is this a regression?"

Sentry should not be used as an availability monitor. If the app cannot be
reached, Kuma owns the first signal.

### App Health Endpoints

Use health endpoints as contracts between app and monitor:

- `/health`: host/webserver-owned shallow `OK`; public, no secrets.
- `/index.php/healthz`: app-owned deep health; tokenized; returns structured
  JSON with DB, GD, storage, and PDF renderer checks.
- The health token is host/Kuma-local only. The repo should document that a
  secret header is required without storing the value.

### Logs

Use logs for diagnosis and narrow Push monitors:

- app log monitor watches new app error lines, after targeted noise filters;
- PHP-FPM and PDF renderer log monitors watch service-level error journals;
- `prod_logs_summary.sh` remains the safe first log tool for Codex;
- raw logs are used only when the summary is insufficient and must be redacted
  before sharing.

### Ops Scripts

Use scripts as agent-legible adapters:

- `prod_doctor.sh`: first read-only production snapshot.
- `prod_logs_summary.sh`: redacted log classification.
- `prod_validate_after_change.sh`: post-change gate.
- `kuma_push_*.sh`: host-to-Kuma Push signals with short OK/CRIT messages.

Every Push script should produce an operator-readable message that explains the
failed condition without revealing secrets.

### Release Gates

Use release gates for deploy safety, not continuous alerting:

- predeploy zero-surprise replay;
- renderer health;
- deep health;
- live canary;
- rollback on post-switch failure;
- optional incident webhook for breakglass/rollback events.

Kuma maintenance mode may wrap the deploy window. Kuma resumes only after the
postdeploy health and canary gates pass.

## Monitor Catalog

| Monitor | Purpose | Tool | Signal | Source | Frequency | Alarm condition | Owner/action | Noise risk | Secret/data note |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| App-Homepage | Prove public app route and TLS | Kuma HTTP | Availability | `https://dasforscherhaus-leg.de/` | 30s | non-2xx after retry | Run `prod_doctor.sh`, inspect Apache/PHP | Low | Public URL only |
| App - Health Shallow | Prove minimal host/webserver health | Kuma keyword | Availability | `/health` body `OK` | 30s | no `OK` / non-200 | Check Apache/vhost/static health route | Low | Public route; document host ownership |
| App - Health Deep | Prove app dependencies | Kuma JSON | Availability/Error | `/index.php/healthz` | 30s | `status != ok` or HTTP fail | Check DB/GD/storage/PDF subcheck, then logs | Medium | Requires `X-Health-Token`; token stays in Kuma/host |
| App - PDF Renderer | Show PDF dependency health independently | Kuma JSON | Availability | `checks.pdf_renderer.ok` from deep health | 30s | value not true | Check `fh-pdf-renderer`, container, renderer logs | Medium | Same health token boundary |
| Host - Services | Detect inactive critical units | Kuma Push | Availability | `kuma_push_host_services.sh` | 60s | any configured service inactive | Check unit status; do not restart before logs | Low | Env controls service list; no secrets in msg |
| Host - Resources | Detect disk/memory/load pressure | Kuma Push | Capacity | `kuma_push_host_resources.sh` | 60s | threshold breach | Identify path/process before cleanup | Medium | No process dumps in Push msg |
| Ops - Restore Verify Freshness | Detect stale restore verification | Kuma Push | Backup | `kuma_push_ops_jobs.sh` restore-verify marker age | 15m | marker missing, invalid, or > 1440m | Renew backup/restore verification | Medium | Marker basename and age only; no backup contents |
| Ops - Backup Creation Freshness | Prove backup creation job freshness | Kuma Push | Backup | `kuma_push_backup_creation.sh` backup-success marker age | 15m | marker missing, invalid, or > 1440m | Check backup creation job and storage | Medium | Marker basename and age only; no dump listing or backup contents |
| App - Log Errors | Detect new unclassified app errors | Kuma Push | Error | `kuma_push_app_logs.sh` | 60s plus 30s stagger | new non-ignored `ERROR - ` lines | Classify with `prod_logs_summary.sh`; route to Sentry/issue if real | High until ROB-382 | Push URL secret; ignore regex targeted only |
| App - php8.5-fpm Log Errors | Detect PHP-FPM service errors | Kuma Push | Error | `journalctl -u php8.5-fpm` | 60s | error count > threshold | Inspect PHP-FPM journal and app health | Medium | Live UI rename remains a Kuma gate if still stale |
| App - PDF Renderer Log Errors | Detect renderer service errors | Kuma Push | Error | `journalctl -u fh-pdf-renderer` | 60s | error count > threshold | Inspect renderer container/service | Medium | No PDF content in messages |
| App - Dashboard PDF Export | Prove live dashboard PDF path | Kuma Push script | Business/PDF | `kuma_push_pdf_export.sh` | 15m | gate rc != 0 or report missing | Check dashboard gate report, renderer, auth creds | Medium | Uses host-local gate credentials |
| App - Booking Confirmation PDF | No-go for live synthetic until privacy-safe target exists | Release gate/manual only | Business/PDF | Booking confirmation PDF gate | Manual/release only | PDF cannot be downloaded/validated in approved gate | Use release/restored-data gate; do not add Kuma monitor yet | Medium-high | No real family hashes or reusable bearer links |
| Security - Scanner Activity | Track scanner spikes separately | Kuma Push | Security | Apache access log patterns | 60s | scanner count above threshold | Observe or tune ingress; not app downtime | Medium | No raw IPs in Push msg |
| TLS/Certbot Freshness | Catch renewal/timer risk | Script or Kuma | Security/Availability | certbot cert/timer status | Daily | cert near expiry or timer missing | Run certbot validation; inspect Apache | Low | Public cert data only |
| Sentry Production Errors | Alert on unexpected app exceptions | Sentry alert | Error | Sentry project | Continuous | new/high-frequency prod issue | Triage by release, area, operation | Medium | No PII; redaction required |
| Sentry PDF/Export Regression | Escalate critical export/PDF failures | Sentry alert | Business/Error | Sentry tags `area`, `export_type` | Continuous | PDF/export issue in prod | Check renderer, gate reports, recent deploy | Low-medium | No HTML/customer data |
| Sentry Event Delivery Smoke | Prove Sentry still receives events | Manual or scheduled safe script | Error/Observability | Sentry test event in non-prod or controlled prod | Release or weekly | event missing | Fix DSN/env/project/alerting | Low | Use synthetic message only |
| Release Gate Result | Block bad deploys before alert storm | CI/deploy | Release | `deploy_ea.sh` and release reports | Per deploy | predeploy/canary failure | Stop deploy or rollback | Low | Reports avoid credentials |
| Codex Prod Doctor | Standard first incident snapshot | Script | Diagnostic | `prod_doctor.sh` | On incident | n/a | Summarize state before changes | Low | Redacted; no raw DB rows |

## Sentry Concept

### Capture In Sentry

Capture these classes:

- uncaught production exceptions from the PHP request path;
- caught exceptions on critical flows where user action fails:
  dashboard exports, PDF renderer, booking confirmation, backend calendar saves,
  login/recovery unexpected failures, sync, notifications, and webhooks;
- repeated unexpected 5xx responses from application code;
- release-correlated failures after deploy.

### Do Not Capture In Sentry

Do not capture:

- expected 409 booking/calendar conflicts;
- validation errors, CAPTCHA failures, invalid login, unauthorized requests;
- bot/scanner 404s and forward-proxy probes;
- health endpoint unauthorized responses;
- raw request bodies, DB rows, confirmation hashes, tokens, Push URLs,
  passwords, email addresses, or names.

### Tags And Context

Recommended tags:

- `environment`: `production`, `staging`, `testing`
- `release`: from `_RELEASE`
- `area`: `booking`, `calendar`, `dashboard_export`, `pdf_renderer`,
  `login`, `recovery`, `sync`, `notification`, `webhook`
- `operation`: stable operation name
- `controller`: controller class or route group
- `http_status`: only after classification
- `role`: role slug only when authenticated; no user id/name/email
- `export_type`: for PDF/dashboard exports
- `renderer_endpoint_kind`: `loopback`, `docker_dns`, `configured`, not full
  tokenized endpoint

Recommended extra context:

- `request_uri` only after query-string/token scrubbing;
- booking flow state such as `manage_mode`, not customer data;
- PDF duration/status and endpoint count, not HTML or payload;
- sync provider count or provider class, not provider names or tokens.

Current context changed by ROB-383:

- Booking confirmation capture no longer sends raw `appointment_hash`; it sends
  `appointment_hash_present` and a short non-reversible digest.
- PDF renderer capture sends endpoint categories, not concrete renderer URLs.
- `SentryBootstrap` scrubs explicit extras and installs a `before_send`
  scrubber for event extras/request data/user context.

### Redaction

Keep `SENTRY_SEND_DEFAULT_PII=false` in production. Add an explicit
`before_send` scrubber before expanding Sentry coverage. It should remove:

- emails, names, phone numbers, IPs if not required for security triage;
- query strings with token/key/password/secret/auth parameters;
- appointment hashes and recovery tokens;
- Push URLs and webhook authorization headers;
- DB connection details.

### Alerts

Recommended Sentry alerts:

- P1: any new prod issue with `area=pdf_renderer` or `area=dashboard_export`
  during a release window or with repeated events.
- P1: unexpected 5xx issue spike on booking/calendar/public routes.
- P2: one new unresolved prod issue in critical flows outside school hours.
- P3: low-frequency non-critical issue, triage next workday.

No Sentry alert for expected 4xx/business conflicts.

### Grouping

Default grouping should be exception class plus stack trace. For explicitly
captured caught exceptions, add stable tags and, if needed later, fingerprints
by:

- `area`
- `operation`
- exception class
- normalized root cause

Do not group by appointment hash, customer, provider, or full URL.

## Uptime Kuma Concept

### Keep

Keep these monitors:

- App homepage.
- Shallow health.
- Deep health.
- PDF renderer deep subcheck.
- Host services.
- Host resources.
- App log errors.
- PHP-FPM log errors.
- PDF renderer log errors.
- Dashboard PDF export.
- Scanner activity.

### Change

Change or clarify:

- Document deep-health/PDF JSON monitors as requiring a secret header. Do not
  put the token in repo templates.
- Keep the PHP-FPM log monitor display and script default aligned to
  `php8.5-fpm`; a stale live Kuma display name is a gated rename.
- Verify production `KUMA_HOST_SERVICES_LIST` includes `cron`, `fail2ban`, and
  `unattended-upgrades`, or explicitly document why they are doctor-only.
- Split or rename `Ops - Jobs Freshness` so it says restore verify freshness,
  not generic jobs.
- After ROB-382, make `App - Log Errors` ignore only proven scanner/proxy noise
  while still alerting on new real app errors.
- Update `prod_validate_after_change.sh` to classify known ignored app-log
  lines consistently with the app-log monitor.

### Add Only If Useful

Add:

- backup creation freshness if restore verify remains separate;
- TLS/certbot freshness if not already covered by Kuma certificate features or
  post-change checks;
- Sentry delivery smoke if production Sentry becomes operationally important.

Add only after the first ROB-382 cleanup:

- parent booking confirmation PDF synthetic, and only if a privacy-safe stable
  synthetic confirmation can be maintained.

### Remove

Remove none immediately. Reclassify `Security - Scanner Activity` as
observation/security context, not app availability.

### Push Monitor Secrets

Push URLs are secrets. Keep them only in host-local env files or Kuma DB state:

- `KUMA_PUSH_URL_HOST_SERVICES`
- `KUMA_PUSH_URL_HOST_RESOURCES`
- `KUMA_PUSH_URL_OPS_JOBS`
- `KUMA_PUSH_URL_APP_LOGS`
- `KUMA_PUSH_URL_PHP_FPM_LOGS`
- `KUMA_PUSH_URL_PDF_RENDERER_LOGS`
- `KUMA_PUSH_URL_PDF_EXPORT`
- `KUMA_PUSH_URL_SECURITY_SCANNER`

The repo should only contain variable names and scripts.

### Agent-Legible Output

Every script message should follow this shape:

- `OK <metric>=<value> <context>`
- `WARN <metric>=<value> <threshold/context>`
- `CRIT <metric>=<value> <actionable-short-context>`

Do not include raw IPs, request tokens, Push URLs, DB values, customer names, or
full stack traces in Push messages.

## Alert And Incident Model

### P1: Wake Or Immediate Action

Use P1 for:

- public app route unavailable;
- deep health failing for DB, storage, or PDF renderer;
- critical service inactive: Apache, PHP-FPM, MariaDB, Docker, PDF renderer;
- repeated unexpected 5xx on booking/calendar/dashboard;
- deploy canary failure or rollback failure;
- disk critically near full.

First action: `prod_doctor.sh`, then classify whether this is public
availability, dependency, capacity, or deploy regression.

### P2: Same Day

Use P2 for:

- dashboard PDF export synthetic failing while app route is healthy;
- new Sentry issue in a critical flow with low volume;
- restore verify marker stale but app otherwise healthy;
- cert expiry approaching warning window;
- PHP-FPM or PDF renderer journal errors without current outage.

First action: redacted logs plus relevant script/gate report.

### P3: Next Workday

Use P3 for:

- low-frequency Sentry issue outside critical flows;
- scanner activity above threshold but no app impact;
- non-blocking CI trend warnings;
- post-change validation warning where all live monitors remain green and root
  cause is already classified.

### Observe Only

Observe without alerting Robin for:

- isolated bot 404s;
- expected 401/403/404;
- expected business 409 conflicts;
- invalid-login attempts;
- one-off known scanner/proxy noise after ROB-382 classification exists.

## Standard Codex Alarm Workflow

1. Identify monitor class: public HTTP, deep health, Push freshness, app logs,
   host resources, synthetic, or Sentry.
2. Check whether primary availability is green: homepage, `/health`, deep
   health, Kuma latest status.
3. Run `bash scripts/ops/prod_doctor.sh`.
4. If logs matter, run `bash scripts/ops/prod_logs_summary.sh --since "60 min ago"`.
5. For a Push monitor, inspect the matching `kuma_push_*.sh` script and its
   expected env variable. Do not print the env file.
6. Decide route:
   - Kuma-only infra/dependency issue;
   - Sentry-only app exception/business flow issue;
   - both systems for real dependency/app failure;
   - observation/noise for known scanner/business cases.
7. Do not change production until the failure class is clear and the stop
   conditions are not triggered.
8. After any approved change, run `prod_validate_after_change.sh`.

Stop immediately if:

- a command would print `config.php`, `/etc/fh` contents, Push URLs, tokens, or
  DB rows;
- a change would delete backups, Kuma data, provider snapshots, or release
  archives;
- app health and rollback direction are both unclear;
- the observed server shape differs from the accepted Ubuntu 26.04 baseline;
- the fix requires broad Apache/app/deployment model changes.

## Implementation Roadmap

### 1. ROB-382 App-Log Noise Classification - Shipped

Marking: `Repo-only`, `Server`, `Kuma`

ROB-382 was completed separately by PR #280 before the long-horizon
implementation run. Treat it as shipped baseline:

- scanner/proxy app-log noise is classified in the repo-owned ops scripts;
- unexpected `ERROR - ` lines still remain actionable;
- `prod_validate_after_change.sh` and `prod_logs_summary.sh` use the same
  classification helper;
- no runtime rate-limit bypass is part of the shipped solution.

### 2. Sentry Hardening And Verification

Marking: `Repo-only`, `Sentry`, `Needs decision`

Create a follow-up from ROB-381:

- add explicit redaction/scrubbing before expanding capture;
- remove raw appointment hash from Sentry extras;
- add tags for area/operation/http status/release;
- add a safe Sentry test-event workflow;
- configure usable read-only Sentry audit access with `SENTRY_ORG` and
  `SENTRY_PROJECT`;
- verify production event ingestion without sending PII.

### 3. Deep Health And Kuma Secret Boundary Cleanup

Marking: `Repo-only`, `Kuma`

- Update `docs/uptime-kuma.md` and monitor template comments to mark
  health-token header requirements.
- Keep token values out of Git.
- Verify the live Kuma monitors still have the correct secret header configured.

### 4. Backup And Cron Freshness Split

Marking: `Repo-only`, `Server`, `Kuma`

- Rename or document `Ops - Jobs Freshness` as restore-verify freshness.
- Add backup creation freshness with a separate marker-based Push script; the
  restore verification marker does not prove backup creation by itself.
- Decide whether cron/fail2ban/unattended-upgrades belong in Host Services
  Push or remain doctor-only.

### 5. Runtime Name Drift Cleanup

Marking: `Repo-only`, `Kuma`, `Server`

- Repo desired state uses `App - php8.5-fpm Log Errors`.
- Ensure `KUMA_PHP_FPM_SERVICE_NAME=php8.5-fpm` is host-local.
- Rename an old live `php8.3-fpm` monitor display to `php8.5-fpm` only through
  an explicit Kuma gate.
- Keep old historical monitor data if possible; rename rather than recreate if
  Kuma supports it cleanly.

### 6. Optional Parent Confirmation PDF Synthetic

Marking: `Needs decision`, `Repo-only`, `Kuma`

Decision after ROB-387: do not run this as a live Kuma synthetic yet.

Reason:

- the existing gate requires a confirmation hash or full confirmation URL;
- that value is bearer-like access to a parent-facing confirmation page;
- there is no current durable synthetic appointment/hash that is clearly safe
  to reuse in production monitoring.

Current safe use:

- run the existing booking confirmation PDF gate during release/restore
  validation with a non-production or explicitly approved host-local hash;
- keep continuous monitoring on renderer health, dashboard PDF export, logs,
  and Sentry PDF/export regressions.

Only revisit as a separate gated issue if a privacy-safe synthetic target exists
and all bearer-like values remain host-local. The detailed decision is in
[Parent Booking Confirmation PDF Synthetic Decision](parent-confirmation-pdf-synthetic-decision.md).

### 7. ROB-367 Observation Tie-In

Marking: `Server`, `Kuma`, `Repo-only`

When ROB-367 runs, use the monitoring concepts here as the observation checklist:

- Kuma stayed green or exceptions are classified;
- Push monitors received fresh signals;
- service logs are quiet or findings have issues;
- backup/restore freshness is clear;
- lessons are recorded in the relevant ops or long-horizon doc.

## Linear Follow-Up Proposal

Recommended order:

1. ROB-382: Uptime-Kuma App-Log-Monitor gegen Scanner-/Proxy-Noise haerten.
   Done by PR #280; keep as shipped baseline, do not broaden.
   Labels: `Repo-only`, `Server`, `Kuma`.
2. ROB-383: Sentry redaction and event-context hardening. Start here for the
   next repo-only implementation PR.
   Labels: `Repo-only`, `Sentry`, `Needs decision`.
3. ROB-384: Kuma deep-health secret-boundary documentation and live header audit.
   Labels: `Repo-only`, `Kuma`.
4. ROB-385: Backup freshness vs restore-verify freshness split.
   Labels: `Repo-only`, `Server`, `Kuma`.
5. ROB-386: Production monitor runtime-name drift cleanup (`php8.3` to `php8.5`).
   Labels: `Repo-only`, `Kuma`, `Server`.
6. ROB-387: decide privacy-safe parent confirmation PDF live synthetic.
   Labels: `Needs decision`, `Repo-only`, `Kuma`.
7. ROB-367: include monitoring soak and lessons learned.
   Labels: `Server`, `Kuma`, `Repo-only`.
8. ROB-388: run the full long-horizon implementation from
   `docs/long-horizon/ROB-381/`.
   Labels: `Repo-only first`, `Server gate`, `Kuma gate`, `Sentry gate`.

## Short Summary

Urgent:

- Start ROB-383 so Sentry redaction/context is safe before relying on alerts.
- Keep Sentry live verification gated until a secure token/connector path is
  available.
- Keep post-change validation classification aligned with the shipped ROB-382
  helper.

Useful but not urgent:

- Document the deep-health token boundary in Kuma docs/templates.
- Split backup creation freshness from restore verification.
- Keep PHP-FPM monitor display names aligned to `php8.5-fpm`.
- Add explicit Sentry redaction and safer context.

Do not build now:

- Prometheus/Grafana/Loki or another monitoring stack.
- Live write-path booking synthetics against real production data.
- Broad "ignore all 404/warning" filters.
- Sentry as an availability substitute.
- Kuma as generic exception tracking.
