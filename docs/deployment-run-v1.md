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

`deploy_ea.sh` remains the single deploy primitive. The later runner may invoke
it once; this contract never invokes it.

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

Terminal state is immutable. The `deploy_running` record is the durable
write-ahead invocation reservation: the future host runner must append and
fsync it before spawning `deploy_ea.sh`. It changes
`deploy_invocation_count` from `0` to `1`; the count can never exceed one or
return to zero. A crash or transport loss from that point is observe-only and
must never create a second deploy process. Before reservation, a valid prefix
may be attached for status/revalidation. After terminal persistence, attachment
only returns the existing result.

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

Arbitrary child exit codes or free-form reasons are never copied into evidence.
The future runner must normalize them into this table.

## Evidence contract

`deployment_evidence.v1` is a closed canonical JSON object. It carries only
fixed enums, booleans, non-negative integers, canonical UTC timestamps, UUIDs,
40-hex commits, and SHA-256 values. It has no fields for commands, arguments,
paths, filenames, hosts, addresses, URLs, usernames, customer/person data,
stdout, stderr, exception text, credentials, or raw logs.

Its sections are:

- expected and observed commit plus exact verification result;
- traffic-gate reference and normalized core;
- dump age/SHA/gzip/restore evidence;
- capacity observed/projected integers and decision;
- local/remote artifact, manifest, and host/artifact deploy-script hashes;
- exactly-once deploy exit and rollback outcome;
- independent post-gates including Kuma raw `13/13`, runtime config, services,
  endpoints, logs, scanner, and dormant/clean;
- a reference to the authoritative `deploy_timing.v1` file by SHA and its own
  timing Run-ID;
- outer orchestrator start/end/wall-clock values in a separate section;
- the terminal state and stable exit/reason pair.

Not-yet-observed sections retain their exact keys with `null` values and a
fixed `not_observed`/`not_invoked` status. They never invent zero hashes or
success. A terminal failure with exit `20` through `25` requires the claimed
gate's failed evidence plus passed evidence for every earlier verified gate;
the journal's last verified state must agree. A successful result requires all
safety and post-gate sections to pass. Timing remains observational as defined
in `docs/deployment.md`: missing
or invalid `deploy_timing.v1` evidence is visible but cannot rewrite a safe
deploy outcome. The outer wall clock never replaces, extends, or mixes with
the five-phase `deploy_timing.v1` baseline.

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
cutoff is `window_end_epoch`. Counts and the decision are recomputed by the
contract validator. Only complete `allow` or `advisory` evidence with exit `0`
can precede invocation reservation. Freshness and requested-window checks are
the responsibility of the later runner because they depend on its invocation
time; they may not be weakened by attaching to an old Run-ID.

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
- one global root-controlled lock and the invocation reservation in the same
  state root;
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
