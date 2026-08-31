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
runner rejects a source path inside the worktree before inspecting the head.
The base runner first extracts the structured contract and its bootstrap
validator, derives and validates the complete trust-path set from that single
contract, and then extracts the selected role, output schema, and review
instructions from the same base commit.
Runtime model and reasoning values live in the structured contract; the role
TOML contains only the human-readable review instructions. It starts a fresh
ephemeral review without user config,
exec-policy rules, external connectors, web search, or ambient PHP configuration
for its trusted contract and output validator. Git and PHP resolve through a
fixed system path, while only the primary may pass a trusted absolute Codex
binary path; either path must identify as the Codex CLI by basename and version
output. Pre-trust Git probes ignore ambient Git environment, global and system
configuration, hooks, fsmonitor, replacement objects, lazy object fetching,
external diff drivers, and text conversion. The runner materializes a private, clean, detached
exact-commit checkout before the model starts, denies reviewer file/Git/network mutation,
derives model settings from the selected reviewer profile, and returns one
lens-, base-, and exact-head-bound JSON result only to the primary. Invalid or
protocol-event output fails closed. Reviewers must not
delegate or publish comments, reviews, PR changes, check reruns, merges, Linear
changes, or workpad updates. The primary remains the only external writer and
landing owner.

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
