---
name: land
description: Drive an open PR from merge prep through merge by syncing the branch,
    monitoring CI and review feedback, fixing issues when needed, and merging
    once everything is green and mergeable.
---

# Land

Use this skill when the Linear issue is in `Merging` and the PR should be
shepherded to an actual merge.

## Goals

-   Keep the PR mergeable against `origin/main`.
-   Process CI and review feedback until no blocking issues remain.
-   Merge the PR and move the Linear issue to `Done`.

## Workflow

1. Confirm the current branch has an open PR and the worktree is clean.
2. If local changes still exist, use [$commit](../commit/SKILL.md) and
   [$push](../push/SKILL.md) first.
3. If the branch is behind or conflicting with `origin/main`, use
   [$pull](../pull/SKILL.md), then push the result.
4. Start or resume [$babysit-pr](../babysit-pr/SKILL.md) and keep it running
   until one of these is true:
    - the PR is green, review-clean, and mergeable
    - new review or CI findings require changes
    - a blocker requires human help
5. If review or CI findings require code changes:
    - acknowledge them in GitHub where appropriate
    - move the Linear issue to `Rework` with [$linear](../linear/SKILL.md)
    - update the workpad
    - fix the code, commit, push, and return to the watcher
6. Once the PR is green and mergeable, merge it explicitly:
    - `gh pr merge --squash --delete-branch`
7. After merge:
    - move the Linear issue to `Done`
    - update the `## Codex Workpad` comment with merge result and final
      validation summary

## Guardrails

-   Do not enable auto-merge just to wait silently.
-   Do not merge with unresolved review feedback.
-   If the watcher surfaces a real blocker, stop and report it clearly.
-   Keep the workpad compact and do not duplicate the PR URL there.
