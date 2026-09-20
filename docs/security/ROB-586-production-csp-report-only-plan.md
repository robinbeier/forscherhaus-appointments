# ROB-586 Production CSP Report-Only Preparation

Status: local implementation and production plan. Nothing in this document
activates a production header, installs a production configuration, runs the new
production status probe, or changes Uptime Kuma. The active status probe
includes one bounded readiness mutation under the effective `www-data`
identity: it creates, flushes, fsyncs, closes, and immediately unlinks a
random same-directory probe file. The inactive probe remains mutation-free.

## Decision

Prepare one bounded `Content-Security-Policy-Report-Only` pilot for the App and
`www` HTTPS surfaces. The application owns both the header and a same-origin
collector. The collector reduces every accepted browser report to fixed classes
before durable storage. `Content-Security-Policy` enforcement remains disabled.

Uptime Kuma remains outside this pilot. Its UI, WebSocket, worker, and vendor
release requirements require a separate policy and evidence set.

## Bound identities and baseline

The implementation starts from merged source commit
`4291b838fda87b0e8ccc6be0bdb262e18efb0e74`. A read-only production snapshot
captured at `2026-09-20T14:02:06Z` reported:

- App HTTPS `200`, `www` HTTPS `200`, Monitor HTTPS expected redirect;
- enforcement CSP and Report-Only CSP missing on all three surfaces;
- the other existing header classes present on all three surfaces;
- deep health and renderer `200`;
- seven active Uptime Kuma monitors with seven latest green;
- no unexpected public listener and two classified overlay listeners;
- no service warning class in the preceding hour;
- zero actionable application-error-like lines in the redacted 24-hour
  summary; two lines were classified as known noise.

A separate aggregate-only database observation reported both Google Analytics
and Matomo disabled. It exposed no analytics code, URL, or setting value.
This is a dated baseline, not proof for a later activation window.

## Activation contract

The feature is inactive unless the runtime can safely load the fixed file
`/var/lib/fh-app-config/csp-report-only.json`. The dedicated directory is
root-owned mode `0755`; the non-secret file is root-owned mode `0644` and
the application can read but cannot replace either path. The existing
`/etc/fh` stays private mode `0700` and unchanged. The reviewed source candidate lives at
`scripts/ops/config/csp_report_only.production.v1.json`; its presence in a
release does not activate it. The first production candidate is:

```json
{
  "schema": "csp_report_only_config.v1",
  "enabled": true,
  "app_host": "dasforscherhaus-leg.de",
  "www_host": "www.dasforscherhaus-leg.de",
  "google_analytics_enabled": false,
  "matomo_origin": null,
  "max_reports_per_minute": 120,
  "retention_hours": 48
}
```

The runtime must reject an unexpected key or type, a non-canonical or duplicate
host, credentials, a path/query/fragment in the optional Matomo origin, an
unsafe file identity, or an unsafe ancestor. A missing or invalid file means
disabled. The file contains no secret and does not make production evidence
public.
For an active receipt, the local validator must compare the observed production
hash with the SHA-256 of the versioned candidate file in the reviewed release;
drift or a missing candidate binding fails closed.

The header is eligible only when all of these are true:

- the activation file passes the trust and schema contract;
- the request uses HTTPS;
- the request host exactly matches `app_host` or `www_host`;
- the response is HTML;
- the request is not the collector endpoint.

APIs, downloads, renderer traffic, unmatched hosts, HTTP, and Monitor receive no
header from this feature.

## Candidate App and WWW policy

```text
default-src 'self';
base-uri 'self';
object-src 'none';
frame-ancestors 'self';
form-action 'self';
script-src 'self' 'unsafe-inline' <exact-google-origins-if-enabled> <exact-matomo-origin-if-configured>;
style-src 'self' 'unsafe-inline';
img-src 'self' data: <exact-google-origins-if-enabled> <exact-matomo-origin-if-configured>;
font-src 'self' data:;
connect-src 'self' <exact-google-origins-if-enabled> <exact-matomo-origin-if-configured>;
report-uri /csp-report;
```

`'unsafe-inline'` remains a measured compatibility concession. The policy does
not grant enforcement readiness, and it does not use a wildcard analytics
origin. Google origins are absent when `google_analytics_enabled=false`;
Matomo is absent when `matomo_origin=null`.

## Collector and retained evidence

The collector accepts only bounded CSP report media types on the exact POST
route. It reads at most 32 KiB, classifies the payload in memory, and retains
only this finite tuple:

```text
surface | directive | blocked-origin-class | disposition | count
```

Allowed surfaces are `app` and `www`. Directive and blocked-origin values are
mapped to fixed allowlists with an `other` or `unknown-external` class where
needed. Only `disposition=report` and exact configured document origins are
eligible. The bounded aggregate contains at most the configured hourly
retention window and uses a global per-minute acceptance limit.

Malformed or ineligible payloads receive the fixed empty response without
durable rejection accounting. They therefore cannot force an unauthenticated
per-request aggregate lock, rewrite, flush, or `fsync`. Only a successfully
classified report reaches the bounded aggregate and its acceptance limit.

Never retain or forward a raw payload, document URL, referrer, source file,
source sample, path, query, fragment, appointment capability, analytics code,
Matomo URL, IP address, user agent, cookie, authorization value, or request
header.

The aggregate file is an observation aid. Before the first accepted report, a
missing aggregate is an explicit zero-observation state and the read-only
status check may pass with `aggregate.status=missing` only after the full path
and create permissions have been verified for the fixed PHP-FPM runtime user
`www-data`. An invalid or unavailable file, a storage error, or an unknown
class remains a visible gap; it is not a clean result. Over-limit reports are
rejected transiently with `429` and do not create a durable rate-limit drop or
rewrite the aggregate.

## Local App and WWW matrix

The matrix separates policy distribution from functional coverage. A
Report-Only policy cannot block a flow, so an existing functional regression is
still useful evidence; it does not prove that the flow emits no policy report.
The browser probe supplies a separate class-only receipt and persists no raw
report. Rows that were not exercised remain explicit gaps.

The executable distribution matrix is covered by
`CspReportOnlyTest::testAppWwwAnalyticsAndExcludedSurfaceMatrix`:

| Surface or response | Analytics state | Expected result | Local result |
| --- | --- | --- | --- |
| App HTML over exact HTTPS host | disabled | header present; no Google or Matomo origin | `passed` |
| WWW HTML over exact HTTPS host | disabled | header present; no Google or Matomo origin | `passed` |
| App and WWW HTML | synthetic Google enabled | only exact HTTPS Google Analytics and Tag Manager origins | `passed` |
| App and WWW HTML | synthetic Matomo enabled | only the exact configured origin, including an explicit port | `passed` |
| Monitor or any unmatched host | any | no header | `passed` |
| App API, PDF, ZIP, or other non-HTML response | any | no header | `passed` |
| collector route, HTTP request, or invalid activation file | any | no header | `passed` |

The full local gate was executed against the implementation with coverage. Its
deep-runtime suite passed the API contract, booking writes, API writes, booking
controller flows, and all 12 selected integration-smoke checks. A real local
Chrome run also produced one intentional class-only violation and an
intercepted report without persisting its body.

| Surface | State or flow | Local functional evidence | CSP-specific result |
| --- | --- | --- | --- |
| App public | booking page and availability selection | deep-runtime browser/readiness checks passed | common App policy and real Chrome receipt `passed` |
| App public | booking, reschedule, and cancellation writes | six booking write-contract checks passed | policy distribution `passed`; no per-write browser receipt required for Report-Only |
| App public | confirmation and ICS capability downloads | HTTP download regressions passed | non-HTML exclusion `passed`; confirmation-page browser receipt `not_tested` |
| App account | login | login readiness and authentication smoke passed | common App policy `passed` |
| App account | logout and own-account save | ordinary account regressions passed | authenticated browser receipt `not_tested` |
| App backoffice | dashboard and chart render | dashboard page and browser summary passed | authenticated browser receipt `not_tested` |
| App backoffice | calendar and representative settings | calendar/account/settings integration regressions passed | authenticated browser receipt `not_tested` |
| App export | representative PDF and ZIP | controller, view, and release-gate contract tests passed | non-HTML exclusion `passed`; live renderer download `not_tested` |
| App responsive | booking and login at mobile width | no dedicated mobile-width run in this slice | `not_tested` |
| App desktop | public core pages | booking and login readiness passed | common App policy and real Chrome chain `passed` |
| App analytics | disabled, synthetic Google, synthetic Matomo | exact policy and classifier matrix passed | real analytics-loaded browser fixture `not_tested` |
| WWW | desktop and mobile homepage | same application assets, but no local production-host virtual-host run | exact WWW policy `passed`; functional host run `not_tested` |

`not_tested` rows are stop conditions for the first production activation unless
the approved production window explicitly supplies the missing bounded evidence.
The matrix never uses real analytics values. Public CSP submissions are
unauthenticated and can be fabricated; class counts are diagnostic input, not
proof of a genuine browser or user journey. A locally clean result does not
prove production traffic, proxy behavior, or every user journey.

## Monitor decision

Do not copy the App/WWW policy to
`monitor.dasforscherhaus-leg.de`. Production currently runs the pinned Uptime
Kuma `2.5.5-slim` image on a loopback listener behind its own HTTPS surface. It
uses no browser monitors, but the dashboard itself can depend on WebSocket,
worker, blob, or data behavior that the App policy does not cover.

The Monitor follow-up must begin with a disposable instance of the pinned image
and a separate browser matrix. It must decide exact `connect-src`, WebSocket,
worker, blob, frame, and data requirements before even a Report-Only header is
considered. This App/WWW pilot must keep both enforcement and Report-Only CSP
missing on Monitor.

## Proposed first production window

This section is a future operation requiring the separate production approval.

1. Deploy the reviewed application release with the feature still disabled.
2. Bind the deployed release SHA and run the existing read-only doctor,
   validation, and redacted log summary.
3. Under the shared production-change lock, install the exact root-controlled
   activation file with no-clobber semantics. Record its SHA-256 and identity
   without printing its contents.
4. Confirm App and `www` expose Report-Only only, enforcement stays missing,
   and Monitor exposes neither.
5. Run the approved existing synthetic browser smokes with their normal cleanup
   receipts, plus the new class-only status probe. When `--expect=active` is
   used, this includes the bounded `www-data` same-directory write-readiness
   probe; an unknown or failed probe is fail-closed. The inactive status probe
   remains read-only.
6. Observe for 60 minutes, with class-only snapshots at activation, 15 minutes,
   and 60 minutes. Do not repeat an unknown or contradictory run
   automatically.
7. Remove the activation file at the end of the pilot even when the observations
   are clean. A later enforcement or longer pilot is another decision.

## Stop conditions

Stop before activation when the deployed SHA, configuration identity, collector
readiness, pre-change health, current analytics state, lock, smoke coverage, or
rollback identity is unknown.

After activation, roll back when:

- enforcement CSP appears;
- Report-Only appears on Monitor, HTTP, an unmatched host, an API, or a download;
- App, `www`, Monitor, renderer, deep health, Kuma, or a required smoke regresses;
- a raw value reaches durable storage, logs, chat, Linear, or a third party;
- the aggregate is invalid, unavailable, unbounded, or unexpectedly noisy;
- sustained unexpected `429` rate-limit responses occur without an understood benign cause;
- a new actionable log/error class appears;
- the installed activation file no longer matches the recorded identity.

## Rollback

Hold the shared production-change lock. Remove only the activation file when its
current SHA-256 and file identity still match the installed candidate. If they
do not match, stop for controlled recovery instead of deleting an unknown file.
No Apache or PHP-FPM reload is required because the application reads the
root-controlled switch for each request.

Then verify:

- App and `www` Report-Only header class is missing;
- enforcement CSP remains missing everywhere;
- Monitor remains unchanged;
- existing production validation, doctor, deep health, renderer, Kuma, and
  redacted log summary pass;
- no synthetic fixture or pending operation marker remains.

Preserve the class-only aggregate and the rejected candidate identity for
review. Do not preserve raw browser reports because none may be written.

## Evidence boundary

Local tests can establish configuration parsing, host and response scoping,
policy construction, payload classification, bounded storage, rate limiting,
and the local flow matrix. A merge establishes reviewed source only. Deployment
with the feature disabled establishes release availability only. The first
production pilot is the earliest point that can establish actual Report-Only
delivery and class-only collection on that exact release.
