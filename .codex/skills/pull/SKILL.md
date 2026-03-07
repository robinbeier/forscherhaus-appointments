---
name: pull
description: Merge the latest origin/main into the current branch, resolve conflicts
    cleanly, and rerun the relevant local validation for this repo.
---

# Pull

Use this skill when the current issue branch must be synced with `origin/main`
or when `git push` is rejected because the remote moved.

## Workflow

1. Ensure the worktree is clean. Commit with [$commit](../commit/SKILL.md) or
   stop if unrelated changes are present.
2. Enable rerere locally:
    - `git config rerere.enabled true`
    - `git config rerere.autoupdate true`
3. Fetch latest refs:
    - `git fetch --prune origin`
4. Fast-forward the remote feature branch first:
    - `git pull --ff-only origin $(git branch --show-current)`
5. Merge `origin/main` with conflict context:
    - `git -c merge.conflictstyle=zdiff3 merge origin/main`
6. Resolve conflicts carefully and remove all conflict markers.
7. Rerun the relevant validation for the touched scope.
8. Before the branch is treated as ready again, rerun the stronger repo gate
   required by [WORKFLOW.md](../../../WORKFLOW.md).
9. Record one short `pull` evidence line in the workpad:
    - merge source(s)
    - result (`clean` or `conflicts resolved`)
    - resulting `HEAD` short SHA

## Conflict Guidance

-   Understand both sides before editing.
-   Prefer minimal, intention-preserving resolutions.
-   Use `git diff --merge` and `git diff --check`.
-   Regenerate derived files only after source conflicts are resolved.
-   Ask for human input only when product intent is genuinely ambiguous.
