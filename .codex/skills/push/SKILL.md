---
name: push
description: Push the current issue branch, create or update the GitHub pull
    request, link it to Linear, and move the issue into review.
---

# Push

Use when the current branch is ready to publish or when review fixes must be
pushed to an existing PR. Keep the state and exact-head invariants in the
[agent workflow contract](../../contracts/agent-workflow.json) authoritative.

## Steps

1. Confirm the current branch and a clean worktree; finalize intended local
   edits with [$commit](../commit/SKILL.md). Run the scoped validation
   selected by [WORKFLOW.md](../../../WORKFLOW.md#3-validate-locally); do not
   rerun a passing check gratuitously.
2. Push with upstream tracking when needed:
   `git push -u origin HEAD`.
3. If the push is rejected because the branch is stale or non-fast-forward,
   follow [$pull](../pull/SKILL.md), then push again. If history was
   intentionally rewritten, `--force-with-lease` is the only permitted force
   option. Surface authentication or other push failures as failures.
4. Ensure a PR exists. Create or update it as needed; if its prior PR is
   closed or merged, use a fresh branch and PR. Fill every section of the
   [current PR template](../../../.github/pull_request_template.md) and remove
   placeholders before `gh pr create` or `gh pr edit`.
5. When an associated Linear issue exists, attach the published PR, move the
   issue to `In Review`,
   and update the single `## Codex Workpad` comment with validation, posture,
   and next action. Follow [$linear](../linear/SKILL.md) for those operations
   and [WORKFLOW.md](../../../WORKFLOW.md#codex-workpad) for the workpad.
6. Reply with the published PR URL. Creating a PR does not authorize merging.

Any push after CI or review evidence makes that landing evidence stale. Return
the issue to `In Review` and follow the exact-head and independent-review rules
in [WORKFLOW.md](../../../WORKFLOW.md#5-review-correction-loop). Keep the PR URL
on the Linear issue, not in the workpad.

If the correct diff is already present and validated, publish it without
reopening analysis. Standard review and publication do not require a separate
CLI login; use the canonical [review expectations](../../../WORKFLOW.md#pr-and-review-expectations).
