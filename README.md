# Forscherhaus Appointments

Forscherhaus school scheduling fork of Easy!Appointments.

This repository prioritizes stable, low-risk delivery for school operations and keeps compatibility with the existing Easy!Appointments architecture.

## Scope

- Fork base: Easy!Appointments (`v1.5.2` lineage)
- Stack: PHP `>=8.3.6`, CodeIgniter, MySQL, jQuery/Bootstrap/FullCalendar
- Primary goal: school-specific scheduling workflows and operational reliability

## Fork Invariants

- `services.attendants_number` is intentionally restricted to `1`.
- Do not implement multi-attendant behavior unless product scope changes explicitly.

## Quickstart (Recommended)

Prerequisites on host (required by `./scripts/setup-worktree.sh`):

- PHP `>=8.3.6`
- Composer
- Node.js `>=20.19.0` plus `npm`/`npx`
- Docker + Docker Compose

```bash
./scripts/setup-worktree.sh
docker compose up -d

# when you need deterministic LDAP fixtures for search/import/SSO work
bash ./scripts/ldap/reset_directory.sh
bash ./scripts/ldap/smoke.sh
```

Smoke-check:

```bash
docker compose run --rm php-fpm composer test
npm run build
```

## Harness Guide

Need the shortest route to the right steering source?

- [Agent Harness Index](docs/agent-harness-index.md): routing across onboarding,
  agent runtime, CI, architecture, ownership, and Symphony
- [WORKFLOW.md](WORKFLOW.md): agent runtime and ticket-to-merge rules
- [AGENTS.md](AGENTS.md): compact repo guardrails plus the extended local/CI command matrix

## Core Commands

```bash
npm start

npm run build

npm run lint:js

npm run assets:refresh
docker compose run --rm php-fpm composer test
PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh
```

For optional smoke tests, write-path contracts, deep runtime suites, release
gates, and CI-only signals, use [Agent Harness Index](docs/agent-harness-index.md)
as the routing map and [AGENTS.md](AGENTS.md) as the compact command-and-guardrail hub.

## Release Gates

Primary references:

- [Zero-surprise restore-dump replay + live canary](docs/release-gate-zero-surprise.md)
- [Dashboard release gate](docs/release-gate-dashboard.md)
- [Booking confirmation PDF gate](docs/release-gate-booking-confirmation-pdf.md)
- [Agent Harness Index](docs/agent-harness-index.md)

## Local Services (Docker)

- App: `http://localhost`
- phpMyAdmin: `http://localhost:8080` (`root` / `secret`)
- Mailpit: `http://localhost:8025`
- PDF renderer: `http://localhost:3003`
- Baikal (CalDAV): `http://localhost:8100`

For deterministic LDAP fixtures, reset and smoke the local directory with:

```bash
bash ./scripts/ldap/reset_directory.sh
bash ./scripts/ldap/smoke.sh
```

If you run PHP on host with Docker PDF renderer, set:

```bash
export PDF_RENDERER_URL=http://localhost:3003
```

If the host runtime goes through Apache `mod_php`, set `PDF_RENDERER_URL` via
Apache `SetEnv` as well; PHP-FPM-only env wiring does not reach those requests.

## Worktree Safety

When using multiple git worktrees, always set a unique Docker Compose project name per worktree:

```bash
docker compose -p fh-main up -d
docker compose -p fh-hotfix up -d
```

This prevents mixed container mounts across worktrees.

## Documentation Map

- [Agent harness index](docs/agent-harness-index.md)
- [Compact guardrails and extended command matrix](AGENTS.md)
- [Write-path CI contracts](docs/ci-write-contracts.md)
- [Architecture map](docs/architecture-map.md)
- [Ownership map](docs/ownership-map.md)
- [Installation guide](docs/installation-guide.md)
- [Docker guide](docs/docker.md)
- [Observability guide](docs/observability.md)
- [Console commands](docs/console.md)
- [REST API](docs/rest-api.md)
- [Google Calendar sync](docs/google-calendar-sync.md)
- [CalDAV sync](docs/caldav-calendar-sync.md)
- [LDAP](docs/ldap.md)
- [LDAP parallel replacement spike](docs/ldap-parallel-spike.md)
- [Provider room feature](docs/feature-provider-room.md)
- [FAQ](docs/faq.md)

## Contribution Rules (Short)

- Keep production code changes inside `application/`.
- Do not modify `system/` unless applying an explicit upstream patch.
- Use CodeIgniter migrations for all DB schema changes (with rollback path).
- Keep `config.php` out of version control; update `config-sample.php` only with safe defaults.

## Testing Before PR

Default review-ready path:

```bash
docker compose run --rm php-fpm composer test
PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh
```

On cold local Docker stacks, `integration-smoke` may need extra Playwright
startup time. In that case, rerun the full gate with
`PRE_PR_INTEGRATION_SMOKE_BROWSER_BOOTSTRAP_TIMEOUT=600` and
`PRE_PR_INTEGRATION_SMOKE_BROWSER_OPEN_TIMEOUT=60`.

Use `bash ./scripts/ci/pre_pr_quick.sh` for a faster local gate. For the full
optional matrix, scope-specific smokes, and rollback notes, see
[Agent Harness Index](docs/agent-harness-index.md) and [AGENTS.md](AGENTS.md).

## Local Cleanup

Local runtime artifacts can become much larger than the actual git checkout,
especially under `storage/logs/`.

Conservative cleanup that preserves local DB data in `docker/mysql/`:

```bash
bash ./scripts/cleanup_local_artifacts.sh
```

This removes everything under `storage/logs/` except the placeholder
`.htaccess` and `index.html` files, plus `build/`, `.phpunit.cache/`, and the
local `easyappointments-0.0.0.zip` artifact. That includes local CI, release,
and ops artifacts stored under `storage/logs/`. To also remove reproducible
dependency directories such as the root installs plus
`tools/symphony/node_modules/` and `pdf-renderer/node_modules/`, opt in
explicitly only when you are fine reinstalling them afterwards:

```bash
bash ./scripts/cleanup_local_artifacts.sh --with-deps
```

Hook note: `./scripts/setup-worktree.sh` installs managed `.git/hooks/pre-commit`
and `.git/hooks/pre-push` hooks. The managed `pre-commit` keeps PHP-related
commits on the same deterministic MySQL/bootstrap path as `pre_pr_quick.sh`,
while the managed `pre-push` runs `pre_pr_quick.sh`. Use
`./scripts/install-git-hooks.sh` to refresh an existing clone, or
`FORCE_HOOK_INSTALL=1 ./scripts/install-git-hooks.sh` to replace older custom
hooks intentionally.

CI note:

- CI is changed-file gated and deep docker-compose jobs only run for relevant
  changes; on pull requests they are limited to non-draft PRs.
- `deep-runtime-suite` is the shared producer for `api-contract-openapi`,
  `write-contract-booking`, `write-contract-api`,
  `booking-controller-flows`, and `integration-smoke`.
- `integration-smoke` now captures a narrow browser evidence bundle on failure
  under `storage/logs/ci/deep-runtime-suite/integration-smoke-browser/`
  (`summary.json`, screenshot, snapshot, trace, network log).
- `phpstan-application`, `js-lint-changed`, `architecture-ownership-map`,
  `architecture-boundaries`, `typed-request-dto`,
  `typed-request-contracts`, `api-contract-openapi`,
  `write-contract-booking`, `write-contract-api`,
  `booking-controller-flows`, and `coverage-delta` are blocking.
- `heavy-job-duration-trends` and `pdf-renderer-latency` are non-blocking
  signal jobs.
- Full job wiring lives in `.github/workflows/ci.yml`; the compact local
  command map and cross-links live in [AGENTS.md](AGENTS.md); specialized
  runtime details stay in the topic docs linked from
  [Agent Harness Index](docs/agent-harness-index.md).

For doc-only/meta commits in constrained environments:

```bash
SKIP_PRECOMMIT=1 git commit -m "Your message"
```

## Upstream

This is a maintained fork of:

- [alextselegidis/easyappointments](https://github.com/alextselegidis/easyappointments)

Upstream merges are done selectively and scheduled according to release risk.

## License

Code licensed under [GPL v3.0](https://www.gnu.org/licenses/gpl-3.0.en.html).
Upstream project content is published under [CC BY 3.0](https://creativecommons.org/licenses/by/3.0/); keep attribution notices when redistributing modified content/docs.
See [LICENSE](LICENSE) and [NOTICE](NOTICE) for details.
