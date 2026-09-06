# Deployment Run Contract v1

Purpose: freeze the secret-free, fail-closed state and evidence boundary for the
future canonical production deploy orchestrator. This contract does not install
or activate a host runner, acquire a production lock, invoke `deploy_ea.sh`, or
connect to production.

## Delivery boundary

ROB-455 is delivered serially:

1. this contract and its pure validators;
2. a root-protected host runner that persists the contract atomically;
3. an operator-side coordinator that builds, uploads, starts, attaches, waits,
   and fetches evidence.

`deploy_ea.sh` remains the single normal deploy primitive. The later runner may
invoke that normal deploy mode once; this contract never invokes it. A failure
in an independent post-gate may require the script's dedicated recovery mode,
which has its own separately reserved at-most-once action and never increments
or resets the normal deploy invocation count.

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

This procedure also applies when upgrading completed post-gate evidence from
the former `13/13` Kuma monitor policy to the consolidated `12/12` policy.
Keep the old matching tools for reconciliation; do not rewrite historical
monitor counts to make old reports pass the new contract.

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

Run once as root on the production host, before installing the new tooling:

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
    archive = '/root/fh-deployment-runs-before-renderer-input-removal'
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
absent `runs/` directory is supported by the dump producer; the Host Runner
creates it for a new run. Update the related helpers together after this step
and use a new run ID. The moved history is outside the scanned tree and needs
no conversion or permanent compatibility code.

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
write-ahead invocation reservation: the future host runner must append and
fsync it before spawning `deploy_ea.sh`. It changes
`deploy_invocation_count` from `0` to `1`; the count can never exceed one or
return to zero. A crash or transport loss from that point is observe-only and
must never create a second deploy process. Before reservation, a valid prefix
may be attached for status/revalidation. After terminal persistence, attachment
only returns the existing result.

The independent post-gates run only after the normal deploy child has returned
successfully. If one of those gates fails, a future runner must durably reserve
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
requires manual recovery rather than an automatic retry. The Host-runner PR
owns the reservation write, fsync, spawn, and reconciliation implementation.

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

The future Host Runner may accept a candidate only while holding both the
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
- dump age/SHA plus explicit checksum-, gzip-, and restore-verification evidence;
- capacity available/projected bytes and inodes, the authenticated staged inode
  count, independently observed restored-datadir inode count, fixed 64-inode
  allowance, observed/projected used percentages, the fixed
  `85` percent ceiling, and a derived decision;
- local/remote artifact, manifest, and host/artifact deploy-script hashes;
- exactly-once deploy exit and any rollback performed inside that child;
- a separate at-most-once dedicated post-gate rollback reservation and verdict;
- independent post-gates including Kuma raw `12/12`, runtime config, services,
  endpoints, logs, scanner, and dormant/clean;
- outer orchestrator start/end/wall-clock values in a separate section;
- the terminal state and stable exit/reason pair.

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
observed booleans retain their exact values, and the two Kuma counts are either
both absent or both valid with healthy not exceeding total. At least one check
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

## Future root state trust boundary

The reserved host state root is:

```text
/var/lib/fh-deploy-orchestrator/runs/<run_id>/
```

This is a future contract, not a claim that the path already exists. The host
runner slice must establish and test these invariants before activation:

- canonical, non-symlink, root-controlled ancestor chain;
- root-owned state root and per-run directory, mode `0700`;
- root-owned regular non-symlink files, mode `0600`, link count exactly `1`;
- one global root-controlled lock plus the normal deploy and dedicated rollback
  invocation reservations in the same state root;
- atomic complete-record append/replacement and durability before process
  spawn;
- identity checks before and after every read/write;
- no normalization of unsafe pre-existing paths;
- no automatic truncation of a corrupt tail.

Expected files are `intent.json`, `state.json`, `events.jsonl`,
`evidence.json`, and a redacted fixed-enum operator journal. This prerequisite
freezes systemd identity and pure reconciliation decisions; the executable
Host-runner PR owns filesystem persistence, locking, crash injection, and real
process calls. SSH/build/upload/start/status/wait belong to the Coordinator PR.

## Host-runner request contracts

The internal root runner accepts two closed, secret-free request objects. This
contract freezes their future boundary; it does not install or execute the
runner.

`deployment_host_runner_request.v1` contains exactly `schema`, `run_id`,
`expected_commit`, `release_id`, the fixed `dump_policy` and
`artifact_expectation` values, and the existing `intent_sha256` over those four
immutable intent fields. It is the request form of the immutable
`deployment_run.v1` intent. It never contains sequence, timestamps, states,
reservation counts, results, commands, arguments, paths, hosts, receipts,
output, or free text. The runner writes the full intent record and all mutable
fields itself. Same Run-ID plus a different intent hash is
`75`/`state_conflict`.

`deployment_host_recovery_request.v1` contains exactly `schema`, `run_id`, and
`intent_sha256`. Recovery is an action on the existing durable run, not a new
intent. Host-local active/previous/failed release paths, runtime users,
commands, and verdicts are not caller fields. Recovery is accepted only from
`post_gates_running`; an existing `rollback_running` reservation attaches
observe-only, and a terminal run returns its stored result without a second
reservation.

Standalone request and state files are recursively key-sorted compact JSON with
one final newline, no NUL, and at most 4,096 bytes. Their file SHA-256 covers
those exact bytes including that newline. Missing, extra, wrongly typed,
noncanonical, oversized, or invalid data is `70`/`contract_invalid`; it is never
repaired or normalized.

The separately pinned `deployment_host_execution_input.v1` is canonical JSON
with one final newline, no NUL, and at most 16,384 bytes. It contains exactly
`schema`, `run_id`, `intent_sha256`, `action`, and `parameters`; it contains no
caller-selected executable, argv, environment, inline secret, app root, runtime
user, or result path. Deploy parameters bind the immutable `release_id`,
`artifact_provenance_sha256`, and root-protected path-plus-SHA references for the
health token, dump, predeploy credentials, canary credentials, and incident
webhook. Renderer operation is fixed to the Docker-backed service; no renderer
mode is accepted in the execution input. The execution-input producer is a fully trusted root authority for
selecting those protected files; web/user-authored input is rejected, and a
path plus digest is integrity evidence rather than authorization by itself.
Before a deploy reservation, the executable snapshots those five references
into immutable root-owned mode-`0600` single-link files in the protected run
directory: `deploy-ref-healthz-token`,
`deploy-ref-zero-surprise-dump.sql` or
`deploy-ref-zero-surprise-dump.sql.gz`,
`deploy-ref-predeploy-credentials`, `deploy-ref-canary-credentials`, and
`deploy-ref-incident-webhook`. The helper receives source path and expected SHA
only on bounded stdin, opens components without following links, streams and
hashes one stable source descriptor, fsyncs a hidden same-directory temporary,
publishes with Linux `renameat2(RENAME_NOREPLACE)`, and fsyncs the run
directory. An exact existing snapshot is authoritative for retry even if the
source has rotated; another expected digest conflicts. Small snapshots are
bounded to 1 MiB. The dump is streamed with a 16 GiB cap, a 30-minute helper
deadline, and free-space headroom equal to the greater of 512 MiB or ten
percent of its size. Snapshot bytes persist with the protected run record and
never enter stdout, stderr, operator events, evidence, responses, descriptions,
or process argv.
Recovery parameters contain only the original immutable `release_id`. The
recovery bundle must bind both the closed recovery request and the original
deploy request, so a recovery request alone cannot authorize a different
release. The runner constructs one fixed argv vector through `/usr/bin/env -i`,
the fixed `LANG=C`, `LC_ALL=C`, and `PATH`, `/bin/bash`, and
the immutable run-local `deploy-script.sh` or `rollback-script.sh` snapshot whose
SHA-256 is bound by the launch record. The exact protected script bytes are
pinned without replacement and file-/directory-fsynced before the reservation
journal is published, so a later replacement of `/root/deploy_ea.sh` cannot
change the admitted child. Deploy result, app, previous, failed, and
runtime-user paths are derived from the trusted state root, Run-ID, and immutable release;
they are never caller authority. The request and execution input are each read
once, validated, copied without replacement into the protected run directory,
file-fsynced, parent-directory-fsynced, and SHA-bound before reservation. This
PR freezes that storage contract but does not implement the storage engine. A
crash after the no-replace input pin but before reservation resumes only when
the caller bytes are exactly identical to the pinned canonical bytes; changed
bytes are a conflict and the pinned file is never replaced.
The child argv contains the run-local script and five protected-input snapshot paths; it never
contains the original protected source paths or their digests.

`deployment_host_post_gate_report.v1` is the same bounded canonical form and
contains exactly `schema`, `run_id`, `intent_sha256`, `captured_at_utc`,
`subject`, the deploy receipt SHA when the subject is `deploy`, and the closed
existing `post_gates` tuple. `--action=post-gates` receives the identity request
plus an explicit report file. Its producer is trusted root authority for the
reported observations; canonical bytes alone do not establish gate truth, and
web/user-authored reports are rejected. The runner validates the report against the
completed action, pins those exact bytes without replacement and with file and
parent-directory fsync, and records their SHA, count `1`, and `passed`/`failed`
verdict. An exact-byte retry attaches idempotently without incrementing the
count; changed bytes are `75`/`state_conflict` and never overwrite the first
submission. A crash after report pinning but before the state slot update uses
the same rule: an identical pinned file resumes the first submission, while
different pinned bytes fail closed. Recovery admission re-reads the exact
pinned failed deploy report, validates its SHA and receipt binding against
current durable state, and never trusts the state verdict alone.

## Host-runner state contracts

`state.json` uses schema `deployment_host_runner_state.v1` and exactly these
keys:

```text
schema run_id intent_sha256 state sequence events_sha256 active_action deploy
post_gates rollback evidence_sha256 terminal updated_at_utc
```

`deploy` contains exactly `request_sha256`, `execution_input_sha256`,
`invocation_count`, `unit_name`, `unit_launch_sha256`,
`unit_manager_boot_id`, `unit_invocation_id`,
`unit_missing_observed_boot_id`, `unit_state`, `observed_exit_code`, and
`receipt_sha256`. `rollback` keeps the independent corresponding request,
execution-input, count, unit, and observed-exit fields plus its fixed
`not_invoked`/`verification_pending`/`succeeded`/`failed`/`unknown` verdict.
`post_gates` keeps separate deploy and rollback report SHA, count, and verdict
triples; every count is `0`/`not_submitted`/null or `1` with an immutable SHA
and `passed`/`failed` verdict. `terminal` contains only
`state`, `exit_code`, and `reason`. Keeping deploy and rollback in separate
closed objects prevents a recovery request or unit from overwriting the
exactly-once deploy binding.

`events.jsonl` is authoritative. `state.json` is a SHA-bound cache and can lag
only when the complete canonical journal proves the next state. A contradiction
or corrupt/partial journal fails closed; neither file is truncated or repaired.
For a current terminal cache, its state, exit, and reason must equal the final
journal record, and its `evidence_sha256` must hash the exact canonical
`evidence.json` bytes including the final newline. The evidence is bounded to
65,536 bytes and must pass the existing `deployment_evidence.v1` bundle
validator against that same complete journal. A terminal journal without this
matching durable state and evidence remains reconciliation-required.
The cached deploy invocation count, accepted-receipt presence, and known
observed deploy exit must match the validated deploy evidence; the cached
rollback count and verdict must likewise match the validated dedicated rollback
evidence. `post_gates_running` and `rollback_running` additionally require the
deploy unit to be `exited`, its independently observed exit to be `0`, and its
accepted receipt SHA to be present. A dedicated rollback normal exit `0` first
records `verification_pending`; it is not success until a bound
rollback-subject post-gate report passes. A normal nonzero rollback exit,
including a normal exit `143`, maps uniquely to rollback failed and needs no
verification report. Signal, core, timeout, or otherwise unproved termination
is not passed to that normal-exit mapping and remains unknown. A bound failed
rollback report after normal exit `0` also maps to rollback failed. An unknown
verdict requires a null observed exit while the run is nonterminal. One narrow
terminal exception preserves an already fsynced `manual_recovery_required`
result: when its dedicated rollback verdict/evidence is frozen `unknown` and no
rollback report exists, a later exact unit observation may add only stopped
unit proof (including a normal exit) while the verdict, evidence, reports, and
public terminal pair remain `unknown`/unchanged. A nested action contradiction
is not a current terminal cache. Outside that frozen-terminal exception, the
closed rollback tuple is therefore: null observed exit with `unknown` and no
report; exit `0` with no report and
`verification_pending`; exit `0` with a passed/failed report and matching
`succeeded`/`failed` verdict; or a nonzero normal exit with `failed` and no
report. The two direct deploy receipt outcomes `internal_rollback_succeeded`
and `rollback_failed_or_unverifiable` remain valid terminal deploy results with
no dedicated rollback reservation or post-gate submission; the report
requirements apply only when the independent rollback invocation count is `1`.
For every known deploy outcome, the stored receipt SHA must equal the SHA-256 of
the unique canonical `deploy_result.v1` bytes derived from the validated deploy
evidence tuple; mere hash presence is not a binding.
Counts are `0` or `1`. A reserved action binds the canonical launch-record SHA
and the manager boot ID. The first complete matching loaded-unit observation
binds one lowercase systemd InvocationID permanently. A later manager reboot
plus an exact not-found result may add the distinct observed boot ID and
classify the transient unit `missing`; same-boot absence or transport ambiguity
remains `unknown`. `active_action` is `deploy` only in `deploy_running`,
`rollback` only in `rollback_running`, and otherwise `none`. Unit state is one
of `not_created`, `starting`, `running`, `exited`, `failed`, `killed`,
`missing`, or `unknown`. Arbitrary child exits may be retained only as the independently
observed byte-sized exit in runner state; `killed` records a systemd signal
verdict with a null normal exit, never a synthesized `143`. Public terminal
evidence still uses only the frozen stable pairs. A receipt SHA requires an
independently observed normal exit from `0`, `30`, `31`, `32`, or `143`.
Terminal fields and evidence SHA are all absent before terminal state and all
present afterwards. Every `manual_recovery_required` state preserves the
fsynced deploy reservation and count `1`. A terminal bundle may preserve an
unknown unit verdict, but an active-run claim is refreshed or cleared only when
every reserved unit is independently known `exited`, `failed`, `killed`, or
reboot-proven `missing`;
`starting`, `running`, or `unknown` keeps the global exclusion in place.
After terminal persistence, exact late unit observation may move such an
unknown unit to one of those stopped states solely to release the claim. It
does not refine the frozen rollback verdict, rewrite terminal evidence, add a
post-gate report, or change the stored response.
The later observation is a new append-only generation (or an atomic COW
replacement retaining the prior generation) and has its own strict durability
prefix: write and fsync the exact new observation envelope and, when first
learned, its observed binding; publish and fsync the converged terminal state;
refresh and fsync the terminal active claim; then clear that exact claim and
fsync its parent directory. A crash before the converged state keeps the old
unknown state and claim held. A crash after state publication resumes claim
refresh; after refresh it resumes only exact claim clearance. State ahead of
the observation bytes, claim refresh ahead of state, or clearance ahead of
either is not authoritative and fails closed.

The fixed-enum operator journal uses schema
`deployment_host_operator_event.v1` and exactly:

```text
schema run_id intent_sha256 sequence recorded_at_utc action event status reason
```

Actions are `deploy`, `post_gates`, `rollback`, or `reconcile`. Status is `ok`,
`running`, `failed`, `unknown`, or `terminal`. Events are limited to request
acceptance, attachment, durable reservation, unit start/observation, receipt
acceptance/rejection, post-gate observation, rollback reservation,
reconciliation required, terminal persistence, and active-run clearance.
Reasons are fixed codes for same-intent attachment, contract/state/unit
classification, receipt classification, interruption, post-gate failure,
rollback verdict, manual recovery, and the stable lifecycle reasons reachable
by `terminal_persisted`. Action, event, status, and reason are validated as one
closed reachability matrix; an enum-valid Cartesian tuple is not sufficient.
No detail, exception, path, command, host, stdout, stderr, or raw journal field
exists.

The exact event enum is `request_accepted`, `attached`,
`reservation_persisted`, `unit_started`, `unit_observed`, `receipt_accepted`,
`receipt_rejected`, `post_gates_observed`, `rollback_reserved`,
`reconciliation_required`, `terminal_persisted`, or `active_run_cleared`.
The exact reason enum is `none`, `same_intent`, `state_conflict`,
`contract_invalid`, `unit_running`,
`unit_exited`, `unit_failed`, `unit_killed`, `unit_missing`, `receipt_valid`,
`receipt_missing`, `receipt_invalid`, `receipt_mismatch`, `child_exit_74`,
`interrupted`, `post_gate_failed`, `ok`, `dump_verification_failed`,
`capacity_gate_failed`, `artifact_verification_failed`,
`expected_commit_mismatch`, `deploy_failed`, `rollback_failed`,
`switch_recovery_required`, or `manual_recovery_required`.

## Locks and durable active run

Every mutation or reconciliation acquires locks in one order:

1. `/var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock`;
2. `/var/lib/fh-deploy-orchestrator/runs/<run_id>/run.lock`.

Both are stable root-owned mode-`0600` regular single-link files beneath trusted
canonical ancestors. They are opened, identity-checked, locked, rechecked, and
never unlinked or recreated. The invocation keeps both locks until its response
has been derived from durable state.

For file-backed CLI actions, the privileged supervisor reads every root-owned
mode-`0600` single-link input through one bounded, no-follow file descriptor.
It builds one closed in-memory envelope from those exact bytes and validates
the full request/action contract before creating a run directory. The same
envelope supplies both the Run-ID used for lock selection and the bytes later
consumed by the locked PHP action; PHP never reopens the caller paths. Invalid
or identity-mismatched input therefore creates no run-local state.

The supervisor keeps an action-specific bounded outer deadline while holding
both locks. Deploy allows 6300 seconds so the sequential protected build,
dump, capacity, reference-pin, preflight, and admission deadlines can
all complete; the other production actions retain a 2400-second bound. The
test-only lock probe remains limited to three seconds.

The production-change lock is global across conforming Host Runner invocations,
not across manual root actions or non-participating tools. Those privileged
actors remain outside this exclusion boundary until they explicitly adopt the
same lock contract. This prerequisite freezes the protected path; its
ancestor/leaf implementation and root/Linux proof belong to the executable
storage slice.

Process locks are not crash-durable. While both locks are held, every action
uses this write-ahead order before any spawn:

1. append and fsync the `deploy_running` or `rollback_running` journal reservation;
2. atomically persist and fsync `active-run.json` bound to that exact reserved journal prefix;
3. atomically persist and fsync the matching `state.json` cache;
4. start the reserved unit exactly once.

`/var/lib/fh-deploy-orchestrator/active-run.json` uses schema
`deployment_host_active_run.v1`. It contains exactly `schema`, `run_id`,
`intent_sha256`, the reserved nonterminal `state`, `sequence`, `events_sha256`,
and `claimed_at_utc`. Allowed states are `deploy_running`,
`post_gates_running`, `rollback_running`, or a terminal state used only for the
clearance handoff. `sequence` and `events_sha256` always bind the exact
canonical authoritative journal prefix through that sequence, including its
final newline. That prefix remains independently provable after `state.json`
advances. A crash after the reservation fsync but before the claim fsync leaves
the journal as the authoritative reservation. The next holder of the global
lock must scan the trusted run journals and reconstruct the one provable
missing claim before handling any candidate; it never spawns from that recovery
path.

For missing-claim reconstruction, an absent `state.json` is valid only for the
first `deploy_running` reservation journal prefix. A present cache may be
current, or it may lag exactly one `deploy_running` or `rollback_running`
reservation record. Current bytes are validated and preserved exactly; a
one-record-lag cache may advance only through the normal immutable state
evolution checks. `post_gates_running` is not a reservation write and a
rollback reservation always retains its authoritative prior post-gate cache,
so neither may use an absent cache as reconstruction authority.

Under the global lock, a different Run-ID is exit `75` while that claim binds a
nonterminal trusted journal, even if no runner process remains or the unit has
already exited. The exact run and intent may only attach/reconcile. A terminal
journal is not required for that attachment: if the trusted nonterminal
`state.json` and journal have advanced beyond the claim's still-valid prefix,
the exact run returns `attach_observe_only`; the claim continues to block every
different Run-ID and never authorizes another spawn. A terminal
journal plus matching durable state and evidence first yields
`refresh_terminal_claim`: the runner atomically replaces and fsyncs the stale
nonterminal claim with a terminal claim bound to the complete terminal journal.
Only that exact terminal claim yields `clear_terminal`, followed by atomic
file+directory fsync clearance. A crash between refresh and clearance is thus
reconcilable without ignoring the original reserved journal-prefix binding. A missing claim with one
discovered trusted nonterminal reserved run is reconstructed; multiple,
corrupt, mismatched, or unprovable candidates fail closed and never authorize a
new spawn.
The candidate Run-ID and intent are checked against the durable claim before
both attach and terminal-clear decisions; terminal clearance never bypasses a
candidate mismatch.

State evolution is independently monotonic. An unchanged lifecycle state keeps
the same authoritative journal sequence/hash while nested observation fields
may move through their closed forward matrix. A lifecycle transition advances
by exactly one valid journal record, changes the journal hash, and may not skip
or cross branches. Launch SHA, manager boot, InvocationID, accepted exit,
receipt, report bytes/SHA/verdict, and terminal result are write-once. Unknown
observation may later resolve to starting/running/terminal/missing; a terminal
unit state and terminal lifecycle never regress.

Terminal persistence has one recoverable prefix order:

1. pin exact action observation bytes (canonical launch/binding plus the
   canonical loaded-observation envelope containing raw systemctl bytes, or a
   canonical absence observation) and all submitted report bytes;
2. fsync the prevalidated evidence candidate;
3. append and fsync the unique terminal journal record;
4. publish and fsync canonical terminal evidence;
5. publish and fsync matching terminal state;
6. refresh and fsync the terminal active claim;
7. clear the exact terminal claim and fsync its parent.

Every crash prefix resumes at its unique next step; a skipped, duplicated, or
reordered prefix fails closed. Terminal cache/attachment requires exact pinned
report bytes for every submitted subject and one exact reconciliation bundle
for every reserved action. It may preserve `unit_state=unknown` and return the
stored terminal status. For the exact same run, an otherwise current terminal
bundle with a live/unknown unit returns internal `terminal_claim_held`: the
immutable terminal response is attachable, but the active claim is neither
refreshed nor cleared and still blocks every different Run-ID. Active-claim
refresh/clear is stricter: every reserved
unit must be independently stopped or reboot-proven missing, so unknown/live
state continues to block other runs. When `active-run.json` is absent, zero
reserved candidates means normal candidate handling may continue. Exactly one
journal-proven reserved candidate is reconstructed observe-only. Its cache may
be current, absent only at the first `deploy_running` reservation seam, or
exactly one final `deploy_running`/`rollback_running` reservation record
behind. Current bytes are preserved; a lagging cache advances only through the
normal immutable evolution checks. Multiple, corrupt, incomplete-terminal, or
otherwise unprovable candidates fail closed.

## Transient unit and internal CLI contract

Unit identity binds action, full Run-ID, and the first 12 hex characters of the
intent SHA:

```text
fh-deploy-<run_id>-<intent12>.service
fh-rollback-<run_id>-<intent12>.service
```

The system-manager transient service uses `Type=exec`, `RemainAfterExit=yes`,
`UMask=0077`, `KillMode=control-group`, `Restart=no`, null standard input,
output, and error, `RuntimeMaxSec=7200s` for deploy or `1800s` for rollback, and
`TimeoutStopSec=300s`. It is argv-based without a shell. The runner never uses
scope units, `--wait`, `--pipe`, `--pty`, `--collect`, or unit-name reuse as an
attachment mechanism. A pre-existing unit-name collision is `75`; attachment
comes only from the bound durable run state.

Before reservation, the runner queries the exact unit name with fixed absolute
argv under `/usr/bin/env -i`, `LANG=C`, `LC_ALL=C`, and the fixed system PATH.
Only a complete current-boot systemctl result with the exact unit ID,
`LoadState=not-found`, `ActiveState=inactive`, `SubState=dead`, empty
InvocationID, zero exec fields, and `Transient=no` proves availability. Any
loaded same-name unit is a collision even when its Description or properties
do not match; malformed, transport-failed, contradictory, or boot-raced output
is unknown. Neither collision nor unknown may reserve or spawn.

The transient launch record is canonical
`deployment_host_systemd_launch.v1`. It binds action, full Run-ID and intent,
request and execution-input hashes, the exact deploy-script byte hash, exact
child argv hash, unit name, every fixed unit property, and a root-protected
256-bit CSPRNG nonce. Its exact keys are `schema`, `action`, `run_id`,
`intent_sha256`, `request_sha256`, `execution_input_sha256`,
`deploy_script_sha256`, `argv_sha256`, `environment_sha256`,
`properties_sha256`, `unit_name`, `properties`, and `launch_nonce`; canonical
bytes are bounded to 16,384 bytes. The nonce is generated
inside the trusted launch boundary; the injectable generator exists only for
pure tests and the executable must never supply it. A secret-free Description
contains only a domain-separated hash of the complete launch record. The nonce,
input hash, protected paths, and component file hashes never enter the
Description, operator event, or response. Immediately before admission the
runner revalidates the bound request/input bundle, exact script bytes, launch
hash, reserved unit binding, current manager boot, and child argv; a
caller-selected command or matching forged argv hash is not authority.

Canonical `deployment_host_systemd_unit_binding.v1` bytes are bounded to
16,384 bytes and contain exactly `schema`, `run_id`, `intent_sha256`, `action`,
`unit_name`, `unit_launch_sha256`, `unit_manager_boot_id`,
`unit_invocation_id`, and `binding_state`. `reserved` has a null InvocationID;
the first exact loaded observation advances once to `observed`, after which the
InvocationID and every identity field are immutable. The binding, not the
launch record alone, commits the reservation-manager boot and is rechecked
against `/proc/sys/kernel/random/boot_id` immediately before admission.

Exact loaded observations use canonical
`deployment_host_systemd_loaded_observation.v1` bytes, bounded to 65,536 bytes,
with exactly `schema`, `manager_boot_id`, and `systemctl_show`; the last field
contains the bounded raw fixed-field systemctl bytes. Exact absence/transport
observations use canonical `deployment_host_systemd_absence.v1` bytes, bounded
to 1,024 bytes, with exactly `schema`, `kind`, and `manager_boot_id`.
`not_found` requires a boot ID; `transport_error` requires null. Terminal
reconciliation decodes the schema first and then derives `unknown`/`missing`
or the loaded unit result; it never chooses the evidence format from a
self-asserted final state.

Both `systemd-run` and `systemctl` controller processes use the same explicit
empty/fixed environment; SSH, sudo, locale, D-Bus, or other caller variables
are never inherited. `systemd-run` additionally receives
`--expand-environment=no` before the child separator, so dollar expressions in
the already validated and hashed child arguments remain literal rather than
being expanded from the service manager environment. Loaded observation parses the exact bounded
`systemctl show` field set once, rejecting missing, duplicate, unknown,
noncanonical, or contradictory fields. It verifies unit ID, Description,
Transient flag, all fixed properties, manager boot, and the immutable
InvocationID. Normal exit `143` remains a normal exit; signal 15, core dump,
timeout, and watchdog are killed/unknown rather than normal-exit or synthesized
`143` results.

The durable reservation is the irreversible public admission boundary. A
successful systemd-run request becomes observe-only. A nonzero or missing
systemd-run result becomes internal
`observe_only_reconciliation_required`: it is never a preflight collision,
never a rejected/no-state response, and never authorizes another admission.
The originating invocation still returns `accepted` in the durable
`deploy_running` or `rollback_running` state with `0`/`ok`; a later exact
invocation returns `attach_observe_only` in the same reserved or advanced
observe-only state. These pairs mean admission/attachment status succeeded,
not that the child succeeded. Only a
later authoritative unit/receipt reconciliation may persist a terminal result,
including justified `manual_recovery_required`/`70`; terminal attachment still
uses CLI exit zero and the stored lifecycle pair.

The root-only executable exposes exactly four actions through the privileged
supervisor; its PHP dispatch modes remain internal:

```text
--action=deploy --request-file=ABSOLUTE_PATH --execution-input-file=ABSOLUTE_PATH
--action=post-gates --request-file=ABSOLUTE_PATH --report-file=ABSOLUTE_PATH
--action=recovery --request-file=ABSOLUTE_PATH --execution-input-file=ABSOLUTE_PATH
--action=reconcile --run-id=UUIDV4 --intent-sha256=SHA256
```

The execution-input file is separate root-protected host-local input. Only its
exact byte SHA may enter protected state; protected file references and
constructed argv never enter a request, evidence, operator journal, or normal
response. The input SHA and its component file SHAs and paths remain
root-protected too: none is copied into unit names/descriptions, responses, or
operator logs as a deterministic offline oracle.
Unknown or incomplete flags exit `64`; contract-invalid input exits `70`; an
intent, active-run, lock, phase, or unit conflict exits `75`. Accepted start and
nonterminal attachment exit `0`. Terminal attachment also exits `0` as a status
operation while returning the immutable stored lifecycle exit/reason inside the
response; it does not replay that failure as the CLI process exit. Neither a
deploy nor recovery attachment may return `terminal` from `events.jsonl` alone:
the caller must also supply the matching current terminal `state.json` and
canonical `evidence.json`, and the complete bundle must pass the same strict
terminal-cache validation. A terminal journal with either derived file absent
or mismatched remains reconciliation-required.

Normal stdout begins only after both `run_id` and `intent_sha256` have been
validated. A flag or request failure before that identity boundary exits `64`
or `70` with no stdout and only a fixed, secret-free diagnostic on stderr; the
runner never fabricates an identity or emits null identifiers. After the
identity boundary, normal stdout is one canonical
`deployment_host_runner_response.v1` object with exactly `schema`, `run_id`,
`intent_sha256`, `action`, `disposition`, `state`, `result_exit_code`, and
`result_reason`. Disposition is `accepted`,
`attach_pre_deploy`, `attach_observe_only`, `terminal`, or `rejected`.
Nonterminal accepted responses carry `0`/`ok`; terminal responses carry the
stored lifecycle pair; rejected responses carry only `70`/`contract_invalid` or
`75`/`state_conflict`. Diagnostics are fixed and secret-free. Coordinator-owned
SSH, build, upload, start/status/wait UX, post-gate collection, and host
activation remain outside this contract PR.

The action/disposition/state combinations are closed. The first deploy start is
`accepted` only after the `deploy_running` reservation is durable. Pre-deploy attachment covers the
explicit frozen states from `planned` through `artifact_verified`; the list is
not derived from enum ordering. Observe-only attachment covers
`deploy_running`, `post_gates_running`, or `rollback_running`. Recovery is
accepted only after the `rollback_running` reservation is durable and only
after a bound failed deploy post-gate report; observe-only recovery is likewise
`rollback_running`. A first failed post-gate submission is accepted in
`post_gates_running`, and an exact replay attaches observe-only. A passing
submission returns its terminal result. Reconcile may report only a pre-deploy attachment,
observe-only attachment, terminal result, or rejection. `succeeded` and every
terminal failure always use disposition `terminal`, never a nonterminal
disposition.

This remains an additive prerequisite, not a runnable Host Runner. This serial
contract slice freezes launch and controller identity, systemd admission and
observation, manager boot and InvocationID binding, missing-unit proof,
monotonic state evolution, active-claim reconstruction/clearance, terminal
crash-prefix ordering, the closed operator tuple matrix, and exact
report/observation-byte participation in terminal attachment and clearance.
The later executable slice still owns protected filesystem creation and
identity rechecks, global/run flock acquisition, atomic no-replace/COW writes,
file and parent fsync calls, actual process execution, and root/Linux crash and
race tests. Child standard
streams are discarded at the systemd boundary, but `deploy_ea.sh` still owns
its internal deployment logfile; activation therefore requires a separate
root-owned mode-`0600` secure-log and redaction audit. No claim here treats that
existing logfile as part of the secret-free runner response/evidence boundary.

## Local validation

The request/state contract is a pure PHPUnit test in the general CI suite; it
does not require root or a live systemd manager:

```bash
php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php \
  tests/Unit/Scripts/DeploymentHostRunnerContractV1Test.php
```

Root/Linux storage, lock, fsync, crash, and transient-unit execution tests stay
with the later executable Host Runner. This contract-only PR does not add an
inactive implementation to the privileged CI list.

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
