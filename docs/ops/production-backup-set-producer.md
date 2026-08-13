# Production Backup-Set Producer

ROB-466 defines the only production authority that may create a fresh backup
set for the restore-verification and retention contracts. Repository delivery
does not install, start, or enable the unit and does not authorize a production
database read or backup write.

## Closed authority

The root-only helper accepts no arguments and reads no environment overrides.
It derives one UTC identifier in `YYYYMMDDTHHMMSSZ` form and publishes exactly:

```text
/root/backups/easyappointments/<id>/db/easyappointments.sql.gz
/root/backups/easyappointments/<id>/meta/backup.env
```

The identifier and `created_at_utc` come from the same internally captured UTC
second. The database name, output root, connection file, dump executable and
dump arguments are fixed. The root-owned, mode `0600`, single-link
`/etc/fh/backup-set-producer.cnf` must contain exactly the reviewed TCP
connection configuration for the dedicated `fh_backup` account on the local
host. Only
one bounded base64url password field varies; the group, key order, account,
protocol, host and port are exact, so the file cannot add dump-shaping options.
The account has only the read privileges needed for the fixed single-database
dump and no write, FILE, routine, trigger, event or administrative grants. No
connection material enters argv, environment variables, logs, metadata or
operator output.

The reviewed installation provisions only `SELECT, SHOW VIEW` on
`easyappointments.*` for `fh_backup` at host `127.0.0.1`. The installed file is
exactly six newline-terminated lines in this order: `[client]`,
`user=fh_backup`, `password=<32..128 base64url characters>`, `protocol=tcp`,
`host=127.0.0.1`, `port=3306`. Extra groups, duplicate keys, comments, blank
lines, option includes and dump switches are rejected before the client starts.

The producer uses only `/usr/bin/mariadb-dump` and the closed single-database
table/data surface accepted by the ROB-465 parser. It never falls back to
`mysqldump` and does not include routines, triggers, events, databases,
external directories or caller-selected options.

## Publication and recovery

The helper first acquires the exact shared production-change lock and a private
backup-producer lock. Active or unreconciled deployment, restore, replay,
traffic, smoke, backup or retention work returns retryable exit `75`. Unknown
identity or unsafe filesystem state returns `70`.

Dump and metadata bytes are built under a private same-filesystem nonce
directory. The helper validates the stable dump process, complete gzip stream,
bounded size, SHA-256, canonical metadata and all file identities. It fsyncs
files and directories before an atomic no-replace rename makes the final set
visible. Only a completely published set may advance
`last_backup_success.utc`; marker updates are monotonic and durable.

Producer-owned crash prefixes are reconciled under both locks. Foreign,
unsafe, linked or ambiguous temporary objects block without mutation. A final
set is never overwritten or relabelled with a newer timestamp. Output is one
canonical aggregate JSON record and contains no set identifier, path, digest,
database output, credentials or SQL.

## Operator boundary

The producer is manual-only. ROB-466 deliberately ships no service or timer,
so a merged repository cannot start recurring database reads. The operator
wrapper invokes exactly the installed helper:

```bash
bash scripts/ops/prod_backup_set_producer.sh \
  --execute \
  --confirm-live-write ROB-466
```

Without both live flags the wrapper only prints the fixed plan and performs no
SSH call.

For the initial ROB-461 wave, install the reviewed helper without adding an
autonomous schedule. Create exactly two fresh sets serially. After
each producer pass, run the ROB-465 verifier for the internally returned set
identifier through a protected host-local handoff; do not copy identifiers to
Linear, chat or logs. Validate two independent restore attestations before any
ROB-453 retention execute pass. A merge is not production authorization.

Rollback is restoring the prior installed manual helper and wrapper; there is
no timer or service to disable. Already published sets and attestations remain
immutable evidence. Never delete or rename a published set as rollback.
