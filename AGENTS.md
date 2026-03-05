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

# Optional: PHPStan Static Analysis (aktueller Scope: helpers/libraries/core)
docker compose run --rm php-fpm composer phpstan:application

# Optional: JavaScript Lint (ESLint; geaenderte Dateien werden in CI geprueft)
npm run lint:js

# Optional: Generate and verify architecture/ownership docs
python3 scripts/docs/generate_architecture_ownership_docs.py
python3 scripts/docs/generate_architecture_ownership_docs.py --check
python3 scripts/ci/check_architecture_ownership_map.py

# Optional: Architecture boundaries gate (CODEOWNERS drift + Deptrac + component boundaries)
python3 scripts/docs/generate_codeowners_from_map.py
python3 scripts/docs/generate_codeowners_from_map.py --check
docker compose run --rm php-fpm composer deptrac:analyze
bash scripts/ci/run_deptrac_changed_gate.sh
python3 scripts/ci/check_component_boundaries.py
composer check:codeowners-sync
composer check:component-boundaries
composer check:architecture-boundaries

# Optional: API OpenAPI contract smoke (read-only, selected API v1 endpoints)
docker compose up -d mysql php-fpm nginx
until docker compose exec -T mysql mysqladmin ping -h localhost -uroot -psecret --silent; do sleep 2; done
until docker compose exec -T mysql mysql -uuser -ppassword -e "USE easyappointments; SELECT 1;" >/dev/null 2>&1; do sleep 2; done
for attempt in 1 2 3; do docker compose exec -T php-fpm php index.php console install && break; [ "$attempt" -eq 3 ] && exit 1; sleep 3; done
docker compose exec -T php-fpm composer contract-test:api-openapi -- \
  --base-url=http://nginx --index-page=index.php --openapi-spec=/var/www/html/openapi.yml \
  --username=administrator --password=administrator
docker compose down -v --remove-orphans

# Optional: Write-path contract smokes (booking + API, deterministic fixtures)
docker compose up -d mysql php-fpm nginx
until docker compose exec -T mysql mysqladmin ping -h localhost -uroot -psecret --silent; do sleep 2; done
until docker compose exec -T mysql mysql -uuser -ppassword -e "USE easyappointments; SELECT 1;" >/dev/null 2>&1; do sleep 2; done
for attempt in 1 2 3; do docker compose exec -T php-fpm php index.php console install && break; [ "$attempt" -eq 3 ] && exit 1; sleep 3; done
docker compose exec -T php-fpm composer contract-test:booking-write -- \
  --base-url=http://nginx --index-page=index.php \
  --username=administrator --password=administrator \
  --booking-search-days=14 --retry-count=1
docker compose exec -T php-fpm composer contract-test:api-openapi-write -- \
  --base-url=http://nginx --index-page=index.php --openapi-spec=/var/www/html/openapi.yml \
  --username=administrator --password=administrator \
  --retry-count=1 --booking-search-days=14
docker compose down -v --remove-orphans

# Optional: Booking controller flow tests (register/reschedule/cancel)
docker compose up -d mysql php-fpm nginx
until docker compose exec -T mysql mysqladmin ping -h localhost -uroot -psecret --silent; do sleep 2; done
until docker compose exec -T mysql mysql -uuser -ppassword -e "USE easyappointments; SELECT 1;" >/dev/null 2>&1; do sleep 2; done
for attempt in 1 2 3; do docker compose exec -T php-fpm php index.php console install && break; [ "$attempt" -eq 3 ] && exit 1; sleep 3; done
docker compose exec -T php-fpm composer test:booking-controller-flows
docker compose down -v --remove-orphans

# Optional: Typed request-dto checks (scope gate for booking/dashboard/api read paths)
docker compose run --rm php-fpm composer phpstan:request-dto
docker compose run --rm php-fpm composer test:request-dto
docker compose run --rm php-fpm php scripts/ci/check_request_dto_adoption.php

# Optional: Typed request-contracts checks (full domain-critical request/controller rollout)
docker compose run --rm php-fpm composer phpstan:request-contracts:l1
docker compose run --rm php-fpm composer test:request-contracts
docker compose run --rm php-fpm php scripts/ci/check_request_contract_adoption.php
docker compose run --rm php-fpm composer phpstan:request-contracts:l2

# Optional: Unit coverage + coverage delta gate
docker compose run --rm php-fpm composer test:coverage:unit
docker compose run --rm php-fpm composer check:coverage:delta

# Optional: Lokale Pre-PR Gates (schnell/voll)
bash ./scripts/ci/pre_pr_quick.sh
bash ./scripts/ci/pre_pr_full.sh
PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh
PRE_PR_BASE_REF=origin/release-branch bash ./scripts/ci/pre_pr_quick.sh
PRE_PR_BASE_REF=origin/release-branch PRE_PR_REQUEST_CONTRACTS_L2_BLOCKING=1 bash ./scripts/ci/pre_pr_full.sh

# Optional: PHPStan status check (blocking)
for run_id in $(gh run list --workflow CI --limit 20 --json databaseId -q '.[].databaseId'); do
  gh run view "$run_id" --json jobs -q '.jobs[] | select(.name=="phpstan-application") | .conclusion'
done
# Erwartung: aktuelle Eintraege sind SUCCESS

# Optional: JS lint status check (blocking)
for run_id in $(gh run list --workflow CI --limit 20 --json databaseId -q '.[].databaseId'); do
  gh run view "$run_id" --json jobs -q '.jobs[] | select(.name=="js-lint-changed") | .conclusion'
done
# Erwartung: aktuelle Eintraege sind SUCCESS

# Optional: Architecture/ownership status check (blocking)
for run_id in $(gh run list --workflow CI --event pull_request --limit 30 --json databaseId -q '.[].databaseId'); do
  gh run view "$run_id" --json jobs -q '.jobs[] | select(.name=="architecture-ownership-map") | .conclusion'
done | awk '$1 != "cancelled"' | head -n 7
# Erwartung: ausgegebene Eintraege sind SUCCESS

# Optional: architecture-boundaries status check (blocking)
for run_id in $(gh run list --workflow CI --event pull_request --limit 40 --json databaseId -q '.[].databaseId'); do
  gh run view "$run_id" --json jobs -q '.jobs[] | select(.name=="architecture-boundaries") | .conclusion'
done | awk '$1 != "cancelled"' | head -n 7
# Erwartung: ausgegebene Eintraege sind SUCCESS

# Optional: API OpenAPI contract status check (blocking)
for run_id in $(gh run list --workflow CI --event pull_request --limit 40 --json databaseId -q '.[].databaseId'); do
  gh run view "$run_id" --json jobs -q '.jobs[] | select(.name=="api-contract-openapi") | .conclusion'
done | awk '$1 != "cancelled"' | head -n 7
# Erwartung: ausgegebene Eintraege sind SUCCESS

# Optional: write-contract-booking rollout streak check (warn-only -> blocking; executed-only)
for run_id in $(gh run list --workflow CI --event pull_request --limit 40 --json databaseId -q '.[].databaseId'); do
  gh run view "$run_id" --json jobs -q '.jobs[] | select(.name=="write-contract-booking") | .conclusion'
done | grep -E '^(success|failure)$' | head -n 7
# Erwartung fuer Blocking-Umschaltung: alle 7 ausgegebenen Eintraege sind SUCCESS

# Optional: write-contract-api rollout streak check (warn-only -> blocking; executed-only)
for run_id in $(gh run list --workflow CI --event pull_request --limit 40 --json databaseId -q '.[].databaseId'); do
  gh run view "$run_id" --json jobs -q '.jobs[] | select(.name=="write-contract-api") | .conclusion'
done | grep -E '^(success|failure)$' | head -n 7
# Erwartung fuer Blocking-Umschaltung: alle 7 ausgegebenen Eintraege sind SUCCESS

# Optional: Booking controller flow status check (blocking)
for run_id in $(gh run list --workflow CI --event pull_request --limit 40 --json databaseId -q '.[].databaseId'); do
  gh run view "$run_id" --json jobs -q '.jobs[] | select(.name=="booking-controller-flows") | .conclusion'
done | awk '$1 != "cancelled"' | head -n 7
# Erwartung: ausgegebene Eintraege sind SUCCESS

# Optional: typed-request-dto status check (blocking)
for run_id in $(gh run list --workflow CI --event pull_request --limit 40 --json databaseId -q '.[].databaseId'); do
  gh run view "$run_id" --json jobs -q '.jobs[] | select(.name=="typed-request-dto") | .conclusion'
done | awk '$1 != "cancelled"' | head -n 7
# Erwartung: ausgegebene Eintraege sind SUCCESS

# Optional: typed-request-contracts rollout streak check (warn-only -> blocking)
for run_id in $(gh run list --workflow CI --event pull_request --limit 40 --json databaseId -q '.[].databaseId'); do
  gh run view "$run_id" --json jobs -q '.jobs[] | select(.name=="typed-request-contracts") | .conclusion'
done | awk '$1 != "cancelled"' | head -n 7
# Erwartung fuer Blocking-Umschaltung: alle 7 ausgegebenen Eintraege sind SUCCESS

# Optional: coverage-delta status check (blocking)
for run_id in $(gh run list --workflow CI --event pull_request --limit 40 --json databaseId -q '.[].databaseId'); do
  gh run view "$run_id" --json jobs -q '.jobs[] | select(.name=="coverage-delta") | .conclusion'
done | grep -E '^(success|failure)$' | head -n 10
# Erwartung: bei relevanten PR-Aenderungen sind aktuelle ausgefuehrte Eintraege SUCCESS

# Optional: Fokuslauf fuer Healthz-Checks
docker compose run --rm php-fpm sh -lc 'APP_ENV=testing php vendor/bin/phpunit --filter HealthzControllerTest'

# Optional: Fokuslauf fuer OpenAPI Contract Validator Unit-Tests
docker compose run --rm php-fpm sh -lc 'APP_ENV=testing php vendor/bin/phpunit --filter OpenApiContractValidatorTest'

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

# Optional: CI Dashboard+Booking+API Integration Smoke (lokaler Repro, read-only)
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
Hinweis: `composer deptrac:analyze` kann auf neueren Host-PHP-Versionen (z. B. 8.5) zusaetzliche Vendor-Deprecation-Ausgaben zeigen; fuer CI-paritaer rauschfreie Ausgaben den Docker-Run `docker compose run --rm php-fpm composer deptrac:analyze` verwenden.
Hinweis: Das Dashboard Release Gate schreibt standardmaessig nach `storage/logs/release-gate/dashboard-gate-<UTC>.json`; mit `--output-json=/pfad/report.json` kann der Zielpfad ueberschrieben werden.
Hinweis: Der CI-Job `phpstan-application` ist blocking.
Hinweis: Der CI-Job `js-lint-changed` ist blocking.
Hinweis: Der CI-Job `architecture-ownership-map` ist blocking.
Hinweis: Der CI-Job `architecture-boundaries` ist blocking.
Hinweis: Der CI-Job `api-contract-openapi` ist blocking.
Hinweis: Der CI-Job `write-contract-booking` laeuft waehrend des Rollouts warn-only (`continue-on-error`) und wird nach 7 aufeinanderfolgenden ausgefuehrten grueneren PR-Laeufen auf blocking umgestellt (nur `success|failure` zaehlt).
Hinweis: Der CI-Job `write-contract-api` laeuft waehrend des Rollouts warn-only (`continue-on-error`) und wird nach 7 aufeinanderfolgenden ausgefuehrten grueneren PR-Laeufen auf blocking umgestellt (nur `success|failure` zaehlt).
Hinweis: Der CI-Job `booking-controller-flows` ist blocking.
Hinweis: Der CI-Job `typed-request-dto` ist blocking.
Hinweis: Der CI-Job `typed-request-contracts` laeuft waehrend des Rollouts warn-only (`continue-on-error`) und wird nach 7 aufeinanderfolgenden grueneren PR-Laeufen auf blocking umgestellt.
Hinweis: Der CI-Job `coverage-delta` ist blocking und laeuft auf `push` nach `main` sowie auf non-draft PRs mit relevanten Deep-Changes.
Hinweis: Das Architecture Boundaries Gate schreibt standardmaessig nach `storage/logs/ci/deptrac-changed-gate.json`, `storage/logs/ci/deptrac-github-actions.log` und `storage/logs/ci/component-boundary-latest.json`.
Hinweis: Das Request Contracts Gate schreibt standardmaessig nach `storage/logs/ci/request-contract-adoption-latest.json` und `storage/logs/ci/phpstan-request-contracts-l2.raw`.
Hinweis: Der API OpenAPI Contract Smoke schreibt standardmaessig nach `storage/logs/ci/api-openapi-contract-<UTC>.json`; mit `--output-json=/pfad/report.json` kann der Zielpfad ueberschrieben werden.
Hinweis: Der Booking Write Contract Smoke schreibt standardmaessig nach `storage/logs/ci/booking-write-contract-<UTC>.json`; mit `--output-json=/pfad/report.json` kann der Zielpfad ueberschrieben werden.
Hinweis: Der API OpenAPI Write Contract Smoke schreibt standardmaessig nach `storage/logs/ci/api-openapi-write-contract-<UTC>.json`; mit `--output-json=/pfad/report.json` kann der Zielpfad ueberschrieben werden.
Hinweis: Der Coverage Report (`composer test:coverage:unit`, inkl. Unit + Booking-Flow-Integration) schreibt standardmaessig nach `storage/logs/ci/coverage-unit-clover.xml`; der Coverage Delta Gate Report nach `storage/logs/ci/coverage-delta-latest.json`.
Hinweis: Der CI-Job `integration-smoke` prueft read-only die Kette Login/Auth + Dashboard Metrics + Booking-Read-Endpoints + API-Auth/Read.
Hinweis: Falls `architecture-boundaries` nach Blocking-Umschaltung durch False-Positives Releases blockiert, in einem Commit `continue-on-error: true` fuer den Job reaktivieren und ein Follow-up-Issue mit max. 14 Tagen Frist zur Rueckkehr in den Blocking-Modus anlegen.
Hinweis: Falls `write-contract-booking` nach Blocking-Umschaltung durch False-Positives Releases blockiert, in einem Commit `continue-on-error: true` fuer den Job reaktivieren und ein Follow-up-Issue mit max. 14 Tagen Frist zur Rueckkehr in den Blocking-Modus anlegen.
Hinweis: Falls `write-contract-api` nach Blocking-Umschaltung durch False-Positives Releases blockiert, in einem Commit `continue-on-error: true` fuer den Job reaktivieren und ein Follow-up-Issue mit max. 14 Tagen Frist zur Rueckkehr in den Blocking-Modus anlegen.
Hinweis: Falls `coverage-delta` nach Blocking-Umschaltung durch False-Positives Releases blockiert, in einem Commit `continue-on-error: true` fuer den Job reaktivieren und ein Follow-up-Issue mit max. 14 Tagen Frist zur Rueckkehr in den Blocking-Modus anlegen.

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
-   macOS/iCloud Troubleshooting (MySQL InnoDB OS error 35 auf `./#innodb_redo/*`): `ls -lO@ docker/mysql/#innodb_redo`; bei `compressed,dataless` Dateien rehydrieren (z. B. `dd if='docker/mysql/#innodb_redo/#ib_redo6' of=/dev/null bs=512 count=1`) und Container neu starten; falls erfolglos `docker/mysql` wie im DB-Restore-Abschnitt zuruecksetzen.

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
-   `scripts/ci/pre_pr_quick.sh` und `scripts/ci/pre_pr_full.sh` bootstrappen fehlende `vendor/`/`node_modules/` automatisch (via `composer install` + `npm ci`/`npm install`).
-   Bei diesem Auto-Bootstrap ist Netzwerkzugriff auf Package-Registries erforderlich; ohne Netz bleiben die Gates weiterhin blockiert.
-   Bei Netzwerkrestriktionen koennen `composer install`/`npm ci` scheitern; Hook-Fehler sind dann erwartbar.
-   Ausnahme fuer reine Doku-/Meta-Commits: `SKIP_PRECOMMIT=1 git commit ...`
-   `./scripts/setup-worktree.sh` installiert einen managed `.git/hooks/pre-push` Hook (`pre_pr_quick.sh`).
-   Fuer einmaliges Bypassen: `SKIP_PREPUSH=1 git push ...`; fuer Full-Gate beim Push: `PRE_PUSH_FULL=1 git push ...`.
-   Optional kann die Hook-/Pre-PR-Basis mit `PRE_PR_BASE_REF` ueberschrieben werden (Standard: `main`).
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
