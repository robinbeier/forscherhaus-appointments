# ROB-292 Production Security Hardening Documentation Log

## Current Status

- Status: ROB-394 and ROB-395 completed; ROB-396 decision-ready.
- Scope: ROB-394 through ROB-397, milestone-gated after the ROB-393 live
  hotfix.
- Branch: `codex/rob-292-prod-security-hardening`.
- Live gates: none executed by this package.

## Roadmap Issue Status

- ROB-292: broad hardening parent.
- ROB-393: completed urgent live hotfix for public `storage`/session exposure.
- ROB-394: completed, add production sensitive-path regression gates.
- ROB-395: completed, persist production server threat model.
- ROB-396: decision-ready, decide baseline server posture for headers, SSH,
  firewall.
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

- Status: completed by PR #296.
- Result:
  - Added a shared redacted sensitive-path helper.
  - Wired the helper into `prod_doctor.sh` and `prod_validate_after_change.sh`.
  - Added focused regression coverage for HTTP 2xx exposure and redacted output.

### Milestone 2 - ROB-395 Production Server Threat Model

- Status: completed by PR #297.
- Result:
  - Added `docs/security/production-server-threat-model.md`.
  - Captured server-specific assets, trust boundaries, attacker stories,
    findings/gaps, and follow-up backlog without secrets or raw production
    config.

### Milestone 3 - ROB-396 Baseline Server Posture Decision

- Status: decision-ready.
- Result:
  - Added `docs/security/production-server-posture-decision.md`.
  - Re-checked headers, SSH policy classes, firewall status, public listener
    classes, and loopback boundaries through redacted read-only evidence.
  - Split live hardening into separate future gates instead of executing
    server writes in ROB-396.

### Milestone 4 - ROB-397 Redacted Prod Doctor Posture Checks

- Status: pending.
- Next action:
  - Extend `prod_doctor.sh` or related helpers with the safe posture classes
    defined by ROB-396.

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

### 2026-05-21 - ROB-394 Completion

- PR #296 merged.
- Validation:
  - `bash -n` for changed shell scripts passed.
  - Focused PHPUnit coverage for the sensitive-path helper passed.
  - `bash ./scripts/ci/pre_pr_quick.sh` passed.

### 2026-05-21 - ROB-395 Evidence Refresh

- `prod_logs_summary.sh --since "24 hours ago"` completed read-only and
  redacted, with no recent service warnings or app-error-like lines reported.
- `prod_doctor.sh` completed read-only and redacted, confirming active core
  services, green Kuma latest state, healthy endpoints, and host Node/npm
  absent.
- The new ROB-394 sensitive-path helper was not yet present on production,
  which is expected before a separate deploy gate.

### 2026-05-21 - ROB-395 Documentation Validation

- Created `docs/security/production-server-threat-model.md`.
- Validation:
  - `git diff --check` passed.
  - Prettier markdown check passed.
  - Targeted secret-boundary grep had no secret-value matches.
  - `bash ./scripts/ci/pre_pr_quick.sh` passed.

### 2026-05-21 - ROB-396 Read-Only Posture Evidence

- `prod_logs_summary.sh --since "24 hours ago"` completed read-only and
  redacted, with no recent service warnings or app-error-like lines reported.
- `prod_doctor.sh` completed read-only and redacted, confirming active core
  services, green Kuma latest state, healthy endpoints, certbot/timer presence,
  and host Node/npm absent.
- A focused sanitized SSH snapshot classified header presence, SSH effective
  policy flags, UFW status, expected public listener classes, and loopback-only
  internal services without printing raw config or secrets.
- Decision: ROB-396 remains docs-only. Header, SSH, HSTS, CSP and UFW changes
  are future separate gates with explicit approval.
