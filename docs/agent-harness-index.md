# Agent Harness Index

Purpose: route humans and agents to the right steering source without repeating
the full command and policy matrix in every top-level document.

This file stays intentionally short. It is a map, not a second runbook.

## Start Here

- If you need local setup or service endpoints: read `README.md`.
- If you are an agent executing an issue end-to-end: read `WORKFLOW.md`.
- If you need compact repo guardrails and command entry points: read
  `AGENTS.md`.
- If you need architecture and ownership scope: read `docs/architecture-map.md`
  and `docs/ownership-map.md`.

## Canonical Sources By Topic

| Topic | Canonical source | Why |
| --- | --- | --- |
| Local onboarding and quickstart | `README.md` | Operator-first entry point. |
| Agent runtime and issue-to-merge state model | `WORKFLOW.md` | Single source for active agent behavior. |
| Machine-readable cross-document workflow invariants | `.codex/contracts/agent-workflow.json` | Structured exact-head, review, public-write, evidence, and blocking-job contract. |
| Model-aware implementation delegation | `WORKFLOW.md`, `.codex/agents/implementation-worker.toml` | Primary-agent authority plus the pinned Luna worker boundary. |
| Compact guardrails and command entry points | `AGENTS.md` | Cross-topic entry point without duplicating specialist docs. |
| Core pre-PR path | `scripts/ci/pre_pr_quick.sh`, `scripts/ci/pre_pr_full.sh` | Actual executable gate logic. |
| CI gate semantics and job wiring | `.github/workflows/ci.yml` | Ground truth for job triggers, blocking status, and artifacts. |
| Local/CI root-host test prerequisites | `docs/root-host-test-harness.md` | Docker Desktop skip boundaries, required Linux-root failures, and security invariants. |
| CI performance measurement and baseline | `docs/ci-performance-baseline.md` | Versioned workload epoch, timing definitions, exclusions, and post-epoch cohort status. |
| Observability runtime ownership | `docs/observability.md` | Runtime split between release gates, Kuma, and Sentry. |
| Kuma Retention-monitor Env transaction | `docs/ops/production-kuma-monitoring-env.md` | Exact-commit helper installation, coordinated writer authority, recovery adoption, atomic Env activation, race handling, and separate Push/timer gates. |
| Production SSH operations harness | `docs/ops/agent-operations.md` | Agent-first production orientation, read-only diagnostics, and post-change validation. |
| Production Docker build-cache retention | `docs/ops/production-build-cache-retention.md` | Fixed dry-run/execute boundary, cache policy, stop conditions, and validation. |
| Production backup-set producer | `docs/ops/production-backup-set-producer.md` | Closed connection/dump authority, atomic set publication, protected handoff-to-attestation selection, disabled ROB-480 recurring continuity units, and no-gap legacy scheduler cutover. |
| Production dump-producer admission | `docs/ops/production-dump-producer-admission.md` | Pinned single-producer registry, canonical manifest/attestation binding, aggregate read-only observer, and disabled desired-state units. |
| Production legacy release provenance | `docs/ops/production-legacy-release-provenance.md` | Fixed two-target authorization, read-only inspection, aggregate output, and separately approved no-replace sidecar publication. |
| Production legacy release hold | `docs/ops/production-legacy-release-hold.md` | Permanent host-local hold for unverifiable legacy Current/Rollback archives; read-only inspect, separately approved provisioning, and retention protection. |
| Production legacy session-mode normalization | `docs/ops/production-session-mode-normalization.md` | One-time dry-run/live-write boundary, fail-closed mode-change class, and required ROB-440 follow-up dry-run. |
| Production session retention | `docs/ops/production-session-retention.md` | Fixed 24-hour policy, protected cleanup contract, disabled timer, monitoring, and rollout/rollback boundary. |
| Production journald retention | `docs/ops/production-journald-retention.md` | Fixed 1 GiB / 30-day drop-in, aggregate drift monitoring, and separately approved activation/vacuum/rollback. |
| Production traffic gate | `docs/traffic-gate-v1.md` | Shared versioned passive traffic contract for deploy and Customers smoke. |
| Canonical deploy state/result/evidence contract | `docs/deployment-run-v1.md` | Closed ROB-455 intent, lifecycle, child receipt, evidence, and future host-state boundary. |
| Architecture boundaries | `docs/architecture-map.md` | Generated view of component boundaries. |
| Ownership scope | `docs/ownership-map.md` | Generated view of ownership and key paths. |
| Canonical architecture/ownership map source | `docs/maps/component_ownership_map.json` | Machine-readable source of truth. |
| Write-path contract harness | `docs/ci-write-contracts.md` | Focused contract-smoke reference. |
| Release gates | `docs/release-gate-zero-surprise.md`, `docs/release-gate-dashboard.md`, `docs/release-gate-booking-confirmation-pdf.md`, `docs/release-gate-provider-ui-smoke.md`, `docs/release-gate-customers-ui-smoke.md` | Dedicated gate behavior and usage. |

## Validation Routing

- Small local confidence check:
  - `docker compose run --rm php-fpm composer test`
- Fast developer-feedback gate; not merge authorization:
  - `bash ./scripts/ci/pre_pr_quick.sh`
- Full local review-ready gate; not merge authorization:
  - `PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh`
- Exact-head landing evidence:
  - follow `WORKFLOW.md`; blocking CI and required final reviews must target the
    same unchanged current PR head
- Harness readiness score:
  - `composer check:agent-harness-readiness`
  - The machine contract owns the supported CI-condition tokens and binds
    critical cross-document clauses to named Markdown sections; the checker
    fails closed on invalid grammar, missing sections, misplaced clauses, or
    duplicate clauses.
  - Every contract-classified blocking job is bound to an exact execution
    fingerprint. Any workflow/job/step shell override or explicit
    `continue-on-error` fails the readiness check independently. Advisory signal
    jobs are classified separately and remain outside this blocking contract.
- Report date sanity:
  - `composer check:harness-report-dates`
- Scope-specific checks:
  - root/host prerequisite contract: `docs/root-host-test-harness.md`
  - write-path contracts: `docs/ci-write-contracts.md`
  - integration smoke browser evidence: `docs/release-gate-dashboard.md`
  - production provider UI smoke: `docs/release-gate-provider-ui-smoke.md`
  - production Customers UI smoke: `docs/release-gate-customers-ui-smoke.md`
  - architecture boundaries entry points: `AGENTS.md`

## Scheduled Hygiene

- Scheduled lightweight hygiene lives in `.github/workflows/hygiene.yml`.
- `agent-harness-readiness-latest.json` is the machine-readable scorecard; use it
  for the current readiness snapshot, not older narrative docs alone.
- `harness-report-date-sanity-latest.json` verifies that dated readiness/audit
  artifacts are not future-dated or internally mismatched.
- Reaction model:
  - `pass`: the harness signals and supporting docs are internally consistent.
  - `fail`: fix the listed drift or date violations before trusting the score as
    the current repo state.

## Editing Rules

- Change `README.md` when operator onboarding, quickstart, or local service
  usage changes.
- Change `WORKFLOW.md` when the agent state machine, workpad policy, or
  ticket-to-merge or model-aware delegation behavior changes.
- Change `.codex/contracts/agent-workflow.json` when a machine-checked
  cross-document workflow invariant changes.
- Change `AGENTS.md` when compact repo guardrails or command entry points
  change.
- Change `.github/workflows/ci.yml` when CI truth changes; then update
  summaries in `README.md` or `AGENTS.md` only as needed.
- Change `docs/maps/component_ownership_map.json` when architecture or
  ownership scope changes; generated docs must follow from that source.

## Anti-Drift Rule

When the same command or policy appears in multiple top-level docs, keep only
one document as the canonical source and reduce the others to a short summary
plus link.
