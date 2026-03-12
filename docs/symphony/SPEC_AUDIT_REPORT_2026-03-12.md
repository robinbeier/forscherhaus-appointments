# Symphony SPEC Audit Report (2026-03-12)

Basis:

- Checkliste: `docs/symphony/SPEC_AUDIT_CHECKLIST.md`
- Upstream SPEC: <https://github.com/openai/symphony/blob/main/SPEC.md>
- Gepruefter Code: `tools/symphony/`, `scripts/symphony/`, `WORKFLOW.md`
- Vorherige historische Audit-Fassung: `docs/symphony/SPEC_AUDIT_REPORT_2026-03-07.md`
- Eingefrorene Gap-Matrix: `docs/symphony/SPEC_GAP_SCORECARD_2026-03-12.md`

## Executive Summary

Aktueller Stand: **nahe an einer idealen Symphony-Portierung, mit nur noch
kleinen bewussten Restabweichungen ausserhalb des Core-Conformance-Pfads**.

Aktuelle Gesamtbewertung: **`9.5/10`**.

Die Bewertung ist gegenueber der historischen 2026-03-07-Fassung deutlich
gestiegen, weil die damals noch offenen Kernluecken inzwischen geschlossen
wurden:

1. Config- und CLI-Paritaet fuer die optionale HTTP-Extension ist vorhanden
   (`--port`, `server.port`, dokumentierte Praezedenz, Loopback-Default).
2. Snapshot-Aggregate bilden echte Token-/Runtime-Telemetrie ab statt
   Outcome-Countern.
3. Die operative Pilot-Baseline ist jetzt bewusst und dokumentiert auf
   Symphony-Readiness zugeschnitten, statt von repo-weiten PHPUnit-/DB-Problemen
   abzuhaengen.

Die frueher sichtbare Luecke rund um die **human-readable Status-Surface unter
`/`** ist inzwischen geschlossen: Dashboard, Snapshot-Health, issue-lokale
Health-Signale und `recent_events` sind vorhanden, dokumentiert und
deterministisch testbar. Die verbleibenden Abweichungen liegen jetzt vor allem
in optionalen Randthemen wie Multi-Sink-Logging oder breiterer Template-Flexibilitaet.

## Evidence Refresh

Evidence refreshed on 2026-03-12:

- `npm --prefix tools/symphony run build`
- `npm --prefix tools/symphony run test:conformance`
- `bash ./scripts/ci/run_symphony_pilot_checks.sh`
- `PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh`

Supporting implementation evidence:

- CLI/workflow state API precedence and loopback defaults in
  `tools/symphony/src/options.ts`, `tools/symphony/src/service.ts`,
  `tools/symphony/src/workflow.ts`
- snapshot telemetry totals in `tools/symphony/src/orchestrator.ts`
- issue and state API semantics in `tools/symphony/src/state-server.ts`
- human-readable dashboard rendering in `tools/symphony/src/state-dashboard.ts`
- deterministic status-surface proof in `tools/symphony/src/state-server.test.ts`
- deterministic pilot-readiness baseline in
  `scripts/ci/run_symphony_pilot_checks.sh`

## Re-Score Rationale

### 1. Punkt 2 Ziele sind operativ erfuellt

Die Upstream-SPEC fordert in Punkt 2 vor allem:

- bounded, observable orchestration
- tracker-driven worker dispatch
- retry/backoff und timeout handling
- operator-visible observability, mindestens structured logs

Diese Ziele sind aktuell lokal erfuellt:

- Orchestrator-Scheduling, continuation turns, retry/backoff, stall handling und
  review-handoff sind in `tools/symphony/src/orchestrator.ts` implementiert und
  durch `tools/symphony/src/orchestrator.test.ts` breit abgedeckt.
- Same-thread continuation ueber denselben App-Server-Run ist in
  `tools/symphony/src/app-server-client.ts` umgesetzt und in
  `tools/symphony/src/app-server-client.test.ts` verifiziert.
- Structured JSON logging fuer Operatoren ist in
  `tools/symphony/src/logger.ts` vorhanden.
- Die repo-lokale Pilot-Baseline ist mit
  `scripts/ci/run_symphony_pilot_checks.sh` nun ausdruecklich ein
  Symphony-Readiness-Gate; `start_pilot.sh` bleibt davon getrennt das
  Live-Bootability-Signal.

### 2. Punkt 3 Kernkomponenten sind jetzt fast vollstaendig

Gegen die Kernbereiche aus Punkt 3 ergibt sich aktuell diese Einordnung:

| Bereich | Bewertung | Begruendung |
| --- | --- | --- |
| Workflow Loader + typed config | `9.5/10` | Positional workflow path, `cwd/WORKFLOW.md`, last-known-good reload, `max_turns`, `max_retry_backoff_ms`, approval/sandbox und `server.port` sind umgesetzt. |
| Tracker integration | `9.0/10` | Candidate fetch, state refresh, blocker normalization, workpad sync und review/merge handoff sind stark und testgedeckt. |
| Orchestrator + scheduler | `9.5/10` | Tick order, bounded concurrency, per-state limits, continuation, stall detection, active stopping, startup cleanup und retries sind vorhanden. |
| Workspace manager | `9.5/10` | Deterministische issue workspaces, path safety, hook lifecycle und best-effort `before_remove` sind SPEC-nah. |
| Agent runner / app-server protocol | `9.0/10` | Handshake, same-thread continuation, token extraction und policy propagation sind stark; Restabweichungen sind klein und nicht operator-kritisch. |
| Observability | `9.5/10` | Structured logs, `/`, `/api/v1/state`, `/api/v1/<issue_identifier>`, refresh trigger, health indicators und `recent_events` sind operator-sichtbar und deterministisch testbar. |

### 3. Warum die Status Surface jetzt als geschlossen gilt

Die fruehere Gap-Matrix behandelte die fehlende dashboardartige Surface noch als
`9.5/10`-Blocker. Gegen die Upstream-SPEC war das bereits zu streng; inzwischen
ist die Surface aber auch praktisch geliefert:

- `3.1.7 Status Surface` ist als **optional** formuliert.
- Punkt 2 verlangt fuer Observability nur eine operator-sichtbare Form, mit
  structured logs als Minimum.
- Die lokale Implementation liefert jetzt zusaetzlich:
  - ein read-only Dashboard unter `/`
  - Snapshot-Health und globale `recent_events`
  - issue-lokale Health- und Event-Sichten unter `/api/v1/<issue_identifier>`
  - pilot-nahe Operator-Doku in `tools/symphony/README.md` und
    `docs/symphony/STAGING_PILOT_RUNBOOK.md`
  - deterministische Testabdeckung in `src/state-server.test.ts`, eingebunden in
    `npm --prefix tools/symphony run test:conformance`

Damit ist die Status Surface nicht nur kein Core-Conformance-Blocker mehr,
sondern auch als optionaler Operator-UX-Track sauber abgeschlossen.

## Delta Zur Historischen Audit-Fassung (2026-03-07)

Die folgenden damaligen Kernaussagen gelten nicht mehr:

| Historische Aussage | Aktueller Stand |
| --- | --- |
| Worker ist single-turn only | Geschlossen. Continuation turns und `agent.max_turns` sind implementiert und getestet. |
| Reconciliation stoppt problematische Sessions nicht aktiv | Geschlossen. Running sessions werden bei Review-Handoff, terminalen States, nicht-aktiven States und Stall-Faellen aktiv gestoppt. |
| Kein Startup cleanup fuer terminale Issues | Geschlossen. Startup cleanup laeuft vor dem Polling. |
| `before_remove` ist fatal | Geschlossen. `before_remove` ist best effort. |
| Kein `--port` / kein `server.port` | Geschlossen. Beide Pfade sind implementiert, dokumentiert und getestet. |
| Snapshot-Aggregate sind outcome-counter | Geschlossen. `generated_at`, `counts` und echte `codex_totals` fuer Tokens/Runtime sind live. |
| Repo-operative Pilot-Baseline ist rot | Geschlossen als Vertragsklaerung. Die Baseline ist jetzt bewusst Symphony-spezifisch und gruen; der Vollgate-Pfad bleibt optional zusaetzlich verfuegbar. |

## Offene Restpunkte

Die verbleibenden Restpunkte sind bewusst **nicht mehr 9.5-blocking**:

1. Multi-Sink-Logging beziehungsweise explizite Sink-Fallback-Strategien
2. Breitere Template-Flexibilitaet ueber die aktuellen strikten Roots hinaus
3. Allgemeine Politur ausserhalb der nun vorhandenen Status-Surface

Diese Restpunkte bleiben optionale spaetere Verbesserungen und aendern nicht die
aktuelle Bewertung der Core-Conformance.

## Final Assessment

**Finale Zielnaehe: `9.5/10`**

Begruendung:

- Die frueheren Kernluecken aus Config-Paritaet, Snapshot-Telemetrie und
  operativer Pilot-Baseline sind geschlossen.
- Die Punkt-2-Ziele der SPEC sind lokal jetzt sowohl technisch als auch
  operativ belastbar nachgewiesen.
- Die vormals groesste Luecke in der Status-Surface ist inzwischen geschlossen;
  die Restabweichungen liegen in engeren optionalen Randthemen und rechtfertigen
  daher nur noch einen kleinen, nicht blockierenden Abzug.
