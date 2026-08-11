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
- the explicitly selected traffic mode;
- `fresh_verified_under_240m` dump policy;
- `build_from_expected_commit` artifact expectation.

`intent_sha256` is the SHA-256 of their recursively key-sorted compact JSON.
The Run-ID is kept alongside that hash rather than folded into it. Reattaching
to an existing Run-ID is permitted only when the candidate intent independently
validates and has the same hash. Same Run-ID plus changed intent is exit `75`
(`state_conflict`); a new attempt needs a new authorization and Run-ID.

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
  -> expected_commit_verified -> traffic_gate_passed -> dump_verified
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
| `20` | `traffic_hard_stop` | complete traffic evidence blocks the run |
| `21` | `traffic_evidence_invalid` | traffic evidence is incomplete or invalid |
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
The receipt alone is not authoritative. `deploy_timing.v1`, stdout, stderr, and
process timing are never result oracles. Dry-run does not publish a receipt and
rejects `--result-file`.

## Evidence contract

`deployment_evidence.v1` is a closed canonical JSON object. It carries only
fixed enums, booleans, non-negative integers, canonical UTC timestamps, UUIDs,
40-hex commits, and SHA-256 values. It has no fields for commands, arguments,
paths, filenames, hosts, addresses, URLs, usernames, customer/person data,
stdout, stderr, exception text, credentials, or raw logs.

Its sections are:

- expected and observed commit plus exact verification result;
- traffic-gate reference and normalized core;
- dump age/SHA plus explicit checksum-, gzip-, and restore-verification evidence;
- capacity available/projected bytes, observed/projected used percentages, the
  fixed `85` percent ceiling, and a derived decision;
- local/remote artifact, manifest, and host/artifact deploy-script hashes;
- exactly-once deploy exit and any rollback performed inside that child;
- a separate at-most-once dedicated post-gate rollback reservation and verdict;
- independent post-gates including Kuma raw `13/13`, runtime config, services,
  endpoints, logs, scanner, and dormant/clean;
- a reference to the authoritative `deploy_timing.v1` file by SHA and its own
  timing Run-ID;
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
measurement must remain unavailable. A terminal failure with exit `20` through
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
incomplete post-gates. Timing remains
observational as defined in `docs/deployment.md`: missing or invalid
`deploy_timing.v1` evidence is visible but cannot rewrite a safe deploy outcome.
Invalid timing bytes retain their authoritative SHA while an unavailable parsed
timing Run-ID or total remains `null`; `valid` timing requires both values. The
outer wall clock never replaces, extends, or mixes with the five-phase
`deploy_timing.v1` baseline. Its milliseconds must agree with the independently
stored second-precision UTC interval: for `delta_ms` derived from the two UTC
timestamps, the accepted range is
`max(0, delta_ms - 999) <= wall_clock_ms <= delta_ms + 999`.
The outer interval must enclose the journal lifecycle: its start is no later
than the immutable intent record, its finish is no earlier than the terminal
record, and that finish is no later than evidence capture.
Runs that fail before reserving the deploy invocation must retain
`deploy_timing` as `not_observed`.

Capacity and artifact collection failures use the same fail-closed distinction:
`invalid` retains the fixed ceiling or artifact expectation and every available
strictly typed measurement, while unavailable measurements, hashes, or the
artifact verification result remain `null`. A complete observation uses
`passed` or `failed`; `invalid` cannot claim success or verification and cannot
substantiate a different terminal reason.

## Traffic-gate consumption

The later runner must read one unique `traffic_gate.v1` report file once into a
bounded buffer. It hashes those exact bytes, including their final newline, and
decodes the normalized core from the same buffer. It must never hash, reopen,
or re-encode a path as equivalent evidence. Compact stdout and pretty file
serialization are different byte sources.

Deploy evidence stores only:

- exact report SHA-256;
- `schema`, producer/policy/catalog versions, purpose, mode, and window bounds;
- `log_set_sha256`;
- rotation/parse/evidence completeness;
- decision and exit;
- the exact 20 aggregate count fields from the merged `traffic_gate.v1`.

It must not embed the report, raw traffic, a path, or a second snapshot.
`purpose` is `deploy`; mode must equal the immutable intent; the canonical
cutoff is `window_end_epoch`; the catalog version matches the producer grammar
`YYYY-MM-DD.N`. Counts and the decision are recomputed by the contract
validator. Only complete `allow` or `advisory` evidence with exit `0` can
precede invocation reservation. Freshness and requested-window checks are the
responsibility of the later runner because they depend on its invocation time;
they may not be weakened by attaching to an old Run-ID.

If the producer returns exit `21` without a parseable published report, traffic
status is `invalid`. Every report-derived core field remains `null`; an exact
raw-byte SHA-256 may be retained only when malformed bytes were actually read.
No-report evidence keeps that hash `null`. This is distinct from
`not_observed`, while a parseable report with an incomplete derived core retains
the full normalized fields and status `failed`. Partial or mixed cores are never
accepted.

Completeness is also derived, never trusted: rotation completeness follows
`rotation_errors`, parse completeness requires zero parse errors plus at least
one parsed line, and evidence completeness is the conjunction of both. Parsed
window lines and parse errors are disjoint producer outcomes, so their sum
cannot exceed `lines_seen`. Unknown source, method, and target overlays are
each bounded by the `unclassified` traffic class that the producer assigns.
Conversely, every unclassified line must be backed by at least one of those
unknown overlays in the aggregate evidence.
The `status_5xx`, `write`, `authenticated`,
`customers_or_sensitive` overlays are each bounded by the combined
`business_or_authenticated` and `unclassified` classes that can carry them.
`scanner_success` is bounded by `business_or_authenticated` alone because
unknown-field rows return as unclassified before scanner classification.
Scanner-success, target-unknown, and customer/sensitive overlays must also fit
into distinct eligible business or unclassified rows in aggregate.

Dump evidence passes only when it is strictly under 240 minutes old and its
checksum, gzip, and restore verification flags are all true. Capacity passes
only when available bytes cover projected required bytes,
observed and projected usage are both below the fixed `85` percent ceiling, and
the projection does not move backwards. The stored `passed` value and status
must equal that derived result. Post-gate `passed` is likewise the exact
conjunction of Kuma `13/13` and every named boolean gate; the healthy monitor
count can never exceed the total.

## Future root state trust boundary

The reserved host state root is:

```text
/var/lib/fh-deploy-orchestrator/runs/<run_id>/
```

This is a future contract, not a claim that the path already exists. The host
runner slice must establish and test these invariants before activation:

- canonical, non-symlink, root-controlled ancestor chain;
- root-owned state root and per-run directory, mode `0700`;
- root-owned regular non-symlink files, mode `0600`, one hardlink;
- one global root-controlled lock plus the normal deploy and dedicated rollback
  invocation reservations in the same state root;
- atomic complete-record append/replacement and durability before process
  spawn;
- identity checks before and after every read/write;
- no normalization of unsafe pre-existing paths;
- no automatic truncation of a corrupt tail.

Expected files are `intent.json`, `state.json`, `events.jsonl`,
`evidence.json`, and a redacted fixed-enum operator journal. Their persistence,
systemd unit, locking, crash injection, and process reconciliation belong to
the Host-runner PR. SSH/build/upload/start/status/wait belong to the Coordinator
PR.

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
