# Git-Integration und Wiederaufnahmeweg

**Repository:** `forscherhaus-appointments`
**Stichtag:** 7. August 2026
**Charakter:** Abschlussprotokoll der kontrollierten `main`-Integration; die
vorherige Branch- und Worktree-Bestandsaufnahme bleibt als Audit-Snapshot
erhalten.

## Integrationsstatus

- Aktueller Branch: `main`
- Ausgangs-HEAD: `55fe4ceeb50816f8c68c223b9100c5e207b6cc4b`
- Integrierte `origin/main`-Basis: `c58ff9097509c63e3a997a31ad26472752245ab8`
- Sicherungsbranch: `codex/backup-main-2026-08-07-pre-sync`
- Sicherungscommit: `d985b2e0` (`Preserve Uptime Kuma 2.5.0 state`)
- Die fünf vorgefundenen lokalen Änderungen und dieser Bericht wurden vor der
  Integration vollständig im Sicherungscommit festgehalten.
- `main` wurde per Fast-Forward auf die Remote-Basis aktualisiert; der
  Sicherungscommit ließ sich anschließend konfliktfrei übertragen.
- Der endgültige `main`-Commit enthält diesen Bericht selbst. Seine SHA wird
  deshalb nicht selbstreferenziell eingebettet, sondern im Abschlussbeleg mit
  `git rev-parse main` und `git rev-parse origin/main` nachgewiesen.
- Es gibt 5 registrierte Worktrees einschließlich des Hauptarbeitsbaums.
- Kein vorhandener Branch oder Worktree wurde entfernt.
- Die Remote-Referenzen wurden vor der Integration und nach der Veröffentlichung
  mit `git fetch --prune origin` aktualisiert.

### Ausgangsarbeitsbaum

```text
## main...origin/main [behind 12]
 M docker/compose.uptime-kuma.yml
 M docs/long-horizon-lts-modernization/Documentation.md
 M docs/server-rebuild-runbook.md
 M docs/uptime-kuma.md
 M scripts/ops/uptime-kuma.monitors.yml
```

### Remotes

```text
origin	https://github.com/robinbeier/forscherhaus-appointments.git (fetch)
origin	https://github.com/robinbeier/forscherhaus-appointments.git (push)
upstream	https://github.com/alextselegidis/easyappointments.git (fetch)
upstream	https://github.com/alextselegidis/easyappointments.git (push)
```

## Registrierte Worktrees nach der Integration

| Pfad | Branch/Zustand | HEAD | Zweck | Wiederaufnahme |
| --- | --- | --- | --- | --- |
| `/Users/robinbeier/Developers/forscherhaus-appointments` | `main` | Berichtscommit; SHA siehe Abschlussbeleg | Kanonischer Integrationsbranch | Im vorhandenen Worktree auf `main` fortsetzen |
| `/Users/robinbeier/.codex/worktrees/1308/forscherhaus-appointments` | `codex/provider-ui-smoke` | `c7550de1` | Provider-UI-Smoke-Test und Release-Gate-Härtung | Im vorhandenen Worktree auf `codex/provider-ui-smoke` fortsetzen |
| `/Users/robinbeier/.codex/worktrees/2455/forscherhaus-appointments` | `detached` | `55fe4cee` | Prüf-/Snapshot-Worktree ohne eigenen Branch | Vor Änderungen neuen Branch vom genannten HEAD anlegen |
| `/Users/robinbeier/.codex/worktrees/6119ae57-5a7e-4443-a3ac-b8f9ff60cbfe/forscherhaus-appointments` | `detached` | `c58ff909` | Prüf-/Snapshot-Worktree ohne eigenen Branch | Vor Änderungen neuen Branch vom genannten HEAD anlegen |
| `/Users/robinbeier/.codex/worktrees/b8b0/forscherhaus-appointments` | `codex/rob-398-app-www-security-headers` | `a86b8d68` | ROB-398: app www security headers | Im vorhandenen Worktree auf `codex/rob-398-app-www-security-headers` fortsetzen |

## Lokale Branches vor der Integration

Die folgende Tabelle ist der unveränderte Audit-Snapshot vor der Integration.
Der Zweck ist aus Branchname und letztem Commit abgeleitet. „In origin/main“
bedeutete zu diesem Zeitpunkt, dass der Branchstand vollständig erreichbar war;
es ist keine Aussage über fachliche Abnahme oder Produktionsfreigabe.

| Branch | Zweck | Letzter Stand | In origin/main | Rückstand / eigene Commits | Upstream | Worktree |
| --- | --- | --- | --- | --- | --- | --- |
| `codex/lts-modernization-long-horizon` | Langfristige LTS-Modernisierung und Scanner-/Apache-Nacharbeit | `e67b81c8`, 2026-05-19 – Fix Apache scanner timestamp parsing | ja | 86 / 0 | origin/codex/lts-modernization-long-horizon | — |
| `codex/provider-ui-smoke` | Provider-UI-Smoke-Test und Release-Gate-Härtung | `c7550de1`, 2026-07-27 – Harden provider smoke identity and locking | ja | 1 / 0 | origin/codex/provider-ui-smoke | `/Users/robinbeier/.codex/worktrees/1308/forscherhaus-appointments` |
| `codex/remove-symphony` | Entfernung der früheren Symphony-Pilotwerkzeuge | `fd911874`, 2026-05-15 – Remove Symphony pilot tooling | ja | 120 / 0 | — | — |
| `codex/rob-292-prod-security-hardening` | ROB-292: prod security hardening | `5059d7e0`, 2026-05-21 – Add ROB-292 security hardening roadmap | nein | 49 / 1 | origin/codex/rob-292-prod-security-hardening | — |
| `codex/rob-367-post-rebuild-observation` | ROB-367: post rebuild observation | `5a2b3966`, 2026-05-20 – Record post-rebuild observation | nein | 57 / 1 | origin/codex/rob-367-post-rebuild-observation [gone] | — |
| `codex/rob-381-monitoring-audit` | ROB-381: monitoring audit | `acf2d288`, 2026-05-20 – Prepare ROB-381 long-horizon roadmap | nein | 64 / 1 | origin/main [ahead 1, behind 64] | — |
| `codex/rob-381-monitoring-doc-reconcile` | ROB-381: monitoring doc reconcile | `c4ed274a`, 2026-05-20 – Reconcile monitoring roadmap docs | ja | 53 / 0 | origin/codex/rob-381-monitoring-doc-reconcile [gone] | — |
| `codex/rob-382-app-log-noise` | ROB-382: app log noise | `6c3087d8`, 2026-05-20 – Remove ROB-382 rate-limit bypass | nein | 65 / 3 | origin/codex/rob-382-app-log-noise | — |
| `codex/rob-383-post-merge-docs` | ROB-383: post merge docs | `a521ac9a`, 2026-05-20 – Record ROB-383 shipped status | nein | 63 / 1 | origin/codex/rob-383-post-merge-docs | — |
| `codex/rob-383-sentry-hardening` | ROB-383: sentry hardening | `7b849a99`, 2026-05-20 – Harden Sentry redaction context | nein | 64 / 2 | origin/codex/rob-383-sentry-hardening | — |
| `codex/rob-384-kuma-secret-boundary` | ROB-384: kuma secret boundary | `983ab125`, 2026-05-20 – Document Kuma deep health secret boundary | nein | 62 / 1 | origin/codex/rob-384-kuma-secret-boundary | — |
| `codex/rob-385-backup-freshness` | ROB-385: backup freshness | `3d6bbe16`, 2026-05-20 – Split backup freshness monitors | nein | 61 / 1 | origin/codex/rob-385-backup-freshness [gone] | — |
| `codex/rob-386-release-gates` | ROB-386: release gates | `d1b7c467`, 2026-05-20 – Align PHP-FPM monitor runtime names | nein | 60 / 1 | origin/codex/rob-386-release-gates [gone] | — |
| `codex/rob-387-pdf-synthetic-decision` | ROB-387: pdf synthetic decision | `78c44c51`, 2026-05-20 – Decide parent PDF synthetic boundary | nein | 59 / 1 | origin/codex/rob-387-pdf-synthetic-decision [gone] | — |
| `codex/rob-388-roadmap-coordination` | ROB-388: roadmap coordination | `72f9b425`, 2026-05-20 – Close monitoring roadmap coordination | nein | 58 / 1 | origin/codex/rob-388-roadmap-coordination [gone] | — |
| `codex/rob-390-391-live-docs` | ROB-390: 391 live docs | `636335d1`, 2026-05-20 – Document live Kuma maintenance | nein | 55 / 1 | origin/codex/rob-390-391-live-docs [gone] | — |
| `codex/rob-392-prod-log-counting` | ROB-392: prod log counting | `265ea916`, 2026-05-20 – Tighten prod app-log counting | nein | 56 / 1 | origin/codex/rob-392-prod-log-counting [gone] | — |
| `codex/rob-394-sensitive-path-regression-gates` | ROB-394: sensitive path regression gates | `a96b73c0`, 2026-05-21 – Add production sensitive path regression gates | nein | 49 / 1 | origin/codex/rob-394-sensitive-path-regression-gates | — |
| `codex/rob-395-production-server-threat-model` | ROB-395: production server threat model | `b84d3888`, 2026-05-21 – Persist production server threat model | nein | 47 / 1 | origin/codex/rob-395-production-server-threat-model | — |
| `codex/rob-396-baseline-server-posture-decision` | ROB-396: baseline server posture decision | `c5af8bfc`, 2026-05-21 – Document production server posture decision | nein | 46 / 1 | origin/codex/rob-396-baseline-server-posture-decision | — |
| `codex/rob-397-prod-doctor-posture-checks` | ROB-397: prod doctor posture checks | `f91b08ed`, 2026-05-21 – Add redacted prod posture checks | nein | 45 / 1 | origin/codex/rob-397-prod-doctor-posture-checks | — |
| `codex/rob-398-app-www-security-headers` | ROB-398: app www security headers | `a86b8d68`, 2026-05-21 – Prepare ROB-398 header gate | nein | 44 / 1 | origin/codex/rob-398-app-www-security-headers [behind 1] | `/Users/robinbeier/.codex/worktrees/b8b0/forscherhaus-appointments` |
| `codex/rob-398-sensitive-path-streaming` | ROB-398: sensitive path streaming | `09520220`, 2026-05-21 – Stream sensitive path helper in prod validation | nein | 44 / 2 | origin/codex/rob-398-app-www-security-headers | — |
| `codex/rob-399-monitor-security-headers-gate` | ROB-399: monitor security headers gate | `108d75e2`, 2026-05-21 – Document ROB-399 monitor header gate | nein | 43 / 1 | origin/codex/rob-399-monitor-security-headers-gate | — |
| `codex/rob-400-ssh-password-auth-gate` | ROB-400: ssh password auth gate | `d2e4c2dd`, 2026-05-22 – Document ROB-400 SSH password auth gate | nein | 42 / 1 | origin/codex/rob-400-ssh-password-auth-gate | — |
| `codex/rob-410-csp-report-only-live-gate` | ROB-410: csp report only live gate | `5bc0f933`, 2026-05-23 – Document ROB-410 CSP report-only gate | nein | 34 / 1 | origin/codex/rob-410-csp-report-only-live-gate [gone] | — |
| `codex/rob-417-uptime-kuma-240` | ROB-417: uptime kuma 240 | `6d41e9d9`, 2026-06-04 – Merge remote-tracking branch 'origin/main' into codex/rob-417-uptime-kuma-240 | nein | 31 / 2 | origin/codex/rob-417-uptime-kuma-240 [gone] | — |
| `codex/rob-418-ci-playwright-bootstrap` | ROB-418: ci playwright bootstrap | `e73f7625`, 2026-06-04 – Use Chromium for CI Playwright smoke | nein | 32 / 7 | origin/codex/rob-418-ci-playwright-bootstrap [gone] | — |
| `codex/rob-430-teacher-pdf-timeout` | ROB-430: teacher pdf timeout | `302e593e`, 2026-07-27 – Remove unused Playwright version resolver | ja | 9 / 0 | origin/codex/rob-430-teacher-pdf-timeout | — |
| `codex/rob-433-provider-prep-pdf` | ROB-433: provider prep pdf | `e495d50e`, 2026-07-26 – Add provider preparation PDF | ja | 17 / 0 | origin/codex/rob-433-provider-prep-pdf | — |
| `codex/rob-434-revert-provider-filter` | ROB-434: revert provider filter | `c06408e0`, 2026-07-26 – Revert unsafe customer provider filter | ja | 15 / 0 | origin/codex/rob-434-revert-provider-filter | — |
| `codex/sentry-alert-gate` | Sentry-Alarm-Gate dokumentieren | `9565e8d3`, 2026-05-20 – Document Sentry alert gate | nein | 51 / 1 | origin/codex/sentry-alert-gate | — |
| `codex/sentry-alert-gate-complete` | Sentry-Alarm-Gate abschließen | `f96b486f`, 2026-05-20 – Complete Sentry alert gate | nein | 50 / 1 | origin/codex/sentry-alert-gate-complete | — |
| `codex/sentry-ingestion-gate` | Sentry-Ingestion-Gate dokumentieren | `7ea52194`, 2026-05-20 – Document Sentry ingestion gate | nein | 52 / 1 | origin/codex/sentry-ingestion-gate | — |
| `main` | Kanonischer Integrationsbranch | `55fe4cee`, 2026-07-26 – Merge pull request #327 from robinbeier/codex/rob-430-pdf-renderer-fallback | ja | 12 / 0 | origin/main [behind 12] | `/Users/robinbeier/Developers/forscherhaus-appointments` |

## Vor der Integration nur auf origin vorhandene Projektbranches

Diese Branches benötigen lokal keinen Wiederaufnahmebestand. Falls sie wieder relevant werden, kann ein neuer lokaler Branch direkt von der jeweiligen `origin/...`-Referenz erstellt werden.

| Remote-Branch | Abgeleiteter Zweck | Letzter Stand |
| --- | --- | --- |
| `origin/beierrobin/rob-157-optimize-symphony-tui-formatter-allocations` | beierrobin/rob 157 optimize symphony tui formatter allocations; letzter Commit: Cache Symphony state presenter formatters | `e19fd183`, 2026-03-13 – Cache Symphony state presenter formatters |
| `origin/beierrobin/rob-170-doc-drift-follow-up` | beierrobin/rob 170 doc drift follow up; letzter Commit: Clarify FAQ authority and setup docs | `76cdfafe`, 2026-03-16 – Clarify FAQ authority and setup docs |
| `origin/beierrobin/rob-175-reduce-pdf-renderer-fallback-outage-latency` | beierrobin/rob 175 reduce pdf renderer fallback outage latency; letzter Commit: Reduce PDF renderer fallback outage latency | `8ac4165a`, 2026-03-17 – Reduce PDF renderer fallback outage latency |
| `origin/beierrobin/rob-180-document-observability-and-backup-restore-workflows` | beierrobin/rob 180 document observability and backup restore workflows; letzter Commit: Document observability and restore workflow notes | `bd2f3cba`, 2026-03-17 – Document observability and restore workflow notes |
| `origin/beierrobin/rob-38-03-symphony-sidecar-tooling-refresh` | beierrobin/rob 38 03 symphony sidecar tooling refresh; letzter Commit: Bump Symphony sidecar Node types | `006c156f`, 2026-03-08 – Bump Symphony sidecar Node types |
| `origin/beierrobin/rob-43-08-jquery-4-compatibility-spike` | beierrobin/rob 43 08 jquery 4 compatibility spike; letzter Commit: Map installation bootstrap ownership | `0ff058c8`, 2026-03-08 – Map installation bootstrap ownership |
| `origin/codex/add-codex-review-infra` | add codex review infra; letzter Commit: Add Codex review agents and review rubric | `ae150a24`, 2026-03-17 – Add Codex review agents and review rubric |
| `origin/codex/admin-dashboard-booking-summary` | admin dashboard booking summary; letzter Commit: Merge remote-tracking branch 'origin/main' into codex/admin-dashboard-booking-summary | `403e5a88`, 2026-03-18 – Merge remote-tracking branch 'origin/main' into codex/admin-dashboard-booking-summary |
| `origin/codex/ci-compose-readiness-pr-d` | ci compose readiness pr d; letzter Commit: Harden CI compose readiness checks | `bd11a09d`, 2026-03-19 – Harden CI compose readiness checks |
| `origin/codex/cookie-handoff-pr-c` | cookie handoff pr c; letzter Commit: Stabilize dashboard browser gate | `67f8034a`, 2026-03-18 – Stabilize dashboard browser gate |
| `origin/codex/dashboard-booking-summary-ci-followup` | dashboard booking summary ci followup; letzter Commit: Prefix resolved Playwright runtime package | `4db71aa8`, 2026-03-18 – Prefix resolved Playwright runtime package |
| `origin/codex/dashboard-browser-smoke-pr-b` | dashboard browser smoke pr b; letzter Commit: Harden dashboard browser smoke gate | `c159d994`, 2026-03-18 – Harden dashboard browser smoke gate |
| `origin/codex/dashboard-planned-capacity` | dashboard planned capacity; letzter Commit: Clarify planned dashboard capacity | `cbc2dbab`, 2026-03-17 – Clarify planned dashboard capacity |
| `origin/codex/playwright-gate-contracts-pr-a` | playwright gate contracts pr a; letzter Commit: codex: fix CI failure on PR #258 | `0089e6ae`, 2026-03-18 – codex: fix CI failure on PR #258 |
| `origin/codex/rob-183-pr-review-subagents-mini` | ROB-183: pr review subagents mini | `4400ac0a`, 2026-03-18 – Tune PR review subagents for hybrid mini split |
| `origin/codex/test-gap-privacy-cache-coverage` | test gap privacy cache coverage; letzter Commit: Harden privacy cache fallback coverage | `8c238376`, 2026-03-16 – Harden privacy cache fallback coverage |
| `origin/feat/booking-remove-inner-scroll` | feat/booking remove inner scroll; letzter Commit: On branch feat/booking-remove-inner-scroll Your branch is up to date with 'origin/feat/booking-remove-inner-scroll'. | `01a20fab`, 2025-10-04 – On branch feat/booking-remove-inner-scroll Your branch is up to date with 'origin/feat/booking-remove-inner-scroll'. |
| `origin/hotfix/jwt-v7-pre-release` | hotfix/jwt v7 pre release; letzter Commit: Hotfix firebase/php-jwt v7 pre-release | `d971c951`, 2026-02-23 – Hotfix firebase/php-jwt v7 pre-release |
| `origin/upstream-tracking` | upstream tracking; letzter Commit: Height for installation page container | `41c9b93a`, 2025-09-15 – Height for installation page container |

## Abschluss und Validierung

- Enge Prüfung: `docker compose -f docker/compose.uptime-kuma.yml config --quiet`
  und `git diff origin/main..HEAD --check` erfolgreich.
- Das zunächst fehlende lokale `vendor/` und der gestoppte Docker-Dienst wurden
  über `bash ./scripts/setup-worktree.sh` beziehungsweise Docker Desktop
  reproduzierbar bereitgestellt; das Setup erzeugte keine Git-Differenz.
- `PRE_PR_RUN_COVERAGE=1 PRE_PR_BASE_REF=origin/main bash
  ./scripts/ci/pre_pr_full.sh` erfolgreich: 720 PHPUnit-Tests, Request-DTO- und
  Request-Contract-Gates, PHPStan, Deptrac, Architektur-/Ownership-Gates, fünf
  Deep-Runtime-Suiten sowie Coverage-Delta bestanden.
- Coverage: 28,8439 Prozent, +6,3939 Prozentpunkte gegenüber der Baseline.
- Die Sass- und PHPUnit-Deprecation/Notice-Ausgaben sind nicht-blockierende
  Bestandssignale; kein Gate ist fehlgeschlagen.

## Wiederaufnahme und Rollback

1. Für die normale Fortsetzung im Hauptordner `main` verwenden und zuerst
   `git status --short --branch` sowie `git fetch --prune origin` prüfen.
2. Der unveränderte Vorintegrationsbestand ist über
   `codex/backup-main-2026-08-07-pre-sync` beziehungsweise Commit `d985b2e0`
   erreichbar. Einzelne Dateien können daraus gezielt verglichen oder auf einen
   neuen Wiederherstellungsbranch übernommen werden.
3. Einen bereits veröffentlichten `main` nicht zurücksetzen oder
   force-pushen. Falls eine fachliche Rücknahme nötig wird, vom aktuellen
   `main` einen neuen Branch erstellen und einen normalen Revert-Commit oder
   eine gezielte Gegenänderung reviewen.
4. Brancharbeit im bereits zugeordneten Worktree fortsetzen. Bei detached
   Worktrees vor Änderungen einen neuen, sprechenden Branch vom dokumentierten
   HEAD anlegen.
5. Vor späterer Branchbereinigung weiterhin Integration, Ticketstatus und
   Worktree-Nutzung einzeln belegen. Für dieses Endziel war keine Löschung
   erforderlich.
6. Für Produktionsarbeit weiterhin einen frischen read-only Preflight
   durchführen; dieser Bericht dokumentiert ausschließlich das lokale
   Repository und die Git-Integration.

## Bewusst nicht durchgeführt

- kein Force-Push und kein `git reset --hard`
- keine Branch-, Worktree- oder Remote-Branch-Löschung
- kein Produktionszugriff
