---
tracker:
    provider: linear
    api_key: $SYMPHONY_LINEAR_API_KEY
    project_slug: $SYMPHONY_LINEAR_PROJECT_SLUG
    active_states:
        - In Progress
polling:
    interval_ms: 60000
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
    max_concurrent: 1
    max_attempts: 2
codex:
    command: $SYMPHONY_CODEX_COMMAND
    response_timeout_ms: 120000
    turn_timeout_ms: 300000
---

Issue {{issue.identifier}} is being processed in attempt {{attempt}}.

# Workflow

This document defines the operational delivery workflow for
`forscherhaus-appointments`.

It complements [AGENTS.md](/Users/robinbeier/Developers/forscherhaus-appointments/AGENTS.md):

-   `AGENTS.md` is the authoritative source for coding, testing, safety, and repo
    guardrails.
-   `WORKFLOW.md` defines how work moves from idea to merge.

If the two documents ever conflict, follow `AGENTS.md`.

This is the first draft, optimized for human + agent collaboration and for a
small Symphony pilot.

## Non-Negotiables

-   Keep production code inside `application/`.
-   Do not modify `system/` unless the change is an explicit upstream patch.
-   Use CodeIgniter migrations for database changes and keep rollback paths
    complete.
-   Run CI-parity checks through Docker for merge-sensitive changes.
-   For multi-PR plans, work strictly one PR at a time: open, validate, review,
    merge, then start the next PR.
-   Until the current deployment is complete, prefer low-risk stability and
    performance work. Do not start major dependency upgrades in this window.
-   Preserve the current domain invariant: `services.attendants_number == 1`
    unless product scope explicitly changes.

## Roles

-   Requester: defines the outcome, priority, and risk tolerance.
-   Implementer: human or agent doing the work.
-   Reviewer A: checks bugs, regressions, security, and edge cases.
-   Reviewer B: checks architecture, readability, test coverage, and
    maintainability.
-   Merge owner: confirms mergeability and performs the merge.

One person may hold multiple roles, but the review responsibilities must still
be covered explicitly.

## Work Item Contract

Before a task moves to `Todo`, it should have:

-   a clear problem statement or desired outcome
-   explicit acceptance criteria
-   known constraints or guardrails
-   validation expectations
-   listed blockers or dependencies, if any

For Symphony pilots, every non-trivial task should have a Linear issue.

## Linear State Model

Use the existing Linear statuses with the following meaning:

| State            | Meaning                           | Entry criteria                                        | Exit criteria                           |
| ---------------- | --------------------------------- | ----------------------------------------------------- | --------------------------------------- |
| `Backlog`        | Parked idea                       | Worth keeping, not ready to scope                     | Promoted to `Triage`                    |
| `Triage`         | Needs clarification               | Goal, scope, or risk still unclear                    | Acceptance criteria and owner are clear |
| `Todo`           | Ready to implement                | Scope is small enough and unblockers are known        | Work has started                        |
| `In Progress`    | Active implementation             | One implementer is actively working                   | PR opened or task returned to `Todo`    |
| `In Review`      | PR is open and feedback is active | Branch pushed, PR open, required context written down | Findings resolved and blocking CI green |
| `Ready to Merge` | Review-complete and mergeable     | No open findings, required CI green, merge is allowed | Merged or sent back to `In Progress`    |
| `Done`           | Merged and complete               | Code is merged and no immediate follow-up is required | N/A                                     |
| `Canceled`       | Explicitly stopped                | Task is no longer desired                             | N/A                                     |

## Delivery Flow

### 1. Intake

-   Create or identify the Linear issue.
-   Confirm goal, constraints, and definition of done.
-   Split oversized work before implementation begins.

If the plan requires multiple PRs, define the sequence up front and execute it
strictly one PR at a time.

### 2. Start Work

-   Move the issue to `In Progress`.
-   Create a branch.
-   Keep the scope aligned with the issue. Do not bundle unrelated cleanup.

### 3. Implement

-   Make small, reviewable changes.
-   Follow repo guardrails from `AGENTS.md`.
-   Add or update regression tests when a bug is fixed and a stable test is
    practical.
-   Rebuild compiled frontend artifacts when `assets/js` or `assets/css` changes
    require it.

### 4. Local Validation

Use CI-parity checks early, not only at the end.

Minimum expectation for merge-sensitive changes:

```bash
docker compose run --rm php-fpm composer test
```

Use additional validation based on change scope:

-   frontend assets changed: `npx gulp scripts` and/or `npx gulp styles`
-   architecture or boundary changes: the relevant architecture gates
-   request contract work: the request DTO / request contracts checks
-   booking/API write-path changes: the write-path contract smokes
-   release/deploy changes: the relevant release gate or zero-surprise replay

Before marking a PR `ready for review`, run the full local pre-PR gate:

```bash
PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh
```

### 5. Open PR

When the branch is ready for review:

-   open the PR
-   move the issue to `In Review`
-   summarize scope, risk areas, and validation performed
-   call out any follow-up intentionally deferred from the PR

### 6. Review Loop

Every implementation plan should cover two review lenses:

-   Reviewer A: bugs, regressions, security, edge cases
-   Reviewer B: architecture, readability, test gaps, maintainability

Resolve findings and repeat the loop until there are no open issues.

### 7. Ready To Merge

Move to `Ready to Merge` only when all of the following are true:

-   required blocking CI is green
-   no open review findings remain
-   the PR is mergeable
-   any required documentation or migration notes are included

After that, continue monitoring the PR until it is actually merged.

### 8. Merge And Follow-Up

After merge:

-   move the issue to `Done`
-   create explicit follow-up issues for deferred work
-   if the original plan had more PRs, start the next one only now

## Stop And Escalate

Stop and ask for human input when:

-   product or legal requirements are unclear
-   the task touches data/privacy-sensitive behavior without prior approval
-   unexpected user changes appear in the same files you need to edit
-   a change requires edits under `system/`
-   a DB change cannot be expressed with a safe migration and rollback path
-   a blocking CI gate needs to be relaxed
-   a task expands beyond the current issue or PR scope

If a blocking gate must be made advisory because of false positives, create a
follow-up issue with a return-to-blocking deadline of at most 14 days.

## Symphony Pilot Guardrails

For the initial Symphony trial:

-   start with 3-5 small, low-risk issues
-   prefer CI/docs/refactor/test-hardening work over feature work
-   avoid production secrets and live operational actions
-   avoid major dependency upgrades during the pilot
-   avoid privacy-sensitive school data workflows during the pilot
-   measure first-pass CI rate, review churn, and time from `Todo` to
    `Ready to Merge`

The pilot is successful only if it reduces coordination overhead without
weakening review quality or release safety.

## Recommended Symphony Pilot Ticket Types

Use the first Symphony pilot only for issue types with small scope, clear
validation, and low rollback cost.

### Good Pilot Ticket Types

1. CI and developer-experience hardening

    Examples:

    - tighten a flaky smoke or readiness check
    - improve CI diagnostics or artifact outputs
    - improve local repro instructions for an existing blocking gate

    Expected validation:

    - targeted gate or repro command runs successfully
    - no production behavior changes unless explicitly intended

2. Test hardening for existing behavior

    Examples:

    - add a regression test for a known bug
    - extend request-contract or contract-smoke coverage for an existing flow
    - add focused controller or validator tests for an existing invariant

    Expected validation:

    - new test fails before the fix when practical
    - relevant PHPUnit / contract checks pass after the change

3. Small refactors behind existing behavior

    Examples:

    - extract duplicated logic in helpers or libraries
    - simplify request parsing without changing route semantics
    - improve code organization inside `application/` without moving boundaries

    Expected validation:

    - existing tests stay green
    - architecture / boundary checks remain green when relevant

4. Documentation and workflow codification

    Examples:

    - update repo runbooks such as `WORKFLOW.md` or release docs
    - document a CI gate, fallback path, or validation sequence
    - codify an existing manual operating rule that repeatedly causes friction

    Expected validation:

    - documentation is internally consistent with `AGENTS.md`
    - examples and commands are accurate

5. Read-only operational safety checks

    Examples:

    - improve release-gate reporting or replay ergonomics
    - tighten non-destructive health or canary assertions
    - clarify breakglass documentation or operator checklists

    Expected validation:

    - replay/smoke scripts still run as documented
    - rollback semantics are unchanged unless explicitly reviewed

### Avoid In The First Pilot

-   major dependency upgrades
-   schema migrations with broad blast radius
-   privacy-sensitive feature work
-   large frontend rewrites
-   cross-cutting architectural moves across many directories
-   anything that requires loosening blocking CI to get merged

## Symphony Pilot Delivery Checklist

Use this checklist for every Symphony pilot issue.

### Linear -> `Todo`

-   the issue has a clear problem statement
-   the issue has explicit acceptance criteria
-   scope fits in one small PR
-   validation expectations are written down
-   no unresolved product/legal dependency exists

### `Todo` -> `In Progress`

-   one implementer is assigned
-   the branch is created
-   out-of-scope cleanup is explicitly excluded
-   any risky assumption is called out before implementation starts

### `In Progress` -> local validation complete

-   code and docs changes are limited to the issue scope
-   regression tests were added when appropriate
-   frontend assets were rebuilt if required
-   minimum CI-parity test passed:

```bash
docker compose run --rm php-fpm composer test
```

-   additional scope-specific checks passed
-   if relevant, the full pre-PR gate passed:

```bash
PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh
```

### local validation complete -> `In Review`

-   PR is opened
-   Linear issue is moved to `In Review`
-   PR description states:
    -   what changed
    -   why it changed
    -   risk areas
    -   commands/checks run
    -   follow-up work intentionally deferred

### `In Review` -> `Ready to Merge`

-   Reviewer A findings are resolved
-   Reviewer B findings are resolved
-   required blocking CI is green
-   PR is mergeable
-   docs, migration notes, or screenshots are attached when needed

### `Ready to Merge` -> `Done`

-   PR is merged
-   Linear issue is moved to `Done`
-   deferred work is captured as follow-up issues
-   if this was part of a multi-PR plan, only now may the next PR begin
