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

You will need modify the root `config.php` so that it matches the following example:

```php 
class Config {
    // ------------------------------------------------------------------------
    // GENERAL SETTINGS
    // ------------------------------------------------------------------------
    
    const BASE_URL      = 'http://localhost'; 
    const LANGUAGE      = 'english';
    const DEBUG_MODE    = TRUE;

    // ------------------------------------------------------------------------
    // DATABASE SETTINGS
    // ------------------------------------------------------------------------
    
    const DB_HOST       = 'mysql';
    const DB_NAME       = 'easyappointments';
    const DB_USERNAME   = 'user';
    const DB_PASSWORD   = 'password';

    // ------------------------------------------------------------------------
    // GOOGLE CALENDAR SYNC
    // ------------------------------------------------------------------------
    
    const GOOGLE_SYNC_FEATURE   = FALSE; // You can optionally enable the Google Sync feature. 
    const GOOGLE_CLIENT_ID      = '';
    const GOOGLE_CLIENT_SECRET  = '';
}
```

In the host machine the server is accessible from `http://localhost` and the database from `localhost:3306`.
The development stack pins MySQL `8.0` in `docker-compose.yml` for CI parity, while application migrations remain compatible with MySQL `5.7+`.

You can additionally access phpMyAdmin from `http://localhost:8080` (credentials are `root` / `secret`) and Mailpit from `http://localhost:8025`.

## Running Tests

Use the Docker Compose PHP service as the canonical test environment:

```bash
docker compose run --rm php-fpm composer test
```

The `composer test` script auto-creates `config.php` from `config-sample.php` when missing, so fresh checkouts can run tests without a manual copy step.

Alternative command in the same container context:

```bash
docker compose run --rm php-fpm sh -lc 'APP_ENV=testing php vendor/bin/phpunit'
```

Inside the Compose network, `DB_HOST='mysql'` resolves through Docker DNS to the `mysql` service.
When running PHP directly on the host, MySQL is reachable via `localhost:3306`, but only if your
`config.php` uses a host-resolvable DB host (for example `127.0.0.1` or `localhost`).

Warning: Running host-side `composer test` while `DB_HOST='mysql'` is configured will fail with a
`php_network_getaddresses: getaddrinfo for mysql failed` error.

The headless Chrome sidecar that renders PDFs is exposed via the `pdf-renderer` service (`http://localhost:3003`). When you run the PHP stack outside of Docker, make sure the application can reach the sidecar by setting the environment variable `PDF_RENDERER_URL=http://localhost:3003`; inside the Compose network the default `http://pdf-renderer:3000` endpoint is used automatically. HTML debug dumps for dashboard PDF exports are disabled by default and can be enabled temporarily with `PDF_RENDERER_DEBUG_DUMP=true`.

Baikal, a self-hosted CalDAV server used to develop the CalDAV syncing integration is available on `http://localhost:8100` (credentials are `admin` / `admin`). 

While activating CalDAV sync with the local Docker-based Baikal, you will need to first create a new Baikal user and then the credentials you defined along with the http://baikal/dav.php URL

Openldap is configured to run through `openldap` container and ports `389` and `636`. 

Phpldapadmin, an admin portal for openldap is available on `http://localhost:8200` (credentials are `cn=admin,dc=example,dc=org` / `admin`).

The deterministic local LDAP fixture now comes from the versioned seed files in `docker/ldap/seed`. Recreate and verify the directory with:

```bash
bash ./scripts/ldap/reset_directory.sh
bash ./scripts/ldap/smoke.sh
```

## Restoring a Server Dump Locally

Use this workflow when you want your local setup to run with a database dump from production/staging.

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

# Import the dump file.
gunzip -c easyappointments_YYYY-MM-DD_HHMMSSZ.sql.gz | docker compose exec -T mysql mysql -uroot -psecret

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
