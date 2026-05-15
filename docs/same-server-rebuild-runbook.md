# Same-Server Rebuild Runbook

Purpose: rebuild the current Hetzner production server in place from a fresh
Ubuntu 24.04 LTS image, then restore the app, database, Uptime Kuma, and
artifact deployment path from verified backups.

This is the selected path when no second target server is available. The
provider snapshot is the migration-level rollback path.

## Locked Assumptions

- The server keeps the same public IP after reinstall.
- The provider panel can create and restore a full server snapshot.
- SSH access is available again after reinstall.
- Production traffic is not expected before August 2026, so downtime during the
  rebuild is acceptable.
- A fresh database dump may be created directly before the wipe and copied to
  secure local storage.
- `/etc/fh`, Apache virtual hosts, cronjobs, systemd units, app configs, and
  relevant service metadata may be backed up locally before the wipe.
- Uptime Kuma is restored from the already secured operator archive unless a
  fresher pre-wipe Kuma export is explicitly approved.
- Deployment stays artifact-based; the rebuilt app directory is not a Git
  checkout.

## Non-Goals

- Do not use this runbook for a live traffic cutover to a second server.
- Do not store DB dumps, Kuma databases, `config.php`, Push URLs, or secret env
  files in Git.
- Do not rely on DNS rollback as the primary rollback path when the same IP is
  retained.
- Do not upgrade to Ubuntu 26.04 LTS in this step; the target baseline is Ubuntu
  24.04 LTS unless a later explicit decision changes it.

## Target Baseline

- Ubuntu 24.04 LTS
- Apache with PHP-FPM
- PHP 8.3 from the Ubuntu LTS package line
- MariaDB 10.11
- Node 24 LTS for build tooling and PDF renderer support
- Composer 2.x
- Docker with Compose plugin for Uptime Kuma
- certbot with Apache plugin
- fail2ban and unattended security updates
- App deployed from release artifacts into `/var/www/html/easyappointments`
- Release archives staged under `/root/releases`
- Host-local secrets under `/etc/fh` with restrictive permissions

## Phase 0: Evidence and Local Backup Prep

Prepare local secure storage before touching the provider panel.

Record without secret values:

- local backup root
- planned provider snapshot name
- current server IP
- current SSH access method
- current app path
- current release path
- current Uptime Kuma secure archive path and checksum

Stop if local storage is not available or would require committing secrets to
the repository.

## Phase 1: Provider Snapshot

Create a provider snapshot immediately before the destructive reinstall.

Evidence to record:

- snapshot name or provider ID
- snapshot creation timestamp
- server ID or host reference
- confirmation that snapshot creation completed

Rollback rule:

- If the rebuild becomes blocked, restore the provider snapshot from the panel.
- No time-based rollback threshold is required for this project because
  production traffic is not expected before August 2026.
- Keep all local pre-wipe backups until the rebuilt server has passed app,
  database, deployment, and Kuma validation.

## Phase 2: Pre-Wipe Backups

Create a fresh DB dump and copy host-local operational files to secure local
storage. Verify every archive before continuing.

The prepared helper is:

```bash
bash ./scripts/ops/prepare_same_server_rebuild_backup.sh --execute
```

Default mode is dry-run. Only pass `--execute` after the provider snapshot has
completed. The helper writes to secure local storage outside Git, verifies the
dump and archive checksums locally, and removes the temporary remote staging
directory unless `--keep-remote` is passed.

Required backup set:

- fresh MariaDB dump for the Easy!Appointments database
- `/etc/fh`
- application `config.php`
- Apache virtual host files and enabled-site state
- relevant cron entries, including root crontab basenames and schedules
- relevant systemd unit files and enabled state
- PDF renderer service configuration
- deployment scripts or release metadata needed to compare the rebuilt host
- package and service inventory

Suggested evidence:

```text
backup-root=<local secure path>
db-dump=<path only>
db-dump-gzip-test=passed
db-dump-sha256=<sha256>
host-config-archive=<path only>
host-config-sha256=<sha256>
kuma-archive=<secure archive path only>
kuma-archive-sha256=<sha256>
```

Do not print file contents from `/etc/fh`, `config.php`, DB dumps, Kuma DB, Push
env files, or notification configuration.

## Phase 3: Reinstall

In the provider panel:

1. Confirm the provider snapshot exists and is restorable.
2. Reinstall the same server with Ubuntu 24.04 LTS.
3. Confirm the public IP is unchanged.
4. Re-enable SSH key access.
5. Login via SSH and record the new OS baseline.

Stop if the IP changes unexpectedly or SSH access cannot be restored.

## Phase 4: Base Bootstrap

Install and configure the base runtime:

```bash
apt-get update
apt-get dist-upgrade
apt-get install apache2 mariadb-server certbot python3-certbot-apache fail2ban unzip zip rsync curl git
apt-get install php-fpm php-cli php-curl php-gd php-intl php-mbstring php-mysql php-xml php-zip php-soap php-bcmath php-readline
```

Then:

- install Node 24 LTS from the chosen documented source
- install Composer 2.x
- install Docker and the Docker Compose plugin
- enable Apache modules: `rewrite`, `headers`, `ssl`, `http2`, `proxy`,
  `proxy_http`, `proxy_wstunnel`
- create `/etc/fh` as `root:root` with `0700`
- create `/root/releases`
- restore Apache virtual host files
- restore systemd units and cron entries after reviewing paths
- restore the PDF renderer service and verify its health endpoint
- configure TLS with certbot after vhosts are in place

## Phase 5: App and Database Restore

1. Restore host-local app config and secret files from the local backup.
2. Import the verified DB dump into MariaDB.
3. Run CodeIgniter migrations.
4. Build or select the accepted release artifact.
5. Upload the release artifact to `/root/releases`.
6. Deploy through the documented artifact flow in [deployment.md](deployment.md).

Required validation:

- dump integrity check passed before import
- migrations pass
- non-sensitive row counts match the pre-wipe evidence
- app home route returns HTTP success
- admin login smoke passes
- booking smoke passes
- dashboard smoke passes
- PDF smoke passes
- zero-surprise replay passes against the restored data
- deep health passes
- live canary passes once the app is serving on the production route

## Phase 6: Uptime Kuma Restore

Use the secured operator archive documented in [uptime-kuma.md](uptime-kuma.md)
and the latest status in
[long-horizon-lts-modernization/Documentation.md](long-horizon-lts-modernization/Documentation.md).

Restore rules:

- verify archive checksum before extraction
- restore the Kuma data directory into the Docker volume or documented host path
- start Kuma with the pinned image line
- keep Push URLs and notification secrets host-local
- do not paste Push URLs or tokens into docs, chat, logs, or Linear

Required validation:

- container is healthy
- dashboard route responds
- SQLite integrity check passes when restoring `kuma.db`
- monitor count matches the captured desired state
- Push monitor count matches the captured desired state
- App, Host, and Ops monitors return green or have an explicit maintenance
  reason

## Phase 7: Acceptance

The rebuild is accepted when:

- Ubuntu 24.04 LTS base is updated and documented
- Apache, PHP-FPM, MariaDB, Docker, Composer, Node, certbot, fail2ban, and
  unattended upgrades are installed and version-recorded
- app deploy uses the artifact path, not a Git checkout
- DB import, migrations, and restored-data smokes pass
- release gates pass on the rebuilt host
- PDF renderer is healthy
- Kuma is restored and the expected monitors are green or intentionally paused
- secrets remain host-local and outside Git
- provider snapshot rollback remains available until acceptance is recorded

## Stop Conditions

- provider snapshot is missing or not completed
- local DB dump or host-config backup fails integrity verification
- SSH access after reinstall cannot be established
- same IP assumption is false
- app config or required secret files are unavailable
- DB import or migrations fail
- artifact deploy cannot pass validation
- deep health, PDF smoke, zero-surprise replay, or live canary fails
- Kuma archive restore fails or monitor parity cannot be explained
