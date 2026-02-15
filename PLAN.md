# Dashboard Targets & Exports – PLAN

## Zielbild

-   Dashboard-Utilisation nutzt Zielgrößen (`target`) statt Slot-Summen als Nenner.
-   Konfliktkennzeichnung basiert auf einem administrierbaren Mindestfüllgrad.
-   Admins erhalten zwei PDF-Exporte (Lehrkraft-Sammel-PDF mit allen Lehrkräften, Schulleitung gesamt).
-   Pro Lehrkraft zeigt das Dashboard die Relation „Slots geplant / benötigt“, damit Kapazitätslücken sofort sichtbar werden.

## Architektur-Notizen

-   Default-Zielgröße wird als `class_size_default` direkt am Provider gepflegt (Feld in `users`-Tabelle).
-   Dashboard-Metriken liefern konsolidierte Kennzahlen (`target`, `booked`, `open`, `fill_rate`, `needs_attention`, `has_plan`, `slots_planned`, `slots_required`, `has_capacity_gap`) gefiltert nach Zeitraum, Service, Status.
-   `Provider_utilization::calculate()` ist Quelle für Slot-Kapazitäten (`total` Slots, `capacity_minutes`); `Dashboard_metrics` reichert die Daten um Zielgrößen und Gap-Flags an.
-   PDF-Rendering läuft über den Headless-Chrome-Sidecar `pdf-renderer`; PHP bindet ihn über `Pdf_renderer`, Views liegen unter `application/views/exports/`.
-   Exporte werden direkt gestreamt, es entstehen keine temporären Dateien auf dem Dateisystem.

## Arbeitspakete

### 1. Dashboard – Slots-vs-Ziel

-   `application/libraries/Dashboard_metrics.php` erweitern:
    -   Kapazitätswerte (`total` Slots) und Ziel (`target`) zusammenführen → Felder `slots_planned`, `slots_required`.
    -   Flag `has_capacity_gap` setzen, wenn geplante Slots < benötigte Slots; JSON-DTO aktualisieren.
    -   Bestehende Sortierung (nach `fill_rate`) und Fallback-Handling unverändert lassen.
-   `application/controllers/Dashboard.php` sowie `assets/js/http/dashboard_http_client.js` prüfen, ob zusätzliche Script Vars / Payload-Anpassungen nötig sind (voraussichtlich nein, aber DTO-Dokumentation aktualisieren).
-   `assets/js/pages/dashboard.js`:
    -   Tabellenrenderer erweitern: Unterhalb des Lehrkraftnamens zweite Zeile `Slots: {planned} / {required}` ausgeben (Fallback `—`).
    -   Optionales Visual für Kapazitätslücken (z. B. Badge/Icon), falls `has_capacity_gap`.
    -   Tooltips/Labels lokalisieren (neue Lang-Keys, z. B. `dashboard_slots_summary`).
-   Styles (`assets/css/general.scss`):
    -   Kleine Typografie (8.5–9 pt, grau) und Abstände für die Zusatzzeile definieren.
    -   Responsive Verhalten der Tabelle testen (Mobile Ansicht).

### 1b. Schulleitungsreport – Slots-vs-Ziel

-   `application/views/exports/dashboard_principal_pdf.php` anpassen:
    -   Slot-Kennzahlen (`slots_planned`, `slots_required`) aus dem bestehenden Dashboard-DTO beziehen.
    -   Unterhalb des Lehrkraftnamens identische Slots-Zeile rendern (`Slots: {planned} / {required}`), inklusive Kapazitätslücken-Badge bei `has_capacity_gap`.
-   `Dashboard_export::principal_pdf()` überprüfen, ob DTO-Felder bereits durchgereicht werden; andernfalls Payload erweitern.
-   Styles innerhalb des PDF-Templates oder der zugehörigen SCSS/CSS ergänzen, damit Typografie und Abstände zur Dashboard-Darstellung passen.
-   Lokalisierung der neuen Labels übernehmen (de/en), falls nicht bereits vom Dashboard wiederverwendbar.

### 2. Lehrkräfte-Sammel-PDF

-   `Dashboard_export::teacher_pdf()` implementieren:
    -   Filter/Zeitraum analog zu `principal_pdf()` normalisieren (Admin-Guard, Fehlermeldungen).
    -   `Dashboard_metrics::collect()` wiederverwenden; pro Lehrkraft Kennzahlen + Terminliste aufbereiten.
    -   Ergebnis als Attachment streamen (`dashboard-lehrkraefte-YYYYMMDD-YYYYMMDD.pdf`).
-   View `application/views/exports/dashboard_teacher_pdf.php` erstellen:
    -   Gestaltung an den Schulleitungsreport anlehnen (Typografie, Farbwelt, Inter-Stack).
    -   Je Lehrkraft Kopfbereich mit Zeitraum, Service/Status-Chips, KPI-Zeile sowie Tabelle/Callout (Terminliste, offene Familien, Outreach-Hinweis).
    -   `@page`-Regeln, Page-Break je Lehrkraft, CSS für Donut/Balken direkt im Template.
-   Routing ergänzen: `$route['dashboard/export/teacher.pdf']['get'] = 'dashboard_export/teacher_pdf';` und Dashboard-Buttons auf Fehlerfälle testen.
-   Dokumentation/Tooltip im Dashboard aktualisieren, sobald das Layout final ist.

### 3. Heatmap-Auslastung

-   Neue Library `Dashboard_heatmap` aggregiert bestätigte Buchungen nach Zeit-Slots (Standard 30 Minuten bzw. Service-Dauer ≥ 30 Minuten) und cached Ergebnisse 60 Sekunden.
-   Endpoint `dashboard/heatmap` (POST) übernimmt Datums-, Status- und Service-Filter, liefert JSON `{meta, slots}` inkl. 95. Perzentil (Farbnormierung) und validiert mit HTTP 422.
-   OpenAPI sowie `application/config/routes.php` dokumentieren/registrieren den Endpoint; Zugriffe nur für Admins.
-   Dashboard-Dropdown ergänzt Heatmap-Eintrag, neue Card rendert Chart.js-Matrix-Heatmap inkl. Legende, Kontext-Badge, Fehlermeldungen & barrierefreier Tabelle.

## Konfiguration & Settings-Verwaltung

-   Keine neuen Settings erforderlich; `dashboard_conflict_threshold` bleibt Drehpunkt für Konflikt-Logik.
-   Prüfen, ob Kapazitäts-Texte zusätzliche Übersetzungen benötigen (de/en Sprachpakete pflegen).

## Qualitätssicherung & Release-Vorbereitung

-   `composer test` ausführen (neue/angepasste Tests für Dashboard-Metriken und Controller).
-   `npm run build`, um aktualisierte JS/CSS-Bundles in `build/` zu generieren.
-   Manual Smoke-Test: Dashboard filtert korrekt, Slots-Zeile erscheint, Kapazitätslücken erkennbar.
-   Export-Buttons (Schulleitung, Lehrkräfte) durchklicken; PDFs öffnen ohne Fehler.

## Offene Punkte

-   Slots-Zeile: bei Services mit unterschiedlichen Dauern prüfen, ob Slot-Berechnung (GGT) die reale Kapazität ausreichend abbildet.
-   Visuelle Hervorhebung für Kapazitätslücken (Badge/Icon) noch festlegen.
-   Lehrkräfte-PDF: pro Lehrkraft eigenständige Seite mit sauberem Page-Break sicherstellen.
-   Texte & Labels der PDF-Templates konsistent auf Deutsch halten; bei Bedarf Übersetzungen ergänzen.
-   Zielgrößen bleiben ausschließlich im Provider-Stammdatensatz pflegbar; Dashboard/Exports zeigen nur an.

## Offene Fragen

-   Wann bewerten wir den stabilen Betrieb des `pdf-renderer` (Monitoring, Security-Hardening, Betriebsprozesse)?
-   Sollen Kapazitätswarnungen (Slots < Ziel) zusätzlich in den Exporten erscheinen?

## Tests

-   Unit: `DashboardMetricsTest` um Slots/Gap-Felder erweitern; Controller-Test auf neue JSON-Struktur aktualisieren.
-   Unit: `ProvidersModelTest` (Persistenz `class_size_default`) noch offen.
-   Frontend: Jest/Integration falls vorhanden (ansonsten manuell) für Rendering der Slots-Zeile und Badge.
-   Manual: Dashboard-Filter-Kombinationen, Kapazitätslücke reproduzieren, Exporte abrufen.
