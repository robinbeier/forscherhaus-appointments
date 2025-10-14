# AGENTS.md — Forscherhaus Appointments × ChatGPT Codex

Ziel dieser Datei: **Konsistente, merge‑fähige Beiträge von ChatGPT Codex** für unser Schul‑Terminbuchungssystem (Fork/Weiterentwicklung von Easy!Appointments). Sie definiert:

-   Kontext & Architekturleitplanken
-   Einheitliche Befehle (Dev/Build/Test)
-   Coding‑Standards & QA
-   Domänen‑Invarianten (Schulkontext)
-   Erwartete Antwortformate von Codex (Plan → Patch → Tests → Docs → PR)

---

## Projektüberblick (Kurz)

-   **Stack:** PHP (≥ 8.1; bevorzugt 8.2+), CodeIgniter (MVC), MySQL; Frontend: JavaScript/jQuery/Bootstrap/FullCalendar; Build: npm + Gulp.
-   **Ziel:** Anpassung von Easy!Appointments an unseren Schulkontext („forscherhaus‑appointments“).

> **Hinweis:** `application/` enthält Business‑Logik, Controller, Views (hier neue Features bauen); `system/` sind Framework‑Interna und bleiben unangetastet. `assets/` enthält Frontend‑Quellen, die nach `build/` kompiliert werden. Zusätzliche Verzeichnisse: `docker/`, `tests/`. (Quelle: bestehende Projektleitfäden)

## Verzeichnisstruktur & Leitplanken

-   `application/` — Feature‑Entwicklung, MVC‑Artefakte.
-   `system/` — **nicht ändern** (nur Upstream‑Patches).
-   `assets/` → Kompilation nach `build/`.
-   `build/` — kompilierte Artefakte (committed).
-   `docker/` — Container‑Setups.
-   `tests/` — PHPUnit‑Tests (Unit/Integration).
-   `storage/logs/` — Laufzeitlogs (vor Releases säubern).

**Leitplanke:** _Kein_ Produktionscode außerhalb von `application/`. Keine direkten Änderungen unter `system/`.

## Lokale Entwicklung & Prerequisites

-   **Option A (Host):** Apache/Nginx, PHP ≥ 8.1 (empfohlen 8.2+), MySQL, Node.js (npm), Composer.
-   **Option B (Docker):** `docker-compose up` für CI‑paritätische Umgebung (inkl. PHP, MySQL, ggf. Cron/Scheduler).

**Erstinstallation**

```bash
npm install
composer install
# Konfiguration
cp config-sample.php config.php  # DB-Zugang & Secrets setzen
# Verzeichnisse
# storage/ muss schreibbar sein
```

## Dev-/Build-/Test-Befehle (Spickzettel)

# Entwicklung (Assets watch & rebuild)

npm start

# Produktion (minifizierte Bundles, Distributables)

npm run build

# Statische Docs (falls gepflegt)

npm run docs

# Tests (bevorzugt per Composer-Alias)

composer test

# Alternativ:

APP_ENV=testing php vendor/bin/phpunit

## Coding Style & Konventionen

-   Editor/Format: .editorconfig (4‑Spaces, LF, Final Newline).
-   Prettier für PHP/JS gemäß Repo‑Konfiguration; z. B.:

```bash
npx prettier --write application/**/*.php
```

-   Benennung:
-   Controller enden auf Controller, Models auf Model; Views nach Route‑Alias benennen.
-   JS: Modulnamen spiegeln Verzeichnisstruktur (assets/js/booking/book.js).
-   snake_case für Config‑Keys, camelCase in JS.

-   Datenbank: Änderungen immer via CodeIgniter‑Migrations umsetzen (inkl. Rollback‑Pfad).
-   Secrets: nie ins VCS; nutze config.php.

## Testing-Richtlinien

-   Ablage: tests/Unit/ (und bei Bedarf tests/Integration/).
-   Namensschema: BookingServiceTest.php; Szenarien test_canCreateAppointment, test_failsOnOverlap.
-   Abdeckung: Erfolgs‑ und Fehlpfade (inkl. Grenzfälle: Terminüberschneidung, Zeitzonen).
-   Tests lokal ausführen und bei PRs grün halten.

## Commit- & PR-Richtlinien

-   Commits: kurz, Präsens, imperativ (z. B. Fix booking validation).
-   PR-Checkliste:
-   Tests grün (composer test)
-   Migrations + Rollback vorhanden (falls DB‑Änderungen)
-   config-sample.php/Docs aktualisiert (falls nötig)
-   Screenshots/GIFs bei UI‑Änderungen
-   Verlinkung: Fixes #123
-   Reviewer: Modulverantwortliche:r

## Qualitätssicherung (vor Merge)

-   Lint/Format sauber (Prettier, Editorconfig).
-   composer test grün; relevante neue Tests vorhanden.
-   Migrations laufen vorwärts und rückwärts.
-   npm run build erfolgreich; keine ungeprüften build/‑Artefakte committet.
-   Logs unter storage/logs/ bereinigt.
