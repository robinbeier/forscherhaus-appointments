# Production Zero-Surprise Image Cleanup

`scripts/ops/prod_zero_surprise_image_cleanup.sh` is the ROB-458 operator path
for legacy images left by completed Zero-Surprise replays. It does not patch the
active release. The wrapper streams the reviewed helper unchanged to the fixed
production Python interpreter over SSH, validates the bounded canonical result
locally, and is read-only by default.

This is a one-time compatibility path for images created before replay teardown
included deterministic image cleanup. New replays continue to use the cleanup
inside `zero_surprise_replay.php`.

## Closed Scope

The helper derives candidates only from Docker image metadata:

- the Compose project label must match the closed `zs-...` grammar;
- the service label must be exactly `php-fpm` or `pdf-renderer`;
- the image must have exactly the matching project-local `:latest` tag;
- an optional repository digest must use that same repository and a full
  SHA-256 digest;
- the full image ID, normalized metadata, all container references, and the
  complete candidate snapshot are rechecked before every deletion;
- deletion uses `docker image rm <full-id>` without `--force`;
- at most 32 projects and 64 images may be handled in one run.

Foreign Compose projects are ignored. A malformed `zs...` identity, unexpected
service/tag/digest, container reference, inventory race, concurrent production
activity, or exceeded cap blocks the whole preflight before its first write.
Once a deletion has been requested, any timeout or later ambiguity stops with
`partial` and `mutation_performed: true`, even when deletion could not be
confirmed and `deleted_count` is still zero. It is never widened into a prune.

The helper never runs image, container, volume, builder, or system prune. It
never prints image IDs, project names, tags, paths, container identities, raw
Docker output, or secrets. Its one-line JSON contains aggregate counts and
bytes only.

## Read-only Preflight

The canonical production-change lock is a prerequisite. On a host where the
Host Runner has not yet been installed, create that single shared authority
once with the reviewed helper before the first dry-run:

```bash
bash scripts/ops/prod_zero_surprise_image_cleanup.sh \
  --prepare-global-lock \
  --confirm-live-write ROB-458
```

This mode may create only root-owned `0700`
`/var/lib/fh-deploy-orchestrator`, its `0700` `locks` directory, and the empty,
single-link `0600` `fh-production-change.lock`. Existing unsafe objects are
never normalized or replaced. Exact replay validates and attaches without a
write. A crash prefix is safely completed by the same command. This is a
separate live mutation and must precede the read-only preflight only when the
fixed lock is absent.

Start with the normal production checks:

```bash
bash scripts/ops/prod_doctor.sh
bash scripts/ops/prod_cleanup_inventory.sh
bash scripts/ops/prod_zero_surprise_image_cleanup.sh
```

The third command acquires the canonical production-change lock, rejects
active deploy/replay/dump/backup/retention/build work, validates the candidate
set twice, verifies that no container references any candidate, and returns
aggregate evidence without removing anything.

A long-running plain `docker compose up` process that only supervises already
started production services is not build activity. Direct Docker builds,
Compose `build`/`run`, Buildx/Buildctl, builder prune, and `compose up --build`
remain blocking. Independently, every running or stopped container reference is
checked before every candidate deletion.

`pass` exits `0`. Unsafe or malformed authority exits `70`. A busy lock, active
production work, or exceeded bounded set exits `75` and is retryable only after
the reported condition has cleared. Usage errors exit `64` in the remote
helper; local wrapper argument errors exit `1`.

## Separate Live-write Gate

Repository merge and a green dry-run do not authorize cleanup. The exact live
command requires both independent CLI signals:

```bash
bash scripts/ops/prod_zero_surprise_image_cleanup.sh \
  --execute \
  --confirm-live-write ROB-458
```

The wrapper sends the same reviewed helper used by dry-run. It does not install
files in `/usr/local`, modify the active release, upload an archive, or create a
production script copy. The only permitted live mutation is deletion of the
fully validated image IDs in the bounded snapshot.

## Stop And Recovery

Stop without broader commands when the result is `blocked` or `partial`, the
standard health gate is unclear, or counts differ from the approved preflight.
Do not use `docker image prune`, `docker system prune`, `--force`, tag-based
deletion, or manual filtering as a fallback.

Deleted images are reproducible replay artifacts, not rollback authority. A
failed or partial run is recovered by preserving all remaining images, running
the full health/inventory checks, and retrying the same exact contract only
after the ambiguity is understood.

## Post-change Validation

Before the serial wave proceeds to build-cache retention, run:

```bash
bash scripts/ops/prod_validate_after_change.sh
bash scripts/ops/prod_doctor.sh
bash scripts/ops/prod_cleanup_inventory.sh
bash scripts/ops/prod_zero_surprise_image_cleanup.sh
```

The final dry-run must report zero candidates. Application, renderer, deep
health, services, scanner protections, Kuma, current/rollback image authority,
and release-artifact binding must remain green.
