# Fresh Server Rebuild Runbook

This runbook describes the target path for rebuilding the production server from
a fresh Ubuntu LTS base, then deploying the app through the repository release
artifact flow.

The current production server must remain unchanged until the rebuild has been
rehearsed and accepted.

## Current Production Baseline

Read-only inventory captured on 2026-05-14:

- host: `booking-server`
- provider: Hetzner vServer on KVM
- OS: Ubuntu 24.04.4 LTS
- kernel: `6.8.0-111-generic`
- disk: single `/dev/sda1`, 38G total, 24G available
- memory: 1.9GiB RAM, 2.0GiB swap
- app path: `/var/www/html/easyappointments`
- release archive path: `/root/releases`
- host-local secret path: `/etc/fh`

Running services:

- `apache2`
- `php8.3-fpm`
- `mariadb`
- `docker`
- `containerd`
- `fail2ban`
- `fh-pdf-renderer`
- `cron`
- `ssh`
- `unattended-upgrades`

Relevant package versions:

- Apache `2.4.58`
- PHP-FPM/CLI `8.3.6`
- MariaDB server `10.11.14`
- Node.js `20.20.2` from NodeSource `node_20.x`
- Composer `2.7.1`
- Docker CE `29.4.3`
- fail2ban `1.0.2`
- certbot `2.9.0` with Apache plugin

Apache currently serves:

- `dasforscherhaus-leg.de`
- `www.dasforscherhaus-leg.de`
- `monitor.dasforscherhaus-leg.de`

Uptime Kuma currently runs as Docker container `uptime-kuma` with image
`louislam/uptime-kuma:2.3.2`, bound to `127.0.0.1:3001`.

## Target Baseline

For the rebuild, use the newest sensible Ubuntu LTS image available when the
server is created. The expected target is Ubuntu 26.04 LTS once it is available
through the provider and the LTS upgrade/rebuild window is stable.

Target runtime choices:

- Apache with PHP-FPM
- PHP 8.4 or the Ubuntu LTS default PHP version, with PHP 8.3 compatibility kept
  until production cutover is accepted
- MariaDB 10.11 retained unless a separate database migration is approved
- Node.js 24 LTS for build tooling and the PDF renderer
- Composer 2.x from the OS or official Composer installer
- Docker CE or Ubuntu Docker packages, but keep one documented install source
- certbot with Apache plugin
- fail2ban
- unattended security updates

## Provisioning Order

1. Create the new server from a fresh Ubuntu LTS image.
2. Create DNS records only after the server can serve temporary validation
   endpoints; keep production DNS pointed at the old server during provisioning.
3. Install OS updates and reboot once if the kernel changes.
4. Install core packages:

   ```bash
   apt-get update
   apt-get dist-upgrade
   apt-get install apache2 mariadb-server certbot python3-certbot-apache fail2ban unzip zip rsync curl git
   ```

5. Install PHP-FPM and required PHP extensions:

   ```bash
   apt-get install php-fpm php-cli php-curl php-gd php-intl php-mbstring php-mysql php-xml php-zip php-soap php-bcmath php-readline
   ```

6. Install Node.js 24 LTS and npm from the chosen NodeSource or OS package path.
7. Install Composer 2.x.
8. Install Docker and Docker Compose plugin.
9. Enable Apache modules needed by the current host:

   ```bash
   a2enmod rewrite headers ssl http2 proxy proxy_http proxy_wstunnel
   ```

10. Configure Apache virtual hosts for the app and monitor subdomain.
11. Configure TLS certificates with certbot after DNS points to the new server,
    or stage certificates explicitly if DNS cutover is handled later.
12. Create `/etc/fh` with `0700` permissions and restore host-local secret files.
13. Create `/root/releases` for uploaded release artifacts.
14. Install and enable the `fh-pdf-renderer` systemd service.
15. Restore the application database.
16. Deploy the app through `docs/deployment.md`.
17. Restore or intentionally re-create Uptime Kuma.

## Host-Local Secret Files

These files must be restored from secure operator storage, not committed:

- `/etc/fh/healthz.token`
- `/etc/fh/release-gate-admin.env`
- `/etc/fh/zero-surprise-predeploy.ini`
- `/etc/fh/zero-surprise-canary.ini`
- `/etc/fh/zero-surprise-incident-webhook.ini`
- application `config.php`
- Uptime Kuma push monitor URLs and tokens

When documenting or testing, list file names and permissions only. Do not print
file contents.

## Application Deploy

Use the artifact flow documented in `docs/deployment.md`.

The new server should accept a release archive in `/root/releases`, then run
`deploy_ea.sh` with zero-surprise predeploy and live canary enabled. The app
directory on the server should remain a deployed artifact tree, not a Git
checkout.

## Database Migration

The migration rehearsal should prove:

- a dump from the old server imports cleanly into the new MariaDB instance
- CodeIgniter migrations run successfully
- the app boots against the restored database
- release gates pass against restored data
- row counts or domain-specific smoke checks match expectations

The local rehearsal procedure and the latest result are recorded in
`docs/database-migration-rehearsal.md`.

Do not point production DNS at the new server until this has passed at least once
on a rehearsal server or isolated rebuild target.

## Uptime Kuma

Uptime Kuma is operational state. Its desired monitor set should be mirrored in
the repository, while the live SQLite DB and push secrets stay host-local.

The rebuild project must choose and test one of these paths:

- migrate the full Kuma data directory and validate monitor history plus push
  monitors
- start Kuma from repo-documented monitor templates and accept loss of old
  history

See the Uptime Kuma milestone in
`docs/long-horizon-lts-modernization/Plan.md` and the operational template in
`docs/uptime-kuma.md`.

## Cutover Strategy

Use [cutover-rehearsal-checklist.md](cutover-rehearsal-checklist.md) to run the
ordered rehearsal across provisioning, DB restore, artifact deploy, Uptime Kuma,
DNS decision points, validations, and rollback.

Before DNS cutover:

- old production server remains unchanged
- new server is fully provisioned
- latest DB dump is restored
- release artifact deploy succeeds
- PDF renderer and deep health pass
- zero-surprise canary passes
- Kuma monitors are green or intentionally paused for maintenance

During cutover:

- lower DNS TTL ahead of time
- put Kuma into maintenance mode if needed
- take or copy the final approved DB dump
- restore DB on new server
- deploy the accepted release artifact
- switch DNS
- watch app health, release gates, logs, and Kuma

Rollback:

- if validation fails before DNS cutover, keep DNS on the old server
- if validation fails after DNS cutover, revert DNS to the old server while the
  old server remains intact
- use `deploy_ea.sh` rollback only for a failed artifact deploy on the same
  server; use the old server for migration-level rollback

The detailed old-server rollback drill, timing defaults, and evidence checklist
live in [old-server-rollback-drill.md](old-server-rollback-drill.md).

## Acceptance Criteria

The rebuild runbook is accepted when:

- a fresh server can be provisioned from these steps without hidden manual fixes
- app deploy uses artifact release tooling
- host-local secrets are known by file path and permission, not by committed
  value
- DB restore and migrations are rehearsed
- Uptime Kuma restore or template startup is rehearsed
- old production remains available as rollback until final acceptance
