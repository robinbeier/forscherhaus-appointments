# Coordinated maintenance admission

ROB-812 is a historical proposal for a durable, repository-wide
`maintenance_pending.v1` admission record. That protocol was never installed
on production and has now been removed from the ordinary backup, restore,
retention, cleanup, and live-probe source paths. It is not a prerequisite for
the normal release path or for the ROB-809 SSH cutover. See the
[cutover record](../security/ROB-809-tailscale-ssh-cutover.md) for its outcome.

The optional Provider UI smoke has its own separately documented lifecycle and
remains suspended until its production retirement or replacement is completed.
Provider-specific admission references must not be copied into the ordinary
maintenance tools. See
[`docs/release-gate-provider-ui-smoke.md`](../release-gate-provider-ui-smoke.md)
for that separate evidence boundary.

## Current contract

Every mutating maintenance operation still uses the canonical production
change lock at
`/var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock`. The lock
must be a root-owned, mode-0600, single-link regular file with the expected
identity. Callers open and recheck that inode, acquire a non-blocking
exclusive `flock`, and retain the descriptor for the operation. A supplied
PID, descriptor number, operation name, run ID, environment variable, or
command-line flag never creates authority.

Each operation keeps its own protected state and recovery contract:

- backup production and restore attestation retain their private locks,
  bounded markers, canonical handoff/attestation pairing, direct-child and
  terminal checks, and fail-closed recovery evidence;
- session, release/archive, and manual cleanup retain their operation-specific
  locks, bounded inventories, quarantine or pending objects, exact file
  identity checks, and postflight health checks;
- ordinary live probes retain their own interruption marker and cleanup
  callback, and refuse unknown activity or incomplete cleanup;
- release deployment retains its separate candidate, rollback, predeploy,
  canary, and health gates.

These operation-specific controls are independent. A source change or a
successful local test does not authorize production installation. The active
production helper, its owner/mode/hash, the lock identity, timer state,
recovery markers, and the operation's own state must be checked immediately
before a separately authorized production change.

## Recovery boundary

Unknown child, Docker, timer, publication, quarantine, or cleanup results are
not converted into permission by elapsed time or by a second attempt. Keep
recovery objects and the previous production state, classify the result, and
stop the affected operation until its operation-specific recovery proof is
complete. Do not reactivate automatic retention or delete backups, archives,
release candidates, or recovery objects as part of this contract.

The shared lock prevents concurrent supported maintenance operations; it does
not claim to serialize native Docker/BuildKit garbage collection or arbitrary
root activity. Those mechanisms retain their own documented boundaries.

## Historical migration note

Older workpads and historical documents may mention a global pending-state
directory, a pinned admission-core hash, or a coordinated all-writer rollout.
Those references describe the rejected/uninstalled ROB-812 proposal. They are
not current production facts and must not be used as installation instructions.
When an older workpad is updated, preserve the historical decision and record
the current operation-specific lock/recovery evidence instead.
