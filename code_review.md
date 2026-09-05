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

The default is one independent reviewer covering all four topics above.
Use a read-only reviewer available in the current environment or a human who
did not implement the change. Provide the relevant surrounding code and tests;
do not restrict a normal review to isolated added lines. Reviewer instructions
are not operating-system isolation: preserve the runtime's read-only controls
and never give a reviewer credentials, production data, or publishing authority.

For authority, personal-data, migration, concurrency, or production risks, add
specialist review when the general reviewer cannot adequately assess the risk.
Explain that choice in the PR instead of requiring a fixed number of lenses.
The primary synthesizes the result and remains responsible for landing.

Record the reviewed commit, scope, findings or `no findings`, and any specialist
review or reviewer substitution. After a push, independently review the delta
and affected paths and update the summary to the new head. Broaden that review
if scope or risk changed. Group actionable findings into a correction pass;
non-blocking suggestions must not restart a completed review cycle.

No separate CLI login or external bootstrap review is required for standard
review, including changes to review-tool code or policy. If a reviewer is
unavailable, use another available independent reviewer or a human; keep the
PR open if no independent review can be obtained. The sealed runner and the
attestation mergegate are optional legacy tooling described in
[optional agent tooling](docs/optional-agent-review.md), not prerequisites.
Current blocking CI, resolved substantive findings, an updated independent
review summary, and explicit merge authorization are still required by
[WORKFLOW.md](WORKFLOW.md#pr-and-review-expectations).

When a change depends on framework, library, or external API behavior, verify the assumption against primary documentation instead of guessing.

## Out of Scope

Avoid comments that do not materially improve confidence in the change, including:

- personal style preferences
- speculative refactors unrelated to the request
- renaming-only suggestions without safety impact
- complaints about missing cleanup outside the touched area
