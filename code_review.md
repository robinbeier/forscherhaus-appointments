# Code Review Guide

Purpose: durable review guidance for `/review` and normal Codex turns in this repo.
Keep this file focused on review behavior, not on general setup or CI command matrices.

## Review Priorities

Review for real engineering risk in this order:

1. Correctness, regressions, and broken behavior
2. Security and unsafe trust boundaries
3. Data integrity, migrations, and rollback safety
4. Test gaps, weak assertions, and flaky validation
5. Maintainability, readability, and architectural fit

Prefer high-signal findings over broad commentary or style feedback.

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

When a change depends on framework, library, or external API behavior, verify the assumption against primary documentation instead of guessing.

## Out of Scope

Avoid comments that do not materially improve confidence in the change, including:

- personal style preferences
- speculative refactors unrelated to the request
- renaming-only suggestions without safety impact
- complaints about missing cleanup outside the touched area
