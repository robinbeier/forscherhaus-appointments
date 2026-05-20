# Sentry Ingestion Enablement Gate - 2026-05-20

Purpose: enable and verify production Sentry ingestion after ROB-383 without
printing or committing DSNs, auth tokens, raw event payloads, customer data, or
host-local configuration contents.

## Scope

Allowed writes:

- deploy current `origin/main` through the normal artifact deploy path;
- write host-local Sentry runtime environment values from local secret files;
- reload PHP-FPM and Apache as needed for runtime env pickup;
- send exactly one synthetic Sentry smoke event with
  `SENTRY_SMOKE_SEND=1`;
- update this gate protocol with redacted results.

Out of scope:

- Uptime Kuma monitor edits;
- Sentry alert/configuration changes;
- database writes outside the existing deploy/release gates;
- exposing `SENTRY_DSN`, `SENTRY_AUTH_TOKEN`, Push URLs, health tokens, DB rows,
  event payloads, stack traces, raw request URLs, appointment hashes, or
  customer data.

## Planned Sequence

1. Refresh `origin/main` and work from a clean branch.
2. Build and upload a release artifact with `build_release.sh`.
3. Deploy on the production host with `/root/deploy_ea.sh`,
   `--renderer-deploy-mode external`, deep health, renderer health, and
   zero-surprise gates.
4. Host-locally set `SENTRY_DSN` and `APP_ENV=production`.
5. Reload PHP-FPM and Apache.
6. Run `php scripts/ops/sentry_smoke.php` as dry-run.
7. Run exactly one `SENTRY_SMOKE_SEND=1 php scripts/ops/sentry_smoke.php`.
8. Read Sentry via API and verify the smoke event:
   - environment is `production`;
   - release is present;
   - tags include `area=sentry_smoke` and `operation=delivery_smoke`;
   - no obvious DSN, token, email, IP, raw query-secret, appointment hash, or
     customer-data pattern is visible in the sampled event.
9. Run production health/Kuma post-checks.

## Stop Conditions

Stop before continuing if:

- deploy build, upload, predeploy, switch, renderer health, deep health, or
  zero-surprise canary fails;
- the Sentry DSN secret file is missing, empty, or URL-shaped incorrectly;
- `scripts/ops/sentry_smoke.php` is missing after deploy;
- dry-run does not return the expected disabled-send message;
- the smoke command would need to expose DSN/token/config contents;
- Sentry API cannot find the synthetic event after send;
- event inspection shows obvious secrets, customer data, or bearer-like raw
  values;
- post-change production health or Kuma latest status is red.

## Execution Log

Status: completed.

- 2026-05-20: gate approved by operator for deploy, host-local Sentry runtime
  env write, reload, one synthetic smoke event, Sentry read-only verification,
  and production health/Kuma post-checks.
- 2026-05-20: built and uploaded release
  `ea_sentry_gate_20260520_1641`; local and remote archive SHA-256 matched:
  `b14073e01d67cc88dc20826236265dc13f8d22e3f22fc293a57448306b853f60`.
- 2026-05-20: deployed `ea_sentry_gate_20260520_1641` with
  `--renderer-deploy-mode external`.
  - Zero-surprise predeploy replay passed.
  - Zero-surprise report validation passed.
  - Renderer health passed after one initial pending probe.
  - Deep health contract passed.
  - Zero-surprise live canary passed.
  - Deployment completed at `/var/www/html/easyappointments`.
- 2026-05-20: wrote host-local Sentry runtime env from the operator-provided
  local DSN secret file.
  - `SENTRY_DSN` present in `/etc/fh/php-fpm-runtime.env`.
  - `APP_ENV=production` present in `/etc/fh/php-fpm-runtime.env`.
  - PHP-FPM and Apache reloads completed.
  - No DSN value was printed or copied into Git, Linear, or chat.
- 2026-05-20: smoke dry-run returned the expected disabled-send message:
  `DRY_RUN sentry_smoke=ready send=disabled enable_with=SENTRY_SMOKE_SEND=1`.
- 2026-05-20: exactly one synthetic smoke event was sent:
  `OK sentry_smoke=sent synthetic_event=1`.
- 2026-05-20: Sentry read-only verification passed.
  - Smoke issue: `FORSCHERHAUS-APPOINTMENTS-PROD-9`.
  - Event count for the smoke issue: `1`.
  - Project environment: `production`.
  - Event has Sentry tag `environment=production`.
  - Event has tag `area=sentry_smoke`.
  - Event has tag `operation=delivery_smoke`.
  - Event release is present.
  - Event context keys were limited to SDK/runtime-style context:
    `os`, `runtime`, and `trace`.
  - No DSN-like, authorization-like, bearer-like, raw query-token, or
    synthetic placeholder secret value was visible in the sampled event detail.
  - Sentry user fields `email`, `ip_address`, `name`, `username`, and `id`
    were empty in the sampled event detail.
  - One automated `ip-like` value hit was a false positive on a package/version
    path, not a user or request field.
- 2026-05-20: production post-change validation passed.
  - App HTTPS: `200`.
  - `www` HTTPS: `200`.
  - Monitor endpoint: `302`.
  - PDF renderer HTTP: `200`.
  - Deep health: `200`.
  - Apache, PHP 8.5 FPM, MariaDB, Docker, fail2ban, cron,
    unattended-upgrades, and `fh-pdf-renderer` active.
  - Uptime Kuma latest status: `13` active monitors, `13` green.
  - Recent warning counts for Apache, PHP-FPM, MariaDB, PDF renderer, Docker,
    and cron were `0`.
  - App error-like lines over 24h were `0`.

## Follow-Up

- Keep the synthetic smoke issue available as delivery evidence unless it
  becomes noisy; resolving it in Sentry is an operator action outside this
  repo-only protocol.
- Sentry alerts were not changed in this gate. Alert rules remain a separate
  Sentry configuration decision.
