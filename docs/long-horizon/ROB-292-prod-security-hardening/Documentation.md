# ROB-292 Production Security Hardening Documentation Log

## Current Status

- Status: coordination package created.
- Scope: ROB-394 through ROB-397, milestone-gated after the ROB-393 live
  hotfix.
- Branch: `codex/rob-292-prod-security-hardening`.
- Live gates: none executed by this package.

## Roadmap Issue Status

- ROB-292: broad hardening parent.
- ROB-393: completed urgent live hotfix for public `storage`/session exposure.
- ROB-394: pending, add production sensitive-path regression gates.
- ROB-395: pending, persist production server threat model.
- ROB-396: pending, decide baseline server posture for headers, SSH, firewall.
- ROB-397: pending, extend redacted production doctor with posture checks.

## Decisions

### 2026-05-21 - Hybrid Long-Horizon Model

Use a long-horizon coordination package for ROB-394 through ROB-397, but keep
each real fix item-to-item. The package coordinates order, boundaries, and
validation; it does not authorize broad combined implementation.

### 2026-05-21 - ROB-393 Remains Isolated

ROB-393 was handled as an urgent isolated live fix. Future milestones may use
ROB-393 evidence, but must not reopen or broaden it unless a regression is
observed.

### 2026-05-21 - Live Writes Stay Gated

The ROB-393 live approval does not carry forward. ROB-394 through ROB-397 start
repo-only or read-only by default. Any Apache, SSH, firewall, deploy, Kuma, or
Sentry write requires a new explicit operator approval and rollback/stop plan.

## Milestone Log

### Milestone 0 - Current Baseline

- Status: completed before package creation.
- Evidence summary:
  - Sensitive paths were blocked after ROB-393.
  - Session and cache artifacts were quarantined root-only.
  - Post-change production validation passed.
  - Uptime Kuma was green after the hotfix.
- Residual risk:
  - The Apache guard is host-local until repo-side regression gates and docs
    are added.

### Milestone 1 - ROB-394 Sensitive-Path Regression Gates

- Status: pending.
- Next action:
  - Design and implement redacted sensitive-path checks in the ops harness or a
    shared helper.

### Milestone 2 - ROB-395 Production Server Threat Model

- Status: pending.
- Next action:
  - Add durable server threat-model documentation, linking ROB-393 through
    ROB-397.

### Milestone 3 - ROB-396 Baseline Server Posture Decision

- Status: pending.
- Next action:
  - Take read-only posture snapshots and produce an implement/defer/no-change
    decision.

### Milestone 4 - ROB-397 Redacted Prod Doctor Posture Checks

- Status: pending.
- Next action:
  - Extend `prod_doctor.sh` or related helpers after ROB-394 defines the safe
    sensitive-path classification.

## Validation Log

### 2026-05-21 - Package Creation

- Created:
  - `docs/long-horizon/ROB-292-prod-security-hardening/Prompt.md`
  - `docs/long-horizon/ROB-292-prod-security-hardening/Plan.md`
  - `docs/long-horizon/ROB-292-prod-security-hardening/Implement.md`
  - `docs/long-horizon/ROB-292-prod-security-hardening/Documentation.md`
- Validation:
  - `git diff --check` passed.
  - Targeted secret-boundary grep over the package found only intentional
    policy terms and no secret values.
