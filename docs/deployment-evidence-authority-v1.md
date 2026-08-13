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
Because compiled frontend outputs are intentionally ignored by Git, the build
refreshes them first and then copies only the exact production-critical
CSS/JS/vendor paths declared by the commit-exported release validator. Whole
ignored directories and unrelated untracked files never enter the stage.
Those bounds include the exact staged regular-file count and a separate inode
count covering the stage root, directories, and regular files.
The local archive inspector resolves an executable `python3` from the build
machine's absolute `PATH` entries, supporting the documented macOS build path;
the fixed remote publication helper remains `/usr/bin/python3` on Ubuntu.
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
successful MariaDB 10.11 restore observation, including the allocated datadir
bytes and inode count measured after that restore. The attestation has no deployment
Run-ID. The runner pins its exact SHA and creates a separate
`deployment_run_dump_observation.v1` binding it to the deployment Run-ID and
intent. Compressed dumps are capped at 16 GiB, uncompressed data at 64 GiB and
the expansion ratio at 100:1. Age must remain below 14,400 seconds.
The root-only producer `scripts/ops/verify_deployment_dump_v1.php` accepts
either the backup-set ID `20YYMMDDTHHMMSSZ` or the single literal selector
`--latest-handoff`. The ID form derives the fixed
`/root/backups/easyappointments/<id>/db/easyappointments.sql.gz` source and
`created_at_utc` from that ID. The handoff form stable-reads the protected,
canonical `/root/backups/easyappointments/last_backup_set.json` under the
shared production-change lock, requires the durable backup-success marker to
name the same completed set, then cross-binds its ID, SHA-256, compressed size,
and uncompressed size to the pinned dump before restore. Neither form accepts
a caller path, digest, image, environment override, SQL, or timestamp. The
producer owns the pinned MariaDB
10.11 image identity, disables pulls and networking, and checks only the fixed
`ea_appointments`, `ea_roles`, `ea_services`, `ea_settings`, and `ea_users`
core tables. It runs no migration, HTTP probe, or zero-surprise gate.

The root-owned mode-0555 installed helper at
`/usr/local/libexec/fh/deployment_dump_attestation_v1.py` is execution
authority. Its historical-run check invokes the root-owned mode-0555
`/usr/local/libexec/fh/validate_deployment_terminal_bundle_v1.php` together
with a root-owned mode-0444 sibling `DeploymentContractV1.php`, so terminal
journals and evidence use the same canonical bundle validator as the Host
Runner. The deploy-tree copies are installation material. Publication uses a
private nonce leaf, file fsync, atomic no-replace rename, and directory fsync.
Exact replay attaches and converges the existing protected
`/root/backups/easyappointments/last_verify_success.utc` marker from the frozen
restore timestamp without regressing a newer marker. The producer holds the
shared production-change lock nonblocking and returns busy exit `75`.

Restore capacity is derived after the bounded gzip scan from the actual
compressed and uncompressed byte counts. The container enforces InnoDB,
restricts the restore principal, caps the shared tablespace, redo and temporary
files, and has fixed memory, swap, CPU, and PID limits. Before restore, a
streaming source-authority parser accepts only the standard table/data subset
of MariaDB 10.11 single-database dump output: the exact sandbox preamble,
closed standard session `SET` forms (including the exact save-set-create-restore
charset sequence around ordinary table definitions and the exact paired
save-disable, lock, disable-keys, zero-or-more same-table inserts, enable-keys,
unlock, commit and restore autocommit sequence),
table drop/create, table locks, key
disable/enable, and `INSERT ... VALUES`. Database-selection and database-DDL
statements are not part of this subset.
Triggers, routines, events, views, dynamic SQL, alternate engines, partitions,
external directories, client commands and every other statement class reject.
The exact accepted `CREATE TABLE` count is capped at 10,000 and supplies the
conservative pre-restore and post-restore inode bounds; quoted data and ordinary
comments cannot create false statement matches, while MariaDB executable
comments are parsed as SQL. The backup producer must therefore use the normal
single-database table/data mode without routines, triggers, or non-default
object options.
Attach replay runs before the restore-only capacity gate. A root-owned
mode-0600 regular lease file is
bind-mounted read-only into the `docker run --rm` container. The host holds an
exclusive advisory lock; the in-container watchdog waits for a shared lock.
Loss of the helper releases the lock, causing the watchdog to terminate MariaDB
and Docker to auto-remove the ROB-465-labeled temporary container. No named
volume is used. The fixed container wrapper writes a canonical clean-shutdown
record to a dedicated run-local bind only after MariaDB has exited and its fixed
private error log contains the normal server, InnoDB, and final shutdown
confirmations. After requesting a clean shutdown, the verifier requires
disappearance of the exact container in every container state, then stable-reads
and durably pins that exact root-owned record. The shutdown client's and wrapper
child's exit statuses are diagnostic only: neither can override a missing
server-side clean-shutdown confirmation, a retained container, or a confirmed
clean shutdown whose client happened to lose the connection during completion.
The global attestation path is derived only from the already-authorized dump
digest: `/var/lib/fh-deploy-evidence/dump-attestations/<dump-sha256>.json`.
Neither the request nor the execution input may supply or override that path.
At the dump gate, the protected source first copies the execution-input dump
to its fixed immutable run leaf with an exact streaming SHA-256 check. A second
stable-FD observation hashes that run copy again before reading the global
attestation through a no-follow root-owned directory walk. The attestation is
accepted only as exact bounded root-owned mode-0600 bytes. A missing, unsafe,
or contradictory observation normalizes to failed/invalid dump evidence and
cannot proceed to capacity or reservation.

The traffic producer publishes the exact canonical report to
`runs/<run-id>/traffic-gate-report.json` while the Host Runner holds both the
global and per-run locks. This immutable run-local leaf is the sole traffic
report authority; arbitrary report paths and caller-selected report digests are
not accepted.
The production collector always requests a 90-second deploy window. It stages
the producer output under a nonce leaf in the already protected run directory,
accepts only producer exits `0`, `20`, or `21`, and atomically publishes with
no replacement followed by file and directory fsync. A first publication must
place the observed window inside the helper's independently captured start and
finish times and cover at least 90 seconds. An exact immutable replay may
attach its original window. The PHP boundary recomputes the producer
fingerprint and catalog version from the fixed producer/catalog sources before
and after helper execution; any drift rejects the observation.

Capacity uses one `statvfs` snapshot of the target filesystem (`f_frsize`,
`f_bavail`, `f_files`, and `f_favail`) and an exact named device map for state,
release, artifact, dump, live storage, renderer state, stage, restore scratch,
and temporary targets. The protected provider measures allocated bytes, logical bytes, and
inodes for the exact live `storage` tree that the child later copies with
`rsync -a` into the stage. The projected copy uses the larger of allocated and
logical bytes, so sparse source files cannot understate their destination cost.
The archive-derived stage bounds and this live-storage footprint are added
before the capacity decision, so `stage_inode_count` represents the complete
projected stage after that copy. Later artifact observations cannot reduce
either bound.
Renderer capacity comes only from the root-maintained canonical policy at
`/etc/fh/deployment-renderer-capacity-v1.json`. Its closed
`deployment_renderer_capacity_policy.v1` object contains exact `host` and
`external` objects with `bytes` and `inodes`. The external values must be
exactly `0/0`; host values must both be positive conservative upper bounds for
`npm ci --omit=dev`, including the staged `node_modules` tree plus npm and
Puppeteer state caches. The selected mode comes from the already-pinned
execution input, while the numeric limits come only from this protected policy.
Host renderer targets must share the measured filesystem and are included
before the capacity verdict.
The Core collector resolves those names only to fixed host targets: the
protected state/run directory for state and the pinned dump, `/root/releases`
for the release and artifact, `/var/www/html` for the stage and deploy
temporary target, `/var/www/html/easyappointments/storage` for the live tree,
and `/var/lib` descendants for host renderer and restore scratch state. It
opens every component without following links and rejects the observation if
any target is unsafe or has a different device from the single `/var/www/html`
`statvfs` snapshot. Test roots are separately closed to the Linux root suite.
The live-tree walk accepts ordinary POSIX file names but only regular files and
directories with stable identities and safe ownership/modes; links and special
files make the capacity observation invalid. No file contents, names, or paths
are emitted by the helper.
Base required bytes are archive + compressed dump + archive-derived stage +
live-storage copy + renderer installation/cache + deterministic temporary
scratch + the uncompressed stream bound + the independently observed restored
datadir footprint; rollback bytes are zero in v1. Headroom is
`max(512 MiB, ceil(base/10))`. Available space must cover projected required
bytes and both observed and projected used percentages must be below 85. Free
inodes must cover the authenticated archive-stage and live-storage inode
counts, the independently observed restored-datadir inode count, and a fixed
64-inode allowance for the
runner's bounded archive, pin, state, receipt, timing, and temporary leaves.
The closed capacity evidence retains all five inode inputs (`available_inodes`,
`stage_inode_count`, `restore_inode_count`, `inode_headroom`, and
`projected_required_inodes`), and the terminal contract independently derives
the inode decision together with the byte and percentage checks.

## Ordered provider boundary

The contract assembles predeploy evidence only through one
`ProtectedPredeployObservationProvider`. It receives the immutable deployment
Run-ID, intent SHA, expected release ID, expected commit and traffic mode separately. Provider
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
Before publishing the child observation or terminal evidence, the Core pins a
canonical run-local `orchestrator-finish.json` containing the finish UTC, boot
ID, and monotonic sample. Exact retries consume that immutable record, so a
crash after any later durability step cannot substitute a newer clock sample
or change the terminal evidence bytes.
