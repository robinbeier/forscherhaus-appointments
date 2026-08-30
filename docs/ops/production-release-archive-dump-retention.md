# Production Release, Archive, and Dump Retention

ROB-453 defines one conservative, class-aware retention pass for the large
production artifacts that are outside session and Docker build-cache
retention. Repository delivery does not activate the timer and does not grant
permission to run the helper on production.

## Fixed classes

The helper recognizes only these protected roots and identities:

- release directories directly below `/var/www/html` named
  `easyappointments_prev_*`, `easyappointments_*_stage`, or
  `easyappointments_failed_*`;
- exact archive pairs `/root/releases/<release>.tar.gz` and
  `<release>.build-provenance.json` validated against canonical
  `release_build_provenance.v1` bytes;
- the fixed `/etc/fh/legacy-release-hold.v1.json` for the two explicitly held
  legacy archives; see `production-legacy-release-hold.md`.
- root-owned backup sets below `/root/backups/easyappointments` whose
  exact canonical `meta/backup.env` is admitted by the compile-time-pinned
  `dump_producer_registry.v1`, and whose `db/easyappointments.sql.gz` has an
  exact canonical, independently restore-verified attestation below
  `/var/lib/fh-deploy-evidence/dump-attestations`.

Unknown names, unsafe ownership/mode/type/link identity, mount crossings,
missing protected current/rollback archives, an active or unreconciled Host
Runner, known deploy/backup work, a busy global production-change lock, or an
open deletion candidate blocks execute mode. Output is aggregate-only: no
release, archive, backup, dump, or application-storage names or bytes are
printed.

The same helper exposes the separate read-only `admission-status` command
documented in `production-dump-producer-admission.md`. It acquires the shared
lock, rechecks activity, validates every registered manifest/attestation
binding and fixed top-level authority, and reports aggregate counts plus the
pinned registry digest and zero-mutation ledger. It has no execute variant;
unknown producers, objects, writers, schemas, or manifest drift return blocked
rather than weakening this retention contract.
An exact canonical pending continuity handoff may identify one manifest-bound
set before its restore attestation is published. It remains separately counted
as pending and forces `execution_ready=false`; it is never a verified retention
candidate or an unclassified foreign object.

### Service mount boundary

Protected release, archive, dump, evidence, and orchestrator trees still reject
every nested mount by default. The hardened systemd service has one narrower
requirement: `ProtectSystem=strict` and the read-only orchestrator tree cause
systemd to expose the already trusted global production-change lock through a
writable bind mount at
`/var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock`. A direct
helper invocation has no such boundary and receives no exception.
Conversely, the exact retention-service cgroup must expose this boundary; its
absence is treated as sandbox drift and fails closed.

The helper accepts at most that one service-created lock boundary. A pathname
match alone is never sufficient. The process must have the exact
`/system.slice/fh-release-archive-dump-retention.service` cgroup, a closed
systemd invocation ID, and a stable mount namespace distinct from PID 1. The
lock mount must be the writable child of the immediately containing read-only
namespace-root mount (mount point `/`, root `/`); child and parent must have the
same filesystem, source, superblock
device and super options, and the child's mount root must be derived exactly
from the parent root and target path. The observed device must also equal the
device of the open lock file.

Before accepting the boundary, the helper opens the lock through already
trusted root-owned `0700` parents and pins a regular, empty, root-owned
`0600`, single-link identity. It compares path and descriptor identity and
reads mountinfo, cgroup, and namespace identity before and after that check.
The complete observation may be attempted at most five times to tolerate a
concurrently settling host mount table; only one internally identical
before/after pair is ever validated, observations are never combined, and five
changing pairs still fail closed. Kernel-generated mountinfo has a separate
bounded 16 MiB snapshot allowance (1 MiB per line) so legitimate runner or
container overlay graphs are not confused with malformed state.
Linux `nsfs` network-namespace mounts can expose their filesystem root as the
kernel handle `net:[inode]` rather than as a slash path. The parser accepts only
that closed positive-inode form when both filesystem type and mount source are
exactly `nsfs`; mount points remain absolute.
The parser compatibility grants no protected-path exception. Any such mount at
or below a protected tree is still an additional nested boundary and is rejected.
Execute acquires the same strict mount preflight before state-directory
preparation and keeps the validated web-root, orchestrator-root, `/var/lib`
parent, and any existing state-directory descriptors open through marker-temp
cleanup. The existing state path and descriptor identities must still match at
the immediate pre-cleanup revalidation; the path is not reopened by name. If
systemd did not already create the state directory, the complete
pinned-boundary observation is repeated immediately before that directory is
created through the pinned parent. That controlled creation is accepted only
when the parent device, inode, mode, owner and group remain identical, the
directory inventory gains exactly `fh-release-retention`, and the parent link
count follows either native Linux directory semantics (`+1`) or the supported
overlay semantics (unchanged). The post-creation parent identity becomes the
new pinned baseline; any pre-existing target, additional name, removal, larger
link-count change, or other parent drift still fails closed. After acquiring
the state lock, descriptor and path identities and the complete
mount/cgroup/namespace observation are
repeated again immediately before the first cleanup mutation. Cleanup itself
uses the already open, validated state-directory descriptor, so a later
pathname mount cannot redirect the operation. The locked inventory then
performs the full mount check again with its own pinned target descriptors.

Any Symlink, Hardlink, detected race, malformed state, additional protected
mount, different source/device/root/parent, non-service context, or changing
namespace remains fail-closed as `unsafe_global_lock`, `mount_state_unknown`,
or `nested_mount_boundary`. Drift at acquisition or the immediate pre-mutation
revalidation retains the zero-mutation ledger. Non-cooperating privileged host
mount changes after that last observation are outside the service authority;
the helper has no `CAP_SYS_ADMIN`, continues to operate only through pinned
descriptors, and rejects drift on the next bounded observation. This exception
does not change the service capability set or any activity, open-file, lock,
capacity, hold, continuity, handoff, admission, parent-death, Docker, or socket
check.

Every `prod_release_archive_dump_retention.v3` result also carries
`mutation_outcome` and fixed aggregate `mutation_counts`. `none` means no
marker-temp cleanup, pending cleanup, or candidate was removed and
`deletion_performed` is `false`. `known` means at least one removal is counted
and `deletion_performed` is `true`, including when a later lock/activity check,
second inventory, capacity result, or marker publication blocks the pass.
`unknown` means an irreversible operation was interrupted between its bounded
start and confirmation; `deletion_performed` is then JSON `null`, never
`false`. The counts distinguish release directories, dump sets, archive files,
the same three pending-cleanup classes, and marker temp files without exposing
identities. These mandatory keys and nullable unknown semantics are v3; older
result must not be interpreted as this contract. The separate durable success
marker remains `prod_release_archive_dump_retention_marker.v1`.

## Conservative policy

| Class | Minimum age | Always protected | Maximum removals per pass |
|---|---:|---|---:|
| previous/stage/failed release directories | 7 days | current directory and exact rollback directory | 4 per class |
| complete archive/provenance pairs | 30 days | current, rollback, then newest complete pairs until 4 release IDs are protected | 8 pairs |
| verified backup sets | 30 days from attestation | newest 2 independently restore-verified dump SHA-256 values | 4 sets |

An archive-only prefix must exactly match a valid permanent legacy hold by
release ID, archive name, full hash, and size; otherwise it is corruption and
blocks. Held archives are never deletion candidates, including after marker
rotation. A sidecar without its archive is corruption and blocks.
When a verified backup set is removed, its small root-protected attestation is
retained as audit evidence.

Capacity uses one `statvfs` snapshot on the shared production filesystem and
`f_bavail * f_frsize`. Projected growth is the current authorized archive size,
authorized unpacked-stage and scratch bounds, plus
`max(live-storage allocated bytes, live-storage logical bytes)`, plus headroom
of `max(512 MiB, 10%)`. Readiness requires at least `max(2 GiB, projected
growth)` available and both observed and projected use strictly below 85%.

## Operator boundary

Default inspection is read-only:

```bash
bash scripts/ops/prod_release_archive_dump_retention.sh
```

One bounded live pass additionally requires the exact ROB-453 confirmation:

```bash
bash scripts/ops/prod_release_archive_dump_retention.sh \
  --execute \
  --confirm-live-write ROB-453
```

This command is documentation, not current production authorization. A future
rollout has this exact order:

1. Merge the reviewed change. From that exact checkout, install the helper as
   the regular, single-link, root-owned `0555` production copy; never execute
   the deploy-tree copy as root:

   ```bash
   sudo /usr/bin/install -o root -g root -m 0555 \
     scripts/ops/libexec/release_archive_dump_retention_v1.py \
     /usr/local/libexec/fh-release-archive-dump-retention-v1
   ```

2. Install and verify the units without activating them. `is-enabled` must
   print `disabled` and `is-active` must print `inactive` before proceeding:

   ```bash
   sudo /usr/bin/install -o root -g root -m 0644 \
     scripts/ops/systemd/fh-release-archive-dump-retention.service \
     /etc/systemd/system/fh-release-archive-dump-retention.service
   sudo /usr/bin/install -o root -g root -m 0644 \
     scripts/ops/systemd/fh-release-archive-dump-retention.timer \
     /etc/systemd/system/fh-release-archive-dump-retention.timer
   sudo /usr/bin/systemd-analyze verify \
     /etc/systemd/system/fh-release-archive-dump-retention.service \
     /etc/systemd/system/fh-release-archive-dump-retention.timer
   sudo /usr/bin/systemctl daemon-reload
   /usr/bin/systemctl is-enabled fh-release-archive-dump-retention.timer
   /usr/bin/systemctl is-active fh-release-archive-dump-retention.timer
   ```

3. Run the exact default dry-run. Continue only when `status` is `pass`,
   `execution_ready` and `capacity.capacity_passed` are `true`, and every
   `would_delete` value is understood. There is no separate cap boolean:

   ```bash
   bash scripts/ops/prod_release_archive_dump_retention.sh
   ```

4. Verify backup/restore evidence and host health, then obtain a separate
   production write approval for exactly one bounded manual pass:

   ```bash
   bash scripts/ops/prod_release_archive_dump_retention.sh \
     --execute \
     --confirm-live-write ROB-453
   ```

   `status=partial` plus nonzero `remaining` fields is the exact class-cap
   signal. `status=partial` with `capacity.capacity_passed=false` is the exact
   capacity non-convergence signal and may have zero `remaining` counts.
   `status=blocked` is a failed safety check. None of these results writes the
   success marker.

5. Whether execute passes, partially converges, blocks, or loses its result,
   run the exact postflight and marker checks before any further decision:

   ```bash
   bash scripts/ops/prod_doctor.sh
   bash scripts/ops/prod_cleanup_inventory.sh
   ssh -o StrictHostKeyChecking=accept-new \
     "${PROD_SSH_TARGET:-root@188.245.244.123}" \
     '/usr/local/libexec/fh-release-archive-dump-retention-v1 marker-status 691200'
   ```

   Verify application/deep health, disk and inode capacity, and the aggregate
   marker. A `partial`, `blocked`, `known`, or `unknown` mutation outcome never
   substitutes for this postflight.

6. Obtain a separate monitoring approval and use the exact-commit,
   root-controlled ROB-490 transaction from
   `docs/ops/production-kuma-monitoring-env.md` to set
   `KUMA_RELEASE_RETENTION_MONITOR_ENABLED=1` in the protected Env. The helper
   installation and the single Env transaction are separate modes. Only after
   their exact postflight may the separately approved existing Push run:

   ```bash
     ssh -o StrictHostKeyChecking=accept-new \
     "${PROD_SSH_TARGET:-root@188.245.244.123}" \
     'KUMA_PUSH_ENV_FILE=/root/backups/uptime-kuma-push.env /usr/local/libexec/fh-kuma-push-runtime-v1/scripts/ops/kuma_push_host_resources.sh'
   ```

7. Enabling and starting are separate gates because `Persistent=true` may run
   a missed schedule immediately when the timer starts. First enable the timer
   without starting it and prove that it remains inactive:

   ```bash
   ssh -o StrictHostKeyChecking=accept-new \
     "${PROD_SSH_TARGET:-root@188.245.244.123}" \
     '/usr/bin/systemctl enable fh-release-archive-dump-retention.timer && /usr/bin/systemctl is-enabled fh-release-archive-dump-retention.timer && /usr/bin/systemctl is-active fh-release-archive-dump-retention.timer'
   ```

   At a separately approved activation window, repeat the production doctor,
   cleanup inventory, and exact retention dry-run. The approval must explicitly
   cover one possible immediate catch-up execute. Only with those fresh gates
   green may the timer be started:

   ```bash
   bash scripts/ops/prod_doctor.sh
   bash scripts/ops/prod_cleanup_inventory.sh
   bash scripts/ops/prod_release_archive_dump_retention.sh
   ssh -o StrictHostKeyChecking=accept-new \
     "${PROD_SSH_TARGET:-root@188.245.244.123}" \
     '/usr/bin/systemctl start fh-release-archive-dump-retention.timer && /usr/bin/systemctl is-enabled fh-release-archive-dump-retention.timer && /usr/bin/systemctl is-active fh-release-archive-dump-retention.timer && /usr/bin/systemctl list-timers --all fh-release-archive-dump-retention.timer && /usr/bin/systemctl show fh-release-archive-dump-retention.service -p ActiveState -p SubState -p Result -p ExecMainStatus'
   ```

   Confirm `enabled`, `active`, the next trigger, and a successful or still
   inactive service result. Then repeat the marker and full postflight checks
   from step 5 before declaring activation complete.

The shipped weekly timer is deliberately not enabled or started by repository
code. Monitoring of the success marker is separately disabled by default until
activation is explicitly approved.

An execute process terminated by a signal, SSH loss, or any other condition
that yields no single canonical helper result has an operator-side mutation
outcome of **unknown**. Never infer `deletion_performed:false` from an exit code,
empty output, the previous dry-run, or a missing success marker. Keep the timer
disabled, re-run the read-only inventory and health checks, inspect only the
aggregate pending/current class counts under the normal locks, and obtain a new
write decision before any recovery pass.

The hardened service has an empty ambient capability set and an exact bounding
set containing only `CAP_DAC_OVERRIDE`, `CAP_DAC_READ_SEARCH`, and
`CAP_SYS_PTRACE`. The DAC capabilities cover root-protected class inspection;
`CAP_SYS_PTRACE` is limited to the pre-delete `/proc` check for foreign open
file descriptors, current/root directories, and mapped candidate inodes. No
network, mount, administration, ownership, or discretionary extra capability
is granted.

## Marker and monitoring

A fully converged execute pass atomically publishes root-owned mode `0600`
`/var/lib/fh-release-retention/last-success.json`. Partial or blocked runs do
not publish success. `marker-status <max-age-seconds>` exposes only `pass`,
`missing`, `stale`, or `invalid` plus aggregate age. The recommended weekly
monitor threshold is 691200 seconds (8 days).

Session retention (ROB-440) and Docker build-cache retention (ROB-450) remain
separate services with separate roots, confirmations, policies, and markers.
