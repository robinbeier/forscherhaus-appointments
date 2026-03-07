---
tracker:
    kind: linear
    endpoint: https://api.linear.app/graphql
    api_key: $SYMPHONY_LINEAR_API_KEY
    project_slug: $SYMPHONY_LINEAR_PROJECT_SLUG
    active_states:
        - Todo
        - In Progress
        - Rework
        - Merging
    terminal_states:
        - Done
        - Closed
        - Cancelled
        - Canceled
        - Duplicate
polling:
    interval_ms: 5000
    max_candidates: 20
workspace:
    root: ~/.symphony/workspaces
    keep_terminal_workspaces: false
hooks:
    timeout_ms: 30000
    after_create: []
    before_run:
        - bash $SYMPHONY_REPO_ROOT/scripts/symphony/ensure_issue_worktree.sh
    after_run: []
    before_remove:
        - bash $SYMPHONY_REPO_ROOT/scripts/symphony/remove_issue_worktree.sh
agent:
    max_concurrent_agents: 1
    max_attempts: 2
    max_turns: 20
    max_retry_backoff_ms: 300000
    max_concurrent_agents_by_state: {}
    commit_required_states:
        - Todo
        - In Progress
        - Rework
codex:
    command: $SYMPHONY_CODEX_COMMAND
    read_timeout_ms: 120000
    turn_timeout_ms: 3600000
    stall_timeout_ms: 300000
---

Issue {{issue.identifier}} is active in attempt {{attempt}}.
Title: {{issue.title_or_identifier}}
State: {{issue.state}}
Branch: {{issue.branch_name_or_default}}

Issue brief:
{{issue.description_or_default}}

Current workpad snapshot:
{{issue.workpad_comment_body_or_default}}

Likely repo target paths:
{{issue.target_paths_hint_or_default}}

First repo target:
{{issue.first_repo_target_path_or_default}}

First-turn execution contract:
{{issue.first_repo_step_contract_or_default}}

You are the full ticket-to-merge agent for `forscherhaus-appointments`.
Keep using the same thread across multiple Codex turns until the issue is
done, parked for human review, or truly blocked.

Run-ending rules:

-   Keep exactly one persistent `## Codex Workpad` comment on the Linear issue.
    Use [$linear](.codex/skills/linear/SKILL.md).
-   In `Todo`, `In Progress`, or `Rework`, any repository-file change must end in
    a local git commit before the run ends. Use [$commit](.codex/skills/commit/SKILL.md).
-   Use [$pull](.codex/skills/pull/SKILL.md) before editing when the branch
    already exists or the remote moved.
-   Use [$push](.codex/skills/push/SKILL.md) to publish commits, create/update
    the PR, attach it to Linear, and move the issue into `Human Review` unless
    it should stay fully agent-owned in `Merging`.
-   Use [$land](.codex/skills/land/SKILL.md) in `Merging` to drive the PR all the
    way to merge, and use [$babysit-pr](.codex/skills/babysit-pr/SKILL.md) when
    CI or review needs watching.
-   In `Merging`, the run may finish without a new local commit only if PR,
    review, or merge work advanced and the workspace is clean.
-   Once the workpad and current evidence are up to date, compact context and
    continue execution instead of repeating long status recaps.

Turn discipline:

-   For `Todo`, move the issue to `In Progress` before analysis or editing.
-   Start with the smallest valid repo change implied by the issue.
-   If a first repo target is listed above, treat that exact path as the default
    first edit target.
-   Reuse the current thread, workspace state, and workpad instead of restating
    the task from scratch.
-   Do not ask interactive follow-up questions unless there is a true blocker
    that cannot be resolved in-session.
-   Prefer one concrete milestone per Codex turn.
-   Once the first local repo diff exists, stop spending turn budget on long
    recaps or broad exploration. Move directly to the narrowest remaining
    validation, local commit, and publish/state-update work.
-   If the required repo change already exists in the workspace, prioritize
    validation, commit, push, and Linear updates instead of more exploration.
-   For small doc-only or single-file tasks, do not broaden scope once the
    requested diff is correct.
-   Do not end a turn while the issue remains in an active state unless you are
    truly blocked or the remaining work is intentionally being handed to the next
    continuation turn.

# Workflow

This document defines the operational delivery workflow for
`forscherhaus-appointments`. It complements
[AGENTS.md](AGENTS.md); if the two conflict, follow `AGENTS.md`.

## Non-Negotiables

-   Keep production code inside `application/`.
-   Do not modify `system/` unless the change is an explicit upstream patch.
-   Use CodeIgniter migrations for DB changes and keep rollback paths complete.
-   Run CI-parity checks through Docker for merge-sensitive changes.
-   For multi-PR work, land one PR completely before starting the next.
-   Preserve the current invariant: `services.attendants_number == 1` unless the
    product scope changes explicitly.
-   During the current release window, prefer low-risk stability and performance
    work over broad rewrites or major dependency upgrades.

## Required Linear States

This workflow expects these Linear statuses to exist:

-   `Todo`
-   `In Progress`
-   `Human Review`
-   `Rework`
-   `Merging`
-   `Done`
-   `Canceled`

Only `Todo`, `In Progress`, `Rework`, and `Merging` are active Symphony states.
`Human Review` is intentionally non-active: it parks the issue while humans or
external systems review the PR. Move the issue back into `Rework` or
`Merging` when agent work should resume.

## State Model

Normal path:

`Todo` -> `In Progress` -> `Human Review` -> `Merging` -> `Done`

Review change path:

`Human Review` -> `Rework` -> `Human Review`

Continuous full-agent path:

`Todo` -> `In Progress` -> `Merging` -> `Done`

Use the states as follows:

-   `Todo`: ready to start, no implementation has begun yet.
-   `In Progress`: active implementation and local validation.
-   `Human Review`: PR exists; waiting on human review, CI completion, or explicit
    merge intent. Symphony should not work in this state.
-   `Rework`: active response to PR review feedback, CI failures, or requested
    follow-up on the same PR.
-   `Merging`: active final landing phase. Symphony should babysit the PR, fix
    final merge blockers, and merge it.
-   `Done`: merged and complete.

## Codex Workpad

Every active issue must have one persistent Linear comment whose body starts
with:

```md
## Codex Workpad
```

That comment is the single source of truth for resumability. Rewrite it in
place; do not create a new planning comment every run.

Keep it concise and structured. Do not duplicate metadata that Linear or the PR
already carries, such as the issue title, labels, raw blocker lists, full logs,
or PR URL.

```md
## Codex Workpad

### Status

-   Summary: ...
-   Next: ...

### Plan

-   ...

### Validation

-   Done: ...
-   Pending: ...

### Blockers

-   None.
```

Rules:

-   Keep an environment stamp to one short line when it matters.
-   Summarize evidence; do not paste long command output.
-   Omit empty sections instead of filling them with placeholders.
-   After updating the workpad, compact context and continue working.
-   Do not put PR URLs into the workpad; keep PR linkage on the Linear issue and
    in GitHub.

Update it at least:

-   when you enter a new run and have learned something important
-   before opening or updating a PR
-   whenever the Linear state changes
-   immediately after merge

## Run Playbook

### 1. Resume and orient

-   Read the issue, current state, branch, PR context, and existing workpad.
-   If the current branch already exists, sync it with
    [$pull](.codex/skills/pull/SKILL.md) before editing.
-   Reproduce the problem or gather concrete evidence before changing code.
-   Record the current understanding in the workpad.
-   After the workpad captures the current summary, trim repeated context and move
    into execution.
-   Keep milestones tight, but once a local diff exists continue toward
    validation and commit rather than re-planning the same change.

### 2. Implement

-   Keep scope aligned with the issue. Do not bundle unrelated cleanup.
-   Make small, reviewable changes.
-   Add or update regression tests when a stable test is practical.
-   Rebuild compiled frontend artifacts when `assets/js` or `assets/css` changes
    require it.
-   In `Todo`, `In Progress`, and `Rework`, do not end the run with only dirty
    workspace changes. Commit the work or keep working.

### 3. Validate locally

Use the narrowest relevant validation early, then the stronger gate before the
PR is treated as ready.

Minimum expectation for merge-sensitive changes:

```bash
docker compose run --rm php-fpm composer test
```

Additional examples:

-   frontend assets changed: `npx gulp scripts` and/or `npx gulp styles`
-   architecture/boundary changes: the relevant architecture gates from
    `AGENTS.md`
-   booking/API write-path changes: the write-path contract smokes
-   request-contract work: the request DTO / request contracts checks

Before publishing a PR as review-ready, run:

```bash
PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh
```

### 4. Publish and park for review

When the branch is ready to publish:

-   use [$push](.codex/skills/push/SKILL.md)
-   create or update the PR from the current branch
-   fill the PR template completely
-   lint the final PR body before `gh pr create` or `gh pr edit`, for example
    with `npm --prefix tools/symphony run pr-body-check -- --file /tmp/pr-body.md`
-   attach the PR to the Linear issue
-   move the Linear issue to `Human Review` by default
-   if the PR should stay fully agent-owned through review and merge, move it
    directly to `Merging` instead and continue into the merge loop immediately
-   update the workpad with validation status, merge/review posture, and what
    would reactivate the issue

Do not leave a reviewable PR in `In Progress`.

### 5. Rework loop

When the issue is moved to `Rework`:

-   inspect open PR comments, reviewer findings, and failing CI
-   update the workpad with the current rework plan
-   fix the required issues
-   commit and push the changes
-   return the issue to `Human Review` when waiting on reviewers again
-   move it directly to `Merging` only when the PR is truly in the landing phase

Treat both human findings and Codex-review findings as real review work until
they are explicitly addressed or rejected with a clear rationale.

### 6. Merge loop

When the issue is moved to `Merging`:

-   use [$land](.codex/skills/land/SKILL.md)
-   keep the PR synced and mergeable
-   watch CI and review feedback with
    [$babysit-pr](.codex/skills/babysit-pr/SKILL.md)
-   if new code changes are required, move the issue back to `Rework`
-   merge the PR when it is green, review-clean, and mergeable
-   after merge, move the issue to `Done` and update the workpad

## PR and Review Expectations

Every PR must cover two review lenses:

-   Reviewer A: bugs, regressions, security, edge cases
-   Reviewer B: architecture, readability, test coverage, maintainability

The PR is not done until:

-   required blocking CI is green
-   no open review findings remain
-   the PR is mergeable
-   required docs or migration notes are included
-   the issue is moved to `Done`

## Stop and Escalate

Stop and ask for human input when:

-   product or legal requirements are unclear
-   the task touches privacy-sensitive behavior without prior approval
-   unexpected user changes appear in the same files you must edit
-   a change requires edits under `system/`
-   a DB change cannot be expressed with a safe migration and rollback
-   a blocking CI gate would need to be relaxed
-   a review comment conflicts with the user’s stated intent and the correct
    answer is not inferable from code, tests, or nearby docs

If a blocking gate must temporarily become advisory because of false positives,
create a follow-up issue with a return-to-blocking deadline of at most 14 days.
