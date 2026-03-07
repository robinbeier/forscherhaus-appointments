# Symphony SPEC Audit Checklist

Zweck: Diese Checkliste ist ein Soll-Katalog, um unsere Symphony-Implementation
gegen die Upstream-Spezifikation zu pruefen.

Quellen:

- Upstream SPEC: <https://github.com/openai/symphony/blob/main/SPEC.md>
- Upstream Skills: <https://github.com/openai/symphony/tree/main/.codex/skills>
- Lokale Test-Matrix fuer einen Teilbereich: `tools/symphony/CONFORMANCE_MATRIX.md`

Hinweise zur Nutzung:

- `[ ]` = noch nicht verifiziert oder fehlt
- `[x]` = nachweislich umgesetzt
- `[-]` = absichtlich nicht im Scope
- Pro Punkt Nachweis notieren: Testdatei, Command, Log, Screenshot oder Codepfad
- Die Punkte unter "Core Conformance" sind die eigentliche Blocking-Liste
- Die Punkte unter "Skill-Kompatibilitaet" sind nur relevant, wenn wir die
  Upstream-Skills weitgehend unveraendert verwenden wollen

## 1. Scope und Architekturgrenzen

- [ ] Die Implementation verhaelt sich als Scheduler/Runner und Tracker-Reader,
  nicht als fest eingebaute Ticket-Business-Logik.
- [ ] Erfolgreiche Runs duerfen an einem Workflow-definierten Handoff-State
  enden und muessen nicht automatisch `Done` setzen.
- [ ] Die Architektur ist erkennbar in Policy-, Config-, Coordination-,
  Execution-, Integration- und Observability-Schichten getrennt.
- [ ] Es gibt eine einzelne autoritative Orchestrator-Runtime-State-Quelle fuer
  Running-, Claimed- und Retry-Zustand.

## 2. Core Conformance: Workflow und Config

- [ ] Der Workflow-Pfad folgt der SPEC-Prioritaet: expliziter Runtime-Pfad
  gewinnt vor `./WORKFLOW.md`.
- [ ] Fehlende Workflow-Datei liefert einen typisierten
  `missing_workflow_file`-Fehler.
- [ ] `WORKFLOW.md` wird korrekt in YAML-Front-Matter und Markdown-Body
  zerlegt.
- [ ] Front Matter, das kein Objekt/Map ist, liefert einen typisierten Fehler.
- [ ] Unbekannte Top-Level-Keys im Front Matter werden ignoriert, nicht als
  Hard-Fail behandelt.
- [ ] Der Prompt-Body wird vor der Verwendung getrimmt.
- [ ] Die Typed-Config-Schicht wendet Defaults aus der SPEC korrekt an.
- [ ] `$VAR`-Indirection funktioniert fuer `tracker.api_key`.
- [ ] `$VAR`-Indirection funktioniert fuer Pfadwerte wie `workspace.root`.
- [ ] `~`-Expansion funktioniert fuer Pfadwerte.
- [ ] URI- oder Command-Strings werden nicht versehentlich als lokale Pfade
  umgeschrieben.
- [ ] `tracker.kind` akzeptiert derzeit nur `linear` und validiert das sauber.
- [ ] `tracker.project_slug` ist fuer Linear-Dispatch Pflicht und wird
  validiert.
- [ ] `codex.command` ist Pflicht fuer Dispatch und bleibt als Shell-Command
  erhalten.
- [ ] `polling.interval_ms` ist zur Laufzeit aenderbar und beeinflusst kueftige
  Ticks ohne Neustart.
- [ ] `agent.max_concurrent_agents` ist zur Laufzeit aenderbar und beeinflusst
  kueftige Dispatch-Entscheidungen ohne Neustart.
- [ ] `agent.max_retry_backoff_ms` ist zur Laufzeit aenderbar und beeinflusst
  kueftige Retry-Schedules ohne Neustart.
- [ ] `hooks.timeout_ms` wird validiert; ungueltige oder nicht-positive Werte
  fallen auf den Default zurueck.
- [ ] `max_concurrent_agents_by_state` normalisiert State-Namen (`trim` +
  `lowercase`) und ignoriert ungueltige Eintraege.
- [ ] `WORKFLOW.md` wird waehrend der Laufzeit ueberwacht und automatisch
  reloaded.
- [ ] Ungueltige Reloads crashen den Service nicht; die letzte bekannte gueltige
  Konfiguration bleibt aktiv.
- [ ] Vor dem Scheduler-Start wird eine Startup-Validierung ausgefuehrt; bei
  Fehlern startet der Dienst nicht.
- [ ] Vor jedem Dispatch-Zyklus wird erneut validiert; bei Fehlern wird nur der
  Dispatch uebersprungen, die Reconciliation laeuft weiter.
- [ ] SPEC-Unklarheit ist explizit behandelt: `agent.max_turns` ist entweder
  implementiert und dokumentiert oder bewusst als Abweichung vermerkt, obwohl es
  im Cheat Sheet und Referenzalgorithmus vorkommt.

## 3. Core Conformance: Prompt-Rendering

- [ ] Das Prompt-Template rendert mit strikter Variablenpruefung.
- [ ] Das Prompt-Template rendert mit strikter Filterpruefung.
- [ ] Die Template-Inputs enthalten den normalisierten `issue`-Record.
- [ ] Die Template-Inputs enthalten `attempt` fuer Retry- und
  Continuation-Semantik.
- [ ] Nested Arrays und Maps aus `issue` bleiben im Template iterierbar.
- [ ] Ein leerer Prompt-Body hat ein bewusst dokumentiertes Verhalten
  (Default-Prompt oder explizite Abweichung).
- [ ] Template-Render-Fehler scheitern nur den betroffenen Run Attempt und
  nicht den gesamten Service.

## 4. Core Conformance: Tracker-Integration

- [ ] Der Tracker-Adapter unterstuetzt `fetch_candidate_issues()`.
- [ ] Der Tracker-Adapter unterstuetzt `fetch_issues_by_states(state_names)`.
- [ ] Der Tracker-Adapter unterstuetzt `fetch_issue_states_by_ids(issue_ids)`.
- [ ] Candidate Fetch filtert auf konfigurierte aktive States und das
  konfigurierte Projekt.
- [ ] Fuer Linear wird `project.slugId` und nicht ein veraltetes Projektfeld
  verwendet.
- [ ] Candidate Fetch ist paginiert; Seiten werden in stabiler Reihenfolge
  verarbeitet.
- [ ] `fetch_issues_by_states([])` liefert leer zurueck, ohne unnoetigen API-
  Call.
- [ ] `fetch_issue_states_by_ids` nutzt GraphQL-ID-Typing gemaess SPEC
  (`[ID!]`).
- [ ] Issue-Normalisierung liefert alle benoetigten Kernfelder:
  `id`, `identifier`, `title`, `state`, `priority`, `branch_name`, `url`,
  `labels`, `blocked_by`, `created_at`, `updated_at`.
- [ ] Labels werden konsequent zu lowercase normalisiert.
- [ ] `blocked_by` wird aus Inverse-Relations vom Typ `blocks` abgeleitet.
- [ ] `priority` wird nur als Integer uebernommen; andere Werte werden `null`.
- [ ] ISO-8601-Zeitstempel werden sauber in `created_at` und `updated_at`
  normalisiert.
- [ ] Tracker-Fehler werden in die vorgesehenen Kategorien gemappt
  (`linear_api_request`, `linear_api_status`, `linear_graphql_errors`,
  `linear_unknown_payload`, `linear_missing_end_cursor` oder aequivalent).
- [ ] Candidate-Fetch-Fehler fuehren zu "skip dispatch this tick", nicht zum
  Service-Crash.
- [ ] State-Refresh-Fehler lassen laufende Worker weiterlaufen und werden erst
  im naechsten Tick erneut versucht.
- [ ] Startup-Terminal-Cleanup-Fehler werden als Warnung sichtbar, blockieren
  aber den Start nicht.

## 5. Core Conformance: Orchestrator und Scheduling

- [ ] Die Tick-Reihenfolge entspricht der SPEC: Reconcile, Preflight-Validation,
  Candidate-Fetch, Sortierung, Dispatch, Observability-Update.
- [ ] Dispatch-Eignung prueft: Pflichtfelder vorhanden, aktiver State,
  nicht terminal, nicht bereits running, nicht bereits claimed.
- [ ] Der `Todo`-Blocker-Guard ist umgesetzt: offene Blocker verhindern
  Dispatch, terminale Blocker nicht.
- [ ] Die Dispatch-Sortierung folgt der SPEC: `priority` aufsteigend,
  dann `created_at` aelteste zuerst, dann `identifier`.
- [ ] Der globale Concurrency-Limit wird korrekt angewendet.
- [ ] Per-State-Concurrency-Limits werden zusaetzlich korrekt angewendet.
- [ ] Claimed-State und Running-State verhindern Double-Dispatch.
- [ ] Normale Worker-Exits fuehren zu einem kurzen Continuation-Retry
  (ca. 1000 ms), nicht zu "done forever".
- [ ] Fehlerhafte Worker-Exits fuehren zu exponentiellem Retry-Backoff mit
  Cap.
- [ ] Retry-Eintraege speichern mindestens Issue-ID, Identifier, Attempt,
  Due-Zeit und Fehlergrund.
- [ ] Ein neuer Retry fuer dieselbe Issue ersetzt einen alten Retry-Timer
  sauber.
- [ ] Stall Detection nutzt `last_codex_timestamp`, sonst `started_at`.
- [ ] `stall_timeout_ms <= 0` deaktiviert Stall Detection vollstaendig.
- [ ] Tracker-Reconciliation stoppt laufende Runs bei terminalem State und
  bereinigt den Workspace.
- [ ] Tracker-Reconciliation stoppt laufende Runs bei nicht-aktivem,
  nicht-terminalem State ohne Workspace-Cleanup.
- [ ] Tracker-Reconciliation aktualisiert bei aktivem State den in-memory
  Issue-Snapshot.
- [ ] Startup-Terminal-Cleanup entfernt Workspaces fuer bereits terminale
  Issues.
- [ ] Restart-Recovery ist tracker- und filesystem-getrieben; es gibt keinen
  stillschweigenden Versuch, alte in-memory Retry-Timer zu restaurieren.

## 6. Core Conformance: Workspace-Management und Safety

- [ ] Pro Issue-Identifier entsteht ein deterministischer Workspace-Pfad unter
  `workspace.root`.
- [ ] Workspace-Key-Sanitization laesst nur `[A-Za-z0-9._-]` zu und ersetzt
  alles andere durch `_`.
- [ ] Der Workspace-Pfad wird absolut normalisiert und gegen den Root-Pfad
  validiert.
- [ ] Out-of-root-Workspaces werden hart abgelehnt.
- [ ] Ein fehlender Workspace wird angelegt.
- [ ] Ein bestehender Workspace wird wiederverwendet.
- [ ] Ein bestehender Nicht-Verzeichnis-Pfad am Workspace-Ziel wird sicher
  behandelt und nicht blind ueberschrieben.
- [ ] Erfolgreiche Runs loeschen Workspaces nicht automatisch.
- [ ] Optionale Workspace-Population oder Sync-Fehler werden sichtbar an den
  Attempt zurueckgegeben.
- [ ] Neue Workspaces duerfen bei fruehem Setup-Fehler optional aufgeraeumt
  werden; Reused Workspaces werden nicht destruktiv resettet, ausser das ist
  explizit dokumentiert.
- [ ] Temporaere Artefakte wie `tmp` oder `.elixir_ls` werden waehrend der Prep
  entfernt, falls die Implementation das laut Testprofil voraussetzt.
- [ ] `after_create` laeuft nur bei neu erzeugtem Workspace.
- [ ] `before_run` laeuft vor jedem Attempt.
- [ ] Fehler oder Timeouts in `before_run` brechen den Attempt ab.
- [ ] `after_run` laeuft nach jedem Attempt, auch bei Fehler, Timeout oder
  Cancellation.
- [ ] Fehler oder Timeouts in `after_run` werden nur geloggt und blockieren
  nicht.
- [ ] `before_remove` laeuft vor Cleanup vorhandener Workspaces.
- [ ] Fehler oder Timeouts in `before_remove` werden nur geloggt; Cleanup
  laeuft weiter.
- [ ] Hook-Ausfuehrung geschieht mit Workspace als `cwd`.
- [ ] Hook-Starts, Hook-Fehler und Hook-Timeouts werden strukturiert geloggt.
- [ ] Vor Agent-Launch wird erneut verifiziert, dass `cwd == workspace_path`.

## 7. Core Conformance: Agent Runner und App-Server-Protokoll

- [ ] Der Codex-Subprozess wird via `bash -lc <codex.command>` gestartet.
- [ ] Der Launch-CWD ist der per-Issue-Workspace.
- [ ] Stdout und Stderr werden getrennt behandelt.
- [ ] Protokoll-JSON wird nur aus Stdout geparst.
- [ ] Partielle Stdout-Zeilen werden bis zum Newline gepuffert.
- [ ] Nicht-JSON auf Stderr wird nur als Diagnose geloggt und crasht die
  Session nicht.
- [ ] Die Startup-Handshake-Reihenfolge entspricht der SPEC:
  `initialize`, `initialized`, `thread/start`, `turn/start`.
- [ ] `initialize` sendet Client-Identity und erforderliche Capabilities.
- [ ] `thread/start` und `turn/start` verwenden die dokumentierte Approval- und
  Sandbox-Policy der Implementation.
- [ ] `thread/start` und `turn/start` uebergeben den absoluten Workspace-Pfad
  als `cwd`.
- [ ] `turn/start` setzt den Titel auf `<issue.identifier>: <issue.title>`.
- [ ] `thread_id` und `turn_id` werden aus den Result-Payloads robust gelesen.
- [ ] `session_id = <thread_id>-<turn_id>` wird gebildet und geloggt.
- [ ] Continuation-Turns verwenden denselben `thread_id` innerhalb eines
  Worker-Runs.
- [ ] Der Subprozess bleibt ueber Continuation-Turns hinweg aktiv und wird erst
  am Ende des Worker-Runs beendet.
- [ ] Turn-Completion unterscheidet sauber zwischen `turn_completed`,
  `turn_failed`, `turn_cancelled`, Timeout und Prozess-Exit.
- [ ] `codex.read_timeout_ms` wird fuer Start- und Sync-Reads erzwungen.
- [ ] `codex.turn_timeout_ms` wird fuer den gesamten Turn erzwungen.
- [ ] `codex.stall_timeout_ms` wird orchestratorseitig anhand Event-Inaktivitaet
  erzwungen.
- [ ] Fehler werden in normalisierte Kategorien gemappt
  (`codex_not_found`, `invalid_workspace_cwd`, `response_timeout`,
  `turn_timeout`, `port_exit`, `response_error`, `turn_failed`,
  `turn_cancelled`, `turn_input_required` oder aequivalent).
- [ ] Agent-Events werden strukturiert an den Orchestrator weitergegeben, inkl.
  `session_started`, Turn-Status und sonstigen relevanten Eventtypen.
- [ ] Approval-Requests koennen nicht unbegrenzt haengen; sie werden gemaess
  dokumentierter Policy auto-approved, surfaced oder als Fehler behandelt.
- [ ] User-Input-Requests koennen nicht unbegrenzt haengen; sie werden gemaess
  dokumentierter Policy behandelt.
- [ ] Unsupported Dynamic Tool Calls liefern einen Fehler zurueck, ohne die
  Session zu blockieren.
- [ ] Usage- und Rate-Limit-Payloads werden auch in kompatiblen Varianten
  robust gelesen.

## 8. Core Conformance: Logging und Observability

- [ ] Startup-, Validation-, Dispatch- und Worker-Fehler sind ohne Debugger
  operator-sichtbar.
- [ ] Issue-bezogene Logs enthalten `issue_id` und `issue_identifier`.
- [ ] Session-Lifecycle-Logs enthalten `session_id`.
- [ ] Log-Messages nutzen stabiles `key=value`-Wording.
- [ ] Log-Messages enthalten Outcome und knappen Fehlergrund.
- [ ] Grosse Rohpayloads werden nicht unkontrolliert geloggt.
- [ ] Ein Ausfall eines Log-Sinks crasht den Orchestrator nicht.
- [ ] Token-Aggregation bevorzugt absolute Thread-Totals gegenueber Delta-
  Payloads.
- [ ] Token-Deltas werden relativ zu zuletzt berichteten Totals berechnet, um
  Double-Counting zu vermeiden.
- [ ] Die Runtime-Sekunden werden als Live-Aggregat aus beendeten und aktiven
  Sessions gebildet.
- [ ] Das zuletzt gesehene Rate-Limit-Payload wird gespeichert.
- [ ] Falls eine Snapshot- oder Monitoring-Schnittstelle existiert, liefert sie
  Running-Liste, Retry-Liste, Token-Totals und Rate-Limits.
- [ ] Falls eine Snapshot- oder Monitoring-Schnittstelle existiert, sind
  `timeout`- oder `unavailable`-Fehler operator-sichtbar.
- [ ] Falls eine menschlich lesbare Statusflaeche existiert, zieht sie ihre
  Daten nur aus dem Orchestrator-State und beeinflusst nicht die Korrektheit.
- [ ] Falls humanisierte Event-Summaries existieren, sind sie reine
  Observability und nicht Teil der Entscheidungslogik.

## 9. Core Conformance: CLI und Host Lifecycle

- [ ] Die CLI akzeptiert optional einen positional Workflow-Pfad.
- [ ] Ohne Argument wird `./WORKFLOW.md` verwendet.
- [ ] Ein expliziter, nicht existierender Workflow-Pfad fuehrt zu einem sauberen
  Fehler.
- [ ] Ein fehlendes Default-`./WORKFLOW.md` fuehrt zu einem sauberen Fehler.
- [ ] Startup-Fehler werden sauber surfaced und fuehren zu Nonzero-Exit.
- [ ] Ein normal gestarteter und normal beendeter Prozess beendet sich mit
  Success-Exit-Code.
- [ ] Ein abnormaler Host-Prozess-Exit fuehrt zu Nonzero-Exit.

## 10. Core Conformance: Security und Operational Safety

- [ ] Die Implementation dokumentiert ihre Trust Boundary explizit.
- [ ] Die Implementation dokumentiert ihre Approval-, Sandbox- und
  Operator-Confirmation-Policy explizit.
- [ ] Workspace-Isolation und Path-Validation sind als Baseline-Schutzmassnahme
  umgesetzt.
- [ ] Secrets koennen ueber `$VAR` eingebunden werden.
- [ ] Secrets werden bei Validation und Logging nie im Klartext ausgegeben.
- [ ] Hook-Skripte werden als voll vertrauenswuerdige Konfiguration behandelt.
- [ ] Hook-Output wird in Logs begrenzt oder abgeschnitten.
- [ ] Hook-Timeouts verhindern haengende Orchestrator-Zustaende.
- [ ] Zusaetzliche Harness-Hardening-Massnahmen sind fuer den gewaehlten
  Deployment-Kontext dokumentiert oder bewusst ausgeschlossen.

## 11. Extension Conformance: HTTP Server und Runtime API

- [ ] Falls ein HTTP-Server ausgeliefert wird, startet er mit CLI-`--port`.
- [ ] Falls ein HTTP-Server ausgeliefert wird, startet er auch via
  `server.port` im Workflow.
- [ ] Falls beides gesetzt ist, hat CLI-`--port` Vorrang vor `server.port`.
- [ ] Der Default-Bind ist Loopback, sofern nicht explizit anders
  konfiguriert.
- [ ] Port `0` wird fuer ephemeres lokales Binden korrekt behandelt, falls
  unterstuetzt.
- [ ] Listener-Rebind-Verhalten bei Port-Aenderung ist dokumentiert
  (Restart-required ist laut SPEC okay).
- [ ] Falls ein Dashboard ausgeliefert wird, haengt es unter `/`.
- [ ] Falls eine JSON-API ausgeliefert wird, haengt sie unter `/api/v1/*`.
- [ ] `GET /api/v1/state` liefert eine Zusammenfassung mit Running-, Retry-,
  Token-, Runtime- und Rate-Limit-Daten.
- [ ] `GET /api/v1/<issue_identifier>` liefert issue-spezifische Runtime- und
  Debug-Daten oder `404 issue_not_found`.
- [ ] `POST /api/v1/refresh` queued einen best-effort Poll/Reconcile-Trigger und
  antwortet mit `202 Accepted`.
- [ ] Unsupported Methods auf definierten Routen liefern `405`.
- [ ] API-Fehler verwenden ein stabiles JSON-Error-Envelope.

## 12. Extension Conformance: `linear_graphql`

- [ ] Falls `linear_graphql` implementiert ist, wird das Tool dem App-Server
  waehrend des Handshakes advertised.
- [ ] Das Tool nutzt die in Symphony konfigurierte Linear-Auth und fordert keine
  Roh-Tokens aus dem Workspace an.
- [ ] Das Tool akzeptiert genau eine GraphQL-Operation pro Call.
- [ ] Leere Queries oder ungueltige Argumentstrukturen liefern strukturierte
  Fehler.
- [ ] Top-Level-GraphQL-`errors` liefern `success=false`, aber der Response-Body
  bleibt fuer Debugging erhalten.
- [ ] Fehlende Auth, Transportfehler und ungueltige Inputs liefern strukturierte
  Fehlerpayloads.
- [ ] Nicht unterstuetzte Tool-Namen blockieren die Session nicht.
- [ ] Falls das Tool nicht implementiert ist, ist diese Abweichung klar
  dokumentiert und die Session bleibt trotzdem robust gegen unbekannte Tool-Calls.

## 13. Operative Freigabe vor Produktion

- [ ] Ein Real-Integration-Profil mit echten Credentials kann bewusst
  ausgefuehrt werden.
- [ ] Reale Integrationstests verwenden isolierte Test-Identifier und
  Workspaces.
- [ ] Skipped Real-Integration-Checks werden als `skipped`, nicht als
  `passed`, ausgewiesen.
- [ ] Wenn das Real-Integration-Profil explizit aktiviert wird, sind Fehlschlaege
  blocking.
- [ ] Hook-Ausfuehrung und Workflow-Pfad-Aufloesung wurden auf dem Ziel-Host-OS
  verifiziert.
- [ ] Falls der HTTP-Server ausgeliefert wird, wurden Port-, Bind- und
  Endpoint-Erwartungen auf dem Zielsystem verifiziert.
- [ ] Vor einem Produktionsentscheid wurde ein laengerer Soak oder Pilotlauf mit
  Observability-Daten bewertet.

## 14. Skill-Kompatibilitaet mit Upstream-Skills

Diese Punkte sind nicht Teil der harten SPEC-Konformitaet. Sie sind nur
relevant, wenn wir die Skills aus `openai/symphony/.codex/skills` moeglichst
unveraendert uebernehmen oder daran angelehnte Workflows fahren wollen.

- [ ] `debug`-Skill-kompatibel: Logs sind an einem stabilen, dokumentierten Ort
  verfuegbar und enthalten `issue_identifier`, `issue_id` und `session_id`.
- [ ] `debug`-Skill-kompatibel: Session-Lifecycle-Logs erlauben ein schnelles
  Tracing von Start, Stream, Completion, Failure und Stall-Recovery.
- [ ] `linear`-Skill-kompatibel: Das `linear_graphql`-Tool ist in
  App-Server-Sessions verfuegbar und verhaelt sich wie im Skill beschrieben.
- [ ] `commit`-Skill-kompatibel: Git ist im Workspace nutzbar, Commits koennen
  lokal erzeugt und im Workspace erhalten werden.
- [ ] `pull`-Skill-kompatibel: Der Workspace ist ein echter Git-Checkout mit
  `origin`, Merge-Update gegen Main ist moeglich.
- [ ] `push`-Skill-kompatibel: `gh` ist installiert und authentifiziert, und ein
  PR-Flow ist fuer den Ziel-Remote vorgesehen.
- [ ] `land`-Skill-kompatibel: CI-Checks, Review-Kommentare und Mergeability
  koennen ueber `gh` beobachtet werden.
- [ ] Repo-spezifische Skill-Annahmen wurden geprueft und bei Bedarf geforkt:
  Die Upstream-Skills enthalten teils symphony-repo-spezifische Kommandos,
  Pfade und PR-Konventionen und sind nicht automatisch 1:1 auf dieses Repo
  uebertragbar.

## 15. Lokale Nachweisquellen in diesem Repo

- [ ] `tools/symphony/CONFORMANCE_MATRIX.md` deckt die automatisierte Nachweis-
  Matrix fuer SPEC `17.1` bis `17.5` nachvollziehbar ab.
- [ ] Fuer SPEC `17.6`, `17.7`, `17.8` sowie fuer die optionalen Extensions
  existieren eigene Nachweise oder ein dokumentierter Gap.
- [ ] `docs/symphony/STAGING_PILOT_RUNBOOK.md` und
  `docs/symphony/ROB-17-PILOT-REPORT-2026-03-06.md` werden als operative
  Evidenz getrennt von echter SPEC-Konformitaet behandelt.
