# Documentation: Branch Stabilization and Mainline Merge

## Current Status

Status: Task scaffold created. Branch stabilization has not started.

Created: 2026-05-19.

Current milestone: Milestone 0 - Baseline and Branch Inventory.

Next action: refresh `origin`, confirm clean worktree, and record current SHAs
for `origin/main`, `codex/lts-modernization-long-horizon`, and
`codex/remove-symphony`.

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
| 0. Baseline and Branch Inventory | Not started | Requires fresh `origin` refs and current SHA/diff inventory. |
| 1. Stabilize LTS Modernization on Current Main | Not started | Requires updated LTS branch, conflict resolution, and local gates. |
| 2. Push/Open LTS PR and Babysit | Not started | Requires LTS validation and PR creation. |
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
