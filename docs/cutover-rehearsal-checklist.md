# Cutover Rehearsal Checklist

Purpose: turn the fresh-server rebuild, data restore, artifact deployment,
Uptime Kuma continuity, validation gates, DNS decision, and rollback path into a
single ordered rehearsal plan.

This checklist is for rehearsal planning and execution on a fresh target host.
It does not replace the detailed runbooks it references.

## Non-Goals

- Do not mutate the current production server while drafting this checklist.
- Do not switch production DNS during a rehearsal unless a real cutover has been
  explicitly approved.
- Do not commit, paste, attach, or print secret values, Push URLs, `config.php`,
  database contents, Kuma database contents, or release archive contents.
- Do not use `deploy_ea.sh` rollback as the migration-level rollback plan. Use
  it only for a failed artifact deployment on the same target host.

## Source Runbooks

- Fresh target provisioning, DB/Kuma migration choices, DNS, and rollback:
  [server-rebuild-runbook.md](server-rebuild-runbook.md)
- Artifact build, upload, deploy, local archive handling, and host-local secret
  paths: [deployment.md](deployment.md)
- Zero-surprise predeploy replay, live canary, breakglass, reports, and deploy
  exit codes: [release-gate-zero-surprise.md](release-gate-zero-surprise.md)
- Release-gate and Uptime Kuma runtime ownership:
  [observability.md](observability.md)
- Uptime Kuma template, live export, restore rehearsal, and Push secret rules:
  [uptime-kuma.md](uptime-kuma.md)
- DB restore rehearsal shape and latest restored-data validation:
  [database-migration-rehearsal.md](database-migration-rehearsal.md)
- Long-horizon milestone scope and status:
  [long-horizon-lts-modernization/Plan.md](long-horizon-lts-modernization/Plan.md)
  and
  [long-horizon-lts-modernization/Documentation.md](long-horizon-lts-modernization/Documentation.md)

## Required Inputs

Record paths, checksums, and decisions. Do not record secret values.

| Input | Required Evidence |
| --- | --- |
| Target host | Hostname, IP, OS image, resource class, SSH access owner |
| Rehearsal domain or local validation route | DNS name or `/etc/hosts` route, TTL, rollback plan |
| Release artifact | Release ID, archive path, SHA256, artifact validation result |
| Database dump | Source path, timestamp, SHA256 or `gzip -t` result, approval status |
| Application config | Host-local path and permission only |
| Health token | `/etc/fh/healthz.token` path and permission only |
| Zero-surprise predeploy credentials | `/etc/fh/zero-surprise-predeploy.ini` path and permission only |
| Zero-surprise canary credentials | `/etc/fh/zero-surprise-canary.ini` path and permission only |
| Incident webhook config | `/etc/fh/zero-surprise-incident-webhook.ini` path and permission only, if used |
| Release-gate admin env | `/etc/fh/release-gate-admin.env` path and permission only |
| Kuma state path | Secure archive path and checksum, or explicit template-rebuild decision |
| Kuma Push env | Host-local env path and permission only |
| Rollback owner | Named operator and rollback decision deadline |
| Observation window | Start time, minimum duration, acceptance criteria |

## Phase 0: Preconditions

- Confirm the current production server remains unchanged and available as
  migration-level rollback.
- Confirm the target host is disposable or approved for rehearsal.
- Confirm host-local secrets are available from secure operator storage by file
  path and permission, not by copied values in documentation.
- Confirm the accepted database dump and Kuma archive are not stored in Git.
- Confirm the planned release artifact was built and validated locally according
  to [deployment.md](deployment.md).
- Confirm Uptime Kuma maintenance is not needed for a rehearsal unless the
  rehearsal uses real monitor endpoints.

Stop if any required input is missing or only exists as an undocumented manual
assumption.

## Phase 1: Fresh Target Server

Use [server-rebuild-runbook.md](server-rebuild-runbook.md) as the provisioning
source of truth.

Checklist:

- Create the target host from the agreed Ubuntu LTS base.
- Install OS updates, reboot if needed, and record the final kernel.
- Install Apache, PHP-FPM and extensions, MariaDB, Node 24 LTS, Composer,
  Docker, certbot, fail2ban, and unattended upgrades.
- Enable required Apache modules.
- Create `/etc/fh` with restrictive permissions.
- Create `/root/releases`.
- Install and enable the PDF renderer service.
- Configure temporary validation routing without moving production DNS.

Validation:

- Record OS, PHP, MariaDB, Node, Composer, Docker, Apache, and PDF renderer
  versions.
- Confirm the server can serve a temporary validation endpoint.
- Confirm no production DNS points at the target host yet.

Stop if provisioning requires hidden manual fixes not captured in
[server-rebuild-runbook.md](server-rebuild-runbook.md).

## Phase 2: Data Restore

Use [database-migration-rehearsal.md](database-migration-rehearsal.md) for the
restore validation shape.

Checklist:

- Copy or mount the approved DB dump to the target host.
- Verify dump integrity before import.
- Restore into the target MariaDB instance.
- Run CodeIgniter migrations.
- Verify non-sensitive row counts and migration version.
- Run restored-data app smokes and release gates before any traffic switch.

Validation:

- `gzip -t <dump>.sql.gz`
- `php index.php console migrate`
- non-sensitive row counts
- restored-data app smokes
- dashboard release gate
- booking confirmation PDF gate
- zero-surprise replay against restored data

Stop if import, migrations, app boot, or any restored-data gate fails.

## Phase 3: Release Artifact

Use [deployment.md](deployment.md) for artifact handling.

Checklist:

- Build the release artifact from a clean checkout using Node 24 LTS.
- Validate the local archive.
- Record release ID, SHA256, byte size, and validation status.
- Upload the archive only to the target rehearsal host, not production.
- On the target host, validate the uploaded archive before deploy.
- Confirm the host `deploy_ea.sh` byte-matches the release copy before switch.

Validation:

- `./build_release.sh --rel <REL> --project "$PWD" --skip-upload`
- `php scripts/release-gate/validate_release_artifact.php --archive=<archive>`
- target-host archive checksum matches local SHA256
- target-host extracted artifact validation passes

Stop if archive validation fails, upload checksum differs, generated assets
drift, or deploy script drift is detected.

## Phase 4: Uptime Kuma

Use [uptime-kuma.md](uptime-kuma.md) as the source of truth.

Choose one path before the rehearsal starts:

- full data migration from the secure Kuma archive, preserving monitor history
  and Push tokens
- template rebuild from repo monitor definitions, intentionally accepting loss
  of history and requiring host-local Push secrets
- separate Kuma continuation, intentionally leaving the existing Kuma instance
  outside the rehearsal

Checklist:

- Record the chosen path.
- If restoring the archive, verify checksum before restore.
- Start Kuma from the documented Compose template or restored data.
- Validate monitor count, Push monitor count, and monitor parity.
- Keep Push URLs and tokens in host-local env files only.

Validation:

- Kuma container healthy
- HTTP smoke to dashboard route
- SQLite integrity check if restoring data
- monitor parity against repo template
- Push monitors green after host-local test pings, when applicable

Stop if Kuma state cannot be restored or intentionally re-created with known
monitor coverage before DNS cutover.

## Phase 5: Pre-DNS Go/No-Go

Before switching DNS or traffic, all of these must be true:

- Old production remains reachable and unchanged.
- Target host provisioning evidence is complete.
- DB restore, migrations, app boot, and restored-data gates are green.
- Release artifact deploy prechecks are green.
- Zero-surprise predeploy replay is green.
- PDF renderer health is green.
- Deep health is green.
- Zero-surprise live canary is green against the target route.
- Kuma is restored, green, or explicitly paused with an approved reason.
- Rollback owner and decision deadline are named.
- DNS TTL and rollback propagation expectations are recorded.

Go only if every item is green or there is a documented, user-approved
exception. Otherwise stop and fix before DNS.

## Phase 6: Cutover Rehearsal

For a rehearsal, prefer a rehearsal domain or controlled local routing. Do not
move production DNS unless this is the approved final cutover.

Checklist:

- Lower DNS TTL ahead of time if a real DNS rehearsal is approved.
- Put Kuma into maintenance only when monitoring real endpoint movement.
- Take or select the final approved DB dump.
- Restore the DB on the target host.
- Deploy the accepted release artifact through `deploy_ea.sh`.
- Switch the rehearsal route or DNS target.
- Watch app health, release gate reports, server logs, and Kuma.

Stop before route or DNS switch if any pre-DNS go/no-go item fails.

## Phase 7: Post-Cutover Validation

Use the deploy-layer order from [observability.md](observability.md):

1. zero-surprise predeploy replay against a fresh dump
2. atomic switch
3. renderer health
4. deep health
5. zero-surprise live canary
6. resume Uptime Kuma monitors

Manual operator smoke:

- homepage loads
- admin login works
- booking path works
- dashboard loads
- PDF export works
- Kuma monitors are green or intentionally paused

Stop and roll back if post-switch deep health, live canary, core manual smoke,
or required Kuma checks fail.

## Phase 8: Rollback Drill

Rollback decision points:

- Before DNS switch: keep DNS on the old server and fix the target host.
- During artifact deploy on the same target host: use `deploy_ea.sh` automatic
  rollback semantics and record exit code `30` or `31`.
- After DNS switch: revert DNS to the old production server while the old server
  remains intact.
- After final acceptance: keep the old server for the agreed observation window
  before decommissioning.

Validation:

- rollback owner confirms the chosen rollback path
- DNS rollback path is tested or time-boxed
- old production health is verified before and after rehearsal
- failed target release path is preserved only if needed for investigation and
  does not contain secrets in shared artifacts

## Evidence To Record

- target host baseline and package versions
- release ID, archive SHA256, and artifact validation result
- DB dump timestamp, integrity result, migration version, and non-sensitive row
  counts
- release-gate report paths and pass/fail status
- Kuma path chosen, checksum/parity result, and monitor status
- DNS TTL, cutover time, and rollback deadline
- manual operator smoke result
- final decision: accept, retry, rollback, or pause

## Stop Conditions

- A secret value would need to be copied into documentation or chat.
- Production DNS would change without explicit final-cutover approval.
- The target host requires undocumented manual fixes.
- DB restore, migrations, app boot, artifact validation, predeploy replay,
  renderer health, deep health, live canary, or required Kuma checks fail.
- Old production is not healthy enough to serve as rollback.
- The rollback owner or rollback deadline is unclear.
