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
| Ticket-bound mutation authorization | `docs/ticket-mutation-authorization.md` | Explicit per-action scope, existing grants, exclusions and current platform approvals. |
| Machine-readable workflow and CI invariants | `.codex/contracts/agent-workflow.json` | Structured exact-head, review, public-write, evidence, and blocking-job contract. |
| Model-aware implementation delegation | `WORKFLOW.md`, `.codex/agents/implementation-worker.toml` | Primary-agent authority plus the pinned Luna worker boundary. |
| Controlled parallel implementation | `WORKFLOW.md` | Explicit disjoint local ownership with primary-owned integration and publication. |
| Compact guardrails and command entry points | `AGENTS.md` | Cross-topic entry point without duplicating specialist docs. |
| Read-only local start preflight | `docs/local-start-preflight.md`, `scripts/ci/start_preflight.py` | Worktree/Git, declared boundaries, Docker resources and approval prerequisites before mutation. |
| Core pre-PR path | `scripts/ci/pre_pr_quick.sh`, `scripts/ci/pre_pr_full.sh`, `scripts/ci/select_local_full_gate.php` | Actual executable gate logic; the full gate reuses the GitHub `integration_smoke` filter for local smoke and frontend-build selection. |
| Gate results and warning evidence | `docs/gate-diagnostic-summary.md`, `scripts/ci/run_gate_with_summary.py` | Shared local/CI summaries, complete raw logs and exact warning-baseline comparison. |
| CI gate semantics and job wiring | `.github/workflows/ci.yml` | Ground truth for job triggers, blocking status, and artifacts. |
| Standard review and landing | `WORKFLOW.md`, `code_review.md` | One independent reviewer, risk-based specialists, current blocking CI, and authorized merge of the reviewed head. |
| Reviewer runtime availability and fallback | `docs/reviewer-runtime-preflight.md` | Live capability/startup checks before a diff, equivalent coverage, exact base/head and enforced read-only boundaries. |
| Local/CI root-host test prerequisites | [Docker test guidance](docker.md#linux-roothost-tests) | Local skips, required Linux CI checks, and focused diagnosis. |
| CI test execution and timing comparisons | `docs/ci-test-execution.md` | Main tests, application coverage, and direct GitHub job timing. |
| Observability runtime ownership | `docs/observability.md` | Runtime split between release gates, Kuma, application logs, and diagnostics. |
| Production SSH operations harness | `docs/ops/agent-operations.md` | Agent-first production orientation, read-only diagnostics, and post-change validation. |
| Production Docker build-cache retention | `docs/ops/production-build-cache-retention.md` | Fixed dry-run/execute boundary, cache policy, stop conditions, and validation. |
| Production backup-set producer | `docs/ops/production-backup-set-producer.md` | Closed connection/dump authority, atomic set publication, protected handoff-to-attestation selection, disabled ROB-480 recurring continuity units, and no-gap legacy scheduler cutover. |
| Production dump-producer admission | `docs/ops/production-dump-producer-admission.md` | Pinned single-producer registry, canonical manifest/attestation binding, on-demand read-only observation, and cleanup-inventory integration. |
| Production legacy release hold | `docs/ops/production-legacy-release-hold.md` | Existing host-local hold for unverifiable legacy archives; retention protection after retirement of the one-time provisioning helper. |
| Production session retention | `docs/ops/production-session-retention.md` | Fixed 24-hour policy, protected cleanup contract, routine inspection, monitoring, and pause/recovery guidance. |
| Production journald retention | `docs/ops/production-journald-retention.md` | Native journal rotation, aggregate inspection, and occasional approved manual cleanup. |
| Canonical deploy state/result/evidence contract | `docs/deployment-run-v1.md` | Closed ROB-455 intent, lifecycle, child receipt, evidence, and future host-state boundary. |
| Architecture boundaries | `docs/architecture-map.md` | Generated view of component boundaries. |
| Ownership scope | `docs/ownership-map.md` | Generated view of ownership and key paths. |
| Canonical architecture/ownership map source | `docs/maps/component_ownership_map.json` | Machine-readable source of truth. |
| Database lock hierarchy | `docs/database-lock-order.md` | Source-grounded parent-first lock order, transaction ownership, productive paths, and known narrower delete subsets. |
| Write-path contract harness | `docs/ci-write-contracts.md` | Focused contract-smoke reference. |
| Release gates | `docs/release-gate-zero-surprise.md`, `docs/release-gate-dashboard.md`, `docs/release-gate-booking-confirmation-pdf.md`, `docs/release-gate-provider-ui-smoke.md`, `docs/release-gate-customers-ui-smoke.md` | Dedicated gate behavior and usage. |

## Validation Routing

- [Local validation requirements](../WORKFLOW.md#3-validate-locally) explain
  the evidence needed before review; [command entry points](../AGENTS.md#default-path)
  include the focused test, quick gate, and full gate invocations.
- [CI test execution and timing](ci-test-execution.md) covers the main tests,
  coverage jobs, and local/CI comparisons.
- [Review and landing](../WORKFLOW.md#pr-and-review-expectations) follows
  the exact-head and authorization requirements in `WORKFLOW.md`.
- [CI write contracts and workflow fingerprints](ci-write-contracts.md#ci-jobs)
  cover the blocking-job contract, failure controls, and drift checks.
- Scope-specific checks:
  - root/host prerequisite contract: [Docker test guidance](docker.md#linux-roothost-tests)
  - integration smoke browser evidence: [Dashboard release gate](release-gate-dashboard.md)
  - production provider UI smoke: [Provider UI smoke release gate](release-gate-provider-ui-smoke.md)
  - production Customers UI smoke: [Customers UI smoke release gate](release-gate-customers-ui-smoke.md)
  - architecture boundaries entry points: [AGENTS.md](../AGENTS.md)

## Editing Rules

- Change `README.md` when operator onboarding, quickstart, or local service
  usage changes.
- Change `WORKFLOW.md` when the agent state machine, workpad policy, or
  ticket-to-merge or model-aware delegation behavior changes.
- Change `.codex/contracts/agent-workflow.json` when a machine-checked
  workflow or CI invariant changes.
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
