---
name: push
description: Push the current issue branch, create or update the GitHub pull request, link
    it back to Linear, and move the issue into the review state.
---

# Push

Use this skill when the current branch is ready to publish or when review fixes
must be pushed to an existing PR.

## Goals

- Push the branch to `origin` safely.
- Create or update the PR with the repo template.
- Attach the PR to the Linear issue and move the issue to the correct next
  Linear state.

## Authoritative Contract

The state and exact-head invariants in the
[agent workflow contract](../../contracts/agent-workflow.json) are
machine-checked and authoritative. Keep the prose below consistent with that
contract.

## Steps

1. Identify the current branch and confirm the worktree is clean.
2. Run the required local validation for the current scope.
    - Before marking the PR ready, prefer:
        - `PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh`
    - If that has already been run for the current diff, do not rerun it
      gratuitously.
3. Push with upstream tracking if needed:
    - `git push -u origin HEAD`
4. If the push is rejected because the branch is stale or non-fast-forward,
   resolve it with [$pull](../pull/SKILL.md) and push again.
5. Ensure a PR exists for the branch.
    - Create one if missing.
    - Update title/body if the scope changed.
    - If `gh pr edit` requests GitHub Projects scope, do not widen the token.
      Use the Primary-only repository transport documented in
      `docs/github-pr-write-transport.md` for title/body updates. Feed its
      bounded JSON only through stdin; it rejects payload-file options, foreign
      repositories, and PRs not bound to the local exact head.
    - If the branch is tied to a closed or merged PR, create a fresh branch and
      reopen from there.
6. Use [`.github/pull_request_template.md`](../../../.github/pull_request_template.md)
   as the PR body source. Fill every section with concrete content.
    - Before `gh pr create` or `gh pr edit`, review the final body against the
      template and remove placeholders.
7. After the PR exists:
    - attach it to the Linear issue with [$linear](../linear/SKILL.md)
    - move the issue to `In Review`
    - do not move it to `Ready to Merge` during publish; that state is reserved
      for the later unchanged exact PR head after blocking CI and the required
      final reviews are both green and finding-free
    - update the `## Codex Workpad` comment with compact validation status,
      merge/review posture, and next expected action
8. Reply with the PR URL.

## Commands

```bash
branch=$(git branch --show-current)
git push -u origin HEAD
gh pr view --json state,url,number 2>/dev/null || true
```

## Notes

- Use `--force-with-lease` only if history was intentionally rewritten.
- If push fails for auth or permissions, stop and surface the exact error.
- After creating or updating the PR, the Linear issue should not stay in
  `In Progress`; move it to `In Review`.
- Any push after review or CI evidence was collected makes that landing
  evidence stale. Return the issue to `In Review` until exact-head CI and the
  required final reviews are re-established on the new head.
- Keep the PR linked on the Linear issue itself; do not duplicate the PR URL in
  the workpad.
- Never obtain, export, print, or pass a raw GitHub token. The narrow REST
  fallback uses an absolute verified `gh` binary, the native credential store,
  a fixed child environment, and a canonical exact-head PR target; it does not
  grant merge, branch, rerun, review, or Linear authority.
- If the correct diff is already present and validated, stop exploring and
  publish it instead of reopening analysis.
