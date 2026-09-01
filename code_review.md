# Code Review Guide

Purpose: durable review guidance for `/review` and normal Codex turns in this repo.
Keep this file focused on review behavior, not on general setup or CI command matrices.
Machine-checked review invariants live in
[`.codex/contracts/agent-workflow.json`](.codex/contracts/agent-workflow.json).

## Review Priorities

Review for real engineering risk in this order:

1. Correctness, regressions, and broken behavior
2. Security and unsafe trust boundaries
3. Data integrity, migrations, and rollback safety
4. Test gaps, weak assertions, and flaky validation
5. Maintainability, readability, and architectural fit

Prefer high-signal findings over broad commentary or style feedback.

## Scope Guardrails

Keep review effort proportional to the change:

- Small scoped UI, product, or doc diffs should stay small unless the diff is
  demonstrably unsafe.
- Treat unrelated infrastructure hardening, test-harness upgrades, release-gate
  cleanup, or broad refactors as follow-up material, not blocking review
  findings, unless they are required to make the current diff safe.
- When a concern belongs to a different subsystem or problem class than the
  requested change, call that out explicitly as follow-up scope instead of
  silently broadening the PR.
- If a finding depends on a chain of speculative improvements rather than a
  concrete failure mode in the diff, do not block on it.

## Findings Bar

Report findings only when they materially increase:

- production risk
- regression risk
- maintenance cost
- ambiguity around behavior or ownership

Do not leave style-only comments unless they hide a real bug, misunderstanding, or future defect risk.
If no substantive issues are found, say explicitly: `no findings`.

## Findings Format

Each finding should be concrete and actionable:

- lead with the issue, not with praise or summary
- cite the file, symbol, or execution path
- explain impact and triggering conditions
- separate confirmed facts from inference
- suggest reproduction steps or validation gaps when possible

Keep summaries brief and secondary to findings.

## Repo-Specific Checks

Always check the diff against these repo rules:

- Production code belongs in `application/`.
- Treat edits in `system/` as exceptional and acceptable only for an explicit upstream patch.
- Database schema changes must use CodeIgniter migrations and should preserve rollback safety.
- Treat `services.attendants_number` as fixed to `1` unless scope explicitly changes.
- If ownership metadata marks a component as `single-owner` or `manual_approval_required`, prefer narrow diffs and flag risky spread.
- Prefer small, mergeable, low-risk changes over broad rewrites.

## Validation Expectations

Assess whether the executed validation actually proves the change is safe:

- For bug fixes, prefer an appropriate regression test when feasible.
- Check that the narrowest relevant tests were run.
- For review-ready changes, expect the full pre-PR gate unless the change is clearly not at that stage yet.
- Flag missing negative-path or edge-case coverage when the change affects them.
- Flag weak assertions that would let the bug survive.

Do not ask for broad new test suites unless the risk justifies them.

## Review Process

When reviewing, first understand the real execution path and changed invariants.
Then review the diff through these lenses:

1. correctness and regression risk
2. security and unsafe assumptions
3. validation adequacy
4. maintainability and architectural fit

Use the reviewer roles with this split:

- `reviewer_correctness` is the deep reviewer for correctness, regressions, and security-sensitive risk.
- `pr_explorer`, `reviewer_tests`, and `reviewer_design` are bounded support reviewers that should return distilled evidence for the parent reviewer to synthesize.
- An `implementation_worker` must never be the sole reviewer of its own diff; preserve an independent reviewer role and primary-agent synthesis.

Authority-, secret-, identity-, transaction-, and concurrency-sensitive diffs
require three independent final reviews on the same unchanged exact head:

- `reviewer_correctness` for correctness and security
- `reviewer_design` for architecture and maintainability
- `reviewer_tests` for regression coverage and flake risk

Invoke each final lens with the exact
`scripts/agent/run_readonly_reviewer.sh` blob from the already trusted review
base, materialized outside the worktree as described in `WORKFLOW.md`; never
execute the checked-out copy or use the head copy as its own trust anchor. The
runner resolves `refs/heads/main` through unauthenticated read-only
`git ls-remote` against the fixed public canonical repository URL in an empty
environment without credentials, proxies, endpoint overrides, helpers, or
ambient Git configuration. The local `refs/remotes/origin/main` must match that
live SHA, and the base must be its exact merge base with the reviewed head; a
stale or rewritten tracking ref and a caller-selected narrower ancestor both
fail closed. The
initial extraction itself uses the absolute system Git binary in an empty
environment with replacement objects, lazy fetching, global/system config,
hooks, fsmonitor, helpers, and external diffs disabled; an ambient `git show`
is not an acceptable trust bootstrap. The
runner rejects a source path inside the worktree before inspecting the head.
The base runner first extracts the structured contract and its bootstrap
validator, derives and validates the complete trust-path set from that single
contract, and then extracts the selected role, output schema, and review
instructions from the same base commit.
The entrypoint retains only bootstrap, Git-object materialization, and process
orchestration. Deterministic path, manifest, serialization, developer-
instruction construction, and model-
catalog behavior lives in the separately unit-tested
`scripts/agent/lib/ReadonlyReviewBundle.php`; the Seatbelt rules live in
`scripts/agent/readonly-reviewer.sb`. Both are exact base blobs in the same
trusted-path manifest, so this split reduces maintenance coupling without
widening the trust root.
Runtime model and reasoning values live in the structured contract; the role
TOML contains only the human-readable review instructions. It starts a fresh
ephemeral review without user config,
exec-policy rules, external connectors, web search, or ambient PHP configuration
for either its bootstrap or its trusted contract and output validator. The
bootstrap passes an explicit environment allowlist into Bash, excluding
`BASH_ENV`, exported functions, shell options, and unrelated ambient variables.
Caller-provided `HOME` and `CODEX_HOME` are ignored. The runner derives the
canonical OS account and creates a private random review root below
`/private/tmp`; the path contains no account name and is removed after the call.
Git and PHP resolve through a fixed system path. The primary passes an absolute
Codex launcher only to locate a source binary; the runner never executes that
source. It requires safe ownership and permissions, copies the source into the
private sealed root, makes the copy non-writable, and verifies its SHA-256
against the platform-specific official 0.145.0 release digest in the trusted
base contract before the first execution. It then validates a bounded
`codex-cli 0.145.0` version output. The contract also records the matching
official release-archive digests for provenance. A CLI upgrade requires a
reviewed version and digest update because its tool and sandbox semantics are
part of the isolation boundary. Pre-trust
Git probes ignore ambient Git environment, global and system
configuration, hooks, fsmonitor, replacement objects, lazy object fetching,
external diff drivers, and text conversion. The runner rejects every tracked
symlink in the exact base and head trees, then materializes a deterministic
bundle containing the full-index patch, sorted changed paths, committed base and
head blobs, trusted base policy, and a SHA-256 manifest. It serializes that
bounded bundle into deterministic JSON. For a newly added UTF-8 text file, the
exact head metadata remains in the manifest while its redundant `head/` blob
is omitted only after its exact bytes match a complete textual new-file hunk in
the binary/full-index `review.patch`. Binary patch forms, unsupported path or
hunk forms, content mismatches, NUL-containing additions, and non-UTF-8
additions retain the independently hashed head blob.
This optional metadata is schema-compatible
(`content_source.kind: full_index_patch_added_text_file` points to
`review.patch`) and the deduplication operation fails closed on any evidence
mismatch. The trusted role and exact Base/Head
binding are supplied as developer instructions; only the serialized bundle is
sent as the untrusted user message over standard input. Patch content can
therefore never occupy the reviewer policy's instruction priority. Before the
model call, the pinned CLI's own prompt renderer must prove the developer/user
role split with a synthetic non-sensitive probe. The model
receives no `.git` directory, original-worktree path, or filesystem review tool.

On macOS the outer Codex process runs in a repository-owned Seatbelt profile
with `deny default`. Only the system runtime, read-only Codex system policy, the
exact private review root, and the canonical host `auth.json` are readable. The
real `CODEX_HOME` is not exposed: a non-writable temporary runtime home holds a
read-only auth link, a synthetic installation ID, and explicitly writable
scratch subtrees that are removed afterward. Direct canaries must prove that
the bundle is readable while a foreign temp path, an account-home sibling, and
the original worktree are denied. Non-macOS execution fails closed.
The harness cannot refresh the host login; expiry is an external prerequisite,
not a reason to widen reviewer write access.

The pinned CLI's bundled model catalog is reduced to one text-only model with
shell, unified execution, patch, image, search, experimental, connector,
delegation, and workspace-dependency tools removed. The host login therefore
authenticates only the outer model-service request and is not a reviewer
capability; no credential content enters the bundle or prompt. Do not mix this
boundary with legacy `sandbox_mode`, `--sandbox`, or permission-profile
configuration. The runner denies reviewer file/Git/external mutation,
derives model settings from the selected reviewer profile, and returns one
lens-, base-, and exact-head-bound JSON result only to the primary. Finding
prose is length-bounded and rejected when it contains credential-, capability-,
contact-, user-home-, URL-, or long hash-like values. Invalid, privacy-unsafe,
or protocol-event output fails closed. Reviewers must not
delegate or publish comments, reviews, PR changes, check reruns, merges, Linear
changes, or workpad updates. The primary remains the only external writer and
landing owner.

The host Codex login authenticates only the explicitly authorized model-service
call; it grants no reviewer connector authority. User configuration and rules
are ignored, connector-capable features are disabled, MCP is empty, command
environment inheritance is disabled, and reviewer instructions forbid reading
or reproducing runtime authentication state.

The initial trust-root introduction and changes to runtime-loaded
`.codex/config.toml` or `AGENTS.md` require a separately enforced external
read-only bootstrap review. The repository runner refuses those cases instead
of allowing a head to review its own authority boundary.

Any later push invalidates those final reviews and requires exact-head review
again.

The read-only exact-head mergegate does not replace reviewer judgment. After
the three final reviews are finding-free, the primary agent records one
new, unedited, owner-authored, privacy-safe attestation for their unchanged
head as described in `docs/exact-head-mergegate.md`. The gate checks that
attestation, exact review-activity watermarks plus a privacy-safe formal-review
payload digest, blocking CI, mergeability, and two identical bounded CI-and-
review evidence observations. PR identity is observed before, between, and
after those observations; all three reads must remain equal. The gate must run
from the exact reviewed `HEAD` with its contract and implementation unchanged.
A still-active `CHANGES_REQUESTED` review, watermark or payload drift, edited
inline feedback, newer trusted review feedback, or a newer invalid attestation
marker invalidates the attestation; close or resolve the finding and publish a
fresh attestation comment before rerunning the gate.

The attestation is an accountable owner assertion, not cryptographic proof of
agent execution. The repository-local gate is designed to prevent accidental
or stale landing evidence; a malicious repository owner is outside its threat
model because that owner can already bypass the local process and merge
directly. Recording reviews that did not run remains a process violation.

The mergegate's lens source is explicitly
`review.sensitive_change_lenses` in
`.codex/contracts/agent-workflow.json`, so review taxonomy changes are
contract changes and cannot silently drift from the landing policy.

Default reviewer depth should match the change:

- For small scoped product/UI changes, start with `pr_explorer` plus `reviewer_correctness`.
- Add `reviewer_tests` only when validation adequacy is genuinely uncertain for the changed behavior.
- Add `reviewer_design` only when the diff materially affects long-lived seams, architecture, or reuse boundaries.
- Apply the mandatory three-lens rule above instead of these defaults for
  security-sensitive write and authority changes.
- Use `docs_researcher` only when framework, library, platform, or external API assumptions matter.

When a change depends on framework, library, or external API behavior, verify the assumption against primary documentation instead of guessing.

## Out of Scope

Avoid comments that do not materially improve confidence in the change, including:

- personal style preferences
- speculative refactors unrelated to the request
- renaming-only suggestions without safety impact
- complaints about missing cleanup outside the touched area
