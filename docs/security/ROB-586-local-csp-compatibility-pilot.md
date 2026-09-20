# ROB-586 Local CSP Compatibility Pilot

Status: repo-only local browser pilot. This document and its harness do not
enable a CSP header, create a report collector, change Apache, change Uptime
Kuma, or access production.

## Purpose

ROB-404 and ROB-409 identified inline scripts/styles, analytics, PDF/QR/canvas,
and legacy vendor assets as compatibility risks. ROB-586 provides a repeatable
local measurement step before any production Report-Only decision. The harness
adds the draft policy to locally served responses in Playwright and records
only fixed violation classes and counters.

Run the harness with JSON on stdin, for example:

```bash
printf '%s\n' '{"url":"http://127.0.0.1:8080/booking","surface":"booking"}' \
  | node scripts/ci/csp_compatibility_probe.js
```

For an analytics-enabled local fixture, an optional Matomo origin may be
supplied without a path or credentials:

```bash
printf '%s\n' '{"url":"http://127.0.0.1:8080/booking","surface":"booking","matomo_origin":"https://analytics.example.test"}' \
  | node scripts/ci/csp_compatibility_probe.js
```

`matomo_origin` accepts one complete HTTP or HTTPS origin only. Credentials,
paths, queries, fragments, surrounding whitespace, and non-HTTP schemes fail
closed. Only an exact origin match is classified as `matomo-configured`;
subdomains, different ports, lookalikes, and omitted configuration remain
`unknown-external`. The configured origin is used only during in-memory
classification and is never included in the receipt or an error message.

The URL guard accepts loopback HTTP targets only. The receipt contains no raw
URLs, paths, query strings, fragments, source snippets, headers, bodies,
tokens, cookies, or user-agent values. The policy's `report-uri` points to a
fixed local interception path; the harness answers that path in Playwright
without forwarding or persisting the report body. No browser CSP report is
persisted.
Redirecting fixtures fail closed instead of bypassing the exact local-origin
classifier or omitting the candidate header. Service workers are blocked, and
WebSockets are either confined to that origin or denied. Each run waits for the
page load plus a bounded 500 ms observation window by default;
`observation_ms` may explicitly select 0 through 5000 ms for slower local
fixtures.

## Surfaces and policy boundary

The first local matrix covers App/WWW-equivalent application routes. The
policy is applied only to locally served HTTP responses; it is never added to
Apache or a production response.

| Surface | Local examples | Required evidence |
| --- | --- | --- |
| Booking | public booking and confirmation | page load and PDF/QR path remain usable |
| Account | login and account page | authentication UI remains usable |
| Backoffice | calendar, dashboard, settings | representative page and interaction load |
| Export | representative export/PDF route | renderer-adjacent flow remains usable |

App and WWW share the candidate application policy during this local pilot:
same-origin scripts, styles, images, fonts, and connections are measured, with
the current inline and data allowances retained as compatibility compromises.
Analytics origins remain excluded from this first fixed policy. Google
Analytics traffic is assigned its fixed aggregate class, while an optional
validated Matomo origin can be assigned `matomo-configured` during a safe local
fixture run. These classes measure compatibility only and do not add an
allowlist or permit an external request.

Monitor/Uptime Kuma is a separate surface with a separate policy boundary.
This pilot does not apply a policy to it and does not modify its configuration.
If Monitor is reviewed later, its candidate policy must be designed against
the monitor application and external checks independently, with no reuse of
App/WWW allowances and no production header change in ROB-586.

The candidate policy is intentionally measurement-oriented and retains
`'unsafe-inline'` for scripts and styles while compatibility is measured. It
is not an enforcement policy and is not production-ready.

### Candidate policy boundaries

The App candidate used by this local harness is:

```text
default-src 'self';
base-uri 'self';
object-src 'none';
frame-ancestors 'self';
form-action 'self';
script-src 'self' 'unsafe-inline';
style-src 'self' 'unsafe-inline';
img-src 'self' data:;
font-src 'self' data:;
connect-src 'self';
report-uri /__csp_report_intercepted__;
```

The WWW candidate intentionally matches App for this pilot:

```text
default-src 'self';
base-uri 'self';
object-src 'none';
frame-ancestors 'self';
form-action 'self';
script-src 'self' 'unsafe-inline';
style-src 'self' 'unsafe-inline';
img-src 'self' data:;
font-src 'self' data:;
connect-src 'self';
report-uri /__csp_report_intercepted__;
```

Matching App and WWW keeps the local comparison interpretable while both
surfaces use the same application assets. It does not imply that a future
production header must be identical: analytics, routing, and deployment
configuration still require separate evidence.

Monitor/Uptime Kuma requires a separate conservative candidate and is not run
by this harness:

```text
default-src 'self';
base-uri 'self';
object-src 'none';
frame-ancestors 'self';
script-src 'self';
style-src 'self';
img-src 'self' data:;
font-src 'self' data:;
connect-src 'self';
worker-src 'self';
```

For Monitor, WebSocket (`ws:`/`wss:`), worker, `blob:`, and `data:` allowances
are unresolved until its actual UI and external-check behavior are measured.
The `img-src` and `font-src` data allowances above are placeholders for a
future compatibility review, not production approval. Monitor is excluded
because its external monitoring and service boundary differ from App/WWW and
its candidate policy needs a separate evidence and rollback plan.

## Regression matrix

Implemented in ROB-586 now:

- loopback-only response header injection in Playwright;
- aggregate, class-only violation receipts;
- receipt privacy tests covering raw URLs, paths, query strings, tokens, and
  browser source fields;
- redirect, service-worker, WebSocket, route-failure, and observation-window
  network boundaries;
- the local policy and target guard.

Continued by the production-preparation slice:

- execute the matrix against local App/WWW-equivalent fixtures for each flow;
- combine receipts with existing browser smokes and classify expected versus
  actionable violations;
- use the implemented same-origin class-only target and its bounded owner
  contract;
- obtain separate production approval and complete the ROB-410 live gate.

The required local matrix combines the probe receipt with existing browser
smokes for:

- public booking and manage/cancellation routes;
- booking confirmation PDF/QR generation;
- login and account page;
- backoffice calendar, dashboard, and representative settings;
- export/PDF renderer path;
- analytics disabled and enabled states where a safe local fixture exists;
- desktop and mobile-width core flows.

The JavaScript regression suite includes a real Chromium test when a browser
executable is available. It serves a local page with an intentional external
script violation and verifies a class-only violation plus an intercepted local
report. Environments without a browser skip that one test; the fake-browser
tests still prove header injection, sanitization, interception, and cleanup.

Record `not_tested` with a reason when a state requires secrets or real data.
Do not interpret a clean local result as production evidence. Monitor remains
documentation-only and is not part of the implemented flow evidence.

## Stop and rollback boundary

Stop before production if the policy needs raw report storage, a reporting
endpoint, real appointment URLs, secrets, or PII; if a flow cannot be covered;
or if the policy requires an unreviewed inline or external-origin exception.

This local pilot has no production rollback. A future production Report-Only
change requires a separate approval, a privacy-safe collector or equivalent
pre-persistence redaction, Apache configtest/reload, post-change validation,
and an explicit removal path. CSP enforcement remains a later decision.

## Result and follow-up

The next implementation slice is specified in
[the production Report-Only preparation](ROB-586-production-csp-report-only-plan.md).
It owns the privacy-safe same-origin collector, exact App/WWW matrix, separate
Monitor decision, observation limits, and rollback contract. Its merge still
does not activate a production header or run a production probe.
