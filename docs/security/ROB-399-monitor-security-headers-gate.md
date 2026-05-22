# ROB-399 Monitor Security Headers Gate

Status: prepared, live write not executed.

Scope: add a minimal baseline browser-hardening header set to the production
Monitor HTTPS surface. This gate intentionally excludes HSTS, CSP, App/WWW
headers, SSH, firewall, Certbot, Kuma data changes, Sentry, deploy, package
updates, and service restarts.

## Baseline Evidence

Source: `bash scripts/ops/prod_doctor.sh`, read-only snapshot
`2026-05-21T19:41:26Z`, after ROB-398 completed.

- Monitor HTTPS returned the expected status class.
- Uptime Kuma reported 13 active monitors and 13 latest green.
- Apache, PHP-FPM, MariaDB, Docker, fail2ban, cron, unattended-upgrades, and
  `fh-pdf-renderer` were active.
- No recent Apache/PHP-FPM/service warning class or app-error-like log class was
  reported.
- Monitor HTTPS header posture:
  - `x_frame_options=present`
  - `referrer_policy=missing`
  - `permissions_policy=missing`
  - `x_content_type_options=missing`
  - `hsts=missing` and `csp=missing`, expected to remain out of scope.
- App and `www` already had the ROB-398 baseline headers and are not part of
  this gate.

Source: `bash scripts/ops/prod_validate_after_change.sh`, read-only snapshot
`2026-05-21T19:40Z`.

- App, `www`, monitor redirect, renderer, deep health, services, containers,
  Kuma, host Node policy, certbot timer, and log classes passed.
- `validation=passed`.

## Header Set

The live gate may add only these headers to Monitor HTTPS responses:

```apache
Header always set X-Content-Type-Options "nosniff"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
Header always set Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=(), usb=(), accelerometer=(), gyroscope=(), magnetometer=()"
```

The existing Monitor `X-Frame-Options` header must be preserved, not replaced by
this gate.

Rationale:

- `X-Content-Type-Options: nosniff` is a low-risk baseline against MIME sniffing.
- `Referrer-Policy: strict-origin-when-cross-origin` reduces cross-origin
  leakage while preserving normal same-origin behavior.
- `Permissions-Policy` denies browser capabilities that the monitoring surface
  does not need.
- HSTS and CSP remain separate decisions because they have broader compatibility
  and recovery implications.

## Preconditions

Before any live write:

- A separate operator approval explicitly authorizes ROB-399 live Apache Monitor
  header changes, configtest, reload, and post-change validation.
- The current production baseline still matches the documented server shape.
- `prod_validate_after_change.sh` passes before the change, including Kuma 13/13
  latest green.
- `prod_doctor.sh` confirms Monitor HTTPS is reachable and App/WWW remain
  healthy.
- `apache.headers_module=present`.
- `apache2ctl configtest` is already clean before editing.
- The Monitor HTTPS vhost scope can be identified as separate from App/WWW
  without printing raw vhost config or secret-bearing files.
- Rollback is clear before editing.

## Live Procedure

Do not execute this section without the separate approval above.

1. Capture read-only baseline:
   - `bash scripts/ops/prod_validate_after_change.sh`
   - `bash scripts/ops/prod_doctor.sh`
2. Run a focused sanitized Apache prerequisite check for:
   - `apache.headers_module=present`
   - `apache.configtest=ok`
   - Monitor HTTPS scope separated from App/WWW scope
3. Edit only the Monitor HTTPS Apache scope needed for the three baseline
   headers above.
4. Preserve the existing Monitor `X-Frame-Options` behavior.
5. Run `apache2ctl configtest`.
6. Stop without reload if configtest fails.
7. If configtest passes, reload Apache only:
   - `systemctl reload apache2`
8. Run post-change validation:
   - `bash scripts/ops/prod_validate_after_change.sh`
   - `bash scripts/ops/prod_doctor.sh`
9. Record only status classes, header presence classes, and validation outcome.

## Expected Post-Change Evidence

Expected `prod_doctor.sh` posture classes:

- Monitor HTTPS:
  - `posture_header.monitor_https.x_frame_options=present`
  - `posture_header.monitor_https.referrer_policy=present`
  - `posture_header.monitor_https.permissions_policy=present`
  - `posture_header.monitor_https.x_content_type_options=present`
- Monitor HSTS and CSP may remain `missing`.
- App and `www` header posture should not regress from the ROB-398 baseline.

Expected health classes:

- Monitor HTTPS returns the expected redirect/status class.
- App HTTPS, `www` HTTPS, deep health, and renderer remain healthy.
- Uptime Kuma remains 13 active monitors and 13 latest green.
- No new app-error-like log class.
- No new Apache/PHP-FPM warning class.
- Sensitive path validation remains blocked/non-2xx.

## Stop Conditions

Stop and do not reload Apache if:

- The change would touch HSTS, CSP, App/WWW headers, SSH, firewall, Certbot,
  Kuma data, Sentry, deployment, packages, PHP runtime, database, or service
  restart behavior.
- `apache.headers_module` is missing.
- Pre-change `apache2ctl configtest` is not clean.
- Pre-change `prod_validate_after_change.sh` is not clean.
- The Monitor HTTPS scope cannot be identified separately from App/WWW without
  raw config dumps.
- The existing Monitor `X-Frame-Options` behavior would be removed or weakened.
- The rollback path is unclear.
- Editing would require printing raw vhost config, `/etc/fh`, tokens,
  passwords, DSNs, health tokens, Push URLs, DB rows, session/cache contents, or
  Kuma data.

Stop and roll back if after reload:

- Monitor access, Kuma latest green count, App health, `www`, deep health, or
  renderer health fails.
- Apache, PHP-FPM, or application logs show new error classes after the change.
- Header posture is partially applied to Monitor in a way that cannot be
  explained by caching or redirect behavior.
- App/WWW header posture regresses from the ROB-398 baseline.

## Rollback

Rollback must be available before live edit:

1. Revert only the ROB-399 header lines from the Monitor HTTPS Apache scope.
2. Preserve the pre-existing Monitor `X-Frame-Options` behavior.
3. Run `apache2ctl configtest`.
4. If configtest passes, run `systemctl reload apache2`.
5. Run `bash scripts/ops/prod_validate_after_change.sh`.
6. Run `bash scripts/ops/prod_doctor.sh`.
7. Record only class/flag evidence.

## Evidence Boundary

Allowed in chat, Linear, PR, and docs:

- Header presence classes.
- Health status classes.
- Kuma active/latest-green counts only.
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
