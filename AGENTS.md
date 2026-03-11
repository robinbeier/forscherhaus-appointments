# AGENTS.md - Forscherhaus Appointments x ChatGPT Codex

Ziel: Merge-faehige, konsistente Beitraege fuer das Schul-Terminbuchungssystem.

## Kanonische Harness-Quellen

- `README.md` - Operator-Onboarding, Quickstart, lokale Services.
- `WORKFLOW.md` - Agent-Runtime, Workpad-Regeln, Ticket-zu-Merge-Statusmodell.
- `docs/agent-harness-index.md` - Routing zwischen Onboarding, Runtime,
  CI/Gates, Architektur, Ownership und Symphony.
- `AGENTS.md` - vollstaendige lokale/CI-Kommandomatrix, Repo-Guardrails und
  Beitraegsregeln.

## Projektueberblick (Kurz)

- **Stack:** PHP (>= 8.1; bevorzugt 8.2+), CodeIgniter (MVC), MySQL; Frontend: JavaScript/jQuery/Bootstrap/FullCalendar; Build: npm + Gulp.
- **Ziel:** Anpassung von Easy!Appointments an den Schulkontext ("forscherhaus-appointments").

## Verzeichnisstruktur & Leitplanken

- `application/` - Feature-Entwicklung, MVC-Artefakte.
- `system/` - nicht aendern (nur Upstream-Patches).
- `assets/` -> Kompilation nach `build/`.
- `build/` - kompilierte Artefakte (committed).
- `docker/` - Container-Setups.
- `tests/` - PHPUnit-Tests (Unit/Integration).
- `storage/logs/` - Laufzeitlogs (vor Releases saeubern).

Leitplanke: Kein Produktionscode ausserhalb von `application/`. Keine direkten Aenderungen unter `system/`.

## Lokale Entwicklung & Prerequisites

- **Option A (Host):** Apache/Nginx, PHP >= 8.1, MySQL, Node.js (npm), Composer.
- **Option B (Docker):** `docker compose up` fuer CI-paritaetische Umgebung.
- Bei Host-PHP + Docker-`pdf-renderer`: `PDF_RENDERER_URL=http://localhost:3003` setzen.

## Erstinstallation

```bash
# Fuer neue Worktrees (empfohlen vor dem ersten Commit)
./scripts/setup-worktree.sh
```

Hinweis: `./scripts/setup-worktree.sh` fuehrt u. a. `composer install`, `npm ci`/`npm install` und `npx gulp vendor` aus. `npm install` triggert zudem `npm run assets:refresh` via `postinstall`.

## Dev-/Build-/Test-Befehle

Hinweis: `README.md` bleibt bewusst kompakter. Die vollstaendige Kommandomatrix
lebt hier in `AGENTS.md`; der kurze Routing-Einstieg steht in
`docs/agent-harness-index.md`.

Hinweis: Die kanonische Ownership-Quelle `docs/maps/component_ownership_map.json`
kann Komponenten explizit als `single-owner` und `manual_approval_required`
markieren. Behandle solche Komponenten konservativ: gruene Checks ersetzen dort
keine explizite menschliche Produktsteuerung bei unklaren oder risikobehafteten
Intent-Aenderungen.

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
  --booking-search-days=14 --retry-count=1 \
  --checks=booking_register_success_contract,booking_register_manage_update_contract,booking_register_unavailable_contract,booking_reschedule_manage_mode_contract,booking_cancel_success_contract,booking_cancel_unknown_hash_contract
docker compose exec -T php-fpm composer contract-test:api-openapi-write -- \
  --base-url=http://nginx --index-page=index.php --openapi-spec=/var/www/html/openapi.yml \
  --username=administrator --password=administrator \
  --retry-count=1 --booking-search-days=14 \
  --checks=appointments_write_unauthorized_guard,customers_store_contract,appointments_store_contract,appointments_update_contract,appointments_destroy_contract,customers_destroy_contract
# Optional: Combined wrapper (runs booking-write + api-openapi-write sequentially)
docker compose exec -T php-fpm composer contract-test:write-path -- \
  --base-url=http://nginx --index-page=index.php --openapi-spec=/var/www/html/openapi.yml \
  --username=administrator --password=administrator --retry-count=1 --booking-search-days=14
docker compose down -v --remove-orphans

# Optional: Deep runtime suite producer + verdicts (shared CI topology for deep runtime gates)
docker compose up -d mysql php-fpm nginx openldap
until docker compose exec -T mysql mysqladmin ping -h localhost -uroot -psecret --silent; do sleep 2; done
until docker compose exec -T mysql mysql -uuser -ppassword -e "USE easyappointments; SELECT 1;" >/dev/null 2>&1; do sleep 2; done
for attempt in 1 2 3; do docker compose exec -T php-fpm php index.php console install && break; [ "$attempt" -eq 3 ] && exit 1; sleep 3; done
docker compose exec -T php-fpm php scripts/ci/run_deep_runtime_suite.php \
  --suites=api-contract-openapi,write-contract-booking,write-contract-api,booking-controller-flows,integration-smoke \
  --base-url=http://nginx --index-page=index.php --openapi-spec=/var/www/html/openapi.yml \
  --username=administrator --password=administrator \
  --booking-search-days=14 --retry-count=1 \
  --start-date=2026-01-01 --end-date=2026-01-31 \
  --report-dir=storage/logs/ci/deep-runtime-suite
docker compose exec -T php-fpm php scripts/ci/assert_deep_runtime_suite.php --manifest=storage/logs/ci/deep-runtime-suite/manifest.json --suite=api-contract-openapi
docker compose exec -T php-fpm php scripts/ci/assert_deep_runtime_suite.php --manifest=storage/logs/ci/deep-runtime-suite/manifest.json --suite=write-contract-booking
docker compose exec -T php-fpm php scripts/ci/assert_deep_runtime_suite.php --manifest=storage/logs/ci/deep-runtime-suite/manifest.json --suite=write-contract-api
docker compose exec -T php-fpm php scripts/ci/assert_deep_runtime_suite.php --manifest=storage/logs/ci/deep-runtime-suite/manifest.json --suite=booking-controller-flows
docker compose exec -T php-fpm php scripts/ci/assert_deep_runtime_suite.php --manifest=storage/logs/ci/deep-runtime-suite/manifest.json --suite=integration-smoke
docker compose down -v --remove-orphans

# Optional: Booking controller flow tests (register/reschedule/cancel)
docker compose up -d mysql php-fpm
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

# Optional: Coverage shards + merge + coverage delta gate
docker compose run --rm php-fpm composer test:coverage:unit-shard
docker compose run --rm php-fpm composer test:coverage:integration-shard
docker compose run --rm php-fpm composer test:coverage:merge-shards
docker compose run --rm php-fpm composer test:coverage:unit
docker compose run --rm php-fpm composer check:coverage:delta

# Optional: Heavy-job duration trend report (read-only GitHub API signal)
GITHUB_TOKEN="$(gh auth token)" GITHUB_REPOSITORY="robinbeier/forscherhaus-appointments" composer check:heavy-job-duration-trends
GITHUB_TOKEN="$(gh auth token)" php scripts/ci/check_heavy_job_duration_trends.php \
  --repo=robinbeier/forscherhaus-appointments \
  --output-json=storage/logs/ci/heavy-job-duration-trends-latest.json

# Optional: Agent harness readiness score + report date sanity
composer check:agent-harness-readiness
composer check:harness-report-dates
php scripts/ci/check_agent_harness_readiness.php \
  --output-json=storage/logs/ci/agent-harness-readiness-latest.json
php scripts/ci/check_harness_report_dates.php \
  --output-json=storage/logs/ci/harness-report-date-sanity-latest.json

# Optional: PDF renderer latency trend signal (deterministic fixture, p50/p95)
composer check:pdf-renderer-latency
php scripts/ci/check_pdf_renderer_latency.php \
  --base-url=http://localhost:3003 \
  --output-json=storage/logs/ci/pdf-renderer-latency-latest.json

# Optional: Deterministic LDAP fixtures fuer Search/Import/SSO
bash ./scripts/ldap/reset_directory.sh
bash ./scripts/ldap/smoke.sh

# Optional: Lokale Pre-PR Gates (schnell/voll)
bash ./scripts/ci/pre_pr_quick.sh
bash ./scripts/ci/pre_pr_full.sh
PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh
PRE_PR_BASE_REF=origin/release-branch bash ./scripts/ci/pre_pr_quick.sh
PRE_PR_BASE_REF=origin/release-branch PRE_PR_REQUEST_CONTRACTS_L2_BLOCKING=0 bash ./scripts/ci/pre_pr_full.sh

# Optional: Symphony-Pilot Basischecks (deterministisch)
bash ./scripts/ci/run_symphony_pilot_checks.sh
bash ./scripts/ci/run_symphony_pilot_checks.sh --with-full-gate

# Optional: Symphony-Pilot Start/Stop (lokal, reproduzierbar)
cp .env.symphony.pilot.example .env.symphony.pilot
bash ./scripts/symphony/start_pilot.sh
bash ./scripts/symphony/stop_pilot.sh

# Hinweis: Dieses Repo nutzt fuer Symphony die Linear-States `In Progress -> In Review -> Ready to Merge -> Done`.
# Hinweis: `In Review` bedeutet PR publiziert und Symphony stoppt; `Ready to Merge` ist der explizite Resume-State fuer `land`/`$Babysit PR`.
# Hinweis: Ernste frische Symphony-Pilotchecks nur von `origin/main` oder mit explizitem `SYMPHONY_WORKTREE_BASE_REF` fahren, damit Worker nicht auf veraltetem Runtime-/Skill-Kontext starten.
# Hinweis: Repo-lokale Worker-Abhaengigkeiten unter `.codex/skills/` und `.claude/napkin.md` muessen versioniert, YAML-gueltig und worktree-tauglich bleiben.
# Hinweis: Ein Symphony-Pilot ist erst dann fachlich sauber, wenn der finale Merge-Diff die Ticket-Akzeptanzkriterien exakt trifft und der letzte `## Codex Workpad`-Status den echten PR-/Merge-Zustand widerspiegelt; `PR erstellt/gemerged` und korrekter Linear-State allein reichen nicht.

# Optional: Symphony Soak Gate (staging/local state API)
python3 ./scripts/symphony/run_soak_gate.py --state-url http://127.0.0.1:8787/api/v1/state --duration-seconds 86400 --poll-seconds 60

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

# Optional: write-contract-booking status check (blocking; executed-only)
for run_id in $(gh run list --workflow CI --event pull_request --limit 40 --json databaseId -q '.[].databaseId'); do
  gh run view "$run_id" --json jobs -q '.jobs[] | select(.name=="write-contract-booking") | .conclusion'
done | grep -E '^(success|failure)$' | head -n 7
# Erwartung: ausgefuehrte aktuelle Eintraege sind SUCCESS

# Optional: write-contract-api status check (blocking; executed-only)
for run_id in $(gh run list --workflow CI --event pull_request --limit 40 --json databaseId -q '.[].databaseId'); do
  gh run view "$run_id" --json jobs -q '.jobs[] | select(.name=="write-contract-api") | .conclusion'
done | grep -E '^(success|failure)$' | head -n 7
# Erwartung: ausgefuehrte aktuelle Eintraege sind SUCCESS

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

# Optional: typed-request-contracts status check (blocking)
for run_id in $(gh run list --workflow CI --event pull_request --limit 40 --json databaseId -q '.[].databaseId'); do
  gh run view "$run_id" --json jobs -q '.jobs[] | select(.name=="typed-request-contracts") | .conclusion'
done | grep -E '^(success|failure)$' | head -n 7
# Erwartung: bei relevanten PR-Aenderungen sind aktuelle ausgefuehrte Eintraege SUCCESS

# Optional: coverage-delta status check (blocking)
for run_id in $(gh run list --workflow CI --event pull_request --limit 40 --json databaseId -q '.[].databaseId'); do
  gh run view "$run_id" --json jobs -q '.jobs[] | select(.name=="coverage-delta") | .conclusion'
done | grep -E '^(success|failure)$' | head -n 10
# Erwartung: bei relevanten PR-Aenderungen sind aktuelle ausgefuehrte Eintraege SUCCESS

# Optional: coverage-shard-unit status check (blocking)
for run_id in $(gh run list --workflow CI --event pull_request --limit 40 --json databaseId -q '.[].databaseId'); do
  gh run view "$run_id" --json jobs -q '.jobs[] | select(.name=="coverage-shard-unit") | .conclusion'
done | grep -E '^(success|failure)$' | head -n 10
# Erwartung: bei relevanten PR-Aenderungen sind aktuelle ausgefuehrte Eintraege SUCCESS

# Optional: coverage-shard-integration status check (blocking)
for run_id in $(gh run list --workflow CI --event pull_request --limit 40 --json databaseId -q '.[].databaseId'); do
  gh run view "$run_id" --json jobs -q '.jobs[] | select(.name=="coverage-shard-integration") | .conclusion'
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

# Optional: Zero-surprise restore-dump replay gate (pre-deploy shadow)
composer release:gate:zero-surprise -- \
  --release-id=REL-YYYYMMDD-HHMM --dump-file=/path/to/predeploy.sql.gz \
  --credentials-file=/path/to/zero-surprise-predeploy.ini

# Vollstaendige Optionen anzeigen
composer release:gate:dashboard -- --help
composer release:gate:booking-confirmation-pdf -- --help
composer release:gate:zero-surprise -- --help

# Optional: CI Dashboard+Booking+API Integration Smoke (lokaler Repro, read-only)
docker compose up -d mysql php-fpm nginx openldap
until docker compose exec -T mysql mysqladmin ping -h localhost -uroot -psecret --silent; do sleep 2; done
until docker compose exec -T mysql mysql -uuser -ppassword -e "USE easyappointments; SELECT 1;" >/dev/null 2>&1; do sleep 2; done
for attempt in 1 2 3; do docker compose exec -T php-fpm php index.php console install && break; [ "$attempt" -eq 3 ] && exit 1; sleep 3; done
docker compose exec -T php-fpm php scripts/ci/dashboard_integration_smoke.php \
  --base-url=http://nginx --index-page=index.php \
  --username=administrator --password=administrator \
  --start-date=YYYY-MM-DD --end-date=YYYY-MM-DD \
  --checks=readiness_login_page,auth_login_validate,dashboard_metrics,booking_page_readiness,booking_extract_bootstrap,booking_available_hours,booking_unavailable_dates,api_unauthorized_guard,api_appointments_index,api_availabilities,ldap_settings_search,ldap_settings_search_missing_keyword,ldap_sso_success,ldap_sso_wrong_password
docker compose down -v --remove-orphans
```

Hinweis: `dashboard_integration_smoke.php`, `booking_write_contract_smoke.php` und `api_openapi_write_contract_smoke.php` akzeptieren optional `--checks=id1,id2`; Vorbedingungen werden automatisch transitiv ergänzt, die Ausführung bleibt aber in fester Registry-/Matrix-Reihenfolge.
Hinweis: Das Hygiene-Workflow `.github/workflows/hygiene.yml` fuehrt leichte Harness-/Drift-Checks wiederkehrend aus und schreibt standardmaessig nach `storage/logs/ci/agent-harness-readiness-latest.json` sowie `storage/logs/ci/harness-report-date-sanity-latest.json`.

Hinweis: `composer test` erstellt `config.php` automatisch aus `config-sample.php`, falls sie fehlt. `DB_HOST='mysql'` ist Compose-DNS. Host-`composer test` funktioniert nur mit host-kompatibler `config.php`.
Hinweis: `composer deptrac:analyze` kann auf neueren Host-PHP-Versionen (z. B. 8.5) zusaetzliche Vendor-Deprecation-Ausgaben zeigen; fuer CI-paritaer rauschfreie Ausgaben den Docker-Run `docker compose run --rm php-fpm composer deptrac:analyze` verwenden.
Hinweis: Das Dashboard Release Gate schreibt standardmaessig nach `storage/logs/release-gate/dashboard-gate-<UTC>.json`; mit `--output-json=/pfad/report.json` kann der Zielpfad ueberschrieben werden.
Hinweis: Das Zero-surprise Replay Gate schreibt standardmaessig nach `storage/logs/release-gate/zero-surprise-<UTC>.json`; mit `--output-json=/pfad/report.json` kann der Zielpfad ueberschrieben werden.
Hinweis: Der CI-Job `phpstan-application` ist blocking.
Hinweis: Der CI-Job `js-lint-changed` ist blocking.
Hinweis: Der CI-Job `architecture-ownership-map` ist blocking.
Hinweis: Der CI-Job `architecture-boundaries` ist blocking.
Hinweis: Der CI-Job `api-contract-openapi` ist blocking.
Hinweis: Der CI-Job `write-contract-booking` ist blocking.
Hinweis: Der CI-Job `write-contract-api` ist blocking.
Hinweis: Der CI-Job `booking-controller-flows` ist blocking.
Hinweis: Der CI-Job `typed-request-dto` ist blocking.
Hinweis: Der CI-Job `typed-request-contracts` ist blocking; der L2-Check ist in CI nicht mehr advisory.
Hinweis: `deep-check-bootstrap` liefert fuer Deep-Jobs nur noch ein `vendor/`-Artifact; die dockerisierten Deep-Jobs setzen CI-only Bootstrap-Flags, damit `php-fpm` in CI bei fehlendem `node_modules/` kein `npm install` und keinen Asset-Rebuild startet.
Hinweis: `deep-runtime-suite` ist der gemeinsame Producer fuer `api-contract-openapi`, `write-contract-booking`, `write-contract-api`, `booking-controller-flows` und `integration-smoke`; er startet `mysql + php-fpm + nginx` genau einmal, seeded genau einmal und schreibt `storage/logs/ci/deep-runtime-suite/manifest.json`.
Hinweis: Die bestehenden Blocking-Gates fuer diese fuenf Checks bleiben als leichte Verdict-Jobs bestehen und validieren nur ihren jeweiligen Suite-Eintrag aus Manifest + Artifact.
Hinweis: `coverage-shard-integration` importiert in CI weiterhin den gemeinsamen `deep-check-seed-snapshot`; die Runtime-Deep-Suites seeden dagegen einmal zentral in `deep-runtime-suite`.
Hinweis: Die CI-Jobs `coverage-shard-unit` und `coverage-shard-integration` sind blocking und laufen auf `push` nach `main` sowie auf non-draft PRs mit relevanten Deep-Changes; `coverage-shard-unit` deckt nur den pure-PHPUnit-Slice ohne Docker/MySQL/Seed ab, waehrend `coverage-shard-integration` die DB-gebundenen Unit-Tests plus Integrations-Controller im dockerisierten Stack ausfuehrt.
Hinweis: Der CI-Job `coverage-delta` ist blocking, aggregiert die beiden Coverage-Shards und prueft die gemergte Clover gegen die Repo-Policy; aktuelle Schwellwerte: baseline `22.45`, absolute minimum `22.25`, max drop `0.20pp`, epsilon `0.02pp`.
Hinweis: Der CI-Job `heavy-job-duration-trends` ist bewusst nicht blocking; er laeuft auf `push` nach `main`, vergleicht Median-Fenster der schweren Jobs gegen eine aeltere erfolgreiche Basis und meldet Regressionssignale nur per Warning/Artifact.
Hinweis: Das Architecture Boundaries Gate schreibt standardmaessig nach `storage/logs/ci/deptrac-changed-gate.json`, `storage/logs/ci/deptrac-github-actions.log` und `storage/logs/ci/component-boundary-latest.json`.
Hinweis: Das Request Contracts Gate schreibt standardmaessig nach `storage/logs/ci/request-contract-adoption-latest.json` und `storage/logs/ci/phpstan-request-contracts-l2.raw`.
Hinweis: Der API OpenAPI Contract Smoke schreibt standardmaessig nach `storage/logs/ci/api-openapi-contract-<UTC>.json`; mit `--output-json=/pfad/report.json` kann der Zielpfad ueberschrieben werden.
Hinweis: Der Booking Write Contract Smoke schreibt standardmaessig nach `storage/logs/ci/booking-write-contract-<UTC>.json`; mit `--output-json=/pfad/report.json` kann der Zielpfad ueberschrieben werden.
Hinweis: Der API OpenAPI Write Contract Smoke schreibt standardmaessig nach `storage/logs/ci/api-openapi-write-contract-<UTC>.json`; mit `--output-json=/pfad/report.json` kann der Zielpfad ueberschrieben werden.
Hinweis: Der Integration Smoke schreibt standardmaessig nach `storage/logs/ci/dashboard-integration-smoke-<UTC>.json`; mit `--output-json=/pfad/report.json` kann der Zielpfad ueberschrieben werden.
Hinweis: `dashboard_integration_smoke.php` unterstuetzt zusaetzlich `--browser-evidence=off|on-failure|always`; im Deep-Runtime-Suite-Pfad werden Failure-Artefakte standardmaessig nach `storage/logs/ci/deep-runtime-suite/integration-smoke-browser/` geschrieben (`summary.json`, Screenshot, Snapshot, Trace, Network-Log).
Hinweis: Die Coverage-Shard-Reports schreiben standardmaessig nach `storage/logs/ci/coverage-shard-unit.phpcov` und `storage/logs/ci/coverage-shard-integration.phpcov`; der gemergte Clover nach `storage/logs/ci/coverage-unit-clover.xml`, der Merge-Report nach `storage/logs/ci/coverage-merge-latest.json` und der Coverage Delta Gate Report nach `storage/logs/ci/coverage-delta-latest.json`.
Hinweis: Der Heavy-Job-Trend-Report schreibt standardmaessig nach `storage/logs/ci/heavy-job-duration-trends-latest.json`; in GitHub Actions wird zusaetzlich das Artifact `heavy-job-duration-trends-artifacts` hochgeladen.
Hinweis: Der CI-Job `pdf-renderer-latency` ist bewusst nicht blocking; er laeuft bei relevanten `pdf-renderer`/latency-guard-Aenderungen und meldet Schwellwertverletzungen als Warning + Artifact.
Hinweis: Der PDF-Renderer-Latenz-Report schreibt standardmaessig nach `storage/logs/ci/pdf-renderer-latency-latest.json`; in GitHub Actions wird zusaetzlich das Artifact `pdf-renderer-latency-artifacts` hochgeladen.
Hinweis: Der CI-Job `integration-smoke` prueft read-only die Kette Login/Auth + Dashboard Metrics + Booking-Read-Endpoints + API-Auth/Read.
Hinweis: Falls `architecture-boundaries` nach Blocking-Umschaltung durch False-Positives Releases blockiert, in einem Commit `continue-on-error: true` fuer den Job reaktivieren und ein Follow-up-Issue mit max. 14 Tagen Frist zur Rueckkehr in den Blocking-Modus anlegen.
Hinweis: Falls `typed-request-contracts` durch False-Positives Releases blockiert, den L2-Step in `ci.yml` temporaer auf advisory (`continue-on-error: true`) zurueckstellen und ein Follow-up-Issue mit max. 14 Tagen Frist zur Rueckkehr in den Blocking-Modus anlegen.
Hinweis: Falls `write-contract-booking` nach Blocking-Umschaltung durch False-Positives Releases blockiert, in einem Commit `continue-on-error: true` fuer den Job reaktivieren und ein Follow-up-Issue mit max. 14 Tagen Frist zur Rueckkehr in den Blocking-Modus anlegen.
Hinweis: Falls `write-contract-api` nach Blocking-Umschaltung durch False-Positives Releases blockiert, in einem Commit `continue-on-error: true` fuer den Job reaktivieren und ein Follow-up-Issue mit max. 14 Tagen Frist zur Rueckkehr in den Blocking-Modus anlegen.
Hinweis: Falls `coverage-delta` nach Blocking-Umschaltung durch False-Positives Releases blockiert, in einem Commit `continue-on-error: true` fuer den Job reaktivieren und ein Follow-up-Issue mit max. 14 Tagen Frist zur Rueckkehr in den Blocking-Modus anlegen.

Docker php-fpm Bootstrap (`docker/php-fpm/start-container`):

- setzt `git config core.fileMode false` und `chmod -R 777 storage`
- erstellt `config.php` via `cp config-sample.php config.php`, falls fehlend
- installiert Abhaengigkeiten bei Bedarf: `composer install`, `npm install`
- baut Assets, falls `assets/vendor` fehlt: `npx gulp compile`
- respektiert in CI optional `EA_SKIP_NPM_BOOTSTRAP=1` und `EA_SKIP_ASSET_BUILD_BOOTSTRAP=1`, um Deep-Jobs ohne redundantes Node-/Asset-Bootstrap zu starten

Docker-Services (lokal) & Debug:

- phpMyAdmin: `http://localhost:8080` (credentials `root` / `secret`)
- Mailpit: `http://localhost:8025`
- PDF-Renderer: `PDF_RENDERER_DEBUG_DUMP=true` aktiviert temporaere HTML-Debug-Dumps
- CalDAV (Baikal): `http://localhost:8100` (credentials `admin` / `admin`), danach `http://baikal/dav.php` mit angelegtem Nutzer verwenden
- OpenLDAP: `openldap` auf `389`/`636`, Admin-UI unter `http://localhost:8200` (credentials `cn=admin,dc=example,dc=org` / `admin`)
- macOS/iCloud Troubleshooting (MySQL InnoDB OS error 35 auf `./#innodb_redo/*`): `ls -lO@ docker/mysql/#innodb_redo`; bei `compressed,dataless` Dateien rehydrieren (z. B. `dd if='docker/mysql/#innodb_redo/#ib_redo6' of=/dev/null bs=512 count=1`) und Container neu starten; falls erfolglos `docker/mysql` wie im DB-Restore-Abschnitt zuruecksetzen.

Warnung (Worktrees): Nutze pro Worktree einen eindeutigen Compose-Projektnamen, damit sich Container/Volumes nicht ueberlagern.
Beispiel: `docker compose -p fh-main up -d` im Haupt-Worktree und `docker compose -p fh-hotfix up -d` in einem zweiten Worktree.
So vermeidest du gemischte Stacks (z. B. `nginx` aus Worktree A, `php-fpm`/`mysql` aus Worktree B).
Managed lokale Hook-/Gate-Stacks leiten ihren MySQL-Datenpfad jetzt automatisch nach `docker/.ci-mysql/<compose-project>` ab; der normale Dev-Stack bleibt bei `docker/mysql`.

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

- Der managed `pre-commit` Hook erwartet `vendor/` und `node_modules/` und faehrt fuer PHP-bezogene Commits einen deterministischen Docker-/MySQL-Bootstrap aehnlich zu `pre_pr_quick.sh`.
- In neuen Worktrees einmal `./scripts/setup-worktree.sh` vor dem ersten Commit ausfuehren.
- `scripts/ci/pre_pr_quick.sh` und `scripts/ci/pre_pr_full.sh` bootstrappen fehlende `vendor/`/`node_modules/` automatisch (via `composer install` + `npm ci`/`npm install`).
- Bei diesem Auto-Bootstrap ist Netzwerkzugriff auf Package-Registries erforderlich; ohne Netz bleiben die Gates weiterhin blockiert.
- Bei Netzwerkrestriktionen koennen `composer install`/`npm ci` scheitern; Hook-Fehler sind dann erwartbar.
- Ausnahme fuer reine Doku-/Meta-Commits: `SKIP_PRECOMMIT=1 git commit ...`
- `./scripts/setup-worktree.sh` installiert managed `.git/hooks/pre-commit` und `.git/hooks/pre-push` Hooks.
- Bestehende Clones koennen die Hooks mit `./scripts/install-git-hooks.sh` aktualisieren; mit `FORCE_HOOK_INSTALL=1 ./scripts/install-git-hooks.sh` werden bewusst aeltere Custom-Hooks ersetzt.
- Fuer einmaliges Bypassen: `SKIP_PREPUSH=1 git push ...`; fuer Full-Gate beim Push: `PRE_PUSH_FULL=1 git push ...`.
- Optional kann die Hook-/Pre-PR-Basis mit `PRE_PR_BASE_REF` ueberschrieben werden (Standard: `main`).
- `--no-verify` nur als letzter Ausweg verwenden.

## Coding Style & Konventionen

- Editor/Format: `.editorconfig` (4 Spaces, LF, Final Newline).
- Prettier fuer PHP/JS gemaess Repo-Konfiguration, z. B. `npx prettier --write application/**/*.php`.
- Benennung: Controller enden auf `Controller`, Models auf `Model`, Views nach Route-Alias.
- JS: Modulnamen spiegeln Verzeichnisstruktur (z. B. `assets/js/booking/book.js`).
- `snake_case` fuer Config-Keys, `camelCase` in JS.
- Datenbankaenderungen immer via CodeIgniter-Migrations (inkl. Rollback-Pfad).
- Secrets nie ins VCS; nutze `config.php`.
- Bugs: Bei Bugfixes einen passenden Regressionstest ergaenzen, wenn sinnvoll.

## Commit- & PR-Richtlinien

- Commits: kurz, praesentisch, imperativ (z. B. `Fix booking validation`).
- Vor PR: Tests gruen (`docker compose run --rm php-fpm composer test`), Migrations inkl. Rollback vorhanden.
- `config-sample.php`/Docs bei Bedarf aktualisieren; bei UI-Aenderungen Screenshots/GIFs beilegen.
- Jeder PR-Implementierungsplan enthaelt einen Review-Loop: Reviewer A prueft Bugs/Regressionen/Security/Edge-Cases, Reviewer B prueft Architektur/Lesbarkeit/Testluecken/Wartbarkeit; Findings fixen und wiederholen bis keine Issues mehr offen sind.
- Vor `ready for review` den vollen Pre-PR-Gate inklusive Coverage Delta ausfuehren (`PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh`).
- Wenn alle Gates gruen sind: `ready for review` pushen und danach mit `$Babysit PR` weiter monitoren.
- Wenn ein Umsetzungsplan mehrere PRs umfasst, gilt das sequentielle Standardvorgehen: jeden PR `ready for review` pushen, mit [$babysit-pr](/Users/robinbeier/Developers/forscherhaus-appointments/.codex/skills/babysit-pr/SKILL.md) bis zu komplett gruener CI, fehlenden offenen Review-Items und `mergeable`-Status beobachten, dann den PR mergen und erst danach mit dem naechsten PR im Plan weitermachen.
- Symphony-/Infrastruktur-PRs nur dann mit Linear-Issue-IDs in Titel oder PR-Body verknuepfen, wenn der PR fachlich wirklich zu diesem Ticket gehoert; sonst entstehen verwirrende Fremd-Attachments auf Arbeits-Issues.

## Post-Release-Upgrade-Kampagne

- Fokus nach dem Release: kontrollierte Dependency-, Upstream- und Gate-Nachpflege in kleinen, mergebaren PRs.
- Pro Kampagnen-PR genau ein klar abgegrenzter Slice: entweder Dependency-Update, Upstream-Nachpflege oder Gate-/Governance-Dokumentation; keine Mischung mit fachlichen Produkt-Aenderungen.
- Reine Docs-/Governance-PRs bleiben docs-only und validieren ueber inhaltliche Konsistenzpruefung sowie Gegenlesen aller genannten Kommandos, Skripte und Gate-Namen gegen die aktuelle Repo-Struktur.
- Dependency-/Gate-PRs starten mit dem schmalsten passenden lokalen Check; bevor sie `ready for review` werden, bleiben die einschlaegigen Blocking-Gates inklusive `PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh` massgeblich.
- Die Kampagne bleibt strikt sequentiell: immer nur ein aktiver Upgrade-PR; der naechste Slice startet erst nach gruener CI, geklaerten Findings und abgeschlossenem Merge des vorherigen PRs.
- Blocking-Gates bleiben auch in der Kampagne blocking; temporaere Advisory-Ausnahmen sind nur bei nachweisbaren False-Positives zulaessig und brauchen ein Follow-up-Issue mit Rueckkehrfrist von hoechstens 14 Tagen.
- Keine Major-Dependency-Upgrades in dieser Phase; diese werden in die naechste Entwicklungsphase verschoben.
- Fuer Dependency-Sweeps vor dem geplanten Upstream-Merge (`easyappointments` 1.6.0): den `roave/security-advisories`-Konflikt nicht isoliert aufloesen; stattdessen nur produktionsrelevante Security-Hotfixes (z. B. `firebase/php-jwt`) umsetzen und `roave` erst nach dem Upstream-Merge neu bewerten.
- Nach Deployment ist ein Zeitfenster von ca. 6 Monaten bis zum naechsten Major-Update vorgesehen; dort werden Major-Upgrades geplant, getestet und gebuendelt umgesetzt.
- `phpmailer/phpmailer` hat aktuell die niedrigste Prioritaet:
  In Produktion werden keine E-Mails versendet, und in der Entwicklung fehlen derzeit die benoetigten E-Mail-Testfaehigkeiten. Entsprechende Upgrades erst in der naechsten Major-Phase bewerten.

## Domaenen-Invariante (derzeit)

- `services.attendants_number` ist aktuell hart auf `1` begrenzt.
- Reviews/Fixes fuer `attendants_number > 1` nur umsetzen, wenn der Produktentscheid explizit auf Multi-Attendant geaendert wird.
