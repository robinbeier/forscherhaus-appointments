# Bestätigungsseite – Verbesserungsplan

## Zielüberblick
- Nutzer:innen sichern nach der Buchung zuverlässig ihre Termindaten (Kopie, Kalender-Export oder PDF).
- Ein optionaler PDF-/Screenshot-Export stellt alle relevanten Angaben inklusive Management-Link und QR-Code bereit.
- Ein Exit-Intent-Guard verhindert das unbeabsichtigte Verlassen der Seite, solange keine Sicherung erfolgt ist.

## ToDo (zum Abhaken)
- [ ] **T7 – Exit-Intent / Before-Unload-Guard**
  - [ ] Prüfe, ob bestehende Events/Hooks für Kopieren/Kalenderexport genutzt werden können; andernfalls neue Listener ergänzen.
  - [ ] Guard nur aktivieren, solange `savedState === false`.
  - [ ] Sicherungsereignisse (copy, calendar export, pdf) setzen `savedState = true`.
  - [ ] Guard-Dialog mit Text „Verwaltungslink noch nicht gesichert – jetzt kopieren?“ (DE) hinterlegen; zusätzliche Sprachen aktuell nicht erforderlich.
- [ ] **T8 – PDF-/Screenshot-Export**
  - [x] Utility-Menü um optionalen CTA ergänzen (`PDF speichern` mit Icon `fa-solid fa-file`).
  - [x] Termin-Daten (Titel, Person/Raum, Datum, Uhrzeit, Dauer) in PDF übernehmen.
  - [x] Management-Link als klickbare URL + QR-Code (`manageUrl`) ausgeben.
  - [x] PDF-Gestaltung gemäß `pdf/template.html` und Branding-Vorgaben umsetzen.
  - [x] Build-Prozess um `html2canvas`, `jsPDF`, `qrcode` erweitern und Verbau testen.
  - [ ] PDF/A-2b-Validierungs-Check als Post-MVP-Folgeaufgabe dokumentieren.
  - [x] Dateinamen-Schema `Terminbestätigung-<Datum>.pdf` (z. B. `Terminbestätigung-2025-11-27.pdf`) implementieren.
- [ ] **T9 – PDF Layout v4 (Polish)**
  - [ ] Header umstellen: „Terminbestätigung“ links, Logo+Schulname rechts (Logo ca. 24 pt) mit sauberer Baseline.
  - [ ] Banner weiter straffen (Padding 8–10 pt, kompakter Abstand nach unten).
  - [ ] Datums-/Zeitzeile gegen Umbrüche absichern (`Mi., 26.11.2025 · 14:00–14:30 Uhr (30 Min)` mit geschützten Leerzeichen).
  - [ ] Spaltenausrichtung verfeinern: Content- und QR-Spalte oben bündig, Gap ≈ 18 mm.
  - [ ] Linkbox robust für lange URLs (nur Border, Unterstreichung, `word-break`/`overflow-wrap`).
  - [ ] QR-Block inkl. Caption prüfen (32 mm, gleichmäßige Quiet Zone).
  - [ ] Footer dezenter (Spacing 16–18 mm, Farbe #5A6270), Metadaten & Dateigröße ≤ 300 KB verifizieren.

## Akzeptanzkriterien
- T7: Beforeunload-Dialog erscheint ausschließlich, wenn `savedState === false`; Texte entsprechen der Spezifikation; nach Sicherung entfällt der Guard.
- T8: PDF-Export erzeugt ein A4-Dokument mit korrekten Termin-Daten, klickbarem Management-Link und funktionierendem QR-Code (`manageUrl`); CTA im Utility-Menü löst Download aus; Build-Prozess integriert alle Bibliotheken ohne Fehler.
- T9: PDF v4 erfüllt alle Detailanforderungen (Header-Layout, kompaktes Banner, stabile Datumszeile ohne Umbruch, robuste Linkbox, saubere Spaltenausrichtung/QR, dezenter Footer, Metadaten & Größe ≤ 300 KB).

## Definition of Done – PDF
- **Branding:** Logo korrekt eingebettet, Farben aus Vorgabe, spezifische Schrift eingebettet (keine Fallbacks), Seitenränder 18–22 mm, genau eine A4-Seite.
- **Inhalte:** Titel, Lehrkraft, Raum, Datum/Uhrzeit mit Zeitzone, Dauer, Referenz-ID enthalten; Verwaltungslink sichtbar und klickbar (PDF-Annotation); QR-Code leitet exakt zu `manageUrl`.
- **Typografie & Lesbarkeit:** Fließtext ≥ 10.5 pt, Überschriften laut Template, Kontrast mindestens 7:1, automatische Worttrennung/Zeilenumbruch für lange URLs.
- **Internationalisierung:** Datum/Uhrzeit im `de-DE`-Format (z. B. „Do, 27.11.2025, 09:00–09:25 Uhr“); wenn Server- und Nutzer-TZ abweichen, Hinweis im Text.
- **Technik:** PDF-Metadaten `Title`, `Author`, `Subject` gesetzt; Dateigröße ≤ 300 KB (optimierte Assets); öffnet fehlerfrei in Acrobat/Preview/Chrome; optional PDF/A-2b-kompatibel.
- **Sicherheit & Privatsphäre:** `manageUrl` enthält keine zusätzlichen personenbezogenen Daten; alle Assets eingebettet, kein externes Nachladen.

## Offene Fragen
- Aktuell keine (wird ergänzt, sobald neue Punkte auftauchen).
