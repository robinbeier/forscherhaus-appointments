# Ops Monitoring Scripts

These scripts mirror and extend the current production Uptime Kuma setup without
storing Push secrets in the repository.

Current production monitor names are documented in `docs/uptime-kuma.md` and
mirrored in `scripts/ops/uptime-kuma.monitors.yml`.

For agent-first production diagnostics and post-change validation, start with
`docs/ops/agent-operations.md` and the `prod_*.sh` scripts in this directory.

For the on-demand, synthetic-only production Provider UI browser smoke, use
`docs/release-gate-provider-ui-smoke.md`. It deliberately runs Playwright on the
operator workstation, not on the production host or in Kuma.

For the on-demand, no-customer-data Customers UI role smoke, use
`docs/release-gate-customers-ui-smoke.md`. It is likewise operator-side and
never belongs in Kuma or cron.

For the shared passive Deploy/Customers traffic decision, use
`docs/traffic-gate-v1.md` and `prod_traffic_gate.sh`. The gate reads the full
current/rotated Apache log set and emits only versioned aggregate evidence.

For the pure ROB-455 deploy intent, lifecycle, child-result receipt, and evidence
contract, use `docs/deployment-run-v1.md`, `lib/DeployResultV1.php`, and
`validate_deployment_contract_v1.php`. This contract slice does not install a
host runner or activate production behavior.

For the one-time ROB-464 legacy session-mode repair boundary, use
`docs/ops/production-session-mode-normalization.md`. It defines the
dry-run/execute split, the exact live confirmation token, fail-closed stop
rules, and the required post-normalization ROB-440 dry-run without authorizing
production execution by merge alone.

Use `scripts/ops/uptime-kuma-push.env.example` as the host-local env template.
The immutable root runtime, canonical `/etc/cron.d` file and migration contract
are documented in
[`docs/ops/production-kuma-push-runtime.md`](../../docs/ops/production-kuma-push-runtime.md);
`scripts/ops/uptime-kuma-crontab.example` remains the personal-crontab form.

Deep-health monitor boundary:

- `/health` is public shallow health.
- `/index.php/healthz` is token-protected deep health.
- The `App - Health Deep` and `App - PDF Renderer` Kuma JSON monitors require
  an `X-Health-Token` header value configured only in Kuma or host-local files.
- The desired-state YAML names that required header but must never contain the
  real value.
- A `401` from `/index.php/healthz` means the first audit target is the
  header/config boundary, not database, storage, or PDF renderer health.

Script inventory:

- `kuma_push_app_logs.sh` monitors newly appended application log errors
- `kuma_push_host_services.sh` monitors critical systemd services
- `kuma_push_host_resources.sh` monitors disk, memory, and load thresholds
- `prod_session_retention.sh` inspects or explicitly applies the bounded ROB-440
  24-hour file-session policy; its systemd units are shipped disabled and the
  operational sequence lives in
  [`docs/ops/production-session-retention.md`](../../docs/ops/production-session-retention.md)
- `prod_release_archive_dump_retention.sh` inspects or explicitly applies the
  separate conservative ROB-453 release-directory, archive-pair, and
  restore-verified-dump policy; it is dry-run by default and its disabled
  systemd activation path is documented in
  [`docs/ops/production-release-archive-dump-retention.md`](../../docs/ops/production-release-archive-dump-retention.md)
- `prod_dump_producer_admission.sh` reports the aggregate, read-only ROB-483
  single-producer registry and canonical manifest/attestation admission class.
  It has no execute path; installation, monitoring activation, and object
  mutation remain separate approvals. See
  [`docs/ops/production-dump-producer-admission.md`](../../docs/ops/production-dump-producer-admission.md).
- `prod_backup_set_producer.sh` executes one closed ROB-480 backup-and-restore
  continuity pass only with the exact ROB-466 write and ROB-461 restore
  confirmations. ROB-480 additionally ships a disabled daily continuity timer;
  a root-only pending/verified handoff state binds the separately sandboxed
  producer and restore-verifier stages. Repository delivery does not install,
  enable, or start those units. See
  [`docs/ops/production-backup-set-producer.md`](../../docs/ops/production-backup-set-producer.md).
- `prod_verify_latest_deployment_dump.sh` resolves that producer's protected
  handoff on the host and runs one ROB-461 restore-attestation pass only with
  its separate exact live confirmation; no set ID crosses the wrapper.
- `prod_legacy_release_provenance.sh` inspects the two fixed host-authorized
  current/rollback legacy archives by default and invokes only the installed
  ROB-468 root helper. Its separately confirmed execute mode can publish only
  canonical no-replace provenance sidecars and helper-owned temps; see
  [`docs/ops/production-legacy-release-provenance.md`](../../docs/ops/production-legacy-release-provenance.md).
  `--inspect-authorization` is read-only; the separately confirmed
  `--provision-authorization` gate may create only the missing fixed
  host-local authorization from validated marker/archive/deployment-run
  authority, or clean only exact helper-owned stale authorization temps, and
  is not sidecar execute approval.
- `prod_journald_retention.sh` inspects the fixed 1 GiB / 30-day journald
  contract by default. Configuration activation, one-time vacuum, and rollback
  use distinct confirmation tokens and remain unexecuted by repository delivery;
  see [`docs/ops/production-journald-retention.md`](../../docs/ops/production-journald-retention.md).
- `prod_app_log_retention.sh` inspects the closed 60-day CodeIgniter daily-log
  class and requires a separate exact confirmation before a bounded pass. It
  never traverses release-gate, CI, ops, or diagnostic evidence; see
  [`docs/ops/production-app-log-retention.md`](../../docs/ops/production-app-log-retention.md).
- `kuma_push_ops_jobs.sh` monitors restore-verification marker freshness
- `kuma_push_backup_creation.sh` monitors backup-creation marker freshness
- `kuma_push_php_fpm_logs.sh` monitors recent PHP-FPM journal errors
- `kuma_push_pdf_renderer_logs.sh` monitors recent `fh-pdf-renderer` journal errors
- `kuma_push_pdf_export.sh` runs the dashboard PDF release gate as a synthetic smoke
- `kuma_push_apache_scanner_activity.sh` watches recent Apache access logs for common scanner probes and only alerts on actionable scanner activity
- `lib/kuma_push_common.sh` provides shared env, curl, and log helpers
- `prod_kuma_push_runtime_v1.sh` plans or performs the exact ROB-489 immutable
  runtime installation and ten-invocation cron path migration over Tailscale;
  default mode is local plan-only
- `prod_doctor.sh` prints redacted read-only production status
- `prod_logs_summary.sh` prints redacted recent production log summaries
- `prod_validate_after_change.sh` runs the standard post-change production gate
- `provider_ui_smoke_principal.sh` is the server-local root-only bootstrap and
  lifecycle wrapper for the permanently dormant Provider UI smoke principal
- `prod_provider_ui_smoke.sh` runs the operator-side Provider UI smoke with a
  short synthetic lease, shell cleanup, and an independent ten-minute systemd
  cleanup timer
- `customers_ui_smoke_principals.sh` manages the four root-protected dormant
  Customers role principals
- `prod_customers_ui_smoke.sh` runs their operator-side Customers view/search
  smoke with no customer fixture and independent ten-minute cleanup
- `prod_traffic_gate.sh` produces the shared passive `traffic_gate.v1` decision
  before caller-owned probes or mutations; it requires a root-protected exact
  monitor-source catalog and fails closed on missing source or active-request
  boundary evidence
- `validate_deployment_contract_v1.php` validates canonical local
  `deployment_run.v1` JSONL plus closed `deployment_evidence.v1` JSON without
  invoking a deploy or trusting a production path; the evidence keeps the
  normal deploy reservation separate from any at-most-once post-gate recovery
- `lib/DeployResultV1.php` validates a closed canonical `deploy_result.v1`
  child-receipt candidate and derives its fixed deploy-evidence tuple without
  reading timing or process output; authority additionally requires an
  independently observed matching child exit and durable runner-state binding
- `lib/DeploymentHostRunnerContractV1.php` freezes the closed deploy/recovery
  requests, pinned semantic execution input, exact-byte post-gate submissions,
  state/response bindings, protected lock path, null-stream policy, deterministic
  transient-unit launch/observation identity, crash-prefix reconciliation, and
  exact terminal report/unit-byte proof for the later root Host Runner; the
  library performs no filesystem mutation, process execution, or production
  activation
- `prod_cleanup_inventory.sh` prints a read-only, redacted cleanup inventory for
  releases, backups, sessions, cache, logs, uploads, and cleanup candidate
  classes plus the aggregate dump-producer admission exit class, without
  deleting anything or forwarding admission output
- `prod_build_cache_retention.sh` reports aggregate Docker build-cache facts in
  read-only mode by default and exposes a separately confirmed, age- and
  storage-bounded builder-cache-only execute gate; see
  `docs/ops/production-build-cache-retention.md`
- `prod_zero_surprise_image_cleanup.sh` provides the ROB-458 read-only/default
  and separately confirmed bounded compatibility cleanup for legacy replay
  images without patching the active release; see
  `docs/ops/production-zero-surprise-image-cleanup.md`
- `install_prod_agent_readme.sh` installs the server-local agent orientation file in explicit execute mode
- `lib/prod_sensitive_paths.sh` checks fixed sensitive web path classes without
  printing URLs, file contents, tokens, session data, or discovered filenames
- `lib/prod_scanner_paths.sh` checks fixed scanner probe classes without
  printing URLs, response bodies, or raw scanner request paths
- `lib/prod_posture.sh` reports advisory production posture classes for
  headers, SSH policy flags, UFW status, listener classes, and unexpected
  public listener count without printing raw config, listener addresses, or
  secrets

Default env file:

- `/root/backups/uptime-kuma-push.env`

Required new Push URLs:

- `KUMA_PUSH_URL_HOST_SERVICES`
- `KUMA_PUSH_URL_HOST_RESOURCES`
- `KUMA_PUSH_URL_OPS_JOBS`
- `KUMA_PUSH_URL_BACKUP_CREATION`
- `KUMA_PUSH_URL_APP_LOGS`
- `KUMA_PUSH_URL_PHP_FPM_LOGS`
- `KUMA_PUSH_URL_PDF_RENDERER_LOGS`
- `KUMA_PUSH_URL_PDF_EXPORT`
- `KUMA_PUSH_URL_SECURITY_SCANNER`

Optional ops freshness env:

- `KUMA_OPS_JOBS_VERIFY_FILE` default `/root/backups/easyappointments/last_verify_success.utc`
- `KUMA_OPS_JOBS_MAX_VERIFY_AGE_MINUTES` default `1440`
- `KUMA_BACKUP_CREATION_MARKER_FILE` default `/root/backups/easyappointments/last_backup_success.utc`
- `KUMA_BACKUP_CREATION_MAX_AGE_MINUTES` default `1440`

Freshness semantics:

- `kuma_push_ops_jobs.sh` proves only that a restore-verification flow has
  written a recent success marker.
- `kuma_push_backup_creation.sh` proves only that a backup-creation flow has
  written a recent success marker.
- Neither script reads backup contents, lists dump directories, validates
  off-host retention, or proves restoreability by itself.
- Push messages include only marker basenames and ages, not backup filenames,
  dump paths, DB rows, or archive contents.

Optional php-fpm log env:

- `KUMA_PHP_FPM_LOG_WINDOW_MINUTES` default `5`
- `KUMA_PHP_FPM_SERVICE_NAME` default `php8.5-fpm`
- `KUMA_PHP_FPM_ERROR_THRESHOLD` default `0`

Optional PDF renderer log env:

- `KUMA_PDF_RENDERER_LOG_WINDOW_MINUTES` default `5`
- `KUMA_PDF_RENDERER_SERVICE_NAME` default `fh-pdf-renderer`
- `KUMA_PDF_RENDERER_ERROR_THRESHOLD` default `0`

Optional PDF export env:

- `KUMA_PDF_EXPORT_APP_ROOT` default `/var/www/html/easyappointments`; the
  historical `KUMA_PDF_EXPORT_REPO_ROOT` remains a compatibility fallback
- `KUMA_PDF_EXPORT_BASE_URL` default `http://localhost`
- `KUMA_PDF_EXPORT_INDEX_PAGE` default `index.php`
- `KUMA_PDF_EXPORT_CREDENTIALS_FILE` default `/etc/fh/release-gate-admin.env`
- `KUMA_PDF_EXPORT_USERNAME` overrides `USERNAME`
- `KUMA_PDF_EXPORT_PASSWORD` overrides `PASSWORD`
- `KUMA_PDF_EXPORT_PDF_HEALTH_URL` default `http://127.0.0.1:3003/healthz`
- `KUMA_PDF_EXPORT_WINDOW_DAYS` default `30`

App log script behavior:

- tracks only newly appended bytes in the current daily app log
- primes itself on first run to avoid an immediate false alarm from historical log lines
- applies a built-in narrow classifier for known scanner/proxy noise that has
  been proven not to represent app downtime, currently:
    - absolute-URI/proxy scanner 404s matching observed route shapes such as
      `Azenvnet/index`, `Wwwgooglecom/index`, and `127001:80/index`
    - numeric host:port scanner 404s matching observed route shapes such as
      `1465618042:3333/index`
    - encoded index.php scanner 404s matching `Index%2ephp/index`
    - CodeIgniter file-cache expiry races for `rate_limit_key_*` entries at
      `Cache_file.php 279`
- supports `KUMA_APP_LOG_IGNORE_REGEX` for additional host-local expected noisy
  log lines, e.g. the host-only `http://pdf-renderer:3000` fallback error or
  expected invalid-login errors such as
  `JSON exception: .*Ungültige Zugangsdaten angegeben`
- does not ignore all 404s, all warnings, or all rate-limit-related errors;
  genuine unclassified app errors must still turn monitor `#9` red
- uses an exclusive lock around the state file so a staggered second cron run cannot race the primary per-minute run
- production currently runs `kuma_push_app_logs.sh` twice per minute: once on the minute and once with a `sleep 30` offset for faster recovery on monitor `#9`

`prod_logs_summary.sh` and `prod_validate_after_change.sh` use the same built-in
classifier, so post-change validation reports actionable app log errors while
also showing how many recent error-like lines were ignored as known noise.
`prod_validate_after_change.sh` separates current actionable app errors observed
after the validation start from historical actionable entries still present in
the 24h app-log window. The current-error check is based on a validation-start
byte snapshot of app-log files, not on log timestamp string comparison, so host
timezone and app log timezone drift cannot turn historical entries into current
failures. Current actionable errors fail the gate; older 24h entries remain
visible as context instead of keeping an otherwise recovered post-change
validation red.

Sensitive-path validation:

- `prod_doctor.sh` reports fixed sensitive path classes as HTTP status classes
  only.
- `prod_validate_after_change.sh` fails when any fixed sensitive path class
  returns HTTP 2xx or when the probe itself cannot run.
- Both scripts stream the local `lib/prod_sensitive_paths.sh` helper over SSH,
  so the check does not depend on the active production app tree already
  carrying the latest ops helper files.
- The check covers the production web exposure classes for storage, session,
  cache, log, vendor, root config, application, and system paths.
- The check output intentionally uses stable class labels and never prints the
  requested URLs, response bodies, tokens, session values, raw config, or
  discovered filenames.

Scanner-path validation:

- `prod_doctor.sh` reports fixed scanner probe classes as HTTP status classes
  only.
- `prod_validate_after_change.sh` reports the same classes in advisory mode by
  default so pre-ROB-405 production state does not break unrelated validation.
- For the ROB-405 live gate, run
  `bash scripts/ops/prod_validate_after_change.sh --require-scanner-blocking`;
  this fails when any fixed scanner probe class returns HTTP 2xx or when a
  probe itself cannot run. Default-, unmatched- and dynamically resolved
  IP-literal-host probes are stricter: only HTTP `403/404` passes on both HTTP
  and TLS, so redirects cannot hide an unprotected default vhost. These probes
  use the public default-route interface rather than loopback. The gate also
  checks the App and Monitor vhost surfaces.
- The check covers known scanner classes for environment files, Git metadata,
  WordPress/PHP info probes, server-status, vendor/phpunit, HNAP1, boaform,
  cgi-bin, phpinfo query-string probes, and the `.env~` backup-suffix variant.
- Output intentionally uses stable class labels and never prints requested
  URLs, response bodies, tokens, raw config, or discovered filenames.
- The default-host extension uses only synthetic unmatched-host input and a
  runtime-derived public-interface address. Neither value is printed or
  retained. TLS certificate verification is intentionally separate from this
  status-only IP-literal/default-vhost probe.

Security posture reporting:

- `prod_doctor.sh` reports posture facts as advisory classes/flags only.
- Header posture is presence-only for App, `www`, and Monitor surfaces; header
  values are never printed.
- SSH posture uses selected effective `sshd -T` policy flags only and never
  prints keys, users, or raw sshd config.
- Firewall and port posture reports UFW status, expected port classes,
  loopback-only internal service classes, and unexpected public listener count;
  it does not print raw listener addresses.
- Missing headers, `PasswordAuthentication=yes`, forwarding enabled, or
  `UFW inactive` are hardening hints for follow-up gates. They are not
  `prod_doctor.sh` failures by themselves.

Dump restore attestation:

- Install `scripts/ops/libexec/deployment_dump_attestation_v1.py` as root-owned
  mode `0555` at `/usr/local/libexec/fh/deployment_dump_attestation_v1.py`.
- Install `scripts/ops/libexec/validate_deployment_terminal_bundle_v1.php` as
  root-owned mode `0555` and `scripts/ops/lib/DeploymentContractV1.php` as
  root-owned mode `0444` in the same `/usr/local/libexec/fh` directory.
- Run `php scripts/ops/verify_deployment_dump_v1.php 20YYMMDDTHHMMSSZ` as root
  on the host for an explicitly selected set. For the ROB-461 production wave,
  use `prod_verify_latest_deployment_dump.sh`; its fixed
  `--latest-handoff` path selects and cross-binds the protected producer
  handoff without exposing the ID to the operator wrapper. Paths, credentials,
  SQL, digests, images, timestamps, and environment overrides are not
  accepted.
- Code or installation approval is not approval to execute a production
  restore or publish production evidence.

Closed production backup sets:

- Install `scripts/ops/libexec/backup_set_producer_v1.py` as root-owned mode
  `0555` at `/usr/local/libexec/fh-backup-set-producer-v1`.
- Install `scripts/ops/libexec/backup_set_producer_supervisor_v1.sh` as
  root-owned mode `0555` at
  `/usr/local/libexec/fh-backup-set-producer-supervisor-v1`; the recurring
  systemd producer must use this fixed parent so the Python helper's unchanged
  parent-death binding remains effective.
- Provision the dedicated `fh_backup` database account with only `SELECT` and
  `SHOW VIEW` on `easyappointments.*`. Provide the exact six-line connection
  authority at `/etc/fh/backup-set-producer.cnf` as root-owned mode `0600`;
  it selects only the host-local `127.0.0.1:3306` TCP listener and never a
  Unix-domain socket. Only its bounded base64url password varies. Never commit,
  print, or pass its contents through argv or environment variables.
- ROB-480 ships disabled desired-state units for daily state-bound canonical
  continuity and independent pending-state restore verification.
  Install, manual
  continuity proof, legacy-cron cutover, activation and rollback remain
  separately controlled production operations; merge activates nothing.
- The production sequence, atomic set contract and ROB-461 two-set gate live
  in `docs/ops/production-backup-set-producer.md`.

Legacy release provenance:

- The repository helper source is
  `scripts/ops/libexec/legacy_release_provenance_v1.py`; a future separately
  approved installation places it root-owned mode `0555` at
  `/usr/local/libexec/fh-legacy-release-provenance-v1`.
- Its only authorization is the canonical host-local root-owned mode `0600`
  `/etc/fh/legacy-release-provenance-authorization.v1.json`. No authorization
  value or target selector crosses the wrapper.
- Repository delivery and merge do not install or run the helper. The closed
  authorization, inspection, execution, and rollback boundary lives in
  `docs/ops/production-legacy-release-provenance.md`.
