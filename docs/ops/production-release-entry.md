# Production release entry

This is a bounded operator entry for one reviewed application release and one
ordinary live probe. It routes through the existing artifact deploy and probe
contracts; it does not create a deployment orchestrator or grant production
authority. A missing, stale, contradictory, or unknown fact blocks the entry.

## Entry gates

1. **Pin the reviewed input.** Record the reviewed `main` commit, unchanged
   blocking CI and review result, release identifier, archive SHA-256 and size,
   matching `<release_id>.build-provenance.json`, the reviewed local
   `build_release.sh`, and the installed host-local `/root/deploy_ea.sh`
   identity. The provenance must bind the commit, archive digest and size. Use
   the [deployment runbook](../deployment.md),
   [provenance contract](../deployment-evidence-authority-v1.md), and
   [terminal receipt contract](../deployment-run-v1.md). Any mismatch, missing
   sidecar, legacy archive without provenance, or unverified installed tool
   blocks the release; do not reconstruct historical provenance.

2. **Refresh production facts read-only.** Pin the verified operator target for
   every read-only command, for example:

   ```bash
   PROD_SSH_TARGET=root@booking-server bash scripts/ops/prod_doctor.sh
   PROD_SSH_TARGET=root@booking-server bash scripts/ops/prod_cleanup_inventory.sh
   PROD_SSH_TARGET=root@booking-server bash scripts/ops/prod_release_archive_dump_retention.sh
   ```

   Confirm the `booking-server` MagicDNS target in the local Tailscale status
   and the remote hostname in the doctor output. The doctor provides host,
   health, runtime, service, and aggregate listener classes;
   `prod_cleanup_inventory.sh` provides the
   active release marker and session/release-retention timer classes. Use the
   retention wrapper only for its separate read-only release/archive/dump
   snapshot. Record each fact's source command, UTC capture time, redacted
   result class, and invalidation boundary:
   a release/configuration/tool/host change, or the boundary stated by the
   source, makes it stale. Never record a tailnet ID, raw overlay address, or
   secret. A fact not directly observed or contradicted by a fresh command
   is `open` and blocks. See [operations harness](agent-operations.md#verify-production-facts-before-designing-production-tooling).

   Before backup, timer changes, or deployment, use the reusable, entirely
   read-only `scripts/ops/prod_release_readiness_preflight.sh` result as the
   release-entry gate. Bind `ACTIVE_RELEASE_ID` to the **currently active**
   release from the last trusted deployment receipt and the fresh inventory;
   it is not the new candidate ID. Run from the reviewed, clean checkout: the
   preflight derives the four expected installed-tool hashes from tracked,
   unchanged local sources rather than accepting hashes copied from the remote
   query. The new candidate's archive and provenance stay bound separately in
   step 1.

   ```bash
   PROD_SSH_TARGET=root@booking-server bash scripts/ops/prod_release_readiness_preflight.sh \
     --expected-active-release "$ACTIVE_RELEASE_ID"
   ```

   Require exit `0`, schema `production_release_readiness.v1`, `status=passed`,
   and `result_class=readiness_verified`. Keep its UTC capture time, fixed
   sources, and invalidation boundary with the release workpad. Any other exit,
   malformed receipt, unknown state, changed identity, or mismatch blocks
   before the first writer. This gate shares one checked marker/lock/timer/tool
   result across release-specific operator steps instead of repeating their
   parsing as shell fragments. It does not acquire the lock or authorize a
   later write: rerun or re-evaluate if production state changes, and retain
   the separate lock check under the writer's own contract in step 3.

3. **Admit one operation.** Observe the existing shared production lock and
   relevant recovery-marker state, including the CSP Report-Only pilot lease,
   before any migration or deployment. Without a migration, let the reviewed
   deploy/probe command acquire and manage the lock under its own contract. For
   an approved migration, the root operator must first acquire that same lock
   through the verified installed `deploy_ea.sh` helper, then call its
   `ordinary_assert_no_pending_probe` and
   `ordinary_assert_no_active_csp_report_only_pilot` checks **while holding the
   lock and before the first migration write**. Keep the validated descriptor
   held and exported to the deploy child through completion or rollback as in
   [Deployment](../deployment.md#deploy). A read-only snapshot taken before lock
   acquisition does not authorize migration. The
   [coordinated maintenance admission](maintenance-admission-contract.md)
   document describes target enrollment, not proof of installed state; use the
   verified installed helper and the ordinary probe’s persistent pending-run
   contract. A busy, replaced,
   unsafe, or already-pending state blocks; no time-based expiry clears it.

4. **Snapshot the exact timer before-state.** For any timer the approved
   operation may change, capture `is-enabled`, `is-active`, `list-timers --all`,
   and the associated service `ActiveState`, `SubState`, `Result`, and
   `ExecMainStatus`. This includes backup continuity and session retention when
   affected, and the transient `fh-defense-ordinary-cleanup.timer` and service
   that the ordinary probe creates. Before a new probe, both transient units
   must report `LoadState=not-found`; after verified cleanup they must return to
   that state. Separately verify that release/archive/dump retention remains
   disabled/inactive; enabling it is a separate production change. Restore and
   verify the exact before-state after any temporary pause.
   See [retention operations](production-release-archive-dump-retention.md#marker-and-monitoring).

   For the backup continuity timer, use the installed, separately reviewed
   `fh-backup-timer-transition-v1` operator helper after its path, owner, mode,
   file identity, and SHA-256 have been bound to the release operation. Its
   source is `scripts/ops/libexec/backup_timer_transition_v1.py`; a checkout
   copy is not an installed production tool. Assign one fresh 32-character
   lowercase hex run ID to the release and retain it for both calls:

   ```bash
   ssh root@booking-server /usr/bin/python3 -I -B \
     /usr/local/libexec/fh-backup-timer-transition-v1 pause "$RUN_ID"
   # Run the separately locked backup and isolated restore under their own contracts.
   ssh root@booking-server /usr/bin/python3 -I -B \
     /usr/local/libexec/fh-backup-timer-transition-v1 restore "$RUN_ID"
   ```

   Require the matching `backup_timer_transition.v1` success receipt and exit
   `0` for **each** call. The helper acquires the shared lock afresh for each
   timer transition, rejects concurrent activity and recovery markers inside
   the lock, records the bound before-state, and releases the lock so backup
   and restore can acquire it independently. A busy lock, mismatched run or
   identity, missing or contradictory receipt, failed timer change, or SSH
   interruption is unresolved: inspect the exact state and recover under a
   separately bound plan. Do not issue a second pause/restore merely because
   the first result is missing. Confirm the full timer and service metadata
   from the read-only snapshot again after the restore. Free-standing
   `systemctl disable/enable` commands are not the release entry.

   For the host facts the inventory does not report, use a fixed read-only
   query and keep only its metadata/classes (never state-file contents):

   ```bash
   ssh root@booking-server /bin/bash -s <<'SH'
   set -euo pipefail
   stat -c 'production_lock=%F %u:%g %a %d:%i' /var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock
   test ! -L /var/lib/fh-deploy-orchestrator
   stat -c 'csp_lease_parent=%F %u:%g %a %d:%i' /var/lib/fh-deploy-orchestrator
   lease=/var/lib/fh-deploy-orchestrator/csp-report-only-pilot.state.json
   if test -e "$lease" || test -L "$lease"; then
       printf 'csp_pilot_lease=present\n'
   else
       printf 'csp_pilot_lease=absent\n'
   fi
   for marker in run.pending request-unconfirmed; do
       if test -e "/var/lib/fh-defense-ordinary/$marker" || test -L "/var/lib/fh-defense-ordinary/$marker"; then
           printf '%s=present\n' "$marker"
       else
           printf '%s=absent\n' "$marker"
       fi
   done
   for unit in fh-backup-set-continuity.timer fh-session-retention.timer fh-release-archive-dump-retention.timer fh-defense-ordinary-cleanup.timer; do
       printf '%s enabled=%s active=%s\n' "$unit" "$(systemctl is-enabled "$unit")" "$(systemctl is-active "$unit")"
       systemctl show "$unit" -p LoadState -p ActiveState -p SubState -p Result
       systemctl list-timers --all --no-pager "$unit"
       if test "$unit" != fh-backup-set-continuity.timer; then
           systemctl show "${unit%.timer}.service" -p LoadState -p ActiveState -p SubState -p Result -p ExecMainStatus
       fi
   done
   systemctl show fh-backup-set-producer.service fh-backup-set-restore-verify.service \
       -p LoadState -p ActiveState -p SubState -p Result -p ExecMainStatus
   SH
   ```

   Accept the CSP lease parent only as a non-symlink root-owned mode-`0700`
   directory. A missing lock, untrusted CSP lease parent, present CSP pilot lease,
   unexpected ordinary marker, or unknown timer state blocks admission before
   any migration. After acquiring the shared lock, repeat the pending-probe and
   CSP-lease checks before the first migration write. Recheck immediately before
   the deploy child under the same held lock; a previous absence is not authority.

5. **Prove recovery inputs when required.** If the deployment contract or
   migration plan requires a fresh database backup, use the existing
   `scripts/ops/prod_backup_set_producer.sh` path under the explicit ROB-466
   write and ROB-461 restore confirmations. That wrapper runs both the producer
   and its bound isolated restore verification. Require the producer's closed
   `published` status with exactly one new set and the verifier's successful
   closed status and exit code. The installed helpers validate the protected
   handoff, dump digest/size, attestation, and restore marker internally; their
   set identifier, paths, and digests must not be extracted into operator output.
   A stale, unbound, missing, or unknown backup/restore result blocks;
   application rollback does not undo a database migration. See
   [backup producer](production-backup-set-producer.md)
   and [deployment evidence authority](../deployment-evidence-authority-v1.md).

## First installation of the timer helper

The timer-transition helper is initially absent on existing hosts. Before
its first use, complete a separate, reviewed installation step from the exact
successfully checked `main` commit: bind the tracked source SHA-256 and the
absent destination, transfer a no-clobber candidate, and install it as a
root-owned, single-link regular file with mode `0555` at
`/usr/local/libexec/fh-backup-timer-transition-v1` while holding the shared
production lock. Verify installed SHA-256, owner, mode, file identity, and
unchanged timer and service state before releasing the lock. An occupied
destination or mismatch stops; do not overwrite it. The read-only release
preflight binds this fourth helper to the reviewed source and rejects an
unresolved backup-timer transition marker or prior deployment recovery marker,
even if the timer appears active.
Installation alone does not authorize a timer transition, backup, or
application deploy.

## Controlled execution and bounded verification

6. **Deploy the reviewed archive through the existing host path.** For a
   normal release without migration, use the checked-main
   `scripts/ops/prod_deploy_bound_release.sh` entry once the two read-only
   admission helpers are installed at their reviewed hashes. It requires the
   already published archive/provenance pair, a fresh verified backup handoff,
   and the exact currently active release. Its inputs are the reviewed commit,
   release and current-release IDs, and the two local artifact paths; it derives
   the artifact hashes from those files and the tool hashes from the pinned
   commit. It verifies the release
   pair and artifact with code from a private snapshot of the checked commit,
   streams the runner from that commit's exact blob, then rechecks production
   readiness and binds both published files, the restored dump and host
   configuration under the shared lock, then invokes the existing
   `/root/deploy_ea.sh` at most once with an absent run-specific result leaf.
   The root-only intent reservation and `deploy_result.v1` receipt stay on the
   host. No old per-release script or copied inode/hash list is an input.

   The operator account and all processes running under its local UID are one
   trusted boundary: that account also holds the production SSH authority.
   The private commit snapshot prevents ordinary checkout drift from changing
   which verifier runs. Its owner-writable files do not defend against a
   hostile process with the same UID. If the operator workstation or account
   is suspected compromised, stop the release and recover that authority;
   this wrapper cannot establish an independent trust boundary on that host.

   ```bash
   bash scripts/ops/prod_deploy_bound_release.sh \
     --rel "$RELEASE_ID" --expected-commit "$REVIEWED_MAIN_COMMIT" \
     --expected-active-release "$ACTIVE_RELEASE_ID" \
     --archive "$ARCHIVE" --provenance "$PROVENANCE" \
     --execute --confirm-live-deploy ROB-618
   ```

   Before this command, independently confirm that the exact main commit has
   successful blocking CI and review, that `publish_existing_release.sh`
   published the exact pair, and that the separate ROB-466/ROB-461 backup and
   restore completed with the backup timer returned to its original state.
   The wrapper does not build, upload, back up, pause timers, migrate data, or
   retry. Its `deployed` result requires the child's exit `0`, matching durable
   receipt, and new active marker. A result of `confirmed_failed`,
   `recovery_required`, missing receipt, SSH interruption, or any unknown
   result stops further writes until the exact host state has been inspected.
   The release ID identifies the protected intent, which records the run ID;
   that run ID identifies the result leaf. A different run ID cannot relaunch
   the same release candidate after an unknown transport or deploy result.
   Before the deploy child starts, a root-owned, fsync-backed global recovery
   guard is also reserved. It blocks every later release candidate after an
   unknown or recovery-required result. A terminal `0` or `30` result retains
   the guard until the caller has validated the first SSH response and sends a
   separate acknowledgment. That acknowledgment takes the shared lock and
   rechecks the exact guard, intent, receipt and active release before retiring
   the guard. It is never sent after an unknown first response or a recovery
   result. The wrapper reports deployment and acknowledgment outcomes
   separately. If the acknowledgment result is unknown, the deployment result
   remains known, but the host guard state must be inspected before further
   writes. A later invocation never clears the guard automatically. Never
   delete the intent, result or guard to retry.
   After ROB-621, the separate [read-only recovery inspector](bound-release-recovery-inspector.md)
   can classify the recorded guard, intent, receipt and active marker under the
   shared lock using the original private run binding. A durable `0` receipt
   with the candidate marker or `30` with the prior marker can establish a
   terminal state; `31`, `32`, `143`, missing or contradictory evidence cannot.
   The inspector never acknowledges or retires a guard. The runner's internal
   `--ack` is not an operator recovery command. Do not re-run the deploy wrapper
   with a fresh run ID to investigate an unknown result.
   For a separately
   authorized migration use the direct, lock-preserving
   [deployment procedure](../deployment.md#deploy) and its additional gate.

   The two new read-only helpers are
   `scripts/ops/libexec/release_pair_admission_v1.py` and
   `scripts/ops/libexec/backup_handoff_admission_v1.py`. Their first host
   installation is a separate, reviewed no-clobber step: bind the merged main
   source SHA-256, prove each target absent under the canonical
   `/usr/local/libexec/fh` directory, transfer and install root:root mode
   `0555` under the shared lock, and verify stable path, owner, mode, link
   count, inode and hash afterward. An occupied or mismatched target blocks.
   Installing the helpers alone does not deploy an application release.

   This guard also changes the host's existing `/root/deploy_ea.sh` primitive.
   Before the first bound release, bind the reviewed `deploy_ea.sh` source and
   the installed file by SHA-256, owner, mode, link count and inode. With no
   active deployment or recovery, retain a no-clobber, hash-bound copy of the
   old primitive; replace the host file only under the shared production lock
   and verify the installed candidate afterward. A mismatch or failed check
   restores the exact retained predecessor under that lock. This tool update
   does not itself authorize an application deployment.

7. **Run one bounded ordinary probe.** After the active `_RELEASE` marker and
   app-root identity match the pinned release, use the existing
   `scripts/ops/run_ordinary_live_probe.sh` action(s) and the exact expected
   release marker described in the [Defense Cycle gate](../release-gate-defense-cycle.md#ordinary-live-identity-and-operator-probe).
   The ordinary probe arms its existing three-hour cleanup timer and persistent
   recovery marker; do not alter their contract or bypass its lock. Preserve
   the secret-free terminal receipt. The probe is incomplete if cleanup,
   journal retirement, active-release identity, or the final receipt is not
   verified.

8. **Stop on uncertainty; recover before proceeding.** On deployment or probe
   interruption, SSH loss, signal, marker drift, lock loss, failed cleanup,
   failed health, or unknown mutation outcome: retain pending markers, cleanup
   protection, and private evidence until an owner-controlled recovery action
   proves their removal safe. Inspect the exact host state; the bound deploy
   guard has no automatic recovery command here. Never retry, clear a marker,
   delete a dump/archive, or restore a
   database based on an absent result or elapsed time.

## Exit evidence

Re-run `bash scripts/ops/prod_doctor.sh` and
`bash scripts/ops/prod_cleanup_inventory.sh`. Verify health from the doctor,
the active release marker from cleanup inventory, and archive/provenance identity
from the reviewed artifact and host transfer evidence. Verify the deploy receipt,
ordinary-probe receipt and cleanup, exact timer before/after state, operation
terminal state, and every recovery marker settled or intentionally retained with an
explicit blocker. Keep only redacted evidence and source/time/invalidation
metadata. A release is not an operator success while any one of those checks
is missing, stale, contradictory, or unknown.
