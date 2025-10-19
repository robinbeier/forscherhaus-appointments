# Dashboard Targets & Exports – PLAN

## Zielbild

-   Dashboard-Utilisation nutzt künftig Zielgrößen (`target`) statt Slot-Summen als Nenner.
-   Konfliktkennzeichnung basiert auf einem administrierbaren Mindestfüllgrad.
-   Admins erhalten zwei PDF-Exporte (Lehrkraft-Sammel-PDF mit allen Lehrkräften, Schulleitung gesamt).

## Architektur-Notizen

-   Default-Zielgröße wird als `class_size_default` direkt an Provider (`users`-Tabelle mit `ea_`-Präfix) geführt.
-   Dashboard-Backend liefert konsolidierte Kennzahlen (`target`, `booked`, `open`, `fill_rate`, `needs_attention`) gefiltert nach Zeitraum, Service, Status.
-   Serverseitiges PDF-Rendering via `dompdf/dompdf` (Composer-Dependency) mit Templates unter `application/views/exports/`.
-   Dateien werden on-the-fly gestreamt; Sammel-PDFs entstehen serverseitig ohne Zwischenablage auf dem Dateisystem.

## Arbeitspakete

### 1. Datenmodell & Migrationen ✅

-   `application/migrations/063_add_class_size_default_to_users.php`: `ALTER TABLE {$dbprefix}users ADD class_size_default INT NULL AFTER room;` inkl. Down-Migration.

### 2. Modelle & Persistenzschicht ✅

-   `application/models/Providers_model.php`: `class_size_default` in `$casts`, `$api_resource`, `only/optional`-Handling ergänzen und Save-Logik anpassen.
-   `application/controllers/Providers.php`: Feld `class_size_default` in `allowed_provider_fields` und Validierung aufnehmen.
-   `assets/js/pages/providers.js`: Formular-Binding (lesen/schreiben), Validierung (Ganzzahl ≥0) und Integration in Save-Payload; Reset & Prefill beachten.
-   `application/views/pages/providers.php`: Eingabefeld „Klassengröße“ (Number-Input, optional) unterhalb von `provider-room` einfügen.
-   Language-Files `application/language/*/translations_lang.php`: Labels/Hilfetexte für „Klassengröße“, Fehlerhinweis bei ungültigem Ziel etc.

### 3. Dashboard Backend

-   Neue Library `application/libraries/Dashboard_metrics.php`: kapselt Berechnung (`resolveTarget`, `countBookedAppointments`, `computeFillRate`, `formatRow`). Greift auf `appointments_model` und `providers_model`.
-   `Dashboard`-Controller:
    -   `__construct`: neue Library/Modelle laden.
    -   `index()`: Script Vars für `dashboard_conflict_threshold`, Service-Liste (Name + ID) und Default-Status (`['Booked']`) bereitstellen.
    -   `metrics()`:
        -   Filter-Parsing erweitern (`service_id`, optional `provider_ids`, Status-Array).
        -   Gebuchte Termine pro Lehrkraft zählen (`appointments.id_users_provider`) nach Zeitraum, Status, Service.
        -   `target` bestimmen: `provider.class_size_default` oder Fallback über `Provider_utilization`.
        -   Ergebnis-Array `{provider_id, provider_name, target, booked, open, fill_rate, needs_attention, has_plan}` sortiert nach `fill_rate` ASC zurückgeben.
-   `application/libraries/Provider_utilization.php`: API erweitern, sodass `calculate()` auch `capacity_minutes` zurückgibt oder neue Methode bereitstellt, damit Dashboard den Fallback nutzen kann, ohne Slots als „total“ zu reporten.
-   `assets/js/http/dashboard_http_client.js`: Methoden `fetch`, `downloadTeacherExport`, `downloadPrincipalExport`.

### 4. Dashboard Frontend (Backend UI)

-   `application/views/pages/dashboard.php`:
    -   Filterleiste um Service-Dropdown (Select2) und Optional-Menu (Button mit Dropdown) erweitern.
    -   (Optional) Toggle „Lehrkräfte ohne Ziel ausblenden“ + Counter (`(n ausgeblendet)`) im Options-Menü vorbereiten; kann bei konsistent gepflegten Klassengrößen ausgeblendet werden.
    -   Aktionen für PDF-Downloads (Lehrer\*innen / Schulleitung) und Einstellung „Konfliktschwelle“ (Modal oder Inline-Input).
    -   Tabelle Header zu `Lehrkraft | Klassengröße | Gebucht | Offen | Auslastung | Status`.
-   `assets/js/pages/dashboard.js`:
    -   Filter-Handling (Service/Status defaults, Schwellenwert anpassbar, hidden providers Counter).
    -   Chart-Daten auf `target`-Nenner umstellen; Bar-Labels `booked/target (xx%)`.
    -   Badge-Logik `needs_attention`.
-   Anzeige kennzeichnen, wenn `target` nur aus dem Kapazitäts-Fallback stammt (`class_size_default` leer) – z. B. Tooltip/Badge.
    -   Fehler-Handling für `target=0` (Label „Kein Ziel“).
-   Styling: Falls nötig Anpassungen in `assets/css/general.scss` bzw. neuem Partial (z. B. `.utilization-target-inline-edit`).
-   `assets/js/vendor` prüfen, ob Select2 bereits initialisiert (ja, via `App.Utils.UI.initializeDropdown`).

### 5. PDF-Exporte

-   Composer-Dependency `dompdf/dompdf:^2.0` in `composer.json` + `composer.lock` eintragen; `vendor` Autoload aktualisieren.
-   Neue Library `application/libraries/Pdf_renderer.php` (Wrapper um Dompdf, Default Fonts, Header/Footer-Handling).
-   Controller `application/controllers/Dashboard_export.php` (neu):
    -   `teacher_pdf()`: Filter verarbeiten, alle ausgewählten Lehrkräfte seitenweise in einer einzigen PDF bündeln (Abschnitt je Lehrkraft) und direkt streamen.
    -   `principal_pdf()`: Aggregationsdaten + Tabelle rendern.
    -   Gemeinsame private Methoden (`buildFiltersFromRequest`, `collectMetrics`, `renderTemplate`, `streamAttachment`).
-   Views:
    -   `application/views/exports/dashboard_teacher_pdf.php`: auf Basis des bestehenden Templates `pdf/template.html` (inkl. CSS) erweitern; Header (Schulname, Zeitraum, Service), KPI-Box, Klassenliste (Tabelle nach Startzeit), Outreach-Textblock (`{{Lehrkraft}}` Platzhalter), optional CSS für Kreisdiagramm (Pseudo-Element oder inline SVG basierend auf Fill-Rate).
-   `application/views/exports/dashboard_principal_pdf.php`: ebenfalls `pdf/template.html` als Grundlage nutzen; Gesamt-KPI, Tabelle aller Lehrkräfte (sortiert), Chart (Progress/Stacked Bar als HTML/SVG).
    -   **ToDos (Schritt 5 – `principal_pdf()` Layout)**:
        -   Header mit lokalisiertem Zeitraum (`„24.–30. Nov 2025“`), generiert-am Zeitstempel und Filter-Chips (`Zeitraum`, `Gespräch`, `Status`) gestalten; Chips optisch konsistent mit Eltern-PDF, Datums-/Label-Aufbereitung wie im Dashboard (`$rangeHuman`, `$serviceLabel`, `$statusesLabel`) wiederverwenden.
        -   PDF-spezifisches CSS direkt im View hinterlegen (Kartenlayout, Chips, Donut, Status-Badges, Tabellenstyles) und an die Optik der Eltern-PDF anlehnen.
        -   KPI-Reihe mit vier Karten (Gesamtauslastung inkl. Donut, Gebuchte Termine, Offen bis 100 %, Unter Schwelle inkl. Fehlen-bis-75 %-Summary) entwerfen; Berechnungen auf DISTINCT-Haushalte ausrichten und vorhandene Dashboard-Kennzahlen einbinden (`$totalBookedDistinct`, `$totalTarget`, `$providersBelowThreshold`).
        -   Aktionskarte „Aktion heute“ einplanen: Summierte fehlende Buchungen bis 75 % (Summe der CEILs je Lehrkraft) und Top-Interventionsliste (mindestens Top 3 nach Fehlbetrag); Hinweis auf konkrete Outreach-Vorlage entfällt (kein Sdui-Block vorgesehen).
        -   Lehrkraft-Tabelle um Spalten „Fehlen bis 75 %“ und „Status-Chip“ erweitern, Sortierung nach Fehlbetrag standardisieren und visuelle Hervorhebung für Konfliktfälle (z. B. Badge, Zeilen-Hinterlegung) aufnehmen.
        -   Seite 2 strukturieren: Definitionsblock (Ziel, Gebucht DISTINCT, Auslastung, Erwartungskonflikt) und kurze Legende zur Datenabdeckung („Alle Lehrkräfte mit gepflegter Klassengröße“); kein Sdui-Block aufnehmen und keinen Fallback ausweisen.
        -   Footer/Satzspiegel-Planung: Seitenzahlen „Seite X / Y“, Export-Zeitstempel und drucktaugliche Farbkontraste (Farbflächen + Muster/Schraffur für B/W); robustes CSS direkt im View definieren, Seitenzahlen rechts unten platzieren.
    -   **Akzeptanzkriterien**:
        -   PDF zeigt auf Seite 1 alle KPIs, Aktionsblock und Tabelle auf einen Blick; Sortierung & Werte stimmen mit Dashboard-DISTINCT-Logik überein (gleiches Datenmodell, gleiche Aggregationen wie im Dashboard).
        -   Fehlen-bis-75 %-Summen entsprechen `Summe(ceil(threshold*target_i) - booked_i)` ohne negative Werte; Top-Liste ist absteigend.
        -   Tabelle kennzeichnet Konflikte visuell (Badge/Farbton, Zeilenhervorhebung) und ist nach Fehlbetrag sortiert.
        -   Filter-Chips und KPI-Karten übernehmen die Kartenoptik/Typografie der Eltern-PDF; Header-/Footerangaben sind lokalisiert (deutsches Datum, 24h-Zeit) und enthalten Seitenzählung rechts unten.
        -   Appendix-Seite (Definitionen) bleibt kompakt (kein Seitenüberlauf) und zeigt Legende „Alle Lehrkräfte mit Zielwert“ als Chip/Badge.
    -   **Offene Fragen (Schritt 5)**: derzeit keine.
-   Helper `application/helpers/dashboard_export_helper.php` (optional) für Formatierungen (Datum, Prozent, Haushaltszähler).
-   Routes `application/config/routes.php`:
    -   `$route['dashboard/export/teacher.pdf']['get'] = 'dashboard_export/teacher_pdf';`
    -   `$route['dashboard/export/principal.pdf']['get'] = 'dashboard_export/principal_pdf';`
-   Sicherheit: Zugriffsschutz `session('role_slug') === DB_SLUG_ADMIN`, Parameter-Validierung und CSRF (bei GET-Downloads via signiertes Token? oder `GET` + session check).
-   Temporäre Dateien über `tmpfile()`/`SplTempFileObject` vermeiden persistente Ablage.

### 6. Konfiguration & Settings-Verwaltung

-   `application/controllers/Settings.php` (falls vorhanden) prüfen, ob UI-Anpassung für `dashboard_conflict_threshold` nötig ist; andernfalls in Dashboard-Optionsmodal pflegen und via `Settings_model` speichern.
-   Sicherstellen, dass `setting('dashboard_conflict_threshold')` überall verfügbar ist (Fallback-Wert `0.75`, falls Setting fehlt).
-   Dokumentation (`docs/` oder internes Wiki) für neue Exporte und Einstellungen ergänzen.

### 7. Qualitätssicherung & Release-Vorbereitung

-   Lauffähigkeit lokal prüfen (`composer install`, `npm install` bei neuen Packages, `composer dump-autoload` nach neuen Klassen).
-   Build-Pipeline: `npm run build` (Chart-Assets) + `composer dumpautoload`.
-   Logs unter `storage/logs/` leeren vor Merge.

### 6. Alternative Rendering-Strategie (Option A1 – Headless Chrome/Puppeteer)

-   **Architektur:** Sidecar-Service `pdf-renderer` (Node.js + Puppeteer) rendert HTML → PDF; PHP ruft HTTP-API an.
-   **Container:** `pdf-renderer/` mit `package.json`, `server.js` (Express, `/pdf`-Endpoint), `Dockerfile` auf Basis `ghcr.io/puppeteer/puppeteer:22-jammy`, Fonts (DejaVu/Liberation/Noto) installieren.
-   **Compose:** Service einhängen (`docker-compose.yml`), `shm_size: 1gb`, optional `PDF_TOKEN`, Healthcheck `/healthz`.
-   **PHP-Anbindung:** `PdfRenderer`-Client via `guzzlehttp/guzzle` (`renderHtml($html, ['waitFor' => 'chartsReady'])`) → PDF-Bytes streamen.
-   **Report-HTML:** vollständige HTML-Seite inkl. lokal gebundelter Assets (Chart.js/ECharts, Fonts), Druck-CSS (`@page A4`), Script setzt `window.chartsReady = true` nach Chart-Initialisierung.
-   **Tests:** `docker compose up pdf-renderer`, `curl` POST `/pdf` Smoke-Test.
-   **Betrieb:** Browser-Reuse, Timeouts (30 s), `networkidle0`, Sicherheitsmaßnahmen (interne Kommunikation, Token-Header).
-   **Migration:** Dompdf-Aufruf ersetzen durch HTTP-Call, bestehendes HTML wiederverwenden, Charts per Chart.js rendern.
-   **Statische Assets:** Warn-/Info-Icons als vorhandene Font-Awesome-Glyphen bzw. PNGs bereitstellen; keine zusätzlichen Render-Helfer nötig.
-   **Netzwerk:** Kommunikation bleibt auf das interne Compose-Netz beschränkt; initial keine Token-/mTLS-Abhängigkeiten.
-   **Fehlertoleranz:** Kein dediziertes Retry erforderlich – Fehler propagiert an PHP, Nutzer bekommt klare Fehlermeldung.
-   **Konfiguration:** Vorerst reine Dev-Lösung; Host/Port direkt in `.env`/Compose hinterlegen, spätere Umgebungen bei Bedarf ergänzen.
-   **Monitoring:** Für das MVP kein zentrales Monitoring; bei Stabilisierung evtl. Healthcheck-Auswertung in der Ops-Pipeline vorsehen.

**Offene Fragen**

-   Wann evaluieren wir, ob der MVP-Betrieb stabil genug ist, um Monitoring und produktive Security-Anforderungen nachzuziehen?
-   Müssen wir einen Rollback-Pfad definieren, falls die Chrome-Pipeline im Produktivbetrieb noch nicht liefert (Fallback: Dompdf)?

## Tests

-   Neue Unit-Tests:
    -   `tests/Unit/Models/ProvidersModelTest.php`: Persistenz von `class_size_default` (Insert/Update, Validierungsfehler).
    -   `tests/Unit/Libraries/DashboardMetricsTest.php`: Ziel-Fallback-Kaskade, Terminzählung, Schwellenwert-Flag.
    -   `tests/Unit/Controllers/DashboardTest.php`: `metrics`-Endpoint (mit Mocks) liefert erwartete JSON-Struktur & Sortierung.
-   Smoke-/Feature-Tests (manuell / ggf. Codeception falls vorhanden):
    -   Provider-Formular: CRUD inkl. Klassengröße.
    -   Dashboard-UI: Filterung, korrekte Ziel-Anzeige (inkl. Kennzeichnung Fallback), Ausblendung ohne Ziel, Badge.
    -   PDF-Generator: Sammel-PDF für Lehrkräfte öffnet sich vollständig, Layout/Breaks korrekt.
-   Regression: Bestehende `Provider_utilization`-Tests aktualisieren (erweiterte Rückgabedaten).

## Offene Punkte

1. Chart-Darstellung in PDFs: Priorisieren CSS-basierte Progress-Anzeige (z. B. Balken/Kreis) – robustere und wartungsarme Variante ohne zusätzliche Libraries.
2. Struktur Sammel-PDF: Kein Inhaltsverzeichnis, keine expliziten Trennseiten; sicherstellen, dass jede Seite eindeutig einer Lehrkraft zugeordnet ist (kein Seitenmix).
3. Mehrsprachigkeit: PDFs durchgehend auf Deutsch ausliefern (Texte & Labels).
4. Zielbearbeitung: Keine Inline-Änderung im Dashboard – `class_size_default` bleibt exklusiv im Provider-Stammdatensatz pflegbar; Dashboard zeigt nur an.
