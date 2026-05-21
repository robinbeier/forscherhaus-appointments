# ROB-292 Production Security Hardening Prompt

## Purpose

Coordinate the production-server hardening follow-ups from ROB-292 after the
ROB-393 emergency hotfix. This is a long-horizon coordination package, not a
single broad implementation issue.

The agent must work item to item. Each real fix stays tied to its Linear issue,
its own branch/PR where repo changes are involved, and its own production gate
when live server changes are required.

Before each milestone, reread this file plus `Plan.md`, `Implement.md`, and
`Documentation.md`.

## Source Documents

- `docs/ops/agent-operations.md`
- `docs/observability.md`
- `docs/deployment.md`
- `docs/uptime-kuma.md`
- `scripts/ops/README.md`
- `WORKFLOW.md`
- `AGENTS.md`
- Linear: ROB-292, ROB-393, ROB-394, ROB-395, ROB-396, ROB-397

## Starting State

- ROB-393 was handled as the isolated urgent production fix.
- The live Apache guard now blocks public access to sensitive app paths.
- Server-side session and cache artifacts were quarantined as root-only after
  the block was verified.
- The durable repo-side follow-up is still needed so the same exposure does not
  drift back in unnoticed.

## Goals

1. Turn ROB-393 evidence into durable, redacted regression gates.
2. Persist the production-server threat model without storing secrets or raw
   production config.
3. Evaluate baseline server posture items after the urgent exposure is closed.
4. Extend the production doctor so future agents can see safe posture facts
   without ad hoc SSH probes.

## Non-Goals

- Do not reopen ROB-393 unless new evidence shows the live block regressed.
- Do not combine ROB-394 through ROB-397 into one broad PR.
- Do not perform live server, Apache, SSH, firewall, Kuma, Sentry, or deploy
  write changes unless the current milestone explicitly reaches a live gate and
  the operator approves the exact write scope.
- Do not print or store secrets, DB rows, session contents, cache contents,
  Push URLs, health tokens, Sentry DSNs, `/etc/fh` contents, `config.php`
  contents, raw Kuma DB rows, or raw production config.

## Hard Constraints

- Default production access is read-only.
- Live changes are milestone gates, not implicit permissions.
- Every repo implementation must keep diffs small and reviewable.
- Each milestone must update `Documentation.md` with status, decisions,
  validation, and remaining risk.
- Any validation failure must be repaired or recorded as a blocker before the
  next milestone starts.

## Done When

- ROB-394 ships durable sensitive-path regression checks.
- ROB-395 ships a production-server threat model document.
- ROB-396 has a documented posture decision and any approved live changes are
  split into their own gate.
- ROB-397 ships redacted posture checks in the production operations harness.
- `Documentation.md` records shipped work, live gates, validation, and residual
  risk.
