# Deployment evidence authority v1

This prerequisite defines how the deployment host obtains evidence inputs. It
does not add an `evidence.json` CLI input and does not make coordinator-provided
terminal evidence authoritative.

## Build provenance

`build_release.sh` requires `--expected-commit FULL_SHA`, rejects tracked
working-tree changes, exports that exact commit, and emits a detached canonical
`<release_id>.build-provenance.json` beside the archive. The sidecar is
`release_build_provenance.v1`; it binds the exact commit, archive digest and
size, source digests, and conservative archive-derived stage bounds.
`temp_scratch_bytes` is an additional scratch allowance only: archive, dump,
and stage bytes are excluded because the capacity formula counts each of them
as a separate component.

The protected root-produced deploy execution input contains
`artifact_provenance_sha256`. That field is not part of the coordinator deploy
request. It selects exact sidecar bytes and is itself protected by the pinned
execution-input SHA, launch record, unit binding, and runner state. A changed
sidecar digest for the same Run-ID conflicts with the first pinned execution
input. The host derives both `/root/releases/<release_id>.tar.gz` and
`/root/releases/<release_id>.build-provenance.json`; no path comes from the
request. Terminal `artifact.manifest_sha256` is the SHA-256 of those exact
sidecar bytes.

Upload never writes either final leaf directly. `build_release.sh` transfers
the archive and sidecar to nonce-named mode-0600 temporary leaves, then invokes
the fixed publication helper with their exact sizes and SHA-256 values. Under
one release-directory lock the helper proves both candidates and any existing
finals before mutation, publishes the archive first and the sidecar last with
atomic no-replace renames, and fsyncs the files and directory. An exact retry
attaches; a different existing leaf fails without overwrite. An archive left
before its sidecar is unavailable rather than deployable, and a retry can
safely complete the pair.

## Dump and capacity

The isolated restore verifier produces a global
`deployment_dump_attestation.v1` from one stable dump observation and one
successful MariaDB 10.11 restore observation. The attestation has no deployment
Run-ID. The runner pins its exact SHA and creates a separate
`deployment_run_dump_observation.v1` binding it to the deployment Run-ID and
intent. Compressed dumps are capped at 16 GiB, uncompressed data at 64 GiB and
the expansion ratio at 100:1. Age must remain below 14,400 seconds.

Capacity uses one `statvfs` snapshot of the target filesystem (`f_frsize`,
`f_bavail`) and an exact named device map for state, release, artifact, dump,
stage, restore scratch, and temporary targets. Base required bytes are archive
+ compressed dump + archive-derived stage + deterministic temporary/restore
scratch; rollback bytes are zero in v1. Headroom is
`max(512 MiB, ceil(base/10))`. Available space must cover projected required
bytes and both observed and projected used percentages must be below 85.

## Ordered provider boundary

The contract assembles predeploy evidence only through one
`ProtectedPredeployObservationProvider`. It receives the immutable deployment
Run-ID, intent SHA, expected commit and traffic mode separately. Provider
methods are invoked exactly in the order expected commit, traffic, dump,
capacity, artifact; the first failed or invalid observation prevents every
later method call. The provider returns closed raw-observation value objects,
never evidence sections, statuses, `verified` claims, or exception text.

This prerequisite freezes that interface and its normalization. It does not
activate a deployment or make an arbitrary PHP implementation trusted. The
Core PR must supply the sole production provider from protected stable-FD pins,
helper results and the independently observed statvfs/unit/clock facts defined
here. Coordinator request data cannot implement the provider, select its file
paths, or supply its pin digests. The test provider exists only under the test
namespace and proves call order and failure retention.

## Timing and child outcome

The runner creates a timing UUID inside `deployment_host_systemd_launch.v1`;
the coordinator cannot select it. The fixed deploy argv contains
`--timing-run-id UUID`, and the launch/argv hashes bind it. `deploy_ea.sh`
creates the corresponding root-protected file with no clobber. Its child-side
fsync is best-effort telemetry hardening only: `DEPLOY_TIMING_DURABLE` is
process-local and never evidence authority.

After the unit has stopped, the runner is the sole timing classifier. It reads
the fixed timing leaf through a bounded stable-FD helper, pins and fsyncs the
exact bytes and parent directory, then calls `DeployTimingSampleValidator`
`validateBytes()`. `validateFile()` remains a diagnostic CLI API and must never
be used as Host Runner authority. Missing or invalid timing stays
`not_observed` or `invalid` and cannot change an exact canonical receipt plus an
independently observed normal unit exit.

The pin helper holds an exclusive lock on the protected run-directory file
descriptor while it reconciles recognized private temp leaves and publishes or
attaches `deploy-timing.jsonl`. Concurrent identical attempts therefore yield
exactly one `pinned` and one `attached` result; neither may remove the other's
live temp. Unknown leaves remain corruption rather than cleanup candidates.
The Linux-root CI matrix covers missing, replay, conflict, unsafe metadata,
size boundaries, stale-temp recovery and concurrent publication.

Orchestrator timing starts durably before the intent. On the same boot,
monotonic time supplies the duration for successful and failed terminals. A
boot change forbids success; failed/manual recovery uses ordered UTC duration
in the existing schema and never claims cross-boot monotonic continuity.
