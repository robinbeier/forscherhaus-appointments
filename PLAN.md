# Bestätigungsseite – Verbesserungsplan

## Zielüberblick
- Terminabschluss führt Nutzer:innen gezielt zum Sichern des Buchungs-Links (kopieren, teilen, Kalender, PDF) auf allen Endgeräten.
- UI-Elemente und Microcopy machen die Bedeutung des Buchungs-Links eindeutig und nutzen konsistente Benennungen.
- Tablet-Layout ordnet Utility-Links klar unter den Primäraktionen an und bleibt optisch ruhig.
- Web Share API stellt vorbefüllte Inhalte (Titel, Datum, Ort, Link) bereit und rundet das Teilen-Erlebnis ab.

## ToDo (zum Abhaken)
- [x] **T10 – Copy & CTA-Benennung anpassen**
  - [x] Hinweistext über dem Link zu „Bitte jetzt sichern: Nur mit diesem Link können Sie Ihre Buchung später ändern oder stornieren.“ aktualisieren.
  - [x] Primary-Button-Label von „Verwaltungslink kopieren“ auf „Buchung-Link kopieren“ umstellen.
  - [x] Hinweis unter dem Link auf „Der Buchungs-Link wird im Kalendereintrag gespeichert.“ ändern.
- [x] **T11 – Datumsformat & Metadaten**
  - [x] Anzeige des Terminzeitraums nach Muster „Mi., 26. Nov. 2025, 14:00–14:25“ (Start- und Endzeit inkl. Wochentag/Monat) implementieren.
  - [x] Sicherstellen, dass Formatierung lokalisiert (`de-DE`) erfolgt.
- [x] **T12 – Tablet-Layout unter Primäraktionen**
  - [x] Nur auf dem Tablet-Layout eine neue Zeile unter den Primärbuttons einfügen, die die beiden Utility-Links enthält.
  - [x] Nur auf dem Tablet-Layout die Utility-Links zentriert unter den Buttons anordnen.
  - [x] Design des Mobile- und Desktop-Layouts bleibt unverändert.
- [x] **T13 – Web Share Payload prüfen**
  - [x] Teilen-Button öffnet das native Share-Sheet auf unterstützten Geräten (Bestandsverhalten verifiziert).
  - [x] Web Share API Payload mit Titel, Datum, Ort und Buchungs-Link vorbefüllen.


## Akzeptanzkriterien
- T10: Copy und Button-Benennungen entsprechen dem neuen Wortlaut (inkl. Groß-/Kleinschreibung); Labels greifen in allen Ansichten, automatische Tests/Screenshots aktualisiert.
- T11: Datumsanzeige entspricht exakt dem Format „Mi., 26. Nov. 2025, 14:00–14:25“ mit lokalem Wochentag/Monatsnamen; Endzeit stimmt mit Terminlänge;
- T12: Tablet-Layout zeigt Utility-Links in einer eigenen, zentrierten Zeile unter den Primärbuttons; keine Überlappungen bei 768–1024 px Breite; mobile/desktop bleiben unverändert.
- T13: Share-Button löst natives Share-Sheet aus (sofern unterstützt) mit vorbefülltem Titel, Datum, Ort, Buchungs-Link; Browser ohne Web Share API erhalten einen passenden Fallback.


## Offene Fragen
- Aktuell keine; Sprachumfang bleibt vorerst deutsch, Share-Fallback-Richtlinien sind nicht erforderlich.
