# PLAN: Innenscroll der Uhrzeitenliste entfernen (Booking Schritt „Datum & Uhrzeit“)

## Ziel
Die **Uhrzeitenliste** im Buchungsschritt „Datum & Uhrzeit“ soll **nicht mehr** in einem eigenen, intern scrollbaren Container liegen.
Stattdessen wächst die Liste in der Seitenhöhe und **die Seite selbst** scrollt.

## Nicht‑Ziele
- **Keine** neuen Texte, Badges, Hints, Zähler, Sticky‑Footer oder Pagination.
- **Keine** Änderungen am Buchungsfluss, an Validierungen oder Business‑Logik.

## Branch
```bash
git checkout -b feat/booking-remove-inner-scroll
```

## Vorgehen (High‑Level)
1) **Zeitlisten‑Container im Markup identifizieren** (Schritt 2 der Buchungsstrecke).
2) **Inline‑Styles** entfernen, falls dort `max-height`, `height` oder `overflow(-y)` gesetzt sind.
3) **CSS/SCSS‑Regeln** finden, die den Container begrenzen; diese **entfernen** oder **überschreiben** (`max-height: none; height: auto; overflow: visible`).
4) **JS‑Logik** entfernen/deaktivieren, die die Höhe des Containers dynamisch setzt.
5) **Build & Test** (Mobile/Desktop) – sicherstellen, dass die Seite (nicht die Liste) scrollt und keine Inhalte abgeschnitten werden.
6) **Commit/PR** erstellen.

---

## To‑Dos für Codex (schrittweise, ausführen in Repo‑Wurzel)

### A) Selektoren & Dateien finden
- [✅] **DOM‑Knoten der Uhrzeitenliste** ermitteln (Booking‑Step‑Template in `application/views/appointments/`).
- [✅] **Bezeichner suchen** (Kandidaten: `time-list`, `available-hours`, `appointment-hours`, `time-slots`):
  ```bash
  git grep -nE "time[-_ ](list|slot|slots)|available[-_ ](hours|times)|appointment[-_ ]hours|time-slots" -- application assets
  ```
- [✅] **Style‑Begrenzer finden** (repo‑weit):
  ```bash
  git grep -nE "max-height|overflow-y|overflow:\s*auto|height:\s*\d" -- assets application
  ```

### B) Markup bereinigen (nur entfernen, nichts hinzufügen)
- [✅] Im View/Template den **Container der Uhrzeitenliste** öffnen (z. B. `application/views/appointments/book.php`).
- [✅] **Entferne** Inline‑CSS wie `style="max-height:…; overflow-y:auto;"` **am Zeitlisten‑Container** und ggf. **bei dessen direkten Eltern**.
- [✅] **Keine** weiteren strukturellen Änderungen am HTML.

### C) CSS/SCSS anpassen
- [✅] In `assets/` die Regel(n) finden, die den Zeitlisten‑Container begrenzen (z. B. `.available-hours`, `.time-list`, o. ä.).
- [✅] Diese Begrenzungen **entfernen** oder **gezielt überschreiben**.
- [✅] **Override** (am Ende der betroffenen CSS/SCSS‑Datei einfügen; *Selektoren an die gefundene Struktur anpassen*):
  ```css
  /* Remove inner scroll on booking time list */
  .booking-step .available-hours,
  .booking-step .time-list,
  .booking-step [data-ea-component="time-list"] {
    max-height: none !important;
    height: auto !important;
    overflow: visible !important;
  }
  ```

### D) JS überprüfen
- [✅] In `assets/js/` nach Code suchen, der die Höhe der Zeitliste setzt oder bei `resize`/`step change` berechnet:
  ```bash
  git grep -nE "scroll|overflow|maxHeight|clientHeight|set.*Height|resize" -- assets/js
  ```
- [✅] Entsprechende **Höhenberechnung entfernen/deaktivieren** (keine neue Logik hinzufügen).

### E) Build & Test
- [ ] Abhängigkeiten & Build (gemäß Projekt‑README):
  ```bash
  npm install
  composer install
  npm start        # Dev‑Watcher
  # oder
  npm run build    # gebündelter Build
  ```
- [ ] **Visuelle Tests**:
  - iOS Safari & Android Chrome, plus Desktop (Chrome/Firefox/Safari).
  - Buchung Schritt „Datum & Uhrzeit“ öffnen.
  - **Erwartung:** Die **Seite scrollt**; die Zeitliste zeigt unterhalb der sichtbaren Zeiten **weitere Einträge**, ohne innere Scrollbar.
  - **Kein** Abschneiden des letzten Eintrags. **Keine** doppelte Scrollbarkeit (nur Body scrollt).

### F) Git & PR
- [ ] Commit:
  ```bash
  git add -A
  git commit -m "feat(booking): remove inner scroll on time list; use page scroll"
  git push --set-upstream origin feat/booking-remove-inner-scroll
  ```
- [ ] PR eröffnen mit kurzem Vorher/Nachher‑Screenshot & Testnotizen.

---

## Akzeptanzkriterien (Definition of Done)
- Die Uhrzeitenliste hat **keinen** eigenen Scroll mehr (`overflow-y` o. ä. entfernt).
- **Nur der Seiten‑Body** scrollt.
- **Alle** verfügbaren Zeiten sind durch **Seiten‑Scrollen** erreichbar.
- Keine Layout‑Regression auf Mobile/Tablet/Desktop.
- **Keine** neuen UI‑Elemente oder Texte hinzugefügt.

## Rollback
- PR revertieren oder Branch zurücksetzen; Änderungen betreffen ausschließlich Markup/CSS/ggf. entferntes JS – **niedriges Risiko**.
