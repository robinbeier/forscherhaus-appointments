# Production dump-producer admission (ROB-483)

This is a read-only admission observation for the single producer registered
in `scripts/ops/config/dump_producer_registry.v1.json`. Its exact canonical
bytes are SHA-256-pinned and embedded in the installed retention helper, so a
registry extension requires a reviewed helper change and a new exact helper
installation; the privileged helper never imports registry logic from a
mutable deploy tree. The registry is the closed identity boundary: exactly
`fh_backup_set_producer_v1` is permitted, and every unknown producer, path,
manifest, writer, or schema fails closed.
Registry JSON is canonical (sorted keys, compact encoding, one final newline)
and contains no credentials, secrets, or discovered host/object identities;
its fixed production paths are reviewed contract values.

The producer entry fixes its purpose, binary and supervisor, publication root,
private staging pattern, shared/private locks, atomic no-clobber plus parent
fsync publication method, set and regular-file owner/group/mode/link contract,
forbidden symlink/mount-crossing classes, lifecycle states, continuity schema,
retention contract, restore authority, and responsible runbook. Registry
extension therefore requires a new producer ID and an equally closed reviewed
entry; path discovery or caller-supplied authority cannot extend admission.

## Contract and redaction

Each published set must have the fixed UTC leaf shape, database leaf,
manifest leaf, and `production_backup_set.v1` manifest schema recorded in the
registry. The existing canonical `meta/backup.env` is the manifest: its exact
six lines bind set ID and UTC creation time to the dump SHA-256, compressed
size, and uncompressed size. The matching restore attestation must bind the
same creation time, digest, and sizes. During the normal producer-to-verifier
window, exactly the handoff-bound canonical pending set may temporarily lack
that attestation; it is still fully manifest/filesystem validated and reports
retryable, never pass. Every other missing attestation remains foreign. This
adds validation; it does not rewrite or relabel an existing set. The restore
authority is separately fixed to
`deployment_dump_attestation.v1` beneath the attestation root. A missing,
duplicate, non-canonical, mismatched, linked, or unrecognised manifest or
registry entry is an admission failure. Output is aggregate status only:
never print set names, paths, SQL, digests, credentials, host values, or raw
manifest contents. Redaction is a contract, not a best-effort presentation
choice.

The top-level authority objects are fixed to their declared classes, safe
root-owned identities, and writer IDs. Marker bytes must be one valid UTC
timestamp, and the producer-owned backup-success marker must name exactly the
current handoff set. `install-snapshots` is a separately preserved root-owned,
non-writable class,
`preserve_outside_dump_retention`; it is not a dump set and is never inferred
from a scan. The existing `decision_blocked` tree remains unchanged.

## Separate approvals

The repository approval covers only these registry, wrapper, unit, test, and
runbook files. Installation approval is a separate root-controlled action for
the reviewed binaries and units. Read-only observation approval covers
`admission-status` and this disabled desired-state timer. Monitoring approval
is separate and may consume aggregate status only. Object-mutation approval
is separate again; this slice grants none and cannot delete, rename, repair,
publish, enable, or start anything.

Objektmutation approval is therefore never implied by admission status.

The helper result uses `prod_dump_producer_admission.v1`. A passing result has
zero foreign and decision-blocked entries, reports only aggregate producer,
manifest-bound and verified-set counts plus the registry digest, and carries
the unchanged zero-mutation ledger. An unknown top-level entry is a blocked
result, not a supported skip or simulated pass. The cleanup inventory consumes
only this exit class and discards helper output rather than reprinting it.
A missing fixed authority is also blocked. A canonical continuity state that
is still `pending` is reported separately as retryable restore verification;
if its bound set still lacks an attestation, that set is included in the
manifest-bound count but not the verified count. The pending state is never
mislabeled as an unknown producer and never makes retention execution-ready,
including the crash window where an attestation already exists. The execute
path enforces that pending state as a retryable gate before it removes any
release, archive, or dump candidate. Existing crash-recovery cleanup of a
trusted marker temp remains a separately accounted recovery mutation.

The units describe desired state only. A separately approved installation may
place them and validate them without activation:

```bash
sudo /usr/bin/install -o root -g root -m 0644 \
  scripts/ops/systemd/fh-dump-producer-admission.service \
  /etc/systemd/system/fh-dump-producer-admission.service
sudo /usr/bin/install -o root -g root -m 0644 \
  scripts/ops/systemd/fh-dump-producer-admission.timer \
  /etc/systemd/system/fh-dump-producer-admission.timer
sudo /usr/bin/systemd-analyze verify \
  /etc/systemd/system/fh-dump-producer-admission.service \
  /etc/systemd/system/fh-dump-producer-admission.timer
sudo /usr/bin/systemctl daemon-reload
/usr/bin/systemctl is-enabled fh-dump-producer-admission.timer
/usr/bin/systemctl is-active fh-dump-producer-admission.timer
```

The required post-install state is `disabled` and `inactive`; the service must
also be inactive. Repository delivery and installation do not activate the
units.
The timer is `Persistent=false` and runs at least every fifteen minutes, so no
delayed catch-up read is implied.

## Complete operating cycle

1. A reviewer checks the exact registry bytes and the installed binary,
   supervisor, runbook, and manifest schemas against the approved source.
2. During a revalidated quiet interval, the read-only wrapper or service
   acquires the existing global lock only as required by the observation
   helper and requests aggregate `admission-status`.
3. The observer validates identity, registry, manifest, restore-attestation
   binding, lock/activity state, and redaction. Any mismatch returns a
   fail-closed result without writing a dump or changing an object.
4. The operator records only the aggregate result and separately decides
   whether any future installation, monitoring, or object mutation should be
   proposed. No such decision is implied by a passing observation.
5. Repeat the read-only status across at least one complete backup and normal
   operating cycle. A stable pass is observation evidence only; it does not
   classify or authorize mutation of the retained `decision_blocked` tree.

## Stop rules and rollback boundary

Stop immediately on registry drift, non-canonical JSON, unknown writer or
schema, missing or duplicate leaves, symlink/hard-link surprises, active
backup/restore/deploy/retention work, lock contention, network requirement,
unexpected output, or any requested mutation. Preserve the evidence for
review; do not retry by widening scope. The `decision_blocked` tree is left
byte-for-byte untouched. Recovery requires a new explicit approval and a
fresh identity check; this runbook never authorises deletion or cleanup.
