# Documentation: LTS Readiness and Rebuild Migration

## Current Status

Status: Milestone 6 same-server restore is accepted through ROB-369 and post-acceptance temporary restore artifacts have been removed. The rebuilt Ubuntu 26.04 host serves the app, restored DB, containerized PDF renderer, TLS, and restored Uptime Kuma without host-level Node.js.

Created: 2026-05-14.

Current milestone: Milestone 7 post-rebuild documentation and cleanup.

Next action: keep the provider snapshot according to the operator-managed retention decision and continue normal post-rebuild observation.

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
| 2026-05-15 | Use same-server rebuild on the existing Hetzner server as the practical execution path. | A second server is unlikely; production traffic is not expected before August 2026; the provider snapshot gives the rollback path while keeping the same IP. |
| 2026-05-15 | Target Ubuntu 24.04 LTS for the same-server rebuild. | It matches the current supported LTS base and avoids combining the host rebuild with an Ubuntu 26.04 migration. |
| 2026-05-15 | Create a provider snapshot, fresh DB dump, and local host-config backup directly before wiping the server. | The old live server will be reinstalled in place, so rollback and restore evidence must exist before the destructive step. |
| 2026-05-18 | Proceed with rebuild preparation using the completed provider snapshot and verified pre-wipe backup set. | The destructive step now has a provider rollback point plus local DB/config/inventory restore inputs. |
| 2026-05-18 | Switch the selected reinstall target from Ubuntu 24.04 LTS to Ubuntu 26.04 LTS, with 24.04 retained as fallback. | Hetzner now offers 26.04, the install is fresh rather than an in-place upgrade, and production traffic is not expected before October 2026. |

## Baseline Facts

Repository:

- Runtime declaration: `php >=8.3.6`.
- Root Node declaration: `node >=24.0.0`.
- Docker PHP image: `php:8.4.18-fpm-bookworm`.
- Existing release gates include zero-surprise replay, artifact validation, deep health, PDF checks, and live canary.
- PHP 8.5 preview smoke path is available via
  `docker/compose.php85-smoke.yml`.
- Existing observability docs define Uptime Kuma as outside-in and push-monitor coverage.

Production server:

- Host: `booking-server`.
- Pre-wipe OS: Ubuntu 24.04.4 LTS.
- Pre-wipe Apache: 2.4.58.
- Pre-wipe PHP: 8.3.6 via `php8.3-fpm`.
- Pre-wipe MariaDB: 10.11.14.
- Pre-wipe Node: 20.20.2 from NodeSource `node_20.x`.
- Pre-wipe Composer: 2.7.1.
- Pre-wipe app path: `/var/www/html/easyappointments`.
- Pre-wipe deployment shape: app directory was not a Git checkout.

Rebuilt target host:

- Host: `booking-server`.
- OS: Ubuntu 26.04 LTS (`resolute`).
- Apache: 2.4.66.
- PHP: 8.5.4 via `php8.5-fpm`.
- MariaDB: 11.8.6.
- Composer: 2.9.5.
- Docker: 29.1.3 with Compose plugin 2.40.3.
- certbot: 4.0.0.
- Swap: 2G `/swapfile`.
- Host-local secret path prepared: `/etc/fh` (`0700`, `root:root`).
- Release staging path prepared: `/root/releases` (`0755`, `root:root`).
- Node/npm: intentionally not installed on the rebuilt host while deployment
  remains artifact-based and the PDF renderer runs as a container. Ubuntu 26.04
  currently offers `nodejs` 22.22.1 and `npm` 9.2.0; NodeSource Node 24 is not
  used to avoid a persistent third-party Apt repository.
- Active app release: `ea_rebuild_20260518_182248`.
- App URL: `https://dasforscherhaus-leg.de/`.
- Monitor URL: `https://monitor.dasforscherhaus-leg.de/`.
- PDF renderer: Docker-backed `fh-pdf-renderer` systemd service, bound to
  `127.0.0.1:3003`.
- Uptime Kuma: Docker Compose project `uptime-kuma`, bound to
  `127.0.0.1:3001`.

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
| 1. Runtime and Dependency Modernization | Complete | Composer/npm safe update sets applied; Node 24 target validated locally and in Docker. |
| 2. Deployment Model Clarification | Complete | Added `docs/deployment.md` and linked it from `README.md`. |
| 3. Fresh Server Rebuild Runbook | Complete | Added `docs/server-rebuild-runbook.md` from read-only production inventory. |
| 4. Database Migration Rehearsal | Complete | Restored existing production MariaDB dump into isolated MariaDB 10.11 stack, ran migrations, checked row counts, confirmed HTTP 200 boot smoke, then completed ROB-358 restored-data app smokes and release gates. |
| 5. Uptime Kuma Mirror and Restore | Complete | Production monitor desired state captured without Push tokens; repo templates and missing Host/Ops Push scripts added; current 12-monitor live export restored locally, matched repo template, all 12 monitors went green in the disposable instance, and the verified current export was copied to secure operator-controlled storage outside Git. |
| 6. End-to-End Cutover Rehearsal | Complete | Cutover checklist, old-server rollback drill, and same-server rebuild runbook exist. Provider snapshot was created by the operator; pre-wipe DB/config/inventory backup was created and verified locally. Same-server reinstall runs Ubuntu 26.04 LTS; app, DB, artifact deploy, containerized PDF renderer, TLS, Kuma restore, Push scripts, and all 12 active Kuma monitors passed final acceptance. |
| 7. Final Cutover and Post-Cutover Documentation | In progress | Same-server rebuild is accepted with provider snapshot retained. Temporary restore artifacts were removed after explicit operator approval. Remaining work is normal post-rebuild observation and any future operations handoff notes. |

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

- 2026-05-14T18:50:48Z - Milestone 1 - Moved development, CI, and Docker Node target to Node 24.
  Validation: official Node.js release/EOL pages confirm Node 20 is EOL and Node 24 is the current LTS line; root `npm run build`, `npm run lint:js`, and `npm audit --audit-level=moderate` passed; `tools/symphony` `npm run build`, `npm test`, and `npm audit --audit-level=moderate` passed; `docker compose build php-fpm` passed; `docker compose run --rm php-fpm node --version` returned `v24.15.0`.
  Decision: require Node `>=24.0.0` for repo-local tooling and use NodeSource `setup_24.x` in the PHP-FPM development image; production remains untouched until the rebuild/migration phase.
  Next: commit the Node 24 target change and run `bash ./scripts/ci/pre_pr_quick.sh` from the clean commit.

- 2026-05-14T18:53:18Z - Milestone 1 - Completed full quick-gate validation on the clean Node 24 commit.
  Validation: `bash ./scripts/ci/pre_pr_quick.sh` passed after rebuilding the local CI `php-fpm` image with NodeSource `setup_24.x`, refreshing frontend assets, installing the isolated MySQL fixture, running PHPUnit, PHPStan application, typed request DTO checks, and architecture ownership checks.
  Decision: Milestone 1 is complete.
  Next: begin Milestone 2 deployment model clarification.

- 2026-05-14T18:54:16Z - Milestone 2 - Documented artifact-based deployment as the preferred model.
  Validation: inspected `build_release.sh`, `deploy_ea.sh`, and existing release-gate docs; added `docs/deployment.md`; linked it from `README.md`; no production commands were run.
  Decision: keep artifact deployment as the stable path instead of switching production to a Git checkout; clarify the artifact -> stage -> predeploy -> switch -> postdeploy -> rollback flow.
  Next: begin fresh-server rebuild runbook.

- 2026-05-14T18:56:25Z - Milestone 3 - Drafted fresh-server rebuild runbook.
  Validation: read-only SSH inventory captured host, OS, resources, running services, package versions, Apache vhosts/modules, Docker/Kuma shape, app/release/secret paths, and PDF renderer unit metadata without printing secret contents; added `docs/server-rebuild-runbook.md`; linked it from `README.md` and `docs/deployment.md`.
  Decision: rebuild target keeps artifact deployment, MariaDB 10.11, Node 24 for tooling/PDF renderer, and old server rollback until cutover acceptance.
  Next: rehearse database restore from an existing production dump.

- 2026-05-14T19:01:21Z - Milestone 4 - Rehearsed database restore from an existing production dump.
  Validation: copied `/root/mariadb-backup-20260319T203328Z.sql.gz` from production, verified it with `gzip -t`, imported it into an isolated MariaDB 10.11 Docker stack with host ports disabled, ran `php index.php console migrate`, confirmed non-sensitive row counts (`13` tables, `73` settings, `379` users, `569` appointments, migration version `68`), and got `HTTP/1.1 200 OK` from nginx inside the restore stack.
  Decision: existing production dumps are usable for rehearsal; final cutover still needs a fresh approved dump or an explicitly accepted recent dump.
  Next: mirror and rehearse Uptime Kuma desired state, backup, and restore.

- 2026-05-14T19:05:00Z - Milestone 5 - Mirrored Uptime Kuma desired state into the repo.
  Validation: read-only production inspection captured Uptime Kuma image, bind address, data volume, 12 active monitor definitions with Push tokens redacted, cron script basenames/schedules only, and historical backup archive locations; added repo templates for Kuma Compose, desired monitors, Push env, crontab, and missing Host/Ops Push scripts.
  Decision: Push monitor secrets remain host-local env values only; the repository mirrors monitor intent and scripts, not the live Kuma database.
  Next: restore an approved Kuma backup into a disposable Kuma instance and verify monitor parity plus green Push monitors.

- 2026-05-14T19:10:31Z - Milestone 5 - Tested Uptime Kuma restore mechanics from an existing historical backup.
  Validation: copied `/root/backups/uptime-kuma/uptime-kuma-data-pre-2.2.1-20260310T152414Z.tar.gz` and its `.sha256` file, verified checksum match, extracted into `/private/tmp/fh-kuma-restore-test-data`, started Kuma 2.3.2 from `docker/compose.uptime-kuma.yml` on local port `13001`, confirmed the container was healthy, confirmed HTTP returned `302 Found` to `/dashboard`, and queried restored monitor metadata.
  Decision: the existing historical backup proves the restore path but is stale; it contains the older 7-monitor set, while current production has 12 active monitors. Full monitor parity requires a fresh approved backup or explicitly accepted current data export.
  Next: obtain an approved current Kuma backup, restore it into the disposable target, and verify all 12 monitors plus Push green status.

- 2026-05-15T05:57:31Z - Milestone 5 - Created current Uptime Kuma live export outside the repository.
  Validation: streamed a live SQLite `.backup` plus Kuma data files from the production container to `/private/tmp/uptime-kuma-live-export-20260515T055731Z`, generated a local SHA256 file, verified the archive checksum, extracted it locally, confirmed `PRAGMA integrity_check` returned `ok`, and confirmed the exported database contains `12` monitors and `8` Push monitors.
  Decision: current live export is available locally but contains secrets and monitor history; it must stay out of Git.
  Next: restore this current export into a disposable Kuma instance and confirm full monitor parity plus Push green status.

- 2026-05-15T06:13:29Z - Milestone 5 - Restored current Uptime Kuma live export and validated parity.
  Validation: restored the current export into `/private/tmp/fh-kuma-live-restore-test-data`, started Kuma 2.3.2 on local port `13002`, confirmed healthy container status and `HTTP/1.1 302 Found` to `/dashboard`, confirmed `PRAGMA integrity_check` returned `ok`, verified `12` monitors and `8` Push monitors, diffed restored monitor metadata against `scripts/ops/uptime-kuma.monitors.yml` with no differences for ID/name/type/interval/retry/max-retries, generated a temporary host-local Push env file outside Git, sent green test pings for all 8 Push monitors, and confirmed latest heartbeat status was green for all 12 monitors.
  Decision: full Kuma data migration is the preferred path for the rebuild; final cutover needs a fresh live export close to the migration window, and Push secrets remain host-local.
  Next: start end-to-end cutover rehearsal.

- 2026-05-15T08:40:24Z - Milestone 4 follow-up / ROB-358 - Validated app behavior against restored production data.
  Validation: copied existing dump `/root/backups/easyappointments/20260515T021701Z/db/easyappointments.sql.gz`, verified `gzip -t`, imported into isolated MariaDB 10.11 stack `fh-rob-358`, ran migrations, seeded a local-only smoke admin, confirmed non-sensitive counts (`13` tables, `73` settings, `454` users, `708` appointments, migration version `68`), passed non-LDAP dashboard/app smoke `11/11`, booking write contract `6/6`, dashboard release gate `8/8`, booking confirmation PDF gate `6/6`, and zero-surprise replay with `3` steps and `4` invariants.
  Decision: restored-data validation is no longer limited to import and HTTP boot; final cutover still needs the same gates rerun against the accepted cutover dump.
  Next: start end-to-end cutover rehearsal.

- 2026-05-15T09:04:15Z - Milestone 1 follow-up / ROB-359 - Proved PHP 8.5 compatibility smoke path.
  Validation: added a default-preserving `PHP_FPM_BASE_IMAGE` build arg and `docker/compose.php85-smoke.yml`; built isolated Compose project `fh-rob-359` from official `php:8.5-fpm-bookworm` digest `sha256:c5589e9861eb95593c211d6d8e988280e911eeccd2ea496937f4cc3f148533d8`; confirmed `PHP 8.5.6`, Composer `2.9.8`, Node `v24.15.0`, required extensions including GD/LDAP/MySQL/Redis/Event/Inotify, and `composer check-platform-reqs` success. `composer phpstan:application`, `composer deptrac:analyze`, focused PHPUnit buffer tests, `tests/Unit/Helper/DonutHelperTest.php` under PHP 8.5 and PHP 8.4, and full PHP 8.5 `composer test` passed after removing deprecated no-op `imagedestroy()` calls from the donut helper.
  Decision: PHP 8.5 compatibility is proven for the repo's Docker smoke path; this is preview evidence only and does not change the current production PHP 8.3 or primary dev PHP 8.4 runtime policy.
  Next: keep PHP 8.5 in the future-compatibility lane until a fresh target server path needs it.

- 2026-05-15T09:19:08Z - Milestone 5 follow-up / ROB-360 - Secured the current Uptime Kuma live export outside temporary local storage.
  Validation: copied the archive, checksum, and redacted manifest to `/Users/robinbeier/Documents/forscherhaus-ops-secure/uptime-kuma/20260515T055731Z`; set the archive path to `0700` directories and `0600` files; verified destination SHA256 `b29f85f61cd4e2bdf15d707fd04e4518acf70f36dae5b08f7b64e6951907b9c4`; confirmed source and destination archive sizes both reported `58621910` bytes; no Push URLs, tokens, or Kuma DB files were committed.
  Decision: the secure local archive is the retained reference for rebuild rehearsal work; final cutover still needs a fresh live export close to the migration window.
  Next: start end-to-end cutover rehearsal.

- 2026-05-15T09:30:09Z - Milestone 5 follow-up / ROB-360 - Removed the temporary Uptime Kuma export source.
  Validation: deleted `/private/tmp/uptime-kuma-live-export-20260515T055731Z`, confirmed the path no longer exists, and verified the secure archive SHA256 still reports `b29f85f61cd4e2bdf15d707fd04e4518acf70f36dae5b08f7b64e6951907b9c4`.
  Decision: the secure operator-controlled archive is now the only retained local copy known to this run.
  Next: start end-to-end cutover rehearsal.

- 2026-05-15T09:55:03Z - Milestone 2 follow-up / ROB-361 - Hardened and validated the local artifact release path.
  Validation: enforced Node 24 LTS with `mise x node@24`, confirmed PHP `8.5.3`, Composer `2.9.5`, Node `v24.15.0`, and npm `11.12.1`; made `build_release.sh` and `deploy_ea.sh` executable in Git; ran `mise x node@24 -- ./build_release.sh --rel ea_rob361_20260515_095309 --project "$PWD" --skip-upload`; build completed with no upload, refreshed frontend assets without tracked drift, installed production Composer dependencies into the stage, validated the staged directory and archive, and explicit post-build artifact validation passed. Final local artifact evidence before cleanup: SHA256 `7f1cb8979deb11b8ab2740b643d4ed744af5107964b9f8b31d9876ac7b4e7cbd`, size `41514796` bytes.
  Decision: local release artifact build and validation are proven; local release archives and logs include staged config and should be treated as sensitive operator artifacts, so the ROB-361 `/tmp` archives and logs were deleted after evidence was recorded.
  Next: prove artifact deploy on a clean target host during the fresh-server rehearsal path.

- 2026-05-15T13:55:25Z - Milestone 6 preparation / ROB-362 - Drafted the cutover rehearsal checklist.
  Validation: added `docs/cutover-rehearsal-checklist.md`, linked it from `docs/server-rebuild-runbook.md` and `docs/readme.md`, and kept it reference-based against the existing deployment, zero-surprise, observability, Uptime Kuma, DB rehearsal, and long-horizon runbooks. The checklist lists required inputs, artifacts, host-local secret file paths, validation commands, pre-DNS go/no-go criteria, post-cutover checks, rollback decision points, and stop conditions without including secret values or live data.
  Decision: Milestone 6 now has an executable checklist, but the end-to-end rehearsal itself has not started.
  Next: provision or choose a fresh rehearsal target, then execute the checklist.

- 2026-05-15T14:21:05Z - Milestone 6 preparation / ROB-363 - Detailed the old-server rollback drill.
  Validation: added `docs/old-server-rollback-drill.md`, linked it from `docs/cutover-rehearsal-checklist.md`, `docs/server-rebuild-runbook.md`, and `docs/readme.md`, and kept the drill documentation-only. The drill distinguishes same-host `deploy_ea.sh` rollback from migration-level old-server rollback, defines project timing defaults of `10` minutes for rollback decision, `30` minutes maximum accepted public downtime, and a `7` day old-server observation window, and lists concrete DNS, DB write-safety, artifact, Kuma, operator, and evidence checks without documenting secret values or live data.
  Decision: old-server rollback is now operationally specified on paper; it still has not been exercised against a fresh target host or real DNS route.
  Next: provision or choose a fresh rehearsal target, then execute the cutover checklist and rollback drill.

- 2026-05-15T16:10:31Z - Milestone 6 preparation - Selected and documented the same-server rebuild path.
  Validation: added `docs/same-server-rebuild-runbook.md` and updated the long-horizon plan, cutover checklist, fresh-server runbook, and old-server rollback drill to distinguish the selected same-server Hetzner rebuild from the earlier parallel-target model.
  Decision: rebuild the existing Hetzner server in place from Ubuntu 24.04 LTS after provider snapshot, fresh DB dump, and local host-config backup; restore Kuma from the secured archive; keep artifact deployment; use provider snapshot restore as migration-level rollback.
  Next: create provider snapshot and pre-wipe backup artifacts, then execute the same-server rebuild runbook.

- 2026-05-15T16:22:22Z - Milestone 6 preparation - Prepared pre-wipe backup helper for the same-server rebuild.
  Validation: added `scripts/ops/prepare_same_server_rebuild_backup.sh`, linked it from `docs/same-server-rebuild-runbook.md`, confirmed `bash -n`, confirmed default dry-run prints the intended SSH target, remote staging path, local secure backup path, database, app config path, and remote cleanup behavior without writing local backup artifacts, and confirmed Markdown formatting.
  Decision: the helper stays dry-run by default and requires `--execute` after the provider snapshot is complete; it creates a fresh DB dump, host-config archive, inventory, and checksums, downloads them to secure local storage outside Git, verifies them locally, and removes the temporary remote staging directory unless `--keep-remote` is passed.
  Next: create the provider snapshot, then run the helper with `--execute` immediately before the reinstall.

- 2026-05-18T17:41:50Z - Milestone 6 / ROB-364 - Created and verified the same-server pre-wipe backup set.
  Validation: operator confirmed the provider snapshot was created; ran `bash ./scripts/ops/prepare_same_server_rebuild_backup.sh --execute`; created local backup root `/Users/robinbeier/Documents/forscherhaus-ops-secure/same-server-rebuild/20260518T174053Z`; helper and independent verification passed `gzip -t`, `tar -tzf`, and `shasum -a 256 -c meta/checksums.sha256`; local backup directories are `0700`, files are `0600`; remote staging directory `/root/rebuild-prewipe-backup-20260518T174053Z` no longer exists.
  Decision: ROB-364 is complete; the rebuild now has provider snapshot rollback plus verified local DB/config/inventory restore inputs.
  Next: start ROB-365, reinstall the same server and bootstrap the runtime.

- 2026-05-18T17:50:40Z - Milestone 6 preparation - Reframed the same-server rebuild for Ubuntu 26.04 LTS.
  Validation: official Ubuntu sources show Ubuntu 26.04 LTS is released and visible as `resolute`; Ubuntu package sources show 26.04 package candidates may move PHP and MariaDB beyond the old 24.04 baseline, so added a read-only target probe at `scripts/ops/probe_same_server_rebuild_target.sh` before restore/deploy work.
  Decision: install Ubuntu 26.04 LTS for the same-server rebuild; keep Ubuntu 24.04 LTS as fallback if the 26.04 target probe, package install, DB restore, or app gates fail before acceptance.
  Next: after provider reinstall, run the read-only target probe before bootstrapping runtime packages.

- 2026-05-18T18:05:43Z - Milestone 6 / ROB-365 - Probed and bootstrapped the rebuilt Ubuntu 26.04 host.
  Validation: operator confirmed SSH works after reinstall; `bash ./scripts/ops/probe_same_server_rebuild_target.sh --execute` passed after `apt-get update` and confirmed Ubuntu 26.04 LTS `resolute`, unchanged IPv4 `188.245.244.123/32`, 1.9 GiB RAM, 38G root disk, Hetzner Ubuntu package sources, PHP 8.5.4, MariaDB 11.8.6, Apache 2.4.66, Composer 2.9.5, Docker 29.1.3, Docker Compose 2.40.3, certbot 4.0.0, and fail2ban availability. `apt-get dist-upgrade -y` reported no pending upgrades. A 2G `/swapfile` was created and enabled. Installed Apache, PHP-FPM and required PHP extensions, MariaDB, Composer, Docker Compose plugin, certbot Apache plugin, fail2ban, cron, unattended-upgrades, and supporting tools. Enabled Apache `rewrite`, `headers`, `ssl`, `http2`, `proxy`, `proxy_http`, `proxy_wstunnel`, `proxy_fcgi`, `setenvif`, and `php8.5-fpm`; `apache2ctl configtest` returned `Syntax OK`; Apache, PHP-FPM, MariaDB, Docker, fail2ban, unattended-upgrades, and cron are active. Created `/etc/fh` as `0700 root:root` and `/root/releases` as `0755 root:root`.
  Decision: accept Ubuntu 26.04 base bootstrap for the app restore path, but keep Node 24 off the server because artifact deploy builds off-host and the PDF renderer container carries its own Node runtime. Do not add the NodeSource `setup_24.x` third-party Apt repository.
  Next: proceed with artifact deploy without server-side Node and restore app, DB, PDF renderer, and Kuma.

- 2026-05-18T18:42:22Z - Milestone 6 / ROB-366 - Restored app, database, containerized PDF renderer, TLS, and Uptime Kuma on the rebuilt host.
  Validation: copied verified DB, host-config, and Kuma archives to `/root/rebuild-restore-inputs`; verified DB and host-config SHA256 plus Kuma archive SHA256 from the retained `.sha256` file; imported the pre-wipe DB dump into MariaDB 11.8.6; created host-local app DB credentials under `/etc/fh`; restored `/etc/fh` with `0700` directories and `0600` files; built and uploaded release artifact `ea_rebuild_20260518_182248` with matching local/remote SHA256 `8c00ef9458d112ceedc18faf2bf523acabde484af8eada42ae398bb2d09ab048`; zero-surprise predeploy replay and report validation passed; configured PHP-FPM runtime env from `/etc/fh/php-fpm-runtime.env` after the first deploy attempt exposed missing `HEALTHZ_TOKEN`; manually completed the activation of the validated release; `zero_surprise_live_canary.php` passed; migration command completed; homepage, renderer health, and deep health returned HTTP 200; DB counts were `13` tables, `73` settings, `453` users, `708` appointments, migration version `68`; certbot issued and deployed TLS for `dasforscherhaus-leg.de`, `www.dasforscherhaus-leg.de`, and `monitor.dasforscherhaus-leg.de`; restored Kuma from the secured 2026-05-15 archive with SQLite integrity `ok`, `12` active monitors, and `8` Push monitors; generated host-local Push env from restored Kuma tokens; installed cron under `/etc/cron.d/fh-uptime-kuma-push`; all Push scripts passed once manually; all 12 active Kuma monitors had latest status `1`; host `node` and `npm` remained absent.
  Decision: use a Docker-backed `fh-pdf-renderer` service and `deploy_ea.sh --renderer-deploy-mode external` for this rebuilt host; add a repo-level static `/health` endpoint because the restored Kuma Shallow Health monitor expects keyword `OK` at that path; keep Push URLs host-local under `/root/backups/uptime-kuma-push.env`.
  Next: run ROB-369 final acceptance, review any residual logs, and decide provider snapshot retention.

- 2026-05-18T18:59:42Z - Milestone 6 / ROB-369 - Accepted the rebuilt same server with provider snapshot retained.
  Validation: app homepage returned HTTP `200`, `www` returned HTTP `200`, monitor endpoint returned HTTP `302`, PDF renderer health returned HTTP `200`, and deep health returned HTTP `200`; Apache, PHP 8.5 FPM, MariaDB, Docker, fail2ban, cron, unattended-upgrades, and `fh-pdf-renderer` were active; Uptime Kuma and PDF renderer containers were running, with Kuma healthy; DB counts stayed at `13` tables, `73` settings, `453` users, `708` appointments, migration version `68`; all `12` active Uptime Kuma monitors had latest status `1`; the certificate for app, `www`, and monitor domains is valid until 2026-08-16; `certbot renew --dry-run --non-interactive --no-random-sleep-on-renew` passed; host `node` and `npm` remained absent; app log scan found `0` recent error-like lines.
  Log review: recent Apache, PHP-FPM, and Docker warning counts were `0`; MariaDB and cron warnings were limited to standard unset-package-environment messages; PDF renderer warnings were historical failed starts during setup before the service became active and healthy.
  Decision: accept the rebuilt Ubuntu 26.04 same-server state with the provider snapshot retained; snapshot-retention tracking is operator-managed outside this repo. Keep temporary restore artifacts on the server until explicit cleanup approval after acceptance.
  Next: post-acceptance cleanup can remove temporary restore inputs and the placeholder deploy directory only after operator approval.

- 2026-05-18T19:04:17Z - Milestone 7 - Removed temporary post-acceptance restore artifacts.
  Validation: verified the active app path was separate from the placeholder directory; removed `/root/rebuild-restore-inputs` and `/var/www/html/easyappointments_placeholder_after_failed_deploy`; confirmed both paths are absent afterward; app homepage returned HTTP `200`, `www` returned HTTP `200`, monitor endpoint returned HTTP `302`, PDF renderer health returned HTTP `200`, and deep health returned HTTP `200`; Apache, PHP 8.5 FPM, MariaDB, Docker, fail2ban, cron, unattended-upgrades, and `fh-pdf-renderer` remained active; Uptime Kuma reported `12` active monitors and `12` green latest statuses; recent app error-like lines were `0`; recent warning counts for Apache, PHP-FPM, MariaDB, PDF renderer, Docker, and cron were `0`.
  Decision: temporary restore inputs and the failed-deploy placeholder are no longer retained on the server. Provider snapshot retention remains operator-managed outside this repo.
  Next: continue normal post-rebuild observation.

- 2026-05-20T15:03:26Z - Milestone 7 / ROB-367 - Completed post-rebuild observation after the accepted same-server rebuild.
  Validation: operator approved read-only production checks for the observation window after the rebuild; `bash scripts/ops/prod_doctor.sh` showed Ubuntu 26.04 LTS on `booking-server`, app `200`, `www` `200`, monitor route `302`, PDF renderer health `200`, deep health `200`, Apache, PHP 8.5 FPM, MariaDB, Docker, fail2ban, cron, unattended-upgrades, and `fh-pdf-renderer` active, both Kuma and PDF renderer containers up, root disk `45%` used with `20` GiB available, `947` MiB memory available, `2047` MiB swap configured, host `node` and `npm` absent, and Uptime Kuma `12/12` latest green. `bash scripts/ops/prod_logs_summary.sh --since "36 hours ago"` showed `0` Apache, PHP-FPM, MariaDB, PDF renderer, Docker, and cron warnings. A read-only Kuma heartbeat summary showed all active monitors latest green; short historical non-green events existed for `App - Log Errors`, `Ops - Jobs Freshness`, and `Security - Scanner Activity`, but current state was green. Backup and restore marker checks used only marker basenames and ages; both markers were present and below the `1440` minute freshness threshold. The observation printed no Push URLs, health-token values, DB rows, Kuma DB rows, customer data, or secret-bearing file contents.
  Decision: classify the post-rebuild state as stable with follow-ups. The accepted Ubuntu 26.04 operating model holds: PHP 8.5 FPM is the active runtime, MariaDB 11.8 remains healthy, the PDF renderer stays container-backed, Certbot/timers are active, Kuma is restored and green, and host Node remains intentionally absent. Follow-ups were created for live monitoring drift rather than mixing fixes into ROB-367: ROB-390 applies the live Kuma backup-creation vs restore-verification split, ROB-391 renames the stale live PHP-FPM monitor display from `php8.3-fpm` to `php8.5-fpm`, and ROB-392 tightens `prod_logs_summary.sh` so standalone stack/context lines are not counted as actionable app-log errors.
  Next: handle ROB-390, ROB-391, and ROB-392 as separate narrow issues; keep any Server/Kuma/Sentry write actions behind explicit approval gates.

- 2026-05-20T15:43:21Z - Milestone 7 / ROB-390 and ROB-391 - Applied the approved live Kuma maintenance gate.
  Validation: operator approved immediate SSH-based Kuma writes. A server-local Kuma SQLite backup was created before the write. `App - php8.3-fpm Log Errors` was renamed to `App - php8.5-fpm Log Errors`; `Ops - Jobs Freshness` was renamed to `Ops - Restore Verify Freshness`; `Ops - Backup Creation Freshness` was created as a separate Push monitor; the host-local Push env and cron gained the backup-creation signal; the missing split Push scripts were installed from the repository; manual restore-verify and backup-creation Push validation returned green messages with marker ages only; post-change Uptime Kuma summary showed `13` active monitors and `13` latest green. No Push URLs, token values, Kuma DB contents, backup contents, DB rows, or production data were recorded.
  Decision: the live Kuma monitor catalog now matches the repo desired-state split for backup creation versus restore verification and the PHP 8.5 FPM display name. The post-change validation gate should expect `13` active/green monitors.
  Next: keep future live Kuma changes behind explicit approval gates and keep generated Push secrets host-local only.

## Known Risks and Follow-Ups

- PHP 8.5 is installed on the real Ubuntu 26.04 target host and the rebuilt
  host has passed final acceptance. Continue treating PHP 8.5 as the active
  server runtime and keep compatibility checks in future release gates.
- The selected same-server Ubuntu 26.04 rebuild is accepted through ROB-369.
- ROB-367 post-rebuild observation classified the server as stable with
  follow-ups. Current app routes, deep health, PDF renderer health, service
  state, resources, and latest Kuma monitor state were green on 2026-05-20.
- Node 24 is validated locally and in Docker, but intentionally not installed
  on the rebuilt server while deployment remains artifact-based and the PDF
  renderer runs as a container. If server-side builds become necessary later,
  revisit the host Node source explicitly.
- Provider snapshot creation has been reported by the operator, but provider
  snapshot restore has not been tested.
- The pre-wipe backup set has been created and verified locally. It contains
  secret-bearing restore inputs and must remain outside Git, chat, Linear
  attachments, and screenshots.
- The earlier parallel-target fresh-server path remains documented, but it is
  no longer the selected execution path unless a second server is introduced.
- Local artifact build and validation pass with Node 24 LTS; the rebuilt host
  successfully runs the uploaded artifact without host-level Node.js by using
  the external/containerized PDF renderer mode.
- Database migration app smokes passed on the pre-wipe dump after restore.
- End-to-end rebuild execution has completed snapshot, pre-wipe backup,
  reinstall, target probe, base bootstrap, DB restore, artifact deploy,
  containerized PDF renderer, TLS, Kuma restore, push scripts, monitors, final
  acceptance, and log review. Provider snapshot rollback restore has still not
  been tested.
- Temporary restore inputs were removed after explicit operator approval:
  `/root/rebuild-restore-inputs` and
  `/var/www/html/easyappointments_placeholder_after_failed_deploy` are absent.
- Live Kuma monitor drift from the repo-only monitoring roadmap has been
  closed for ROB-390 and ROB-391. Production now has separate restore-verify
  and backup-creation freshness monitors plus the `php8.5-fpm` display name.
- The ROB-392 app-log counting cleanup has shipped; keep future log-noise
  changes narrow and regression-tested.
- Old-server rollback is documented for the parallel-target model, but it is not
  the selected rollback path for the same-server rebuild.
- The current Uptime Kuma live export restores successfully and preserves
  history, and a verified secure local copy now exists outside Git at the
  operator-controlled archive path. The temporary `/private/tmp` source has
  been removed.
- Secrets and push URLs must not leak through docs, command output, commits, or
  retained local test artifacts.
- Ubuntu 24.04 is no longer the active fallback path for this accepted rebuild;
  revisit it only if a future rebuild on Ubuntu 26.04 becomes untenable.

## Status Update Protocol

Append a short entry here after each milestone step:

```text
YYYY-MM-DDTHH:MM:SSZ - Milestone N - Summary
Validation: command(s) and result
Decision: any new decision, or "none"
Next: next concrete action
```
