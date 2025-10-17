# PLAN: Booking Confirmation Desktop Grid Refresh

## Ziel
- Desktop-Bestätigungsseite wirkt ruhiger, weil Banner, Details, Sicherungs-Block und Utilities in drei Reihen und zwei Spalten klar gruppiert sind.
- Mobile und Tablet Layouts bleiben unverändert.

## Umsetzungspunkte und Abnahmekriterien
- [ ] **T1 - Grid-Reorganisation (3 Reihen, 2 Spalten)**
  Umsetzung: Grid mit Reihen A (Erfolg-Banner über beide Spalten), B (links Termindetails, rechts Sicherungs-Block) und C (links Ändern/Stornieren, rechts Utilities) ab Desktop übernehmen, align-items:start setzen.
  Abnahme: Oberkante Details und Sicherungs-Block bündig, Ändern/Stornieren und Utilities teilen sich eine Zeile, Utilities-Höhe entspricht 48 px.
- [ ] **T2 - Sicherungs-Block als Card**
  Umsetzung: Hinweis "Wichtig..." plus Primary CTA "Verwaltungslink kopieren" und Secondary CTA "Zum Kalender hinzufügen" innerhalb einer Card bündeln, 12 px Abstand zwischen Hinweis und Buttons sowie zwischen den Buttons einhalten.
  Abnahme: In Reihe B steht rechts ausschließlich diese Card, keine Utilities zwischen den Buttons.
- [ ] **T3 - Utilities in Reihe C (rechts)**
  Umsetzung: Utilities ("Teilen", künftig "PDF speichern") als einzeilige Leiste in `.utilities` mit Höhe `--btn-h`, align-items:center und gap:16 px platzieren.
  Abnahme: Utilities sind vertikal mittig zur linken Ghost-Action ausgerichtet.

## QA-Checkliste (DoD)
- [ ] Reihe-B-Oberkanten (Details vs. Save) sind pixelgenau ausgerichtet.
- [ ] Reihe-C-Ausrichtung: Ghost-Button links und Utility-Leiste rechts haben gleiche Höhe.
