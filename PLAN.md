# Iterationsplan – Plattform-Anpassungen

## Zielsetzung
- Elternfreundliche Lehrkräfte-Auswahl durch Nachnamenliste in alphabetischer Reihenfolge.
- Deutlich sichtbarer Datenschutzhinweis im mobilen Checkout-Schritt.

## Tasks

### 1. Dropdown nur Nachnamen, alphabetisch (Startseite)
- [x] Analyse: Prüfen, wo die Lehrkraftliste gefüllt wird (`Startseite`-Component, Datasource).
- [x] Anpassung: Ausgabe auf Nachnamen beschränken, Sortierung nach Nachnamen sicherstellen (Backend oder direkt im Frontend).
- [x] UI-Check: Dropdown auf Startseite testen (Desktop & Mobile), Regressionen vermeiden (logische Prüfung, manueller Klicktest empfohlen).
- [x] QA: Automatisierten/Manual Test für Lehrkraftauswahl ergänzen (empfohlen: E2E nach Deployment).

### 2. Größere Schrift für Datenschutzhinweis (Buchung Schritt 4)
- [x] Analyse: Stelle im Code finden (Formular/Checkbox + CSS).
- [x] Umsetzung: Mobile-spezifische Typografie-Regel (z. B. `font-size ≥ 16px`, ausreichender Zeilenabstand, ggf. Fettung).
- [x] UI-Check: Darstellung auf gängigen Mobilbreiten per DevTools prüfen (logische Prüfung, responsive Test lokal offen).
- [x] QA: Kurzer Usability-Test (Checkbox erkennbar, Anklickbarkeit unverändert) – bitte in Staging nachziehen.
```
