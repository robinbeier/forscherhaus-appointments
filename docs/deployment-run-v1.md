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

## Host-runner request contracts

The internal root runner accepts two closed, secret-free request objects. This
contract freezes their future boundary; it does not install or execute the
runner.

`deployment_host_runner_request.v1` contains exactly `schema`, `run_id`,
`expected_commit`, `release_id`, `traffic_mode`, the fixed `dump_policy` and
`artifact_expectation` values, and the existing `intent_sha256` over those five
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

## Host-runner state contracts

`state.json` uses schema `deployment_host_runner_state.v1` and exactly these
keys:

```text
schema run_id intent_sha256 state sequence events_sha256 active_action deploy
rollback evidence_sha256 terminal updated_at_utc
```

`deploy` contains exactly `request_sha256`, `execution_input_sha256`,
`invocation_count`, `unit_name`, `unit_state`, `observed_exit_code`, and
`receipt_sha256`. `rollback` keeps the independent corresponding request,
execution-input, count, unit, and observed-exit fields plus its fixed
`not_invoked`/`succeeded`/`failed`/`unknown` verdict. `terminal` contains only
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
accepted receipt SHA to be present. A known successful rollback verdict requires
exit `0`; a known failed verdict requires a nonzero exit; an unknown verdict
requires a null observed exit. A nested action
contradiction is not a current terminal cache.
Counts are `0` or `1`. `active_action` is `deploy` only in `deploy_running`,
`rollback` only in `rollback_running`, and otherwise `none`. Unit state is one
of `not_created`, `starting`, `running`, `exited`, `failed`, `killed`, or
`unknown`. Arbitrary child exits may be retained only as the independently
observed byte-sized exit in runner state; `killed` records a systemd signal
verdict with a null normal exit, never a synthesized `143`. Public terminal
evidence still uses only the frozen stable pairs. A receipt SHA requires an
independently observed normal exit from `0`, `30`, `31`, `32`, or `143`.
Terminal fields and evidence SHA are all absent before terminal state and all
present afterwards. Every `manual_recovery_required` state preserves the
fsynced deploy reservation and count `1`. A terminal bundle may preserve an
unknown unit verdict, but an active-run claim is refreshed or cleared only when
every reserved unit is independently known `exited`, `failed`, or `killed`;
`starting`, `running`, or `unknown` keeps the global exclusion in place.

The fixed-enum operator journal uses schema
`deployment_host_operator_event.v1` and exactly:

```text
schema run_id intent_sha256 sequence recorded_at_utc action event status reason
```

Actions are `none`, `deploy`, `rollback`, or `reconcile`. Status is `ok`,
`running`, `failed`, `unknown`, or `terminal`. Events are limited to request
acceptance, attachment, durable reservation, unit start/observation, receipt
acceptance/rejection, post-gate observation, rollback reservation,
reconciliation required, terminal persistence, and active-run clearance.
Reasons are fixed codes for same-intent attachment, contract/state/lock/unit
classification, receipt classification, interruption, post-gate failure,
rollback verdict, and manual recovery. No detail, exception, path, command,
host, stdout, stderr, or raw journal field exists.

The exact event enum is `request_accepted`, `attached`,
`reservation_persisted`, `unit_started`, `unit_observed`, `receipt_accepted`,
`receipt_rejected`, `post_gates_observed`, `rollback_reserved`,
`reconciliation_required`, `terminal_persisted`, or `active_run_cleared`.
The exact reason enum is `none`, `same_intent`, `state_conflict`,
`contract_invalid`, `lock_busy`, `unit_collision`, `unit_running`,
`unit_exited`, `unit_failed`, `unit_killed`, `unit_missing`, `receipt_valid`,
`receipt_missing`, `receipt_invalid`, `receipt_mismatch`, `child_exit_74`,
`interrupted`, `post_gate_failed`, `rollback_succeeded`, `rollback_failed`, or
`manual_recovery_required`.

## Locks and durable active run

Every mutation or reconciliation acquires locks in one order:

1. `/run/lock/fh-production-change.lock`;
2. `/var/lib/fh-deploy-orchestrator/runs/<run_id>/run.lock`.

Both are stable root-owned mode-`0600` regular single-link files beneath trusted
canonical ancestors. They are opened, identity-checked, locked, rechecked, and
never unlinked or recreated. The invocation keeps both locks until its response
has been derived from durable state.

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

## Transient unit and internal CLI contract

Unit identity binds action, full Run-ID, and the first 12 hex characters of the
intent SHA:

```text
fh-deploy-<run_id>-<intent12>.service
fh-rollback-<run_id>-<intent12>.service
```

The system-manager transient service uses `Type=exec`, `RemainAfterExit=yes`,
`UMask=0077`, `KillMode=control-group`, `Restart=no`, journal output, null
standard input, `RuntimeMaxSec=7200s` for deploy or `1800s` for rollback, and
`TimeoutStopSec=300s`. It is argv-based without a shell. The runner never uses
scope units, `--wait`, `--pipe`, `--pty`, `--collect`, or unit-name reuse as an
attachment mechanism. A pre-existing unit-name collision is `75`; attachment
comes only from the bound durable run state.

The future root-only executable has three internal actions:

```text
--action=deploy --request-file=ABSOLUTE_PATH --execution-input-file=ABSOLUTE_PATH
--action=recovery --request-file=ABSOLUTE_PATH --execution-input-file=ABSOLUTE_PATH
--action=reconcile --run-id=UUIDV4 --intent-sha256=SHA256
```

The execution-input file is separate root-protected host-local input. Only its
exact byte SHA may enter state; its commands, arguments, paths, environment, and
secrets never enter a request, evidence, operator journal, or normal response.
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

The action/disposition/state combinations are closed. The first deploy request
is `accepted` in `accepted`; a later same-intent call can be
`attach_pre_deploy` at that same durable state because disposition distinguishes
request history, not just the state name. Pre-deploy attachment covers the
explicit frozen states from `planned` through `artifact_verified`; the list is
not derived from enum ordering. Observe-only attachment covers
`deploy_running`, `post_gates_running`, or `rollback_running`. Recovery is
`accepted` only in `post_gates_running` and observe-only only in
`rollback_running`. Reconcile may report only a pre-deploy attachment,
observe-only attachment, terminal result, or rejection. `succeeded` and every
terminal failure always use disposition `terminal`, never a nonterminal
disposition.

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
