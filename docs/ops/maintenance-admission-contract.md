# Coordinated maintenance admission

ROB-532 tracks replacement of process-name activity inference with a verified
contract shared by supported maintenance entrypoints. This document is the
**target contract and migration gate**, not evidence that it is installed.
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
| Ordinary live probe | `scripts/ops/run_ordinary_live_probe.sh`; interruption markers and cleanup callback | Enroll the separately invoked cleanup path as a writer |
| Provider/customer UI smokes | `scripts/ops/prod_provider_ui_smoke.sh`, `scripts/ops/prod_customers_ui_smoke.sh`; private cleanup locks and delayed systemd cleanup | Enroll foreground mutation, delayed cleanup units and disarm cleanup as separate writer entrypoints |
| Backup producer | `scripts/ops/libexec/backup_set_producer_v1.py`; shared and private locks, continuity state | Retain both validated descriptors in the trusted dump child; prove child termination and publication recovery separately |
| Restore verification | `scripts/ops/libexec/deployment_dump_attestation_v1.py`; detached container, lease watcher, orphan and continuity checks | Pending state must cover the interval between owner death and verified container termination |
| Session retention | `scripts/ops/libexec/session_retention_v1.py`; shared lock and protected local state | Read the same pending-state contract before mutation |
| Manual build-cache retention | `scripts/ops/prod_build_cache_retention.sh`; private lock and activity veto | Require the shared lock for execute, including when its path is absent; ROB-579 tracks this prerequisite |
| Retired release/archive/dump retention | Retired helper and existing hold/retention controls | Remain disabled; do not reactivate as part of enrollment |
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

Manual `docker builder prune` needs an operation-specific terminal proof. Its
CLI delegates mutation to the Docker daemon; CLI termination, timeout or a free
flock does not establish that daemon-side work ended. Unlike a restore
container, this command supplies no container identity to reconcile. Publish
pending state before dispatch and preserve it on an unknown outcome. Before
enrollment, demonstrate a supported daemon-level completion/reconciliation
mechanism, or keep manual prune unavailable under the new protocol. Do not
substitute container inspection, process names or elapsed time for that proof.
This requirement does not change native BuildKit GC's separate boundary.

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

Remove command-name inference only after every supported retained scheduler,
manual and recovery path is enrolled, versions agree, crash/recovery evidence
passes, and installed production versions are independently verified. A
repository-only test or a free flock is insufficient. ROB-532 stays open until
its agreed operational acceptance criteria are met; child prerequisite issues
may close independently with their narrower evidence.

For host operations and release authority, use
[the operations harness](agent-operations.md). The
[backup producer](production-backup-set-producer.md) and
[manual cache retention](production-build-cache-retention.md) documents retain
their operation-specific contracts.
