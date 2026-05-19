# Plan: LTS Readiness and Rebuild Migration

## Operating Rule

Complete one milestone at a time. After each milestone, run its validations, repair failures immediately, and update `Documentation.md` before moving on.

## Execution Path Update

As of 2026-05-15, the practical migration path is a same-server rebuild on the
existing Hetzner server rather than a second parallel target host. The selected
path is documented in `docs/same-server-rebuild-runbook.md`:

- create a provider snapshot first
- create and locally secure a fresh DB dump and host-config backup before wipe
- reinstall the same server with Ubuntu 26.04 LTS, with Ubuntu 24.04 LTS kept
  as fallback if 26.04 has blocking host/runtime issues
- keep the same public IP
- restore the app through the artifact deployment path
- restore Uptime Kuma from the secured archive
- use provider snapshot restore as migration-level rollback

The parallel fresh-server milestones below remain useful as a model, but DNS
cutover and old-server rollback only apply if a second target server is later
introduced.

## Milestone 0: Baseline and Safety Inventory

Goal: Freeze current repo, production, and monitoring facts before changing anything.

Deliverables:

- Current dependency inventory for Composer, root npm, `pdf-renderer`, and `tools/symphony`.
- Current production package/runtime inventory.
- Current deployment shape documented: app path, not-a-Git-checkout status, Apache/PHP-FPM/PDF renderer assumptions.
- Current Uptime Kuma shape documented: image, volume, port binding, known monitors, push scripts.
- Secret-handling rule documented for all later milestones.

Validations:

- `git status --short --branch`
- `composer outdated --direct --locked --format=json`
- `npm outdated --json --include=dev` in root, `pdf-renderer`, and `tools/symphony`
- read-only SSH checks only; no environment dumps or secret file contents
- `Documentation.md` updated with baseline snapshot

Stop-and-fix:

- If production facts conflict with repo docs, document the conflict and resolve the source of truth before dependency changes.

## Milestone 1: Runtime and Dependency Modernization

Goal: Bring dependencies to safe current versions while preserving PHP 8.3 production compatibility.

Deliverables:

- Composer patch/minor update set applied and validated.
- Optional `phpunit` update handled separately because it affects test behavior.
- npm patch/minor updates applied separately for root, `pdf-renderer`, and `tools/symphony`.
- Node 24 compatibility tested for all JavaScript surfaces.
- PHP 8.4 compatibility established for primary dev/CI path.
- PHP 8.5 compatibility smoke performed or blocker list created.

Initial Composer candidates:

- `deptrac/deptrac` 4.6.0 -> 4.6.1
- `google/apiclient` 2.19.0 -> 2.19.3
- `phpstan/phpstan` 2.1.40 -> 2.1.54
- `roave/security-advisories` dev-master 4c336cf -> 16706d8
- `sentry/sentry` 4.21.0 -> 4.27.0
- optional separate step: `phpunit/phpunit` 13.0.5 -> 13.1.9

Initial npm candidates:

- root: `chartjs-chart-matrix` 3.0.0 -> 3.0.4, `jspdf` 4.2.0 -> 4.2.1, `moment-timezone` 0.6.0 -> 0.6.2
- `pdf-renderer`: `puppeteer` 24.39.0 -> 24.43.1
- `tools/symphony`: `graphql` 16.13.1 -> 16.14.0, `yaml` 2.8.2 -> 2.9.0

Validations:

- `docker compose run --rm php-fpm composer test`
- `docker compose run --rm php-fpm composer deptrac:analyze`
- relevant PHPStan scripts from `composer.json`
- root npm build/lint path
- `pdf-renderer` install/start/health smoke
- `tools/symphony` test/build
- `bash ./scripts/ci/pre_pr_quick.sh`

Stop-and-fix:

- If any validation fails, repair or roll back that narrow dependency group before moving on.
- Do not widen to Symfony runtime major upgrades unless the failure proves they are required.

## Milestone 2: Deployment Model Clarification

Goal: Make the existing release-gate deployment path understandable and reproducible.

Deliverables:

- Deployment runbook for artifact-based releases.
- Clear separation of repo artifact, host-local config, database migration, and service restart.
- Explicit rollback behavior using previous release and old server.
- Documented maintenance window order for Uptime Kuma.

Required deployment flow:

1. Build release artifact.
2. Validate artifact.
3. Transfer artifact to target host.
4. Prepare stage directory.
5. Inject host-local config and secrets.
6. Run zero-surprise predeploy replay against dump.
7. Atomic switch.
8. Restart/probe PDF renderer.
9. Probe deep health.
10. Run zero-surprise live canary.
11. Resume Uptime Kuma.

Validations:

- `./build_release.sh`
- `php scripts/release-gate/validate_release_artifact.php --archive=...`
- zero-surprise replay dry run against test dump
- documentation review against `docs/release-gate-zero-surprise.md`

Stop-and-fix:

- If the documented flow differs from `deploy_ea.sh`, reconcile docs and implementation before continuing.

## Milestone 3: Fresh Server Rebuild Runbook

Goal: Describe a reproducible new Ubuntu LTS server setup without mutating production.

Deliverables:

- New-server setup runbook for Ubuntu LTS.
- Required packages and services.
- PHP-FPM/Apache/PDF renderer configuration outline.
- MariaDB setup and restore assumptions, including version drift from the old
  MariaDB 10.11 host when the selected LTS ships a newer line.
- Node 24 setup for build/tooling where needed.
- Secret file layout, using templates only.
- Preflight checklist for disk, RAM, DNS, TLS, firewall, backups.

Validations:

- Runbook can be followed on a staging VM or dry-run reviewed against current production.
- Required PHP extensions match `composer.lock` platform requirements.
- Apache/PHP-FPM assumptions match health and release-gate paths.
- No secrets or production-only values committed.

Stop-and-fix:

- If any production-only behavior cannot be reproduced from docs and templates, add the missing interface or template before moving on.

## Milestone 4: Database Migration Rehearsal

Goal: Prove that app data can move by dump and still satisfy release gates.

Deliverables:

- DB dump command/runbook.
- Restore command/runbook.
- Migration command/runbook.
- Validation report from imported dump.
- Rollback notes.

Validations:

- Dump created from production or representative production backup.
- Dump imported into isolated environment.
- CodeIgniter migrations run.
- Admin login smoke passes.
- Booking flow smoke passes.
- Dashboard smoke passes.
- PDF export smoke passes.
- zero-surprise replay passes against restored data.

Stop-and-fix:

- If dump restore or migration fails, do not proceed to server migration work. Fix migration assumptions first.

## Milestone 5: Uptime Kuma Mirror and Restore

Goal: Make Kuma rebuildable and migratable while keeping state and secrets out of Git.

Deliverables:

- Kuma Compose template or runbook pinned to the chosen image line.
- Monitor catalog as desired state.
- Push monitor env template with variable names only.
- Host cron/systemd template without real push URLs.
- Backup and restore runbook for `/app/data`.
- Restore validation checklist.

Required boundaries:

- Commit templates and monitor catalog.
- Do not commit `kuma.db`.
- Do not commit push URLs or notification credentials.
- Do not dump root crontab with real values into repo.

Validations:

- Kuma starts from the repo template on a test host.
- Restored `/app/data` shows the expected monitor set.
- Push monitor scripts run with an env file.
- App/host/ops monitors become green after restore.
- Notification behavior is checked without exposing credentials in logs.

Stop-and-fix:

- If history preservation is unreliable, document whether history is required or whether monitor configuration-only restore is acceptable before final cutover.

## Milestone 6: End-to-End Cutover Rehearsal

Goal: Prove the whole migration before production traffic returns.

Deliverables:

- Rebuilt server or fresh target built from the selected runbook.
- Target host probe confirms the selected Ubuntu LTS version, package
  candidates, resources, and package sources before restore work starts.
- App deployed from release artifact.
- DB restored and migrated.
- Kuma restored or intentionally kept separate.
- Release gates and monitors green.
- Rollback checklist verified: provider snapshot restore for same-server
  rebuild, old-server rollback for parallel migration.
- Final cutover checklist prepared.

Validations:

- full pre-PR gate before release artifact
- release artifact validation
- zero-surprise predeploy replay
- renderer health
- deep health
- zero-surprise live canary
- Kuma monitor parity check
- manual operator smoke: homepage, login, booking, dashboard, PDF export

Stop-and-fix:

- Do not switch DNS/traffic until all validations are green or a documented, user-approved exception exists.

## Milestone 7: Final Cutover and Post-Cutover Documentation

Goal: accept the rebuilt or new server only after rehearsal success.

Deliverables:

- Final DB dump from the pre-wipe server.
- Final restore on the rebuilt or new server.
- Final release artifact deployed.
- DNS/traffic cutover if a second target server is used; otherwise same-IP
  service acceptance.
- Post-cutover gates and Kuma checks.
- Provider snapshot retained for the agreed observation window on the
  same-server path, or old server retained if a parallel target is used.
- `Documentation.md` updated with shipped state and remaining follow-ups.

Validations:

- App health and deep health green.
- zero-surprise live canary green.
- Kuma monitors green.
- No new critical Sentry/application errors.
- Rollback decision point documented.

Stop-and-fix:

- If post-cutover canary fails, restore the provider snapshot on the
  same-server path, or roll back to the old server or previous release
  according to the documented parallel-server rollback path.
