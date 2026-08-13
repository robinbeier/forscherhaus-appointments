# Production Journald Retention

ROB-451 fixes the persistent system journal at a maximum of 1 GiB and retains
at most 30 days. Repository delivery only ships the contract. It does not
install the helper, change journald, restart a service, vacuum a journal, or
enable monitoring on production.

## Fixed contract

The only managed drop-in is
`/etc/systemd/journald.conf.d/60-fh-journald-retention.conf`:

```ini
[Journal]
SystemMaxUse=1G
MaxRetentionSec=30day
```

The helper accepts the managed file only when it is a root-owned regular
`0644` file with one link below a protected root-owned directory chain. Active
`SystemMaxUse` or `MaxRetentionSec` assignments in the main configuration or
another drop-in are conflicts, not precedence guesses. Missing, changed,
duplicated, unreadable, or unsafe configuration fails closed.

The 30-day window preserves current incident evidence. ROB-445 deploy timing is
independently retained in its protected per-run source and does not depend on
journald. Before any future vacuum, confirm both facts again from aggregate
metadata; never print journal entries or timing file contents.

## Read-only inspection

After the reviewed helper has separately been installed root-owned and
non-writable at `/usr/local/libexec/fh-journald-retention-v1`, inspection is the
default:

```bash
bash scripts/ops/prod_journald_retention.sh
```

Output is one canonical aggregate object. It contains only contract status,
normalized limits, exact allocated bytes below the protected persistent
`/var/log/journal` tree, and a fixed reason class. It never
contains journal entries, units, paths from entries, customer data, or secret
values. `pass` requires the exact unambiguous drop-in and usage at or below
1 GiB. `drift` or `invalid` is a stop condition.

## Three separate production changes

The following commands describe future, separately approved operations. They
are not authorized by merge and must not be combined into one approval.
Configuration and one-time vacuum are deliberately separate approvals.

Install and activate only the fixed configuration:

```bash
bash scripts/ops/prod_journald_retention.sh \
  --apply-config \
  --confirm-live-write ROB-451-CONFIG
```

The helper holds the shared production-change lock, publishes the drop-in with
atomic no-replace semantics, fsyncs it and its parent, validates the exact
merged configuration with `systemd-analyze cat-config`, and restarts
`systemd-journald.service` even when the exact file was already attached. A failed first
activation removes only the file it just published and restarts journald with
the prior configuration.

Existing journal usage above 1 GiB is reported as `applied_needs_vacuum`; it is
not silently treated as a configuration failure and does not authorize the
separate vacuum step.

Only after the configuration is effective, host health is green, the 30-day
incident window is accepted again, and a new approval is recorded, rotate and
vacuum archived journal data:

```bash
bash scripts/ops/prod_journald_retention.sh \
  --vacuum \
  --confirm-live-write ROB-451-VACUUM
```

The vacuum uses both fixed bounds and reports only aggregate before, after, and
reclaimed bytes. It never broadens scope to application, Apache, database,
backup, release, Docker, or provider data.

Rollback removes only the byte-exact managed drop-in and restarts journald:

```bash
bash scripts/ops/prod_journald_retention.sh \
  --rollback-config \
  --confirm-live-write ROB-451-ROLLBACK
```

A missing managed file is an idempotent rollback. A different or unsafe file
at the managed path is never removed automatically.

## Validation and monitoring

For each separately approved production change:

1. run `prod_doctor.sh` and the default journald inspection;
2. stop on a busy production-change lock, configuration conflict, unsafe path,
   unavailable aggregate usage, or non-green host health;
3. execute only the approved configuration, vacuum, or rollback operation;
4. rerun the default inspection and `prod_validate_after_change.sh`;
5. record only aggregate status, limits, and byte counts in Linear.

`KUMA_JOURNALD_RETENTION_MONITOR_ENABLED=0` is the shipped default. After a
separately approved production activation and successful validation, changing
it to `1` makes the existing host-resources Push monitor fail closed on missing,
changed, conflicting, unreadable, or over-limit journald state. The Push
message contains only `journald_retention=drift|invalid`.

## Stop conditions

Stop without mutation if the production shape differs from the documented
Ubuntu/systemd host, another retention assignment exists, the managed path or
an ancestor is unsafe, ROB-445 evidence is not independently available, the
accepted incident window would be shortened, or rollback direction is unclear.
No command here grants production authorization.
