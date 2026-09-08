# Production Docker Build-Cache Retention

`scripts/ops/prod_build_cache_retention.sh` is the ROB-450 entry point for a
bounded Docker build-cache policy. It is read-only by default. It never runs an
image, container, volume, network, or system prune.

The fixed initial policy is deliberately conservative:

- only cache older than 168 hours is eligible;
- at least 2 GiB (`2147483648` bytes) of cache remains reserved;
- Docker must expose both an age filter and a storage-reservation flag;
- active or unclassifiable build, deploy, dump, replay, or smoke activity
  blocks the run;
- a non-blocking root-only lock prevents parallel execute runs;
- when the canonical Host Runner global production-change lock exists, the
  execute path must acquire and hold it as well;
- image, container, and volume inventories must remain exactly unchanged.

## Proposed Native Docker GC Configuration

The repository includes
[`scripts/ops/config/docker-build-cache-gc.json`](../../scripts/ops/config/docker-build-cache-gc.json)
as a proposed native Docker builder-GC policy. It is a sample only: it is not
installed, activated, scheduled, or an authorization to change a host. The
policy enables builder GC with a 2 GiB reserved space and a 168-hour unused
cache duration. The 2 GiB value is a reserve, not a hard total-size cap; recent
cache can exceed it, and the proposal imposes no hard fresh-cache cap.

An operator evaluating this proposal must confirm that the effective Docker
builder uses the default Docker driver; this daemon setting does not configure
a separate custom BuildKit daemon. Merge its `builder.gc` object with the
existing daemon configuration, preserve unrelated settings, and save the exact
pre-change configuration, including an explicit record when it was absent.
Validate the candidate with `dockerd --validate` and obtain separate approval
before activation. Native GC relies on BuildKit's in-use protection; it does
not execute `prod_build_cache_retention.sh` or its global activity guard.
Activation requires a Docker restart, which may briefly affect PDF, Kuma, and
the app. Run the Docker, PDF, Kuma, and app health checks afterward; on any
failure, restore the exact prior configuration (or absence), restart Docker,
and revalidate. This cannot restore cache already deleted by GC.

See the [Docker builder garbage-collection documentation](https://docs.docker.com/build/cache/garbage-collection/).

## Read-only Snapshot

Run the normal health and cleanup inventory first:

```bash
bash scripts/ops/prod_doctor.sh
bash scripts/ops/prod_cleanup_inventory.sh
bash scripts/ops/prod_build_cache_retention.sh
```

The last command is the dry-run and monitoring interface. It reports only
aggregate counts and bytes, the fixed policy, activity classification, and a
stable result/reason pair. It does not print cache record IDs, image names,
container names, volume names, raw prune output, paths, or secrets.

Persisting or alerting on that aggregate output is an operations decision.
At minimum, monitor `status`, `reason`, `cache.total_bytes`,
`cache.reclaimable_bytes`, and `cleanup_candidate`. A blocked or malformed
snapshot is a monitoring failure; it is never permission to prune manually.

## Separate Live-write Gate

Repository merge does not authorize a production cleanup. After a fresh
read-only snapshot, an operator must separately approve the exact live run.
The execute path requires two independent CLI signals:

```bash
bash scripts/ops/prod_build_cache_retention.sh \
  --execute \
  --confirm-live-write ROB-450
```

The script then runs exactly one of these capability-equivalent commands,
depending on the installed Docker CLI:

```text
docker builder prune --force --filter until=168h --keep-storage 2147483648
docker builder prune --force --filter until=168h --reserved-space 2147483648
```

It never adds `--all`. Raw Docker output is discarded. Cache totals are
measured again afterward, and the image/container/volume identity hashes must
match the preflight snapshot.

## Stop Conditions

Do not execute when:

- `prod_doctor.sh` or the read-only cleanup inventory is unhealthy or unclear;
- a deploy, rollback, Zero-Surprise replay, UI smoke, dump,
  backup, or Docker/BuildKit build is active or expected to start;
- another retention process owns the cleanup lock;
- Docker does not support the fixed age and storage-reservation flags;
- build-cache accounting is absent, duplicated, malformed, or reports
  reclaimable bytes greater than total bytes;
- the production host differs from the accepted Docker/Linux operating model.

Do not substitute `docker system prune`, `docker image prune`,
`docker container prune`, `docker volume prune`, `docker buildx prune --all`,
or an unbounded builder prune.

Live mode serializes on the root-owned `0700`
`/var/lib/fh-build-cache-retention` directory. The opened directory descriptor
must retain the same device and inode as the no-symlink path before pruning;
an unsafe, replaced, or busy directory blocks the run.

## Post-change Validation

Every live execution requires all of the following before another cleanup
class may be considered:

```bash
bash scripts/ops/prod_validate_after_change.sh
bash scripts/ops/prod_doctor.sh
bash scripts/ops/prod_cleanup_inventory.sh
bash scripts/ops/prod_build_cache_retention.sh
```

Compare the aggregate before/after cache bytes. Confirm that application,
renderer, deep health, services, scanner protections, and Kuma remain green.
The retention run is incomplete until those checks pass.

## Rollback And Recovery

Deleted build cache is not logically restorable. The safe recovery is to stop
further cleanup, preserve all images/containers/volumes, and allow the next
approved build to repopulate only the missing cache. A cold-cache build can be
materially slower, so do not start a replay or deployment until capacity and
timeout headroom have been reassessed.

If the prune command fails or protected inventory changes, treat the result as
an incident: do not retry with broader flags, do not force-remove Docker
objects, and run the full post-change validation before deciding the next step.
