# Bound release recovery inspection

`scripts/ops/prod_inspect_bound_release_recovery.sh` is a diagnostic,
**read-only** entry for the bounded release path from ROB-618. It is not a
second deploy or acknowledgement entry. It neither invokes `deploy_ea.sh` nor
uploads an artifact, changes a timer, removes a guard, or retries an operation.
An unknown first SSH result remains a stop until this separate inspection has
classified the retained evidence. Even a terminal inspection does not itself
authorize another release or clear the guard.

## Inputs and source binding

Use the original private deployment record for the candidate release, prior
active release, candidate commit, run ID, and six original SHA-256 bindings.
Never take these expected values from the protected state being inspected.
The `--source-commit` is different: it is the current, reviewed, successfully
checked `main` commit containing the inspector. The wrapper requires that exact
clean `main`, verifies the local source against its commit blob before and
after the SSH call, and streams those bytes to the Tailscale MagicDNS target
`root@booking-server`. It does not install a helper on production.

Default invocation is plan-only. After recording the current production marker
from the existing read-only inventory, a first no-guard check can establish
that there is no **currently pending** global deploy guard:

```bash
bash scripts/ops/prod_inspect_bound_release_recovery.sh \
  --source-commit "$REVIEWED_MAIN_COMMIT" \
  --mode no-guard \
  --expected-active-release "$CURRENT_ACTIVE_RELEASE" \
  --run-read-only --confirm-read-only ROB-621
```

This class does not prove what happened in an earlier release. It is not a
substitute for the run-bound recovery mode. For a specific unknown first SSH
result, supply **all** independently recorded original values:

```bash
bash scripts/ops/prod_inspect_bound_release_recovery.sh \
  --source-commit "$REVIEWED_MAIN_COMMIT" \
  --mode recovery \
  --release "$ORIGINAL_CANDIDATE_RELEASE" \
  --expected-active-release "$ORIGINAL_PRIOR_RELEASE" \
  --commit "$ORIGINAL_CANDIDATE_COMMIT" \
  --run-id "$ORIGINAL_RUN_ID" \
  --archive-sha "$ORIGINAL_ARCHIVE_SHA256" \
  --provenance-sha "$ORIGINAL_PROVENANCE_SHA256" \
  --continuity-sha "$ORIGINAL_CONTINUITY_SHA256" \
  --deploy-sha "$ORIGINAL_DEPLOY_TOOL_SHA256" \
  --pair-helper-sha "$ORIGINAL_PAIR_HELPER_SHA256" \
  --backup-helper-sha "$ORIGINAL_BACKUP_HELPER_SHA256" \
  --run-read-only --confirm-read-only ROB-621
```

Do not place these values in a public issue or command transcript. The
inspector emits only a fixed schema, status and result class, not the input
values or protected file contents. Preserve the original private record and
the redacted result in the existing operation Workpad.

## Evidence contract

The streamed inspector requires root on `booking-server`. It opens the
existing shared production lock read-only and takes a nonblocking exclusive
advisory lock. The lock file must remain root-owned, single-link, empty and
mode `0600` under the root-owned mode-`0700` lock directory. While holding it,
the inspector opens only the exact root-protected guard, intent and result
paths derived from the supplied release/run ID plus `_RELEASE`. It checks
parents, file type, owner, mode, link count, bounded size, opened inode and
unchanged identity before returning. The receipt is the durable
`deploy_result.v1` candidate published by the existing fsync-backed deploy
primitive; the inspector cannot retrospectively prove an fsync that did not
occur. The root operator account and its same-UID processes remain the trusted
local boundary.

The guard must bind exactly the supplied candidate, prior release, run ID,
intent leaf and result leaf. The intent must bind the supplied candidate
commit, run ID, all six hashes and the five well-formed historical config
bindings. Current config bytes are not substituted for that historical intent.
The receipt accepts only the declared outcome/exit pairs. The active marker
must be in its canonical format and name either candidate or prior release.

| Result class | Meaning |
| --- | --- |
| `no_pending_guard` | Guard absent and expected current marker confirmed under lock; not a historical deployment verdict. |
| `terminal_deployed` | Bound receipt says exit `0`/`succeeded`, candidate marker matches, and observed identities remain stable. |
| `terminal_confirmed_failed` | Bound receipt says exit `30` with a declared failed outcome, prior marker matches, and identities remain stable. |
| `recovery_required_exit31`, `recovery_required_exit32`, `recovery_required_exit143` | Distinct retained nonterminal outcomes; further release writes remain blocked. |
| `lock_busy` | The deployment or another operation may still be active; do not retry a deploy. |
| `*_missing`, `*_unsafe`, `*_changed`, `*_invalid`, `*_mismatch`, `binding_mismatch`, `marker_conflict` | Missing, untrusted, changed or contradictory evidence; no terminal claim. |
| `transport_or_receipt_unknown`, `observation_unknown` | SSH/receipt interpretation or runtime observation was not reliable; no terminal claim. |

The wrapper returns `0` only for the three passing diagnostic classes above,
`75` for `lock_busy`, and `70` for blocked or unknown observations. A passing
inspection does **not** call the internal `--ack` mode. Guard retirement is a
separate owner-authorized operation with fresh identities under the same lock.
Do not re-run the deploy wrapper with a new run ID, remove retained files, or
interpret a transport failure as a confirmed deployment result.

The first production use can verify source streaming, target/lock trust and a
current no-guard state. It cannot exercise terminal or contradictory retained
states without an actual relevant deployment attempt; those branches require
isolated regression evidence and remain bounded to the version tested.
