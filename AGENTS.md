# AGENTS.md - Forscherhaus Appointments x ChatGPT Codex

Ziel: Merge-faehige, konsistente Beitraege fuer das Schul-Terminbuchungssystem.

## Projektueberblick (Kurz)

-   **Stack:** PHP (>= 8.1; bevorzugt 8.2+), CodeIgniter (MVC), MySQL; Frontend: JavaScript/jQuery/Bootstrap/FullCalendar; Build: npm + Gulp.
-   **Ziel:** Anpassung von Easy!Appointments an den Schulkontext ("forscherhaus-appointments").

## Verzeichnisstruktur & Leitplanken

-   `application/` - Feature-Entwicklung, MVC-Artefakte.
-   `system/` - nicht aendern (nur Upstream-Patches).
-   `assets/` -> Kompilation nach `build/`.
-   `build/` - kompilierte Artefakte (committed).
-   `docker/` - Container-Setups.
-   `tests/` - PHPUnit-Tests (Unit/Integration).
-   `storage/logs/` - Laufzeitlogs (vor Releases saeubern).

Leitplanke: Kein Produktionscode ausserhalb von `application/`. Keine direkten Aenderungen unter `system/`.

## Lokale Entwicklung & Prerequisites

-   **Option A (Host):** Apache/Nginx, PHP >= 8.1, MySQL, Node.js (npm), Composer.
-   **Option B (Docker):** `docker compose up` fuer CI-paritaetische Umgebung.
-   Bei Host-PHP + Docker-`pdf-renderer`: `PDF_RENDERER_URL=http://localhost:3003` setzen.

## Erstinstallation

```bash
# Fuer neue Worktrees (empfohlen vor dem ersten Commit)
./scripts/setup-worktree.sh
```

Hinweis: `./scripts/setup-worktree.sh` fuehrt u. a. `composer install`, `npm ci`/`npm install` und `npx gulp vendor` aus. `npm install` triggert zudem `npm run assets:refresh` via `postinstall`.

## Dev-/Build-/Test-Befehle

```bash
# Entwicklung (Assets watch & rebuild)
npm start

# Optional: Vendor/Theme-Assets refresh
npm run assets:refresh

# Produktion (minifizierte Bundles, Distributables)
npm run build

# Docs-Generierung (lokal)
npm run docs

# Frontend-Assets bei Aenderungen in assets/js oder assets/css neu bauen
npx gulp scripts
npx gulp styles

# Tests (CI-paritaer im Docker-Compose-Netz)
docker compose run --rm php-fpm composer test
docker compose run --rm php-fpm sh -lc 'APP_ENV=testing php vendor/bin/phpunit'
```

Hinweis: `composer test` erstellt `config.php` automatisch aus `config-sample.php`, falls sie fehlt. `DB_HOST='mysql'` ist Compose-DNS. Host-`composer test` funktioniert nur mit host-kompatibler `config.php`.

## Console-CLI (Wartung/Setup)

```bash
php index.php console help
php index.php console migrate
php index.php console migrate fresh
php index.php console migrate up
php index.php console migrate down
php index.php console seed      # nur fuer Testdaten
php index.php console install   # nur fuer frische Instanzen
php index.php console backup
php index.php console backup /path/to/backup/folder
php index.php console sync
```

## Pre-Commit Preconditions

-   Der lokale `pre-commit` Hook erwartet `vendor/` und `node_modules/`.
-   In neuen Worktrees einmal `./scripts/setup-worktree.sh` vor dem ersten Commit ausfuehren.
-   Bei Netzwerkrestriktionen koennen `composer install`/`npm ci` scheitern; Hook-Fehler sind dann erwartbar.
-   Ausnahme fuer reine Doku-/Meta-Commits: `SKIP_PRECOMMIT=1 git commit ...`
-   `--no-verify` nur als letzter Ausweg verwenden.

## Coding Style & Konventionen

-   Editor/Format: `.editorconfig` (4 Spaces, LF, Final Newline).
-   Prettier fuer PHP/JS gemaess Repo-Konfiguration, z. B. `npx prettier --write application/**/*.php`.
-   Benennung: Controller enden auf `Controller`, Models auf `Model`, Views nach Route-Alias.
-   JS: Modulnamen spiegeln Verzeichnisstruktur (z. B. `assets/js/booking/book.js`).
-   `snake_case` fuer Config-Keys, `camelCase` in JS.
-   Datenbankaenderungen immer via CodeIgniter-Migrations (inkl. Rollback-Pfad).
-   Secrets nie ins VCS; nutze `config.php`.

## Commit- & PR-Richtlinien

-   Commits: kurz, praesentisch, imperativ (z. B. `Fix booking validation`).
-   Vor PR: Tests gruen (`docker compose run --rm php-fpm composer test`), Migrations inkl. Rollback vorhanden.
-   `config-sample.php`/Docs bei Bedarf aktualisieren; bei UI-Aenderungen Screenshots/GIFs beilegen.

## Domaenen-Invariante (derzeit)

-   `services.attendants_number` ist aktuell hart auf `1` begrenzt.
-   Reviews/Fixes fuer `attendants_number > 1` nur umsetzen, wenn der Produktentscheid explizit auf Multi-Attendant geaendert wird.
