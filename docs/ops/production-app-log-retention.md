# Production Application-Log Retention

ROB-452 defines a conservative retention policy for the daily CodeIgniter
application logs. Repository delivery does not install or enable the timer and
does not authorize a production command.

## Closed Retention Class

The only deletable class is a regular, single-link `www-data:www-data` `0644`
file named `log-YYYY-MM-DD.php` directly below
`/var/www/html/easyappointments/storage/logs`. Eligibility uses the stable file
modification time, not the filename: a file is eligible at or beyond 60 days
(5,184,000 seconds). The helper takes a nonblocking exclusive file lock and
rechecks identity and age immediately before unlinking.

The following classes are retained and never traversed or deleted:

- `release-gate/`, `ci/`, and `ops/` evidence directories;
- `.htaccess` and `index.html`;
- the four fixed dashboard/provider PDF diagnostic files.

Any other name, symlink, special file, hard link, owner, mode, size, directory
identity, or protected-class drift blocks the complete pass. App logs larger
than 128 MiB also require review rather than automatic deletion. This contract
does not clean Journald, Apache logs, backups, sessions, databases, deploy
timing, uploads, cache, or any nested evidence.

## Bounds and Output

One pass deletes at most 1,000 files and at most 512 MiB. If more eligible data
remains, the result is `partial`, no success marker is published, and a later
pass is required. Output is one canonical aggregate JSON record containing
counts, byte totals, status, and fixed policy values. It never contains a
filename, log line, customer datum, path selected from a directory entry, or
secret.

Execute mode holds the private state-directory lock and the shared production
change lock. A deploy, recovery, or other cooperating production mutation
therefore makes the pass fail closed. A complete pass atomically publishes the
root-owned `0600` marker `/var/lib/fh-app-log-retention/last-success.json`.

The systemd service is root only to unlink `www-data` files and publish its
protected marker. Its capability boundary is exactly `CAP_DAC_OVERRIDE`;
ambient capabilities are empty and writable paths are restricted to the log
root, state directory, and shared lock file.

## Repository Validation

The operator wrapper is read-only by default:

```bash
bash scripts/ops/prod_app_log_retention.sh
```

Live deletion is a distinct command and remains unauthorized by merge:

```bash
bash scripts/ops/prod_app_log_retention.sh \
  --execute \
  --confirm-live-write ROB-452
```

The Linux root gate exercises the fixed production roots, exact 60-day
boundary, protected classes, locks, identity/type/owner/mode drift, byte and
file caps, aggregate output, marker publication, replay, and service capability
boundary.

## Separate Production Rollout

These steps are documentation for a later, separately approved operation:

1. Install `app_log_retention_v1.py` as root-owned `0755`
   `/usr/local/libexec/fh-app-log-retention-v1`; never run the deploy-tree copy.
2. Install the service and timer as root-owned `0644`, then run
   `systemd-analyze verify` without enabling either unit.
3. Run the wrapper in default read-only mode and retain only its aggregate JSON.
4. Confirm that the protected count covers the expected static, diagnostic,
   and evidence classes and that no pass is blocked or capped.
5. Obtain a new explicit production live-write approval before the first
   `--execute` pass.
6. Re-run the normal production doctor and cleanup inventory, then inspect only
   the aggregate marker status.
7. Obtain a separate activation approval before enabling the timer and its
   disabled-by-default monitor.

Deletion has no in-place rollback. A wrong or unclassified entry is therefore
a hard pre-delete stop; recovery of a legitimately deleted daily log would be a
separate backup/incident procedure, not an automatic rollback. To stop future
runs, disable and stop the timer. Removing units, helper, or marker is also a
separate production change and must not delete any remaining app logs.
