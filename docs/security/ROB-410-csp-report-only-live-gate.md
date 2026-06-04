# ROB-410 CSP Report-Only Live-Gate

Status: prepared gate, live activation stopped pending ROB-414.

Scope: evaluate whether the ROB-409 `Content-Security-Policy-Report-Only`
pilot can safely go live on App and `www`. This document records the gate
decision, required live procedure, stop conditions, and handoff. It does not set
a CSP header, does not edit Apache configuration, and does not touch
production.

## Executive Summary

ROB-409 established that CSP enforcement is not ready and that a Report-Only
pilot is useful only if reporting is privacy-safe before any production header
is added.

ROB-410 verified the current repo and production posture and reached this
decision:

- `Content-Security-Policy`: **do not enable**.
- `Content-Security-Policy-Report-Only`: **do not enable yet**.
- Reporting target: **not ready**.
- Collector prerequisite: **ROB-414 must complete before live activation**.

The blocker is not Apache header mechanics. The blocker is that no dedicated,
provably privacy-safe CSP browser-report collector exists. Existing Sentry
scrubbing protects application events, but it is not a safe direct sink for raw
browser CSP reports.

## Evidence Summary

### Repository Evidence

Source: repository search for CSP reporting and collector terms.

- No implemented CSP report endpoint was found.
- No `report-uri` or `report-to` production target was found.
- No `securitypolicyviolation` browser capture path was found.
- `application/bootstrap/SentryBootstrap.php` provides Sentry event scrubbing
  and safe digests for selected application events.
- `docs/observability.md` defines Sentry as application-error observability, not
  as a raw browser-report collector.
- ROB-398, ROB-399, and ROB-407 provide the existing narrow Apache header
  gate pattern.

Decision: existing observability is not acceptable as the CSP reporting target
unless a future change proves redaction before persistence and before any
third-party transmission.

### Production Evidence

Source: `bash scripts/ops/prod_doctor.sh`, read-only redacted snapshot
`2026-05-23T20:26:11Z`.

- App HTTPS and `www` HTTPS returned `200`.
- Monitor HTTPS returned the expected redirect/status class.
- Renderer and deep health returned `200`.
- Uptime Kuma reported `13` active monitors and `13` latest green.
- App, `www`, and Monitor CSP posture were `missing`.
- Baseline security headers and HSTS were present on App, `www`, and Monitor.
- Sensitive-path and scanner-path failure counts were `0`.
- UFW was active; expected public listener classes were `22`, `80`, and `443`;
  unexpected public listener count was `0`.
- Renderer, Kuma, and database listener classes were loopback.
- Apache, PHP-FPM, MariaDB, Docker, fail2ban, cron, unattended-upgrades, and
  `fh-pdf-renderer` were active.
- Host Node/npm were absent.
- Recent service warning classes and app-error-like log classes were `0`.

Source: `bash scripts/ops/prod_logs_summary.sh --since "24 hours ago"`,
read-only redacted snapshot `2026-05-23T20:26:25Z`.

- Apache, PHP-FPM, MariaDB, Docker, cron, and PDF renderer warning classes were
  `0`.
- App-error-like lines for the last 24 hours were `0`.

Decision: production was healthy enough to prepare a gate, but the missing
collector stops live activation.

## Gate Decision

ROB-410 stops before live activation and creates ROB-414, `Implement
privacy-safe CSP report collector`, as a prerequisite.

ROB-414 must prove one of these before ROB-410 can continue:

- a minimal dedicated CSP report collector that reduces browser reports to safe
  classes before persistence; or
- an equivalent architecture where redaction happens before durable storage and
  before any third-party transmission.

Direct raw browser CSP reports to Sentry, logs, Kuma, or another third-party
sink are not acceptable.

## Required Reporting Properties

A future reporting target must:

- not persist raw document URLs;
- strip query strings and fragments before durable storage;
- strip or classify path segments that may contain appointment hashes or other
  capability URLs;
- not persist raw request bodies;
- not persist tokens, API tokens, health tokens, Push URLs, DSNs, passwords,
  session IDs, cookies, or authorization headers;
- not persist PII from page URLs, referrers, blocked URLs, report bodies, or
  user-agent strings;
- store only safe classes such as surface, directive, blocked-origin class,
  disposition, and count or sampled count;
- define retention before collection starts;
- define a triage owner before collection starts;
- define rate-limit and abuse behavior for the collector.

## Candidate Report-Only Policy

This policy remains a draft measurement policy. It must not be applied until
ROB-414 provides a safe reporting target.

```text
default-src 'self';
base-uri 'self';
object-src 'none';
frame-ancestors 'self';
form-action 'self';
script-src 'self' 'unsafe-inline' https://www.googletagmanager.com <matomo-origin-if-enabled>;
style-src 'self' 'unsafe-inline';
img-src 'self' data: https://www.google-analytics.com <matomo-origin-if-enabled>;
font-src 'self' data:;
connect-src 'self' https://www.google-analytics.com <matomo-origin-if-enabled> <reporting-endpoint-origin>;
report-to <reporting-group-if-supported>;
report-uri <redacted-report-endpoint-if-used>;
```

Before activation, replace placeholders only with a proven safe reporting
target. If no reporting target exists, do not add the header.

## Smoke Matrix

ROB-410 should not go live until this matrix is executable manually or through
existing smokes. If a flow cannot be tested without secrets or real PII, record
`not_tested` plus a reason.

| Surface | Flow | Evidence class |
| --- | --- | --- |
| Public booking | Load booking page, select appointment, submit normal request | `booking_flow=pass` |
| Public booking | Cancellation/reschedule URL behavior | `booking_manage_flow=pass` |
| Booking confirmation | PDF/QR generation path | `booking_pdf_qr=pass` |
| Account/login | Staff login and logout | `login_flow=pass` |
| Account/login | Account page load/save smoke where safe | `account_flow=pass` |
| Backoffice calendar | Calendar load, event rendering, modal open | `calendar_flow=pass` |
| Dashboard | Dashboard load and chart rendering | `dashboard_flow=pass` |
| Settings | Representative settings page load | `settings_flow=pass` |
| Exports/PDF | Representative export/PDF renderer path | `export_pdf_flow=pass` |
| Analytics disabled | Booking page without analytics configured | `analytics_disabled=pass` |
| Analytics enabled | Analytics configured, if available without exposing secrets | `analytics_enabled=pass|not_tested` |
| Responsive | Mobile-width booking and login smoke | `mobile_core=pass` |
| Desktop | Desktop booking and backoffice smoke | `desktop_core=pass` |

## Future Live-Gate Contract

Do not execute this section until ROB-414 is complete and separate explicit
production approval authorizes the Apache live write.

Preconditions:

- ROB-414 completed and linked as the reporting prerequisite.
- Reporting target, redaction path, retention, and triage owner are decided.
- Draft policy is adjusted for the actual reporting target.
- `bash scripts/ops/prod_validate_after_change.sh` passes before change.
- `bash scripts/ops/prod_doctor.sh` reports healthy App, `www`, Monitor,
  renderer, deep health, services, Kuma, UFW/listener posture, and log classes.
- Sanitized Apache prerequisite check confirms headers module present,
  configtest ok, App/WWW scope identified, and Monitor scope excluded.
- Rollback path is documented before editing.

Allowed live change:

- Add only `Content-Security-Policy-Report-Only` to intended App/WWW HTTPS
  responses.
- Do not add `Content-Security-Policy`.
- Do not change Monitor CSP.
- Do not change HSTS, baseline headers, UFW, SSH, Kuma, Sentry, DB, deploy,
  package, Docker, or runtime state.

Post-change validation:

- `apache2ctl configtest` passes before reload.
- Apache reload succeeds.
- App, `www`, Monitor, renderer, deep health, and Kuma classes remain healthy.
- Smoke matrix has pass/not-tested evidence.
- Reports, if collected, appear only as redacted classes.
- `prod_validate_after_change.sh` passes.
- `prod_doctor.sh` remains healthy.

Rollback:

- Remove only the Report-Only header change.
- Run `apache2ctl configtest`.
- Reload Apache only if configtest passes.
- Run `prod_validate_after_change.sh`.
- Run `prod_doctor.sh`.
- Preserve only class-based evidence.

## Stop Conditions

Stop before live activation if:

- no explicit production approval exists;
- ROB-414 is not complete;
- reporting target is undecided;
- redaction happens after durable storage or after sending to a third party;
- raw CSP payloads, raw URLs, query strings, fragments, appointment hashes,
  tokens, cookies, auth headers, health tokens, Push URLs, PII, raw request
  bodies, DB rows, session/cache contents, raw Apache config, or raw app config
  would be exposed;
- App/WWW Apache scope cannot be identified without raw config dumps;
- `prod_validate_after_change.sh` or `prod_doctor.sh` is red before change;
- smoke matrix coverage is too weak to interpret violations;
- rollback is unclear.

Stop after live activation and roll back if:

- enforcement appears instead of Report-Only;
- Report-Only is applied to Monitor or other unintended surfaces;
- reports contain sensitive raw data after redaction;
- App, `www`, Monitor, renderer, deep health, or Kuma health regresses;
- log/error classes show new unexplained failures;
- report volume creates monitoring noise or storage risk.

## Handoff

ROB-414 owns implementation of a privacy-safe collector or equivalent safe
reporting target.

ROB-410 can resume after ROB-414 only to execute the future live-gate contract
above with separate explicit production approval.

ROB-411 should not start until ROB-410 has either:

- completed a privacy-safe production Report-Only pilot; or
- documented another safe data source.

ROB-411 should receive only safe classes: directive, surface, blocked-origin,
count or sampled count, and a decision candidate such as `noise`,
`intentional-allow`, `fix-before-enforcement`, `blocker`, or `unknown`.

## Evidence Boundary

Allowed in chat, Linear, PR, and docs:

- Header presence classes.
- Health status classes.
- Kuma active/latest-green counts.
- Sanitized warning/error counts.
- `apache.headers_module=present|missing`.
- `apache.configtest=ok|failed`.
- `prod_validate_after_change.sh` pass/fail outcome.
- CSP report classes after ROB-414.

Not allowed:

- Raw CSP reports.
- Raw document URLs, query strings, fragments, appointment hashes, or
  capability URLs.
- Raw Apache vhost config.
- Secret-bearing file contents.
- Tokens, passwords, DSNs, Push URLs, health-token values, cookies, or
  authorization headers.
- DB rows, session/cache contents, Kuma DB rows, raw logs, or raw production
  config.
