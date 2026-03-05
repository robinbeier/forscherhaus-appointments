# Agent Readiness Refresh - 2026-03-18

## Scope
This document is the final delta-refresh and re-baselining snapshot for agent-native readiness as of **2026-03-18**.

## Executive Summary
- Overall readiness is now **4.62/5.00** and clears the hard target `>= 4.5`.
- The three hardening switches are active and stable: `coverage-delta`, `write-contract-booking`, `write-contract-api` are all blocking and have stable executed-only streaks.
- CI contracts are now materially stricter than the rollout baseline: write-path gates and coverage are merge-blocking, not advisory.
- Feedback loops improved the most due to deterministic seed/install, diagnostics, and blocking quality gates in one workflow.
- Test confidence increased through layered checks (unit + integration + API contract + write-path contracts + smoke), but long-running deep jobs remain expensive.
- Typed request-contract enforcement is blocking end-to-end, but static-analysis noise risk still exists in framework-dynamic edges.
- Ownership and architecture docs are in place and enforced, but ownership delegation is still concentrated on one handle.

## Updated 5-Dimension Score
Baseline for delta comparison in this refresh: **4.20** (hardening kickoff baseline used for PR-sequencing).

| Dimension | Score (0-5) | Confidence | Delta vs letzter Baseline | Warum (kurz) | Evidence |
| --- | --- | --- | --- | --- | --- |
| Fully typed | 4.5 | Medium | +0.5 | Typed request contracts and L2 checks are blocking in CI for domain-critical scope. | `.github/workflows/ci.yml:248`, `.github/workflows/ci.yml:281`, `README.md:216`, `AGENTS.md:245` |
| Traversable | 4.7 | High | +0.5 | Architecture + ownership maps are generated and validated; boundary checks are blocking. | `docs/architecture-map.md:1`, `docs/ownership-map.md:1`, `.github/workflows/ci.yml:386`, `.github/workflows/ci.yml:409`, `AGENTS.md:239` |
| Test coverage | 4.5 | Medium | +0.6 | Coverage-delta is blocking with explicit policy thresholds; suite includes unit + booking flow + API read integration. | `.github/workflows/ci.yml:299`, `README.md:221`, `AGENTS.md:246`, `AGENTS.md:252` |
| Feedback loops | 4.8 | High | +0.5 | Critical quality gates are blocking and include deterministic setup, diagnostics, and artifact upload. | `.github/workflows/ci.yml:299`, `.github/workflows/ci.yml:536`, `.github/workflows/ci.yml:627`, `.github/workflows/ci.yml:409` |
| Self-documenting | 4.6 | Medium | +0.0 | CI policy and rollback behavior are documented in README/AGENTS and tied to gate commands. | `README.md:210`, `README.md:212`, `README.md:221`, `AGENTS.md:166`, `AGENTS.md:172`, `AGENTS.md:256` |

**Overall score (average): 4.62 / 5.00**

## CI Contract: Vorher vs Nachher

| Gate | Vorher (Rollout) | Nachher (Stand 2026-03-18) | Evidence |
| --- | --- | --- | --- |
| `coverage-delta` | Warn-only (`continue-on-error: true`) during rollout. | Blocking (no job-level `continue-on-error`). | `f0ad4210:.github/workflows/ci.yml` (contains `continue-on-error` for `coverage-delta`), `.github/workflows/ci.yml:299` |
| `write-contract-booking` | Warn-only rollout until streak target. | Blocking (rollout precondition met). | `6ac1ddbf^:.github/workflows/ci.yml` (contains `continue-on-error`), `.github/workflows/ci.yml:536`, `README.md:210` |
| `write-contract-api` | Warn-only rollout until streak target. | Blocking (rollout precondition met). | `94d44439^:.github/workflows/ci.yml` (contains `continue-on-error`), `.github/workflows/ci.yml:627`, `README.md:212`, `AGENTS.md:242` |

Additional blocking quality gates remain active (for example `architecture-boundaries`, `typed-request-contracts`, `api-contract-openapi`, `booking-controller-flows`).
Evidence: `.github/workflows/ci.yml:409`, `.github/workflows/ci.yml:248`, `.github/workflows/ci.yml:454`, `.github/workflows/ci.yml:719`, `README.md:209`, `README.md:216`, `README.md:220`.

## Stabilitaetsnachweis der 3 Hardening-Switches
Verification date: **2026-03-05** (executed-only; `success|failure`, excluding `skipped/cancelled`).

### `coverage-delta` (PR runs, first 7 executed)
- `success`
- `success`
- `success`
- `success`
- `success`
- `success`
- `success`

### `write-contract-booking` (PR runs, first 7 executed)
- `success`
- `success`
- `success`
- `success`
- `success`
- `success`
- `success`

### `write-contract-api` (PR runs, first 7 executed)
- `success`
- `success`
- `success`
- `success`
- `success`
- `success`
- `success`

### `write-contract-api` (post-merge push runs on `main`, first 2 executed)
- `success`
- `success`

## Offene Restluecken

| Luecke | Impact | Risk | Aufwand | Naechster Schritt |
| --- | --- | --- | --- | --- |
| Kein maschinenlesbarer Readiness-Score-Generator | Mittel | Mittel | M | Script einfuehren, das den Score aus CI-/Doc-Signalen reproduzierbar berechnet und versioniert. |
| Workflow-level Enforcement statt Branch-Protection Required Checks | Hoch | Mittel | S-M | Sobald org/repo policy erlaubt: Required Checks in Branch Protection/Rulesets spiegeln. |
| Deep checks sind teuer (Zeit/Kosten) | Mittel | Mittel | M | CI-Sharding + selective caching fuer dockerized quality jobs einfuehren. |
| Coverage bleibt line-based; keine diff-/risk-weighted Policy | Mittel | Niedrig | M | Delta policy um component/diff-aware thresholds erweitern. |
| Single-owner Bottleneck in Ownership Map | Mittel | Mittel | S | Ownership-Delegation pro Komponente mit mindestens 2 unterschiedlichen Handles einfuehren. |
| Transiente GH API 502 bei Streak-Abfragen | Niedrig | Mittel | S | Retry-capable helper script unter `scripts/ci/` fuer status/streak checks bereitstellen. |
| Typed-contract false-positive risk an dynamischen Framework-Kanten | Mittel | Niedrig | M | Gezielt stubs/phpstan config hardening statt globaler ignores; monatliche triage cadence festlegen. |

## Abschlussstatus gegen Exit-Kriterien
- Neue Abschlussdoku vorhanden: **erfuellt**.
- Finaler Score `>= 4.5`: **erfuellt** (`4.62`).
- Drei Hardening-Switches aktiv und stabil belegt: **erfuellt**.

Result: **PR-6 exit criteria met**.

## Assumptions
- Delta-Baseline in dieser Abschlussdoku ist die operative Hardening-Kickoff-Baseline `4.20`.
- Der Fokus bleibt auf agent-native readiness (Delivery-/Quality-Platform), nicht auf Feature-Expansion.
