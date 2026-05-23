# ROB-409 CSP Report-Only Design

Status: repo-only/docs-only design for a later CSP Report-Only pilot. This
document does not set a CSP header, does not edit Apache configuration, does
not implement reporting, and does not touch production.

Scope: define a privacy-first measurement design so ROB-410 can later prepare a
separate `Content-Security-Policy-Report-Only` live gate for App and `www`.

## Executive Summary

ROB-404 decided that CSP enforcement is not ready for Forscherhaus
Appointments. The application still has legitimate inline script, inline style,
analytics, PDF/QR/canvas, and legacy vendor-library behavior that could break
under enforcement.

ROB-409 therefore designs a non-blocking report-only pilot:

- App and `www` are the first target surfaces.
- Monitor stays out of scope and keeps its own header boundary.
- Enforcement remains deferred.
- Reporting must be privacy-safe before any production header is added.
- ROB-410 owns any future Apache live gate and requires separate explicit
  production approval.

Decision:

- `Content-Security-Policy`: **do not enable**.
- `Content-Security-Policy-Report-Only`: **design only in ROB-409; possible
  live gate in ROB-410**.
- Reporting implementation: **defer to ROB-410 or a smaller prerequisite if
  ROB-410 finds the reporting target is not ready**.

## Design Goals

- Measure CSP compatibility without blocking public booking, backoffice, login,
  export, analytics, or PDF/QR flows.
- Prefer privacy and operational safety over broad telemetry.
- Keep App/WWW separate from Monitor, Sentry, Kuma, SSH, UFW, database, deploy,
  and package/runtime changes.
- Produce violation classes that ROB-411 can triage without raw secrets or
  production data.
- Preserve the existing server-hardening pattern: docs PR first, then a
  separately approved live gate.

## Non-Goals

ROB-409 does not:

- add `Content-Security-Policy`;
- add `Content-Security-Policy-Report-Only`;
- change Apache, vhosts, `.htaccess`, Kuma, Sentry, UFW, SSH, DB, deploy,
  packages, Docker, PHP runtime, or services;
- implement a report collection endpoint;
- configure a third-party report collector;
- change application templates, JavaScript, CSS, vendor assets, analytics
  behavior, or PDF behavior;
- decide CSP enforcement.

## Source Inputs

- `docs/security/ROB-404-csp-compatibility-spike.md`: compatibility baseline
  and enforcement deferral.
- `docs/security/production-server-posture-decision.md`: CSP must stay a
  separate gate from baseline headers and HSTS.
- `docs/security/ROB-398-app-www-security-headers-gate.md`: App/WWW header
  gate boundary and validation pattern.
- `docs/security/ROB-399-monitor-security-headers-gate.md`: Monitor is a
  separate surface.
- `docs/security/ROB-407-short-hsts-live-gate.md`: header live-gate pattern
  and evidence boundary.

No production access was used for this design.

## Target Surfaces

### In Scope For A Later ROB-410 Pilot

- App HTTPS surface.
- `www` HTTPS surface.
- Public booking flow.
- Staff login/account flow.
- Backoffice pages that exercise calendar, dashboard, settings, customer and
  provider UI.
- Booking confirmation PDF/QR flow.
- Export/PDF renderer-adjacent flows where browser behavior matters.
- Analytics-disabled and analytics-enabled states, where practical.

### Out Of Scope For ROB-409 And Initial ROB-410

- Monitor CSP.
- CSP enforcement.
- Longer-term nonce/hash refactors.
- Any live changes before ROB-410 has an explicit production approval.

## Candidate Report-Only Policy

This policy is a **draft measurement policy**, not a production-ready policy and
not an enforcement policy.

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

Rationale:

- `default-src 'self'`, `base-uri 'self'`, `object-src 'none'`,
  `frame-ancestors 'self'`, and `form-action 'self'` are the low-risk baseline
  intent to measure.
- `script-src 'unsafe-inline'` is included only because ROB-404 identified
  intentional inline script bridges. Its presence is a measurement compromise,
  not a desired final state.
- `style-src 'unsafe-inline'` is included only because inline styles are common
  in current views and export templates.
- `img-src data:` is included because CAPTCHA, QR/PDF, canvas, and analytics
  behavior may need image/data allowances.
- Analytics origins must be included only when analytics is enabled and
  measured.
- `report-uri`/`report-to` placeholders must not be added until the reporting
  target and redaction path are decided.

Known unresolved allowances:

- final Google Analytics origin set;
- operator-configured Matomo origin handling;
- whether `font-src data:` is required by current vendor assets;
- whether `connect-src` needs additional same-site/API or reporting endpoints;
- whether `worker-src`, `child-src`, or `media-src` are needed after real
  browser observation;
- whether report-only noise is useful enough to justify production collection.

## Privacy And Reporting Requirements

CSP reports can contain URLs. In this application, URLs may carry sensitive
appointment-management context. Reporting must therefore be privacy-safe before
ROB-410 adds any production header.

Required privacy properties:

- do not persist raw document URLs;
- strip query strings and fragments before durable storage;
- strip or classify path segments that may contain appointment hashes or other
  capability URLs;
- do not persist raw request bodies;
- do not persist tokens, API tokens, health tokens, Push URLs, DSNs, passwords,
  session IDs, cookies, or authorization headers;
- do not persist PII from page URLs, referrers, blocked URLs, or user-agent
  strings;
- store only sanitized classes where possible, for example:
  - surface class: `app`, `www`, `booking`, `backoffice`, `account`, `export`;
  - directive class: `script-src`, `style-src`, `img-src`, `connect-src`;
  - blocked-origin class: `self`, `google-analytics`, `matomo-configured`,
    `data`, `extension`, `unknown-external`;
  - disposition class: `report`;
- define retention before collection starts;
- define an owner for triage before collection starts.

Stop if safe redaction cannot be implemented before persistence.

## Reporting Architecture Options

### Option A: Existing Observability, Only With Redaction

Reports could flow into existing observability only if redaction happens before
durable storage and before any report leaves the host boundary.

Pros:

- fewer new moving parts;
- easier operational visibility.

Cons:

- high privacy risk if raw CSP payloads are sent directly;
- may mix browser-report noise with application-error signals.

Decision for ROB-409: acceptable only if ROB-410 proves pre-persistence
redaction. Do not send raw CSP reports directly to existing observability.

### Option B: Minimal Dedicated Report Endpoint

A small endpoint could accept browser reports, reduce them to safe classes, and
discard raw payloads.

Pros:

- clearer privacy boundary;
- purpose-built classification for ROB-411 triage.

Cons:

- requires code and tests;
- adds a write path and abuse surface;
- may require rate limiting and storage decisions.

Decision for ROB-409: likely the cleanest long-term path, but not implemented
here. If ROB-410 cannot use an existing safe target, create a prerequisite
implementation issue before live activation.

### Option C: Staging Or Manual Browser-Driven Pilot First

Run the draft policy in a staging-like environment or browser test harness
before production collection.

Pros:

- avoids production telemetry risk;
- can validate the policy against known flows.

Cons:

- may miss real browser, analytics, extension, or production routing behavior.

Decision for ROB-409: recommended as a pre-live step if setup cost is low, but
not a substitute for a short, privacy-safe production Report-Only pilot.

## Smoke Matrix For ROB-410

ROB-410 should not go live until this matrix is executable manually or through
existing smokes.

| Surface | Flow | Required evidence class |
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
| Analytics enabled | Google Analytics and/or Matomo configured, if available without exposing secrets | `analytics_enabled=pass|not_tested` |
| Responsive | Mobile-width booking and login smoke | `mobile_core=pass` |
| Desktop | Desktop booking and backoffice smoke | `desktop_core=pass` |

If a flow cannot be tested without secrets or real PII, record only
`not_tested` plus a reason. Do not fabricate pass evidence.

## ROB-410 Gate Contract

ROB-410 may prepare and execute a live Report-Only gate only after separate
explicit production approval.

Preconditions:

- this design doc is merged;
- reporting target and redaction are decided;
- reporting retention and triage owner are decided;
- draft policy is adjusted for actual reporting target;
- `prod_validate_after_change.sh` passes before change;
- `prod_doctor.sh` reports healthy App, `www`, Monitor, renderer, deep health,
  services, Kuma, UFW/listener posture, and log classes;
- Apache headers module and configtest classes are healthy;
- rollback path is documented before editing.

Allowed live change:

- add only `Content-Security-Policy-Report-Only` to intended App/WWW HTTPS
  responses;
- do not add `Content-Security-Policy`;
- do not change Monitor CSP;
- do not change HSTS, baseline headers, UFW, SSH, Kuma, Sentry, DB, deploy,
  package, Docker, or runtime state.

Post-change validation:

- `apache2ctl configtest` passes before reload;
- Apache reload succeeds;
- App/WWW health classes remain healthy;
- Monitor, renderer, deep health, and Kuma classes remain healthy;
- smoke matrix has pass/not-tested evidence;
- reports, if collected, appear only as redacted classes;
- `prod_validate_after_change.sh` passes;
- `prod_doctor.sh` remains healthy.

Rollback:

- remove only the Report-Only header change;
- run `apache2ctl configtest`;
- reload Apache only if configtest passes;
- re-run `prod_validate_after_change.sh`;
- re-run `prod_doctor.sh`;
- preserve only class-based evidence.

## Stop Conditions For ROB-410

Stop before live activation if:

- no explicit production approval exists;
- reporting target is not decided;
- redaction happens after durable storage or after sending to a third party;
- raw report payloads would be persisted;
- appointment hashes, query strings, fragments, tokens, health tokens, Push
  URLs, cookies, authorization headers, PII, raw request bodies, DB rows,
  session/cache contents, raw Apache config, or raw app config would be exposed;
- the target Apache scope cannot be identified without raw config dumps;
- `prod_validate_after_change.sh` or `prod_doctor.sh` is red before change;
- smoke matrix coverage is too weak to interpret violations;
- rollback is unclear.

Stop after live activation and roll back if:

- `Content-Security-Policy` enforcement appears instead of Report-Only;
- Report-Only is applied to unintended surfaces;
- reports contain sensitive raw data after redaction;
- App, `www`, Monitor, renderer, deep health, or Kuma health regresses;
- log/error classes show new unexplained failures;
- report volume is high enough to create monitoring noise or storage risk.

## Handoff To ROB-411

ROB-411 should receive only safe classes and summarized observations:

- directive class;
- surface class;
- blocked-origin class;
- count or sampled count;
- decision candidate: `noise`, `intentional-allow`, `fix-before-enforcement`,
  `blocker`, or `unknown`;
- no raw URLs, no raw report payloads, no secrets, no PII.

ROB-411 should not start until ROB-410 has either:

- completed a privacy-safe production Report-Only pilot; or
- explicitly stopped with a documented reason and an alternate safe data source.

## Acceptance Criteria

- ROB-409 persists this design in the repo.
- The candidate policy is clearly labeled draft and not production-ready.
- Reporting privacy requirements are explicit.
- ROB-410 has a clear live-gate contract.
- No production change is made by ROB-409.
