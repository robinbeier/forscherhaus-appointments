# ROB-381 Long-Horizon Monitoring Implementation Log

## Current Status

- Status: prepared, not yet executing implementation milestones.
- Branch: `codex/rob-381-monitoring-audit`.
- Scope: autonomous implementation roadmap for the ROB-381 monitoring audit,
  initially repo-only.
- Live gates: Server/Kuma/Sentry writes are not approved by default.
- Current start point for new work: ROB-383 Sentry redaction and event-context
  hardening.

## Roadmap Issue Status

- ROB-381: audit and target concept source issue.
- ROB-382: completed by PR #280, app-log noise classification.
- ROB-383: Sentry redaction and event-context hardening.
- ROB-384: Kuma deep-health secret-boundary documentation and header audit.
- ROB-385: Split backup creation freshness from restore verification signal.
- ROB-386: Clean up production monitor runtime-name drift.
- ROB-387: Decide privacy-safe parent booking confirmation PDF synthetic.
- ROB-388: Long-horizon implementation coordinator for the full roadmap.
- ROB-367: existing post-rebuild observation issue to receive final monitoring
  findings.
- New roadmap issues: created in Linear on 2026-05-20.

## Decisions

### 2026-05-20 - Long-Horizon File Location

Use `docs/long-horizon/ROB-381/` for the long-horizon task files instead of the
repo root. This keeps the repo root focused while preserving durable task
memory in versioned docs.

### 2026-05-20 - Initial Execution Boundary

Plan the implementation as repo-only first. Server, Kuma, and Sentry live write
changes are explicit gated milestones with stop conditions.

### 2026-05-20 - Sentry Token Handling

The operator has a Sentry Security/API token, but it must not be pasted into
chat, Linear, docs, or git. Live Sentry verification requires a secure local
environment or connector path. Without that, only repo-side Sentry hardening is
in scope.

### 2026-05-20 - Tooling Choice

Do not introduce a new monitoring product now. Uptime Kuma, Sentry, app health
endpoints, logs, release gates, and existing ops scripts remain the target
architecture unless a later milestone proves a high-leverage gap.

### 2026-05-20 - ROB-382 Shipped Before Long-Horizon Run

ROB-382 was implemented separately in PR #280 and moved to Done in Linear.
The shipped implementation keeps runtime rate limiting unchanged and narrows the
change to App-Log monitor classification, production summary/validation script
alignment, docs, and regression tests. The long-horizon implementation now
starts with ROB-383.

### 2026-05-20 - PR Babysitting Requirement

Every separate implementation PR in this roadmap must be watched with
`.codex/skills/babysit-pr/SKILL.md` until it is merged, closed,
ready-to-merge, or blocked on human input. Automated review comments must be
handled before continuing to the next milestone.

## Milestone Log

### Milestone 0 - Preflight And Roadmap Alignment

- Status: completed for the repo-only start.
- Done:
  - ROB-381 audit documents created.
  - Long-horizon task files created.
  - Sentry token handling boundary recorded.
  - Linear follow-up issues ROB-383 through ROB-388 created.
  - Branch fast-forwarded to `origin/main` after PR #280.
  - ROB-382 completion recorded as shipped context.
- Pending:
  - None for the repo-only ROB-383 start.

### Milestone 1 - ROB-382 App-Log Noise Classification

- Status: completed separately by PR #280.
- Result: shipped as narrow repo-side monitor classification; no runtime
  rate-limit bypass.
- Next: do not reopen unless new monitor evidence proves a regression.

### Milestone 2 - Sentry Redaction And Event Context Hardening

- Status: next repo-only implementation milestone.
- Gate: secure Sentry token/connector needed only for live verification, not for
  repo-side code hardening.

### Milestone 3 - Deep Health And Kuma Secret Boundary Cleanup

- Status: not started.

### Milestone 4 - Backup Creation Vs Restore-Verify Freshness

- Status: not started.

### Milestone 5 - Runtime Monitor Naming Drift Cleanup

- Status: not started.

### Milestone 6 - Optional Booking Confirmation PDF Synthetic Decision

- Status: not started.

### Milestone 7 - ROB-367 Observation Integration

- Status: not started.

### Milestone 8 - Final Review Package

- Status: not started.

## Validation Log

### 2026-05-20 - Repo-Only Start Preflight

- `git fetch --prune origin`
- `git pull --ff-only origin main`
- `git status --short --branch`
- `gh auth status`
- `docker compose config --services`
- `git diff --check`
- secret/PII grep over `docs/monitoring` and `docs/long-horizon/ROB-381`
  for Push URLs, tokens, raw IPs, email addresses, and key material
- `bash ./scripts/ci/pre_pr_quick.sh`

Result: passed. The only secret-scan text hit was an intentional documentation
warning that `config.php` must not be printed.

## Known Risks

- Linear connector may be intermittently unavailable.
- Live Sentry audit previously returned unauthorized with the available local
  environment; this must be fixed through secure token configuration, not by
  pasting the token into the task.
- Sentry live verification remains gated on a secure token/connector path.

## Final Shipped State

Not shipped yet.
