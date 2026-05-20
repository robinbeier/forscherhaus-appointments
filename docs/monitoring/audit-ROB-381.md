# ROB-381 Monitoring Audit

Date: 2026-05-20

Scope: audit current monitoring and production signals for Forscherhaus
Appointments. This document separates observed state from target
recommendations. It intentionally excludes secrets, Push URLs, tokens, DB rows,
raw Kuma database contents, and raw production config.

## Executive Findings

- Production was reachable and healthy during this audit: public app, public
  shallow health, renderer health, tokenized deep health, core services,
  containers, TLS, and all active Kuma monitors were green.
- The strongest current gap is not basic availability. It is classification:
  some expected scanner/proxy traffic still becomes CodeIgniter `ERROR` log
  noise and can make `App - Log Errors` look like downtime.
- `prod_validate_after_change.sh` currently fails when any app-error-like log
  line exists in the last 24 hours. That is useful as a strict gate after
  changes, but it is too blunt while ROB-382 noise is unresolved.
- Sentry is wired in code, but only partially. It boots from `SENTRY_*`, reads
  `_RELEASE`, and captures selected caught exceptions on PDF/export/booking
  confirmation paths. Many handled `json_exception()` paths still only log and
  return HTTP 500.
- Live Sentry issue/event audit was not completed: a local token-like env value
  was present, but `SENTRY_ORG` and `SENTRY_PROJECT` were unset, and the
  read-only Sentry organization probe returned HTTP 401.
- No additional monitoring product is justified now. Uptime Kuma, Sentry, app
  health endpoints, release gates, logs, and the existing ops scripts are enough
  if classification and ownership are tightened.

## Evidence Used

Repo and issue sources:

- `AGENTS.md`
- `README.md`
- `WORKFLOW.md`
- `docs/agent-harness-index.md`
- `docs/observability.md`
- `docs/ops/agent-operations.md`
- `docs/uptime-kuma.md`
- `scripts/ops/README.md`
- `scripts/ops/uptime-kuma.monitors.yml`
- `scripts/ops/uptime-kuma-crontab.example`
- `scripts/ops/uptime-kuma-push.env.example`
- `scripts/ops/kuma_push_*.sh`
- `scripts/ops/prod_doctor.sh`
- `scripts/ops/prod_logs_summary.sh`
- `scripts/ops/prod_validate_after_change.sh`
- `application/controllers/Healthz.php`
- `application/bootstrap/SentryBootstrap.php`
- `application/helpers/http_helper.php`
- `application/controllers/Calendar.php`
- `application/controllers/Booking.php`
- `application/controllers/Dashboard_export.php`
- `application/controllers/Booking_confirmation.php`
- `application/libraries/Pdf_renderer.php`
- `docs/release-gate-zero-surprise.md`
- `docs/release-gate-dashboard.md`
- `docs/release-gate-booking-confirmation-pdf.md`
- Linear: ROB-381, ROB-382, ROB-375, ROB-374, ROB-367, ROB-360, ROB-366

Read-only checks run:

- `bash scripts/ops/prod_doctor.sh`
- `bash scripts/ops/prod_validate_after_change.sh`
- `bash scripts/ops/prod_logs_summary.sh --since "24 hours ago"`
- `curl https://dasforscherhaus-leg.de/health`
- Sentry API read-only organization probe

Production evidence was summarized only. No secret-bearing file contents, Push
URLs, tokens, raw config, raw Kuma DB rows, or DB rows were copied here.

## Current Monitoring State

### Uptime Kuma

`docs/uptime-kuma.md` and `scripts/ops/uptime-kuma.monitors.yml` mirror the
current desired state:

| Monitor | Type | What it really checks | Signal |
| --- | --- | --- | --- |
| `App-Homepage` | HTTP | Public homepage returns 2xx | Availability |
| `App - Health Shallow` | Keyword | Public `/health` returns `OK` | Availability |
| `App - Health Deep` | JSON query | Tokenized `healthz` returns `status=ok` | Dependency health |
| `App - PDF Renderer` | JSON query | Tokenized `healthz` has `checks.pdf_renderer.ok=true` | PDF dependency |
| `Host - Services` | Push | Critical systemd services from host-local env | Host health |
| `Host - Resources` | Push | Disk, memory, load thresholds | Capacity |
| `Ops - Jobs Freshness` | Push | Restore-verify marker freshness | Backup/ops freshness |
| `App - Log Errors` | Push | New CodeIgniter log lines matching `ERROR - ` | App error signal |
| `App - php8.3-fpm Log Errors` | Push | PHP-FPM journal errors | Runtime error signal; historical live name captured during audit |
| `App - PDF Renderer Log Errors` | Push | PDF renderer journal errors | PDF runtime error signal |
| `App - Dashboard PDF Export` | Push | Dashboard PDF release gate as live synthetic | Business/PDF synthetic |
| `Security - Scanner Activity` | Push | Common scanner patterns in Apache access logs | Security/noise context |

Live read-only check at audit time showed 12 active Kuma monitors and 12 latest
green heartbeats.

### Health Endpoints

There are three health layers:

- `/` proves public route, TLS, Apache, PHP routing enough for the homepage.
- `/health` returns `OK` in production and backs the shallow Kuma keyword
  monitor. This route is not defined in `application/config/routes.php`; it is
  host or webserver owned and should be documented as such.
- `/index.php/healthz` is application-owned and token-protected. It checks DB,
  GD, storage writability, and PDF renderer reachability. A failed check returns
  HTTP 503 and logs `Healthz check failed: ...`.

The deep endpoint is a good dependency check. It is also sensitive because the
Kuma monitor needs the health token as a header. The repo template correctly
omits the token, but the docs currently describe the deep/PDF JSON monitors as
`public URL only`, which is incomplete.

### Runtime And Production Harness

`docs/ops/agent-operations.md` is the correct entry point for future Codex
production incident work. It defines:

- read-only first workflow;
- `prod_doctor.sh` for a redacted status snapshot;
- `prod_logs_summary.sh` for redacted log summaries;
- `prod_validate_after_change.sh` as the standard post-change gate;
- stop conditions around secrets, DB dumps, Kuma data, and unclear rollback.

Read-only production status during the audit:

- Ubuntu 26.04 host `booking-server`.
- Apache, PHP 8.5 FPM, MariaDB, Docker, fail2ban, cron,
  unattended-upgrades, and `fh-pdf-renderer` active.
- App, monitor, renderer, and deep-health endpoints healthy.
- Kuma and PDF renderer containers present.
- Node/npm absent on host as intended for artifact deploy.
- Certbot timer present and current certificate valid at audit time.
- Recent service journals had no warning-or-higher entries.
- App log summary had 7 app-error-like lines in the last 24 hours.

The stricter post-change validation failed only because
`app_error_like_lines_24h` was non-zero. That is a useful audit finding: the
gate is strict enough to catch log noise, but not yet smart enough to classify
ROB-382-style noise.

### Logs And Error Classification

Current log monitors are intentionally simple:

- `kuma_push_app_logs.sh` tracks newly appended bytes in the daily CodeIgniter
  log and counts `ERROR - ` lines after optional `KUMA_APP_LOG_IGNORE_REGEX`.
- `kuma_push_php_fpm_logs.sh` watches recent PHP-FPM journal errors.
- `kuma_push_pdf_renderer_logs.sh` watches recent PDF renderer journal errors.
- `prod_logs_summary.sh` gives redacted counts and short samples.

Observed 24h app-log classes during this audit:

- scanner-style 404s routed into CodeIgniter error logging;
- a proxy/CONNECT-related rate-limit cache warning from CodeIgniter/system cache
  deletion behavior.

These are not app downtime when the public route, `/health`, deep health, and
Kuma status stay green. They belong to ROB-382.

`application/helpers/http_helper.php` still makes generic `json_exception()`
return HTTP 500 and write an error log. ROB-375 already fixed backend calendar
appointment-save conflicts in `Calendar::save_appointment()` by returning 409
for known conflict messages without using `json_exception()`. Public booking
registration also returns 409 when no provider/time is available. However,
other expected business cases can still throw into generic `json_exception()`
unless explicitly classified.

### PDF Renderer

PDF monitoring exists at four layers:

- `Healthz::checkPdfRenderer()` checks `/healthz` on renderer candidates.
- `App - PDF Renderer` tracks the deep health PDF subcheck.
- `kuma_push_pdf_renderer_logs.sh` watches renderer service journal errors.
- `kuma_push_pdf_export.sh` runs the dashboard PDF release gate as a synthetic
  smoke every 15 minutes when scheduled.

`Pdf_renderer` captures final renderer failures to Sentry with tags
`area=pdf_renderer` and `operation=render_html`, and includes endpoint-selection
context. It does not include PDF HTML or tokens.

Gap: live synthetic coverage currently targets dashboard PDF export, not the
parent booking confirmation PDF flow. That is acceptable unless parent
confirmation PDF becomes operationally critical enough to justify a
privacy-safe synthetic appointment/hash strategy.

### Database

The app-level DB signal is the deep-health `SELECT 1 AS ok`. Release gates and
restore rehearsals cover broader DB behavior before deploys. This is correct:
Kuma should not query production business tables.

Gap: live DB health does not detect semantic data drift, stuck migrations, or
future booking write-path anomalies. Those belong to release gates, restore
rehearsals, and Sentry/log classification, not a broad live Kuma write probe.

### Cron, Backups, Restore

`kuma_push_ops_jobs.sh` checks one freshness marker:

- default marker: `/root/backups/easyappointments/last_verify_success.utc`
- default maximum age: 1440 minutes

This is a restore-verify freshness watchdog, not a generic cron monitor and not
a complete backup integrity monitor. Previous incident context around monitor
`#7` should be preserved: if this marker goes stale, the right action is to
renew the backup/restore verification flow, not to mark the app down.

Potential blind spots:

- backup creation freshness may differ from restore-verify freshness;
- off-host or operator-retained backup availability is not proven by this
  marker;
- cron daemon activity is checked by the production harness, but the repo env
  example for `KUMA_HOST_SERVICES_LIST` does not include `cron`, `fail2ban`, or
  `unattended-upgrades`.

### Deployment And Release Gates

Release monitoring is strong:

- `deploy_ea.sh` performs predeploy zero-surprise replay;
- validates release artifacts and deploy script drift;
- restarts and probes the PDF renderer;
- validates deep health;
- runs zero-surprise live canary;
- rolls back on post-switch validation failure;
- writes `_RELEASE`, which Sentry can use as release context.

Kuma should wrap deploy windows with maintenance mode, but should not replace
predeploy replay, renderer health, deep health, or live canary.

### Sentry

Technical wiring:

- `composer.json` requires `sentry/sentry`; lockfile currently resolves the SDK.
- `index.php` loads `SentryBootstrap` and boots it after `ENVIRONMENT` is set.
- `SentryBootstrap` reads `SENTRY_DSN`, `SENTRY_TRACES_SAMPLE_RATE`,
  `SENTRY_SEND_DEFAULT_PII`, and `SENTRY_SERVER_NAME` from env, getenv, or
  Apache-style server variables.
- `_RELEASE` is parsed and attached as the Sentry release.
- Default PII behavior is false unless `SENTRY_SEND_DEFAULT_PII` is explicitly
  true.
- Tests verify DSN absence disables boot options, Apache-style env reading,
  release parsing, and scoped tags/extras.

Captured paths today:

- PDF renderer final failures: `area=pdf_renderer`, `operation=render_html`.
- Dashboard export failures: `area=dashboard_export`, `export_type=...`.
- Booking confirmation related-entity failures:
  `area=booking_confirmation`, `operation=resolve_related_entities`.

Gaps:

- Live Sentry event ingestion was not verified in this audit because the
  available local API credentials were not usable.
- There is no explicit `before_send` redaction/scrubbing policy in repo code.
- `SENTRY_SEND_DEFAULT_PII=true` is supported and tested; production should
  keep it false unless there is a separate privacy decision.
- `Booking_confirmation` currently passes the raw appointment hash as Sentry
  extra context. A confirmation hash is not a password, but it is a bearer-like
  link component and should be replaced with non-reversible or truncated context.
- Broad CodeIgniter `json_exception()` paths log errors but do not explicitly
  capture Sentry events with area/status classification.
- Expected business conflicts should continue to be excluded from Sentry and
  Kuma error paths.

## What We Monitor Today

- Public app availability.
- Public shallow health.
- Tokenized deep application dependency health.
- PDF renderer health through deep health.
- Host services, host resource pressure, and PDF/PHP service logs via Push.
- Restore-verify freshness via Push.
- New CodeIgniter app log errors via Push.
- Dashboard PDF export via a live synthetic Push monitor.
- Scanner activity as a separate security/noise signal.
- Release safety through predeploy replay, postdeploy health, and live canary.
- Selected application failures in Sentry on PDF/export/confirmation paths.

## What We Think We Monitor But Do Not Fully Monitor

- "Deep health is public": it is not. It needs a token header. The repo mirror
  omits the secret correctly but should label the secret boundary explicitly.
- "Ops jobs freshness means backups are good": it currently proves only the
  restore-verify marker freshness.
- "App - Log Errors means app down": false. It means new matching app log
  lines. Some are real app errors; some are scanner/proxy noise until ROB-382 is
  fixed.
- "Host - Services covers all core host units": repo template covers a subset;
  production host-local overrides should be checked before assuming cron,
  fail2ban, and unattended-upgrades are included.
- "Sentry is production-verified": code is present, but this audit did not
  verify live event ingestion or alerting.
- "Parent-facing PDF is live-monitored": dashboard PDF is monitored; booking
  confirmation PDF is release-gated, not clearly live-monitored.

## Failure Modes That Could Remain Unnoticed

- Sentry DSN/API project misconfiguration or broken event delivery.
- Expected business conflicts outside the already-classified Calendar paths
  still becoming HTTP 500/log noise.
- Parent booking confirmation PDF regression between deploys.
- Backup creation failure when the restore-verify marker is still fresh from an
  earlier successful run.
- Missing off-host backup retention or restore artifact availability.
- Cron/timer degradation if host services monitor does not include cron/timers
  in production env.
- Semantic booking/calendar data anomalies that do not produce exceptions,
  health failures, or release gate failures.

## Noise Sources

- Scanner 404s that reach CodeIgniter.
- Forward-proxy/CONNECT probes that touch app/rate-limit/cache paths.
- Expected invalid-login or bad-input cases if logged as errors.
- Host-only PDF renderer fallback errors if fallback probing is expected.
- Deep-health dependency failures causing both deep-health red and app-log red.
  This duplication is acceptable for real dependency failures, but should be
  interpreted as one incident.
- `prod_validate_after_change.sh` currently treats any app-error-like line in
  24h as a failed validation, even when all availability checks and Kuma are
  green.

## Sentry Vs Kuma Classification

Belongs in Sentry, not Kuma downtime:

- unexpected PHP exceptions on booking, calendar, dashboard export, PDF, login,
  recovery, sync, notification, and webhook paths;
- repeated HTTP 500s from app request paths;
- caught critical export/PDF exceptions with safe context;
- release-correlated regressions.

Belongs in Kuma, not necessarily Sentry:

- public app route down;
- TLS/certificate availability symptoms;
- deep-health DB/storage/PDF dependency down;
- inactive critical systemd service;
- disk/memory/load threshold breach;
- stale restore-verify marker;
- no Push heartbeat from a host script.

Belongs in both:

- PDF renderer outage that breaks live PDF generation;
- DB connectivity failure;
- storage unwritable;
- repeated unexpected 5xx on critical flows;
- deployment canary failure or rollback event.

Should disturb less or not at all:

- single scanner 404;
- expected 401/403/404 from bots;
- expected validation failures and business conflicts returned as 4xx;
- one-off known rate-limit cache race once ROB-382 handles it;
- scanner activity monitor below threshold.

## Minimal Operating Targets

- Public app route: healthy within one Kuma interval plus one retry.
- Shallow health: `OK` within one interval plus one retry.
- Deep health: DB, GD, storage, and PDF renderer all OK.
- PDF renderer: health OK and dashboard synthetic export succeeds within 15
  minutes.
- Restore verification: marker age at or below 1440 minutes, or a deliberately
  documented maintenance exception.
- Host services: Apache, PHP-FPM, MariaDB, Docker, PDF renderer, and preferably
  cron/fail2ban/unattended-upgrades active.
- Capacity: root disk below 85 percent, memory below configured warning
  threshold, load below configured per-core threshold.
- Sentry: production release and environment attached to relevant unexpected
  exceptions, with no PII/secrets.

## ROB-382 Placement

ROB-382 was implemented after this audit in PR #280. It should remain a
focused completed follow-up, not a general monitoring rewrite:

- targeted app-log classification policy without muting real app errors;
- regression tests for `kuma_push_app_logs.sh`;
- docs for noise policy;
- companion logic for `prod_validate_after_change.sh` and
  `prod_logs_summary.sh` so known scanner/proxy noise does not keep the gate
  red.

Do not reopen ROB-382 as a broad Sentry task. Future Sentry work belongs to
ROB-383.
