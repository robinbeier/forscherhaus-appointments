# Vorlage: Autorisierung für Ticket- und Repository-Mutationen

Diese Vorlage bindet eine konkrete Aufgabe an Ticket, Repository und Scope.
Die ausgefüllte Vorlage ergänzt den bestehenden Auftrag. Explizites `NEIN`
sperrt die betreffende Aktion; `NICHT ERTEILT` und leere Felder erteilen keine
neue Freigabe. Bereits gültige Freigaben aus derselben Session bleiben
maßgeblich, solange der Nutzer sie nicht ausdrücklich ändert. Bei einem
echten Widerspruch wird nur die betroffene Aktion geklärt.

## Startfreigabe

```text
Ticket: <Ticket-ID oder Platzhalter>
Repository: <Repository-Pfad oder URL-Platzhalter>
Branch/Arbeitsstand: <Branch/Ref-Platzhalter>
Scope: <konkrete Dateien, Module und Fragen>
Base/Head: <Commit- oder Ref-Platzhalter, sobald bekannt>

Bitte je Zeile genau eine Auswahl setzen: JA / NEIN / NICHT ERTEILT.
Nicht gesetzte Felder bleiben offen und erteilen keine neue Berechtigung.

Read-only-Diagnose und Review: <JA|NEIN|NICHT ERTEILT>
Lokale Dateien ändern und lokale Tests ausführen: <JA|NEIN|NICHT ERTEILT>
Linear-Codex-Workpad aktualisieren: <JA|NEIN|NICHT ERTEILT>
Linear-Status ändern: <JA|NEIN|NICHT ERTEILT>
Commit erstellen: <JA|NEIN|NICHT ERTEILT>
Push ausführen: <JA|NEIN|NICHT ERTEILT>
PR erstellen und Beschreibung schreiben: <JA|NEIN|NICHT ERTEILT>
Auf Review-Kommentare dieses PRs antworten: <JA|NEIN|NICHT ERTEILT>
Review-Thread dieses PRs auflösen: <JA|NEIN|NICHT ERTEILT>
SHA-gebundener Merge des final geprüften PR-Heads: <JA|NEIN|NICHT ERTEILT>
Optionale Einschränkung auf einen bestimmten Commit: <SHA oder keine>
```

Die Felder für Workpad und Review-Antworten erlauben jeweils nur diese
Kommentare am bezeichneten Ticket beziehungsweise PR. Andere Nachrichten
oder externe Kommunikation benötigen einen eigenen Auftrag. Plattformseitige aktuelle Bestätigungen und
Freigabeprüfungen gelten weiterhin; diese Vorlage umgeht keine Policy,
erteilt keine Credentials und erweitert keine Toolrechte.

## Grenzen und Reihenfolge

- Produktion, Deployment und sonstige externe Nachrichten sind standardmäßig
  ausgeschlossen. Andere irreversible Aktionen bleiben außerhalb der Vorlage.
- Der SHA-gebundene Merge ist eine eigene externe und irreversible Auswahl.
  Er erteilt weder Produktions- noch Deployment-Berechtigung. Eine Freigabe
  für den final geprüften PR-Head kann vor Arbeitsbeginn erfolgen: unmittelbar
  vor dem Merge werden tatsächlicher Head, grüne Checks und Reviews erneut
  geprüft und der Merge an genau diesen SHA gebunden. Ein fest vorgegebener
  SHA schränkt diese Freigabe zusätzlich ein.
- Eine materielle Erweiterung über den vereinbarten Auftrag hinaus braucht
  eine gezielte zusätzliche Freigabe. Übliche Dateiänderungen innerhalb des
  bereits autorisierten Scopes werden selbstständig umgesetzt.
- Alle bestehenden Tests, Reviews und Exact-Head-Gates bleiben erforderlich.
  Es gibt keine vorausgefüllte Auto-Freigabe.
- Vor einer noch nicht freigegebenen Mutation wird, soweit möglich, zuerst ein
  konkretes prüfbares Ergebnis vorbereitet. Danach wird genau eine gebündelte
  Frage zu den fehlenden Aktionen und ihrem Scope gestellt, mit Begründung
  und Quelle der Freigabegrenze. Bereits
  autorisierte unabhängige Arbeit läuft weiter; bekannte Freigaben werden nicht
  erneut abgefragt.

## Übergabe

```text
Ticket: <Ticket-ID>
Repository: <Repository>
Scope: <Dateien/Module>
Base: <exakter Base-SHA>
Head: <exakter Head-SHA>
Erledigt und geprüft: <konkretes Ergebnis und Tests>
Autorisierte Aktionen: <Liste mit JA und Scope>
Nicht erteilt/offen: <Liste der fehlenden Aktionen>
Externe Nebenwirkungen: <keine oder konkret autorisierte Wirkung>
Nächste gebundene Entscheidung: <eine präzise Frage oder „keine“>
```
