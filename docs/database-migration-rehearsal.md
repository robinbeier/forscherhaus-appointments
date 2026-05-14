# Database Migration Rehearsal

This document records how to rehearse a production database dump restore without
changing the production server.

The rehearsal target is MariaDB 10.11 because production currently runs
MariaDB 10.11. The normal local development stack can continue to use its
default database image.

## Safety Rules

- Use an existing production dump unless an operator explicitly approves
  creating a new dump.
- Do not print row contents, secrets, tokens, or application configuration.
- Use a unique Compose project name and a temporary data directory.
- Keep host ports disabled so the rehearsal cannot collide with a running local
  development stack.
- Delete the local dump copy and temporary database directory after validation
  unless further investigation needs them.

## Compose Stack

Use the MariaDB restore override together with the portless local CI override:

```bash
COMPOSE_PROJECT_NAME=fh-restore-test \
EA_MYSQL_DATA_PATH=/private/tmp/fh-restore-test-mysql \
docker compose \
  -f docker-compose.yml \
  -f docker/compose.mariadb-restore.yml \
  -f docker/compose.ci-local.yml \
  up -d mysql php-fpm nginx
```

Check readiness:

```bash
COMPOSE_PROJECT_NAME=fh-restore-test \
EA_MYSQL_DATA_PATH=/private/tmp/fh-restore-test-mysql \
docker compose \
  -f docker-compose.yml \
  -f docker/compose.mariadb-restore.yml \
  -f docker/compose.ci-local.yml \
  exec -T mysql mariadb-admin ping -h localhost -uroot -psecret --silent
```

## Restore Procedure

Copy an approved dump to a local temporary path and verify the archive:

```bash
gzip -t /private/tmp/mariadb-backup-YYYYMMDDTHHMMSSZ.sql.gz
gzip -l /private/tmp/mariadb-backup-YYYYMMDDTHHMMSSZ.sql.gz
```

Import it into the isolated database:

```bash
gunzip -c /private/tmp/mariadb-backup-YYYYMMDDTHHMMSSZ.sql.gz \
  | COMPOSE_PROJECT_NAME=fh-restore-test \
    EA_MYSQL_DATA_PATH=/private/tmp/fh-restore-test-mysql \
    docker compose \
      -f docker-compose.yml \
      -f docker/compose.mariadb-restore.yml \
      -f docker/compose.ci-local.yml \
      exec -T mysql mariadb -uroot -psecret easyappointments
```

Run application migrations:

```bash
COMPOSE_PROJECT_NAME=fh-restore-test \
EA_MYSQL_DATA_PATH=/private/tmp/fh-restore-test-mysql \
docker compose \
  -f docker-compose.yml \
  -f docker/compose.mariadb-restore.yml \
  -f docker/compose.ci-local.yml \
  exec -T php-fpm php index.php console migrate
```

Run non-sensitive validation queries:

```bash
COMPOSE_PROJECT_NAME=fh-restore-test \
EA_MYSQL_DATA_PATH=/private/tmp/fh-restore-test-mysql \
docker compose \
  -f docker-compose.yml \
  -f docker/compose.mariadb-restore.yml \
  -f docker/compose.ci-local.yml \
  exec -T mysql mariadb -uroot -psecret easyappointments \
  -e "SELECT COUNT(*) AS tables_count FROM information_schema.tables WHERE table_schema='easyappointments'; SELECT COUNT(*) AS settings_count FROM ea_settings; SELECT COUNT(*) AS users_count FROM ea_users; SELECT COUNT(*) AS appointments_count FROM ea_appointments; SELECT version FROM ea_migrations ORDER BY version DESC LIMIT 1;"
```

Run an HTTP boot smoke from inside the nginx container:

```bash
COMPOSE_PROJECT_NAME=fh-restore-test \
EA_MYSQL_DATA_PATH=/private/tmp/fh-restore-test-mysql \
docker compose \
  -f docker-compose.yml \
  -f docker/compose.mariadb-restore.yml \
  -f docker/compose.ci-local.yml \
  exec -T nginx wget -S -O /dev/null http://127.0.0.1/
```

## 2026-05-14 Rehearsal Result

Source dump:

- remote path: `/root/mariadb-backup-20260319T203328Z.sql.gz`
- copied locally to: `/private/tmp/mariadb-backup-20260319T203328Z.sql.gz`
- compressed size: 153943 bytes
- uncompressed size: 418401 bytes
- archive validation: `gzip -t` passed

Validation:

- MariaDB 10.11 restore stack started with host ports disabled.
- Dump import into `easyappointments` passed.
- `php index.php console migrate` passed.
- Non-sensitive counts after migration:
  - tables: `13`
  - settings: `73`
  - users: `379`
  - appointments: `569`
  - latest migration version: `68`
- nginx HTTP boot smoke returned `HTTP/1.1 200 OK`.

Decision:

- Existing production dumps are sufficient for migration rehearsal.
- The final cutover still needs a fresh, approved dump from the old server or a
  clearly accepted recent dump.

## Cleanup

Stop the stack:

```bash
COMPOSE_PROJECT_NAME=fh-restore-test \
EA_MYSQL_DATA_PATH=/private/tmp/fh-restore-test-mysql \
docker compose \
  -f docker-compose.yml \
  -f docker/compose.mariadb-restore.yml \
  -f docker/compose.ci-local.yml \
  down --remove-orphans
```

Then remove local temporary files:

```bash
rm -rf /private/tmp/fh-restore-test-mysql
rm -f /private/tmp/mariadb-backup-YYYYMMDDTHHMMSSZ.sql.gz
```
