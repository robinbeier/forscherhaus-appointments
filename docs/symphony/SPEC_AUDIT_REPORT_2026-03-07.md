# Symphony SPEC Audit Report (2026-03-07)

Note (2026-03-12): parts of this report are now historical rather than
current. For the current gap matrix, stale-finding corrections, and the
`9.5/10` closeout criteria, read
`docs/symphony/SPEC_GAP_SCORECARD_2026-03-12.md`.

Basis:

- Checkliste: `docs/symphony/SPEC_AUDIT_CHECKLIST.md`
- Upstream SPEC: <https://github.com/openai/symphony/blob/main/SPEC.md>
- Gepruefter Code: `tools/symphony/`, `scripts/symphony/`, `WORKFLOW.md`

## Executive Summary

Aktueller Stand: **teilweise conformant, aber keine vollstaendige Symphony-Portierung
gemaess Upstream-SPEC**.

Das lokale `tools/symphony` ist klar als **Pilot-Scaffold** erkennbar, nicht als
vollstaendige Referenz-Implementation. Die vorhandenen Unit-Tests sind gruener
als die reale SPEC-Lage: sie pruefen vor allem das aktuell implementierte
Verhalten, nicht die volle Upstream-SPEC.

Wahrscheinlichste Erklaerungen dafuer, dass Symphony bisher "nicht wie
gewuenscht" laeuft:

1. Die Runtime implementiert **nicht die in der SPEC geforderte Mehr-Turn-
   Worker-Semantik auf demselben Thread**, sondern beendet den App-Server nach
   genau einem Turn und startet fuer Folgeschritte neue Worker/Threads.
2. Die Reconciliation **markiert** problematische oder terminale Runs nur,
   **stoppt** sie aber nicht aktiv und fuehrt auch **kein Startup-Terminal-
   Cleanup** aus.
3. Mehrere Konfigurations- und CLI-Schnittstellen sind **schema-drifted** gegen
   die Upstream-SPEC; wer sich an der SPEC orientiert, bekommt lokal ein anderes
   Verhalten.
4. Die Observability-/State-Flaeche ist nur eine **kleine Pilot-API**, nicht
   die in der SPEC beschriebene Debug-/Operations-Flaeche.
5. Die Workspace-Cleanup-Logik weicht in kritischen Details von der SPEC ab,
   insbesondere bei `before_remove`.

## Priorisierte Findings

### 1. Worker-Runs sind auf genau einen Turn begrenzt und verlieren damit die SPEC-Continuation-Semantik

Schweregrad: Hoch

Die SPEC fordert, dass ein Worker bei weiter aktivem Issue auf demselben
laufenden App-Server und demselben `thread_id` mehrere Turns fahren kann, bis
`agent.max_turns` erreicht ist. Lokal ruft der Orchestrator genau einmal
`appServer.runTurn(...)` auf und beendet den Run danach
([orchestrator.ts](/Users/robinbeier/Developers/forscherhaus-appointments/tools/symphony/src/orchestrator.ts#L552)).
Der App-Server-Client selbst terminiert den Prozess bei jedem Ergebnis oder
Fehler sofort
([app-server-client.ts](/Users/robinbeier/Developers/forscherhaus-appointments/tools/symphony/src/app-server-client.ts#L258),
[app-server-client.ts](/Users/robinbeier/Developers/forscherhaus-appointments/tools/symphony/src/app-server-client.ts#L267)).

Praktische Folge:

- Verlust von Thread-Kontext zwischen Iterationen
- mehr Neustarts als vorgesehen
- hoeheres Risiko fuer haengende oder ineffiziente Bearbeitung
- Verhalten passt nicht zu der Upstream-SPEC, an der sich Nutzer orientieren

### 2. Stall-Detection erkennt nur ueberlange Laufzeit, beendet aber den Run nicht aktiv

Schweregrad: Hoch

Die SPEC verlangt orchestratorseitige Stall-Detection auf Basis der letzten
Codex-Aktivitaet und aktives Beenden plus Retry. Lokal wird nur
`runtimeMs > turnTimeout + grace` geprueft und dann `stallLogged` /
`suppressRetry` gesetzt
([orchestrator.ts](/Users/robinbeier/Developers/forscherhaus-appointments/tools/symphony/src/orchestrator.ts#L384)).
Ein aktives Stoppen des Workers oder des Codex-Prozesses findet dort nicht
statt.

Praktische Folge:

- wirklich festhaengende Sessions bleiben laenger oder unbegrenzt aktiv
- Reconciliation erkennt das Problem, heilt es aber nicht
- das passt direkt zur Beobachtung "laeuft nicht wie gewuenscht"

### 3. Reconciliation und Startup-Cleanup sind unvollstaendig

Schweregrad: Hoch

Die SPEC fordert:

- Startup-Terminal-Workspace-Cleanup
- Stoppen laufender Sessions bei terminalem oder nicht-aktivem State
- Workspace-Cleanup bei terminalem State

Lokal gibt es keinen Startup-Sweep fuer terminale Issues in `service.ts` oder
`orchestrator.ts`
([service.ts](/Users/robinbeier/Developers/forscherhaus-appointments/tools/symphony/src/service.ts#L66),
[orchestrator.ts](/Users/robinbeier/Developers/forscherhaus-appointments/tools/symphony/src/orchestrator.ts#L304)).
Bei State-Aenderungen wird ein laufender Run nur als `suppressRetry` markiert,
nicht aktiv gestoppt
([orchestrator.ts](/Users/robinbeier/Developers/forscherhaus-appointments/tools/symphony/src/orchestrator.ts#L374)).

Praktische Folge:

- stale Workspaces sammeln sich an
- terminale oder verschobene Tickets koennen weiterlaufen
- Operatoren sehen Statusaenderungen im Tracker, aber die Runtime zieht nicht
  sauber nach

### 4. Hook-Semantik fuer `before_remove` widerspricht der SPEC

Schweregrad: Mittel bis hoch

Die SPEC verlangt, dass `before_remove`-Fehler nur geloggt und ignoriert
werden. Lokal ist `before_remove` fatal, weil `isFatalPhase()` fuer
`before_remove` `true` liefert
([workspace-manager.ts](/Users/robinbeier/Developers/forscherhaus-appointments/tools/symphony/src/workspace-manager.ts#L76)).
`cleanupTerminalWorkspace()` fuehrt dadurch den Hook vor dem `rm` aus und
scheitert komplett, wenn der Hook fehlschlaegt
([workspace-manager.ts](/Users/robinbeier/Developers/forscherhaus-appointments/tools/symphony/src/workspace-manager.ts#L158)).

Praktische Folge:

- kaputte Cleanup-Hooks blockieren die Bereinigung
- Worktree-/Workspace-Leaks werden wahrscheinlicher
- genau der Debug-/Aufraeumpfad wird unzuverlaessig

### 5. Config- und CLI-Schema weichen deutlich von der Upstream-SPEC ab

Schweregrad: Mittel

Beispiele:

- kein positional Workflow-Pfad in der CLI
  ([options.ts](/Users/robinbeier/Developers/forscherhaus-appointments/tools/symphony/src/options.ts#L6))
- Default-Workflow ist Repo-Root-`WORKFLOW.md`, nicht `cwd/WORKFLOW.md`
  ([workflow.ts](/Users/robinbeier/Developers/forscherhaus-appointments/tools/symphony/src/workflow.ts#L335))
- lokales Schema nutzt `tracker.provider`, `agent.maxConcurrent`,
  `agent.maxAttempts`, `codex.responseTimeoutMs`
  ([workflow.ts](/Users/robinbeier/Developers/forscherhaus-appointments/tools/symphony/src/workflow.ts#L405))
- zentrale SPEC-Felder fehlen oder sind nicht implementiert:
  `terminal_states`, `max_retry_backoff_ms`,
  `max_concurrent_agents_by_state`, `stall_timeout_ms`,
  `approval_policy`, `thread_sandbox`, `turn_sandbox_policy`,
  `agent.max_turns`

Praktische Folge:

- Konfiguration gemaess SPEC fuehrt lokal nicht zu erwartetem Verhalten
- Doku, Erwartung und Runtime driften auseinander

### 6. App-Server-Handshake und Tool-/Input-Policy sind nur teilweise SPEC-konform

Schweregrad: Mittel

Der Client sendet zwar `initialize`, `initialized`, `thread/start`,
`turn/start`, aber wichtige Payload-Teile aus der SPEC fehlen, etwa
Approval-/Sandbox-Policy und `title`
([app-server-client.ts](/Users/robinbeier/Developers/forscherhaus-appointments/tools/symphony/src/app-server-client.ts#L732),
[app-server-client.ts](/Users/robinbeier/Developers/forscherhaus-appointments/tools/symphony/src/app-server-client.ts#L757)).

Zusaetzlich wird `item/tool/call` pauschal als `input_required` beendet statt
ein unbekanntes Tool mit Fehler zu beantworten und die Session fortzusetzen
([app-server-client.ts](/Users/robinbeier/Developers/forscherhaus-appointments/tools/symphony/src/app-server-client.ts#L441)).

Praktische Folge:

- weniger kompatibel mit echten App-Server-Versionen und deren Discovery-Modell
- Sessions koennen frueher als noetig abbrechen

### 7. Die State-API ist fuer echtes Debugging zu schmal

Schweregrad: Mittel

Die API bietet nur:

- `GET /api/v1/state`
- `POST /api/v1/refresh`

und liefert ein lokales Wrapper-Format `{status, snapshot}` statt der
empfohlenen Baseline-Shape
([state-server.ts](/Users/robinbeier/Developers/forscherhaus-appointments/tools/symphony/src/state-server.ts#L91)).
Es fehlt `GET /api/v1/<issue_identifier>`, es gibt keine `405`-Semantik fuer
falsche Methoden und keine issue-spezifischen Debug-Daten.

Praktische Folge:

- schwierigeres Incident-Triage
- unzureichende Operator-Sicht fuer haengende oder retriende Einzel-Issues

### 8. Die Erfolgsdefinition "HEAD muss vorgerueckt sein" ist repo-pragmatisch, aber nicht spec-neutral

Schweregrad: Mittel

Der lokale Pilot wertet einen abgeschlossenen Turn ohne neuen Commit als Fehler
`workspace_no_committed_output`
([orchestrator.ts](/Users/robinbeier/Developers/forscherhaus-appointments/tools/symphony/src/orchestrator.ts#L594)).
Das ist ein bewusstes Repo-Guardrail, aber keine Upstream-SPEC-Anforderung.

Praktische Folge:

- Sessions koennen lokal als Fehler gewertet werden, obwohl der Agent
  sinnvoll gearbeitet hat, aber bewusst nichts committet hat
- diese Guardrail erklaert "funktioniert nicht" besonders dann, wenn die
  Agent-Prompts oder Skills nicht konsequent committen

## Soll/Ist nach Checklisten-Bloecken

### 1. Scope und Architekturgrenzen

Status: Teilweise erfuellt

- Scheduler/Runner-Charakter ist vorhanden.
- Eine einzelne In-Memory-Orchestrator-State-Quelle ist vorhanden.
- Die Schichten sind erkennbar getrennt.
- Ticket-Business-Logik ist aber teilweise repo-spezifisch in Guardrails und
  Git-Worktree-Regeln hineingezogen.

### 2. Workflow und Config

Status: Teilweise erfuellt

Erfuellt:

- Front matter + Prompt-Body parsing
- Reload mit last-known-good fallback
- Preflight fuer API-Key / Project-Slug / Codex-Command
- `$VAR` und `~`-Aufloesung

Nicht oder nur teilweise erfuellt:

- Fehlerklassen weichen von der SPEC ab
- Default-Workflow-Pfad folgt nicht der SPEC
- kein positional Workflow-Pfad
- zentrale Config-Felder fehlen
- kein support fuer `terminal_states`
- kein support fuer per-state concurrency
- kein support fuer `max_retry_backoff_ms`
- keine spec-gemaesse Codex policy fields

### 3. Prompt-Rendering

Status: Teilweise erfuellt

Erfuellt:

- strict roots
- unknown paths fail

Nicht oder nur teilweise erfuellt:

- kein Filter-Konzept wie in der SPEC-Beschreibung
- `attempt` ist lokal immer numerisch modelliert, nicht `null/absent` auf dem
  ersten Lauf
- leere Prompt-Bodies werden als Fehler behandelt statt optionalem
  Minimal-Fallback

### 4. Tracker-Integration

Status: Teilweise erfuellt

Erfuellt:

- `slugId` wird verwendet
- Pagination ist vorhanden
- Blocker-Normalisierung ueber Relations ist vorhanden
- leere State-Liste short-circuitet

Nicht oder nur teilweise erfuellt:

- Fehlerklassen weichen von der SPEC ab
- Priority wird zu `0` statt `null` normalisiert
- `fetchIssueStatesByIds()` liefert nur `Map<id, state>` statt minimal
  normalisierte Issue-Snapshots
- keine klare Trennung aktiver vs. terminaler States in der Config

### 5. Orchestrator und Scheduling

Status: Teilweise erfuellt, mit mehreren kritischen Gaps

Erfuellt:

- Tick loop
- Prioritaets-Sortierung
- Todo-Blocker-Filter
- Retry-Queue-Grundmechanik

Nicht oder nur teilweise erfuellt:

- kein echter claimed-state gemaess SPEC
- Continuation ueber neue Worker statt ueber mehrere Turns im selben Worker
- falsche Backoff-Formel relativ zur SPEC
- Stall-Detection basiert nicht auf letzter Aktivitaet
- Stalls werden nicht aktiv beendet
- keine startup terminal workspace cleanup
- running sessions werden bei State-Wechsel nicht aktiv gestoppt

### 6. Workspace-Management und Safety

Status: Teilweise erfuellt

Erfuellt:

- Sanitization
- Root containment
- Hook execution im workspace cwd
- Git-state-capture fuer repo-spezifische Guardrail

Nicht oder nur teilweise erfuellt:

- `before_remove` fatal statt best-effort
- kein explizites Handling fuer bestehende Nicht-Verzeichnisse am Workspace-Pfad
- success cleanup ist repo-/pilot-spezifisch statt rein issue-state-basiert

### 7. Agent Runner und App-Server-Protokoll

Status: Teilweise erfuellt, aber nicht voll konform

Erfuellt:

- `bash -lc <command>`
- stdout/stderr Trennung
- line buffering
- request/response timeout
- turn timeout

Nicht oder nur teilweise erfuellt:

- keine Mehr-Turn-Sessions
- fehlende `title`- und policy-payloads
- unsupported tool calls fuehren nicht zu structured tool failure + continue
- kein optionales `linear_graphql`-Tool
- Eventmodell ist schmaler als in der SPEC

### 8. Logging und Observability

Status: Teilweise erfuellt

Erfuellt:

- strukturierte Logs
- Issue-/Session-Felder in vielen relevanten Logs

Nicht oder nur teilweise erfuellt:

- Token-Totals sind keine spec-gemaessen Input/Output/Total-Aggregate
- Runtime-Sekunden fehlen
- issue-spezifische Debug-Sicht fehlt
- Snapshot-API ist nur eine Minimalvariante

### 9. CLI und Host Lifecycle

Status: Teilweise erfuellt

- `--workflow` und `--check` existieren
- bootstrap errors werden surfaced
- Service-Start/Stop ist da
- aber die CLI entspricht nicht der SPEC-Signatur

### 10. Security und Operational Safety

Status: Teilweise erfuellt

- Das Repo dokumentiert fuer den Pilot Approval-/Sandbox-Guardrails.
- Workspace root containment existiert.
- Secrets werden nicht aktiv geloggt.

Gaps:

- keine spec-nahe Dokumentation der gesamten Trust Boundary im Tool selbst
- keine sichtbare Hardening-Konfiguration innerhalb der Runtime ausserhalb der
  Startskript-Guardrails

### 11. HTTP Server und Runtime API

Status: Teilweise erfuellt

- optionaler API-Server existiert
- loopback default ueber Env ist gegeben

Gaps:

- nicht ueber `server.port` in `WORKFLOW.md` steuerbar
- keine CLI-`--port`-Unterstuetzung
- fehlender issue endpoint
- falsche / reduzierte Response-Shapes
- keine `405`-Semantik

### 12. `linear_graphql` Extension

Status: Nicht implementiert

Das ist fuer die Core-Conformance nicht blocking, erklaert aber eine Luecke
zwischen Upstream-Skills / Upstream-SPEC und lokaler Runtime.

### 13. Operative Freigabe vor Produktion

Status: Noch nicht ausreichend belegt

Es gibt Pilot-Runbook und Soak-Gate-Artefakte, aber laut bestehendem
Pilot-Report ist der echte 24h-Staging-Soak bisher nicht nachgewiesen.

## Vorhandene positive Signale

- Die lokale Test-Suite in `tools/symphony` ist derzeit gruen.
- `slugId`-Drift in Linear wurde bereits beruecksichtigt.
- Es gibt sinnvolle repo-spezifische Guardrails fuer Commit-Pflicht und
  Worktree-Erhalt bei Fehlschlaegen.
- Reload mit last-known-good fallback ist fuer einen Pilot bereits solide.

## Wahrscheinlichste naechste Schritte

1. Zuerst die echten Laufzeitfehler schliessen:
   - running sessions aktiv terminieren koennen
   - startup terminal cleanup ergaenzen
   - `before_remove` best-effort machen
   - echte stall detection nach letzter Aktivitaet implementieren
2. Danach die groesste Verhaltensluecke schliessen:
   - Mehr-Turn-Worker auf demselben Thread / App-Server einfuehren
3. Anschliessend Config/API-Schema an die Upstream-SPEC angleichen:
   - CLI path semantics
   - missing config fields
   - state API shape + issue endpoint
4. Repo-spezifische Guardrails explizit als lokale Extensions dokumentieren,
   damit klar ist, was SPEC und was Forscherhaus-spezifisch ist.
