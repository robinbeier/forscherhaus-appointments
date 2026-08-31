---
name: land
description: Drive an open PR from merge prep through merge by syncing the branch,
    monitoring CI and review feedback, fixing issues when needed, and merging
    once everything is green and mergeable.
---

# Land

Use this skill when the PR head is already exact-head review-clean and should
be shepherded through the repository mergegate, `Ready to Merge`, and the
actual merge.

## Goals

- Keep the PR mergeable against `origin/main`.
- Process CI and review feedback until no blocking issues remain.
- Merge the PR and move the Linear issue to `Done`.

## Authoritative Contract

The state and exact-head invariants in the
[agent workflow contract](../../contracts/agent-workflow.json) are
machine-checked and authoritative. Keep the prose below consistent with that
contract.

## Workflow

1. Confirm the current branch has an open PR and the worktree is clean.
2. If local changes still exist, use [$commit](../commit/SKILL.md) and
   [$push](../push/SKILL.md) first.
3. If the branch is behind or conflicting with `origin/main`, use
   [$pull](../pull/SKILL.md), then push the result.
    - Any push after `Ready to Merge` immediately invalidates the landing
      evidence. Move the Linear issue back to `In Review`, rerun exact-head CI
      and the required final reviews on the new head, and restore
      `Ready to Merge` only after that evidence is green again.
4. Start or resume [$babysit-pr](../babysit-pr/SKILL.md) and keep it running
   until one of these is true:
    - the PR is green, review-clean, and mergeable
    - new review or CI findings require changes
    - a blocker requires human help
5. If review or CI findings require code changes:
    - acknowledge them in GitHub where appropriate
    - move the Linear issue to `Rework` with [$linear](../linear/SKILL.md)
    - update the workpad
    - fix the code, commit, and push; after any fix/commit/push, return the
      issue to `In Review` and rerun exact-head CI plus all required final
      reviews on the new head before restoring `Ready to Merge`
    - return to the watcher only after that new-head evidence is available
6. Once the PR is green, review-clean, and mergeable:
    - publish a new, unedited privacy-safe final-review attestation with the
      current formal-review and inline-review-comment watermarks plus the
      formal-review payload digest described in `docs/exact-head-mergegate.md`
    - run
      `composer check:exact-head-mergegate -- --pr=<number-or-canonical-url> --reviewed-sha=<current_head_sha>`
    - require its bounded PR-identity and repeated CI-and-review evidence
      observations to remain unchanged through the final read; run it from the
      exact reviewed `HEAD` with the contract and mergegate files clean
    - only after exit `0`, move the Linear issue to `Ready to Merge`
    - confirm the current PR head still matches that exact reviewed,
      CI-green, mergegate-approved head
7. Merge it explicitly:
    - `gh pr merge --merge --match-head-commit <current_head_sha>`
    - do not force `--delete-branch` from inside the worker worktree; local
      workspace cleanup handles branch removal separately and avoids false
      non-zero exits after a successful merge
8. After merge:
    - verify the merge commit and refreshed `origin/main`
    - move the Linear issue to `Done`
    - update the `## Codex Workpad` comment with merge result and final
      validation summary

## Guardrails

- Do not enable auto-merge just to wait silently.
- Do not merge with unresolved review feedback.
- Do not merge a later head than the one that was reviewed and passed
  blocking CI and the exact-head mergegate.
- If the watcher surfaces a real blocker, stop and report it clearly.
- Keep the workpad compact and do not duplicate the PR URL there.
