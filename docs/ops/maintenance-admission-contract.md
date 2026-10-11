# Coordinated maintenance admission

ROB-812 tracks the durable pending-state prerequisite for ROB-809. The earlier
ROB-532 process-identity work is complete, but does not establish this pending
protocol. This document is the **target contract and migration gate**, not
evidence that it is installed.
Keep the current conservative activity veto until every removal criterion below
passes. A source merge does not authorize production installation.

## Current boundary

The existing empty `fh-production-change.lock` is a protected, root-owned,
mode-0600, single-link regular file. Peers validate identity across opening and
acquire a nonblocking exclusive flock. A supplied descriptor number, PID,
operation name, command line or claimed run ID is never authority.

Until the pending-state protocol below is complete, the conservative activity
veto remains enabled. Every current scanner may use a process name only after
opening that PID directory through an `O_NOFOLLOW|O_DIRECTORY` descriptor,
reading a bounded `status` record and requiring its real, effective, saved and
filesystem UIDs to be root, then reading `cmdline` relative to that same
descriptor. The `cmdline` inode owner is not authority: an unprivileged
dumpable-disabled process can otherwise expose a root-owned proc inode while
retaining an unprivileged kernel identity. Unprivileged same-name processes
are ignored; a read or identity error for a root candidate remains fail-closed.
The production trust uid and proc root are fixed to root and `/proc`; neither
can be supplied by an operator or environment variable. Synthetic `Uid:` rows
are used only by the local AST-based test harness.

The following source paths define the enrollment inventory. Before rollout,
bind each installed tool and unit to its verified version and enumerate its
scheduler, operator and recovery entrypoints, including direct supported calls.

| Participant | Entry and existing lifetime controls | Remaining coordination requirement |
| --- | --- | --- |
| Ordinary deploy | `deploy_ea.sh`; validated shared descriptor | Preserve deployment state, recovery and delegated descriptor checks |
| Ordinary live probe | `scripts/ops/run_ordinary_live_probe.sh`; interruption markers and cleanup callback | Refuse every wrapper action and the separately invoked cleanup path when fixed `/var/lib/fh-maintenance-admission` is present or its root-controlled absence is unknown; even preflight and verify may initialize fixture state or lock files |
| Provider/customer UI smokes | `scripts/ops/prod_provider_ui_smoke.sh`, `scripts/ops/prod_customers_ui_smoke.sh`; private cleanup locks and delayed systemd cleanup | Enroll foreground mutation, delayed cleanup units and disarm cleanup as separate writer entrypoints |
| Backup producer | `scripts/ops/libexec/backup_set_producer_v1.py`; shared and private locks, continuity state | Repository source registers pending before dump dispatch and clears only after direct-child termination and publication. The source now also has a bounded `--recover-pending` path for a current-boot, fully published pending run; installed helper remains legacy and production rollout remains open. |
| Restore verification | `scripts/ops/libexec/deployment_dump_attestation_v1.py`; detached container, lease watcher, orphan and continuity checks | Repository source admits before reconciliation, registers pending before Docker launch, and settles after terminal restore and publication. The source now includes a narrowly scoped recovery proof for the post-publication/post-cleanup state; installed recovery and production installation remain open. |
| Session retention | `scripts/ops/libexec/session_retention_v1.py`; shared lock and protected local state | Repository execute path reads the pending-state contract through its existing lock descriptor before mutation; installed helper and unit remain legacy until the coordinated rollout |
| Manual build-cache retention | `scripts/ops/prod_build_cache_retention.sh`; private lock and activity veto | Require the shared lock for execute, including when its path is absent; ROB-579 tracks this prerequisite |
| Manual release/archive/dump retention (not enrolled) | `scripts/ops/prod_release_archive_dump_retention.sh` still exposes `--execute`; the root-controlled helper is installed and its timer is inactive | Manual execute remains supported and accessible, but is outside pending-state admission. Enroll/fence every supported manual path or explicitly retire it before the ROB-809 cutover; keep automatic retention disabled |
| Retained manual backup/restore wrappers | Installed operator paths, verified by bounded read-only inventory | Enroll or explicitly retire every supported path before removing the veto |

Native Docker/BuildKit garbage collection remains a separate engine-managed
mechanism with its own reference/liveness rules (ROB-521). This contract does
not disable it or claim to serialize it through the manual script. Nor does it
contain an administrator who can replace root-owned tools or run arbitrary
commands. The inventory covers supported entrypoints, not all possible root
activity.

The backup descriptor change and manual cache refusal are compatible local
prerequisites (ROB-578 and ROB-579). They do not resolve ROB-532's original
command-name availability finding or the detached-container interval.

## UI-smoke maintenance-pending enrollment obligations

The Provider and Customers UI smoke scripts are not enrolled by this document;
the following is the bounded proof required before either path can participate
in pending-state admission. The operator scripts currently run a read-only
preflight, arm an independent ten-minute systemd lease, invoke the root-only
server-local principal wrapper, and perform cleanup in an exit trap. Provider
uses `fh-provider-ui-smoke-cleanup` and
`scripts/ops/provider_ui_smoke_principal.sh`; Customers uses
`fh-customers-ui-smoke-cleanup` and
`scripts/ops/customers_ui_smoke_principals.sh`.

* **One lifecycle record:** register one pending run while holding the shared
  lock *before* arming the timer. Bind its fixed application root, state
  directory, smoke family, run identity, and intended unit name. The fixture
  file does not yet exist at registration, so its later observed identity
  needs separate protected evidence; a caller-supplied filename or claimed ID
  cannot retroactively bind the immutable pending record. Keep the veto
  through foreground activation, the local browser gate, deactivation, and
  timer disarm. Clearing it immediately after successful activation would
  admit other writers while the fixture and delayed cleanup remain live.
* **Delayed systemd cleanup:** enrollment must cover the independently owned
  timer and its oneshot service, including the exact wrapper and arguments
  passed by `systemd-run --unit=... --on-active=10m`. The service must
  reconcile the same run under the shared lock before deactivation; it may
  not treat a generic recovery capability as permission to process another
  run. A service cannot prove its own terminal systemd state while it is still
  executing. The pending veto remains until a separate lock-held finalizer
  proves the exact service terminal, timer disarmed, and fixture dormant/clean.
  Timer ownership and elapsed time are not terminal cleanup proof.
* **Unknown `systemd-run` response:** a lost SSH or `systemd-run` response is
  an unresolved pending operation, never evidence that no timer or service was
  created. Keep the pending record and perform a server-local reconciliation of
  both the exact `.timer` and `.service`; do not clear state from the caller's
  exit status, a quiet response, or a free lock. An inability to distinguish
  absent, queued, running, or completed cleanup remains blocked.
* **Timer disarm:** the present disarm sequence uses the private smoke lock
  and masks `systemctl stop`/`reset-failed` errors before checking whether the
  units remain active. That is insufficient as a pending-state terminal proof.
  The enrolled finalizer must hold the shared lock, check each command result,
  establish that neither exact unit can still dispatch or execute, and verify
  wrapper deactivation plus dormant/clean fixture state before clearing the
  record. An unknown stop, unit status, or fixture result retains the veto;
  preserve the existing independent cleanup protection when its state is not
  proven terminal.
* **Direct server-local entrypoints:** direct root calls to either principal
  wrapper, including `bash` invocations that bypass the operator script, are
  separate writer entrypoints. `install` and `remove` require their own
  lock-held admission, registration, and terminal proof. `activate` and
  `deactivate` must bind to the existing lifecycle run or refuse; they cannot
  open a second independent record while that run is pending. Any supported
  shell alias must obey the same rule. A PID, action name, caller-supplied
  state path, or wrapper success line is not authority.

These are source-derived proof obligations only. They do not install, authorize
or imply production enrollment; production installation remains a separately
approved change after the recovery, mixed-version, and direct-entrypoint
evidence in this contract passes.

## Required state transitions

1. **Admit:** validate and acquire the existing shared inode; verify the
   installed protocol epoch and all protected pending state. Unknown, malformed,
   oversized or unsupported state refuses admission before relevant mutation.
2. **Register:** durably publish a bounded pending operation in a separately
   protected state directory before launching work that can outlive its owner.
   Include schema version, registered operation, unique run identity, boot
   identity and intended resource identity. Do not put journal bytes into the
   existing empty lock or replace that inode.
3. **Execute:** retain direct-child lock coverage using the already validated
   descriptors. Bind detached resource evidence to exact observed identities.
   A lost Docker launch response is unresolved pending work, never evidence
   that no container started. Names or reusable PIDs alone cannot prove identity.
4. **Settle:** establish terminal direct-child/container status and complete the
   operation's existing cleanup/publication obligations. Durably clear pending
   state only after those checks succeed, then release the lock.
5. **Recover:** an explicitly authorized recovery path acquires the same lock,
   reconciles protected state with exact resources and existing operation
   evidence, and records the terminal result before clearing the pending record.
   A recovery failure or interruption remains blocked.

Parent death, timeout, TERM/KILL, reboot, torn publication, failed container
inspection and an unknown resource outcome all preserve the pending veto.
There is no time-based expiry or operator flag that converts uncertainty into
permission. The shared flock prevents concurrent admission; durable pending
state prevents admission after an owner dies while work may still exist.

The local, uninstalled core uses an immutable hardlink from `pending.json` to
`clear-state.json` before removing the pending name. It synchronizes the state
directory before and after that removal and retains the marker until a separate
recovery call verifies the exact record and terminal outcome. A marker with or
without the pending name refuses ordinary admission. Recovery can settle the
matching two-name inode state after interruption; an identity mismatch remains
blocked. Admission also synchronizes an apparently empty state directory before
accepting it, including after an interrupted final marker removal. These core
mechanics do not supply an operation-specific terminal proof or enroll a writer.
The local recovery capability can also settle an unchanged pending record
left before the clear marker was created. It requires the same held lock,
an exact record binding, and a trusted operation-specific terminal proof
both before and after marker creation; an unknown second proof retains
the marker veto. No generic operator success flag or time limit can
supply that proof. The capability does not make a writer with unfinished
resource reconciliation ready for production installation.
For a writer that already holds the canonical shared lock, the local core now
has a descriptor-based admission check. It validates the same lock inode and
protocol epoch and checks the caller's exclusive flock through read-only
/proc/self/fdinfo for that exact descriptor, without attempting to acquire a
lock, opening a second lock description or creating state. A capability keeps a
close-on-exec duplicate of the original open-file-description until exit, so
closing and reusing the caller's numeric FD cannot silently rebind authority.
The caller retains ownership of its original descriptor and closes it on every
failed admission and after mutation. This
check is only a source-level integration primitive: the ordinary deployment
entry now invokes it in local source and regression fixtures. The repository
backup producer also uses the same held descriptor for pending registration
around its direct dump child and continuity publication. Installed production
helpers remain on the older contract. The local
core also offers recovery through the same already-held lock descriptor, so a
writer can settle its own clear marker without releasing the shared lock. The
caller must independently prove its exact operation is terminal and all
publication or cleanup obligations are complete. Neither API replaces
registration or terminal proof for work that can outlive its owner.
The repository restore-verification helper now uses this same lock-held core
before local reconciliation and registers a run-, dump-, backup-, and
container-intent-bound pending record before detached Docker launch. Its
successful path clears pending only after the container-exit proof,
attestation, success-marker, optional continuity publication, and run-tree
cleanup; an unknown path preserves the pending veto. This is source-only
enrollment.
If cleanup is interrupted, the run tree may be complete, partial, or absent;
the pending veto remains regardless. After successful cleanup, an interrupted
pending or clear-marker settlement retains the published attestation, success
marker, and optional continuity state. Those records can only support recovery
when an independent verifier binds them to the registered dump and backup,
checks the exact Docker resource is absent, and proves that every required
publication completed.
The repository source now contains a cross-process proof for one deliberately
narrow case: the pending record must be from the current boot and bind the
exact backup-set ID, dump hash and sizes, run leaf, container intent, and an
immutable `continuity_required` choice. The verifier re-reads and hashes the
exact backup file, checks the canonical handoff and backup-success marker,
validates the published attestation and restore-success marker, optionally
requires the matching verified continuity state, proves that the exact run
tree and all labeled dump containers/volumes are absent, and settles through
`maintenance_pending_v1` while the caller-held canonical lock remains held.
The bounded root-only `--recover-pending` entrypoint opens only the existing
canonical and restore locks, selects exactly the current-boot pending record
or its matching clear marker, and performs this proof before allowing the
core to settle pending-only, marker-plus-pending, or marker-only state.
Missing, changed, malformed, old-boot, or otherwise unknown state returns to
the pending veto. This is source-only evidence: it does not prove recovery of
a pre-publication or pre-cleanup interruption, does not replace a deployed
operator, and does not authorize production installation.
Only trusted root integration code may call the recovery capability; its proof
callback is not an operator-selectable flag or an authorization boundary. Each
future writer must bind a fixed, reviewed verifier for its own resource before
this core can be installed or used on production.

The backup producer has the same source-only limitation in a narrower form.
Its `--recover-pending` entrypoint opens an already existing private lock and
never creates one during recovery. It accepts only a current-boot producer
record whose backup set, closed metadata, handoff, success marker and pending
continuity state all match, with no producer staging or temporary files. It
settles pending-only, marker-plus-pending and marker-only state through the
existing recovery capability; old-boot, partial or mismatched evidence keeps
the veto. Publication is operation-specific evidence that the direct dump
child had already terminated successfully because the producer publishes the
handoff and continuity state only after `create_backup()` returns. Before
settlement, recovery also synchronizes the backup directory after the complete
proof; an fsync failure leaves the pending or clear-marker veto untouched.
This is source-only evidence; the installed helper and production recovery
path remain unchanged until a separate rollout review.

Manual `docker builder prune` needs an operation-specific terminal proof. Its
CLI delegates mutation to the Docker daemon; CLI termination, timeout or a free
flock does not establish that daemon-side work ended. Unlike a restore
container, this command supplies no container identity to reconcile. Publish
pending state before dispatch and preserve it on an unknown outcome. Before
enrollment, demonstrate a supported daemon-level completion/reconciliation
mechanism, or keep manual prune unavailable under the new protocol. Do not
substitute container inspection, process names or elapsed time for that proof.
This requirement does not change native BuildKit GC's separate boundary.

The local ROB-812 prerequisite fences the legacy manual entrypoint at the
fixed `/var/lib/fh-maintenance-admission` path. Execute mode acquires the
canonical shared `fh-production-change.lock` before checking that path, then
treats its presence as `maintenance_protocol_unenrolled` and refuses to
dispatch the Docker daemon. The check includes directories, regular files,
symlinks and dangling symlinks; the path is never created and no caller or environment
override exists. A missing path preserves the existing bounded execute
behavior. Dry-run may report whether the path was observed, but remains
read-only and does not acquire or create protocol state.

This fence closes the legacy writer during protocol installation only when
installation and the check use the same shared lock. It does not prove
completion of a previously dispatched prune, reconcile daemon state, or
serialize native BuildKit garbage collection, which remains a separate
engine-managed mechanism.

The state directory, bounded schema, atomic publication protocol, per-service
write permissions and recovery interface must be implemented and reviewed
before enabling this contract. They are proposed requirements, not permissions
already granted by a unit or by this document. Keep private locks, backup
continuity state, probe recovery markers and fallback artifacts intact.

## Local acceptance matrix

Use disposable Linux fixtures and synthetic data. Record actual binaries,
source head, test doubles and any missing systemd sandbox coverage.

| Case | Required evidence |
| --- | --- |
| Every ordered pair of registered writers | Second writer cannot mutate while first is active or has unresolved pending state |
| Read-only path | No mutation or creation of lock/pending state |
| Missing, unsafe, replaced or busy lock | Refusal before operation dispatch; no replacement inode is created |
| Direct-child owner dies | Independent lock attempt remains busy while child lives; locks release after verified child exit and reaping |
| Actual trusted dump executable | Descriptor retention in the deployed client version is verified; synthetic-child success alone is insufficient |
| Detached launch before identity receipt | Crash leaves pending state; reconciliation proves exact terminal resources before admission resumes |
| Manual prune CLI exits or is killed | Operation-specific daemon evidence establishes completion; unknown outcome retains pending state and blocks admission |
| TERM/KILL, timeout, stopped child, inspection failure | Bounded diagnostics; no unrelated process signals or cleanup; unknown outcome blocks |
| Invalid/truncated/oversized record, fsync/rename failure | No silent empty-state fallback or premature admission |
| Reboot and identity reuse | Old boot/PID/container-name evidence cannot certify terminal state |
| Interrupted recovery | Pending veto remains until recovery actually completes |
| Mixed versions and interrupted installation | Epoch-aware participants refuse inconsistent state; legacy direct paths are demonstrably fenced before activation |
| Rollback | Pending operations are settled before reverting readers; state is not deleted to restore availability |

Use a negative control against the original implementation for each local
regression. A simulated command dispatch proves admission logic, not actual
Docker cleanup safety. A client waiting on a synthetic connection proves that
phase's descriptor retention, not a complete database dump or production
behavior. Required Linux root/systemd CI remains separate from container tests.

## Migration and removal gate

Land and verify the compatible prerequisites first. Implement pending-state
readers, durable publication and recovery together while retaining the existing
veto. Rehearse the complete matrix and installation/rollback on a disposable
host. Prepare an exact installation manifest covering tools, units, supported
entrypoints, protocol epoch, lock identity, state-path permissions and fallback
copies. Before installation, specify and validate the exact absolute state path,
root-controlled ancestor chain, owner/mode/link-count and filesystem guarantees,
service sandbox permissions, bounded record schema and Docker identity fields.
Demonstrate how every supported entrypoint is prevented from mutation during
mixed-version installation and how a pre-launch record is reconciled without a
received resource identity. Missing any of these is an installation blocker.
Obtain separate production-change approval for the complete manifest.

An old binary cannot reject an epoch it does not read. The cutover therefore
needs an external admission fence: inhibit all supported scheduler and manual
entrypoints, settle active operations under the old contract, then replace and
verify the complete tool/unit set before enabling the new epoch. Stopping a
timer alone does not fence a direct manual call. The installation manifest must
identify how each legacy executable or wrapper is retired or made unreachable
through supported invocation paths, including retained rollback copies. A
shared lock held only during installation is insufficient if an old process can
resume afterward. Interrupted installation must leave the fence in place;
tests must exercise direct legacy invocation and prove refusal or unavailability,
not attribute new protocol awareness to unchanged code. Epoch checks apply only
to updated participants. Rollback requires the same fence and settled pending
operations; reverting only some tools must not reopen legacy admission.

The inactive retention timer does not retire the manual `--execute` wrapper. Its
continued accessibility is therefore a migration blocker until that entrypoint
is enrolled in the pending-state protocol or explicitly retired and verified
unreachable.

Remove command-name inference only after every supported retained scheduler,
manual and recovery path is enrolled, versions agree, crash/recovery evidence
passes, and installed production versions are independently verified. A
repository-only test or a free flock is insufficient. ROB-812 remains open
until its local writer enrollment, recovery and mixed-version acceptance
criteria pass; small local prerequisites may land independently with their
narrower evidence. Production installation and the SSH cutover remain separate
nighttime steps under ROB-809 after those local criteria are met.

For host operations and release authority, use
[the operations harness](agent-operations.md). The
[backup producer](production-backup-set-producer.md) and
[manual cache retention](production-build-cache-retention.md) documents retain
their operation-specific contracts.
