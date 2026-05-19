# Plan: Branch Stabilization and Mainline Merge

## Operating Rule

Complete one branch at a time. Do not begin the Remove-Symphony integration
until the LTS modernization PR is merged or the user explicitly accepts a
different handoff point.

After every milestone, update `Documentation.md`. If a validation fails, repair
or document the blocker before moving on.

## Milestone 0: Baseline and Branch Inventory

Goal: Confirm current local and remote branch state before changing anything.

Deliverables:

- Fresh `origin` refs.
- Clean worktree confirmation.
- Current SHAs for `origin/main`, `codex/lts-modernization-long-horizon`, and
  `codex/remove-symphony`.
- Diff/overlap summary for both branches.

Validations:

```bash
git status --short --branch
git fetch origin --prune
git rev-list --left-right --count origin/main...codex/lts-modernization-long-horizon
git rev-list --left-right --count origin/main...codex/remove-symphony
comm -12 \
  <(git diff --name-only origin/main..codex/lts-modernization-long-horizon | sort) \
  <(git diff --name-only origin/main..codex/remove-symphony | sort)
```

Stop-and-fix:

- If the worktree is dirty with unrelated changes, stop and ask.
- If remote branch names differ, update this plan before continuing.

## Milestone 1: Stabilize LTS Modernization on Current Main

Goal: Bring `codex/lts-modernization-long-horizon` up to date with current
`origin/main`.

Deliverables:

- Branch updated from current `origin/main`.
- Any conflicts resolved.
- Conflict decisions recorded.
- No unrelated refactors.

Preferred conflict policy:

- Preserve Node 24, PHP 8.5 preview/support, artifact deploy, server rebuild,
  Kuma, and production operations harness changes.
- Preserve current `main` fixes unless they directly conflict with the branch's
  accepted modernization work.
- Do not remove the production-operations harness added by ROB-374.

Validations:

```bash
git status --short --branch
git diff --check
bash -n scripts/ops/prod_doctor.sh scripts/ops/prod_logs_summary.sh scripts/ops/prod_validate_after_change.sh scripts/ops/install_prod_agent_readme.sh scripts/ops/lib/prod_common.sh
bash ./scripts/ci/pre_pr_quick.sh
PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh
```

Production read-only smoke, if SSH is available:

```bash
bash scripts/ops/prod_doctor.sh --prod-ssh-target root@188.245.244.123
bash scripts/ops/prod_validate_after_change.sh --prod-ssh-target root@188.245.244.123
```

Stop-and-fix:

- If quick/full gates fail because of branch code, fix and rerun the failed
  gate.
- If production read-only smoke fails, do not mutate production; document and
  ask unless the failure is clearly transient and non-blocking for the PR.

## Milestone 2: Push/Open LTS PR and Babysit

Goal: Push the stabilized LTS branch and watch the PR until it is ready,
merged/closed, or blocked.

Deliverables:

- Pushed LTS branch.
- PR URL recorded.
- `babysit-pr` session run until a strict terminal condition.
- CI/review/mergeability outcome recorded.

Commands:

```bash
git push origin codex/lts-modernization-long-horizon
```

Open a PR with the repository's normal GitHub workflow. Then use:

```bash
python3 .codex/skills/babysit-pr/scripts/gh_pr_watch.py --pr auto --watch
```

Stop-and-fix:

- If CI fails from branch changes, diagnose, patch, commit, push, and resume
  `babysit-pr`.
- If failures are likely flaky and the watcher offers retry, use the watcher
  retry path up to its budget.
- If review feedback is actionable and safe, address it, push, and resume
  babysitting.
- Stop only when the PR is ready/merged/closed or user help is required.

## Milestone 3: Rebase or Merge Remove-Symphony onto Post-LTS Main

Goal: Prepare `codex/remove-symphony` after LTS has landed or is otherwise
accepted as the base.

Deliverables:

- Fresh `origin/main` containing the LTS merge, or documented user-approved
  alternative base.
- Remove-Symphony branch updated from that base.
- Conflicts resolved in favor of removing Symphony pilot tooling.
- No accidental resurrection of `tools/symphony` or Symphony pilot commands.

Preferred conflict policy:

- Deleted Symphony pilot tooling stays deleted.
- Keep non-Symphony harness improvements from post-LTS `main`.
- Keep docs accurate: if a link points to removed Symphony pilot docs or tools,
  remove or rewrite it.

Validation searches:

```bash
rg -n "tools/symphony|run_symphony|Symphony pilot|STAGING_PILOT_RUNBOOK|SPEC_GAP_SCORECARD|SPEC_AUDIT" .
git status --short --branch
git diff --check
```

Stop-and-fix:

- Any remaining Symphony reference must be either intentionally historical or
  removed.
- If unclear whether a reference should survive, stop and ask.

## Milestone 4: Validate Remove-Symphony

Goal: Prove the removal branch is clean on the post-LTS base.

Validations:

```bash
bash ./scripts/ci/pre_pr_quick.sh
PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh
```

Optional focused checks if files touched by the removal require them:

```bash
composer test
composer check:agent-harness-readiness
```

Stop-and-fix:

- If CI references removed Symphony paths, update CI/docs/scripts rather than
  restoring Symphony pilot code.
- If non-Symphony failures appear, diagnose whether they came from the rebase or
  from current `main`; document before widening scope.

## Milestone 5: Push/Open Remove-Symphony PR and Babysit

Goal: Push the updated removal branch and babysit it to a terminal ready/merged
state.

Deliverables:

- Pushed Remove-Symphony branch.
- PR URL recorded.
- `babysit-pr` used until ready/merged/closed or blocked.
- Final documentation entry records the resulting mainline state.

Commands:

```bash
git push origin codex/remove-symphony
python3 .codex/skills/babysit-pr/scripts/gh_pr_watch.py --pr auto --watch
```

Stop-and-fix:

- Address branch-related CI or review failures with narrow commits.
- Resume PR babysitting after every push.
- Stop only on a strict babysitter terminal condition.
