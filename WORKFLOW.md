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

Before executing any wrapper extracted from a commit, materialize it with the
following host bootstrap. This is the trust boundary: it uses the absolute
system Git binary in an empty environment, disables replacement objects, lazy
fetching, user/system configuration, hooks, fsmonitor, external diff helpers,
and interactive credential helpers. Do not replace it with an ambient `git
show` command.

```bash
materialize_trusted_blob() {
    local checkout="$1" commit_sha="$2" repo_path="$3" destination="$4"
    [[ "$checkout" = /* && "$destination" = /private/tmp/* ]]
    [[ "$commit_sha" =~ ^[a-f0-9]{40}$ ]]
    [[ "$repo_path" != /* && "$repo_path" != *..* && "$repo_path" != *\\* ]]
    /usr/bin/env -i \
        PATH=/usr/bin:/bin \
        TMPDIR=/private/tmp \
        GIT_ATTR_NOSYSTEM=1 \
        GIT_CONFIG_GLOBAL=/dev/null \
        GIT_CONFIG_NOSYSTEM=1 \
        GIT_NO_LAZY_FETCH=1 \
        GIT_NO_REPLACE_OBJECTS=1 \
        GIT_OPTIONAL_LOCKS=0 \
        GIT_PAGER=cat \
        GIT_TERMINAL_PROMPT=0 \
        /usr/bin/git --no-replace-objects \
        -c core.fsmonitor=false \
        -c core.hooksPath=/dev/null \
        -c core.untrackedCache=false \
        -c diff.external= \
        -c pager.diff=false \
        -C "$checkout" show "${commit_sha}:${repo_path}" > "$destination"
}
```

Before opening writer lanes, the primary records a small JSON manifest outside
the validator checkout. From a separate clean primary checkout detached at the
already verified common base, it materializes the validator wrapper from that
base outside the checkout and runs

```bash
trusted_validator=$(mktemp "/private/tmp/parallel-work-validator-base.XXXXXX")
materialize_trusted_blob <absolute-validator-checkout> <base-sha> scripts/agent/check_parallel_work_contract.sh "$trusted_validator"
chmod 700 "$trusted_validator"
"$trusted_validator" --validator-checkout=<absolute-validator-checkout> --manifest=<lane-manifest.json>
rm -f "$trusted_validator"
```

The materialized wrapper enters through a `php -n` bootstrap before Bash can
process caller startup state. That bootstrap passes only fixed `PATH`, `TMPDIR`,
`LANG`, and `LC_ALL` values into the Bash payload, excluding `BASH_ENV`,
exported functions, shell options, `HOME`, `CODEX_HOME`, and other ambient
variables. The wrapper then reads the manifest base with a fixed PHP runtime,
rejects any checkout-HEAD mismatch before executing validator source, verifies
that it is the checkout's exact base blob, then
materializes the CLI and both shared validator libraries directly from that
same commit into a private trust bundle. The checker therefore executes no PHP
source from the checkout and starts PHP without ambient `php.ini`, `PHPRC`, scan
directories, or prepend/append hooks. It requires the validator checkout to be
clean and its HEAD to equal the manifest's declared base, then reads both the
workflow contract and the ownership map from that exact commit. Checkout-time
filters, symlink substitutions, assume-unchanged state, a caller-controlled
SHA, or a mutable validator checkout cannot relax the policy used to approve a
lane.

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
explicit `match` value: `directory` or `exact_file`. `directory` covers
descendants only and `exact_file` covers one file. Canonical ownership maps
use the same explicit object grammar; trailing slashes, underscores, and other
filename spelling never create implicit ownership. Maps list each file
explicitly when a component spans sibling files. Each lane uses its own worktree and branch
from the already verified common base.

Exactly one primary remains the external single writer for commits, pushes,
PRs, checks, Linear, workpads, attestations, merges, and production actions.
Workers may edit only their assigned local ownership. Shared contracts,
cross-lane integration files, and landing helpers remain primary-owned. Stop a
lane if it needs another lane's files, its ownership becomes ambiguous, or a
semantic cross-lane dependency appears.

The manifest pass is admission, not completion evidence. After every worker
return and again immediately before the primary commits or integrates a lane,
materialize a fresh wrapper from the same separate clean primary checkout whose
validator files match the lane's declared base, then run:

```bash
trusted_validator=$(mktemp "/private/tmp/parallel-work-validator-base.XXXXXX")
materialize_trusted_blob <absolute-validator-checkout> <base-sha> scripts/agent/check_parallel_work_contract.sh "$trusted_validator"
chmod 700 "$trusted_validator"
"$trusted_validator" --validator-checkout=<absolute-validator-checkout> --manifest=<lane-manifest.json> --repo-root=<absolute-lane-worktree> --verify-lane=<lane-id> --allow-dirty-precommit
rm -f "$trusted_validator"
```

Verification requires an explicit evidence mode and fails closed unless the validator wrapper, CLI, shared path
grammar, and contract implementation match their declared-base blobs. It then
checks that the lane HEAD descends from the base and compares all committed,
staged, unstaged, and non-ignored untracked paths with the lane's declared ownership. An
ownership violation, base drift, invalid path, or in-lane validator invocation
blocks integration. `--allow-dirty-precommit` returns only
`status: provisional_pass` and can never be integration evidence. After the
primary creates the lane commit, it reruns the same command with
`--require-clean`; only `status: pass` with `integration_ready: true` is
integration evidence because it binds the declared base, immutable lane HEAD,
clean local state, and a changed-path digest. Omitting both evidence modes is a
usage error. The primary records both results in the
existing workpad; the worker never runs or publishes this authority check.

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
trusted_runner=$(mktemp "/private/tmp/readonly-reviewer-base.XXXXXX")
materialize_trusted_blob <absolute-worktree> <base-sha> scripts/agent/run_readonly_reviewer.sh "$trusted_runner"
chmod 700 "$trusted_runner"
"$trusted_runner" --repo-root=<absolute-worktree> --codex-bin=<absolute-codex-launcher> --lens=<lens> --base-sha=<base-sha> --head-sha=<head-sha>
rm -f "$trusted_runner"
```

Before materialization, fetch `origin/main`. The supplied base SHA must equal
the merge base of the exact head and the canonical local
`refs/remotes/origin/main` tracking ref; an arbitrary older or newer ancestor
is rejected. This preserves the complete branch diff even when `origin/main`
has advanced and prevents a caller from narrowing review scope to a later
branch commit.

The runner first enters through a PHP `-n` bootstrap and passes only an explicit
allowlist of runtime variables into its Bash body, so `BASH_ENV`, exported shell
functions, shell options, and unrelated ambient variables cannot execute before
the trust checks. It resolves the effective account through the OS account
database and ignores caller-supplied `HOME` and `CODEX_HOME`. A private random
review root below `/private/tmp` holds only generated review evidence and
short-lived runtime state; its absolute path contains no account name. It then
starts an ephemeral session without user
configuration, user or project exec-policy rules, external connectors, or web
search and never permits approval escalation. Git and PHP resolve only through a
fixed system tool path.
The primary must supply Codex as an executable absolute `--codex-bin` path; the
runner resolves its canonical target, rejects repository-owned targets even
through symlinks, and requires safe source ownership and permissions. It copies
that target into the private review root, removes write permission, verifies the
copy against the platform-specific official-release binary SHA-256 from the
trusted base contract, and only then executes it to require Codex CLI 0.145.0
exactly, allowing only bounded build metadata. The same contract records the
matching official release-archive SHA-256 for provenance. The exact pins are
intentional: a Codex upgrade can change the available tool surface or sandbox
behavior and therefore requires a reviewed version-and-digest contract update.
Repository-root and runner paths are likewise
resolved to their canonical physical targets before trust-boundary comparisons.
The host Codex login authenticates only the model-service call and does not grant
reviewer connector authority: user configuration and rules are ignored,
connector-capable features are disabled, MCP is empty, command environment
inheritance is disabled, ambient OpenAI or Codex API-key overrides are removed,
and the reviewer may not inspect runtime authentication state.
Every pre-trust Git command ignores ambient Git environment,
global and system configuration, hooks, fsmonitor, replacement objects, lazy
object fetching, external diff drivers, and text conversion. The runner rejects
tracked symlinks in both exact commit trees and materializes only a deterministic
review bundle from immutable Git objects: the binary full-index patch, sorted
changed-path list, per-path base and head blobs, trusted base policy, and a
timestamp-free manifest binding every readable artifact by SHA-256, byte count,
base SHA, head SHA, and lens. It exposes neither a `.git` directory nor the
original worktree to the model. Changes to the source worktree after preflight
cannot alter reviewed content.

The runner places the trusted base role, exact Base/Head binding, and review
rules in Codex developer instructions. It serializes the bounded bundle into
deterministic JSON and supplies that serialization alone as the untrusted user
message over standard input. Committed patch, path, text, JSON, and binary data
therefore cannot share instruction priority with the reviewer policy. The model
call starts only after the pinned CLI's prompt renderer proves that the trusted
policy appears in a `developer` message and a synthetic bundle probe appears in
a separate `user` message. The model receives no filesystem path as its source
of review context. On macOS the Codex
process itself runs inside a repository-owned
Seatbelt profile that starts with `deny default`. It permits only the system
runtime, read-only Codex system policy, the exact private review root, and the
canonical host `auth.json` file. The actual `CODEX_HOME` is never exposed: a
non-writable temporary runtime home contains only a read-only link to that exact
login file, a synthetic installation ID, and two explicitly writable scratch
subtrees that are deleted after the call. The host login authenticates only the
outer model-service request; its contents are never copied into the bundle or
prompt.
The reviewer cannot refresh or rewrite that login; an expired login fails closed
and must be refreshed outside the reviewer harness before a later attempt.

Before the call, the same Seatbelt profile must read an in-bundle canary and
must reject canaries in a foreign temp directory, the account home, and the
original worktree. Any deviation fails closed. The runner derives a one-model
catalog from the pinned CLI and removes shell, unified execution, patch, image,
search, experimental, connector, delegation, and workspace-dependency tool
surfaces. The model therefore cannot turn the outer broker's network or exact
login-file access into host, GitHub, or Linear access. Non-macOS execution fails
closed until an equivalent repository-enforced boundary exists. Do not combine
this contract with legacy `sandbox_mode`, `--sandbox`, or permission-profile
overrides.

Its trusted PHP contract and output
validator run without ambient `php.ini` files, `PHPRC`, `PHP_INI_SCAN_DIR`, or
prepend/append hooks. The machine contract selects the role;
the runner reads its one canonical trust-path manifest from the base commit,
extracts the listed contract, reviewer profiles, schema, validator, and review
instructions, derives model and reasoning settings from the structured machine
contract, and
fail-closed validates the single JSON review object against the requested lens,
base SHA, exact head, normalized repository-relative files changed by that
exact diff, and bounded privacy-safe finding prose. Credential-, capability-,
contact-, user-home-, URL-, and long hash-like values are rejected. Reviewer
output returns to the primary; reviewers do not write
files, Git, GitHub, Linear, checks, reviews, comments, or workpads and do not
delegate. The primary alone decides how findings are integrated or published.
Model, reasoning, feature-disable, runtime-pin, and trusted-path changes start in
`.codex/contracts/agent-workflow.json`. The trusted helper enforces the
fail-closed safety invariants and the focused contract tests intentionally fail
on drift; update those validation expectations in the same reviewed change.

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
