# ROB-381 Long-Horizon Monitoring Implementation Prompt

## Purpose

Implement the monitoring roadmap from ROB-381 end to end with small, reviewable,
low-risk changes. The run must preserve the audit boundary: no secrets, no raw
production data, no Push URLs, no tokens, no raw DB rows, and no broad monitoring
tool expansion unless a concrete gap proves Uptime Kuma, Sentry, health
endpoints, logs, and existing ops scripts are insufficient.

This file is the durable specification for the long-horizon Codex task. The
agent must reread this file, `Plan.md`, `Implement.md`, and
`Documentation.md` before each substantial milestone.

## Source Documents

- `docs/monitoring/audit-ROB-381.md`
- `docs/monitoring/target-concept.md`
- `docs/observability.md`
- `docs/uptime-kuma.md`
- `docs/ops/agent-operations.md`
- `scripts/ops/README.md`
- `scripts/ops/uptime-kuma.monitors.yml`
- `scripts/ops/uptime-kuma-push.env.example`
- `scripts/ops/uptime-kuma-crontab.example`
- `WORKFLOW.md`
- `AGENTS.md`
- Linear: ROB-381, ROB-382, ROB-383, ROB-384, ROB-385, ROB-386,
  ROB-387, ROB-388, ROB-367

## Goals

1. Finish the monitoring roadmap from `docs/monitoring/target-concept.md`.
2. Treat ROB-382 as completed by PR #280. Do not reopen or broaden it unless
   new evidence proves the shipped classifier is wrong.
3. Start repo-only implementation with ROB-383: Sentry redaction and event
   context hardening.
4. Harden Sentry redaction and event context before expanding capture scope.
5. Clarify Uptime Kuma deep-health and Push-monitor boundaries without exposing
   secrets.
6. Split backup creation freshness from restore-verify freshness if the current
   signal is ambiguous.
7. Clean up monitor/runtime naming drift after the Ubuntu 26.04 rebuild.
8. Treat parent booking-confirmation PDF synthetic monitoring as optional and
   decision-gated.
9. Feed the final observed production state into ROB-367 or a successor issue.

## Non-Goals

- Do not use Uptime Kuma as generic exception tracking.
- Do not use Sentry as an availability monitor.
- Do not add Prometheus, Grafana, Loki, ELK, OpenTelemetry collectors, or other
  new monitoring products unless the existing stack demonstrably cannot cover a
  high-value signal.
- Do not commit secrets, tokens, DSNs, Push URLs, raw Kuma database exports,
  production config, DB rows, customer data, appointment hashes, or raw request
  bodies.
- Do not perform live server, Kuma, or Sentry write changes without reaching the
  explicit gate for that milestone.
- Do not combine unrelated roadmap items into one broad PR if separate review is
  safer.

## Hard Constraints

- Production access is read-only until a milestone explicitly reaches a live
  gate and the operator has approved the required write scope.
- Sentry Security/API tokens must never be pasted into chat, Linear, docs, git,
  shell history visible in logs, or command output. Use a secure local
  environment or connector secret only.
- Kuma Push URLs and health tokens stay host-local or in Kuma state only.
- Expected business conflicts, validation errors, invalid login attempts,
  CAPTCHA failures, scanner 404s, unauthorized health probes, and known proxy
  probes must not become Sentry issues or server-down alerts.
- Every milestone must update `Documentation.md` with status, decisions,
  validation, and remaining risk.
- If validation fails, repair or document the blocker before moving to the next
  milestone.
- Each separate implementation PR must be watched with
  `.codex/skills/babysit-pr/SKILL.md` until it is merged, closed,
  ready-to-merge, or blocked on explicit human input.

## Deliverables

- Updated repo code, scripts, tests, and documentation for the roadmap.
- Linear issues created for each implementation item and kept aligned with
  shipped work.
- One or more reviewable PRs, kept sequential according to repo workflow.
- Evidence of validation per milestone.
- No secret or production-data leakage in git diff, docs, Linear, or final
  report.

## Done When

- ROB-382 noise classification remains implemented and validated from PR #280.
- Sentry event redaction/context hardening is implemented and validated.
- Deep-health/Kuma secret-boundary documentation and templates are consistent.
- Backup freshness signals are clearly split or the current single signal is
  explicitly justified.
- Runtime monitor naming reflects the actual production runtime.
- Optional booking-confirmation PDF monitoring has a documented go/no-go
  decision.
- ROB-367/post-rebuild observation has the final monitoring findings linked or
  summarized.
- `Documentation.md` records what shipped, what was deferred, and why.
- Relevant narrow tests plus the required pre-PR gate have been run or a clear
  blocker is recorded.
