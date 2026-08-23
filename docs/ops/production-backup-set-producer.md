# Production Backup-Set Producer

ROB-466 defines the only production authority that may create a fresh backup
set for the restore-verification and retention contracts. Repository delivery
does not install, start, or enable the ROB-480 recurring units and does not
authorize a production database read, restore, backup write, or scheduler
cutover.

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
connection configuration for the dedicated `fh_backup` account through the
host-local `127.0.0.1:3306` TCP listener. A Unix-domain socket is not an
accepted or fallback connection path. Only
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
Its fixed `--no-autocommit` option makes the per-table transaction wrapper
explicit rather than relying on a client-version default.
The parser accepts only the reviewed MariaDB sandbox preamble spellings,
including the official form with exactly one trailing space before the line
ending. It also accepts the exact standard saved-client-charset sequence that
MariaDB emits around ordinary `CREATE TABLE` definitions: save, set `utf8mb4`,
create, restore. Missing, reordered or detached steps, added assignments,
alternate sources and session modifiers remain rejected.
For table-data blocks it also accepts only MariaDB's exact paired autocommit
sequence: save and disable autocommit, lock one table, disable its keys, zero
or more inserts into that same table, enable its keys, unlock, `COMMIT`, then immediate
restore. A missing, duplicated, reordered, detached or augmented control
statement remains rejected.
Additional whitespace and malformed control lines remain rejected.

## Publication and recovery

The helper first acquires the exact shared production-change lock and a private
backup-producer lock. Active or unreconciled deployment, restore, replay,
traffic, smoke, backup or retention work returns retryable exit `75`. Unknown
identity or unsafe filesystem state returns `70`.

Dump and metadata bytes are built under a private same-filesystem nonce
directory. The helper validates the stable dump process, complete gzip stream,
bounded size, SHA-256, canonical metadata and all file identities. The gzip
header timestamp is derived exactly from the trusted backup-set UTC identifier;
this gives independent sets of unchanged SQL distinct digest authorities while
keeping immutable set replay byte-exact. This is an exact set-header binding,
not a promise that separately recompressing SQL reproduces prior bytes. Attach
rejects a gzip timestamp that does not match its set identifier. The helper
fsyncs files and directories before an atomic no-replace rename makes the final
set visible. Only a completely published set may advance
`last_backup_success.utc`; marker updates are monotonic and durable.

Producer-owned crash prefixes are reconciled under both locks. Foreign,
unsafe, linked or ambiguous temporary objects block without mutation. A final
set is never overwritten or relabelled with a newer timestamp. Output is one
canonical aggregate JSON record and contains no set identifier, path, digest,
database output, credentials or SQL.

The matching restore verifier has one additional closed selector,
`--latest-handoff`. It acquires the same production-change lock, reads
`last_backup_set.json` through a stable no-follow file descriptor, validates
its exact canonical schema, requires the independently durable backup-success
marker to name the same completed set, and binds the selected ID, dump digest,
compressed size and uncompressed size to the pinned dump before restore. Its
operator result contains only schema and status; the protected set identifier
never crosses SSH or enters the wrapper output.

## Recurring continuity contract

ROB-480 adds a disabled desired-state schedule around the unchanged ROB-466
and ROB-465 authorities. `fh-backup-set-continuity.timer` targets one closed
producer-to-verifier chain once per day at `02:17 UTC`. The timer is explicitly
`Persistent=false`, so starting it after the cutover cannot create an
unreviewed catch-up database read. The repository never enables or starts it.

The timer targets `fh-backup-set-producer.service`. Before the producer advances
the protected handoff or backup marker, it atomically publishes the exact new
handoff into root-only `backup_continuity_state.json` with status `pending`.
Only then may it advance `last_backup_set.json` and
`last_backup_success.utc`. A successful producer service activates
`fh-backup-set-restore-verify.service`, which accepts only the fixed
`--continuity-state` selector. The verifier requires the pending state, current
handoff, marker, pinned dump identity, sizes and digest to agree before restore.
Only after an attached or newly published canonical attestation and durable
restore marker does it atomically transition the same state to `verified`.

A pending state is replayable only inside the unchanged four-hour dump-
freshness boundary and blocks publication of another set. The restore service
retries a failed exact-state verification every 15 minutes, rate-limited to 16
starts per rolling four hours. At the unchanged four-hour boundary the helper
returns the dedicated non-retryable stale-state result. A producer retry inside that window validates the same
immutable set and repairs only its matching handoff and marker before the
verifier runs again. A verified state must match the current handoff and marker
before a fresh set can begin. Producer crashes before or between state, handoff
and marker publication therefore recover to the same set; verifier crashes
leave `pending` as an explicit unknown-or-incomplete restore outcome. An
expired pending state is a deliberate manual stop, never authority to discard
the unknown outcome or create a replacement set. No protected identifier
crosses SSH or appears in the aggregate wrappers.

Producer and verifier independently implement the same closed canonical state
schema. This is intentional: neither privileged executable imports mutable
logic from the other authority. Root regression coverage feeds producer-
canonical pending bytes into the verifier and proves the exact transition, so
schema drift fails before publication.

The legacy cron does not participate in this new state protocol and is not
described as serialized by it. During the overlap period a legacy writer or
marker update is classified as activity or makes the pending state binding
fail closed; it cannot be accepted as the canonical continuity proof. The
manual proof must run in a revalidated quiet interval outside both legacy cron
slots, with no active legacy writer or verifier.

The producer remains the only writer of `last_backup_success.utc` and
`last_backup_set.json`; the verifier remains the only writer of the canonical
attestation and `last_verify_success.utc`.

Both services are oneshot, root-owned desired state with an empty ambient
capability set, strict filesystem protections and fixed absolute executables.
The producer service has an empty capability bounding set, can create only TCP
sockets, and makes the Docker socket and deployment-evidence root explicitly
inaccessible. The verifier service alone is bounded to `CAP_DAC_OVERRIDE`,
`CAP_DAC_READ_SEARCH`, and `CAP_SYS_PTRACE`, matching the maximum required by
its root-protected restore and process-identity checks without granting network,
mount, ownership, administration or discretionary extra capabilities. The
helpers retain their own trust, stable-binary, shared-lock, parent-death,
Docker-socket, daemon, attestation and crash-recovery checks; service hardening
does not replace or weaken them.

The former host-local writer and restore verifier remain outside this
authority. During migration their dedicated cron stays installed and active
until one fresh canonical set and its state-bound restore attestation have
both passed. After that proof, a quiet-host cutover must atomically move the
exact cron file without replacement to a root-protected, cron-ignored rollback
name on the same filesystem before the new timer is enabled and started. The
five legacy reference objects are not part of this scheduler mutation.

## Operator boundary

The manual producer wrapper remains the pre-cutover and diagnostic execution
gate. It invokes exactly the installed producer followed, only after producer
success, by the continuity-state restore verifier:

```bash
bash scripts/ops/prod_backup_set_producer.sh \
  --execute \
  --confirm-live-write ROB-466 \
  --confirm-live-restore ROB-461
```

Without `--execute` and both exact confirmations the wrapper performs no SSH
call. A confirmation supplied in plan mode is rejected. This is a fail-closed
two-stage attempt using two separate SSH invocations, not one remote shell or
one lock held across both processes. The verifier's remote process context
therefore cannot retain the producer command in its own ancestor command line;
each helper still performs the unchanged activity gate and acquires the shared
production lock independently. The durable pending record binds the second
stage to the exact first-stage output. An SSH disconnect, intervening lock
owner, activity or marker drift can therefore leave `pending`, but can never be
accepted as continuity evidence; the cutover stops until the exact state is
verified.

Install `verify_deployment_dump_v1.php` and its reviewed PHP library
dependencies beneath `/usr/local/libexec/fh`, alongside the already required
ROB-465 helper. The separate handoff consumer is also plan-only by default and
requires the independent ROB-461 live-restore confirmation:

```bash
bash scripts/ops/prod_verify_latest_deployment_dump.sh \
  --execute \
  --confirm-live-restore ROB-461
```

It invokes only the fixed installed verifier with `--latest-handoff`; it does
not accept a set ID, path, digest or environment override. This selector can
independently revalidate the already `verified` handoff-to-attestation binding.
It is not the recurring ROB-480 chain: only the producer service's `OnSuccess`
verifier uses `--continuity-state` and may transition `pending` to `verified`.

The restore helper waits for the final MariaDB server with networking enabled;
it never treats the image entrypoint's temporary initialization server as
restore-ready. Clean shutdown evidence is published only after that final
server has completed the fixed import and verification sequence.

Install and validate the reviewed recurring units without activating them:

```bash
sudo /usr/bin/install -o root -g root -m 0555 \
  scripts/ops/libexec/backup_set_producer_v1.py \
  /usr/local/libexec/fh-backup-set-producer-v1
sudo /usr/bin/install -o root -g root -m 0555 \
  scripts/ops/libexec/backup_set_producer_supervisor_v1.sh \
  /usr/local/libexec/fh-backup-set-producer-supervisor-v1
sudo /usr/bin/install -o root -g root -m 0644 \
  scripts/ops/systemd/fh-backup-set-producer.service \
  /etc/systemd/system/fh-backup-set-producer.service
sudo /usr/bin/install -o root -g root -m 0644 \
  scripts/ops/systemd/fh-backup-set-continuity.timer \
  /etc/systemd/system/fh-backup-set-continuity.timer
sudo /usr/bin/install -o root -g root -m 0644 \
  scripts/ops/systemd/fh-backup-set-restore-verify.service \
  /etc/systemd/system/fh-backup-set-restore-verify.service
sudo /usr/bin/systemd-analyze verify \
  /etc/systemd/system/fh-backup-set-producer.service \
  /etc/systemd/system/fh-backup-set-continuity.timer \
  /etc/systemd/system/fh-backup-set-restore-verify.service
sudo /usr/bin/systemctl daemon-reload
/usr/bin/systemctl is-enabled fh-backup-set-continuity.timer
/usr/bin/systemctl is-active fh-backup-set-continuity.timer
```

The fixed supervisor must remain the producer service's direct child of
systemd. It keeps the Python producer as a distinct child so the unchanged
`PR_SET_PDEATHSIG(SIGKILL)` guard binds the mutating process to that trusted
parent; replacing the service command with a direct Python invocation is not a
supported profile.

The required pre-cutover state is `disabled` and `inactive`; both services must
also be inactive. Continue only with the exact installed helper and unit hashes,
no unsafe or unrecognized continuity-state object, free shared/private locks,
no active backup/restore/deploy/retention work, the
expected dedicated legacy cron identity, and green application, Docker and
monitoring health.

The no-gap cutover order is fixed:

1. Leave the legacy cron unchanged and run one explicitly approved manual
   canonical continuity attempt with both exact confirmations. Run outside the
   legacy `02:17` and `02:40 UTC` slots after proving both legacy processes
   absent. Require producer status `published`, a new canonical restore
   attestation, and matching fresh backup and restore markers from the same
   pending-state binding. If crash recovery returns producer status `attached`,
   complete its verifier transition and repeat the combined pass until the
   fresh proof is `published`; do not cut over on the attachment alone. The
   producer/verifier lock gap is not proof: any intervening activity, SSH
   disconnect or non-success leaves the cutover blocked on the pending state.
2. Independently revalidate the handoff-to-attestation binding with the fixed
   `prod_verify_latest_deployment_dump.sh` route without exposing the protected
   identifier.
3. Revalidate identities, locks, processes, cron schedule, helper/unit hashes,
   Docker socket/daemon and host health. Any drift stops before scheduler
   mutation.
4. In one bounded rollback-capable operation, no-clobber move the exact
   dedicated cron file to its fixed cron-ignored same-filesystem rollback name,
   enable and start only `fh-backup-set-continuity.timer`, and verify `enabled`,
   `active`, `Persistent=false`, and the next `02:17 UTC` trigger. Starting the
   timer must not execute the producer immediately.
5. Repeat marker/handoff binding, service/timer state, cron absence, process,
   application/deep-health, Docker and Kuma checks. Do not move, quarantine or
   delete the legacy five-object group in this gate.

If any scheduler mutation or postflight fails before an unknown producer or
restore outcome, stop and disable the new timer, atomically restore the exact
cron file from the fixed rollback name, reload cron/systemd state, and verify
the prior schedule and health. If a producer or restore process started but no
canonical result was captured, the mutation outcome is unknown: keep both
schedulers stopped, inspect only through the normal locks, and obtain a new
decision. Published sets and attestations are immutable and are never renamed
or deleted as rollback.

For the initial ROB-461 wave, the reviewed helper was installed without an
autonomous schedule. Exactly two fresh sets were created serially. After each
producer pass, run the protected handoff consumer above; do not copy
identifiers to Linear, chat or logs. Validate two independent restore
attestations before any ROB-453 retention execute pass. A merge is not
production authorization.

After a successful ROB-480 scheduler cutover, rollback returns to the exact
pre-cutover dedicated cron file as described above; it never rolls back data.
Already published sets and attestations remain immutable evidence. Never delete
or rename a published set as rollback.
