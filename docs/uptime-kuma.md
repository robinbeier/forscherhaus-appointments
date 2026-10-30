# Uptime Kuma Operations

This document mirrors the production Uptime Kuma desired state without storing
the live SQLite database or Push monitor secrets in the repository.

## Boundary

Repository-owned:

- container template: `docker/compose.uptime-kuma.yml`
- push scripts: `scripts/ops/kuma_push_*.sh`
- host-local env example: `scripts/ops/uptime-kuma-push.env.example`
- crontab example: `scripts/ops/uptime-kuma-crontab.example`
- immutable root runtime: `docs/ops/production-kuma-push-runtime.md`

Host-owned:

- Uptime Kuma SQLite data directory
- monitor history
- Push tokens and full Push URLs
- token header values for deep-health JSON monitors
- notification credentials
- maintenance state

## Container controls

The repository template retains the pinned 2.5.8 image and root-owned
`/app/data` compatibility while applying a read-only root filesystem, a small
`/tmp` tmpfs, `no-new-privileges`, 384 MiB memory/512 MiB memory-plus-swap,
128 PIDs, and 5 MiB/3-file JSON logs. Startup testing showed that dropping all
capabilities requires retaining only `CHOWN`, `DAC_OVERRIDE`, and `FOWNER` so
the pinned image can initialise or reuse the root-owned `/app/data` volume.
The template does not change the image user or migrate existing data.

On 2026-10-30 the pinned image passed local SQLite initialization, synthetic
administrator setup/login, and actual HTTP, JSON-query and Push monitor checks.
After a container restart, the same credentials and monitor definitions still
worked and all three emitted fresh successful heartbeats. External DNS and
verified HTTPS also passed. The final run measured a cgroup memory peak of
about 147 MiB and 26 PIDs, with no OOM events or automatic restarts. Actual
root filesystem, limits, `NoNewPrivs=1`, Seccomp=2 and the three retained
capabilities were inspected. The process remains root; this is reduced
privilege, not a migration to a non-root image.

An initial healthy response alone reached only the database setup wizard and
was not counted as a monitor proof. The read-only root prevents the image's
optional nscd startup; the DNS/HTTPS check verifies the tested fallback path.
These synthetic checks do not establish production health or every monitor
type. Current production coverage remains HTTP, JSON-query, Push, and no
ping/TCP monitors. The live
Compose file is host-owned: an authorized rollout must separately reconcile
these controls there, preserve the exact previous Compose file and data backup,
and use that pair for rollback without overwriting the newer data directory.

## Production Snapshot

The repository template's desired image is the official
`louislam/uptime-kuma:2.5.8-slim@sha256:3e0ae13fa92d0a1108b7e4133eaa25b2bc4e26d2f5d8691a5cb01ee1fe5c95f1`.
Production was verified healthy on this image on 2026-10-26, running Kuma
2.5.8 with Node 22.23.4 / module ABI 127.
A repository merge alone does not update the host-owned Compose file.

### 2.5.8 production update (2026-10-26)

[Upstream 2.5.8](https://github.com/louislam/uptime-kuma/releases/tag/2.5.8)
is a stable patch release including the admin-only RCE security correction
GHSA-v2p3-6f5h-6hhf, Docker-response validation, and removal of TLS support
from TCP Port monitors. The read-only live inventory found one HTTP, one
JSON-query, five Push and zero TCP Port monitors, including inactive ones.
Thus the TCP TLS behavior change does not affect this instance.

Production became healthy at 17:33:01 UTC (18:33:01 Europe/Berlin).
The image-only update was merged in [PR #888](https://github.com/robinbeier/forscherhaus-appointments/pull/888)
as `4bb9192f0c218a01fd844831531b0a4f3bb27a34`; the
[production evidence](https://github.com/robinbeier/forscherhaus-appointments/pull/888#issuecomment-6423254346)
records the separate live result.

Verification completed:

- A stopped full data-directory backup matched file hashes. Separate restores
  under old 2.5.7 and new 2.5.8 started with `--network none`; SQLite integrity
  passed and all 14 configuration-table digests matched, including monitor
  definitions and notification assignments. Both images reported Node 22.23.4
  / module ABI 127.
- Immediately before cutover, another consistent full backup was hash-verified
  and a verified rollback data copy was prepared on the same filesystem.
- Only the image reference changed in the host-owned Compose file. Loopback
  binding, data bind mount and restart policy stayed unchanged. SQLite integrity
  and all configuration digests also passed after the live switch and at the
  final check.
- At 17:45:32 UTC (18:45:32 Europe/Berlin), all seven active monitors had fresh
  successful regular results after the update, including all five Push monitors.
  The shared production-change lock covered the switch and this observation;
  a switch or observation failure would restore the matched old image/data pair.
- `bash scripts/ops/prod_validate_after_change.sh` passed after healthy start
  and again after all fresh signals: endpoints, deep health, services, protected
  paths and resources passed, with no new application errors.
- The full local `PRE_PR_RUN_COVERAGE=1 PRE_PR_BASE_REF=origin/main bash scripts/ci/pre_pr_full.sh`
  gate, applicable GitHub CI, and independent/code/security reviews passed for
  the update PR. These are distinct from the live checks above.

No application release, monitor definitions, notifications, cron or credentials
changed. Authenticated dashboard login was not exercised. These dated results
establish this update's outcome; future operations require fresh live checks.

#### 2.5.8 rollback path

Recovery artifacts are retained host-local under
`/root/kuma-upgrade-2.5.8-20261026`: `compose.before.yml`, `rollback-image.tar`,
`live-data-backup`, `live-backup-files.sha256.json`, `rollback-ready-data`,
`baseline.json`, `config-digest.py`, and the trial/operation evidence.
The matched rollback image is the official
`louislam/uptime-kuma:2.5.7-slim@sha256:4837e2b531b118d2fad24430fabed1488b14542f03b2bebf035497271b3a1ee8`.
Older custom 2.5.6 and 2.5.3 artifacts belong to historical upgrades below.

For an authorized rollback:

1. Obtain the shared production-change lock and verify the current image,
   host-owned Compose, retained old image and matching full backup. Confirm the
   prepared rollback copy still matches the recorded backup hashes and passes
   SQLite integrity before changing live data. Stop if artifacts or identities
   are ambiguous.
2. Stop Kuma. Preserve the newer data directory separately; never overwrite it
   or start 2.5.7 against potentially upgraded 2.5.8 data. Restore the exact
   `compose.before.yml` and the matching `rollback-ready-data` copy together.
   The prepared copy can be renamed into `/var/lib/uptime-kuma-data` on the same
   filesystem, avoiding another full copy during a disk-space failure.
3. Use the retained old image, loading `rollback-image.tar` if necessary, and
   restart the `uptime-kuma` service using its host-owned Compose/project.
   Require a healthy 2.5.7 runtime, unchanged ports/mount/restart policy, SQLite
   integrity and configuration digests matching `baseline.json`.
4. Verify the same seven active monitors and fresh successful regular results,
   including all five Push monitors, then run
   `bash scripts/ops/prod_validate_after_change.sh`. Preserve the failed-upgrade
   data and operation evidence for diagnosis.

Do not rerun the one-shot `fh-kuma-258-switch.py` to request rollback. This
procedure is a recovery plan; no live rollback was needed or exercised. The
old-image isolated restore test above is the rollback compatibility evidence.

The live Compose file is host-owned (reconfirmed at
`/opt/uptime-kuma/docker-compose.yml` on 2026-10-26) and may differ from the
repository template. Before an authorized live upgrade, recheck its location,
preserve that exact file, and change only its image reference. Do not overwrite
it with `docker/compose.uptime-kuma.yml` or allow an unset `KUMA_DATA_PATH` to switch
the live bind mount to the template's default named volume. Re-render and
verify the live port binding, data mount, restart policy, and image before
starting the new container; keep the exact prior file, old image, and data
backup together for rollback.

On 2026-09-16, the production update to the then-current `2.5.5-slim` image
completed successfully: the live instance was healthy at 19:36 UTC after the
change, 18 configuration tables were preserved, and the full-backup old-image
restore and new-image offline-upgrade checks passed. Production validation
passed at 19:37 UTC. This is historical evidence for the deployed 2.5.5
baseline and does not establish compatibility or deployment of 2.5.7.

- historical 2.5.7 desired image (now the 2.5.8 rollback target): `louislam/uptime-kuma:2.5.7-slim@sha256:4837e2b531b118d2fad24430fabed1488b14542f03b2bebf035497271b3a1ee8`
- observed live version on 2026-10-23: Kuma `2.5.7`, Node `22.23.4`, matching the desired image digest
- observed live image before the 2.5.7 upgrade: `fh-uptime-kuma:2.5.6-node22.23.4-20261022`, image ID `sha256:accceb9108d9b1fcc330da75561a5abc0a29992447fc7c657877626886cae5d9`
- historical 2026-09-16 deployed baseline: `2.5.5-slim@sha256:9c56a772a7df53f444a404c579e87a27bd0c201375d00d6745a75bf8138f4342`
- historical older fallback (not the 2.5.7 rollback target): `2.5.3-slim@sha256:7d70c3bd3127dc4ad2910f9d2d18481a6ac4f195a4e23e32f0c50869f977985a`
- listen address: `127.0.0.1:3001`
- data mount: `/var/lib/uptime-kuma-data` bind-mounted at `/app/data`
- database file: `/app/data/kuma.db` (SQLite)

The slim image omits Chromium and embedded MariaDB; this instance uses SQLite
and HTTP, keyword, JSON, and Push monitors. It does not use browser monitors.

### Node.js security runtime update

On 2026-10-22, production moved from the official 2.5.6-slim image's Node
22.22.3 to Node 22.23.4. The image used for that historical update was
`fh-uptime-kuma:2.5.6-node22.23.4-20261022`, image ID
`sha256:accceb9108d9b1fcc330da75561a5abc0a29992447fc7c657877626886cae5d9`.
Only `/usr/local/bin/node` and its version metadata changed; Kuma, native
modules, monitor definitions, notification assignments and the data mount
were preserved. Both images use Debian bookworm and Node module ABI 127.

Before switching, a consistent full data-directory backup was taken while
Kuma was stopped briefly. The saved old image and the patched image both
started successfully with isolated, network-disabled copies of that backup.
Digests over 14 configuration tables matched before/after both trials and
after the live change. All seven monitors were green after the update.
The live Compose file was changed only at its image reference; loopback
binding and `/var/lib/uptime-kuma-data` remained unchanged. The complete
production validation gate passed, followed by the application PDF gate.

The prior Compose files, images, full data backup, build recipe and trial
records remain host-local under `/root/node-security-20261022T0836`.
Keep that old image/data pair for rollback. A future application/schema
upgrade must repeat the full backup and compatibility procedure below; this
runtime-only evidence does not authorize changing monitors or notifications.

### 2.5.7 production update (2026-10-23)

At 09:12:10 UTC, production became healthy on the official pinned 2.5.7-slim
image. The former custom image only supplied the Node security correction
that upstream now includes. No local overlay is needed.

Before switching, a consistent full data-directory backup was verified against
the stopped live files. Separate copies restored successfully under both the
old custom 2.5.6 image and the official 2.5.7 image with `--network none`.
SQLite integrity checks passed, and digests over 14 configuration tables
matched before and after both trials and the live switch, including monitor
definitions and notification assignments. The target runtime was Node 22.23.4
with module ABI 127. The host-owned Compose file changed only at its image
reference; the data bind mount, loopback port and restart policy were preserved.

A second consistent full backup was verified immediately before switching.
A separate verified rollback data copy was prepared on the same filesystem,
so rollback can preserve failed-upgrade data and rename the prepared copy
into place without another full copy after a possible disk-space failure.
The production change used the shared production-change lock.

At 09:43:44 UTC, all seven active monitors had fresh successful regular
results after the switch, including all five Push monitors. The complete
`prod_validate_after_change.sh` gate subsequently passed. The full local
`PRE_PR_RUN_COVERAGE=1 PRE_PR_BASE_REF=origin/main bash scripts/ci/pre_pr_full.sh`
gate also passed. These observations establish this update's result, not a
permanent health guarantee or an authenticated dashboard-login test.

Recovery artifacts remain host-local under
`/root/kuma-upgrade-2.5.7-20261023`: `compose.before.yml`, `rollback-image.tar`,
`live-data-backup`, `live-backup-files.sha256.json`, `rollback-ready-data`, and
the trial/operation evidence. The rollback image is the custom 2.5.6 image
listed above, not the older 2.5.3 fallback. Stop Kuma before restoring that
image and its matching full backup together; preserve the newer data directory
separately. Do not run the one-shot switch script again to request rollback.

### Application-upgrade procedure used for 2.5.7

For future upgrades, bind both versions and a fresh backup to the then-current
live state and repeat these gates. The October 22 Node patch alone was not
evidence for the 2.5.7 application upgrade:

1. Make a fresh, consistent full Kuma data backup while the observed live
   `fh-uptime-kuma:2.5.6-node22.23.4-20261022` image is still active; verify an
   isolated restore under that same old custom image and retain the old
   image/data pair together.
2. Run an offline compatibility trial with the desired official
   `louislam/uptime-kuma:2.5.7-slim@sha256:4837e2b531b118d2fad24430fabed1488b14542f03b2bebf035497271b3a1ee8`
   image and a copy of that backup. Confirm the instance starts and the
   database is readable before touching production data.
3. Compare monitor definitions and notification assignments in the offline
   trial. After the live upgrade, verify the same pre-upgrade monitor set and
   fresh successful results, including fresh Push signals. The seven-monitor
   repository catalog is a separate desired-state comparison, not permission
   to change the live catalog during an image upgrade.
4. Keep the old custom 2.5.6 image and its matching fresh data backup available
   as the rollback pair. The saved `2.5.3-slim` image above is an older fallback,
   not a substitute for this pair.
5. Stop without changing production if backup restore, image compatibility,
   monitor definitions, or fresh signals are ambiguous.

If rollback is required, restore the freshly bound data backup together with
the saved old custom 2.5.6 image. The 2.5.3 image is not an application-upgrade
rollback substitute.

Active monitors were captured on 2026-05-14. The repo desired-state catalog now
also includes reviewed follow-up changes, such as the ROB-385 split between
restore-verification freshness and backup-creation freshness. ROB-390 and
ROB-391 applied the related live Kuma changes on 2026-05-20; future live Kuma
renames or new monitor creation still require an explicit Kuma write gate.

The repo desired monitor catalog contains seven monitors. A repository merge
does not update the live catalog; use the bounded retirement procedure below. `App - Health
Deep` remains the single JSON health monitor and includes the PDF renderer
dependency check in its response; the former standalone PDF Renderer monitor
is removed from the active catalog. The static shallow-health and PDF-renderer
journal monitors are retired from the desired catalog; functional deep-health
and PDF-export checks remain.

Operational transition for the existing live Kuma instance: after `App - Health Deep`
(`json-query`) and its notifications are verified active, pause the former
`App - PDF Renderer` (`json-query`) monitor. Its historical ID is `8`; identify
it by its name and type if the instance has been rebuilt. Keep its existing
history; this repository change does not require a Push runtime or cron update.
Finish any active deployment before changing the monitor count. Application
deployments use the direct checks described in
[the deployment runbook](deployment.md).
Pausing the monitor does not alter old completed deployment records or authorize
an installation of deployment tools.

After an authorized change to Kuma itself, manually compare the affected
monitors in its dashboard with the catalog below by name and type, then verify
their status and notification assignments. Historical database IDs are not
identity: a fresh reconstruction may assign different IDs. This is an operator
check for monitoring changes, not an automated deployment gate.

`prod_validate_after_change.sh` still checks the monitoring endpoint and
container, but does not validate the Kuma database, catalog, or heartbeat
results. `prod_doctor.sh` reports observations without certifying the catalog.
Application deployments rely on their direct checks; neither the catalog nor
an all-green monitor count is an additional release condition.

### Retired monitors

The static `/health` file contains only `OK`; its keyword monitor adds no
application or dependency check beyond the retained Homepage and Deep Health
monitors. Keep the endpoint itself for existing consumers.

The PDF renderer journal monitor counts only `err..alert` journal priorities.
Renderer application output does not reliably assign those priorities, so a
green result is not proof of successful rendering. Retain Deep Health for
renderer reachability and the Dashboard PDF Export monitor for actual exports;
use renderer logs on demand when either reports a failure.

The PHP-FPM journal monitor also counts only `err..alert` journal entries and
masks journal-query failures as empty output. PHP-FPM's primary error log is
configured separately; a green journal result does not establish absence of
FPM errors. Keep FPM logs available for diagnosis, and retain Homepage, Deep
Health and application error monitoring.

The `Host - Services` Push monitor only queried `systemctl is-active`, not
application readiness. Homepage, Deep Health and actual PDF exports retain
functional checks. Separate alerts for service state alone (including Docker
or an optional access service) are not required for this single-server setup.
Keep service-state inspection in `prod_doctor.sh` and post-change diagnostics;
retiring the monitor does not stop services or remove those diagnostic checks.

The `Security - Scanner Activity` Push monitor counted matching access-log
requests, including blocked bursts from multiple sources. A `2xx` response did
not prove disclosure, and a blocked burst did not by itself require action.
Retire that continuous alert, while retaining Apache path rejection and the
fixed scanner-path checks used by `prod_doctor.sh` and post-change validation.
The separate scanner-IP Fail2ban jail is also retired; SSH and other Fail2ban
protection remain intact. See the [scanner protection decision](security/ROB-405-apache-scanner-blocking-gate.md).
Logs remain available for focused incident diagnosis; retiring the alarm does
not resolve any independently identified security finding.

Retirement changes monitor activation and the corresponding cron invocation,
not any monitored service. For each explicitly approved live
retirement, back up the affected configuration, verify retained monitors and
notification assignments, pause the identified monitor, and remove only its
cron invocation. The retired monitors are `App — Health Shallow` (keyword),
`App - PDF Renderer Log Errors` (push), `App - php8.5-fpm Log Errors` (push),
`Host - Services` (push), and `Security - Scanner Activity` (push);
the shallow monitor has no cron invocation. Already-paused monitors require no
further change. Verify fresh regular results from all retained monitors.
Preserve history, shared libraries and credentials. An unused installed script
may remain for rollback; never remove an installed script while cron still
references it. On failure, restore only the affected prior cron and monitor
state, preserving unrelated changes and newly recorded history.

Repo desired monitor catalog:

| Name | Type | Interval | Secret handling |
| --- | --- | ---: | --- |
| App-Homepage | `http` | 30s | public URL only |
| App - Health Deep | `json-query` | 30s | `X-Health-Token` header value in Kuma/host-local config only |
| Host - Resources | `push` | 60s | `KUMA_PUSH_URL_HOST_RESOURCES` |
| Ops - Restore Verify Freshness | `push` | 900s | `KUMA_PUSH_URL_OPS_JOBS` |
| Ops - Backup Creation Freshness | `push` | 900s | `KUMA_PUSH_URL_BACKUP_CREATION` |
| App - Log Errors | `push` | 60s | `KUMA_PUSH_URL_APP_LOGS` |
| App - Dashboard PDF Export | `push` | 900s | `KUMA_PUSH_URL_PDF_EXPORT` |

`App - Log Errors` is an event monitor: each newly read error is reported once,
and the next run without a new error reports recovery. Set its Kuma **Retries**
(`maxretries`) to **0**, keeping the 60-second heartbeat interval and the existing
twice-per-minute producer schedule. Requiring a repeated failure can suppress a
single error as pending and then silently recover. Zero retries also removes the
extra retry grace for missing heartbeats; the producer normally sends every
30 seconds. Preserve notification assignments and all other monitor settings.
This configuration change requires production approval; a repository merge does
not apply it. Verify the saved value and a fresh normal heartbeat afterward.

The catalog above records the non-secret monitor shape. Live monitor history,
Push URLs, and other credentials remain host-owned.

## Operational decision (2026-09-15)

The automatic release/archive/dump retention timer is intentionally disabled
and inactive. The host resource monitor remains active, but its optional
retention-success check is disabled with the host-local setting
`KUMA_RELEASE_RETENTION_MONITOR_ENABLED=0`. The installed helper is retained
for read-only inventory; any manual cleanup requires a separate, explicit
production authorization for the exact bounded pass.

## Health Monitor Boundary

`/health` and `/index.php/healthz` intentionally have different trust
boundaries:

- `/health` is the public shallow health route. It should only prove that the
  web path can return `OK`.
- `/index.php/healthz` is the application-owned deep health route. It requires
  the `X-Health-Token` header and returns dependency checks for database, GD,
  storage, and PDF renderer.
- `App - Health Deep` reads `/index.php/healthz`, including the
  `checks.pdf_renderer.ok` dependency result in the overall deep-health
  response.

The `X-Health-Token` value belongs only in Kuma monitor headers or host-local
files such as `/etc/fh/healthz.token`. Do not copy the value into Git, Linear,
chat, command transcripts, or runbook examples.

For a live header audit, record only sanitized facts:

- whether the header is configured for the deep-health monitors;
- whether the latest Kuma status is green;
- whether `/health` is independently green;
- whether deep health fails with `401`, `503`, or a dependency-specific JSON
  failure.

Do not print the header value, Kuma database rows, Push URLs, or raw production
configuration while auditing.

## New Server Startup

Start Kuma from the repository template:

```bash
docker compose -f docker/compose.uptime-kuma.yml up -d --build
```

For a disposable local restore rehearsal, use a separate data path and port:

```bash
KUMA_DATA_PATH=/private/tmp/fh-kuma-restore-test-data \
KUMA_PORT=13001 \
docker compose -f docker/compose.uptime-kuma.yml up -d --build
```

Put Apache or another reverse proxy in front of `127.0.0.1:3001` for
`monitor.dasforscherhaus-leg.de`.

For an explicitly approved new Push monitor, store its generated Push URL in
a host-local env file based on
`scripts/ops/uptime-kuma-push.env.example`.

## Push Script Installation

Install the versioned, root-controlled runtime described in
`docs/ops/production-kuma-push-runtime.md`. Root cron must never execute Push
scripts or their libraries from the app release tree. Only for initial setup,
when no host-local Env exists, create it separately. Never overwrite an existing
Env with this example:

```bash
install -m 0600 scripts/ops/uptime-kuma-push.env.example /root/backups/uptime-kuma-push.env
```

Fill in real Push URLs on the host only.

The retention-success check in `kuma_push_host_resources.sh` reads
`KUMA_RELEASE_RETENTION_MONITOR_ENABLED=0` from the protected host-local Env
and is therefore disabled by the operational decision above. The completed
one-time activation helper is retired; regular resource monitoring does not
depend on it. Keep existing recovery files.

Reactivation of the retention timer or its success check is a future,
separately authorized operational change. It requires a fresh read-only
inventory, review of the retention policy, and explicit approval of the exact
timer/monitor change before enabling either setting.

Future Env changes need explicit approval for the concrete change, a verified
backup, root-only permissions, protection against concurrent writes, and a
checked rollback. Preserve unrelated settings and secrets. Never overwrite an
existing Env with the example above or print its contents. A code update does
not authorize an Env change, Push, timer activation, or deletion.

For `/etc/cron.d`, the canonical desired state is
`scripts/ops/config/fh-uptime-kuma-push.cron`; the personal-crontab form remains
in `scripts/ops/uptime-kuma-crontab.example`. The desired schedule after the
approved transition runs:

- host resources every minute
- restore-verification freshness every 15 minutes
- backup-creation freshness every 15 minutes after the host-local backup job
  writes its success marker
- app log errors every minute plus a 30 second staggered run
- dashboard PDF export every 15 minutes

The `App - Log Errors` Push monitor is still an app-error monitor, not a
scanner monitor. Its script ignores only built-in, narrow known-noise patterns
for externally generated scanner/proxy traffic that was observed while `/`,
`/health`, and `/index.php/healthz` remained green, including the observed
numeric host:port scanner form `1465618042:3333/index`. Additional
`KUMA_APP_LOG_IGNORE_REGEX` values stay host-local and must not hide broad
classes such as all 404s or all PHP warnings.

`Ops - Restore Verify Freshness` and `Ops - Backup Creation Freshness` are
separate by design:

- `kuma_push_ops_jobs.sh` reads only `last_verify_success.utc` and proves that a
  restore-verification flow completed recently.
- `kuma_push_backup_creation.sh` reads only `last_backup_success.utc` and proves
  that a backup-creation flow completed recently.
- Neither monitor reads backup contents, lists backup directories, validates
  off-host retention, or proves end-to-end restoreability alone.

After the separately approved ROB-480 scheduler cutover, the unchanged backup
marker belongs only to the canonical ROB-466 producer and the restore marker
belongs only to the paired state-bound handoff verifier. The retired
legacy cron must not remain a second marker writer. The Push scripts and Kuma
monitor definitions do not change during that cutover.

## Backup and Restore

For any future full-history migration, use a fresh approved full backup close
to the migration window. Verify archive/database integrity, current monitor
definitions, and fresh successful pushes from the host-local scripts. A tested
older backup proves restore mechanics only; it is not the current production
backup or monitor state.

For a full-history migration:

1. Stop the new Kuma container.
2. Restore the approved backup archive into the new Kuma data volume.
3. Start the new Kuma container.
4. Confirm the same monitor names exist.
5. Confirm HTTP and JSON monitors turn green.
6. Confirm every Push monitor receives a fresh successful push from the new
   host-local cron/scripts.
