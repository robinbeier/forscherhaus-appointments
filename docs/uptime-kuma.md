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
- notification credentials
- maintenance state

## Production Snapshot

Read-only inventory captured on 2026-05-14:

- container: `uptime-kuma`
- image: `louislam/uptime-kuma:2.3.2`
- listen address: `127.0.0.1:3001`
- data volume: `uptime-kuma_uptime-kuma-data` mounted at `/app/data`
- database file: `/app/data/kuma.db`

Active monitors:

| Name | Type | Interval | Secret handling |
| --- | --- | ---: | --- |
| App-Homepage | `http` | 30s | public URL only |
| App — Health Shallow | `keyword` | 30s | public URL only |
| App - Health Deep | `json-query` | 30s | public URL only |
| Host - Services | `push` | 60s | `KUMA_PUSH_URL_HOST_SERVICES` |
| Host - Resources | `push` | 60s | `KUMA_PUSH_URL_HOST_RESOURCES` |
| Ops - Jobs Freshness | `push` | 900s | `KUMA_PUSH_URL_OPS_JOBS` |
| App - PDF Renderer | `json-query` | 30s | public URL only |
| App - Log Errors | `push` | 60s | `KUMA_PUSH_URL_APP_LOGS` |
| App - php8.3-fpm Log Errors | `push` | 60s | `KUMA_PUSH_URL_PHP_FPM_LOGS` |
| App - PDF Renderer Log Errors | `push` | 60s | `KUMA_PUSH_URL_PDF_RENDERER_LOGS` |
| App - Dashboard PDF Export | `push` | 900s | `KUMA_PUSH_URL_PDF_EXPORT` |
| Security - Scanner Activity | `push` | 60s | `KUMA_PUSH_URL_SECURITY_SCANNER` |

The full non-secret monitor shape is mirrored in
`scripts/ops/uptime-kuma.monitors.yml`.

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
- backup/job freshness every 15 minutes
- app log errors every minute plus a 30 second staggered run
- php-fpm log errors every minute
- PDF renderer log errors every minute
- dashboard PDF export every 15 minutes

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

Pending:

- create or approve a current backup of the live 12-monitor Kuma state
- validate that the current backup restores all 12 monitors
- validate that Push monitors go green with host-local env values

Important limitation:

- the tested historical backup contains the earlier 7-monitor state, not the
  current 12-monitor production state; restore mechanics are proven, but current
  monitor parity still needs a fresh approved backup.

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
