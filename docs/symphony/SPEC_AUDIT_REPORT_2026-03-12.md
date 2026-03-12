# Symphony SPEC Audit Report (2026-03-12)

Basis:

- Checkliste: `docs/symphony/SPEC_AUDIT_CHECKLIST.md`
- Upstream SPEC: <https://github.com/openai/symphony/blob/main/SPEC.md>
- Gepruefter Code: `tools/symphony/`, `scripts/symphony/`, `WORKFLOW.md`
- Vorherige historische Audit-Fassung: `docs/symphony/SPEC_AUDIT_REPORT_2026-03-07.md`
- Eingefrorene Gap-Matrix: `docs/symphony/SPEC_GAP_SCORECARD_2026-03-12.md`

## Executive Summary

Aktueller Stand: **nahe an einer idealen Symphony-Portierung, mit kleiner
bewusster Restabweichung im optionalen Status-Surface-Bereich**.

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

Die verbleibende sichtbare Luecke ist eine **human-readable Status-Surface unter
`/`**. Die Upstream-SPEC markiert diese aber in `3.1.7` ausdruecklich als
**optional**. Punkt 2 verlangt operator-visible observability mit mindestens
structured logs, und diese Basis ist lokal bereits vorhanden. Deshalb blockiert
die fehlende HTML-/Dashboard-Oberflaeche die `9.5/10`-Bewertung nicht mehr,
wohl aber die `10/10`-Naehestufe.

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
| Observability | `8.5/10` | Structured logs, `/api/v1/state`, `/api/v1/<issue_identifier>`, refresh trigger und richer telemetry sind da; die human-readable Surface unter `/` fehlt noch. |

### 3. Warum die fehlende Status Surface nicht mehr blockiert

Die fruehere Gap-Matrix behandelte die fehlende dashboardartige Surface noch als
`9.5/10`-Blocker. Gegen die Upstream-SPEC ist das zu streng:

- `3.1.7 Status Surface` ist als **optional** formuliert.
- Punkt 2 verlangt fuer Observability nur eine operator-sichtbare Form, mit
  structured logs als Minimum.
- Die lokale Implementation liefert bereits structured JSON logs und eine
  zweckmaessige JSON-State-API fuer Incident-Triage.

Damit bleibt die fehlende HTML-Surface ein sinnvoller Follow-up fuer bessere
Operator-UX, aber **kein Core-Conformance-Blocker** mehr.

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

1. Human-readable Status Surface unter `/`
2. Reichere Operator-UX fuer Recent Events und Health-Indikatoren
3. Allgemeine Politur der Observability-Darstellung

Diese Restpunkte sind gute Kandidaten fuer `ROB-127` bis `ROB-131`, aendern aber
nicht die aktuelle Bewertung der Core-Conformance.

## Final Assessment

**Finale Zielnaehe: `9.5/10`**

Begruendung:

- Die frueheren Kernluecken aus Config-Paritaet, Snapshot-Telemetrie und
  operativer Pilot-Baseline sind geschlossen.
- Die Punkt-2-Ziele der SPEC sind lokal jetzt sowohl technisch als auch
  operativ belastbar nachgewiesen.
- Die verbleibende groesste Luecke liegt in einer explizit optionalen
  Status-Surface-UX und rechtfertigt deshalb einen kleinen, aber nicht
  blockierenden Abzug statt einer Rueckstufung auf `7.5/10`.
