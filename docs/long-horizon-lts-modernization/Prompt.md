# Prompt: LTS Readiness and Rebuild Migration

## Source Pattern

This long-horizon task follows the four-file durable memory pattern described in OpenAI's Codex article "Run long horizon tasks with Codex": a stable spec, milestone plan, implementation runbook, and live documentation log.

Reference: https://developers.openai.com/blog/run-long-horizon-tasks-with-codex

## Mission

Modernize the Forscherhaus Appointments repository and operational model so that the application can continue to run safely on the current production server, then migrate cleanly to a freshly built Ubuntu LTS server when the 24.04 -> 26.04 LTS path is operationally appropriate.

This is not a feature project. It is an LTS-readiness, deployment, migration, and observability project.

## Current State

- Repository: `forscherhaus-appointments`.
- Current production host: `root@188.245.244.123`, host name `booking-server`.
- Current production OS/runtime:
  - Ubuntu 24.04.4 LTS.
  - Apache 2.4.58.
  - PHP 8.3.6 via `php8.3-fpm`.
  - MariaDB 10.11.14.
  - Node.js 20.20.2 from NodeSource `node_20.x`.
  - Composer 2.7.1.
- Current app path on production: `/var/www/html/easyappointments`.
- Current app deployment is not a Git checkout.
- Repository runtime declarations:
  - `composer.json`: `php >=8.3.6`.
  - initial root `package.json`: `node >=20.19.0`.
  - Docker PHP dev image: `php:8.4.18-fpm-bookworm`.
- Uptime Kuma:
  - Runs as Docker container `uptime-kuma`.
  - Image: `louislam/uptime-kuma:2.3.2`.
  - Bound to `127.0.0.1:3001`.
  - Data volume mounted to `/app/data`.
  - `kuma.db` is production state and must not be committed.
  - Repo already contains app-oriented push monitor scripts under `scripts/ops`.

## Goals

1. Keep the current production server stable until final cutover.
2. Modernize dependencies with small, intentional upgrade sets.
3. Validate PHP 8.3 production compatibility, PHP 8.4 primary dev/CI compatibility, and PHP 8.5 future Ubuntu 26.04 compatibility.
4. Move JavaScript/tooling/runtime readiness toward Node 24 LTS.
5. Preserve MariaDB 10.11 as the database line for this project.
6. Make a new Ubuntu LTS server reproducible from repo-documented infrastructure.
7. Make database dump migration repeatable and validated by release gates.
8. Make deployment simpler or at least clearer, with artifact, stage, switch, canary, and rollback steps.
9. Mirror Uptime Kuma desired state in the repo without committing secrets or runtime database state.
10. Keep the old production server as rollback until the new server is fully accepted.

## Non-Goals

- Do not implement product features.
- Do not switch MariaDB to MySQL in this project.
- Do not add a PHP PPA just to chase PHP 8.4 on Ubuntu 24.04.
- Do not perform an in-place production OS upgrade as the default path.
- Do not commit `config.php`, Kuma DB files, push URLs, tokens, credentials, Apache site secrets, or root crontabs with real secret values.
- Do not broaden changes in `system/` unless applying an explicit upstream patch.

## Hard Constraints

- Production mutations require an explicit user request for that step.
- Any production SSH exploration must avoid printing secrets, push URLs, notification credentials, private keys, or full environment dumps.
- Keep changes narrow and reviewable.
- Use existing release gates instead of replacing them.
- Use CodeIgniter migrations for schema changes, including rollback.
- Treat `services.attendants_number` as fixed to `1` unless product scope changes explicitly.
- Keep host-local state out of the repo; document interfaces and templates instead.

## Deliverables

1. Runtime and dependency readiness:
   - Composer and npm updates in small verified groups.
   - PHP 8.3/8.4/8.5 compatibility status documented.
   - Node 24 readiness validated or blockers documented.
2. Rebuild infrastructure:
   - A documented fresh-server setup path for Ubuntu LTS.
   - Explicit packages, services, PHP extensions, Docker requirements, Apache/PHP-FPM assumptions, and secret file locations.
3. Database migration:
   - Dump, restore, migration, and validation runbook.
   - Zero-surprise replay against restored production dump.
4. Deployment model:
   - Artifact-based deploy path documented.
   - Stage preparation, host config injection, atomic switch, postdeploy checks, and rollback behavior described.
5. Uptime Kuma mirror:
   - Compose template or runbook for Kuma.
   - Monitor catalog.
   - Push monitor env template.
   - Backup/restore runbook for `/app/data`.
6. Live project documentation:
   - `Documentation.md` kept current after each milestone with status, decisions, validations, and shipped changes.

## Done When

This task is complete when all of the following are true:

- A fresh Ubuntu LTS server can be set up from the repo documentation without relying on hidden tribal knowledge.
- The app remains compatible with current PHP 8.3 production.
- PHP 8.4 is green for the primary dev/CI path.
- PHP 8.5 has either passed compatibility checks or has a precise blocker list.
- Node 24 works for root assets, `pdf-renderer`, and `tools/symphony`, or blockers are documented.
- App database dump migration has been rehearsed and validated.
- Existing release gates remain the deployment safety source of truth.
- Deployment is documented as artifact -> stage -> switch -> canary -> rollback.
- Kuma desired state is documented in the repo.
- Kuma secrets and database state remain outside the repo.
- Kuma backup and restore have been tested once.
- The old production server remains available as rollback until final cutover is explicitly accepted.
