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

# optional API OpenAPI contract smoke command (requires mysql/php-fpm/nginx stack)
docker compose exec -T php-fpm composer contract-test:api-openapi -- \
  --base-url=http://nginx --index-page=index.php --openapi-spec=/var/www/html/openapi.yml \
  --username=administrator --password=administrator

# optional booking controller flow tests (register/reschedule/cancel; mutation-safe in ephemeral DB)
docker compose exec -T php-fpm composer test:booking-controller-flows

# optional typed request-dto checks (phpstan + focused unit suite + adoption guard)
docker compose run --rm php-fpm composer phpstan:request-dto
docker compose run --rm php-fpm composer test:request-dto
docker compose run --rm php-fpm php scripts/ci/check_request_dto_adoption.php

# optional unit coverage + coverage delta gate
docker compose run --rm php-fpm composer test:coverage:unit
docker compose run --rm php-fpm composer check:coverage:delta
```

## Release Gates (Optional, Pre-Deploy)

```bash
# Dashboard gate
composer release:gate:dashboard -- --help

# Booking confirmation PDF gate
composer release:gate:booking-confirmation-pdf -- --help
```

References:

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
composer deptrac:analyze
composer check:codeowners-sync
composer check:component-boundaries
composer check:architecture-boundaries
docker compose run --rm php-fpm composer phpstan:request-dto
docker compose run --rm php-fpm composer test:request-dto
docker compose run --rm php-fpm php scripts/ci/check_request_dto_adoption.php
```

CI note: pull requests to `main` run both `build-test` and `integration-smoke`, and the integration smoke check is blocking.
CI note: `integration-smoke` now covers auth + dashboard metrics + booking read endpoints + API auth/read endpoints (read-only).
CI note: the `api-contract-openapi` check validates selected API v1 endpoints against `openapi.yml` and is blocking.
CI note: the `booking-controller-flows` check validates booking register/reschedule/cancel controller flows and is blocking.
CI note: the `typed-request-dto` check validates scoped DTO normalization adoption and is blocking.
CI note: the `phpstan-application` check is blocking.
CI note: the `js-lint-changed` check is blocking.
CI note: the `architecture-ownership-map` check is blocking.
CI note: the `architecture-boundaries` check validates CODEOWNERS drift + Deptrac changed-file layer violations + component loader boundaries and is currently warn-only during rollout (planned blocking switch after 7 consecutive green PR runs).
CI note: the `coverage-delta` check validates Unit-suite Clover line coverage against the in-repo baseline/delta policy and is currently warn-only during rollout (planned blocking switch after 7 consecutive green PR runs).
CI note: `coverage-delta` artifacts are written to `storage/logs/ci/coverage-unit-clover.xml` and `storage/logs/ci/coverage-delta-latest.json`.
CI note: `architecture-boundaries` artifacts are written to `storage/logs/ci/deptrac-changed-gate.json`, `storage/logs/ci/deptrac-github-actions.log`, and `storage/logs/ci/component-boundary-latest.json`.

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
See [LICENSE](LICENSE) for details.
