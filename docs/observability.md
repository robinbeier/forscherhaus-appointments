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

Use Sentry for:

- uncaught PHP exceptions
- selected caught exceptions on critical request paths
- release-correlated application failures such as PDF renderer errors

## Anti-Drift Rule

Keep host-local runtime files out of the repository:

- `config.php`
- Apache site-specific env files
- `/etc/fh/*.ini`
- root crontab entries
- Uptime Kuma database state

Document the interface and required variables in the repo. Keep machine- or
host-specific values on the host.
