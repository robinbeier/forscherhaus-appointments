# ROB-404 CSP Compatibility Spike

Status: repo-only/docs-only compatibility decision for a future Content Security
Policy path. This document does not enable CSP, does not add Apache
configuration, and does not change production.

Scope: evaluate whether Forscherhaus Appointments can safely move toward
`Content-Security-Policy` or `Content-Security-Policy-Report-Only` after the
baseline header work from ROB-398, ROB-399, and ROB-407.

## Executive Summary

ROB-404 should not enable CSP enforcement now. The current application has
legitimate CSP compatibility blockers across booking, backoffice, export/PDF,
analytics, and shared layout surfaces:

- inline scripts are used for server-to-client data bridges and selected page
  behavior;
- inline styles are common in views and PDF/export templates;
- optional analytics integrations inject dynamic external script behavior;
- booking confirmation uses browser-side PDF/QR/canvas flows that rely on image
  data generation;
- the UI depends on multiple legacy/vendor libraries whose CSP requirements
  should be measured before enforcement.

Decision:

- `Content-Security-Policy` enforcement: **defer**.
- `Content-Security-Policy-Report-Only`: **prepare only as a separate later
  gate**.
- Immediate ROB-404 output: document compatibility risks, prerequisites, and
  the shape of a safe later report-only pilot.

## Evidence

Local static inventory, no production access:

- `rg "<script"` found script tags in 43 non-email view files.
- `rg "<style|style="` found inline style usage in 26 non-email view files.
- `application/views` currently contains 85 view files.
- Confirmed high-signal CSP-sensitive files include:
  - `application/views/components/js_vars_script.php`
  - `application/views/components/js_lang_script.php`
  - `application/views/components/jquery_compat_inline.php`
  - `application/views/components/google_analytics_script.php`
  - `application/views/components/matomo_analytics_script.php`
  - `application/views/components/company_color_style.php`
  - `application/views/pages/booking_confirmation.php`
  - `application/views/layouts/booking_layout.php`
  - `application/views/layouts/backend_layout.php`
  - `application/views/layouts/account_layout.php`
  - `application/views/layouts/message_layout.php`
  - `application/views/exports/dashboard_principal_pdf.php`
  - `application/views/exports/dashboard_teacher_pdf.php`
  - `application/views/exports/provider_parent_appointments_pdf.php`

No raw production config, secrets, tokens, logs, database rows, Kuma data, or
Apache vhost dumps were read for ROB-404.

## Surface Inventory

### Booking Surface

Relevant files:

- `application/views/layouts/booking_layout.php`
- `application/views/pages/booking.php`
- `application/views/pages/booking_confirmation.php`
- `application/views/pages/booking_cancellation.php`
- `application/views/pages/booking_message.php`
- `application/views/components/booking_*`

CSP considerations:

- booking layout loads local vendor scripts such as jQuery, Bootstrap, Moment,
  CookieConsent, Tippy, Flatpickr, and Font Awesome;
- `booking_confirmation.php` contains inline style and inline script blocks;
- booking confirmation uses `html2canvas`, `jspdf`, and QR code generation,
  including `QRCode.toDataURL` and `canvas.toDataURL`;
- optional Google Analytics and Matomo scripts can be included on booking
  pages.

Risk: a strict `script-src 'self'` or `style-src 'self'` policy would likely
break booking confirmation and analytics unless inline/data/script exceptions
are designed first.

### Backoffice Surface

Relevant files:

- `application/views/layouts/backend_layout.php`
- `application/views/pages/calendar.php`
- `application/views/pages/dashboard.php`
- `application/views/pages/dashboard_teacher.php`
- `application/views/pages/providers.php`
- `application/views/pages/business_settings.php`
- `application/views/pages/ldap_settings.php`

CSP considerations:

- the backend layout loads local vendor scripts including jQuery, Bootstrap,
  Moment, Tippy, Trumbowyg, Select2, Flatpickr, and Font Awesome;
- calendar pages load FullCalendar and FullCalendar Moment bundles;
- dashboard pages load Chart.js and chartjs-chart-matrix;
- several pages use inline `style=` for hidden controls, progress bars, and
  dynamic layout state;
- `ldap_settings.js` renders JSON into a template string for operator display;
  this is not itself a CSP blocker, but it is a relevant UI behavior to keep in
  a report-only pilot test matrix.

Risk: CSP enforcement without real browser coverage could break calendar,
settings, dashboard, modal, or rich-text workflows.

### Account, Login, and Message Surfaces

Relevant files:

- `application/views/layouts/account_layout.php`
- `application/views/layouts/message_layout.php`
- `application/views/pages/login.php`
- `application/views/pages/account.php`

CSP considerations:

- these surfaces load shared local vendor and app scripts;
- account/login flows are high-sensitivity flows where broken script execution
  could block staff access or recovery workflows.

Risk: report-only can be piloted here, but enforcement should wait until login
and account smokes explicitly cover CSP behavior.

### Server-to-Client Data Bridges

Relevant files:

- `application/views/components/js_vars_script.php`
- `application/views/components/js_lang_script.php`
- `application/views/components/jquery_compat_inline.php`

CSP considerations:

- these are intentional inline script bridges from PHP-rendered state to the
  browser runtime;
- a nonce- or hash-based CSP would need a reusable framework-level pattern
  before enforcement.

Risk: allowing `'unsafe-inline'` for script would reduce CSP value; removing it
requires non-trivial template and layout work.

### Analytics

Relevant files:

- `application/views/components/google_analytics_script.php`
- `application/views/components/matomo_analytics_script.php`
- `application/views/pages/google_analytics_settings.php`
- `application/views/pages/matomo_analytics_settings.php`

CSP considerations:

- Google Analytics may load `https://www.googletagmanager.com`;
- Matomo uses an operator-configured analytics URL and script endpoint;
- Matomo includes a noscript image fallback.

Risk: a useful CSP would need explicit policy branches for enabled and disabled
analytics states. Operator-configured Matomo makes a static allowlist harder.

### Export, PDF, and Renderer-Adjacent Views

Relevant files:

- `application/views/exports/dashboard_principal_pdf.php`
- `application/views/exports/dashboard_teacher_pdf.php`
- `application/views/exports/provider_parent_appointments_pdf.php`
- `application/views/pages/booking_confirmation.php`

CSP considerations:

- export templates include inline styles and minimal inline script readiness
  markers;
- booking confirmation generates client-side PDF artifacts from canvas/images;
- server-side renderer behavior should be treated as a separate compatibility
  check because PDF rendering may not behave like a normal browser tab.

Risk: PDF/export flows are user-visible and should be in the report-only pilot
test plan before enforcement.

## Compatibility Risk Matrix

| Area | Risk if CSP enforced now | Why |
| --- | --- | --- |
| Inline script bridges | High | App state and translations are emitted through inline scripts. |
| Inline styles | High | Many views use inline styles for visibility, layout, progress, and exports. |
| Booking confirmation PDF | High | QR/canvas/PDF generation uses data URL and canvas behavior. |
| Analytics | Medium | Google and operator-configured Matomo need explicit allowances. |
| Local vendor bundles | Medium | Legacy libraries may need `style-src`, `img-src`, `font-src`, or worker/data allowances. |
| Login/account | Medium | Breakage impact is high even if the technical surface is smaller. |
| Monitor surface | Low for app CSP | Monitor is not the application and should keep its own header gate. |

## Report-Only Pilot Shape

A later issue may prepare a `Content-Security-Policy-Report-Only` gate. That
gate should not enforce blocking behavior. It should collect enough signal to
decide whether CSP is worth tightening.

Recommended pilot properties:

- apply to App and `www` only first; keep Monitor separate;
- start with report-only mode, not enforcement;
- define a reporting target before adding the header;
- classify expected noise before changing production;
- test public booking, booking confirmation PDF, login, calendar, dashboard,
  settings, exports, Google Analytics disabled/enabled, and Matomo
  disabled/enabled where practical;
- keep any production write behind a separate live approval, Apache configtest,
  reload, `prod_validate_after_change.sh`, and `prod_doctor.sh`.

Candidate policy direction for a later design document only:

```text
default-src 'self';
base-uri 'self';
object-src 'none';
frame-ancestors 'self';
script-src 'self' <measured analytics allowances>;
style-src 'self' <measured inline strategy>;
img-src 'self' data: <measured analytics allowances>;
font-src 'self' data:;
connect-src 'self' <measured analytics/reporting allowances>;
form-action 'self';
```

This is intentionally not ready for production. The unresolved parts are the
inline strategy, analytics allowlists, and PDF/canvas compatibility.

## Stop Conditions For A Future CSP Gate

Stop before any live write if:

- the policy requires `'unsafe-inline'` for scripts without a documented reason
  and a path to reduce it;
- report collection would expose PII, URLs containing appointment hashes, API
  tokens, health tokens, Push URLs, session data, or raw request bodies;
- no owner is defined for triaging report-only noise;
- public booking, login, calendar, dashboard, booking confirmation PDF, and
  export flows are not covered by smoke tests or manual validation;
- analytics behavior is unknown but analytics is enabled in the target
  environment;
- the change would touch Apache, Kuma, Sentry, UFW, SSH, DB, deploy, packages,
  or service runtime outside a separately approved live gate.

Stop after a report-only pilot if:

- report volume is dominated by unclassified noise;
- reports include sensitive data beyond safe classes;
- key workflows produce repeatable violations;
- the only viable policy still requires broad `script-src 'unsafe-inline'`
  without nonces/hashes or a bridge refactor path.

## Recommendation

Close ROB-404 with this decision:

- CSP enforcement remains deferred.
- The next useful step is a separate issue for a CSP Report-Only pilot design.
- That follow-up should decide reporting infrastructure, privacy boundaries,
  pilot surfaces, and a temporary report-only policy before any production
  header is added.

Suggested follow-up title:

- `Prepare CSP Report-Only pilot gate`

Suggested follow-up scope:

- design report-only policy and reporting target;
- decide whether reports go to existing observability or a minimal dedicated
  endpoint;
- document privacy constraints for CSP reports;
- define smoke matrix and success criteria;
- prepare a separate Apache live gate, but do not execute it without explicit
  production approval.

## Non-Goals Confirmed

ROB-404 does not:

- enable `Content-Security-Policy`;
- enable `Content-Security-Policy-Report-Only`;
- edit Apache configuration;
- change layouts, templates, JavaScript, vendor assets, analytics behavior, PDF
  behavior, Sentry, Kuma, UFW, SSH, DB, deploy, packages, or production
  services.
