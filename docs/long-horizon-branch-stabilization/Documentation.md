# Documentation: Branch Stabilization and Mainline Merge

## Current Status

Status: Milestone 1 validation complete. LTS modernization is current with
`origin/main` and ready for push/PR babysitting.

Created: 2026-05-19.

Current milestone: Milestone 2 - Push/Open LTS PR and Babysit.

Next action: commit this status update, push the LTS branch, open the PR, and
use `babysit-pr` until CI reaches a terminal state.

## Locked Decisions

| Date | Decision | Reason |
| --- | --- | --- |
| 2026-05-19 | Merge `codex/lts-modernization-long-horizon` before `codex/remove-symphony`. | LTS contains foundational runtime, deployment, server, Kuma, and ops-harness work. Remove-Symphony deletes files that LTS still touches, so merging LTS first avoids accidental Symphony resurrection during the larger merge. |
| 2026-05-19 | Keep the two branches as separate PRs. | Smaller, reviewable PRs preserve the already-reviewed Remove-Symphony intent and keep branch-specific CI failures easier to diagnose. |
| 2026-05-19 | Use `babysit-pr` for PR monitoring after push/PR creation. | The task needs continuous CI/review/mergeability monitoring and repair loops after each PR is opened. |
| 2026-05-19 | Do not mutate production during branch stabilization. | Production has already been accepted; this task is about mainline integration and CI stability, not ops changes. |

## Initial Branch Facts

Observed before creating this task:

- `origin/main`: `0f3b64c4af23358184b0b125d2f97b1c2a4d8d34`.
- `codex/lts-modernization-long-horizon`: `423c2c8a33bdbe31d1bb67f81759f17c5e01e93a`, `32` commits ahead of `origin/main`.
- `codex/remove-symphony`: `fd9118743b4e4fa6372ec2d77df7a3238073798c`, `1` commit ahead of `origin/main`.
- Overlapping files:
  - `AGENTS.md`
  - `README.md`
  - `docs/agent-harness-index.md`
  - `tools/symphony/package.json`
  - `tools/symphony/package-lock.json`

These facts must be refreshed when implementation begins.

## Milestone Status

| Milestone | Status | Evidence |
| --- | --- | --- |
| 0. Baseline and Branch Inventory | Complete | Fresh `origin` refs checked on 2026-05-19. Worktree clean; `origin/main` `0f3b64c4af23358184b0b125d2f97b1c2a4d8d34`; LTS `003cca218b6fd1e7ba02eebdc13417726e7f1c4d`, `0/33`; Remove-Symphony `fd9118743b4e4fa6372ec2d77df7a3238073798c`, `0/1`; overlapping paths recorded. |
| 1. Stabilize LTS Modernization on Current Main | Complete | LTS has no behind commits relative to `origin/main`; no merge conflict resolution needed. `git diff --check`, ops shell syntax checks, production read-only doctor/validate, `bash ./scripts/ci/pre_pr_quick.sh`, and `PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh` passed. |
| 2. Push/Open LTS PR and Babysit | In progress | Requires LTS validation status commit, branch push, PR creation, and `babysit-pr`. |
| 3. Rebase or Merge Remove-Symphony onto Post-LTS Main | Not started | Requires LTS outcome first. |
| 4. Validate Remove-Symphony | Not started | Requires removal branch updated on the post-LTS base. |
| 5. Push/Open Remove-Symphony PR and Babysit | Not started | Requires removal branch validation and PR creation. |

## Known Risks and Follow-Ups

- The Remove-Symphony branch deletes tooling that LTS still touched in
  `tools/symphony/package.json` and `tools/symphony/package-lock.json`; resolve
  later conflicts in favor of removal after LTS has landed.
- Full local gates may take a long time and may require Docker/network access.
  Do not replace them with weaker checks unless the exception is documented.
- `babysit-pr` may require GitHub permissions and `gh` auth. If permissions
  block reruns, review handling, or mergeability checks, stop and ask.
- Do not let a green local run hide PR-level CI failures; PR babysitting remains
  required after push.

## Status Update Protocol

Append entries in this format:

```text
YYYY-MM-DDTHH:MM:SSZ - Milestone N - Summary
Branch/base: <branch> on <base sha>
Validation: <commands and result>
Decision: <decision or none>
Next: <next action>
```

## Validation Log

- 2026-05-19T06:00:16Z - Milestone 0 - Refreshed branch baseline.
  Branch/base: `codex/lts-modernization-long-horizon` at
  `003cca218b6fd1e7ba02eebdc13417726e7f1c4d` on `origin/main`
  `0f3b64c4af23358184b0b125d2f97b1c2a4d8d34`.
  Validation: `git status --short --branch` clean; `git fetch origin --prune`
  passed; `git rev-list --left-right --count` returned `0 33` for LTS and
  `0 1` for Remove-Symphony; overlap check returned `AGENTS.md`, `README.md`,
  `docs/agent-harness-index.md`, `tools/symphony/package-lock.json`, and
  `tools/symphony/package.json`.
  Decision: no merge-from-main needed for LTS before validation because it is
  not behind `origin/main`.
  Next: run LTS syntax checks, local gates, and read-only production smokes.
- 2026-05-19T06:06:06Z - Milestone 1 - Validated LTS modernization locally.
  Branch/base: `codex/lts-modernization-long-horizon` at
  `003cca218b6fd1e7ba02eebdc13417726e7f1c4d` on `origin/main`
  `0f3b64c4af23358184b0b125d2f97b1c2a4d8d34`.
  Validation: `git diff --check` passed; `bash -n scripts/ops/prod_doctor.sh
  scripts/ops/prod_logs_summary.sh scripts/ops/prod_validate_after_change.sh
  scripts/ops/install_prod_agent_readme.sh scripts/ops/lib/prod_common.sh`
  passed; `bash scripts/ops/prod_doctor.sh --prod-ssh-target
  root@188.245.244.123` passed with app/deep-health/PDF/Kuma green and 12/12
  Kuma monitors green; `bash scripts/ops/prod_validate_after_change.sh
  --prod-ssh-target root@188.245.244.123` passed; `bash
  ./scripts/ci/pre_pr_quick.sh` passed; `PRE_PR_RUN_COVERAGE=1 bash
  ./scripts/ci/pre_pr_full.sh` passed including integration smoke 16/16, deep
  runtime suites 5/5, and coverage delta `+7.1175pp`.
  Decision: proceed to LTS PR creation; no code changes were needed beyond this
  status documentation.
  Next: commit documentation status, push branch, open PR, and run `babysit-pr`.
