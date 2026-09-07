# Monitoring Target Concept

Date: 2026-05-20

This is the historical design proposal from that date. For the maintained
monitor catalog and operating instructions, use [Uptime Kuma Operations](../uptime-kuma.md).
Its monitor rows are historical design guidance, not a claim about current live
monitor state. The proposal recommends consolidating the standalone PDF
dependency monitor into Deep Health; verify live adoption separately.

This concept is intentionally built around the existing stack: Uptime Kuma,
app health endpoints, release gates, logs, and repo-owned ops scripts.
The repository Sentry integration is being retired. That retirement takes
effect with the later deployment containing the application changes; the live
Sentry project, events, and host configuration remain unchanged until then.
No new monitoring platform is recommended at this stage.

## Target Architecture

### Uptime Kuma

Use Kuma for outside-in and host-local operational signals:

- public availability;
- dependency health exposed through app health endpoints;
- Push freshness from host scripts;
- resource/service thresholds;
- backup/restore freshness;
- carefully bounded live synthetics.

Kuma should answer: "Does Robin need to act because the service or an
operational dependency is down or stale?"

Kuma should not be used as generic error tracking. A business conflict,
validation error, bot 404, or single expected warning should not look like app
downtime.

### App Health Endpoints

Use health endpoints as contracts between app and monitor:

- `/health`: host/webserver-owned shallow `OK`; public, no secrets.
- `/index.php/healthz`: app-owned deep health; tokenized; returns structured
  JSON with DB, GD, storage, and PDF renderer checks.
- The health token is host/Kuma-local only. The repo should document that a
  secret header is required without storing the value.

### Logs

Use logs for diagnosis and narrow Push monitors:

- app log monitor watches new app error lines, after targeted noise filters;
- PHP-FPM and PDF renderer log monitors watch service-level error journals;
- `prod_logs_summary.sh` remains the safe first log tool for Codex;
- raw logs are used only when the summary is insufficient and must be redacted
  before sharing.

### Ops Scripts

Use scripts as agent-legible adapters:

- `prod_doctor.sh`: first read-only production snapshot.
- `prod_logs_summary.sh`: redacted log classification.
- `prod_validate_after_change.sh`: post-change gate.
- `kuma_push_*.sh`: host-to-Kuma Push signals with short OK/CRIT messages.

Every Push script should produce an operator-readable message that explains the
failed condition without revealing secrets.

### Release Gates

Use release gates for deploy safety, not continuous alerting:

- predeploy zero-surprise replay;
- renderer health;
- deep health;
- live canary;
- rollback on post-switch failure;
- optional incident webhook for breakglass/rollback events.

Kuma maintenance mode may wrap the deploy window. Kuma resumes only after the
postdeploy health and canary gates pass.

## Monitor Catalog

| Monitor | Purpose | Tool | Signal | Source | Frequency | Alarm condition | Owner/action | Noise risk | Secret/data note |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| App-Homepage | Prove public app route and TLS | Kuma HTTP | Availability | `https://dasforscherhaus-leg.de/` | 30s | non-2xx after retry | Run `prod_doctor.sh`, inspect Apache/PHP | Low | Public URL only |
| App - Health Shallow | Prove minimal host/webserver health | Kuma keyword | Availability | `/health` body `OK` | 30s | no `OK` / non-200 | Check Apache/vhost/static health route | Low | Public route; document host ownership |
| App - Health Deep | Prove app dependencies | Kuma JSON | Availability/Error | `/index.php/healthz` | 30s | `status != ok` or HTTP fail | Check DB/GD/storage/PDF subcheck, then logs | Medium | Requires `X-Health-Token`; token stays in Kuma/host |
| App - PDF Renderer | Show PDF dependency health independently | Kuma JSON | Availability | `checks.pdf_renderer.ok` from deep health | 30s | value not true | Check `fh-pdf-renderer`, container, renderer logs | Medium | Same health token boundary |
| Host - Services | Detect inactive critical units | Kuma Push | Availability | `kuma_push_host_services.sh` | 60s | any configured service inactive | Check unit status; do not restart before logs | Low | Env controls service list; no secrets in msg |
| Host - Resources | Detect disk/memory/load pressure | Kuma Push | Capacity | `kuma_push_host_resources.sh` | 60s | threshold breach | Identify path/process before cleanup | Medium | No process dumps in Push msg |
| Ops - Restore Verify Freshness | Detect stale restore verification | Kuma Push | Backup | `kuma_push_ops_jobs.sh` restore-verify marker age | 15m | marker missing, invalid, or > 1440m | Renew backup/restore verification | Medium | Marker basename and age only; no backup contents |
| Ops - Backup Creation Freshness | Prove backup creation job freshness | Kuma Push | Backup | `kuma_push_backup_creation.sh` backup-success marker age | 15m | marker missing, invalid, or > 1440m | Check backup creation job and storage | Medium | Marker basename and age only; no dump listing or backup contents |
| App - Log Errors | Detect new unclassified app errors | Kuma Push | Error | `kuma_push_app_logs.sh` | 60s plus 30s stagger | new non-ignored `ERROR - ` lines | Classify with `prod_logs_summary.sh`; create a narrow follow-up if real | Low-medium after ROB-382/ROB-392 | Push URL secret; ignore regex targeted only |
| App - php8.5-fpm Log Errors | Detect PHP-FPM service errors | Kuma Push | Error | `journalctl -u php8.5-fpm` | 60s | error count > threshold | Inspect PHP-FPM journal and app health | Medium | Live display name aligned by ROB-391; Push URL stays host-local |
| App - PDF Renderer Log Errors | Detect renderer service errors | Kuma Push | Error | `journalctl -u fh-pdf-renderer` | 60s | error count > threshold | Inspect renderer container/service | Medium | No PDF content in messages |
| App - Dashboard PDF Export | Prove live dashboard PDF path | Kuma Push script | Business/PDF | `kuma_push_pdf_export.sh` | 15m | gate rc != 0 or report missing | Check dashboard gate report, renderer, auth creds | Medium | Uses host-local gate credentials |
| App - Booking Confirmation PDF | No-go for live synthetic until privacy-safe target exists | Release gate/manual only | Business/PDF | Booking confirmation PDF gate | Manual/release only | PDF cannot be downloaded/validated in approved gate | Use release/restored-data gate; do not add Kuma monitor yet | Medium-high | No real family hashes or reusable bearer links |
| Security - Scanner Activity | Track scanner spikes separately | Kuma Push | Security | Apache access log patterns | 60s | scanner count above threshold plus actionable status/source signal | Observe or tune ingress; not app downtime | Medium | No raw IPs in Push msg |
| TLS/Certbot Freshness | Catch renewal/timer risk | Script or Kuma | Security/Availability | certbot cert/timer status | Daily | cert near expiry or timer missing | Run certbot validation; inspect Apache | Low | Public cert data only |
| Release Gate Result | Block bad deploys before alert storm | CI/deploy | Release | `deploy_ea.sh` and release reports | Per deploy | predeploy/canary failure | Stop deploy or rollback | Low | Reports avoid credentials |
| Codex Prod Doctor | Standard first incident snapshot | Script | Diagnostic | `prod_doctor.sh` | On incident | n/a | Summarize state before changes | Low | Redacted; no raw DB rows |

## Uptime Kuma Concept

### Keep

Keep these monitors:

- App homepage.
- Shallow health.
- Deep health.
- PDF renderer deep subcheck.
- Host services.
- Host resources.
- App log errors.
- PHP-FPM log errors.
- PDF renderer log errors.
- Dashboard PDF export.
- Scanner activity.

### Change

Change or clarify:

- Document deep-health/PDF JSON monitors as requiring a secret header. Do not
  put the token in repo templates.
- Keep the PHP-FPM log monitor display and script default aligned to
  `php8.5-fpm`; ROB-391 completed the live Kuma display-name alignment on
  2026-05-20.
- Verify production `KUMA_HOST_SERVICES_LIST` includes `cron`, `fail2ban`, and
  `unattended-upgrades`, or explicitly document why they are doctor-only.
- Keep backup creation and restore verification as separate signals; ROB-390
  completed the live split and renamed the old generic jobs monitor on
  2026-05-20.
- Keep `App - Log Errors` limited to proven scanner/proxy noise ignores while
  still alerting on new real app errors; ROB-382 and ROB-392 aligned the Kuma
  script, app-log summary, and post-change validation logic.

### Add Only If Useful

Add:

- TLS/certbot freshness if not already covered by Kuma certificate features or
  post-change checks;

Keep deferred after ROB-387:

- parent booking confirmation PDF synthetic, and only if a privacy-safe stable
  synthetic confirmation can be maintained.

### Remove

Remove none immediately. Reclassify `Security - Scanner Activity` as
observation/security context, not app availability. Fully blocked external
bursts should stay telemetry-only; scanner-path 2xx responses or many-source
bursts remain actionable.

### Push Monitor Secrets

Push URLs are secrets. Keep them only in host-local env files or Kuma DB state:

- `KUMA_PUSH_URL_HOST_SERVICES`
- `KUMA_PUSH_URL_HOST_RESOURCES`
- `KUMA_PUSH_URL_OPS_JOBS`
- `KUMA_PUSH_URL_APP_LOGS`
- `KUMA_PUSH_URL_PHP_FPM_LOGS`
- `KUMA_PUSH_URL_PDF_RENDERER_LOGS`
- `KUMA_PUSH_URL_PDF_EXPORT`
- `KUMA_PUSH_URL_SECURITY_SCANNER`

The repo should only contain variable names and scripts.

### Agent-Legible Output

Every script message should follow this shape:

- `OK <metric>=<value> <context>`
- `WARN <metric>=<value> <threshold/context>`
- `CRIT <metric>=<value> <actionable-short-context>`

Do not include raw IPs, request tokens, Push URLs, DB values, customer names, or
full stack traces in Push messages.

## Alert And Incident Model

### P1: Wake Or Immediate Action

Use P1 for:

- public app route unavailable;
- deep health failing for DB, storage, or PDF renderer;
- critical service inactive: Apache, PHP-FPM, MariaDB, Docker, PDF renderer;
- repeated unexpected 5xx on booking/calendar/dashboard;
- deploy canary failure or rollback failure;
- disk critically near full.

First action: `prod_doctor.sh`, then classify whether this is public
availability, dependency, capacity, or deploy regression.

### P2: Same Day

Use P2 for:

- dashboard PDF export synthetic failing while app route is healthy;
- new application-log issue in a critical flow with low volume;
- restore verify marker stale but app otherwise healthy;
- cert expiry approaching warning window;
- PHP-FPM or PDF renderer journal errors without current outage.

First action: redacted logs plus relevant script/gate report.

### P3: Next Workday

Use P3 for:

- low-frequency application-log issue outside critical flows;
- scanner activity above threshold but no app impact;
- non-blocking CI trend warnings;
- post-change validation warning where all live monitors remain green and root
  cause is already classified.

### Observe Only

Observe without alerting Robin for:

- isolated bot 404s;
- expected 401/403/404;
- expected business 409 conflicts;
- invalid-login attempts;
- one-off known scanner/proxy noise after ROB-382 classification exists.

## Standard Codex Alarm Workflow

1. Identify monitor class: public HTTP, deep health, Push freshness, app logs,
   host resources, or synthetic.
2. Check whether primary availability is green: homepage, `/health`, deep
   health, Kuma latest status.
3. Run `bash scripts/ops/prod_doctor.sh`.
4. If logs matter, run `bash scripts/ops/prod_logs_summary.sh --since "60 min ago"`.
5. For a Push monitor, inspect the matching `kuma_push_*.sh` script and its
   expected env variable. Do not print the env file.
6. Decide route:
   - Kuma-only infra/dependency issue;
   - application-log or release-gate error/business-flow issue;
   - observation/noise for known scanner/business cases.
7. Do not change production until the failure class is clear and the stop
   conditions are not triggered.
8. After any approved change, run `prod_validate_after_change.sh`.

Stop immediately if:

- a command would print `config.php`, `/etc/fh` contents, Push URLs, tokens, or
  DB rows;
- a change would delete backups, Kuma data, provider snapshots, or release
  archives;
- app health and rollback direction are both unclear;
- the observed server shape differs from the accepted Ubuntu 26.04 baseline;
- the fix requires broad Apache/app/deployment model changes.

## Implementation Roadmap

### 1. ROB-382 App-Log Noise Classification - Shipped

Marking: `Repo-only`, `Server`, `Kuma`

ROB-382 was completed separately by PR #280 before the long-horizon
implementation run. Treat it as shipped baseline:

- scanner/proxy app-log noise is classified in the repo-owned ops scripts;
- unexpected `ERROR - ` lines still remain actionable;
- `prod_validate_after_change.sh` and `prod_logs_summary.sh` use the same
  classification helper;
- no runtime rate-limit bypass is part of the shipped solution.

### 2. Deep Health And Kuma Secret Boundary Cleanup - Shipped Repo-Side

Marking: `Repo-only`, `Kuma`

- ROB-384 was completed by PR #283.
- The desired-state monitor file now marks the deep-health and PDF-renderer
  monitors as requiring host/Kuma-local `X-Health-Token` headers.
- The agent operations docs distinguish token-missing `401` from real app or
  dependency `503` failures.
- Live Kuma header audit remains a gated read-only Kuma step because token
  values must not be printed or committed.

### 3. Backup And Cron Freshness Split - Shipped Repo-Side

Marking: `Repo-only`, `Server`, `Kuma`

- ROB-385 was completed by PR #284.
- Repo desired state now names the existing restore-verification signal
  `Ops - Restore Verify Freshness`.
- `scripts/ops/kuma_push_backup_creation.sh` adds a separate marker-based Push
  signal for backup creation freshness.
- Live adoption requires host-local Push URL provisioning and cron/timer
  scheduling; Push URLs remain secrets.

### 4. Runtime Name Drift Cleanup - Shipped Repo-Side

Marking: `Repo-only`, `Kuma`, `Server`

- ROB-386 was completed by PR #285.
- A read-only production check confirmed `php8.5-fpm` active/enabled and
  `php8.3-fpm` inactive/not listed.
- Repo desired state uses `App - php8.5-fpm Log Errors`.
- The pre-wipe inventory helper defaults to `php8.5-fpm` while keeping service
  names configurable.
- Renaming a stale live Kuma display name remains an explicit Kuma write gate.

### 5. Optional Parent Confirmation PDF Synthetic

Marking: `Needs decision`, `Repo-only`, `Kuma`

Decision after ROB-387: do not run this as a live Kuma synthetic yet.

Reason:

- the existing gate requires a confirmation hash or full confirmation URL;
- that value is bearer-like access to a parent-facing confirmation page;
- there is no current durable synthetic appointment/hash that is clearly safe
  to reuse in production monitoring.

Current safe use:

- run the existing booking confirmation PDF gate during release/restore
  validation with a non-production or explicitly approved host-local hash;
- keep continuous monitoring on renderer health, dashboard PDF export, and logs.

Only revisit as a separate gated issue if a privacy-safe synthetic target exists
and all bearer-like values remain host-local. The detailed decision is in
[Parent Booking Confirmation PDF Synthetic Decision](parent-confirmation-pdf-synthetic-decision.md).

### 6. ROB-367 Observation Tie-In

Marking: `Server`, `Kuma`, `Repo-only`

When ROB-367 runs, use the monitoring concepts here as the observation checklist:

- Kuma stayed green or exceptions are classified by monitor type;
- Push monitors received fresh signals, including separate backup creation and
  restore-verification freshness after live adoption;
- service logs are quiet or findings have narrow follow-up issues;
- backup/restore freshness is clear;
- PHP-FPM runtime names match the live `php8.5-fpm` service;
- deep-health failures separate missing token/header configuration from real
  dependency failures;
- PDF monitoring avoids bearer-like parent confirmation URLs unless a
  privacy-safe synthetic target is explicitly created;
- lessons are recorded in the relevant ops or long-horizon doc.

ROB-388 feeds this checklist into ROB-367, but ROB-367 itself remains a later
read-only production observation gate.

## Linear Follow-Up Proposal

Recommended order:

1. ROB-382: Uptime-Kuma App-Log-Monitor gegen Scanner-/Proxy-Noise haerten.
   Done by PR #280; keep as shipped baseline, do not broaden.
   Labels: `Repo-only`, `Server`, `Kuma`.
2. ROB-384: Kuma deep-health secret-boundary documentation and live header audit.
   Completed by PR #283; live header audit remains gated.
   Labels: `Repo-only`, `Kuma`.
3. ROB-385: Backup freshness vs restore-verify freshness split.
   Completed by PR #284; live Push URL provisioning was completed by ROB-390.
   Labels: `Repo-only`, `Server`, `Kuma`.
4. ROB-386: Production monitor runtime-name drift cleanup (`php8.3` to `php8.5`).
   Completed by PR #285; live Kuma display-name alignment was completed by
   ROB-391.
   Labels: `Repo-only`, `Kuma`, `Server`.
5. ROB-387: decide privacy-safe parent confirmation PDF live synthetic.
   Completed by PR #286; decision is no live parent confirmation synthetic yet.
   Labels: `Needs decision`, `Repo-only`, `Kuma`.
6. ROB-367: include monitoring soak and lessons learned.
   Completed as the post-rebuild observation handoff; follow-up live changes
   were split into ROB-390, ROB-391, and ROB-392.
   Labels: `Server`, `Kuma`, `Repo-only`.
7. ROB-388: completed roadmap coordination for the monitoring follow-up.
   Completed by PR #287 as the final repo-only coordination package.
   Labels: `Repo-only first`, `Server gate`, `Kuma gate`.
8. ROB-390: split live backup-creation freshness from restore verification.
   Completed on 2026-05-20; live Kuma summary showed 13 active monitors and
   13 latest green after the change.
   Labels: `Server`, `Kuma`.
9. ROB-391: align live PHP-FPM monitor display name with `php8.5-fpm`.
    Completed on 2026-05-20.
    Labels: `Kuma`.
10. ROB-392: tighten production app-log counting.
    Completed by PR #289.
    Labels: `Repo-only`, `Server`, `Kuma`.

## Short Summary

Urgent:

- Keep live Push URL, health-token, and parent-confirmation bearer-like values
  out of chat, Linear, docs, and git.

Useful but not urgent:

- Reconcile any future Kuma desired-state drift with redacted snapshots before
  changing live monitors.
- Revisit parent confirmation PDF synthetics only if a privacy-safe synthetic
  target exists.

Do not build now:

- Prometheus/Grafana/Loki or another monitoring stack.
- Live write-path booking synthetics against real production data.
- Broad "ignore all 404/warning" filters.
- Kuma as generic exception tracking.
