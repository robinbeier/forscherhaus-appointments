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

Before opening writer lanes, the primary records a small JSON manifest and runs

```bash
php scripts/agent/check_parallel_work_contract.php --manifest=<lane-manifest.json>
```

The checker reads both the workflow contract and the ownership map from the
manifest's declared base commit. Mutable files in the current checkout cannot
relax the policy used to approve a lane.

The manifest names one full lowercase common base SHA, the primary ID, exact
`primary_approved_component_ids` for any intersected `single-owner` or
`manual_approval_required` entries in `docs/maps/component_ownership_map.json`,
and a `semantic_independence` object with empty `shared_contracts` and
`cross_lane_dependencies` arrays plus `coordination_required: false`. The
checker cannot infer semantic coupling from paths; this explicit primary
attestation makes any known shared contract, cross-lane dependency, or required
coordination a fail-closed reason to keep the work serial. The manifest has no
more than two `implementation_worker` lanes. Every lane repeats that
base SHA, lists normalized repository-relative ownership rules, and
declares an empty `external_mutations` list. Ownership must be disjoint and may
not include the primary-owned harness, reviewer, workflow, or landing paths in
the machine contract. The checker, ownership validator, and reviewer trust
manifest all use `scripts/agent/lib/RepoPath.php` as their single normalized
repository-path grammar. Every ownership rule is an object with `path` and an
explicit `match` value: `exact_or_descendants` or `filename_stem`. Canonical
filename-stem exceptions are likewise explicit in
`docs/maps/component_ownership_map.json#prefix_match_overrides`; spelling alone
never changes matching semantics. Each lane uses its own worktree and branch
from the already verified common base.

Exactly one primary remains the external single writer for commits, pushes,
PRs, checks, Linear, workpads, attestations, merges, and production actions.
Workers may edit only their assigned local ownership. Shared contracts,
cross-lane integration files, and landing helpers remain primary-owned. Stop a
lane if it needs another lane's files, its ownership becomes ambiguous, or a
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

Run final reviewers through the repository-owned external read-only boundary.
The executable, policy, profiles, schema, and validator must come from the
already trusted review base, never from the head being reviewed. Materialize
the base copy of `scripts/agent/run_readonly_reviewer.sh` in a private temporary
file, then invoke that copy with the checked-out worktree as `--repo-root`.
The checked-out runner is contract data, not an executable entry point; it
refuses to run while its own source path is inside the worktree:

```bash
trusted_runner=$(mktemp "${TMPDIR:-/tmp}/readonly-reviewer-base.XXXXXX")
git show <base-sha>:scripts/agent/run_readonly_reviewer.sh > "$trusted_runner"
chmod 700 "$trusted_runner"
"$trusted_runner" --repo-root="$(git rev-parse --show-toplevel)" --codex-bin="$(command -v codex)" --lens=<lens> --base-sha=<base-sha> --head-sha=<head-sha>
rm -f "$trusted_runner"
```

The runner starts an ephemeral session without user configuration, user or
project exec-policy rules, external connectors, or web search. It uses a
read-only sandbox with network denied for reviewer commands and never permits
approval escalation. Git and PHP resolve only through a fixed system tool path;
Codex does too unless the primary supplies its trusted executable as an absolute
`--codex-bin` path. The runner fetches the named commits into a private temporary
repository and gives the reviewer only that clean, detached exact-head checkout,
so changes to the source worktree after preflight cannot alter reviewed content.
Its trusted PHP contract and output
validator run without ambient `php.ini` files, `PHPRC`, `PHP_INI_SCAN_DIR`, or
prepend/append hooks. The machine contract selects the role;
the runner reads its one canonical trust-path manifest from the base commit,
extracts the listed contract, reviewer profiles, schema, validator, and review
instructions, derives model and reasoning settings from the structured machine
contract, and
fail-closed validates the single JSON review object against the requested lens,
base SHA, and exact head. Reviewer output returns to the primary; reviewers do not write
files, Git, GitHub, Linear, checks, reviews, comments, or workpads and do not
delegate. The primary alone decides how findings are integrated or published.

The first introduction of this trust root cannot bootstrap itself. Likewise,
a change to `.codex/config.toml` or any `AGENTS.md` can affect reviewer runtime
instructions before repository code runs. Those changes fail closed and need a
separately enforced external read-only bootstrap review authorized and run by
the primary. A bootstrap review is review evidence only; it grants no mutation,
publication, Linear, or landing authority.

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
