# Ops Monitoring Scripts

These scripts are designed to extend the current production Uptime Kuma setup without conflicting with the existing seven monitors on `188.245.244.123`.

Current production monitor names:

- `App-Homepage`
- `App — Health Shallow`
- `App - Health Deep`
- `Host - Services`
- `Host - Resources`
- `Ops - Jobs Freshness`
- `App - PDF Renderer`

Suggested additional Push monitors:

- `App - Log Errors`
- `App - php8.3-fpm Log Errors`
- `App - PDF Renderer Log Errors`
- `App - Dashboard PDF Export`

Script inventory:

- `kuma_push_app_logs.sh` monitors newly appended application log errors
- `kuma_push_php_fpm_logs.sh` monitors recent `php8.3-fpm` journal errors
- `kuma_push_pdf_renderer_logs.sh` monitors recent `fh-pdf-renderer` journal errors
- `kuma_push_pdf_export.sh` runs the dashboard PDF release gate as a synthetic smoke
- `lib/kuma_push_common.sh` provides shared env, curl, and log helpers

Default env file:

- `/root/backups/uptime-kuma-push.env`

Required new Push URLs:

- `KUMA_PUSH_URL_APP_LOGS`
- `KUMA_PUSH_URL_PHP_FPM_LOGS`
- `KUMA_PUSH_URL_PDF_RENDERER_LOGS`
- `KUMA_PUSH_URL_PDF_EXPORT`

Optional php-fpm log env:

- `KUMA_PHP_FPM_LOG_WINDOW_MINUTES` default `5`
- `KUMA_PHP_FPM_SERVICE_NAME` default `php8.3-fpm`
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
