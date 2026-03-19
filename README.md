# Easy!Appointments

Easy!Appointments is a highly customizable web application that allows your customers to book appointments with you via a sophisticated web interface. Moreover, it provides the ability to sync your data with Google Calendar so you can use them with other services.

## Setup

Read [AGENTS.md](AGENTS.md) for the compact operator contract and [docs/agent-harness-index.md](docs/agent-harness-index.md) for the routing map into topic docs.

### With Docker

```bash
docker compose up -d
```

Application: [http://localhost](http://localhost)

### Installation

```bash
docker compose run --rm php-fpm php index.php console install
```

Default credentials:

- Username: `administrator`
- Password: `administrator`

## Services

Start the full stack:

```bash
docker compose up -d
```

Core services only:

```bash
docker compose up -d mysql php-fpm nginx
```

Optional supporting services are documented in [docs/docker.md](docs/docker.md).

## Database

Dump the local database:

```bash
docker compose exec mysql sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' > dump.sql
```

Restore a dump:

```bash
docker compose exec -T mysql sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < dump.sql
```

For production-dump restore and reset workflows see [docs/docker.md](docs/docker.md).

## Validation

Fast local gate:

```bash
bash ./scripts/ci/pre_pr_quick.sh
```

Review-ready path:

```bash
docker compose run --rm php-fpm composer test
PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh
```

For scoped checks, deep runtime suites, and rollout docs see [docs/agent-harness-index.md](docs/agent-harness-index.md).

## Testing Before PR

Default review-ready path:

```bash
docker compose run --rm php-fpm composer test
PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh
```

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
