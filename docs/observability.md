# Observability

Purpose: define the runtime ownership between release gates and Uptime Kuma
without turning top-level docs into an operations runbook.

The repository retirement of Sentry becomes effective with the deployment that
contains the corresponding application changes. Until then, the live
production Sentry project, events, and host configuration are unchanged. This
document describes the post-deployment repository contract.

## System Boundaries

- Release gates are the executable truth for deploy safety.
- Uptime Kuma provides outside-in availability and push-monitor coverage.
- Application logs and release gates provide the repository-owned error and
  regression signals.

These layers complement each other. They do not replace each other.

## Runtime Matrix

PDF renderer endpoint resolution:

- Docker-internal PHP runtime: `http://pdf-renderer:3000`
- Host PHP runtime: `http://127.0.0.1:3003`
- Apache `mod_php`: prefer explicit `SetEnv PDF_RENDERER_URL "http://127.0.0.1:3003"`

Error and diagnostic data policy:

- Keep raw request bodies, authorization headers, customer contact data,
  appointment hashes, recovery tokens, Push URLs, and database values out of
  logs, diagnostics, and monitor messages.
- Use stable classifications and short non-reversible digests only when
  correlation is required.
- PDF renderer failures continue through ordinary application logging and
  release-gate checks; this document defines no separate endpoint
  categorization channel.

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

Use application logs and release gates for unexpected PHP exceptions, critical
request-path failures, and release-correlated regressions such as PDF renderer
errors. Expected validation failures, invalid logins, CAPTCHA failures,
booking conflicts, unauthorized health probes, scanner 404s, and availability
checks remain normal HTTP responses, Kuma/ops signals, or log-only observations
depending on the case.

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
