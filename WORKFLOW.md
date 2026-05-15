# Workflow

This document defines the operational delivery workflow for
`forscherhaus-appointments`. It complements [AGENTS.md](AGENTS.md); if the two
conflict, follow `AGENTS.md`.

## Canonical Scope

- Use `README.md` for operator onboarding, quickstart, and local service usage.
- Use `docs/agent-harness-index.md` for the shortest route to the right
  steering source.
- Use `AGENTS.md` for compact repo guardrails, validation defaults, and topic
  routing.
- Use `WORKFLOW.md` for active agent behavior, Linear state handling, workpad
  discipline, and the ticket-to-merge loop.

## Non-Negotiables

- Keep production code inside `application/`.
- Do not modify `system/` unless the change is an explicit upstream patch.
- Use CodeIgniter migrations for DB changes and keep rollback paths complete.
- Run CI-parity checks through Docker for merge-sensitive changes.
- For multi-PR work, land one PR completely before starting the next.
- Preserve the current invariant: `services.attendants_number == 1` unless the
  product scope changes explicitly.
- When `docs/maps/component_ownership_map.json` marks a component as
  `single-owner` or `manual_approval_required`, keep changes narrow and
  conservative.

## Linear States

Expected statuses:

- `Todo`
- `In Progress`
- `In Review`
- `Rework`
- `Ready to Merge`
- `Done`
- `Canceled`

Normal path:

`Todo` -> `In Progress` -> `In Review` -> `Ready to Merge` -> `Done`

Review change path:

`In Review` -> `Rework` -> `In Review`

Use the states as follows:

- `Todo`: ready to start, no implementation has begun yet.
- `In Progress`: active implementation and local validation.
- `In Review`: PR exists and is waiting on human review, CI completion, or
  explicit merge intent.
- `Rework`: active response to PR review feedback, CI failures, or requested
  follow-up on the same PR.
- `Ready to Merge`: final landing phase.
- `Done`: merged and complete.

## Codex Workpad

For Linear-backed work, keep one persistent Linear comment whose body starts
with:

```md
## Codex Workpad
```

Rewrite it in place; do not create a new planning comment every run. Keep it
concise and structured:

```md
## Codex Workpad

### Status

- Summary: ...
- Next: ...

### Plan

- ...

### Validation

- Done: ...
- Pending: ...

### Blockers

- None.
```

Rules:

- Summarize evidence; do not paste long command output.
- Omit empty sections instead of filling them with placeholders.
- Do not put PR URLs into the workpad; keep PR linkage on the Linear issue and
  in GitHub.
- Update it when entering a new run, before opening or updating a PR, whenever
  the Linear state changes, and immediately after merge.

## Run Playbook

### 1. Resume and orient

- Read the issue, current state, branch, PR context, and existing workpad.
- If the current branch already exists, sync it before editing.
- Reproduce the problem or gather concrete evidence before changing code.
- Record the current understanding in the workpad when Linear is involved.
- Keep milestones tight, but once a local diff exists continue toward
  validation and commit rather than re-planning the same change.

### 2. Implement

- Keep scope aligned with the issue. Do not bundle unrelated cleanup.
- Make small, reviewable changes.
- Add or update regression tests when a stable test is practical.
- Rebuild compiled frontend artifacts when `assets/js` or `assets/css` changes
  require it.
- Do not end active implementation with only dirty workspace changes unless the
  task is explicitly paused or blocked.

### 3. Validate locally

Use the narrowest relevant validation early, then the stronger gate before the
PR is treated as ready.

Minimum expectation for merge-sensitive changes:

```bash
docker compose run --rm php-fpm composer test
```

Before publishing a PR as review-ready, run:

```bash
PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh
```

For the full gate matrix, optional scope-specific smokes, and rollback notes,
route through `docs/agent-harness-index.md` and then `AGENTS.md`.

### 4. Publish and park for review

When the branch is ready to publish:

- create or update the PR from the current branch
- fill the PR template completely
- attach the PR to the Linear issue when applicable
- move the Linear issue to `In Review` by default
- if the PR should stay agent-owned through review and merge, move it directly
  to `Ready to Merge`
- update the workpad with validation status, merge/review posture, and what
  would reactivate the issue

Do not leave a reviewable PR in `In Progress`.

### 5. Rework loop

When the issue is moved to `Rework`:

- inspect open PR comments, reviewer findings, and failing CI
- update the workpad with the current rework plan
- fix the required issues
- commit and push the changes
- return the issue to `In Review` when waiting on reviewers again
- move it directly to `Ready to Merge` only when the PR is truly in the
  landing phase

Treat both human findings and Codex-review findings as real review work until
they are explicitly addressed or rejected with a clear rationale.

## PR and Review Expectations

Every PR must cover two review lenses:

- Reviewer A: bugs, regressions, security, edge cases
- Reviewer B: architecture, readability, test coverage, maintainability

The PR is not done until:

- required blocking CI is green
- no open review findings remain
- the PR is mergeable
- required docs or migration notes are included
- the issue is moved to `Done`

## Stop and Escalate

Stop and ask for human input when:

- product or legal requirements are unclear
- the task touches privacy-sensitive behavior without prior approval
- unexpected user changes appear in the same files you must edit
- a change requires edits under `system/`
- a DB change cannot be expressed with a safe migration and rollback
- a blocking CI gate would need to be relaxed
- a review comment conflicts with the user's stated intent and the correct
  answer is not inferable from code, tests, or nearby docs

If a blocking gate must temporarily become advisory because of false positives,
create a follow-up issue with a return-to-blocking deadline of at most 14 days.
