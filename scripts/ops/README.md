# Ops Monitoring Scripts

This directory currently contains the production follow-up for Uptime Kuma monitor `#9` (`App - Log Errors`).

Files:

- `lib/kuma_push_common.sh`: shared curl/env/log helpers for Kuma push scripts
- `kuma_push_app_logs.sh`: incremental app-log monitor for the daily Easy!Appointments log file

Default env file:

- `/root/backups/uptime-kuma-push.env`

Required push URL:

- `KUMA_PUSH_URL_APP_LOGS`

Optional app-log env:

- `KUMA_APP_ROOT` default `/var/www/html/easyappointments`
- `KUMA_APP_LOG_FILE` default `${KUMA_APP_ROOT}/storage/logs/log-$(date +%F).php`
- `KUMA_PUSH_STATE_DIR` default `/var/tmp/kuma-push-state`
- `KUMA_APP_LOG_LOCK_FILE` default `${KUMA_PUSH_STATE_DIR}/app-logs.lock`
- `KUMA_APP_LOG_PATTERN` default `ERROR - `
- `KUMA_APP_LOG_IGNORE_REGEX` for expected noisy log lines, e.g. the host-only `http://pdf-renderer:3000` fallback error
- `KUMA_APP_LOG_ERROR_THRESHOLD` default `0`

Behavior:

- tracks only newly appended bytes in the current daily app log
- primes itself on first run to avoid an immediate false alarm from historical log lines
- uses an exclusive lock around the state file so a staggered second cron run cannot race the primary per-minute run

Production schedule:

- run `kuma_push_app_logs.sh` once per minute
- run a second staggered invocation with `sleep 30` to shorten recovery time for monitor `#9`
