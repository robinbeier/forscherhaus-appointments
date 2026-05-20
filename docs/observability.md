# Observability

Purpose: define the runtime ownership between release gates, Uptime Kuma, and
Sentry without turning top-level docs into an operations runbook.

## System Boundaries

- Release gates are the executable truth for deploy safety.
- Uptime Kuma provides outside-in availability and push-monitor coverage.
- Sentry captures application errors and traces from the real PHP request path.

These layers complement each other. They do not replace each other.

## Runtime Matrix

PDF renderer endpoint resolution:

- Docker-internal PHP runtime: `http://pdf-renderer:3000`
- Host PHP runtime: `http://127.0.0.1:3003`
- Apache `mod_php`: prefer explicit `SetEnv PDF_RENDERER_URL "http://127.0.0.1:3003"`

Sentry runtime configuration:

- For PHP-FPM request paths, `env[...]` pool config is sufficient.
- For Apache `mod_php` request paths, configure `SENTRY_*` via Apache `SetEnv`.
- If the application is served by Apache `mod_php`, Apache is the canonical
  runtime source for Sentry and `PDF_RENDERER_URL`.
- `SentryBootstrap` installs a `before_send` scrubber and also scrubs explicit
  extras passed through `SentryBootstrap::captureException()`. Keep this as the
  central redaction boundary before adding new capture sites.

Sentry data policy:

- Do send stable tags such as `area`, `operation`, `export_type`, release, and
  environment.
- Do not send raw appointment hashes, recovery tokens, Push URLs, DSNs, request
  bodies, authorization headers, customer emails, phone numbers, or names.
- For bearer-like values needed for correlation, use boolean presence flags or
  non-reversible short digests from `SentryBootstrap::safeDigest()`.
- PDF renderer failures should send endpoint categories such as `loopback`,
  `docker_dns`, or `configured`, not full renderer URLs.

Sentry delivery smoke:

- `php scripts/ops/sentry_smoke.php` is dry-run by default and prints no DSN,
  token, event payload, or server config.
- To send the synthetic event from a host with Sentry env already configured,
  explicitly set `SENTRY_SMOKE_SEND=1`.
- The smoke event uses `area=sentry_smoke` and `operation=delivery_smoke`; it
  must stay synthetic and must not include production customer/request data.

## Deploy Observability Model

Use the deploy layers in this order:

1. zero-surprise predeploy replay against a fresh dump
2. atomic switch
3. renderer health
4. deep health
5. zero-surprise live canary
6. resume Uptime Kuma monitors

Operational rule:

- Put Uptime Kuma into maintenance only for the real deploy window.
- Resume Kuma only after post-deploy health and canary checks pass.

## Monitoring Responsibilities

Use Uptime Kuma for:

- homepage and health endpoints
- renderer health
- push-monitored app/php-fpm/pdf-renderer log checks
- synthetic PDF export probes
- cron or backup freshness signals

Do not add a parent booking-confirmation PDF live synthetic until the criteria
in
[Parent Booking Confirmation PDF Synthetic Decision](monitoring/parent-confirmation-pdf-synthetic-decision.md)
are met. The existing booking confirmation PDF release gate requires a
confirmation hash or URL and is not, by itself, a safe continuous Kuma monitor.

Health endpoint boundaries:

- `/health` is public shallow health and should not require a secret.
- `/index.php/healthz` is token-protected deep health and must be queried with
  the `X-Health-Token` header from Kuma or host-local config.
- The deep-health token is a bearer-like operational secret. Do not print it,
  paste it into Linear/chat, store it in desired-state YAML, or include it in
  command examples. Use `<redacted>` when documenting probes.
- If a deep-health monitor fails with `401`, treat that as a header/config
  boundary issue, not an app dependency outage, until proven otherwise.

Use Sentry for:

- uncaught PHP exceptions
- selected caught exceptions on critical request paths
- release-correlated application failures such as PDF renderer errors

Do not use Sentry for expected business outcomes such as validation failures,
invalid logins, CAPTCHA failures, booking conflicts, unauthorized health probes,
scanner 404s, or availability checks. Those remain normal HTTP responses,
Kuma/ops signals, or log-only observations depending on the case.

## Anti-Drift Rule

Keep host-local runtime files out of the repository:

- `config.php`
- Apache site-specific env files
- `/etc/fh/*.ini`
- root crontab entries
- Uptime Kuma database state

The reproducible Kuma target state is documented in `docs/uptime-kuma.md`.

Document the interface and required variables in the repo. Keep machine- or
host-specific values on the host.
