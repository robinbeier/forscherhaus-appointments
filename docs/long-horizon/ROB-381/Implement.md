# ROB-381 Long-Horizon Implementation Instructions

## Start Here

1. Read `Prompt.md`.
2. Read `Plan.md`.
3. Read `Documentation.md`.
4. Read `docs/monitoring/audit-ROB-381.md` and
   `docs/monitoring/target-concept.md`.
5. Confirm the active Linear roadmap issue for the milestone.
6. Work only on the current milestone unless the plan explicitly says the next
   milestone is unblocked.

## Execution Rules

- Treat `Plan.md` as the source of truth for sequence and gates.
- Keep diffs scoped to the current milestone.
- Add or update tests for bugfixes where possible.
- Run validation after each milestone and repair failures immediately.
- Update `Documentation.md` after each milestone, including failed attempts.
- Preserve unrelated user changes.
- Do not edit `system/` unless applying an explicit upstream patch.
- Use CodeIgniter migrations for schema changes, though this roadmap should not
  need schema changes.
- Do not commit secrets or local credentials.

## Linear Rules

- Treat ROB-382 as already shipped by PR #280. Keep it as historical context;
  begin new repo-only implementation work with ROB-383 unless the operator
  explicitly redirects.
- Use the roadmap follow-up issues for later milestones.
- Keep comments concise and use a single Codex Workpad comment when updating an
  issue directly.
- If Linear is unavailable, continue repo work only when the issue boundary is
  already clear, then document the pending Linear update in `Documentation.md`.

## Sentry Token Handling

- Never ask the operator to paste a Sentry Security/API token into chat.
- Never write the token to `Prompt.md`, `Plan.md`, `Implement.md`,
  `Documentation.md`, Linear, shell scripts, git, or docs.
- Prefer a connector or secure local environment variable configured outside the
  repo.
- When verifying live Sentry, print only sanitized status such as organization
  reachable, project reachable, issue count, or event-delivery result.
- If the token is unavailable or returns unauthorized, stop live Sentry work and
  continue only repo-side Sentry hardening.

## Production Access Rules

Default production access is read-only:

- allowed: service status, health endpoints, disk/memory/timer/container status,
  sanitized log summaries, existing read-only ops scripts;
- not allowed: config changes, DB writes, Kuma writes, Sentry writes, deployment,
  monitor creation/deletion, printing secrets, printing DB rows.

Live write gates require explicit operator approval and a documented rollback or
stop plan.

The initial autonomous implementation run is repo-only. Server, Kuma, and
Sentry live writes are milestone gates, not implicit permissions. If a repo
change needs live Server/Kuma/Sentry verification, record the exact gate and
stop instead of mutating production.

## PR Babysitting

- Use a separate branch and PR for each implementation milestone unless the
  operator explicitly approves a combined PR.
- After opening or updating a PR, run
  `.codex/skills/babysit-pr/scripts/gh_pr_watch.py --pr auto --watch` from the
  PR branch worktree.
- Do not stop watching merely because CI is pending or idle.
- Address actionable CI failures and review comments in follow-up commits,
  push them, and restart the watcher.
- Stop only when the PR is merged, closed, ready-to-merge, or blocked on
  explicit human input.

## Validation Loop

For every milestone:

1. Run the narrowest relevant checks.
2. Run `git diff --check`.
3. Run targeted secret/PII checks over changed docs/scripts.
4. Run `bash ./scripts/ci/pre_pr_quick.sh` when code/scripts changed.
5. Run the full pre-PR gate before marking review-ready, unless blocked.
6. Record validation in `Documentation.md`.

## Stop Conditions

Stop and ask before continuing if:

- a step would reveal or store a secret;
- a step would copy production DB rows or customer data;
- a filter could hide real app errors broadly;
- live Server, Kuma, or Sentry write access is required;
- validation fails for a reason that cannot be repaired in the current
  milestone;
- the current branch contains overlapping user changes that make safe edits
  ambiguous.

## Final Reporting

Final output must include:

- completed milestones;
- changed files;
- validation run and failures;
- Linear issue IDs updated or still pending;
- live gates intentionally not executed;
- remaining risks and next action.
