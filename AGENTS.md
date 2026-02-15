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
-   **Option B (Docker):** `docker compose up` für CI‑paritätische Umgebung (inkl. PHP, MySQL, ggf. Cron/Scheduler).
-   Bei Host-PHP + Docker-`pdf-renderer`: `PDF_RENDERER_URL=http://localhost:3003` setzen.
-   TODO: `docker-compose.restore.yml` ist derzeit untracked; Restore-Workflow erst nach Tracking/Doku standardisieren.

**Erstinstallation**

```bash
npm install
composer install
# Konfiguration
cp config-sample.php config.php  # DB-Zugang & Secrets setzen
# Verzeichnisse
# storage/ muss schreibbar sein

# Optional: Für Docker-Tests ohne manuelles Setup
# `composer test` erstellt `config.php` automatisch aus `config-sample.php`, falls die Datei fehlt.
```

## Dev-/Build-/Test-Befehle (Spickzettel)

# Entwicklung (Assets watch & rebuild)

npm start

# Optional: Vendor/Theme-Assets refresh (wird auch durch `npm install` ausgelöst)

npm run assets:refresh

# Produktion (minifizierte Bundles, Distributables)

npm run build

# Statische Docs (falls gepflegt)

npm run docs

# Frontend-Assets (bei DEBUG_MODE=false werden `*.min.js`/`*.min.css` geladen)

# Nach Änderungen in assets/js oder assets/css: Assets neu bauen und Browser hart neu laden.

npx gulp scripts
npx gulp styles

# Tests (verbindlich im Docker-Compose-Netz, CI-paritär)

docker compose run --rm php-fpm composer test

# Alternative (direkter PHPUnit-Aufruf im selben Container)

docker compose run --rm php-fpm sh -lc 'APP_ENV=testing php vendor/bin/phpunit'

# Hinweis: DB_HOST='mysql' ist Compose-DNS. Host-`composer test` funktioniert nur mit host-kompatibler config.php.

# Console-CLI (Wartung/Setup)

php index.php console help
php index.php console migrate
php index.php console migrate up
php index.php console migrate down
php index.php console migrate fresh # Achtung: destruktiv (setzt Migrationsstand zurück)
php index.php console seed # Nur für Testdaten
php index.php console install # Nur für frische Instanzen (migrate fresh + seed)
php index.php console backup
php index.php console backup /path/to/backup/folder
php index.php console sync

# Docker-Workflows (aus docs/docker.md)

docker compose down
# Optional safety backup of current local MySQL data directory
backup_tgz="/tmp/forscherhaus-mysql-$(date +%Y%m%d-%H%M%S).tgz"
tar -czf "$backup_tgz" -C docker mysql
docker compose up -d mysql php-fpm nginx
until docker compose exec -T mysql mysqladmin ping -h localhost -uroot -psecret --silent; do sleep 2; done
gunzip -c easyappointments_YYYY-MM-DD_HHMMSSZ.sql.gz | docker compose exec -T mysql mysql -uroot -psecret
docker compose exec -T php-fpm php index.php console migrate


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
-   Tests lokal im Docker-Compose-Netz ausführen und bei PRs grün halten.

## Commit- & PR-Richtlinien

-   Commits: kurz, Präsens, imperativ (z. B. Fix booking validation).
-   PR-Checkliste:
-   Tests grün (`docker compose run --rm php-fpm composer test`)
-   Migrations + Rollback vorhanden (falls DB‑Änderungen)
-   config-sample.php/Docs aktualisiert (falls nötig)
-   Screenshots/GIFs bei UI‑Änderungen
-   Verlinkung: Fixes #123
-   Reviewer: Modulverantwortliche:r

## Qualitätssicherung (vor Merge)

-   Lint/Format sauber (Prettier, Editorconfig).
-   `docker compose run --rm php-fpm composer test` grün; relevante neue Tests vorhanden.
-   Migrations laufen vorwärts und rückwärts.
-   npm run build erfolgreich; keine ungeprüften build/‑Artefakte committet.
-   Logs unter storage/logs/ bereinigt.

## Domänen-Invariante (derzeit)

-   `services.attendants_number` ist aktuell hart auf `1` begrenzt.
-   Reviews/Fixes für `attendants_number > 1` nur umsetzen, wenn der Produktentscheid explizit auf Multi-Attendant geändert wird.
