# Implement: Execution Instructions

## Required Reading Before Work

At the start of every work session:

1. Read `Prompt.md`.
2. Read `Plan.md`.
3. Read `Documentation.md`.
4. Read `.codex/skills/babysit-pr/SKILL.md` before opening or monitoring a PR.
5. Check `git status --short --branch`.
6. Identify the current milestone and stay inside it.

## Work Loop

For each milestone:

1. State the current branch and milestone.
2. Gather facts with non-destructive commands.
3. Make the smallest branch update needed.
4. Resolve conflicts according to the policies in `Plan.md`.
5. Run the milestone validations.
6. Fix validation failures before proceeding.
7. Update `Documentation.md` with branch SHAs, conflicts, validation results,
   PR URLs, and next action.

## Branch Order

Strict order:

1. `codex/lts-modernization-long-horizon`
2. `codex/remove-symphony`

Do not start the Remove-Symphony update until LTS modernization is merged,
ready-to-merge, or the user explicitly changes the handoff point.

## Git Safety

- Never use `git reset --hard` or destructive checkout commands without
  explicit user approval.
- If unrelated local changes exist, stop and ask.
- Prefer merge-from-main or rebase only when the branch policy is clear and the
  worktree is clean.
- Commit conflict resolutions and validation fixes with scoped messages.
- Keep both PRs separate.

## LTS Branch Rules

Preserve:

- Node 24 target.
- PHP 8.5 smoke/support work.
- Artifact deployment changes.
- Ubuntu 26.04 same-server rebuild docs/evidence.
- Uptime Kuma restore and Push monitor scripts.
- Production operations harness.

Do not add new product features.

## Remove-Symphony Branch Rules

Preserve the intent of removing obsolete Symphony pilot tooling.

If conflicts involve files under `tools/symphony`, `scripts/symphony`,
`docs/symphony`, or Symphony pilot CI/scripts, resolve toward removal unless the
user explicitly says otherwise.

After conflict resolution, search for surviving references:

```bash
rg -n "tools/symphony|run_symphony|Symphony pilot|STAGING_PILOT_RUNBOOK|SPEC_GAP_SCORECARD|SPEC_AUDIT" .
```

Classify every remaining reference as:

- intentionally historical, or
- stale and removed in the branch.

## Validation Defaults

Before PR:

```bash
git diff --check
bash ./scripts/ci/pre_pr_quick.sh
PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh
```

For LTS modernization, also run syntax checks for the ops scripts:

```bash
bash -n scripts/ops/prod_doctor.sh scripts/ops/prod_logs_summary.sh scripts/ops/prod_validate_after_change.sh scripts/ops/install_prod_agent_readme.sh scripts/ops/lib/prod_common.sh
```

If production SSH is available, LTS may also run read-only checks:

```bash
bash scripts/ops/prod_doctor.sh --prod-ssh-target root@188.245.244.123
bash scripts/ops/prod_validate_after_change.sh --prod-ssh-target root@188.245.244.123
```

Do not mutate production during this branch-stabilization task.

## PR Creation and Babysitting

When pushing or opening a PR, use the repo's normal GitHub flow. Immediately
after PR creation, use the `babysit-pr` skill.

Preferred watch command:

```bash
python3 .codex/skills/babysit-pr/scripts/gh_pr_watch.py --pr auto --watch
```

If the watcher reports CI failures:

- Diagnose with `gh run view`.
- Fix branch-caused failures locally, commit, push, and resume `--watch`.
- Retry likely flaky failures only when the watcher indicates retry is
  appropriate.

If the watcher reports review feedback:

- Address actionable and correct feedback with a narrow commit.
- Push and immediately resume watching.
- Stop only when the PR is ready/merged/closed or user help is required.

## Documentation Discipline

`Documentation.md` is the live audit log. Update it:

- after baseline inventory,
- after each branch update/conflict resolution,
- after each validation run,
- after each PR is opened,
- after each babysitting terminal outcome.

Use UTC timestamps. Do not paste long logs; summarize high-signal evidence and
link commands/results by name.

## Completion Rule

Do not mark this long-horizon task complete unless:

- both branch outcomes are recorded,
- both PR babysitting outcomes are recorded,
- unresolved blockers are explicit,
- no accidental Symphony pilot resurrection is left after Remove-Symphony,
- no production mutation happened as part of this task.
