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

## 2026-05-15 ROB-358 Restored-Data App Smoke Result

Source dump:

- remote path:
  `/root/backups/easyappointments/20260515T021701Z/db/easyappointments.sql.gz`
- copied locally to:
  `/private/tmp/fh-rob-358-dumps/easyappointments-20260515T021701Z.sql.gz`
- compressed size: 144798 bytes
- uncompressed size: 465797 bytes
- SHA256:
  `a6681caca010ea779b04280c35db6881e5526c1aa5ca9a82d5fcfcbfa35c3b96`
- archive validation: `gzip -t` passed

Restore and migration:

- MariaDB 10.11 restore stack used Compose project `fh-rob-358` with host app
  ports disabled.
- Dump import into `easyappointments` passed.
- `php index.php console migrate` passed.
- A local-only gate administrator was seeded in the isolated restore database
  for smoke credentials `administrator` / `administrator`.
- Non-sensitive counts after migration:
  - tables: `13`
  - settings: `73`
  - users: `454`
  - appointments: `708`
  - latest migration version: `68`
- nginx HTTP boot smoke returned `HTTP/1.1 200 OK`.
- PDF renderer health returned `{"ok":true}`.

Validation:

- Non-LDAP dashboard/app smoke passed `11/11` checks:
  `storage/logs/ci/rob-358-dashboard-nonldap-smoke.json`.
- Booking write contract passed `6/6` checks:
  `storage/logs/ci/rob-358-booking-write-contract.json`.
- Dashboard release gate passed `8/8` checks including principal PDF, teacher
  ZIP, and teacher PDF exports:
  `storage/logs/release-gate/rob-358-dashboard-release-gate.json`.
- Booking confirmation PDF gate passed `6/6` checks with a fully linked restored
  appointment:
  `storage/logs/release-gate/rob-358-booking-confirmation-pdf.json`.
- Zero-surprise replay passed with `3` steps and `4` invariants:
  `storage/logs/release-gate/rob-358-zero-surprise.json`.

Notes:

- Local `RATE_LIMITING` was disabled only in the untracked test `config.php` to
  make repeated smoke requests deterministic.
- The dump did not expose bookable slots in the default 14-day search window, so
  restored-data booking checks used `--booking-search-days=180`.
- LDAP-specific smoke checks were not included in this restored-data app smoke;
  they require their own LDAP fixture/runtime validation.

Decision:

- The current production dump has been validated beyond import and HTTP boot:
  admin login, dashboard metrics/page readiness, booking read paths, API
  availability, booking write/cancel contracts, dashboard PDF/ZIP exports,
  booking confirmation PDF generation, and zero-surprise replay all pass against
  restored data.
- Final cutover still needs the same validation rerun against a fresh approved
  dump or an explicitly accepted recent cutover dump.

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
