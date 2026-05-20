# Codex Production Operations Harness

Purpose: make production operations legible to Codex without storing secrets,
live data, or long-lived operational state in Git.

Codex normally runs from the local macOS workspace and reaches production over
SSH. The repository is the system of record; the production server only carries
a short pointer file for emergency orientation.

## Default Workflow

1. Start read-only.
   Run `bash scripts/ops/prod_doctor.sh` before deciding whether a production
   change is needed.
2. Inspect logs through the redacted summary.
   Run `bash scripts/ops/prod_logs_summary.sh` instead of printing raw logs.
3. Make only narrow, explicit changes.
   Destructive or write-capable commands require direct operator approval and a
   clear rollback or stop condition.
4. Validate after every change.
   Run `bash scripts/ops/prod_validate_after_change.sh` and record the
   high-signal result in Linear or the relevant runbook.
5. Keep secrets out of normal output.
   Never print DB rows, Push URLs, tokens, passwords, `config.php`, Kuma DB
   contents, health-token values, or `/etc/fh` file contents.

Default target:

```bash
root@188.245.244.123
```

Override it with:

```bash
bash scripts/ops/prod_doctor.sh --prod-ssh-target root@example
```

## Production Map

Current accepted baseline:

- Ubuntu 26.04 LTS on host `booking-server`
- App: `https://dasforscherhaus-leg.de/`
- Monitor: `https://monitor.dasforscherhaus-leg.de/`
- Active app path: `/var/www/html/easyappointments`
- Release archive path: `/root/releases`
- Host-local secrets: `/etc/fh`, `/etc/fh/healthz.token`, and
  `/root/backups/uptime-kuma-push.env`
- Core services: `apache2`, `php8.5-fpm`, `mariadb`, `docker`, `fail2ban`,
  `cron`, `unattended-upgrades`, `fh-pdf-renderer`
- PDF renderer: Docker-backed `fh-pdf-renderer`, bound to `127.0.0.1:3003`
- Uptime Kuma: Docker Compose project `uptime-kuma`, bound to `127.0.0.1:3001`
- Host Node/npm: intentionally absent while artifact deploy remains in use

Log and signal sources:

- Apache logs: `/var/log/apache2`
- App logs: `/var/www/html/easyappointments/storage/logs`
- PHP-FPM journal: `php8.5-fpm`
- MariaDB journal: `mariadb`
- PDF renderer journal: `fh-pdf-renderer`
- Cron journal: `cron`
- Kuma data: `/var/lib/uptime-kuma-data`

## Commands

Read-only overall status:

```bash
bash scripts/ops/prod_doctor.sh
```

Redacted recent logs:

```bash
bash scripts/ops/prod_logs_summary.sh
bash scripts/ops/prod_logs_summary.sh --since "24 hours ago"
```

Post-change validation:

```bash
bash scripts/ops/prod_validate_after_change.sh
```

Optional Certbot renewal simulation:

```bash
bash scripts/ops/prod_validate_after_change.sh --with-certbot-dry-run
```

The Certbot dry-run uses `--no-random-sleep-on-renew` because Certbot 4 may
otherwise wait several minutes before doing useful work.

Install or refresh the server-local pointer file:

```bash
bash scripts/ops/install_prod_agent_readme.sh
bash scripts/ops/install_prod_agent_readme.sh --execute
```

Default mode is dry-run. The installed file is `/etc/fh/AGENT_README.md` and
must not contain secret values.

## Incident Playbooks

App route is down:

- Run `prod_doctor.sh`.
- Check `apache2`, `php8.5-fpm`, deep health, and recent Apache/app/PHP logs.
- Do not restart services until logs identify a plausible service-level issue.
- After any restart or config change, run `prod_validate_after_change.sh`.

Deep health fails:

- Confirm the app homepage and PDF renderer health separately.
- Separate failure modes before changing anything:
  - `401`: Kuma or the probe is missing the `X-Health-Token` header or using
    the wrong host-local value.
  - `503` with JSON dependency details: inspect the named dependency and app
    logs.
  - connection/TLS failure: route as availability or proxy issue before app
    debugging.
- Check PHP-FPM env wiring and app log summary after the status class is known.
- Do not print the health token, Kuma header value, `/etc/fh` file contents,
  Push URLs, Kuma DB rows, or raw production config. Record only whether the
  header is present/configured and which sanitized status class was observed.

PDF rendering fails:

- Check `fh-pdf-renderer`, container status, and `http://127.0.0.1:3003/healthz`.
- Review redacted PDF renderer logs.
- Confirm the app still uses `PDF_RENDERER_URL=http://127.0.0.1:3003`.

Kuma is red:

- Compare public HTTP checks with Push monitor freshness.
- Run the relevant `scripts/ops/kuma_push_*.sh` manually only when the env file
  exists on the host and output can stay redacted.
- Never print Push URLs or tokens.

Disk, memory, or swap pressure:

- Use `prod_doctor.sh` for resource facts first.
- Avoid broad cleanup commands. Identify the path or service causing growth.
- Do not delete backup, release, or Kuma data paths without explicit approval.

Certbot or TLS issue:

- Check certificate expiry and timers through `prod_validate_after_change.sh`.
- Use `--with-certbot-dry-run` only when needed; it talks to Let's Encrypt.
- Do not replace certs manually unless the Apache vhost state is understood.

Suspicious log spike:

- Start with `prod_logs_summary.sh --since "60 min ago"`.
- Use raw logs only when the redacted summary is insufficient, and redact before
  pasting anything into chat, docs, or Linear.

## Stop Conditions

Stop and ask for operator direction if:

- a command would delete or overwrite DB dumps, Kuma data, release archives, or
  provider snapshot evidence;
- a command would print secret-bearing file contents;
- app health and rollback direction are both unclear;
- the observed server shape differs from the accepted Ubuntu 26.04 baseline;
- a fix requires changing deployment model, host Node policy, Kuma monitor
  definitions, database schema, or provider snapshot retention.

## Anti-Drift Rule

Keep this file as the human and agent entry point for production operations.
Move details into scripts when they can be checked mechanically. Keep
`AGENTS.md` and `docs/agent-harness-index.md` as short maps.
