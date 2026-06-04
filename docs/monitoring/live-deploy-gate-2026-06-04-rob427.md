# ROB-427 Live Deploy Gate - 2026-06-04

Purpose: deploy the merged repo-only ROB-427 validation-harness fix through the
standard production artifact path and verify that post-change app-log validation
now separates current actionable app-log errors from historical 24h context.

## Scope

Included:

- ROB-427: `prod_validate_after_change.sh` uses validation-start app-log byte
  snapshots plus prefix hashes instead of timestamp-string comparison.
- Regression behavior: historical app-log entries remain context; newly appended,
  truncated, rewritten, or grown-back app-log errors are treated as current.

Excluded:

- changing Uptime Kuma monitors or Push URLs;
- changing Sentry settings or alert rules;
- cleanup of old releases, sessions, backups, or local build artifacts;
- database writes outside the existing zero-surprise predeploy/canary flow.

## Execution

Release:

- `ea_rob427_live_20260604_1720`
- local branch: `main`
- merge commit before deploy: `37d9bf0c`
- archive SHA-256:
  `8083dbca16fb029991b661d666186d8512aeef6fab705358339db28498b7b7e0`

Preflight:

- `bash scripts/ci/pre_pr_quick.sh` passed with Docker access.
- `bash scripts/ops/prod_doctor.sh` showed app, deep health, PDF renderer,
  services, sensitive/scanner path checks, and Kuma latest state green.
- Pre-deploy public `/build/`, `/build/index.php`, `/build/vendor/`, and
  `/build/vendor/autoload.php` returned `404`.
- Host-local gate files were checked by path/readability only; no file contents
  were printed.
- Current release before deploy was
  `ea_rob420_421_422_live_20260604_1619`.

Build and upload:

- `./build_release.sh --rel ea_rob427_live_20260604_1720 --project "$PWD" --upload ... --remote-dir /root/releases`
- Local and remote artifact validators passed.
- Local and remote archive SHA-256 matched.
- Explicit remote archive check found no `build/` entries.

Host deploy script:

- Production `/root/deploy_ea.sh` already matched `deploy_ea.sh` inside the
  uploaded release archive.
- No deploy-script synchronization was needed.

Deploy:

- `/root/deploy_ea.sh` completed with exit code `0`.
- Deploy used `--renderer-deploy-mode external`; host Node/npm remained absent.
- Zero-surprise predeploy replay passed.
- Zero-surprise report validation passed.
- PDF renderer health passed after one pending probe immediately following the
  renderer restart.
- Deep health contract passed.
- Zero-surprise post-deploy canary passed.
- Localhost HTTP check passed.

Post-deploy checks:

- `_RELEASE` reports `ea_rob427_live_20260604_1720`.
- `/var/www/html/easyappointments/build` is absent.
- Public `/build/`, `/build/index.php`, `/build/vendor/`, and
  `/build/vendor/autoload.php` returned `404`.
- `bash scripts/ops/prod_validate_after_change.sh` passed.
- ROB-427-specific app-log evidence:
  - `app_error_like_lines_current=0`
  - `app_error_like_lines_24h=4`
  - `app_error_like_lines_24h_historical=4`
- Fresh `prod_doctor` after deploy showed app, deep health, renderer, services,
  sensitive/scanner path checks, and Kuma latest state green (`13` active,
  `13` green).
- `SENTRY_AUTH_TOKEN` and `SENTRY_AUTH_TOKEN_FILE` were not present in the local
  shell, so the optional Sentry read-only check was skipped.

## Outcome

Live deployment succeeded. ROB-427 is active on production and the standard
post-change validation gate now passes with historical app-log context retained
as context instead of failing the current-error check.

No secrets, Push URLs, tokens, DB rows, backup contents, raw logs, Sentry event
payloads, or host-local configuration contents were recorded in this protocol.
