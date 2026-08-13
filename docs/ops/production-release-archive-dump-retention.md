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
- root-owned backup sets below `/root/backups/easyappointments` whose
  `db/easyappointments.sql.gz` has an exact canonical, independently
  restore-verified attestation below
  `/var/lib/fh-deploy-evidence/dump-attestations`.

Unknown names, unsafe ownership/mode/type/link identity, mount crossings,
missing protected current/rollback archives, an active or unreconciled Host
Runner, known deploy/backup work, a busy global production-change lock, or an
open deletion candidate blocks execute mode. Output is aggregate-only: no
release, archive, backup, dump, or application-storage names or bytes are
printed.

## Conservative policy

| Class | Minimum age | Always protected | Maximum removals per pass |
|---|---:|---|---:|
| previous/stage/failed release directories | 7 days | current directory and exact rollback directory | 4 per class |
| complete archive/provenance pairs | 30 days | current, rollback, then newest complete pairs until 4 release IDs are protected | 8 pairs |
| verified backup sets | 30 days from attestation | newest 2 independently restore-verified dump SHA-256 values | 4 sets |

An archive-only prefix is undeployable and may be resumed after the same age
and protection checks; a sidecar without its archive is corruption and blocks.
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

This command is documentation, not current production authorization. Before a
future live run: merge the PR, install the reviewed helper under
`/usr/local/libexec`, run the default dry-run, obtain a separate production
write approval, verify backups/restore evidence and host health, then re-run
health plus the read-only cleanup inventory afterward.

The shipped weekly timer is deliberately not enabled or started by repository
code. Monitoring of the success marker is separately disabled by default until
activation is explicitly approved.

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
