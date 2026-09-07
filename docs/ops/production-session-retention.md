# Production Session Retention

ROB-440 provides deterministic retention for CodeIgniter file sessions without
enabling probabilistic PHP request-time garbage collection. Repository delivery
does not install or enable the timer. Every production step remains a separate
live-write operation.

## Fixed Policy

- exact root: `/var/www/html/easyappointments/storage/sessions`;
- session prefix: `ea_session`, followed by a PHP-compatible session ID;
- retention: 86,400 seconds (24 hours), inclusive at the cutoff;
- maximum deletion: 10,000 sessions per run;
- only regular `www-data:www-data`, `0600`, single-link files;
- stable path/open/file identity and a second age check immediately before
  deletion;
- nonblocking exclusive file lock: a CodeIgniter session in use is retained;
- aggregate canonical JSON only; no session names or contents;
- successful runs atomically publish root-owned `0600`
  `/var/lib/fh-session-retention/last-success.json` only when no eligible file
  remains.

The execute path also takes the private retention-directory lock, the shared
production-change lock, and rejects observable deploy, recovery, dump, replay,
or UI-smoke activity. Ambiguous paths, owners, modes, hard links,
types, identities, activity, or marker state fail closed.

The systemd service runs as root because the session files are `www-data:0600`
while its lock and success marker are root-protected. Its capability boundary is
exactly `CAP_DAC_OVERRIDE`. `AmbientCapabilities` is empty, `NoNewPrivileges`
and `ProtectSystem=strict` remain active, and writable paths are limited to the
session root, state directory, and shared lock file.

The deletion guard deliberately relies on CodeIgniter's advisory session-file
lock. It does not inspect another user's `/proc/<pid>/fd` entries: doing so
would require `CAP_SYS_PTRACE`, which is outside this service's approved and
tested capability boundary.

## Repository Validation

The local wrapper is read-only by default:

```bash
bash scripts/ops/prod_session_retention.sh
```

The live mode is deliberately a different command and is not authorized merely
because this code is merged:

```bash
bash scripts/ops/prod_session_retention.sh \
  --execute \
  --confirm-live-write ROB-440
```

Do not run either command against production without the matching operational
approval. The Root PHPUnit suite creates isolated fixed production paths only on
a disposable Linux root runner and exercises identity, type, owner, mode,
locking, cutoff, cap, marker, and replay behavior.

## Operating the existing retention service

A read-only production check on 2026-09-07 confirmed that
`fh-session-retention.timer` was enabled and active, its service had completed
successfully, and `KUMA_SESSION_RETENTION_MONITOR_ENABLED=1` was configured.
This is a dated observation, not a guarantee of current health. Repository
changes do not install helpers, change the schedule, or authorize live writes.

The timer policy is daily at 03:37 UTC with up to 15 minutes randomized delay.
The monitor treats a missing, invalid, or older-than-36-hours marker as critical
when monitoring is enabled.

For routine inspection, use the default read-only wrapper above and the
[cleanup inventory](production-cleanup-inventory.md). Check the timer's current
state and next trigger, the service result, and marker freshness. The service
should be inactive between runs. Retain aggregate results only; investigate
blocked results or unknown file counts before considering an execute pass.

A separately authorized manual pass uses the existing execute command above.
Confirm no deploy, dump, restore, replay, smoke, or other cleanup is active.
Exit `75` with `status=partial` means bounded, locked, or cap-limited work is
incomplete; no success marker is written. Re-inventory before deciding on
another pass. After `status=pass`:

- Repeat the [cleanup inventory](production-cleanup-inventory.md) for
  `session_retention.marker_status`, `session_retention.marker_age_seconds`,
  and root disk usage; check inode usage with read-only `df -Pi /` on the host.
- Run the [standard post-change validation](agent-operations.md) for application
  and renderer/deep health, services, and scanner posture.
- Inspect the retained monitors in the [Kuma dashboard](../uptime-kuma.md),
  including a fresh resources result. The standard validator checks Kuma's
  endpoint/container, not monitor heartbeats. Record only sanitized status.

For a separately approved helper or unit update, preserve the installed
regular, single-link, root-owned `0555`
`/usr/local/libexec/fh-session-retention-v1` and root-owned `0644` units under
`/etc/systemd/system/`. Never execute the deploy-tree copy as root. Validate
changed units with `systemd-analyze verify` before `daemon-reload`, retain a
reviewed rollback copy, and repeat dry-run and post-change validation. An
update is not authorization to enable or restart the timer or alter monitoring.

## Rollback

Rollback stops future cleanup; deleted expired sessions are intentionally not
recoverable:

1. disable and stop `fh-session-retention.timer`;
2. leave the service stopped and preserve its aggregate journal and marker for
   diagnosis;
3. set `KUMA_SESSION_RETENTION_MONITOR_ENABLED=0` only after documenting the
   rollback so the shared resources monitor does not report an expected stale
   marker;
4. run the standard post-change validation.

Do not delete or rewrite session files, the marker, or the shared production
lock while investigating. Never replace this policy with a broad `find -delete`
or probabilistic PHP session GC.

## Session file permissions

The application file-session driver sets mode `0600` for newly created session
files. Retention continues to reject sessions with unsafe permissions. The
one-time repair path for historical `0644` session files has been retired after
a read-only production scan found no remaining legacy files. Unexpected modes
are investigated as a creation or configuration problem; routine retention does
not change session permissions.
