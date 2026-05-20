# Uptime Kuma Operations

This document mirrors the production Uptime Kuma desired state without storing
the live SQLite database or Push monitor secrets in the repository.

## Boundary

Repository-owned:

- container template: `docker/compose.uptime-kuma.yml`
- desired monitor template: `scripts/ops/uptime-kuma.monitors.yml`
- push scripts: `scripts/ops/kuma_push_*.sh`
- host-local env example: `scripts/ops/uptime-kuma-push.env.example`
- crontab example: `scripts/ops/uptime-kuma-crontab.example`

Host-owned:

- Uptime Kuma SQLite data directory
- monitor history
- Push tokens and full Push URLs
- token header values for deep-health JSON monitors
- notification credentials
- maintenance state

## Production Snapshot

Read-only inventory captured on 2026-05-14:

- container: `uptime-kuma`
- image: `louislam/uptime-kuma:2.3.2`
- listen address: `127.0.0.1:3001`
- data volume: `uptime-kuma_uptime-kuma-data` mounted at `/app/data`
- database file: `/app/data/kuma.db`

Active monitors were captured on 2026-05-14. The repo desired-state catalog now
also includes reviewed follow-up changes, such as the ROB-385 split between
restore-verification freshness and backup-creation freshness. ROB-390 and
ROB-391 applied the related live Kuma changes on 2026-05-20; future live Kuma
renames or new monitor creation still require an explicit Kuma write gate.

Repo desired monitor catalog:

| Name | Type | Interval | Secret handling |
| --- | --- | ---: | --- |
| App-Homepage | `http` | 30s | public URL only |
| App — Health Shallow | `keyword` | 30s | public URL only |
| App - Health Deep | `json-query` | 30s | `X-Health-Token` header value in Kuma/host-local config only |
| Host - Services | `push` | 60s | `KUMA_PUSH_URL_HOST_SERVICES` |
| Host - Resources | `push` | 60s | `KUMA_PUSH_URL_HOST_RESOURCES` |
| Ops - Restore Verify Freshness | `push` | 900s | `KUMA_PUSH_URL_OPS_JOBS` |
| Ops - Backup Creation Freshness | `push` | 900s | `KUMA_PUSH_URL_BACKUP_CREATION` |
| App - PDF Renderer | `json-query` | 30s | same `X-Health-Token` boundary as deep health |
| App - Log Errors | `push` | 60s | `KUMA_PUSH_URL_APP_LOGS` |
| App - php8.5-fpm Log Errors | `push` | 60s | `KUMA_PUSH_URL_PHP_FPM_LOGS` |
| App - PDF Renderer Log Errors | `push` | 60s | `KUMA_PUSH_URL_PDF_RENDERER_LOGS` |
| App - Dashboard PDF Export | `push` | 900s | `KUMA_PUSH_URL_PDF_EXPORT` |
| Security - Scanner Activity | `push` | 60s | `KUMA_PUSH_URL_SECURITY_SCANNER` |

The accepted Ubuntu 26.04 rebuild runs PHP-FPM as `php8.5-fpm`. Repo desired
state, host-local Push env, script defaults, and live Kuma monitor display names
should all target `php8.5-fpm`.

The full non-secret monitor shape is mirrored in
`scripts/ops/uptime-kuma.monitors.yml`.

## Health Monitor Boundary

`/health` and `/index.php/healthz` intentionally have different trust
boundaries:

- `/health` is the public shallow health route. It should only prove that the
  web path can return `OK`.
- `/index.php/healthz` is the application-owned deep health route. It requires
  the `X-Health-Token` header and returns dependency checks for database, GD,
  storage, and PDF renderer.
- `App - Health Deep` and `App - PDF Renderer` both read from
  `/index.php/healthz`; the latter checks the `checks.pdf_renderer.ok` JSON
  value.

The `X-Health-Token` value belongs only in Kuma monitor headers or host-local
files such as `/etc/fh/healthz.token`. Do not copy the value into Git, Linear,
chat, command transcripts, desired-state YAML, or runbook examples. The
desired-state YAML may name the required header, but must keep the value as a
host-local placeholder.

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

Create the monitors from `scripts/ops/uptime-kuma.monitors.yml` manually in the
Kuma UI or with a separately reviewed import script. For Push monitors, copy the
generated Push URLs into a host-local env file based on
`scripts/ops/uptime-kuma-push.env.example`.

## Push Script Installation

Deploy the app artifact first so `scripts/ops` exists on the host, then create
the host-local env file:

```bash
install -m 0600 scripts/ops/uptime-kuma-push.env.example /root/backups/uptime-kuma-push.env
```

Fill in real Push URLs on the host only.

Install cron entries from `scripts/ops/uptime-kuma-crontab.example`. The current
production schedule runs:

- host services every minute
- host resources every minute
- restore-verification freshness every 15 minutes
- backup-creation freshness every 15 minutes after the host-local backup job
  writes its success marker
- app log errors every minute plus a 30 second staggered run
- php-fpm log errors every minute
- PDF renderer log errors every minute
- dashboard PDF export every 15 minutes

The `App - Log Errors` Push monitor is still an app-error monitor, not a
scanner monitor. Its script ignores only built-in, narrow known-noise patterns
for externally generated scanner/proxy traffic that was observed while `/`,
`/health`, and `/index.php/healthz` remained green. Additional
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

Before the 2026-05-20 live gate, the live UI still used the older
`Ops - Jobs Freshness` name. The monitor's intended meaning was already
restore-verification freshness; the live gate below aligned the display name
with that meaning.

## 2026-05-20 ROB-390/ROB-391 Live Gate

The live Kuma write gate for ROB-390 and ROB-391 was applied on 2026-05-20 over
SSH with Push URLs and tokens kept host-local.

Redacted result:

- a server-local Kuma SQLite backup was created before the write;
- `App - php8.3-fpm Log Errors` was renamed to
  `App - php8.5-fpm Log Errors`;
- `Ops - Jobs Freshness` was renamed to `Ops - Restore Verify Freshness`;
- a separate Push monitor `Ops - Backup Creation Freshness` was created;
- the host-local Push env now contains a backup-creation Push URL without the
  value being copied into Git, Linear, or chat;
- `/etc/cron.d/fh-uptime-kuma-push` now schedules
  `kuma_push_backup_creation.sh`;
- the production host had the current split `kuma_push_ops_jobs.sh` and
  `kuma_push_backup_creation.sh` installed from this repository after the live
  gate exposed the missing server-side script;
- manual Push validation returned separate green restore-verification and
  backup-creation signals using only marker ages;
- post-change Kuma summary showed 13 active monitors and 13 latest green.

Do not include the generated Push token, full Push URL, Kuma DB contents, or
backup contents in follow-up documentation.

## Backup and Restore

Production has historical Kuma backups under `/root/backups/uptime-kuma` and
`/root/backups/uptime-kuma-data-before-layer-c-*.tar.gz`.

For a full-history migration:

1. Stop the new Kuma container.
2. Restore the approved backup archive into the new Kuma data volume.
3. Start the new Kuma container.
4. Confirm the same monitor names exist.
5. Confirm HTTP and JSON monitors turn green.
6. Confirm every Push monitor receives a fresh successful push from the new
   host-local cron/scripts.

For a clean template rebuild:

1. Start Kuma with `docker/compose.uptime-kuma.yml`.
2. Recreate monitors from `scripts/ops/uptime-kuma.monitors.yml`.
3. Store generated Push URLs in the host-local env file.
4. Install the crontab from `scripts/ops/uptime-kuma-crontab.example`.
5. Run every push script once manually.
6. Confirm all app, host, and ops monitors are green.

## 2026-05-14 Restore Status

Confirmed:

- production monitor desired state was captured without Push tokens
- production already has historical Kuma backup archives
- repo now contains a Kuma container template, monitor template, env example,
  crontab example, and the missing Host/Ops Push scripts
- historical backup
  `/root/backups/uptime-kuma/uptime-kuma-data-pre-2.2.1-20260310T152414Z.tar.gz`
  was copied to a disposable local restore path
- the backup checksum matched the production `.sha256` file
- the restored Kuma 2.3.2 container started healthy on local port `13001`
- local HTTP smoke returned `HTTP/1.1 302 Found` to `/dashboard`
- restored monitor metadata was readable from the local SQLite database

Superseded by the 2026-05-15 live export and the 2026-05-20 ROB-390/ROB-391
live gate:

- a current 12-monitor live export was validated on 2026-05-15;
- ROB-390/ROB-391 moved the live state to 13 active monitors on 2026-05-20;
- post-change validation showed 13 active monitors and 13 latest green.

Important limitation:

- the tested historical backup contains the earlier 7-monitor state, not the
  later 12- or 13-monitor production state; restore mechanics are proven, but
  any future full-history migration should use a fresh approved live backup.

## 2026-05-15 Live Export

Created a current live export outside the repository under `/private/tmp`.

Validation:

- archive checksum verified with `shasum -a 256 -c`
- archive extracted successfully
- exported SQLite database returned `PRAGMA integrity_check = ok`
- exported database contains `12` monitors
- exported database contains `8` Push monitors
- redacted manifest contains all current monitor names and Push-token presence

The export archive contains secrets and monitor history. Do not commit it.

## 2026-05-15 Secure Live Export Archive

Copied the verified current live export from temporary local storage to an
operator-controlled local archive outside the repository.

Secure archive:

- path:
  `/Users/robinbeier/Documents/forscherhaus-ops-secure/uptime-kuma/20260515T055731Z`
- files: archive, `.sha256`, and redacted manifest
- archive SHA256:
  `b29f85f61cd4e2bdf15d707fd04e4518acf70f36dae5b08f7b64e6951907b9c4`
- archive size: `58621910` bytes

Validation:

- source checksum passed before copy
- destination SHA256 matched the source checksum after copy
- source and destination archive sizes matched after copy
- archive directory permissions are `0700`
- archive files are `0600`

Retention:

- the secure copy is the retained reference for rebuild rehearsal work
- final cutover still needs a fresh live export close to the migration window
- the temporary `/private/tmp` source was removed after the secure archive was
  verified
- Push URLs, tokens, and Kuma database files remain outside Git

## 2026-05-15 Current Export Restore Rehearsal

Restored the current live export into a disposable local Kuma instance.

Restore target:

- data path: `/private/tmp/fh-kuma-live-restore-test-data`
- local port: `13002`
- Compose project: `fh-kuma-live-restore-test`

Validation:

- archive extracted successfully
- restored SQLite database returned `PRAGMA integrity_check = ok`
- restored Kuma 2.3.2 container started healthy
- local HTTP smoke returned `HTTP/1.1 302 Found` to `/dashboard`
- restored database contained `12` monitors
- restored database contained `8` Push monitors
- restored monitor metadata matched `scripts/ops/uptime-kuma.monitors.yml`
  for ID, name, type, interval, retry interval, and max retries
- a temporary host-local Push env file was generated from the restored DB and
  kept outside the repository
- all 8 Push monitors accepted green test pings against the restored instance
- latest heartbeat status was green for all 12 monitors

Decision:

- full Kuma data migration is the preferred rebuild path because the current
  export restores monitor history, monitor configuration, and Push tokens
  successfully
- final cutover still needs a fresh live export close to the migration window
- Push URLs and any generated env files remain host-local only

Cleanup:

- disposable container, extracted data, temporary Push env, and comparison files
  were removed after validation
- the original current export archive remains outside Git for retention or
  secure relocation
