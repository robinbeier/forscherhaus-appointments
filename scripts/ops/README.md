# Ops Monitoring Scripts

These scripts mirror and extend the current production Uptime Kuma setup without
storing Push secrets in the repository.

Current production monitor names are documented in `docs/uptime-kuma.md` and
mirrored in `scripts/ops/uptime-kuma.monitors.yml`.

For agent-first production diagnostics and post-change validation, start with
`docs/ops/agent-operations.md` and the `prod_*.sh` scripts in this directory.

Use `scripts/ops/uptime-kuma-push.env.example` as the host-local env template
and `scripts/ops/uptime-kuma-crontab.example` as the cron template.

Script inventory:

- `kuma_push_app_logs.sh` monitors newly appended application log errors
- `kuma_push_host_services.sh` monitors critical systemd services
- `kuma_push_host_resources.sh` monitors disk, memory, and load thresholds
- `kuma_push_ops_jobs.sh` monitors backup/job freshness
- `kuma_push_php_fpm_logs.sh` monitors recent PHP-FPM journal errors
- `kuma_push_pdf_renderer_logs.sh` monitors recent `fh-pdf-renderer` journal errors
- `kuma_push_pdf_export.sh` runs the dashboard PDF release gate as a synthetic smoke
- `kuma_push_apache_scanner_activity.sh` watches recent Apache access logs for common scanner probes
- `lib/kuma_push_common.sh` provides shared env, curl, and log helpers
- `prod_doctor.sh` prints redacted read-only production status
- `prod_logs_summary.sh` prints redacted recent production log summaries
- `prod_validate_after_change.sh` runs the standard post-change production gate
- `install_prod_agent_readme.sh` installs the server-local agent orientation file in explicit execute mode

Default env file:

- `/root/backups/uptime-kuma-push.env`

Required new Push URLs:

- `KUMA_PUSH_URL_HOST_SERVICES`
- `KUMA_PUSH_URL_HOST_RESOURCES`
- `KUMA_PUSH_URL_OPS_JOBS`
- `KUMA_PUSH_URL_APP_LOGS`
- `KUMA_PUSH_URL_PHP_FPM_LOGS`
- `KUMA_PUSH_URL_PDF_RENDERER_LOGS`
- `KUMA_PUSH_URL_PDF_EXPORT`
- `KUMA_PUSH_URL_SECURITY_SCANNER`

Optional php-fpm log env:

- `KUMA_PHP_FPM_LOG_WINDOW_MINUTES` default `5`
- `KUMA_PHP_FPM_SERVICE_NAME` default `php8.5-fpm`
- `KUMA_PHP_FPM_ERROR_THRESHOLD` default `0`

Optional PDF renderer log env:

- `KUMA_PDF_RENDERER_LOG_WINDOW_MINUTES` default `5`
- `KUMA_PDF_RENDERER_SERVICE_NAME` default `fh-pdf-renderer`
- `KUMA_PDF_RENDERER_ERROR_THRESHOLD` default `0`

Optional PDF export env:

- `KUMA_PDF_EXPORT_BASE_URL` default `http://localhost`
- `KUMA_PDF_EXPORT_INDEX_PAGE` default `index.php`
- `KUMA_PDF_EXPORT_CREDENTIALS_FILE` default `/etc/fh/release-gate-admin.env`
- `KUMA_PDF_EXPORT_USERNAME` overrides `USERNAME`
- `KUMA_PDF_EXPORT_PASSWORD` overrides `PASSWORD`
- `KUMA_PDF_EXPORT_PDF_HEALTH_URL` default `http://127.0.0.1:3003/healthz`
- `KUMA_PDF_EXPORT_WINDOW_DAYS` default `30`

App log script behavior:

- tracks only newly appended bytes in the current daily app log
- primes itself on first run to avoid an immediate false alarm from historical log lines
- supports `KUMA_APP_LOG_IGNORE_REGEX` for expected noisy log lines, e.g. the host-only `http://pdf-renderer:3000` fallback error or expected invalid-login errors such as `JSON exception: .*Ungültige Zugangsdaten angegeben`
- uses an exclusive lock around the state file so a staggered second cron run cannot race the primary per-minute run
- production currently runs `kuma_push_app_logs.sh` twice per minute: once on the minute and once with a `sleep 30` offset for faster recovery on monitor `#9`
