# Workflow

This document defines the operational delivery workflow for
`forscherhaus-appointments`. It complements [AGENTS.md](AGENTS.md); if the two
conflict, follow `AGENTS.md`.

## Canonical Scope

- Use `README.md` for operator onboarding, quickstart, and local service usage.
- Use `docs/agent-harness-index.md` for the shortest route to the right
  steering source.
- Use `AGENTS.md` for compact repo guardrails, validation defaults, and topic
  routing.
- Use `WORKFLOW.md` for active agent behavior, Linear state handling, workpad
  discipline, and the ticket-to-merge loop.
- Use [`.codex/contracts/agent-workflow.json`](.codex/contracts/agent-workflow.json)
  as the machine-readable source for cross-document workflow invariants.

## Non-Negotiables

- Keep production code inside `application/`.
- Do not modify `system/` unless the change is an explicit upstream patch.
- Use CodeIgniter migrations for DB changes and keep rollback paths complete.
- Run CI-parity checks through Docker for merge-sensitive changes.
- For multi-PR work, keep publication, integration, and landing serial. Local
  implementation may overlap only under the controlled parallel-work contract
  below.
- Preserve the current invariant: `services.attendants_number == 1` unless the
  product scope changes explicitly.
- When `docs/maps/component_ownership_map.json` marks a component as
  `single-owner` or `manual_approval_required`, keep changes narrow and
  conservative.

## Model-Aware Delegation

The primary agent owns the end-to-end goal, authority boundary, sequencing,
file ownership, integration, validation, issue and PR state, merge decision,
and every production action. Delegation does not transfer those
responsibilities.

Use the repo-defined `implementation_worker` as the default subordinate for a
concrete implementation, regression-test, or documentation slice when that
slice is independently verifiable and has non-overlapping ownership. The role
is registered as `[agents.implementation_worker]` in `.codex/config.toml` and
its resolved config layer pins `gpt-5.6-luna` with medium reasoning in
`.codex/agents/implementation-worker.toml`. The project-wide
`agents.default_subagent_model` and
`agents.default_subagent_reasoning_effort` values carry the same Luna/medium
defaults for v2 spawn interfaces that do not expose custom agent types.

The primary agent must provide each implementation worker with:

- one bounded outcome and explicit file or module ownership
- relevant constraints and acceptance criteria
- the narrow validation expected from the worker
- notice that other agents may be editing the repository and that their work
  must not be reverted

Keep work local to the primary agent when it is trivial, tightly sequential,
ambiguous, cross-cutting, or likely to overlap another writer. Architecture,
hard debugging, conflict resolution, security-sensitive authority decisions,
final integration, and production planning stay with the primary agent or an
explicitly stronger specialist. Do not create delegation merely to fill the
available thread limit.

The implementation worker is a pure subordinate: it must not spawn or
coordinate other agents, merge, push, publish PRs, change issue state, or
perform production actions. Production and other irreversible external
actions always require the primary agent and the applicable explicit approval.

Before the first delegation in a session, inspect the active spawn interface:

- When it exposes custom agent types, select
  `agent_type="implementation_worker"`. This applies the registered role's
  model, sandbox, and subordinate instructions.
- When it exposes only `task_name`, `message`, and `fork_turns`, use that
  generic spawn path without a model override. The project-wide subagent
  defaults apply Luna/medium. Because this legacy path cannot apply the role's
  dedicated instructions, the task message must repeat the bounded ownership,
  no-delegation, no-push/merge/PR/issue-state, and no-production boundaries.

`task_name` always names the delegated task path; it never selects an agent
role or model. If the active runtime cannot honor either registered roles or
the project-wide subagent defaults, fail closed: do not claim that a Luna
worker ran, and either keep the task with the primary agent or disclose an
explicit supported fallback.

Choose `fork_turns` only for the context the task needs; do not rely on it as a
model selector. Prefer a self-contained task with `fork_turns="none"` or a
small positive turn window to avoid irrelevant history. Use
`fork_turns="all"` only when the worker genuinely needs the full conversation.
The registered role remains the model authority. If Luna is unavailable,
disclose the fallback and prefer `gpt-5.6-terra` or keep the task with the
primary agent instead of silently changing the execution model.

After a worker returns, the primary agent inspects the diff, reconciles it with
concurrent work, runs integration-level validation, and obtains independent
review. A worker's completion report is implementation evidence, not review,
merge, or production authority.

## Controlled Parallel Work

Parallel work means local implementation only. It is opt-in for explicitly
approved, independently verifiable lanes; normal PR publication, Linear
mutation, integration, attestation, and landing remain serial.

The machine-readable contract and validator are the implementation authority:
`.codex/contracts/agent-workflow.json`,
`scripts/agent/trusted_base_launcher.sh`, and the validator payload
`scripts/agent/check_parallel_work_contract.sh`. They bind the live canonical
main, exact common base, clean validator checkout, explicit disjoint
`directory`/`exact_file` ownership, semantic-independence attestation, component
approvals, primary-reserved paths, and at most two implementation-worker lanes.
The Primary privately materializes the launcher with fixed system Git from the
already verified declared base, verifies its exact blob and non-executable tree
mode, and only then starts it in clean Bash. The launcher in turn privately
reads the `trusted_base_bootstrap` manifest from that exact base, then
materializes and verifies its declared shared runtime and payload before any
checkout code can execute. It starts the attested shared runtime directly;
that runtime dispatches only the separately attested, manifest-bound payload,
independently validates the same manifest, and owns clean Git/Python plus
declared-path materialization for both agent-harness payloads.
Direct execution of any checked-out bootstrap script is forbidden and fails
closed. The validator verifies provisional pre-commit ownership and clean
post-commit integration evidence. One exact-base Python engine owns ownership
path normalization, validation, matching, and overlap semantics for the lane
validator plus Python CI/documentation consumers. PHP consumes only its
strictly validated JSON result. The language-neutral match, overlap, and
invalid-rule cases live in `.codex/contracts/ownership-path-rules.json`;
callers may not silently normalize supplied ownership candidates. Do not
reproduce the bootstrap command in other docs or duplicate validator internals;
use this canonical command and the machine contract.
Do not replace this with an ambient `git show` or a checked-out wrapper. The
machine contract owns the fixed system-Git bootstrap and payload selection.

The canonical Primary-owned invocation shape is below. Supply an absolute
repository root, its verified 40-character base, one allowlisted payload name,
and only that payload's documented arguments. The outer command is static host
code: complete materialization and blob verification must succeed before any
repository-selected Bash can run.

```bash
/usr/bin/env -i PATH=/usr/bin:/bin:/usr/sbin:/sbin /bin/bash --noprofile --norc -c '
  set -euo pipefail
  repo_root="$1"
  base_sha="$2"
  payload="$3"
  shift 3
  [[ "$repo_root" == /* && "$base_sha" =~ ^[a-f0-9]{40}$ ]]
  case "$(/usr/bin/uname -s)" in
    Darwin) private_parent=/private/tmp ;;
    Linux) private_parent=/tmp ;;
    *) exit 2 ;;
  esac
  umask 077
  bootstrap_root="$(/usr/bin/mktemp -d "$private_parent/forscherhaus-trusted-launcher.XXXXXX")"
  case "$bootstrap_root" in "$private_parent"/forscherhaus-trusted-launcher.*) ;; *) exit 2 ;; esac
  cleanup() { /bin/chmod -R u+w -- "$bootstrap_root" 2>/dev/null || true; /bin/rm -rf -- "$bootstrap_root"; }
  trap cleanup EXIT
  git_read() {
    /usr/bin/env -i GIT_ATTR_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_SYSTEM=/dev/null GIT_NO_LAZY_FETCH=1 GIT_NO_REPLACE_OBJECTS=1 GIT_OPTIONAL_LOCKS=0 GIT_PAGER=cat GIT_TERMINAL_PROMPT=0 LANG=C LC_ALL=C PATH=/usr/bin:/bin:/usr/sbin:/sbin /usr/bin/git -c core.hooksPath=/dev/null -C "$repo_root" "$@"
  }
  launcher_path="$bootstrap_root/trusted_base_launcher.sh"
  launcher_entry="$(git_read ls-tree "$base_sha" -- scripts/agent/trusted_base_launcher.sh)"
  read -r launcher_mode launcher_type launcher_blob launcher_tree_path <<< "$launcher_entry"
  [[ "$launcher_mode" == 100644 && "$launcher_type" == blob && "$launcher_blob" =~ ^[a-f0-9]{40}$ && "$launcher_tree_path" == scripts/agent/trusted_base_launcher.sh ]]
  git_read show "$base_sha:scripts/agent/trusted_base_launcher.sh" > "$launcher_path"
  [[ "$(git_read hash-object --no-filters "$launcher_path")" == "$launcher_blob" ]]
  /bin/chmod 0500 "$launcher_path"
  /usr/bin/env -i PATH=/usr/bin:/bin:/usr/sbin:/sbin TMPDIR=/tmp LANG=C LC_ALL=C TRUSTED_BASE_MATERIALIZED=1 TRUSTED_BASE_LAUNCHER_SOURCE_PATH="$launcher_path" /bin/bash --noprofile --norc "$launcher_path" --repo-root="$repo_root" --base-sha="$base_sha" --payload="$payload" -- "$@"
' trusted-base-launcher /absolute/repository <base-sha> <reviewer-or-parallel> <payload-options>
```

Both agent-harness entry points begin with the same exact-base system-Git
launcher, discard caller startup configuration, and use isolated
`/usr/bin/python3` before any PHP runs.
The launcher first materializes the fixed bootstrap-contract parser as an
exact regular blob from that base. Launcher and shared runtime invoke that same
parser at separate attestation points, so manifest, mode, payload, and runtime
bindings are checked twice. The runtime also retains a deliberately independent
structural cross-check; this small security-floor redundancy may not be removed
as ordinary cleanup.
`scripts/agent/verify_trusted_php_runtime.py` owns contract policy and CLI
dispatch; `scripts/agent/lib/trusted_runtime_primitives.py` owns the separately
testable file, archive, ELF, and dependency-closure mechanics. Together they
bind PHP and its dynamic dependency closure to the exact-base machine contract.
Every admitted platform requires an exact closure pin; a missing platform pin
or any pin drift fails closed and requires a reviewed contract update. A
fixed-host-path closure must also be entirely system-owned. Alternatively, a
platform may use one HTTPS archive whose URL, archive digest, sole member,
member digest, private extraction mode, static non-system closure, and aggregate
closure are all exact-base-bound. Both macOS runtimes use that bounded archive
path; they are downloaded without ambient proxy or credential state, verified
before extraction, and never executed from the archive. Platforms absent from
both runtime-source maps and the closure-pin map are deliberately unsupported.
Ambient `PATH` never grants interpreter authority. The centralized ownership
matcher is also Primary-owned because it defines shared lane and CI semantics.
Worker ownership and Primary reservations both use the same explicit
`path`/`match` objects; exact files are never inferred to be directories. PHP is
only the fail-closed process adapter for the one exact-base Python matcher and
contains no second normalization, match, or overlap implementation.

The exact-base JSON contract is the sole declarative configuration authority
for reviewer profiles, runtime pins, disabled features, and trusted paths. PHP
requires both the deterministic committed snapshot and the complete code-side
policy attestation to match that policy exactly. Run
`php scripts/agent/generate_reviewer_policy_snapshot.php` only for the snapshot
and `php scripts/agent/generate_reviewer_runtime_attestation.php` only for the
separate `GeneratedReviewerRuntimeAttestation.php` artifact containing every
top-level reviewer-policy key and its independent digest. Neither generator
rewrites runtime enforcement code. Each generator has a side-effect-free
`--check` mode and owns only its named artifact. Explicit disabled-feature
floors remain hand-enforced. Both generators and both generated artifacts are
trusted bootstrap paths, so changing either generation contract also requires
the external bootstrap-review path.

These repeated checks are distinct trust anchors, not competing policy
implementations. The launcher proves the exact-base materialization contract
before repository code runs; the shared runtime reattests that same contract
after dispatch; the generated snapshot makes the declarative policy reviewable;
and the complete PHP attestation keeps an independently changed JSON file from
redirecting enforcement. For the same reason, the canonical remote remains a
literal fail-closed transport/identity floor in each external-boundary payload
instead of being read only from mutable policy JSON. Changes to one of these
anchors must update its exact-base peers and tests together. Do not centralize
them into a head helper or one policy-derived lookup: a partial update is meant
to fail closed.

External review input is deliberately narrower than a checkout. It contains a
zero-context UTF-8 patch (changed lines only), the normalized changed-path
index, its deterministic manifest, and the allowlisted trusted base policy.
Full base/head file blobs and unchanged hunk context are never materialized or
serialized; unchanged hunk-section headings are stripped as well. Binary diffs
stop before any model request because they cannot be reviewed without
transmitting broader blob content. The serializer rejects every file outside
its exact manifest-derived allowlist. Tracked symbolic links and gitlinks are
rejected before bundle materialization because their target content is not part
of that exact text-only evidence boundary. `AGENTS.md`, `WORKFLOW.md`, and
`code_review.md` are trusted policy context, so changing any of them requires
the external bootstrap-review path.

Exactly one primary remains the external single writer for commits, pushes,
PRs, checks, Linear, workpads, attestations, merges, and production actions.
Workers may edit only their assigned local ownership. Shared contracts,
cross-lane integration files, and landing helpers remain primary-owned. Stop a
lane if it needs another lane's files, ownership becomes ambiguous, or a
semantic cross-lane dependency appears.

A merge invalidates the base of every remaining lane. Before any remaining
lane can publish, the primary synchronizes it with the newly verified
`origin/main`, resolves integration centrally, and reruns all validation and
exact-head review evidence invalidated by that synchronization.

## Linear States

Expected statuses:

- `Todo`
- `In Progress`
- `In Review`
- `Rework`
- `Ready to Merge`
- `Done`
- `Canceled`

Normal path:

`Todo` -> `In Progress` -> `In Review` -> `Ready to Merge` -> `Done`

Review change path:

`In Review` -> `Rework` -> `In Review`

Use the states as follows:

- `Todo`: ready to start, no implementation has begun yet.
- `In Progress`: active implementation and local validation.
- `In Review`: PR exists and is waiting on human review, CI completion, or
  explicit merge intent.
- `Rework`: active response to PR review feedback, CI failures, or requested
  follow-up on the same PR.
- `Ready to Merge`: final landing phase after the required reviews are
  finding-free and blocking CI is green on the same unchanged exact commit.
  Any new push returns the issue to `In Review`.
- `Done`: the merge commit and updated `origin/main` have been verified.

## Codex Workpad

For Linear-backed work, keep one persistent Linear comment whose body starts
with:

```md
## Codex Workpad
```

Rewrite it in place; do not create a new planning comment every run. Keep it
concise and structured:

```md
## Codex Workpad

### Status

- Summary: ...
- Next: ...

### Plan

- ...

### Validation

- Done: ...
- Pending: ...

### Blockers

- None.
```

Rules:

- Summarize evidence; do not paste long command output.
- Omit empty sections instead of filling them with placeholders.
- Do not put PR URLs into the workpad; keep PR linkage on the Linear issue and
  in GitHub.
- Update it when entering a new run, before opening or updating a PR, whenever
  the Linear state changes, and immediately after merge.

## Run Playbook

### 1. Resume and orient

- Read the issue, current state, branch, PR context, and existing workpad.
- If the current branch already exists, sync it before editing.
- Reproduce the problem or gather concrete evidence before changing code.
- For authority-, secret-, identity-, transaction-, or concurrency-sensitive
  writes, record the complete path before editing:
  `route -> request classification -> server-side authority -> locks and
transaction -> mutation -> post-commit effects`.
- Record the current understanding in the workpad when Linear is involved.
- Keep milestones tight, but once a local diff exists continue toward
  validation and commit rather than re-planning the same change.

### 2. Implement

- Keep scope aligned with the issue. Do not bundle unrelated cleanup.
- Make small, reviewable changes.
- Add or update regression tests when a stable test is practical.
- Rebuild compiled frontend artifacts when `assets/js` or `assets/css` changes
  require it.
- Do not end active implementation with only dirty workspace changes unless the
  task is explicitly paused or blocked.

### 3. Validate locally

Use the narrowest relevant validation early, then the stronger gate before the
PR is treated as ready. These evidence levels are deliberately distinct:

- focused tests and the quick hook provide early developer feedback
- the full local pre-PR gate establishes review readiness
- blocking GitHub CI plus final reviews on the unchanged exact PR head establish
  merge readiness

A successful quick hook or local full gate is never merge authorization by
itself.

Minimum expectation for merge-sensitive changes:

```bash
docker compose run --rm php-fpm composer test
```

Before publishing a PR as review-ready, run:

```bash
PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh
```

For the full gate matrix, optional scope-specific smokes, and rollback notes,
route through `docs/agent-harness-index.md` and then `AGENTS.md`.

### 4. Publish and park for review

When the branch is ready to publish:

- create or update the PR from the current branch
- fill the PR template completely
- attach the PR to the Linear issue when applicable
- move the Linear issue to `In Review`
- move it to `Ready to Merge` only after the required final reviews are
  finding-free, blocking CI is green on the same unchanged exact head, and the
  repository exact-head mergegate exits `0` for that PR and SHA
- update the workpad with validation status, merge/review posture, and what
  would reactivate the issue

Do not leave a reviewable PR in `In Progress`.

### 5. Rework loop

When the issue is moved to `Rework`:

- inspect open PR comments, reviewer findings, and failing CI
- update the workpad with the current rework plan
- fix the required issues
- commit and push the changes
- return the issue to `In Review` when waiting on reviewers again
- move it directly to `Ready to Merge` only when the PR is truly in the
  landing phase

Every push after review or CI evidence was collected invalidates that landing
evidence. Return the issue to `In Review`, rerun the required exact-head CI and
reviews, and only then restore `Ready to Merge`.

Treat both human findings and Codex-review findings as real review work until
they are explicitly addressed or rejected with a clear rationale.

## PR and Review Expectations

Every PR must cover at least two independent review lenses:

- Reviewer A: bugs, regressions, security, edge cases
- Reviewer B: architecture, readability, maintainability

Authority-, secret-, identity-, transaction-, and concurrency-sensitive
changes require a third independent lens:

- Reviewer C: tests, regression coverage, and flake risk

Final reviews and blocking CI must all target the current unchanged exact PR
head. A later push makes the earlier evidence stale.

Run final reviewers through the repository-owned sealed-bundle boundary using
the exact-base launcher contract in `scripts/agent/trusted_base_launcher.sh`;
never execute the checked-out launcher or reviewer payload. Fixed system Git
must completely materialize and verify the launcher from the verified base
before clean Bash starts it; only then may it privately materialize the fixed
`scripts/agent/lib/trusted_base_bootstrap_contract.py` parser, validate the
manifest, and materialize `scripts/agent/lib/trusted_base_payload_runtime.sh` and
`scripts/agent/run_readonly_reviewer.sh`.
The shared runtime, runner, policy, profiles, schema,
and validator are trusted base artifacts, never head artifacts. It requires
the live canonical main, local tracking ref, exact merge base, and reviewed
head to match; later pushes invalidate all review evidence.

The harness enforces the deterministic sealed bundle, exact Base/Head binding,
macOS Seatbelt default-deny isolation, an attested PHP runtime, and a private
Codex copy whose exact binary and system-only dynamic dependency closure are
pinned before its first execution. The sandbox has no broad Homebrew-library
allowance. It also enforces disabled reviewer tools,
and privacy-safe fail-closed output. It exposes no worktree or `.git`, user
configuration, connectors, delegation, credentials, or external writes;
non-macOS execution fails closed. The machine contract is the source for model,
feature, schema, runtime, and trusted-path settings; the runner orchestrates
separately materialized exact-base bundle and isolated-runtime libraries.
Commit-derived evidence is rendered through an empty-template private Gitdir.
Its index is first bound to the verified review base so `check-attr --cached`
can reject paths marked binary by trusted-base attributes. Raw blobs from both
commits must also be bounded UTF-8 without NUL bytes before the index advances
to the verified head and zero-context numstat and patch evidence is rendered.
Independent numeric-numstat validation rejects any remaining binary
classification before model input.
Head-side attribute changes remain untrusted diff content and cannot
reclassify or conceal binary evidence before rejection. Source-worktree Git
configuration, `.git/info/attributes`, and host Git templates cannot influence
changed paths, attribute evidence, numstat, or patch bytes.
The pinned CLI exposes `--ignore-user-config`, `--ignore-rules`, and
`--strict-config` on `exec` but rejects them on its `debug` preflights. Those
preflights therefore use `env -i`, a newly created non-writable synthetic
`HOME`/`CODEX_HOME` containing no config or rules, a sealed working directory,
and the same Seatbelt boundary; the final `exec` requires all three flags.
The version-pinned model-catalog adapter drops unknown catalog additions
without failing, but rejects missing or type-drifted fields needed by that
exact CLI ABI. Capability-bearing fields are always reconstructed to the
disabled reviewer surface; the required web-search representation enum is
pinned to its smallest text-only form while search support stays disabled.
The machine contract also owns the exact recursive Git pathspec that sends any
nested `AGENTS.md` change back through bootstrap review; the shell runner does
not add an implicit policy glob.
Bundle construction, model/prompt policy, and output validation remain separate
modules. Structural output rules come from the exact-base JSON schema; exact
Base/Head/lens/path binding and privacy are additional semantic checks.
The JSON machine contract is the only hand-edited reviewer-policy authority.
Generated PHP policy and runtime-attestation files are deterministic
change-control projections refreshed by their repository generators, not
additional policy sources. Exact equality is intentional: a runtime-boundary
change must be explicit and generator-checked. Likewise, the parallel-work
validator materializes and verifies the single `validator_bootstrap_paths`
list declared by that contract instead of maintaining a validator-side path
copy.
Consult `.codex/contracts/agent-workflow.json`,
`scripts/agent/trusted_base_launcher.sh`, and the reviewer payload for those
implementation details.

The first introduction of this trust root cannot bootstrap itself. Likewise,
a change to `.codex/config.toml`, any `AGENTS.md`, or any reviewer bootstrap,
role, schema, isolation, runtime, or policy-context path declared by the exact
base contract can affect future review authority. Those changes fail closed and
need a separately enforced external read-only bootstrap review authorized and
run by the primary. The contract owns those path lists; shell and tests consume
them instead of maintaining additional allowlist copies. The isolated model call
uses both the outer Seatbelt boundary and Codex `read-only` sandboxing with
approval mode `never`. A bootstrap review is review evidence only; it grants no
mutation, publication, Linear, or landing authority.

For an executable bootstrap/isolation check without a model request, the
Primary may invoke the reviewer payload through the same exact-base launcher
with `--diagnostic-bootstrap-only` and without `--codex-bin`. On macOS this runs
the real exact-base materialization, PHP attestation, and Seatbelt allow/deny
canaries. It writes only inside private system-temporary roots, never the user
home, and returns `review_evidence: false`; it can diagnose the harness but can
never satisfy a final-review or landing requirement.

After the final reviews are finding-free, record their canonical,
privacy-safe exact-head attestation on the PR and run the repository-owned
read-only verifier:

```bash
composer check:exact-head-mergegate -- --pr=<number-or-canonical-url> --reviewed-sha=<40-character-sha>
```

The verifier uses GitHub REST GET requests plus bounded, read-only GraphQL
queries. It must run from the exact reviewed `HEAD`, loads its policy from that
committed tree, and rejects local changes to the contract or mergegate
implementation. Its workflow parser runs isolated and accepts only the YAML
runtime file manifest and digest pinned by that reviewed policy.
It observes all normalized CI and review evidence twice. It reads PR identity
before, between, and after those bounded observations. All three PR reads and
both complete evidence observations must remain equal. It requires the open
non-draft PR, clean mergeability, the canonical successful CI run and every
blocking check to bind to that PR and SHA. Always-on checks must succeed;
diff-conditional checks must be either successful or explicitly skipped. It
also requires the three distinct review lenses from the machine contract in
one new, unedited, SHA-bound owner attestation with exact review-activity
watermarks and a privacy-safe review payload digest. Batched GraphQL edit
counts bind the attestation's unedited state plus each trusted formal review
and inline review comment, while only body digests enter the watermark. A still-active trusted
`CHANGES_REQUESTED` review, trusted watermark or payload drift, edited trusted
inline feedback, newer trusted review feedback, or a newer invalid attestation
marker invalidates that evidence. Missing, pending, duplicated, malformed,
stale, or wrong-suite evidence fails closed. The report contains no raw comment
body, reviewer identity, token, capability, or personal data.
Untrusted review activity neither grants authority nor vetoes landing. See
`docs/exact-head-mergegate.md`.

An exit `0` is required before `Ready to Merge`, but it does not perform the
merge. Use the compare-and-swap merge command from
`.codex/contracts/agent-workflow.json` on the same still-current SHA.

The PR is not done until:

- required blocking CI is green
- no open review findings remain
- the PR is mergeable
- the read-only exact-head mergegate passes on the current reviewed SHA
- required docs or migration notes are included
- the reviewed head, CI head, and current PR head are identical
- the issue is moved to `Done`

## Stop and Escalate

Stop and ask for human input when:

- product or legal requirements are unclear
- the task touches privacy-sensitive behavior without prior approval
- unexpected user changes appear in the same files you must edit
- a change requires edits under `system/`
- a DB change cannot be expressed with a safe migration and rollback
- a blocking CI gate would need to be relaxed
- a review comment conflicts with the user's stated intent and the correct
  answer is not inferable from code, tests, or nearby docs

If a blocking gate must temporarily become advisory because of false positives,
create a follow-up issue with a return-to-blocking deadline of at most 14 days.
