---
name: land
description: Drive an open PR from merge preparation through merge by syncing
    the branch, processing CI and review feedback, and merging when authorized.
---

# Land

Use only when merging is authorized. Follow the canonical independent-review
path in [WORKFLOW.md](../../../WORKFLOW.md#pr-and-review-expectations).

## Workflow

1. Confirm an open PR and clean worktree. If local changes remain, use
   [$commit](../commit/SKILL.md) and [$push](../push/SKILL.md). If the branch
   is behind or conflicts with `origin/main`, use [$pull](../pull/SKILL.md),
   then push the result.
   Any push after `Ready to Merge` invalidates landing evidence; return the
   issue to `In Review`, recheck exact-head CI, and obtain independent review
   of the delta and affected paths.
2. Follow the native, bounded [PR follow-up loop](../../../WORKFLOW.md#pr-follow-up)
   until CI is green, review-clean, and mergeable, or a blocker needs human
   help. Apply corrections through [$linear](../linear/SKILL.md), the workpad,
   [$commit](../commit/SKILL.md), and [$push](../push/SKILL.md); then repeat the
   new-head checks.
3. Before landing, read the current PR head, blocking checks, and review
   feedback. Apply all [pre-merge checks](../../../WORKFLOW.md#pr-and-review-expectations):
   independent review is recorded for that head, substantive findings are
   fixed or concretely rejected, checks are green and not unexpectedly skipped,
   and the PR is mergeable. Confirm explicit merge authorization and move the
   associated Linear issue to `Ready to Merge`.
4. Capture the reviewed current SHA and merge with the compare-and-swap command:

    ```bash
    gh pr merge --merge --match-head-commit <current_head_sha>
    ```

    Do not queue auto-merge or use `--delete-branch` from a worker worktree.

5. Verify the merge commit and refreshed `origin/main`, move the associated
   Linear issue to `Done`, and update the `## Codex Workpad` with the result.

Never merge unresolved substantive findings or a later head than the one whose
blocking CI and independent review were verified. Keep the workpad compact and
do not duplicate the PR URL there. Follow the canonical [Linear state and
workpad rules](../linear/SKILL.md).
