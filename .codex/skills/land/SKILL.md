---
name: land
description: Drive an open PR from merge prep through merge by syncing the branch,
    monitoring CI and review feedback, fixing issues when needed, and merging
    once everything is green and mergeable.
---

# Land

Use this skill only when merging is authorized. Follow the standard independent
review path in `WORKFLOW.md`; the optional legacy attestation tool is not a
prerequisite for landing.

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
      and an independent review of the delta and affected paths on the new head, and restore
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
      issue to `In Review` and rerun exact-head CI and update independent review for the new head
      after checking the delta and affected paths before restoring `Ready to Merge`
    - return to the watcher only after that new-head evidence is available
6. Once the PR is green, review-clean, and mergeable:
    - read the current PR head, applicable blocking CI results, and review feedback
    - require a review summary for that head from an independent reviewer;
      include scope, outcome, and any risk-based specialist or substitution
    - do not accept missing, pending, failed, or unexpectedly skipped blocking checks
    - ensure substantive findings are fixed or rejected with a concrete rationale;
      unresolved blocking reviews prevent landing
    - confirm that the user authorized merging, not merely PR creation
    - move an associated Linear issue to `Ready to Merge`
    - keep the verified SHA for the compare-and-swap merge below
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
- Do not merge with unresolved substantive review findings.
- Broaden a delta review when scope or risk changed; record the new reviewed SHA.
- Do not merge a later head than the one that was reviewed and passed
  blocking CI.
- If the watcher surfaces a real blocker, stop and report it clearly.
- Keep the workpad compact and do not duplicate the PR URL there.
