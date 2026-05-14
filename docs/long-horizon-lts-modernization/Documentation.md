# Documentation: LTS Readiness and Rebuild Migration

## Current Status

Status: Milestone 1 in progress; Composer and npm safe updates complete.

Created: 2026-05-14.

Current milestone: Milestone 1, Runtime and Dependency Modernization.

Next action: evaluate Node 24 compatibility and decide whether to move Docker/tooling from Node 20 in this milestone.

## Locked Decisions

| Date | Decision | Reason |
| --- | --- | --- |
| 2026-05-14 | Use fresh-server rebuild + migration as the default production path. | Easier to test, easier to roll back, avoids carrying forward unknown server drift. |
| 2026-05-14 | Keep the current production server stable until final cutover. | It remains the rollback path while the new server is built and validated. |
| 2026-05-14 | Keep MariaDB 10.11 for this project. | Current production already uses MariaDB 10.11 LTS; switching to MySQL would be a separate migration. |
| 2026-05-14 | Treat PHP 8.3 as production compatibility, PHP 8.4 as primary dev/CI target, and PHP 8.5 as future compatibility preview. | Production is Ubuntu 24.04/PHP 8.3; repo Docker already uses PHP 8.4; Ubuntu 26.04 moves the future target forward. |
| 2026-05-14 | Move JS/tooling readiness toward Node 24 LTS. | Production Node 20 is EOL; Node 24 is the longer-lived LTS target. |
| 2026-05-14 | Use artifact-based deployment as the preferred deployment model. | Existing release gates and artifact validation are valuable; deployment should become clearer, not ad hoc. |
| 2026-05-14 | Mirror Uptime Kuma desired state in repo, but keep Kuma DB and secrets host-local. | Monitoring must be reproducible without committing operational state or credentials. |

## Baseline Facts

Repository:

- Runtime declaration: `php >=8.3.6`.
- Root Node declaration: `node >=20.19.0`.
- Docker PHP image: `php:8.4.18-fpm-bookworm`.
- Existing release gates include zero-surprise replay, artifact validation, deep health, PDF checks, and live canary.
- Existing observability docs define Uptime Kuma as outside-in and push-monitor coverage.

Production server:

- Host: `booking-server`.
- OS: Ubuntu 24.04.4 LTS.
- Apache: 2.4.58.
- PHP: 8.3.6 via `php8.3-fpm`.
- MariaDB: 10.11.14.
- Node: 20.20.2 from NodeSource `node_20.x`.
- Composer: 2.7.1.
- App path: `/var/www/html/easyappointments`.
- Deployment shape: app directory is not a Git checkout.

Uptime Kuma:

- Container: `uptime-kuma`.
- Image: `louislam/uptime-kuma:2.3.2`.
- Port binding: `127.0.0.1:3001 -> 3001`.
- Data mount: Docker volume to `/app/data`.
- Production state includes `kuma.db` and must remain outside Git.
- Repo has app push monitor scripts in `scripts/ops`.

## Milestone Status

| Milestone | Status | Evidence |
| --- | --- | --- |
| 0. Baseline and Safety Inventory | Complete | Refreshed Composer/npm outdated data and read-only production runtime/Kuma baseline on 2026-05-14. |
| 1. Runtime and Dependency Modernization | In progress | Composer and npm safe update sets applied and validated; Node 24 compatibility pending. |
| 2. Deployment Model Clarification | Not started | Existing release-gate docs inspected. |
| 3. Fresh Server Rebuild Runbook | Not started | Strategy chosen, no runbook yet. |
| 4. Database Migration Rehearsal | Not started | DB is assumed dump-migratable; rehearsal pending. |
| 5. Uptime Kuma Mirror and Restore | Not started | Current Kuma shape known; repo mirror pending. |
| 6. End-to-End Cutover Rehearsal | Not started | Requires prior milestones. |
| 7. Final Cutover and Post-Cutover Documentation | Not started | Requires rehearsal success. |

## Validation Log

- 2026-05-14T18:27:48Z - Milestone 0 - Refreshed dependency and production baseline.
  Validation: `composer outdated --direct --locked --format=json` passed; `npm outdated --json --include=dev` in root, `pdf-renderer`, and `tools/symphony` returned expected outdated exit code with parseable JSON; read-only SSH baseline confirmed Ubuntu 24.04.4 LTS, PHP 8.3.6 FPM, Apache 2.4.58, MariaDB 10.11.14, Node 20.20.2, Composer 2.7.1, app path `/var/www/html/easyappointments`, not a Git checkout, and healthy `louislam/uptime-kuma:2.3.2` bound to `127.0.0.1:3001`.
  Decision: milestone baseline accepted; production remains read-only.
  Next: start Milestone 1 with Composer patch/minor updates.

- 2026-05-14T18:37:33Z - Milestone 1 - Applied Composer safe update set.
  Validation: `composer update deptrac/deptrac google/apiclient phpstan/phpstan roave/security-advisories sentry/sentry --with-dependencies` passed with no advisories; `composer deptrac:analyze` passed; host `composer phpstan:application` passed with sandbox escalation; `bash ./scripts/ci/pre_pr_quick.sh` passed in the repo's isolated Docker CI stack. A direct host `composer test` and a direct non-isolated `docker compose run --rm php-fpm composer test` were intentionally not accepted as evidence because the host could not resolve `mysql` and the default local MySQL volume was dirty.
  Decision: keep this as the minimal Composer production-safe update block; defer `phpunit/phpunit` and Symfony major-line moves to separate, explicit steps.
  Next: update and validate the small npm lockfile set for root, `pdf-renderer`, and `tools/symphony`.

- 2026-05-14T18:45:12Z - Milestone 1 - Applied npm safe update set and transitive audit lockfile fixes.
  Validation: root `npm run build`, `npm run lint:js`, and `npm audit --audit-level=moderate` passed; `pdf-renderer` `npm ci --ignore-scripts`, `node --check server.js`, and `npm audit --audit-level=moderate` passed; `tools/symphony` `npm run build`, `npm test`, and `npm audit --audit-level=moderate` passed. The first root build failed in the sandbox because the archive step could not resolve `api.github.com` or write Composer cache; the escalated rerun passed.
  Decision: keep npm changes lockfile-only; include transitive audit fixes because they do not require manifest changes and remove all current npm audit findings in the three package roots.
  Next: evaluate Node 24 compatibility and whether moving Docker/runtime docs from Node 20 to Node 24 is safe in this milestone.

## Known Risks and Follow-Ups

- PHP 8.5 compatibility is not proven.
- Node 24 compatibility is not proven.
- Production deployment is not currently Git-based; artifact deployment must be documented precisely.
- Kuma restore must preserve either full history or an explicitly accepted reduced state.
- Secrets and push URLs must not leak through docs, command output, or commits.
- Ubuntu 26.04 production migration should wait for a stable LTS-to-LTS upgrade window or fresh-server image process.

## Status Update Protocol

Append a short entry here after each milestone step:

```text
YYYY-MM-DDTHH:MM:SSZ - Milestone N - Summary
Validation: command(s) and result
Decision: any new decision, or "none"
Next: next concrete action
```
