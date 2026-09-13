# Defense Factory: Rückblick auf den ersten vollständigen Zyklus

Stand: 13.09.2026. Untersuchte Repository-Basis:
`3ef28886dc4ab7182f6723dbcd1017b611e868b1` (Merge von PR #570).
Dies ist eine Prozessauswertung vorhandener Belege, kein neuer Sicherheitsscan.

## Ergebnis und Einordnung

Der erste Zyklus ist für seine **sechs ausdrücklich definierten Invarianten
verifiziert abgeschlossen**. Der Abschluss vom 13.09. ergänzt die am 12.09.
noch offenen Nachweise für ROB-550, ROB-551 und ROB-552. Die älteren offenen
Zwischenstände bleiben historisch richtig; sie beschreiben nicht das Endergebnis.
Der Abschluss ist keine Aussage über die Fehlerfreiheit des gesamten Repos.

Die größte Stärke war die Qualitätssicherung: feste Stände, unabhängige Reviews,
persistierte Ergebnisse und überprüfte Bereinigung verhinderten einen bloß
behaupteten Abschluss. Die größte Schwäche war die späte Klärung, **welcher
Nachweis mit welcher Umgebung, welchem Modell und welchem Prüfrahmen tatsächlich
erreichbar ist**. Dadurch entstanden zusätzliche Entwicklungsrunden im Prüfrahmen.

Der Artikel beschreibt einen schrittweise automatisierten Prozess mit
wiederverwendbarem Kontext, isolierten Umgebungen und unabhängiger Prüfung der
ausgelieferten Fixes. Daran gemessen ist das wichtigste nächste Ziel: weniger
Übergaben und Wiederholungen bei gleicher Belegqualität. Ein weiteres Modell oder
mehr parallele Scans lösen diese organisatorische Lücke allein nicht.
[Quelle: OpenAI Defense Factory](https://openai.com/the-defense-factory/).

## Quellen und Aussagegrenzen

Ausgewertet wurden die aktuellen Nachrichten der folgenden Tasks sowie
GitHub-Metadaten, PR-Beschreibungen, Review-Einreichungen und Inline-Kommentare:

- [Schließe Defense-Factory-Zyklus ab](codex://threads/01a09354-8cec-71a3-b7eb-c0eac153c0b9)
- [Prüfe ROB-550 Kalenderrechte](codex://threads/01a08d57-c605-7683-b495-23a89c29d3e8)
- [Bewerte Defense-Factory-Workflow](codex://threads/01a091ed-ed1a-7b63-a0ca-a21cdfef5666), einschließlich der Fortsetzung vom 13.09.
- [Plan für ROB-553 bis ROB-559](codex://threads/01a08fe4-f5fb-7610-b4ba-10bdd714f76a)
- [PR #553](https://github.com/robinbeier/forscherhaus-appointments/pull/553) bis [PR #570](https://github.com/robinbeier/forscherhaus-appointments/pull/570), alle gemergt.

Zusätzlich wurden die lokalen Abschlussberichte unter `storage/logs/release-gate/`
gelesen, insbesondere `defense-cycle-final-verification-20260913.md`. Diese
privaten Laufbelege bleiben außerhalb der Versionsverwaltung. Die Auswertung
liest vorhandene Produktionsnachweise; sie führt keine neuen Produktionsprüfungen
durch. Erinnerungseinträge dienten nur zum Auffinden der Primärbelege.

Gesamtarbeitszeit, Modellkosten, Zahl eindeutiger Sicherheitslücken und eine
vollständige Zahl der Plattformabbrüche sind daraus nicht zuverlässig bestimmbar.
Die unten angegebenen PR-Zeiten messen Veröffentlichung bis Merge, einschließlich
Wartephasen. Sie sind weder reine Rechenzeit noch Arbeitszeit.

## a) Was gut lief

1. **Die Kette wurde tatsächlich bis zum ausgelieferten Verhalten geschlossen.**
   Anwendung, Operatorwerkzeuge und Repo wurden getrennt identifiziert:
   Anwendung `acaf9d8962ca78b364227e89eb2f744797bd5336`, finaler
   Werkzeug-Head `2d82e62570b348f426ba95148671e3ba5dba193b`, Merge
   `3ef28886dc4ab7182f6723dbcd1017b611e868b1`. Der finale Bericht hält
   13/13 installierte Werkzeugdateien und 2.446 unveränderte reguläre Release-Dateien
   fest. Ein Werkzeugupdate wurde nicht als neues Anwendungsdeployment ausgegeben.
2. **Kleine, klar begrenzte Korrekturen funktionierten gut.** PRs #553–#556
   hatten jeweils einen Commit und benötigten nach Veröffentlichung ungefähr
   8–19 Minuten bis zum Merge. Der komplexe Konkurrenzfix #557 ist eine wichtige
   Ausnahme: 21 Commits und gut neun Stunden zeigen seine deutlich größere Tragweite.
3. **Reviews hielten reale Fehler zurück.** Die Kommentare betrafen unter anderem
   falsche Bereitschaftsmeldungen, unvollständige Fehlerbelege und riskante
   Bereinigung. Diese Befunde wegzuoptimieren hätte die Nachweise schwächer gemacht.
4. **Fehlversuche wurden erhalten.** Fehler des Prüfclients, ein technisch
   abgebrochener Zusatzscan und ein fehlerhafter lokaler Vergleich blieben als
   fehlgeschlagene Versuche dokumentiert. Spätere Erfolge überschrieben sie nicht.
5. **Der Lauf erzeugte wiederverwendbare Verbesserungen.** Lock-Vertrag,
   Zwei-Verbindungs-Testgerüst, atomare Schreibverträge, Startvorprüfung,
   Testzusammenfassungen, Reviewer-Vorprüfung und Aktionsfreigaben wurden bereits
   in PRs #558–#564 bearbeitet. PR #565 ergänzte verlässliches lokales Aufräumen.
   Diese Arbeit ist Ausgangsbasis für Runde zwei, kein erneut anzulegendes Backlog.

## b) Was nicht gut lief

**Prüfbarkeit und Aufwand wurden zu spät geklärt.** Der erste Prüfrahmen konnte
normale Abläufe und Teilinvarianten belegen, aber nicht alle noch offenen
Berechtigungsgrenzen. Trotzdem floss schon viel Arbeit in zusätzliche Prüf-PRs.
Die Aussage „Prüf-PRs brachten gar nichts“ wäre insgesamt zu hart: #567–#570
lieferten wiederverwendbare Tests, Bereinigung und letztlich den Abschluss.
Richtig ist: Nutzen und verbleibende Lücke wurden vorher nicht präzise genug
benannt; Zwischenfortschritt wirkte teilweise wie bevorstehender Gesamtabschluss.

**Der Prüfclient wurde während der Abnahme entwickelt.** Reale Rückgabeverträge
wurden zunächst falsch angenommen: ein erfolgreicher Account-Save liefert keinen
JSON-Inhalt; ein normaler Login-Redirect verwendet 307; Logout-Cookies mussten
korrekt auslaufen. Beim früheren Konkurrenztest waren Fixture-Berechtigung,
Prüfpunkt und Bereinigung zunächst nicht belastbar. Solche Fehler kosten viel
Zeit, wenn sie erst im Review oder auf Produktion auffallen.

**Der gültige Arbeitsstand war über Tasks und Arbeitskopien verteilt.** Die
Hauptarbeitskopie stand auch bei dieser Retrospektive noch auf `55a410cd`, während
der aktuelle Remote-Stand `3ef28886` war. Die vorhandenen isolierten Arbeitskopien
waren sinnvoll; es fehlte der zuverlässige Einstieg in den neuesten Abschluss.
Alte Übergaben und alte grüne Reviews konnten dadurch wie aktueller Stand wirken.

**Ein guter Produkt-Fix wurde zeitweise zum Werkzeugprojekt.** Fehlerfälle des
Prüfrahmens wurden in mehreren Reviewrunden ergänzt. Ein neues Hilfswerkzeug
eröffnet selbst zusätzliche Fragen zu Besitz, Zustand, Wiederholung und Abbruch.
Das erklärt den Aufwand besser als die pauschale Diagnose „das Modell war zu schwach“.

## c) Die konkret beobachteten Reibungen

### Die 7.200 Sekunden

Es war ein echter Inaktivitätstest der unveränderten Produktionsgrenze, kein
zufälliger Timeout und keine Review-Wartefrist. Der erfolgreiche Lauf wartete
**7.202 Sekunden**. Er belegte, dass die ursprüngliche Sitzungsdatei noch
vorhanden und der Aktivitätswert unverändert war, während der nächste Zugriff
die abgelaufene Identität serverseitig verwarf. Die lokale Variante verwendet
bereits fünf Sekunden und beweist entsprechend nur das isolierte Verhalten.

Der reale Zweistundennachweis lässt sich nicht durch einen kurzen lokalen Test
ersetzen, solange genau diese Produktionsaussage verlangt wird. Er muss aber
nicht bei jeder Dokumentations- oder Werkzeugänderung wiederholt werden:
Der Abschluss am 13.09. übernahm den unverändert gültigen Nachweis vom 12.09.
und prüfte die Anwendungsprovenienz erneut.

Künftig: Zweck, Dauer, Prüfvoraussetzungen und Operationsfenster vor Beginn
sichtbar machen; erst nach funktionierendem kurzen Kontolauf starten. Währenddessen
nur unabhängige lesende oder lokale Arbeit erledigen. Der aktuelle gemeinsame
Produktionslock verhindert parallele Deployments und andere davon abhängige
Arbeiten. Das Fenster ist daher auch für Betrieb und Backupplanung relevant.
[Bestehender Vertrag](../release-gate-defense-cycle.md).

### Bereinigung und Produktion

Hier sind drei verschiedene Dinge auseinanderzuhalten:

- **Lokal:** Verwaiste Docker-Netze erschöpften den Adresspool. Root-eigene
  Testdaten, frühe Abbrüche und Compose-Kompatibilität erschwerten das Aufräumen.
  Das wurde in #565 korrigiert; ein containerloses Netz allein galt zurecht nicht
  als Löschfreigabe. [Konkreter Reviewbefund](https://github.com/robinbeier/forscherhaus-appointments/pull/565#discussion_r3990722854).
- **Im Produktionsprüfrahmen:** Reviews fanden Risiken, dass beim Löschen
  synthetischer Objekte inzwischen entstandene, nicht protokollierte Beziehungen
  mitgelöscht werden könnten. Die Reaktion war Abbruch bei unklaren Abhängigkeiten
  und kontrollierte Wiederherstellung. Das ist ein belegtes Risiko im Review,
  kein Beleg für tatsächlich gelöschte Echtdaten.
  [Konkreter Reviewbefund](https://github.com/robinbeier/forscherhaus-appointments/pull/570#discussion_r3997145347).
- **Im Betrieb:** Der Test hält absichtlich einen gemeinsamen Lock bis zur
  geprüften Bereinigung. Deployment, Backup und Retention müssen warten bzw.
  kontrolliert erneut versuchen. Unvollständige Journale und Pending-Marker
  sperren Folgeaktionen. Der finale Vertrag enthält einen unabhängigen
  Drei-Stunden-Cleanup-Timer; die kürzeren Zusatzfixtures haben ein eigenes
  Zeitfenster. Das ist koordinierter Schutz, darf aber nicht wie ein unerklärter
  Hänger wirken.

Ein konkreter Vorfall „Cleanup löschte die Testsitzung vor Ende der 7.200 Sekunden“
ist in den untersuchten Belegen **nicht nachgewiesen**. Der erfolgreiche
Abschluss belegt gerade die noch vorhandene Datei. Die neue Prozessregel muss
deshalb die nachgewiesene Koordinations- und Wiederholungsreibung adressieren.

### Astra, Daybreak und die verschiedenen Grenzen

Vier unabhängige Fragen wurden wiederholt vermischt:

| Frage | Beleg/Beispiel | Konsequenz |
| --- | --- | --- |
| Kann der konkrete Reviewer in dieser Laufzeit starten? | Ein konfigurierter Reviewer war im ChatGPT-Zugang nicht unterstützt. | Vor dem Diff die vorhandene Runtime-Vorprüfung nutzen; Startfehler ist kein Finding. |
| Darf das Modell diese konkrete Tätigkeit ausführen? | Astra begrenzte weitergehende Prüfungen trotz Nutzerfreigabe. | Grenze vor dem Prüf-PR klären; Ablehnung nicht durch Umformulieren oder Modellwechsel umgehen. |
| Ist die externe Aktion autorisiert? | Linear-Workpad bzw. PR-Anhang waren nicht hinreichend im Aktionsrahmen enthalten. | Bestehende Freigaben übernehmen; nur fehlende Aktion gezielt klären. |
| Kann die Umgebung den Lauf tragen? | Docker-Adresspool, Git-Schreibgrenze, Prüfclientfehler. | Umgebung/Prüfrahmen diagnostizieren; keine Produktschwachstelle daraus ableiten. |

Planungsorientierung, **Stand 13.09.2026**, keine zugesicherte Modellfähigkeit:

| Arbeit | Sinnvolle Besetzung |
| --- | --- |
| Koordination, Architektur, Belegbewertung, anspruchsvolles Review | Astra als primärer Integrator, innerhalb seiner aktuellen Grenzen. |
| Kleine, präzise abgegrenzte Implementierungs-/Dokumentationsaufgabe | Der bereits registrierte Luna-Worker; Hauptagent integriert und prüft. |
| Zugelassene defensive Security-Arbeit | Ein tatsächlich verfügbarer, für den konkreten Zugang freigeschalteter Daybreak-Blue-Zugang; Scope und einzelne Methode bleiben zu prüfen. |
| Nicht unterstützte oder abgelehnte Methode | Begrenzung dokumentieren und eine qualifizierte menschliche Prüfung bzw. offizielle Zugangsklärung organisieren. Keine automatische Ersatzroute für dieselbe Ablehnung. |

Die aktuellen offiziellen Hilfeseiten ordnen Daybreak Blue GPT-5.6 Sol zu.
Sie sagen außerdem, dass reduzierte Ablehnungen für Astra derzeit nicht für
Daybreak-Blue-Kunden verfügbar sind. Daraus folgt: „stärkeres Astra“ und
„passender freigeschalteter Zugang“ sind unterschiedliche Entscheidungen.
Daybreak beseitigt nicht alle Schutzmaßnahmen. Eine sichtbare Modelloption
beweist weder den Kontozugang noch die Zulässigkeit jeder Prüfung.
[Daybreak-Übersicht](https://help.openai.com/en/articles/20001258-trusted-access-for-cyber),
[aktuelle Fehler- und Zugangshinweise](https://help.openai.com/en/articles/20001259-openai-daybreak-common-issues-and-troubleshooting).
Diese Zuordnung vor späteren Runden neu prüfen; hier wurde kein Daybreak-Kontozugang getestet.

Bei der Retrospektive trat der Startfehler erneut direkt auf: Die geladene
Reviewer-Rolle verlangte `gpt-5.4`, das der ChatGPT-Zugang zurückwies. Der
separate Homebrew-Client 0.145.0 war zudem für Astra zu alt. Der bereits
installierte App-Client 0.154.0-alpha.6.2 startete Astra dagegen erfolgreich mit
lesender Sandbox. Das ist eine konkrete Versions-/Konfigurationsdifferenz,
keine Security-Ablehnung. Deshalb wird die Standard-Reviewer-Rolle nun im
Projekt ausdrücklich auf Astra/high mit unveränderter lesender Grenze gebunden.
Die neue Rollenbindung muss in einer frisch geladenen Projektlaufzeit geprüft
werden; der alte Rollenkatalog dieser Session wird dadurch nicht nachträglich geändert.

### Auto-Review und Linear

Mindestens ein Workpad-Abbruch ist direkt belegt. Die Retrospektive im Task
„Prüfe ROB-550 Kalenderrechte“ vom 11.09., 09:18 CEST beschreibt die zu spät
explizierte Linear-Freigabe; daraus entstand ROB-559. Zusätzlich beanstandete
das PR-Review, dass die Vorlage den regulären Linear-PR-Anhang nicht abdeckte.
Die inzwischen ergänzte Vorlage enthält auch Review-Anforderung, Antworten,
Thread-Auflösung, begrenzte CI-Wiederholung und SHA-gebundenen Merge.
[Review zur fehlenden Aktion](https://github.com/robinbeier/forscherhaus-appointments/pull/564#discussion_r3989999013).

Die Plattformprüfung einer externen Mutation, automatische GitHub-Code-Reviews
und Security-Modellgrenzen sind verschiedene Mechanismen. Nicht jeder
unterbrochene Turn lässt sich einem dieser Mechanismen sicher zuordnen.
Eine vollständige Abbruchzählung wäre daher nicht belastbar.

Künftig muss ein abgelehnter Dokumentationsschreibvorgang nicht den bisherigen
Arbeitsstand vernichten: den vorgesehenen Text vorher lokal sichern, ausstehende
Synchronisation kenntlich machen und unabhängige erlaubte Arbeit fortsetzen.
Vor einem späteren Versuch den gespeicherten Zielstand lesen, damit keine
Doppelkommentare entstehen. Der lokale Text ist noch kein Linear-Update.
Die vorhandenen Workpad-Meilensteine und Plattformprüfungen bleiben bestehen.

## Muster in den GitHub-Kommentaren

Über die 18 PRs #553–#570 wurden **48 Inline-Kommentare mit P1/P2-Kennzeichnung**
gezählt. 31 davon entfielen auf #567–#570. Dies sind Kommentare, nicht zwingend
48 unterschiedliche Fehler; mehrere Kommentare können verwandte oder erneut
auftretende Probleme betreffen. Eine False-Positive-Quote wurde nicht ermittelt.

| PR-Gruppe | Commits / ausgewählte Zeitspannen | P1/P2-Inline-Kommentare |
| --- | --- | ---: |
| #553–#556: begrenzte Produktkorrekturen | jeweils 1; etwa 8–19 Minuten | 0 |
| #557: Konkurrenz-/Berechtigungsfix | 21; 9 Stunden 4 Minuten | 2 |
| #558–#564: Vertrags- und Prozessarbeit | #561: 7; 1 Stunde 24 Minuten | 11 |
| #565: lokales Cleanup | 8; 56 Minuten | 4 |
| #566: synthetischer Canary | 1; 16 Minuten | 0 |
| #567–#570: Evidenz- und Live-Prüfrahmen | #570: 10; 10 Stunden 58 Minuten | 31 |

Das wiederkehrende Problem war **Zustand über mehrere Schritte hinweg**:
Welche Umgebung wurde geprüft? Gehört eine Zeile noch der Fixture? Wurde ein
Request beendet? Ist eine Quittung dauerhaft gespeichert? Bleibt der erste
Erfolg bei einer Wiederholung erhalten? Wurden Fehler als `unknown` bzw.
fehlgeschlagen gemeldet statt als `ready`?
[Beispiel falscher Bereitschaft](https://github.com/robinbeier/forscherhaus-appointments/pull/561#discussion_r3988748342).

Ein leerer Security-Reviewbefund ersetzte kein Korrektheitsreview: Auch nach
solchen Security-Ergebnissen fanden normale Code-Reviews relevante P1/P2-Probleme.
Die Lehre ist, diese Perspektiven beizubehalten und bekannte Fehlerklassen vor
Veröffentlichung zu prüfen. Korrekturen sinnvoll bündeln; nach jeder Änderung
CI und unabhängiges Review für den aktuellen Head gemäß bestehendem Workflow
erneuern. Ein grünes Review eines alten Heads zählt nicht für neue Änderungen.

## d) Konsequenzen für weitere Runden

### Bereits umgesetzt und wiederzuverwenden

| Reibung | Vorhandene Lösung |
| --- | --- |
| Implizite Lock-Reihenfolge und unklare Transaktionen | PRs #558–#560, [Lock-Vertrag](../database-lock-order.md), [Schreibverträge](../ci-write-contracts.md) |
| Späte Umgebungsfehler und laute Gate-Ausgabe | PRs #561–#562, [Startvorprüfung](../local-start-preflight.md), [Diagnosezusammenfassung](../gate-diagnostic-summary.md) |
| Reviewer startet nicht | PR #563, [Runtime-Vorprüfung](../reviewer-runtime-preflight.md) |
| Fehlende Einzelaktionen im Freigaberahmen | PR #564, [Autorisierungsvorlage](../ticket-mutation-authorization.md) |
| Hinterlassene temporäre Docker-Ressourcen | PR #565, vorhandener fokussierter Testaufruf und Lifecycle-Helfer |
| Nicht ausreichende Produktionsnachweise | PRs #566–#570, [bestehender Evidenzvertrag](../release-gate-defense-cycle.md) |

### Mit dieser Retrospektive lokal eingearbeitet

1. **Ein gemeinsamer Einstieg:** [Defense-Factory-Leitfaden](../defense-factory.md),
   verlinkt aus AGENTS, WORKFLOW und Harness-Index. Er übernimmt bestehende
   Nachweise und unterscheidet Anwendung, Werkzeugstand und Repository.
2. **Nutzen vor Prüf-PR:** Eine kurze Notiz benennt Invariante, vorhandene Lücke,
   erlaubte und verfügbare Methode, erwarteten neuen Beleg, Eigentümer,
   Bereinigung und Dauer. Dafür werden vorhandener Bericht und Workpad genutzt.
3. **Planbare Wartezeit:** Operationsfenster und Nachweiswiederverwendung
   ausdrücklich prüfen; keine neue automatische Produktionsaufgabe einrichten.
4. **Fortsetzen nach Schreibblockade:** Lokaler Checkpoint, offene Synchronisation
   und erneutes Lesen des Zielstands werden in der Autorisierungsvorlage erklärt.
5. **Fehlerarten getrennt behandeln:** Modellgrenze, fehlender Zugang,
   Plattformablehnung, Umgebungsfehler und fachliches Testergebnis bekommen
   unterschiedliche nächste Schritte.
6. **Den tatsächlich erneut beobachteten Reviewer-Startfehler korrigieren:**
   Projektregistrierung und explizite Modellbindung ergänzen; ein Regressionstest
   liest die effektiven Konfigurationsdateien und prüft Modell sowie lesende Grenze.

Diese Änderungen betreffen Anleitung und die Reviewer-Konfiguration. Sie führen keine neuen
Prüfprogramme ein, ändern keine Freigabegrenze und lockern keine CI- oder
Produktionsanforderung. Veröffentlichung und Merge sind getrennte Schritte.

### Was die nächste Runde zeigen soll

Über die konkret korrigierte Reviewer-Konfiguration hinaus sind anhand dieser
Belege **keine weiteren spekulativen Infrastruktur-PRs erforderlich**. Die Verbesserungen müssen nun
im tatsächlichen Ablauf genutzt werden. Technische Restfehler werden anhand
konkreter neuer Belege bearbeitet, nicht durch erneuten Aufbau desselben Systems.

Für die nächste Runde drei einfache Werte im vorhandenen Laufbericht sammeln:

- menschliche Unterbrechungen, jeweils mit Ursache und betroffener Aktion;
- Zeit bis zum verifizierten Abschluss, getrennt von fachlich nötiger Wartezeit;
- zusätzliche Prüfrahmenkorrekturen nach dem ersten Review, mit geschlossenem
  Nachweisbedarf.

Erfolg wäre: vorhandene Ergebnisse ohne erneute Rekonstruktion übernehmen,
keine bekannten Start-/Freigabefehler wiederholen und die neuen Nachweise mit
weniger Prüfrahmenänderungen liefern. Erst danach lässt sich belastbar entscheiden,
welche weitere Automatisierung wirklich Zeit spart.
