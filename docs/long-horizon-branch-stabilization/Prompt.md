# Prompt: Branch Stabilization and Mainline Merge

## Source Pattern

This long-horizon task follows OpenAI's four-file durable memory pattern for
long-running Codex work: a stable spec, milestone plan, execution runbook, and
live documentation log.

Reference: https://developers.openai.com/blog/run-long-horizon-tasks-with-codex

## Mission

Prepare and land the two active stabilization branches into `main` with minimal
risk and maximum CI confidence:

1. `codex/lts-modernization-long-horizon`
2. `codex/remove-symphony`

The branches must land sequentially. First stabilize and merge the LTS
modernization branch. Then rebase or merge the Symphony-removal branch onto the
new `main`, resolve any conflicts in favor of the intended removal, validate,
open/push the PR, and babysit CI/review until the PR is ready to merge or
requires operator help.

## Current Known State

- Repository: `forscherhaus-appointments`.
- Current working branch when this task was created:
  `codex/lts-modernization-long-horizon`.
- Current base observed before this task:
  `origin/main` at `0f3b64c4af23358184b0b125d2f97b1c2a4d8d34`.
- `codex/lts-modernization-long-horizon` was observed as `32` commits ahead of
  `origin/main`.
- `codex/remove-symphony` was observed as `1` commit ahead of `origin/main`.
- `codex/remove-symphony` has already been reviewed, but must still be
  revalidated after `lts-modernization` lands.
- Overlapping paths include:
  - `AGENTS.md`
  - `README.md`
  - `docs/agent-harness-index.md`
  - `tools/symphony/package.json`
  - `tools/symphony/package-lock.json`

## Goals

1. Bring `codex/lts-modernization-long-horizon` up to date with current
   `origin/main` without broadening its scope.
2. Resolve any conflicts conservatively and preserve the accepted LTS,
   deployment, server, Kuma, and production-operations harness changes.
3. Run the strongest practical local validation before pushing.
4. Push/open the LTS modernization PR and use `babysit-pr` to watch CI,
   reviews, and mergeability until the PR is ready, merged, closed, or blocked.
5. After LTS lands, bring `codex/remove-symphony` up to date with the new
   `main`.
6. Resolve Symphony conflicts in favor of the intended removal. Do not allow the
   LTS branch's earlier `tools/symphony` package changes to resurrect removed
   Symphony pilot tooling.
7. Run local validation for the removal branch, push/open its PR, and use
   `babysit-pr` until it reaches a terminal ready/merged/blocked state.

## Non-Goals

- Do not add new app features.
- Do not change production server state.
- Do not alter the deployment model, Kuma monitor definitions, DB schema, or
  provider snapshot policy as part of this merge stabilization.
- Do not combine both branches into one PR unless the user explicitly changes
  the strategy.
- Do not silently keep Symphony pilot files because of merge convenience.

## Hard Constraints

- Keep production secrets, DB contents, Push URLs, tokens, and host-local config
  out of Git, Linear, PR comments, and logs.
- Work on one branch at a time.
- Never use destructive git commands such as `git reset --hard` or
  `git checkout --` without explicit user approval.
- If unrelated local changes are present, stop and ask before merging, editing,
  or committing.
- If validation fails, diagnose and fix the branch before opening/pushing a PR
  unless the failure is clearly unrelated and documented.
- During PR babysitting, follow
  `.codex/skills/babysit-pr/SKILL.md`.

## Deliverables

- A validated PR for `codex/lts-modernization-long-horizon` into `main`.
- A validated PR for `codex/remove-symphony` into `main`, opened only after the
  LTS modernization branch is ready/merged or the user explicitly changes the
  order.
- `Documentation.md` updated after each milestone with:
  - branch and base SHAs,
  - conflicts and resolutions,
  - validation commands and results,
  - PR URLs,
  - CI/review babysitting outcome,
  - blockers or follow-ups.

## Done When

This task is complete when:

- LTS modernization is merged into `main` or is green, mergeable, review-clean,
  and explicitly ready for the operator to merge.
- Remove-Symphony has been rebuilt on the post-LTS `main` and is merged or is
  green, mergeable, review-clean, and explicitly ready for the operator to
  merge.
- `Documentation.md` records the final shipped state and any remaining manual
  merge/approval step.
