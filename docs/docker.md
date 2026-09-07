# Docker

Run the development containers of Easy!Appointments with Docker and Docker Compose utility. Docker allows you to compose your application in microservices, so that you can easily get started with the local development.

Simply clone the project and run `docker compose up` to start the environment.

If you work with multiple git worktrees of the same repository, use a unique
Compose project name per worktree to avoid mixed stacks.

Examples:

```bash
docker compose -p fh-main up -d
docker compose -p fh-hotfix up -d
```

Without a unique project name, services can accidentally mix mounts across
worktrees (for example `nginx` from one path and `php-fpm`/`mysql` from another).

## PHP extension scope

The development/test image omits the unused PHP extensions `odbc` and `soap`,
along with the unused PECL extensions `csv`, `event`, `inotify` and `redis`.
Application code and Composer requirements do not use them; the active database
configuration uses MySQLi, while CodeIgniter's optional upstream ODBC adapters
remain available in `system/` for installations that explicitly need them.
Sessions and application caches use files. Normal PHP CSV functions remain
available, and Symfony's PHP event dispatcher does not require the PECL `event`
extension. Add extensions only for an actual application or test requirement.

## Shared PHP images for local checks

The local pre-PR scripts and managed commit hook keep separate Compose project
names, networks, containers, worktree mounts and MySQL data directories. Their
normal Compose v2 portless setup shares only the PHP image.

The helper derives its local image name from the PHP build directory contents,
resolved build arguments and target platform. Identical inputs in different
worktrees select the same image. Changes to the Dockerfile, extensions, build
arguments or build-context files select another image; a missing image is
built with Docker's normal layer cache. Application files outside the build
context do not require a new image because the worktree is mounted at runtime.

Custom service images, unsupported build options, Compose v1 and non-portless
setups retain the previous project-scoped behavior. Direct `docker compose`
commands and production image workflows are unchanged.

For an intentional refresh of upstream packages without a recipe change:

```bash
source scripts/ci/docker_compose_helpers.sh
ci_docker_compose build --pull --no-cache php-fpm
```

Ad-hoc `build --build-arg` or `--ssh` flags use a project-scoped image. Set build
arguments in a Compose override to include them in the shared identity. Remote package updates
are not detected automatically. Normal cleanup still removes only the local
project's containers and test data; images are retained for reuse.

## Local configuration

Keep the root `config.php` local. If it is missing, the worktree setup,
PHP-FPM container, and Composer test scripts create it from
[`config-sample.php`](../config-sample.php); they do not overwrite an existing configuration. Keep
local credentials and other secrets out of version control.

In the host machine the server is accessible from `http://localhost` and the database from `localhost:3306`.
The development stack pins MySQL `8.4.8` in `docker-compose.yml` for CI parity, while application migrations remain compatible with MySQL `5.7+`.

You can additionally access phpMyAdmin from `http://localhost:8080` (credentials are `root` / `secret`) and Mailpit from `http://localhost:8025`.

## Running Tests

Use the Docker Compose PHP service as the canonical test environment:

```bash
docker compose run --rm php-fpm composer test
```

Alternative command in the same container context:

```bash
docker compose run --rm php-fpm sh -lc 'APP_ENV=testing php vendor/bin/phpunit'
```

Inside the Compose network, `DB_HOST='mysql'` resolves through Docker DNS to the `mysql` service.
When running PHP directly on the host, MySQL is reachable via `localhost:3306`, but only if your
`config.php` uses a host-resolvable DB host (for example `127.0.0.1` or `localhost`).

Warning: Running host-side `composer test` while `DB_HOST='mysql'` is configured will fail with a
`php_network_getaddresses: getaddrinfo for mysql failed` error.

### GitHub integration runtime

GitHub's `deep-runtime-suite` runs PHP and the browser directly on the disposable
runner. MySQL and, when requested, OpenLDAP remain Docker services. A loopback-only
PHP test server with four workers serves the existing HTTP and browser checks;
the job no longer builds the full PHP development image or starts nginx.
Frontend dependencies and generated assets are prepared only when
`integration_smoke` requests the browser check. Installation uses
`npm ci --ignore-scripts`; one explicit `npm run build` then prepares
vendor files, application JavaScript and styles. API and controller suites alone
need no frontend build.
The job retains the same suites, LDAP checks and browser evidence. Server logs
are included in the suite artifact, and cleanup stops the test server and services.

This checks application behavior, not nginx/FastCGI configuration. The full local
pre-PR gate continues to exercise the Docker PHP-FPM/nginx stack. This CI setup
is not a production serving configuration.

### Linux root/host tests

The explicit test commands above include server-operation tests. On Docker
Desktop, missing host-only prerequisites (Docker access, POSIX ownership or
Linux capabilities) produce a specific skip before any mutation. Do not add
host Docker access merely to eliminate a skip. An available but unsafe
resource or a failed test remains a failure.

GitHub's independent blocking `root-deployment-tests` job runs
[`scripts/ci/run_root_deployment_regressions.sh`](../scripts/ci/run_root_deployment_regressions.sh)
with `FH_ROOT_HOST_TESTS_REQUIRED=1`: missing prerequisites fail there. A local
skip does not replace that CI check. Keep the test list in that script and the
prerequisite checks in
[`RootHostTestPrerequisites.php`](../tests/Support/RootHostTestPrerequisites.php).
The Linux-root script belongs on the disposable CI host, never production.
For job preparation and the split from general tests, see
[CI test execution](ci-test-execution.md#independent-general-and-root-tests).

For focused local diagnosis:

```bash
docker compose run --rm --no-deps php-fpm \
  php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php \
  tests/Unit/Scripts/RootHostTestPrerequisitesTest.php
```

## PHP 8.5 Preview Smoke

The normal development image stays on the PHP version pinned in
`docker/php-fpm/Dockerfile`. To test future PHP 8.5 compatibility without
changing that default, use the preview override with a unique Compose project
and temporary MySQL data directory:

```bash
COMPOSE_PROJECT_NAME=fh-php85-smoke \
EA_MYSQL_DATA_PATH=/private/tmp/fh-php85-smoke-mysql \
docker compose \
  -f docker-compose.yml \
  -f docker/compose.ci-local.yml \
  -f docker/compose.php85-smoke.yml \
  build php-fpm
```

Runtime and platform checks:

```bash
COMPOSE_PROJECT_NAME=fh-php85-smoke \
EA_MYSQL_DATA_PATH=/private/tmp/fh-php85-smoke-mysql \
docker compose \
  -f docker-compose.yml \
  -f docker/compose.ci-local.yml \
  -f docker/compose.php85-smoke.yml \
  run --rm --no-deps php-fpm php -v

COMPOSE_PROJECT_NAME=fh-php85-smoke \
EA_MYSQL_DATA_PATH=/private/tmp/fh-php85-smoke-mysql \
docker compose \
  -f docker-compose.yml \
  -f docker/compose.ci-local.yml \
  -f docker/compose.php85-smoke.yml \
  run --rm --no-deps php-fpm composer check-platform-reqs
```

For PHPUnit or other DB-backed checks, start and install the isolated test DB
first:

```bash
COMPOSE_PROJECT_NAME=fh-php85-smoke \
EA_MYSQL_DATA_PATH=/private/tmp/fh-php85-smoke-mysql \
docker compose \
  -f docker-compose.yml \
  -f docker/compose.ci-local.yml \
  -f docker/compose.php85-smoke.yml \
  up -d mysql

COMPOSE_PROJECT_NAME=fh-php85-smoke \
EA_MYSQL_DATA_PATH=/private/tmp/fh-php85-smoke-mysql \
docker compose \
  -f docker-compose.yml \
  -f docker/compose.ci-local.yml \
  -f docker/compose.php85-smoke.yml \
  run --rm php-fpm php index.php console install

COMPOSE_PROJECT_NAME=fh-php85-smoke \
EA_MYSQL_DATA_PATH=/private/tmp/fh-php85-smoke-mysql \
docker compose \
  -f docker-compose.yml \
  -f docker/compose.ci-local.yml \
  -f docker/compose.php85-smoke.yml \
  run --rm -e APP_ENV=testing php-fpm composer test
```

Clean up after the smoke:

```bash
COMPOSE_PROJECT_NAME=fh-php85-smoke \
EA_MYSQL_DATA_PATH=/private/tmp/fh-php85-smoke-mysql \
docker compose \
  -f docker-compose.yml \
  -f docker/compose.ci-local.yml \
  -f docker/compose.php85-smoke.yml \
  down --remove-orphans

rm -rf /private/tmp/fh-php85-smoke-mysql
```

The headless Chrome sidecar that renders PDFs is exposed via the `pdf-renderer` service (`http://localhost:3003`). When you run the PHP stack outside of Docker, make sure the application can reach the sidecar by setting the runtime environment variable `PDF_RENDERER_URL=http://127.0.0.1:3003`; inside the Compose network the default `http://pdf-renderer:3000` endpoint is used automatically. If the request path runs through Apache `mod_php`, set `PDF_RENDERER_URL` in Apache as well, because PHP-FPM-only env wiring will not reach those requests. HTML debug dumps for dashboard PDF exports are disabled by default and can be enabled temporarily with `PDF_RENDERER_DEBUG_DUMP=true`.


The renderer image uses `node:24-bookworm-slim` and installs only the Chrome
revision selected by `pdf-renderer/package-lock.json`. Automatic Puppeteer
browser downloads are disabled during `npm ci`; the explicit Chrome install
also installs its Debian runtime dependencies. The build retains the configured
DejaVu, Liberation and Noto fonts, discards package caches, and runs as the
unprivileged `node` user with `PUPPETEER_CACHE_DIR=/home/node/.cache/puppeteer`.
Update the lockfile to update Puppeteer and its matching browser together.

Chromium remains the rendering engine because the existing exports use CSS
Grid/Flexbox and JavaScript-assisted shared logos. A switch to a non-browser
engine would require template and output validation beyond an image cleanup.
The booking confirmation PDF uses its separate client-side export path.
Validate renderer changes with `docker compose exec -T pdf-renderer npm test`
and representative application exports; check fonts, pagination, and landscape
output as well as successful HTTP responses. Repository changes do not replace
the production renderer until a separately approved rebuild/deployment.

Baikal, a self-hosted CalDAV server used to develop the CalDAV syncing integration is available on `http://localhost:8100` (credentials are `admin` / `admin`). 

While activating CalDAV sync with the local Docker-based Baikal, you will need to first create a new Baikal user and then the credentials you defined along with the http://baikal/dav.php URL

Openldap is configured to run through the `openldap` container and ports `389` and `636`.

The default Docker stack no longer bundles phpLDAPadmin. Use the deterministic LDAP reset/smoke helpers and standard
LDAP clients against the local fixture instead.

The deterministic local LDAP fixture is versioned in the repository. Recreate and verify the directory with:

```bash
bash ./scripts/ldap/reset_directory.sh
bash ./scripts/ldap/smoke.sh
```

## Restoring a Server Dump Locally

Use this workflow when you want your local setup to run with a database dump from production/staging.

Recommended path for the current production host:

```bash
bash ./scripts/import_prod_backup.sh
```

The script will:

- create a fresh backup on `root@188.245.244.123`
- download the dump plus metadata to `/tmp`
- create a safety archive of the current local `docker/mysql` directory
- reset the local MySQL data directory
- import the production dump into the local `easyappointments` database
- run `php index.php console migrate`
- start the remaining Docker services again without pulling new images

Useful options:

```bash
# reuse an existing remote backup directory
bash ./scripts/import_prod_backup.sh \
  --remote-backup-dir /root/backups/easyappointments/20260313T074134Z

# leave only mysql/php-fpm/nginx running after the import
bash ./scripts/import_prod_backup.sh --core-services-only
```

Manual fallback:

```bash
cd /path/to/forscherhaus-appointments

# Stop the current stack.
docker compose down

# Optional safety backup of the current local MySQL data directory.
backup_tgz="/tmp/forscherhaus-mysql-$(date +%Y%m%d-%H%M%S).tgz"
tar -czf "$backup_tgz" -C docker mysql

# Clean reset local MySQL data (destructive for local DB state).
mkdir -p docker/mysql
find docker/mysql -mindepth 1 -maxdepth 1 -exec rm -rf {} +

# Start the required services.
docker compose up -d mysql php-fpm nginx

# Wait until MySQL is ready.
until docker compose exec -T mysql mysqladmin ping -h localhost -uroot -psecret --silent; do sleep 2; done

# Recreate the target DB and import the dump file.
docker compose exec -T mysql mysql -uroot -psecret -e "
DROP DATABASE IF EXISTS easyappointments;
CREATE DATABASE easyappointments CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
"
gunzip -c easyappointments_YYYY-MM-DD_HHMMSSZ.sql.gz | docker compose exec -T mysql mysql -uroot -psecret easyappointments

# Run migrations to bring schema/settings to current code level.
docker compose exec -T php-fpm php index.php console migrate
```

Verify after import:

```bash
docker compose exec -T mysql mysql -uroot -psecret -e "
USE easyappointments;
SELECT version FROM ea_migrations;
SHOW COLUMNS FROM ea_users LIKE 'class_size_default';
SELECT name, value FROM ea_settings WHERE name='dashboard_conflict_threshold';
"
```

**Attention:** This configuration is meant to make development easier. It is not intended to server as a production environment!

A production image of Easy!Appointments can be found at: https://github.com/alextselegidis/easyappointments-docker

*This document applies to Easy!Appointments v1.5.2.*

[Back](readme.md)
