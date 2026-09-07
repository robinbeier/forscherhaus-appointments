# Uptime Kuma Operations

This document mirrors the production Uptime Kuma desired state without storing
the live SQLite database or Push monitor secrets in the repository.

## Boundary

Repository-owned:

- container template: `docker/compose.uptime-kuma.yml`
- push scripts: `scripts/ops/kuma_push_*.sh`
- host-local env example: `scripts/ops/uptime-kuma-push.env.example`
- crontab example: `scripts/ops/uptime-kuma-crontab.example`
- immutable root runtime: `docs/ops/production-kuma-push-runtime.md`

Host-owned:

- Uptime Kuma SQLite data directory
- monitor history
- Push tokens and full Push URLs
- token header values for deep-health JSON monitors
- notification credentials
- maintenance state

## Production Snapshot

Refreshed after the authorized 2026-09-07 switch to the slim image:

- image: pinned in `docker/compose.uptime-kuma.yml` to `2.5.3-slim` and its digest
- listen address: `127.0.0.1:3001`
- data mount: `/var/lib/uptime-kuma-data` bind-mounted at `/app/data`
- database file: `/app/data/kuma.db` (SQLite)

The slim image omits Chromium and embedded MariaDB; this instance uses SQLite
and HTTP, keyword, JSON, and Push monitors. It does not use browser monitors.
After the switch, container health and production validation passed, with the
monitor and notification configuration preserved. The previous `2.5.0` image
and the complete pre-switch data/Compose backup remain available for recovery;
rollback must restore the old data together with the old image.

Active monitors were captured on 2026-05-14. The repo desired-state catalog now
also includes reviewed follow-up changes, such as the ROB-385 split between
restore-verification freshness and backup-creation freshness. ROB-390 and
ROB-391 applied the related live Kuma changes on 2026-05-20; future live Kuma
renames or new monitor creation still require an explicit Kuma write gate.

The repo desired monitor catalog contains seven monitors. A repository merge
does not update the live catalog; use the bounded retirement procedure below. `App - Health
Deep` remains the single JSON health monitor and includes the PDF renderer
dependency check in its response; the former standalone PDF Renderer monitor
is removed from the active catalog. The static shallow-health and PDF-renderer
journal monitors are retired from the desired catalog; functional deep-health
and PDF-export checks remain.

Operational transition for the existing live Kuma instance: after `App - Health Deep`
(`json-query`) and its notifications are verified active, pause the former
`App - PDF Renderer` (`json-query`) monitor. Its historical ID is `8`; identify
it by its name and type if the instance has been rebuilt. Keep its existing
history; this repository change does not require a Push runtime or cron update.
Finish any active deployment before changing the monitor count. Application
deployments use the direct checks described in
[the deployment runbook](deployment.md).
Pausing the monitor does not alter old completed deployment records or authorize
an installation of deployment tools.

After an authorized change to Kuma itself, manually compare the affected
monitors in its dashboard with the catalog below by name and type, then verify
their status and notification assignments. Historical database IDs are not
identity: a fresh reconstruction may assign different IDs. This is an operator
check for monitoring changes, not an automated deployment gate.

`prod_validate_after_change.sh` still checks the monitoring endpoint and
container, but does not validate the Kuma database, catalog, or heartbeat
results. `prod_doctor.sh` reports observations without certifying the catalog.
Application deployments rely on their direct checks; neither the catalog nor
an all-green monitor count is an additional release condition.

### Retired monitors

The static `/health` file contains only `OK`; its keyword monitor adds no
application or dependency check beyond the retained Homepage and Deep Health
monitors. Keep the endpoint itself for existing consumers.

The PDF renderer journal monitor counts only `err..alert` journal priorities.
Renderer application output does not reliably assign those priorities, so a
green result is not proof of successful rendering. Retain Deep Health for
renderer reachability and the Dashboard PDF Export monitor for actual exports;
use renderer logs on demand when either reports a failure.

The PHP-FPM journal monitor also counts only `err..alert` journal entries and
masks journal-query failures as empty output. PHP-FPM's primary error log is
configured separately; a green journal result does not establish absence of
FPM errors. Keep FPM logs available for diagnosis, and retain Homepage, Deep
Health and application error monitoring.

The `Host - Services` Push monitor only queried `systemctl is-active`, not
application readiness. Homepage, Deep Health and actual PDF exports retain
functional checks. Separate alerts for service state alone (including Docker
or an optional access service) are not required for this single-server setup.
Keep service-state inspection in `prod_doctor.sh` and post-change diagnostics;
retiring the monitor does not stop services or remove those diagnostic checks.

The `Security - Scanner Activity` Push monitor counted matching access-log
requests, including blocked bursts from multiple sources. A `2xx` response did
not prove disclosure, and a blocked burst did not by itself require action.
Retire that continuous alert, while retaining Apache/Fail2ban protection and the
fixed scanner-path checks used by `prod_doctor.sh` and post-change validation.
Logs remain available for focused incident diagnosis; retiring the alarm does
not resolve any independently identified security finding.

Retirement changes monitor activation and the corresponding cron invocation,
not any monitored service. For each explicitly approved live
retirement, back up the affected configuration, verify retained monitors and
notification assignments, pause the identified monitor, and remove only its
cron invocation. The retired monitors are `App — Health Shallow` (keyword),
`App - PDF Renderer Log Errors` (push), `App - php8.5-fpm Log Errors` (push),
`Host - Services` (push), and `Security - Scanner Activity` (push);
the shallow monitor has no cron invocation. Already-paused monitors require no
further change. Verify fresh regular results from all retained monitors.
Preserve history, shared libraries and credentials. An unused installed script
may remain for rollback; never remove an installed script while cron still
references it. On failure, restore only the affected prior cron and monitor
state, preserving unrelated changes and newly recorded history.

Repo desired monitor catalog:

| Name | Type | Interval | Secret handling |
| --- | --- | ---: | --- |
| App-Homepage | `http` | 30s | public URL only |
| App - Health Deep | `json-query` | 30s | `X-Health-Token` header value in Kuma/host-local config only |
| Host - Resources | `push` | 60s | `KUMA_PUSH_URL_HOST_RESOURCES` |
| Ops - Restore Verify Freshness | `push` | 900s | `KUMA_PUSH_URL_OPS_JOBS` |
| Ops - Backup Creation Freshness | `push` | 900s | `KUMA_PUSH_URL_BACKUP_CREATION` |
| App - Log Errors | `push` | 60s | `KUMA_PUSH_URL_APP_LOGS` |
| App - Dashboard PDF Export | `push` | 900s | `KUMA_PUSH_URL_PDF_EXPORT` |

`App - Log Errors` is an event monitor: each newly read error is reported once,
and the next run without a new error reports recovery. Set its Kuma **Retries**
(`maxretries`) to **0**, keeping the 60-second heartbeat interval and the existing
twice-per-minute producer schedule. Requiring a repeated failure can suppress a
single error as pending and then silently recover. Zero retries also removes the
extra retry grace for missing heartbeats; the producer normally sends every
30 seconds. Preserve notification assignments and all other monitor settings.
This configuration change requires production approval; a repository merge does
not apply it. Verify the saved value and a fresh normal heartbeat afterward.

The catalog above records the non-secret monitor shape. Live monitor history,
Push URLs, and other credentials remain host-owned.

## Health Monitor Boundary

`/health` and `/index.php/healthz` intentionally have different trust
boundaries:

- `/health` is the public shallow health route. It should only prove that the
  web path can return `OK`.
- `/index.php/healthz` is the application-owned deep health route. It requires
  the `X-Health-Token` header and returns dependency checks for database, GD,
  storage, and PDF renderer.
- `App - Health Deep` reads `/index.php/healthz`, including the
  `checks.pdf_renderer.ok` dependency result in the overall deep-health
  response.

The `X-Health-Token` value belongs only in Kuma monitor headers or host-local
files such as `/etc/fh/healthz.token`. Do not copy the value into Git, Linear,
chat, command transcripts, or runbook examples.

For a live header audit, record only sanitized facts:

- whether the header is configured for the deep-health monitors;
- whether the latest Kuma status is green;
- whether `/health` is independently green;
- whether deep health fails with `401`, `503`, or a dependency-specific JSON
  failure.

Do not print the header value, Kuma database rows, Push URLs, or raw production
configuration while auditing.

## New Server Startup

Start Kuma from the repository template:

```bash
docker compose -f docker/compose.uptime-kuma.yml up -d
```

For a disposable local restore rehearsal, use a separate data path and port:

```bash
KUMA_DATA_PATH=/private/tmp/fh-kuma-restore-test-data \
KUMA_PORT=13001 \
docker compose -f docker/compose.uptime-kuma.yml up -d
```

Put Apache or another reverse proxy in front of `127.0.0.1:3001` for
`monitor.dasforscherhaus-leg.de`.

For an explicitly approved new Push monitor, store its generated Push URL in
a host-local env file based on
`scripts/ops/uptime-kuma-push.env.example`.

## Push Script Installation

Install the versioned, root-controlled runtime described in
`docs/ops/production-kuma-push-runtime.md`. Root cron must never execute Push
scripts or their libraries from the app release tree. Only for initial setup,
when no host-local Env exists, create it separately. Never overwrite an existing
Env with this example:

```bash
install -m 0600 scripts/ops/uptime-kuma-push.env.example /root/backups/uptime-kuma-push.env
```

Fill in real Push URLs on the host only.

The retention-success check in `kuma_push_host_resources.sh` reads
`KUMA_RELEASE_RETENTION_MONITOR_ENABLED=1` from the protected host-local Env.
The completed one-time activation helper is retired; regular monitoring does
not depend on it. Keep the enabled setting and existing recovery files.

Future Env changes need explicit approval for the concrete change, a verified
backup, root-only permissions, protection against concurrent writes, and a
checked rollback. Preserve unrelated settings and secrets. Never overwrite an
existing Env with the example above or print its contents. A code update does
not authorize an Env change, Push, timer activation, or deletion.

For `/etc/cron.d`, the canonical desired state is
`scripts/ops/config/fh-uptime-kuma-push.cron`; the personal-crontab form remains
in `scripts/ops/uptime-kuma-crontab.example`. The desired schedule after the
approved transition runs:

- host resources every minute
- restore-verification freshness every 15 minutes
- backup-creation freshness every 15 minutes after the host-local backup job
  writes its success marker
- app log errors every minute plus a 30 second staggered run
- dashboard PDF export every 15 minutes

The `App - Log Errors` Push monitor is still an app-error monitor, not a
scanner monitor. Its script ignores only built-in, narrow known-noise patterns
for externally generated scanner/proxy traffic that was observed while `/`,
`/health`, and `/index.php/healthz` remained green, including the observed
numeric host:port scanner form `1465618042:3333/index`. Additional
`KUMA_APP_LOG_IGNORE_REGEX` values stay host-local and must not hide broad
classes such as all 404s or all PHP warnings.

`Ops - Restore Verify Freshness` and `Ops - Backup Creation Freshness` are
separate by design:

- `kuma_push_ops_jobs.sh` reads only `last_verify_success.utc` and proves that a
  restore-verification flow completed recently.
- `kuma_push_backup_creation.sh` reads only `last_backup_success.utc` and proves
  that a backup-creation flow completed recently.
- Neither monitor reads backup contents, lists backup directories, validates
  off-host retention, or proves end-to-end restoreability alone.

After the separately approved ROB-480 scheduler cutover, the unchanged backup
marker belongs only to the canonical ROB-466 producer and the restore marker
belongs only to the paired state-bound handoff verifier. The retired
legacy cron must not remain a second marker writer. The Push scripts and Kuma
monitor definitions do not change during that cutover.

## Backup and Restore

For any future restore or migration, use a fresh approved full backup close to
the migration window. Verify archive/database integrity, current monitor
definitions, and fresh successful pushes from the host-local scripts. A tested
older backup proves restore mechanics only; it is not the current production
backup or monitor state.

For a full-history migration:

1. Stop the new Kuma container.
2. Restore the approved backup archive into the new Kuma data volume.
3. Start the new Kuma container.
4. Confirm the same monitor names exist.
5. Confirm HTTP and JSON monitors turn green.
6. Confirm every Push monitor receives a fresh successful push from the new
   host-local cron/scripts.
