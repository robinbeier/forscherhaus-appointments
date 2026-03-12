# AGENTS.md - Forscherhaus Appointments x ChatGPT Codex

Ziel: merge-faehige, konsistente Beitraege fuer das Schul-Terminbuchungssystem.

## Kanonische Harness-Quellen

- `README.md`: Operator-Onboarding, Quickstart, lokale Services, Kurzform der Repo-Regeln.
- `WORKFLOW.md`: Agent-Runtime, Codex-Workpad, Linear-State-Modell, Ticket-zu-Merge-Ablauf.
- `docs/agent-harness-index.md`: Routing zwischen Onboarding, Runtime, CI/Gates, Architektur, Ownership und Symphony.
- `AGENTS.md`: kompakte Repo-Guardrails plus die erweiterte lokale/CI-Kommandomatrix.

Anti-Drift-Regel: Wenn Inhalt schon kanonisch in einer der Dateien oben oder in einer spezialisierten Doku liegt, steht hier nur noch die Kurzfassung plus Verweis.

## Repo-Scope und harte Leitplanken

- Stack: PHP `>= 8.1` (`8.2+` bevorzugt), CodeIgniter, MySQL, jQuery/Bootstrap/FullCalendar, npm + Gulp.
- Produktionscode bleibt in `application/`; `system/` nur fuer explizite Upstream-Patches anfassen.
- DB-Schema-Aenderungen immer ueber CodeIgniter-Migrations mit Rollback-Pfad.
- Secrets und lokale Credentials nie committen; `config.php` bleibt lokal, `config-sample.php` enthaelt nur sichere Defaults.
- Frontend-Quellen leben unter `assets/`, kompilierte Artefakte unter `build/` bleiben versioniert.
- `services.attendants_number` bleibt auf `1` begrenzt, bis der Produktentscheid explizit Multi-Attendant fordert.
- `docs/maps/component_ownership_map.json` ist die kanonische Ownership-Quelle. Bei `single-owner` oder `manual_approval_required` nur eng und konservativ aendern; gruene Checks ersetzen keine Produktentscheidung.
- In der aktuellen Release-/Upgrade-Phase gelten kleine, risikoarme, mergebare Aenderungen als Standard; keine breiten Rewrites oder ungeplanten Major-Upgrades.

## Setup und Arbeitsumgebung

Neue Worktrees zuerst mit diesem Repo-Setup initialisieren:

```bash
./scripts/setup-worktree.sh
```

Wesentliche Defaults:

- Lokaler Standard-Stack: `docker compose up -d`
- Host-PHP + Docker-`pdf-renderer`: `export PDF_RENDERER_URL=http://localhost:3003`
- LDAP-Fixtures fuer Search/Import/SSO:

```bash
bash ./scripts/ldap/reset_directory.sh
bash ./scripts/ldap/smoke.sh
```

- Pro Worktree immer einen eigenen Compose-Projektnamen verwenden, z. B. `docker compose -p fh-main up -d` oder `docker compose -p fh-hotfix up -d`.
- `docker/php-fpm/start-container` setzt `core.fileMode=false`, erstellt bei Bedarf `config.php`, installiert fehlende PHP/Node-Abhaengigkeiten und baut Assets, falls `assets/vendor` fehlt.
- CI kann `EA_SKIP_NPM_BOOTSTRAP=1` und `EA_SKIP_ASSET_BUILD_BOOTSTRAP=1` setzen, damit Deep-Jobs kein redundantes Node-/Asset-Bootstrap fahren.

Fuer Details siehe:

- `docs/docker.md`: Services, Docker-Stack, Dump-Restore, InnoDB-Fehlerbild `OS error 35`, PDF-Renderer, Baikal, OpenLDAP
- `docs/console.md`: `php index.php console ...`
- `README.md`: Quickstart, Services, Hook-Shortcuts, Test-vor-PR-Kurzpfad

## Kernbefehle

```bash
# Frontend / Build
npm start
npm run build
npm run assets:refresh
npm run lint:js
npx gulp scripts
npx gulp styles
npm run docs

# Basis-Tests (CI-paritaetisch)
docker compose run --rm php-fpm composer test
docker compose run --rm php-fpm sh -lc 'APP_ENV=testing php vendor/bin/phpunit'
docker compose run --rm php-fpm composer phpstan:application

# Lokale Review-Gates
bash ./scripts/ci/pre_pr_quick.sh
PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh
PRE_PR_BASE_REF=origin/release-branch bash ./scripts/ci/pre_pr_quick.sh
PRE_PR_BASE_REF=origin/release-branch PRE_PR_REQUEST_CONTRACTS_L2_BLOCKING=0 bash ./scripts/ci/pre_pr_full.sh

# Harness-/Signal-Checks
composer check:agent-harness-readiness
composer check:harness-report-dates
composer check:pdf-renderer-latency
GITHUB_TOKEN="$(gh auth token)" GITHUB_REPOSITORY="robinbeier/forscherhaus-appointments" composer check:heavy-job-duration-trends
```

Hinweise:

- `composer test` erstellt `config.php` automatisch aus `config-sample.php`, falls sie fehlt.
- `DB_HOST='mysql'` ist Compose-DNS; Host-`composer test` funktioniert nur mit host-kompatibler `config.php`.
- `composer deptrac:analyze` wegen Host-PHP-Rauschen bevorzugt via Docker ausfuehren: `docker compose run --rm php-fpm composer deptrac:analyze`.

## Erweiterte Validierung nach Themen

### Architektur und Ownership

```bash
python3 scripts/docs/generate_architecture_ownership_docs.py
python3 scripts/docs/generate_architecture_ownership_docs.py --check
python3 scripts/ci/check_architecture_ownership_map.py

python3 scripts/docs/generate_codeowners_from_map.py
python3 scripts/docs/generate_codeowners_from_map.py --check
docker compose run --rm php-fpm composer deptrac:analyze
bash scripts/ci/run_deptrac_changed_gate.sh
python3 scripts/ci/check_component_boundaries.py
composer check:codeowners-sync
composer check:component-boundaries
composer check:architecture-boundaries
```

### Request DTOs und Request Contracts

```bash
docker compose run --rm php-fpm composer phpstan:request-dto
docker compose run --rm php-fpm composer test:request-dto
docker compose run --rm php-fpm php scripts/ci/check_request_dto_adoption.php

docker compose run --rm php-fpm composer phpstan:request-contracts:l1
docker compose run --rm php-fpm composer test:request-contracts
docker compose run --rm php-fpm php scripts/ci/check_request_contract_adoption.php
docker compose run --rm php-fpm composer phpstan:request-contracts:l2
```

### Coverage

```bash
docker compose run --rm php-fpm composer test:coverage:unit-shard
docker compose run --rm php-fpm composer test:coverage:integration-shard
docker compose run --rm php-fpm composer test:coverage:merge-shards
docker compose run --rm php-fpm composer test:coverage:unit
docker compose run --rm php-fpm composer check:coverage:delta
```

Aktuelle `coverage-delta`-Policy: baseline `22.45`, absolutes Minimum `22.25`, maximaler Drop `0.20pp`, Epsilon `0.02pp`.

### Write-Contracts, Integration-Smokes und Deep Runtime

Spezialisierte Dokus sind kanonisch:

- `docs/ci-write-contracts.md`: Booking-/API-Write-Contracts, lokale Repro-Schritte, Reports, Rollback-Regel
- `docs/release-gate-dashboard.md`: Dashboard-Release-Gate, Integration-Smoke, Browser-Evidence
- `docs/release-gate-booking-confirmation-pdf.md`: Booking-Confirmation-PDF-Gate
- `docs/release-gate-zero-surprise.md`: Restore-Dump-Replay-Gate

Hauefige Einstiegskommandos:

```bash
docker compose exec -T php-fpm composer contract-test:api-openapi -- \
  --base-url=http://nginx --index-page=index.php --openapi-spec=/var/www/html/openapi.yml \
  --username=administrator --password=administrator

docker compose exec -T php-fpm composer contract-test:write-path -- \
  --base-url=http://nginx --index-page=index.php --openapi-spec=/var/www/html/openapi.yml \
  --username=administrator --password=administrator --retry-count=1 --booking-search-days=14

docker compose exec -T php-fpm composer test:booking-controller-flows

docker compose exec -T php-fpm php scripts/ci/run_deep_runtime_suite.php \
  --suites=api-contract-openapi,write-contract-booking,write-contract-api,booking-controller-flows,integration-smoke \
  --base-url=http://nginx --index-page=index.php --openapi-spec=/var/www/html/openapi.yml \
  --username=administrator --password=administrator \
  --booking-search-days=14 --retry-count=1 \
  --start-date=2026-01-01 --end-date=2026-01-31 \
  --report-dir=storage/logs/ci/deep-runtime-suite
```

Wichtig:

- Die lokalen Docker-Repros fuer diese Checks stehen in den verlinkten Spezial-Dokus; hier werden nur die eigentlichen Entry-Commands gelistet.
- `dashboard_integration_smoke.php`, `booking_write_contract_smoke.php` und `api_openapi_write_contract_smoke.php` akzeptieren optional `--checks=id1,id2`; Vorbedingungen werden automatisch transitiv ergaenzt.
- `deep-runtime-suite` produziert die Manifest-Basis fuer `api-contract-openapi`, `write-contract-booking`, `write-contract-api`, `booking-controller-flows` und `integration-smoke`.
- Browser-Evidence aus dem Integration-Smoke landet im Deep-Runtime-Pfad standardmaessig unter `storage/logs/ci/deep-runtime-suite/integration-smoke-browser/`.

### Release Gates

```bash
composer release:gate:dashboard -- --help
composer release:gate:booking-confirmation-pdf -- --help
composer release:gate:zero-surprise -- --help
```

Typische Reports:

- CI/Harness: `storage/logs/ci/*.json`, `storage/logs/ci/*.phpcov`, `storage/logs/ci/*.xml`
- Release Gates: `storage/logs/release-gate/*.json`

Die exakten Default-Dateinamen und Optionen gehoeren in die jeweilige Spezial-Doku oder in `--help`, nicht als langer Doppelbestand in dieses Dokument.

### Symphony / Pilot

```bash
bash ./scripts/ci/run_symphony_pilot_checks.sh
bash ./scripts/ci/run_symphony_pilot_checks.sh --with-full-gate
cp .env.symphony.pilot.example .env.symphony.pilot
bash ./scripts/symphony/start_pilot.sh
bash ./scripts/symphony/stop_pilot.sh
python3 ./scripts/symphony/run_soak_gate.py --state-url http://127.0.0.1:8787/api/v1/state --duration-seconds 86400 --poll-seconds 60
```

Symphony-Workflow und Linear-State-Regeln stehen kanonisch in `WORKFLOW.md`. Fuer Pilot-/State-API-Betrieb zusaetzlich `tools/symphony/README.md` und `docs/symphony/STAGING_PILOT_RUNBOOK.md` lesen.

## CI-Wahrheit und schnelle Statuspruefung

Quelle der Wahrheit fuer Trigger, Blocking-Status, Artifacts und `continue-on-error` ist `.github/workflows/ci.yml`.

Aktuell blocking:

- `phpstan-application`
- `js-lint-changed`
- `architecture-ownership-map`
- `architecture-boundaries`
- `api-contract-openapi`
- `write-contract-booking`
- `write-contract-api`
- `booking-controller-flows`
- `typed-request-dto`
- `typed-request-contracts`
- `coverage-shard-unit`
- `coverage-shard-integration`
- `coverage-delta`

Aktuell nicht blocking:

- `heavy-job-duration-trends`
- `pdf-renderer-latency`

Generischer `gh`-Status-Check statt vieler kopierter Schleifen:

```bash
ci_job_status() {
  local job="$1" event="${2:-pull_request}" limit="${3:-40}"
  for run_id in $(gh run list --workflow CI --event "$event" --limit "$limit" --json databaseId -q '.[].databaseId'); do
    gh run view "$run_id" --json jobs -q ".jobs[] | select(.name==\"$job\") | .conclusion"
  done
}

ci_job_status architecture-boundaries pull_request | awk '$1 != "cancelled"' | head -n 7
ci_job_status write-contract-booking pull_request | grep -E '^(success|failure)$' | head -n 7
ci_job_status phpstan-application push 20 | head -n 7
```

Rollback-Regel fuer False-Positives: nur den betroffenen Job temporaer auf advisory zurueckstellen, Follow-up-Issue anlegen und innerhalb von spaetestens 14 Tagen in den blocking Modus zurueckkehren.

## Hooks, Commits und PRs

- `./scripts/setup-worktree.sh` installiert die gemanagten `.git/hooks/pre-commit` und `.git/hooks/pre-push`.
- Bestehende Clones mit `./scripts/install-git-hooks.sh` aktualisieren; `FORCE_HOOK_INSTALL=1 ./scripts/install-git-hooks.sh` ersetzt bewusst aeltere Custom-Hooks.
- `pre-commit` erwartet `vendor/` und `node_modules/`; `pre_pr_quick.sh` und `pre_pr_full.sh` bootstrappen fehlende Abhaengigkeiten automatisch.
- Ohne Netzwerk koennen `composer install` oder `npm ci` scheitern; Hook-/Gate-Fehler sind dann erwartbar.
- Fuer docs-only oder Meta-Commits: `SKIP_PRECOMMIT=1 git commit ...`
- Fuer einmaliges Push-Bypassen: `SKIP_PREPUSH=1 git push ...`
- Fuer Full-Gate auf Push: `PRE_PUSH_FULL=1 git push ...`
- `PRE_PR_BASE_REF` kann die Vergleichsbasis ueberschreiben; `--no-verify` nur als letzter Ausweg.
- Commits kurz, praesentisch und imperativ formulieren.
- Vor `ready for review` muessen die relevanten lokalen Gates gruen sein; Standard fuer review-reife Aenderungen ist `PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh`.
- Bei Bugfixes nach Moeglichkeit einen passenden Regressionstest ergaenzen.
- `config-sample.php` und Doku mitziehen, wenn Default-Verhalten oder Setup sich aendert; UI-Aenderungen mit Screenshot/GIF belegen.
- Jeder Umsetzungsplan mit mehreren PRs bleibt strikt sequentiell: einen PR fertigstellen, review-/CI-clean mergen, dann erst den naechsten starten.
- Symphony-/Infrastruktur-PRs nur mit Linear-Issue-IDs verknuepfen, wenn sie fachlich wirklich zu diesem Ticket gehoeren.

## Coding Style und Benennung

- Formatierung folgt `.editorconfig` (4 Spaces, LF, Final Newline).
- Prettier gemaess Repo-Konfiguration verwenden, z. B. `npx prettier --write application/**/*.php`.
- Controller enden auf `Controller`, Models auf `Model`, Views orientieren sich am Route-Alias.
- JS-Modulnamen spiegeln die Verzeichnisstruktur, z. B. `assets/js/booking/book.js`.
- `snake_case` fuer Config-Keys, `camelCase` in JavaScript.

## Aktuelle Upgrade-Kampagne

- Pro PR genau ein klar abgegrenzter Slice: Dependency-Update, Upstream-Nachpflege oder Gate-/Governance-Aenderung; nicht mischen.
- Docs-/Governance-PRs bleiben docs-only und pruefen die referenzierten Kommandos, Skripte und Gate-Namen gegen die aktuelle Repo-Struktur.
- Blocking-Gates bleiben auch in der Kampagne blocking; Advisory-Ausnahmen nur bei nachweisbarem False-Positive mit Follow-up-Issue und Rueckkehrfrist <= 14 Tage.
- Keine ungeplanten Major-Dependency-Upgrades in dieser Phase.
- Fuer den geplanten `easyappointments`-Upstream-Merge `1.6.0` den `roave/security-advisories`-Konflikt nicht isoliert "wegupgraden"; zuerst nur produktionsrelevante Security-Hotfixes umsetzen.
- `phpmailer/phpmailer` hat derzeit niedrige Prioritaet, solange produktiv keine E-Mails versendet werden und lokal keine tragfaehige E-Mail-Testfaehigkeit bereitsteht.
