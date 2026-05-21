# ROB-398 App/WWW Security Headers Gate

Status: prepared, live write not executed.

Scope: add a minimal baseline browser-hardening header set to the production
App and `www` HTTPS surfaces. This gate intentionally excludes HSTS, CSP,
Monitor headers, SSH, firewall, Certbot, Kuma, Sentry, deploy, package updates,
and service restarts.

## Baseline Evidence

Source: `bash scripts/ops/prod_doctor.sh`, read-only snapshot
`2026-05-21T18:14:54Z`.

- App HTTPS and `www` HTTPS returned healthy status classes.
- App HTTPS header posture:
  - `x_frame_options=missing`
  - `referrer_policy=missing`
  - `permissions_policy=missing`
  - `x_content_type_options=missing`
  - `hsts=missing` and `csp=missing`, expected to remain out of scope.
- `www` HTTPS header posture:
  - `x_frame_options=missing`
  - `referrer_policy=missing`
  - `permissions_policy=missing`
  - `x_content_type_options=missing`
  - `hsts=missing` and `csp=missing`, expected to remain out of scope.
- No unexpected public listener class was reported.
- No recent service warning class or app-error-like log class was reported.
- `sensitive_path_check=helper_missing` was reported before the validator was
  updated to stream the local sensitive-path helper. This was a known
  validation-harness gap, not an App/WWW header finding.

Source: focused sanitized read-only Apache prerequisite check,
`2026-05-21T18:15Z`.

- `apache.headers_module=present`
- `apache.configtest=ok`

Source: `bash scripts/ops/prod_validate_after_change.sh`, read-only snapshot
`2026-05-21T18:39Z`, after the validator was updated to stream the local
sensitive-path helper.

- Sensitive path validation ran without a `helper_missing` class.
- All fixed sensitive path classes returned non-2xx status classes.
- `validation=passed`.

## Header Set

The live gate may add only these headers to App and `www` HTTPS responses:

```apache
Header always set X-Content-Type-Options "nosniff"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
Header always set Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=(), usb=(), accelerometer=(), gyroscope=(), magnetometer=()"
Header always set X-Frame-Options "SAMEORIGIN"
```

Rationale:

- `X-Content-Type-Options: nosniff` is a low-risk baseline against MIME sniffing.
- `Referrer-Policy: strict-origin-when-cross-origin` reduces cross-origin
  leakage while preserving normal same-origin behavior.
- `Permissions-Policy` denies unused browser capabilities for this scheduling
  app without affecting booking, login, calendar, or PDF flows.
- `X-Frame-Options: SAMEORIGIN` blocks third-party framing while preserving
  same-origin admin/app behavior.

## Preconditions

Before any live write:

- A separate operator approval explicitly authorizes ROB-398 live Apache header
  changes, configtest, reload, and post-change validation.
- The current production baseline still matches the documented server shape.
- App, `www`, deep health, renderer, Apache, PHP-FPM, MariaDB, Docker,
  fail2ban, cron, and `fh-pdf-renderer` are healthy by redacted harness output.
- `apache.headers_module=present`.
- `apache2ctl configtest` is already clean before editing.
- `prod_validate_after_change.sh` can run without a known unrelated
  `helper_missing` failure because it streams the local sensitive-path helper
  over SSH.
- The target Apache include/vhost path can be identified without printing raw
  vhost config or secret-bearing files.
- Rollback is clear before editing.

## Live Procedure

Do not execute this section without the separate approval above.

1. Capture read-only baseline:
   - `bash scripts/ops/prod_doctor.sh`
   - Optional focused sanitized Apache prerequisite check for module/configtest
     classes.
2. Edit only the App/WWW Apache scope needed for the four baseline headers.
3. Run `apache2ctl configtest`.
4. Stop without reload if configtest fails.
5. If configtest passes, reload Apache only:
   - `systemctl reload apache2`
6. Run post-change validation:
   - `bash scripts/ops/prod_validate_after_change.sh`
   - `bash scripts/ops/prod_doctor.sh`
7. Record only status classes, header presence classes, and validation outcome.

## Expected Post-Change Evidence

Expected `prod_doctor.sh` posture classes:

- App HTTPS:
  - `posture_header.app_https.x_frame_options=present`
  - `posture_header.app_https.referrer_policy=present`
  - `posture_header.app_https.permissions_policy=present`
  - `posture_header.app_https.x_content_type_options=present`
- `www` HTTPS:
  - `posture_header.www_https.x_frame_options=present`
  - `posture_header.www_https.referrer_policy=present`
  - `posture_header.www_https.permissions_policy=present`
  - `posture_header.www_https.x_content_type_options=present`
- HSTS and CSP may remain `missing`.
- Monitor header posture is not evaluated as a ROB-398 success criterion.

Expected health classes:

- App HTTPS healthy.
- `www` HTTPS healthy.
- Deep health healthy.
- Renderer healthy.
- No new app-error-like log class.
- No new Apache/PHP-FPM warning class.
- Sensitive path validation remains blocked/non-2xx after the helper is present
  on production.

## Stop Conditions

Stop and do not reload Apache if:

- The change would touch HSTS, CSP, Monitor headers, SSH, firewall, Certbot,
  Kuma, Sentry, deployment, packages, PHP runtime, database, or service restart
  behavior.
- `apache.headers_module` is missing.
- Pre-change `apache2ctl configtest` is not clean.
- `prod_validate_after_change.sh` has a known unrelated failure before the
  Apache change.
- The target Apache scope cannot be identified without raw config dumps.
- The rollback path is unclear.
- Editing would require printing raw vhost config, `/etc/fh`, tokens, passwords,
  DSNs, health tokens, Push URLs, DB rows, session/cache contents, or Kuma data.

Stop and roll back if after reload:

- Login, public booking, admin UI, FullCalendar, cancellation/reschedule,
  dashboard PDF, renderer health, App health, `www`, or deep health fails.
- Apache, PHP-FPM, or application logs show new error classes after the change.
- Header posture is partially applied to App/WWW in a way that cannot be
  explained by caching or redirect behavior.

## Rollback

Rollback must be available before live edit:

1. Revert only the ROB-398 header lines from the Apache scope.
2. Run `apache2ctl configtest`.
3. If configtest passes, run `systemctl reload apache2`.
4. Run `bash scripts/ops/prod_validate_after_change.sh`.
5. Run `bash scripts/ops/prod_doctor.sh`.
6. Record only class/flag evidence.

## Evidence Boundary

Allowed in chat, Linear, PR, and docs:

- Header presence classes.
- Health status classes.
- `apache.headers_module=present|missing`.
- `apache.configtest=ok|failed`.
- `prod_validate_after_change.sh` pass/fail outcome.
- Sanitized service/log warning counts.

Not allowed:

- Raw Apache vhost config.
- Secret-bearing file contents.
- Tokens, passwords, DSNs, Push URLs, health-token values.
- DB rows, session/cache contents, Kuma DB rows.
- Discovered filenames from sensitive paths.
