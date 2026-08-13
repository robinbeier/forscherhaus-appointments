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
traffic-gate, or UI-smoke activity. Ambiguous paths, owners, modes, hard links,
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

## Separate Production Rollout

The following is an ordered future runbook, not merge authorization:

1. Confirm no deploy, dump, restore, replay, traffic gate, smoke, or other
   cleanup is active. Run the standard read-only doctor and cleanup inventory.
2. Install `scripts/ops/libexec/session_retention_v1.py` as the regular,
   single-link, root-owned `0555` file
   `/usr/local/libexec/fh-session-retention-v1`. Never execute the deploy-tree
   copy as root. Copy both unit files from `scripts/ops/systemd/` to
   `/etc/systemd/system/` as root-owned `0644`, run `systemd-analyze verify`,
   then `daemon-reload`. Do **not** enable the timer.
3. Run the repository wrapper in default dry-run mode. Retain the aggregate
   result only. Stop on any blocked result or unknown file count.
4. With a separate live-write GO, run one manual execute pass. Exit `75` with
   `status=partial` means the bound, locked, or cap-limited work is incomplete;
   no success marker is written. Re-inventory before deciding on another pass.
5. After a `status=pass`, verify marker freshness, root disk/inodes, app health,
   renderer/deep health, services, scanner posture, and Kuma raw status.
6. Set `KUMA_SESSION_RETENTION_MONITOR_ENABLED=1` in the protected Kuma push
   environment and verify the existing host-resources push remains green.
7. Only after another explicit approval, enable and start
   `fh-session-retention.timer`. Confirm the next trigger and that the service is
   inactive between runs.

The timer runs daily at 03:37 UTC with up to 15 minutes randomized delay. The
monitor treats a missing, invalid, or older-than-36-hours marker as critical
once monitoring is explicitly enabled.

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
