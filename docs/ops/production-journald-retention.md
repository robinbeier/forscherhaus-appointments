# Production Journal Maintenance

Use systemd's built-in journal rotation and the existing host disk-space alert.
There is no repository-owned journal helper or scheduled vacuum. The repository
does not impose a 1 GiB / 30-day policy. Merging this documentation does not
change production configuration.

## Inspect before deciding

Run `bash scripts/ops/prod_doctor.sh` for host health and free space. For an
aggregate journal size, use the approved production SSH target from
[agent-operations.md](agent-operations.md):

```bash
ssh root@188.245.244.123 'journalctl --disk-usage'
```

Do not print journal entries or protected configuration. If disk usage is
healthy, no manual cleanup is needed. If the disk alert fires, identify the
large storage category first; journal cleanup cannot fix growth in Docker,
backups, application logs, or other directories.

Without overrides, `SystemMaxUse` defaults to 10% of the filesystem, capped at
4 GiB; `SystemKeepFree` also constrains growth. Only archived journal files
are removed, so active files can leave usage above the nominal limit. Verify
host configuration before relying on these defaults. See the upstream
[journald configuration reference](https://www.freedesktop.org/software/systemd/man/latest/journald.conf.html).

## Manual cleanup when needed

1. Check host health and journal size. Confirm that the records needed for any
   current incident have been preserved.
2. Agree on the journal history that may be discarded and obtain explicit
   approval for that deletion. A size target does not guarantee a minimum
   number of days of history.
3. Use native `journalctl --rotate` followed by a vacuum with the approved
   size or age bound. For example, `journalctl --vacuum-size=1G` deletes old
   archived journal files toward a 1 GiB target; it is an example, not a
   standing policy or authorization. Do not delete journal files by hand.
4. Read `journalctl --disk-usage` again and run
   `bash scripts/ops/prod_validate_after_change.sh`. Record only aggregate
   before/after sizes and health status.

Deleted journal history cannot be restored by undoing a setting. A one-time
vacuum does not install a lasting limit or require a journald restart. If
repeated manual cleanup becomes necessary, consider a native `SystemMaxUse`
setting before introducing another tool or scheduled job.

## Installed monitoring

The [`kuma_push_host_resources.sh`](../../scripts/ops/kuma_push_host_resources.sh)
source no longer contains the retired journald-retention branch.
Older installed bundles may still contain it, disabled by default; keep it
disabled until a separately approved package replacement removes it. Its helper
is no longer supplied by this repository.
Do not remove an installed helper while an active monitor depends on it;
review the installed dependency first. Keep the ordinary disk, memory, load, and other
retention checks. Repository changes do not update the installed monitoring
bundle or remove host-local files.
