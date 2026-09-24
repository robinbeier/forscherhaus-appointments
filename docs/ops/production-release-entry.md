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
   preflight derives the three expected installed-tool hashes from tracked,
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
       systemctl show "${unit%.timer}.service" -p LoadState -p ActiveState -p SubState -p Result -p ExecMainStatus
   done
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

## Controlled execution and bounded verification

6. **Deploy the reviewed archive through the existing host path.** Use the
   exact `/root/deploy_ea.sh` invocation and required host-local inputs in
   [Deployment](../deployment.md#deploy), preserving the shared lock across
   any approved migration and deployment. For this entry, additionally pass
   `--result-file "$DEPLOY_RESULT_FILE"`: choose one absent, run-specific leaf
   beneath the existing canonical root-owned mode-`0700` `/root` directory,
   bind that exact path to the run, and verify the leaf is absent before invoking
   the deploy command. The helper rejects an existing or unsafe target; do not
   remove or overwrite it to retry. Require the machine-readable
   `deploy_result.v1` receipt and independently compare its outcome and exit
   code with the observed child result. Exit `0` is success;
   `30` is verified pre-switch failure or rollback; `31`, `32`, `74`, missing,
   invalid, mismatched, killed, or unknown results require state inspection and
   block retry. Do not infer success from output alone.

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
   protection, and private evidence until the existing recovery path proves
   their removal safe. Inspect the exact host state and use the documented
   rollback or recovery mode. Never retry, clear a marker, delete a dump/archive, or restore a
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
