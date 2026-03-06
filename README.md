# Forscherhaus Appointments

Forscherhaus school scheduling fork of Easy!Appointments.

This repository prioritizes stable, low-risk delivery for school operations and keeps compatibility with the existing Easy!Appointments architecture.

## Scope

-   Fork base: Easy!Appointments (`v1.5.2` lineage)
-   Stack: PHP `>=8.1` (8.2+ recommended), CodeIgniter, MySQL, jQuery/Bootstrap/FullCalendar
-   Primary goal: school-specific scheduling workflows and operational reliability

## Fork Invariants

-   `services.attendants_number` is intentionally restricted to `1`.
-   Do not implement multi-attendant behavior unless product scope changes explicitly.

## Quickstart (Recommended)

```bash
./scripts/setup-worktree.sh
docker compose up -d
```

Smoke-check:

```bash
docker compose run --rm php-fpm composer test
npm run build
```

## Development Commands

```bash
# watch assets
npm start

# full production build
npm run build

# optional frontend lint (ESLint, excludes minified assets)
npm run lint:js

# optional vendor/theme refresh
npm run assets:refresh

# CI-parity tests
docker compose run --rm php-fpm composer test
docker compose run --rm php-fpm sh -lc 'APP_ENV=testing php vendor/bin/phpunit'

# optional API OpenAPI contract smoke command (CI-parity stack: mysql/php-fpm/nginx)
docker compose exec -T php-fpm composer contract-test:api-openapi -- \
  --base-url=http://nginx --index-page=index.php --openapi-spec=/var/www/html/openapi.yml \
  --username=administrator --password=administrator

# optional booking write-path contract smoke command (mutation contract, deterministic fixtures)
docker compose exec -T php-fpm composer contract-test:booking-write -- \
  --base-url=http://nginx --index-page=index.php \
  --username=administrator --password=administrator \
  --booking-search-days=14 --retry-count=1 \
  --checks=booking_register_success_contract,booking_register_manage_update_contract,booking_register_unavailable_contract,booking_reschedule_manage_mode_contract,booking_cancel_success_contract,booking_cancel_unknown_hash_contract

# optional API OpenAPI write-path contract smoke command (customer+appointment lifecycle)
docker compose exec -T php-fpm composer contract-test:api-openapi-write -- \
  --base-url=http://nginx --index-page=index.php --openapi-spec=/var/www/html/openapi.yml \
  --username=administrator --password=administrator \
  --retry-count=1 --booking-search-days=14 \
  --checks=appointments_write_unauthorized_guard,customers_store_contract,appointments_store_contract,appointments_update_contract,appointments_destroy_contract,customers_destroy_contract

# optional: each smoke/write script also accepts --checks=id1,id2.
# prerequisites are auto-expanded transitively and execution still follows the script's fixed registry order.

# optional combined write-path contract command
docker compose exec -T php-fpm composer contract-test:write-path -- \
  --base-url=http://nginx --index-page=index.php --openapi-spec=/var/www/html/openapi.yml \
  --username=administrator --password=administrator \
  --retry-count=1 --booking-search-days=14

# optional booking controller flow tests (register/reschedule/cancel; mutation-safe in ephemeral DB)
docker compose exec -T php-fpm composer test:booking-controller-flows

# optional typed request-dto checks (phpstan + focused unit suite + adoption guard)
docker compose run --rm php-fpm composer phpstan:request-dto
docker compose run --rm php-fpm composer test:request-dto
docker compose run --rm php-fpm php scripts/ci/check_request_dto_adoption.php

# optional typed request-contracts checks (full domain-critical request/controller scope rollout)
docker compose run --rm php-fpm composer phpstan:request-contracts:l1
docker compose run --rm php-fpm composer test:request-contracts
docker compose run --rm php-fpm php scripts/ci/check_request_contract_adoption.php
docker compose run --rm php-fpm composer phpstan:request-contracts:l2

# optional coverage shards + merge + coverage delta gate
docker compose run --rm php-fpm composer test:coverage:unit-shard
docker compose run --rm php-fpm composer test:coverage:integration-shard
docker compose run --rm php-fpm composer test:coverage:merge-shards
docker compose run --rm php-fpm composer test:coverage:unit
docker compose run --rm php-fpm composer check:coverage:delta
```

## Release Gates (Optional, Pre-Deploy)

```bash
# Zero-surprise restore-dump replay (manual mandatory pre-deploy runbook step)
composer release:gate:zero-surprise -- --help

# Zero-surprise post-deploy live canary (used by deploy_ea.sh)
php scripts/release-gate/zero_surprise_live_canary.php --help

# Dashboard gate
composer release:gate:dashboard -- --help

# Booking confirmation PDF gate
composer release:gate:booking-confirmation-pdf -- --help
```

References:

-   [Zero-surprise restore-dump replay](docs/release-gate-zero-surprise.md)
-   [Zero-surprise post-deploy live canary + rollback trigger](docs/release-gate-zero-surprise.md)
-   [Dashboard release gate](docs/release-gate-dashboard.md)
-   [Booking confirmation PDF gate](docs/release-gate-booking-confirmation-pdf.md)

## Local Services (Docker)

-   App: `http://localhost`
-   phpMyAdmin: `http://localhost:8080` (`root` / `secret`)
-   Mailpit: `http://localhost:8025`
-   PDF renderer: `http://localhost:3003`
-   Baikal (CalDAV): `http://localhost:8100`
-   phpLDAPadmin: `http://localhost:8200`

If you run PHP on host with Docker PDF renderer, set:

```bash
export PDF_RENDERER_URL=http://localhost:3003
```

## Worktree Safety

When using multiple git worktrees, always set a unique Docker Compose project name per worktree:

```bash
docker compose -p fh-main up -d
docker compose -p fh-hotfix up -d
```

This prevents mixed container mounts across worktrees.

## Documentation Map

-   [Project runbook and contributor guardrails](AGENTS.md)
-   [Write-path CI contracts](docs/ci-write-contracts.md)
-   [Architecture map](docs/architecture-map.md)
-   [Ownership map](docs/ownership-map.md)
-   [Installation guide](docs/installation-guide.md)
-   [Docker guide](docs/docker.md)
-   [Console commands](docs/console.md)
-   [REST API](docs/rest-api.md)
-   [Google Calendar sync](docs/google-calendar-sync.md)
-   [CalDAV sync](docs/caldav-calendar-sync.md)
-   [LDAP](docs/ldap.md)
-   [Provider room feature](docs/feature-provider-room.md)
-   [FAQ](docs/faq.md)

## Contribution Rules (Short)

-   Keep production code changes inside `application/`.
-   Do not modify `system/` unless applying an explicit upstream patch.
-   Use CodeIgniter migrations for all DB schema changes (with rollback path).
-   Keep `config.php` out of version control; update `config-sample.php` only with safe defaults.

## Testing Before PR

```bash
docker compose run --rm php-fpm composer test
docker compose run --rm php-fpm composer phpstan:application
npm run lint:js
python3 scripts/docs/generate_architecture_ownership_docs.py --check
python3 scripts/ci/check_architecture_ownership_map.py
python3 scripts/docs/generate_codeowners_from_map.py --check
bash scripts/ci/run_deptrac_changed_gate.sh
python3 scripts/ci/check_component_boundaries.py
docker compose run --rm php-fpm composer deptrac:analyze
composer check:codeowners-sync
composer check:component-boundaries
composer check:architecture-boundaries
docker compose run --rm php-fpm composer phpstan:request-dto
docker compose run --rm php-fpm composer test:request-dto
docker compose run --rm php-fpm php scripts/ci/check_request_dto_adoption.php
docker compose run --rm php-fpm composer phpstan:request-contracts:l1
docker compose run --rm php-fpm composer test:request-contracts
docker compose run --rm php-fpm php scripts/ci/check_request_contract_adoption.php
```

Recommended local pre-PR gates:

```bash
# fast gate (used by managed pre-push hook)
bash ./scripts/ci/pre_pr_quick.sh

# full CI-parity gate before "Ready for review"
bash ./scripts/ci/pre_pr_full.sh

# optional full gate + coverage delta
PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh

# optional advisory override for request-contracts L2 (default is strict/blocking)
PRE_PR_REQUEST_CONTRACTS_L2_BLOCKING=0 bash ./scripts/ci/pre_pr_full.sh
```

Hook note: `./scripts/setup-worktree.sh` installs a managed `.git/hooks/pre-push` hook that runs `pre_pr_quick.sh`.
Use `SKIP_PREPUSH=1 git push ...` to bypass once, or `PRE_PUSH_FULL=1 git push ...` to run the full gate at push time.

CI note: deep docker-compose jobs run only when relevant files changed and, for pull requests, only when the PR is not in draft mode.
CI note: `deep-check-bootstrap` now ships a vendor-only dependency artifact. Deep docker-compose jobs restore `vendor/` from that artifact and set CI-only bootstrap flags so `php-fpm` does not rerun `npm install` or asset compilation when `node_modules/` is absent.
CI note: dockerized deep jobs now restore a deterministic `deep-check-seed-snapshot` artifact instead of rerunning `php index.php console install` per job.
CI note: the HTTP deep jobs (`api-contract-openapi`, `write-contract-booking`, `write-contract-api`, `integration-smoke`) use the CI-parity `mysql + php-fpm + nginx` stack; `booking-controller-flows` continues to run with `mysql + php-fpm` only.
CI note: `integration-smoke` covers auth + dashboard metrics + booking read endpoints + API auth/read endpoints (read-only).
CI note: the `api-contract-openapi` check validates selected API v1 endpoints against `openapi.yml` and is blocking.
CI note: the `write-contract-booking` check validates booking write-path HTTP contracts and is blocking.
CI note: rollback policy for `write-contract-booking`: if false positives block delivery, restore warn-only (`continue-on-error: true`) in one commit and create a follow-up issue with max 14-day expiry.
CI note: the `write-contract-api` check validates API write-path OpenAPI contracts and is blocking.
CI note: rollback policy for `write-contract-api`: if false positives block delivery, restore warn-only (`continue-on-error: true`) in one commit and create a follow-up issue with max 14-day expiry.
CI note: the `booking-controller-flows` check validates booking register/reschedule/cancel controller flows and is blocking.
CI note: the `typed-request-dto` check validates scoped DTO normalization adoption and is blocking.
CI note: the `typed-request-contracts` check validates typed request-contract rollout on the full domain-critical scope and is blocking (L1 + adoption + L2).
CI note: the `phpstan-application` check is blocking.
CI note: the `js-lint-changed` check is blocking.
CI note: the `architecture-ownership-map` check is blocking.
CI note: the `architecture-boundaries` check validates CODEOWNERS drift + Deptrac changed-file layer violations + component loader boundaries and is blocking.
CI note: coverage now runs in two blocking deep-check shards (`coverage-shard-unit`, `coverage-shard-integration`) and feeds an aggregating blocking `coverage-delta` gate. `coverage-shard-unit` runs the pure PHPUnit-only subset on the GitHub runner without Docker/MySQL/seed, while `coverage-shard-integration` carries the DB-backed unit tests plus integration controllers in the dockerized deep stack.
CI note: `coverage-delta` current policy thresholds are baseline `22.45%`, absolute minimum `22.25%`, max drop `0.20pp`, epsilon `0.02pp`. Coverage runs on pushes to `main` and non-draft PRs when deep checks are relevant.
CI note: coverage artifacts are written to `storage/logs/ci/coverage-shard-unit.phpcov`, `storage/logs/ci/coverage-shard-integration.phpcov`, `storage/logs/ci/coverage-unit-clover.xml`, `storage/logs/ci/coverage-merge-latest.json`, and `storage/logs/ci/coverage-delta-latest.json`.
CI note: `architecture-boundaries` artifacts are written to `storage/logs/ci/deptrac-changed-gate.json`, `storage/logs/ci/deptrac-github-actions.log`, and `storage/logs/ci/component-boundary-latest.json`.
CI note: `write-contract-booking` artifacts are written to `storage/logs/ci/booking-write-contract-<UTC>.json`.
CI note: `write-contract-api` artifacts are written to `storage/logs/ci/api-openapi-write-contract-<UTC>.json`.
Local note: when running Deptrac directly on newer host PHP runtimes (for example PHP 8.5), vendor-level deprecation output can appear; prefer the dockerized command above for CI-parity output.

For doc-only/meta commits in constrained environments:

```bash
SKIP_PRECOMMIT=1 git commit -m "Your message"
```

## Upstream

This is a maintained fork of:

-   [alextselegidis/easyappointments](https://github.com/alextselegidis/easyappointments)

Upstream merges are done selectively and scheduled according to release risk.

## License

Code licensed under [GPL v3.0](https://www.gnu.org/licenses/gpl-3.0.en.html).
Upstream project content is published under [CC BY 3.0](https://creativecommons.org/licenses/by/3.0/); keep attribution notices when redistributing modified content/docs.
See [LICENSE](LICENSE) and [NOTICE](NOTICE) for details.
