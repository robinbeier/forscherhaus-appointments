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

# Optional: Fokuslauf fuer Healthz-Checks
docker compose run --rm php-fpm sh -lc 'APP_ENV=testing php vendor/bin/phpunit --filter HealthzControllerTest'

# Optional: Dashboard Release Gate (vor Deployment / Regression-Check)
composer release:gate:dashboard -- \
  --base-url=http://localhost --index-page=index.php --username="$EA_GATE_USERNAME" --password="$EA_GATE_PASSWORD" \
  --start-date=YYYY-MM-DD --end-date=YYYY-MM-DD --statuses=Booked --pdf-health-url=http://localhost:3003/healthz

# Optional: Booking Confirmation PDF Gate (read-only synthetic)
composer release:gate:booking-confirmation-pdf -- \
  --base-url=http://localhost --index-page=index.php --confirmation-hash=REPLACE_WITH_APPOINTMENT_HASH

# Vollstaendige Optionen anzeigen
composer release:gate:dashboard -- --help
composer release:gate:booking-confirmation-pdf -- --help

# Optional: CI Dashboard Integration Smoke (lokaler Repro)
docker compose up -d mysql php-fpm nginx
until docker compose exec -T mysql mysqladmin ping -h localhost -uroot -psecret --silent; do sleep 2; done
until docker compose exec -T mysql mysql -uuser -ppassword -e "USE easyappointments; SELECT 1;" >/dev/null 2>&1; do sleep 2; done
for attempt in 1 2 3; do docker compose exec -T php-fpm php index.php console install && break; [ "$attempt" -eq 3 ] && exit 1; sleep 3; done
docker compose exec -T php-fpm php scripts/ci/dashboard_integration_smoke.php \
  --base-url=http://nginx --index-page=index.php \
  --username=administrator --password=administrator \
  --start-date=YYYY-MM-DD --end-date=YYYY-MM-DD
docker compose down -v --remove-orphans
```

Hinweis: `composer test` erstellt `config.php` automatisch aus `config-sample.php`, falls sie fehlt. `DB_HOST='mysql'` ist Compose-DNS. Host-`composer test` funktioniert nur mit host-kompatibler `config.php`.
Hinweis: Das Dashboard Release Gate schreibt standardmaessig nach `storage/logs/release-gate/dashboard-gate-<UTC>.json`; mit `--output-json=/pfad/report.json` kann der Zielpfad ueberschrieben werden.

Docker php-fpm Bootstrap (`docker/php-fpm/start-container`):

-   setzt `git config core.fileMode false` und `chmod -R 777 storage`
-   erstellt `config.php` via `cp config-sample.php config.php`, falls fehlend
-   installiert Abhaengigkeiten bei Bedarf: `composer install`, `npm install`
-   baut Assets, falls `assets/vendor` fehlt: `npx gulp compile`

Docker-Services (lokal) & Debug:

-   phpMyAdmin: `http://localhost:8080` (credentials `root` / `secret`)
-   Mailpit: `http://localhost:8025`
-   PDF-Renderer: `PDF_RENDERER_DEBUG_DUMP=true` aktiviert temporaere HTML-Debug-Dumps
-   CalDAV (Baikal): `http://localhost:8100` (credentials `admin` / `admin`), danach `http://baikal/dav.php` mit angelegtem Nutzer verwenden
-   OpenLDAP: `openldap` auf `389`/`636`, Admin-UI unter `http://localhost:8200` (credentials `cn=admin,dc=example,dc=org` / `admin`)

Warnung (Worktrees): Nutze pro Worktree einen eindeutigen Compose-Projektnamen, damit sich Container/Volumes nicht ueberlagern.
Beispiel: `docker compose -p fh-main up -d` im Haupt-Worktree und `docker compose -p fh-hotfix up -d` in einem zweiten Worktree.
So vermeidest du gemischte Stacks (z. B. `nginx` aus Worktree A, `php-fpm`/`mysql` aus Worktree B).

## Lokaler DB-Dump Restore (Docker)

Warnung: Der Reset von `docker/mysql` loescht lokale DB-Daten.

```bash
# Stack stoppen
docker compose down

# Lokale MySQL-Daten zuruecksetzen (destruktiv)
mkdir -p docker/mysql
find docker/mysql -mindepth 1 -maxdepth 1 -exec rm -rf {} +

# Services starten
docker compose up -d mysql php-fpm nginx

# Warten bis MySQL bereit ist
until docker compose exec -T mysql mysqladmin ping -h localhost -uroot -psecret --silent; do sleep 2; done

# Dump importieren
gunzip -c easyappointments_YYYY-MM-DD_HHMMSSZ.sql.gz | docker compose exec -T mysql mysql -uroot -psecret

# Migrationen ausfuehren
docker compose exec -T php-fpm php index.php console migrate
```

Optional: Sicherungs-Backup vor dem Reset.

```bash
backup_tgz="/tmp/forscherhaus-mysql-$(date +%Y%m%d-%H%M%S).tgz"
tar -czf "$backup_tgz" -C docker mysql
```

Optional: Import verifizieren.

```bash
docker compose exec -T mysql mysql -uroot -psecret -e "
USE easyappointments;
SELECT version FROM ea_migrations;
SHOW COLUMNS FROM ea_users LIKE 'class_size_default';
SELECT name, value FROM ea_settings WHERE name='dashboard_conflict_threshold';
"
```

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

## Release-Fokus (naechste 9 Tage bis Deployment)

-   Fokus bis zum Deployment: Stabilitaet und ggf. Performance-Verbesserungen mit geringem Risiko.
-   Keine Major-Dependency-Upgrades in dieser Phase; diese werden in die naechste Entwicklungsphase verschoben.
-   Fuer Dependency-Sweeps vor dem geplanten Upstream-Merge (`easyappointments` 1.6.0): den `roave/security-advisories`-Konflikt nicht isoliert aufloesen; stattdessen nur produktionsrelevante Security-Hotfixes (z. B. `firebase/php-jwt`) umsetzen und `roave` erst nach dem Upstream-Merge neu bewerten.
-   Nach Deployment ist ein Zeitfenster von ca. 6 Monaten bis zum naechsten Major-Update vorgesehen; dort werden Major-Upgrades geplant, getestet und gebuendelt umgesetzt.
-   `phpmailer/phpmailer` hat aktuell die niedrigste Prioritaet:
    In Produktion werden keine E-Mails versendet, und in der Entwicklung fehlen derzeit die benoetigten E-Mail-Testfaehigkeiten. Entsprechende Upgrades erst in der naechsten Major-Phase bewerten.

## Domaenen-Invariante (derzeit)

-   `services.attendants_number` ist aktuell hart auf `1` begrenzt.
-   Reviews/Fixes fuer `attendants_number > 1` nur umsetzen, wenn der Produktentscheid explizit auf Multi-Attendant geaendert wird.
