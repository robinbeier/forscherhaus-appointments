# Deployment Run Contract v1

Purpose: document the retained state, receipt, and evidence formats used by the
pure validators and historical production-state checks. The unused Host Runner
has been retired. This document is not an implementation plan or a second
operator deployment path; use [deployment.md](deployment.md) for deployment.

`deploy_ea.sh` remains the single normal deploy primitive. These validators do
not invoke it or authorize retrying an uncertain production operation.

## Logical intent and attachment

The first canonical JSONL record uses schema `deployment_run.v1` and
`record_type=intent`. Its lowercase UUIDv4 `run_id` identifies one logical,
authorized deployment intent. A retry or new authorization must use a new
Run-ID even when the other intent fields are identical.

The immutable intent contains:

- the full expected 40-hex commit;
- a bounded release ID;
- `fresh_verified_under_240m` dump policy;
- `build_from_expected_commit` artifact expectation.

`intent_sha256` is the SHA-256 of their recursively key-sorted compact JSON.
The Run-ID is kept alongside that hash rather than folded into it. Reattaching
to an existing Run-ID is permitted only when the candidate intent independently
validates and has the same hash. Same Run-ID plus changed intent is exit `75`
(`state_conflict`); a new attempt needs a new authorization and Run-ID.

### One-time upgrade after traffic-check removal

This procedure also applies when retiring the Kuma monitor-count fields from
the closed post-gate contract. Use the old matching tools to finish or reconcile
nonterminal runs first. Archive only completed runs with those tools; do not
rewrite historical reports or add a legacy adapter to make them pass the new contract.
Replacing the matching toolset changes contract validation only. It does not
change the live Kuma instance or its monitor state.

It also applies when upgrading from the former host-renderer
execution input containing `renderer_deploy_mode`. That old input is not
accepted by the new closed contract, even though its v1 schema name is unchanged.
Before replacing any tools, stop submitting new runs and use the **old matching
runner and helpers** to finish or reconcile every nonterminal run and clear its
terminal active claim. A stopped runner process alone is not sufficient. If a
run cannot be safely completed or recovered, postpone the tooling upgrade;
never delete its claim or edit its pinned input to make the upgrade pass.
Then archive the completed runs using the locked procedure below, install the
matching new toolset together, and start with new run IDs and inputs. Use a fresh
archive destination for each contract upgrade.

Old completed runs can contain removed intent fields, renderer inputs, and journal state.
The dump producer checks every directory in `runs/`, so finishing active runs
and choosing a new run ID alone is insufficient. Before replacing **any** of
the installed helpers or their sibling contract, move the verified completed
runs out of the active tree with the existing, mutually compatible tools.
Do not run this after a partial tooling update; restore the previous matching
helper and validator/contract first.

Run once as root on the production host, before installing the new tooling.
The example uses a new destination for the Kuma-policy transition, distinct
from earlier traffic-check or renderer-input archives. For another transition,
choose another unused destination; never reuse or overwrite an earlier archive.

```bash
python3 - <<'PY_UPGRADE'
import fcntl
import importlib.util
import os
import stat

helper = '/usr/local/libexec/fh/deployment_dump_attestation_v1.py'
spec = importlib.util.spec_from_file_location('installed_dump_helper', helper)
old = importlib.util.module_from_spec(spec)
spec.loader.exec_module(old)
root = old.open_absolute_directory(old.ORCHESTRATOR_ROOT, 0o700)
locks = old.open_child(root, 'locks', 0o700)
lock = os.open('fh-production-change.lock',
               os.O_RDWR | os.O_CLOEXEC | os.O_NOFOLLOW, dir_fd=locks)
meta = os.fstat(lock)
assert stat.S_ISREG(meta.st_mode) and meta.st_uid == meta.st_gid == 0
assert meta.st_nlink == 1 and stat.S_IMODE(meta.st_mode) == 0o600
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
assert old.identity(meta) == old.identity(
    os.stat('fh-production-change.lock', dir_fd=locks, follow_symlinks=False))
old.assert_no_nonterminal_runs(root)
try:
    runs = old.open_child(root, 'runs', 0o700)
except FileNotFoundError:
    print('No historical runs to move.')
else:
    os.close(runs)
    archive = '/root/fh-deployment-runs-before-kuma-count-retirement'
    os.mkdir(archive, 0o700)  # Existing destination aborts; never overwrite it.
    destination = old.open_absolute_directory(archive, 0o700)
    os.rename('runs', 'runs', src_dir_fd=root, dst_dir_fd=destination)
    os.fsync(destination)
    os.fsync(root)
    print('Completed runs moved out of the active tree.')
PY_UPGRADE
```

The existing helper validates terminal journals and evidence using the old
contract while the production-change lock is held. Any active claim, running
operation, incomplete run, or invalid terminal bundle stops the move. An
absent `runs/` directory is supported by the dump producer and does not need
to be recreated. The moved history is outside the scanned tree and needs no
conversion or permanent compatibility code.

## State journal

Every record is one compact canonical JSON object with no trailing data.
Object keys are recursively sorted, sequences start at `1`, and timestamps use
second-precision UTC (`YYYY-MM-DDTHH:MM:SSZ`). One final newline for the whole
JSONL file is allowed. Unknown, missing, duplicate, mixed-run, mixed-intent,
noncanonical, corrupt, out-of-order, negative, or wrongly typed data fails
closed.

The success path is strictly monotonic:

```text
planned -> built -> uploaded -> accepted -> lock_acquired
  -> expected_commit_verified -> dump_verified
  -> capacity_passed -> artifact_verified -> deploy_running
  -> post_gates_running -> succeeded
```

Terminal failure states are:

- `failed_before_write`
- `failed_pre_switch`
- `failed_switch_recovery_required`
- `failed_post_switch_rollback_succeeded`
- `failed_post_switch_rollback_failed`
- `manual_recovery_required`

`failed_switch_recovery_required` is reachable only directly from
`deploy_running`; once `post_gates_running` begins, a switch-phase recovery
claim is an impossible ordering and fails closed. Exit `32` is represented by
that dedicated state and by deploy evidence alike; it cannot alias
`manual_recovery_required`. Terminal state is immutable.
The `deploy_running` record is the durable
write-ahead invocation reservation: a journal producer must append and
fsync it before spawning `deploy_ea.sh`. It changes
`deploy_invocation_count` from `0` to `1`; the count can never exceed one or
return to zero. A crash or transport loss from that point is observe-only and
must never create a second deploy process. Before reservation, a valid prefix
may be attached for status/revalidation. After terminal persistence, attachment
only returns the existing result.

The independent post-gates run only after the normal deploy child has returned
successfully. If one of those gates fails, the journal must durably reserve
the dedicated rollback action by appending and fsyncing the monotonic branch
state `rollback_running` before starting it:

```text
post_gates_running -> rollback_running
  -> failed_post_switch_rollback_succeeded
  |  failed_post_switch_rollback_failed
  |  manual_recovery_required
```

`rollback_running` is not part of the success path and cannot transition back
to a prior state or to `succeeded`. It represents separate reservation count
`1`, is attach-observe-only, and is not a second normal deploy invocation. A
crash before reservation leaves rollback `not_invoked`; a crash after
reservation but before a verified verdict records rollback `unknown` and
requires manual recovery rather than an automatic retry.

## Public exit and reason pairs

The public contract uses only stable pairs:

| Exit | Reason | Meaning |
| ---: | --- | --- |
| `0` | `ok` | progress, attachment, or success |
| `22` | `dump_verification_failed` | dump freshness, gzip, SHA, or restore gate failed |
| `23` | `capacity_gate_failed` | capacity evidence failed |
| `24` | `artifact_verification_failed` | artifact or host-script evidence failed |
| `25` | `expected_commit_mismatch` | expected commit was not observed |
| `30` | `deploy_failed` | pre-switch failure or verified rollback success |
| `31` | `rollback_failed` | rollback failed or is unverifiable |
| `32` | `switch_recovery_required` | switch state requires recovery |
| `70` | `contract_invalid` | closed-schema or trusted-state validation failed |
| `75` | `state_conflict` | lock, attachment, or intent conflict |
| `143` | `interrupted` | interruption leaves a fixed fail-closed state |

`state_conflict` is a before-write terminal only. Once deploy or rollback
execution has been reserved, the terminal reason must preserve that execution
phase instead of relabeling it as an attachment or lock conflict.
`contract_invalid` may also terminate a reserved deploy as
`manual_recovery_required` only when the child verdict cannot be accepted; in
that case deploy evidence is strictly `unknown`, invocation count is `1`, and
exit/rollback outcome remain `null`/`not_observed`.

Arbitrary child exit codes or free-form reasons are never copied into evidence.
The future runner must normalize them into this table.

## Deploy child result candidate

When normal deploy is invoked with `--result-file ABSOLUTE_PATH`,
`deploy_ea.sh` publishes one closed, secret-free `deploy_result.v1` receipt
candidate.
The object has exactly `schema`, `outcome`, and `exit_code`; it contains no
timing, paths, commands, hosts, output, or free text. Its fixed bindings are:

| Outcome | Exit | Deploy evidence |
| --- | ---: | --- |
| `succeeded` | `0` | `succeeded`, `1`, `0`, `not_run` |
| `failed_pre_switch` | `30` | `failed`, `1`, `30`, `not_run` |
| `internal_rollback_succeeded` | `30` | `failed`, `1`, `30`, `succeeded` |
| `rollback_failed_or_unverifiable` | `31` | `failed`, `1`, `31`, `failed` |
| `switch_recovery_required` | `32` | `failed`, `1`, `32`, `recovery_required` |
| `interrupted_pre_switch` | `143` | `failed`, `1`, `143`, `not_run` |

The caller supplies a run-specific target beneath an existing canonical
root-owned mode-`0700` directory. The target must be absent at invocation start
and is never overwritten or repaired. Publication uses a root-owned mode-`0600`
single-link same-directory temporary file, mandatory file fsync, atomic
no-replace publication, and mandatory parent-directory fsync. The writer
returns a normal deploy exit only after all durability and final identity
checks succeed. A receipt write, file-fsync, parent-fsync, or final identity
failure instead returns `74`; `74` is deliberately not a valid
`deploy_result.v1` outcome/exit pair. Best-effort cleanup removes only the
writer's revalidated inode and fsyncs the parent again. A crash or unprovable
cleanup may leave a complete candidate, but it remains unaccepted.

A producer of this state format may accept a candidate only while holding both the
host-global production-change lock and the run lock, after it has independently
observed the terminal child/systemd result and proved an exact exit/outcome
match against a canonical trusted receipt. It then binds the exact receipt-byte
SHA-256 into its own atomically persisted and fsynced state. Missing, malformed,
untrusted, mismatched, killed, exit-`74`, or otherwise unknown child results
remain `unknown`/`null`, require manual recovery, and never authorize a respawn.
The receipt alone is not authoritative. Stdout and stderr are never result
oracles. Dry-run does not publish a receipt and
rejects `--result-file`.

## Evidence contract

`deployment_evidence.v1` is a closed canonical JSON object. It carries only
fixed enums, booleans, non-negative integers, canonical UTC timestamps, UUIDs,
40-hex commits, and SHA-256 values. It has no fields for commands, arguments,
paths, filenames, hosts, addresses, URLs, usernames, customer/person data,
stdout, stderr, exception text, credentials, or raw logs.

Its sections are:

- expected and observed commit plus exact verification result;
- protected fresh-dump age/SHA plus explicit checksum-, gzip-, and
  restore-verification evidence from the predeploy replay;
- capacity available/projected bytes and inodes, the authenticated staged inode
  count, independently observed restored-datadir inode count, fixed 64-inode
  allowance, observed/projected used percentages, the fixed
  `85` percent ceiling, and a derived decision;
- local/remote artifact, manifest, and host/artifact deploy-script hashes;
- exactly-once deploy exit and any rollback performed inside that child;
- a separate at-most-once dedicated post-gate rollback reservation and verdict;
- independent post-gates for runtime configuration, services, endpoints, logs,
  scanner, and dormant/clean;
- outer orchestrator start/end/wall-clock values in a separate section;
- the terminal state and stable exit/reason pair.

Evidence ownership is deliberately split. Backup/restore evidence comes from
the protected fresh dump and predeploy replay. PDF export evidence comes from
that replay and the live canary. Those checks are not additional `post_gates`
fields. The standalone `prod_validate_after_change.sh` command is a general
post-change sanity check; it is not an independent backup/restore or PDF-export
proof source.

Not-yet-observed sections retain their exact keys with `null` values and a
fixed `not_observed`/`not_invoked` status. They never invent zero hashes or
success. An interruption directly after the `deploy_running` write-ahead
reservation for which no valid, bound child receipt can be recovered uses
deploy status `unknown`, invocation count `1`, a `null` child exit, and rollback
outcome `not_observed`. That shape is valid for either the direct
`manual_recovery_required`/`interrupted` crash window or a rejected child result
normalized to `manual_recovery_required`/`contract_invalid` exit `70`; neither
case permits a second invocation. An observed `interrupted_pre_switch` receipt instead binds the
known `failed_pre_switch` terminal, exit `143`, and rollback `not_run`. A
missing, unreadable, or pre-digest dump failure uses `invalid`:
the known policy and 14,400-second ceiling remain fixed, observed values keep
their strict types, unavailable measurements stay `null`, and at least one
measurement must remain unavailable. A terminal failure with exit `22` through
`25` requires the claimed gate's failed evidence plus passed evidence for every
earlier verified gate;
the journal's last verified state must agree. Evidence `captured_at_utc` cannot
precede the terminal journal timestamp. A successful result requires all
safety and post-gate sections to pass. Post-switch terminal evidence reached
directly from `deploy_running` keeps post-gates `not_observed` and the separate
rollback section `not_invoked`; rollback performed inside `deploy_ea.sh` remains
part of the deploy-child evidence. A completed dedicated rollback terminal is
reachable only from `rollback_running`; it preserves the already successful
deploy child and requires the separately reserved rollback verdict. A
manual-recovery terminal reached from `post_gates_running` or
`rollback_running` requires failed post-gate evidence. The sole partial
exception is
`post_gates_running -> manual_recovery_required` with reason `interrupted`:
it may use status `incomplete` when the interruption prevented all checks from
finishing. Because the deploy child completed before post-gates began, its
deploy evidence remains `succeeded` with exit `0` and rollback `not_run`; the
terminal run still fails closed on the interrupted post-gates. Its separate
rollback evidence is `not_invoked` with reservation count `0` when the terminal
follows `post_gates_running`, or `unknown` with reservation count `1`, fixed
mode `dedicated_post_gate_recovery`, and no invented verdict when it follows
`rollback_running`. In that shape
`passed` is `null`, unobserved checks stay `null`,
observed booleans retain their exact values. At least one check
must remain unobserved. The same transition uses `passed` or `failed` when all
checks completed before terminal persistence; no other failure transition may
claim passed post-gates, and no other terminal state or reason accepts
incomplete post-gates. The outer wall clock must agree with the independently
stored second-precision UTC interval: for `delta_ms` derived from the two UTC
timestamps, the accepted range is
`max(0, delta_ms - 999) <= wall_clock_ms <= delta_ms + 999`.
The outer interval must enclose the journal lifecycle: its start is no later
than the immutable intent record, its finish is no earlier than the terminal
record, and that finish is no later than evidence capture.
Capacity and artifact collection failures use the same fail-closed distinction:
`invalid` retains the fixed ceiling or artifact expectation and every available
strictly typed measurement, while unavailable measurements, hashes, or the
artifact verification result remain `null`. A complete observation uses
`passed` or `failed`; `invalid` cannot claim success or verification and cannot
substantiate a different terminal reason.

## Retained production state checks

The unused Host Runner implementation and its request/launch contracts have
been removed. The operator deployment path is documented in
[deployment.md](deployment.md).

Backup, dump-attestation, and retention tools still use the shared production
lock and reject conflicting or unverifiable historical run state. Keep these
checks, the shared evidence validators, and
`/var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock`; removing the
unused runner does not make an existing run record safe to ignore.

## Local validation

The pure validator can validate a completed local fixture without privileged
execution:

```bash
php scripts/ops/validate_deployment_contract_v1.php \
  --run-jsonl=/path/to/events.jsonl \
  --evidence-json=/path/to/evidence.json
```

Exit `0` means the two closed contracts agree. Invocation errors exit `64`;
invalid or conflicting contract data exits `70`. This command does not inspect
or trust a production path and is not a production runner.
