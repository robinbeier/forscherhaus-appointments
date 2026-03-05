# Napkin Runbook

## Curation Rules

-   Re-prioritize on every read.
-   Keep recurring, high-value notes only.
-   Max 10 items per category.
-   Each item includes date + "Do instead".
-   Keep items sorted by execution risk first.

## Execution & Validation (Highest Priority)

1. **[2026-03-04] Treat OpenAPI error responses as schema-optional in contract smokes**
   Do instead: for 4xx/5xx checks (for example unauthorized guards), assert status/header contracts first and only enforce JSON schema when the spec actually defines one.
2. **[2026-02-22] Run CI-parity tests through docker compose before merge-sensitive changes**
   Do instead: execute `docker compose run --rm php-fpm composer test` and report failures or environment limits in handoff.
3. **[2026-02-22] Rebuild frontend bundles when touching `assets/js` or `assets/css`**
   Do instead: run `npx gulp scripts` and/or `npx gulp styles`, then verify updated artifacts in `build/`.
4. **[2026-02-22] Keep migration rollback path complete**
   Do instead: implement database changes only via CodeIgniter migrations and verify both migrate up and down behavior.
5. **[2026-02-26] Keep deep health checks fast under dependency outages**
   Do instead: gate local-only fallback endpoints by `APP_ENV` and keep health-check network timeouts short to avoid long blocking requests.
6. **[2026-02-28] Match CI smoke DB readiness before dashboard checks**
   Do instead: wait for both MySQL root ping and app-user query readiness, then retry `php index.php console install` up to 3 times before running `scripts/ci/dashboard_integration_smoke.php`.

## Repo Guardrails & Domain Behavior

1. **[2026-02-22] Keep production code inside `application/`**
   Do instead: place feature/controller/model/view changes in `application/` and avoid edits in `system/` unless explicitly requested.
2. **[2026-02-25] Keep health endpoints versioned in app code**
   Do instead: implement monitoring endpoints as routes/controllers under `application/` and deploy them via release artifacts; avoid unmanaged root files like `healthz.php` that can disappear on deploy.
3. **[2026-02-22] Preserve single-attendant invariant**
   Do instead: treat `services.attendants_number` as fixed to `1` unless product scope explicitly changes to multi-attendant.
4. **[2026-02-22] Never commit runtime secrets or local config**
   Do instead: keep sensitive values in local `config.php` and adjust `config-sample.php` only for safe defaults/documentation.
5. **[2026-03-02] Document OAuth callback URLs with default index page behavior**
   Do instead: use `.../index.php/google/oauth_callback` for default examples and explicitly mention rewrite-mode alternatives when `index.php` is removed.

## Shell & Command Reliability

1. **[2026-03-05] Pin pre-PR gate context explicitly for non-main targets**
   Do instead: set `PRE_PR_BASE_REF=<target-base>` for `scripts/ci/pre_pr_quick.sh`, `scripts/ci/pre_pr_full.sh`, or managed pre-push runs; add `PRE_PR_REQUEST_CONTRACTS_L2_BLOCKING=1` when strict local request-contracts L2 blocking is required.
2. **[2026-03-03] Run Deptrac via Docker for stable local output**
   Do instead: use `docker compose run --rm php-fpm composer deptrac:analyze` because newer host PHP runtimes can emit vendor-level deprecation noise.
3. **[2026-03-03] Scope `rg` to the repository/workspace root**
   Do instead: run `rg` from the project directory (or target folders explicitly) to avoid macOS permission noise and long scans across `~/Library`.
4. **[2026-03-02] When moving the repo root path, repair git + automation path dependencies**
   Do instead: stop compose, move repo, keep temporary symlink at old path, run `git worktree repair && git worktree prune`, then replace old path in `/Users/robinbeier/.codex/automations/*/automation.toml` `cwds`.
5. **[2026-02-26] Detect iCloud duplicate placeholders before release rsync**
   Do instead: check for `* 2.*`/`* 3.*` files (especially in `assets/vendor` and `vendor`) and remove/rehydrate them before `./build_release.sh`, because `rsync` can stall on FileProvider `compressed,dataless` entries.
6. **[2026-03-01] Fix MySQL InnoDB startup failures caused by FileProvider offload**
   Do instead: when logs show OS error 35 on `./#innodb_redo/*`, inspect `docker/mysql` with `ls -lO@`; rehydrate `compressed,dataless` files (for example `dd if='<file>' of=/dev/null bs=512 count=1`) and restart MySQL, or reset `docker/mysql` if recovery fails.
7. **[2026-02-25] Keep deploy archive ID aligned with `deploy_ea.sh --rel`**
   Do instead: build/upload `${REL}.tar.gz` (for example via `./build_release.sh --rel "$REL"`) before deploy; do not confuse DB rollback dumps like `predeploy-db-...sql.gz` with deploy archives.
8. **[2026-02-23] Use unique Compose project names per worktree**
   Do instead: start Docker with `docker compose -p <unique-name> ...` in each worktree and verify mounts via `docker inspect` before smoke-tests.
9. **[2026-02-22] Avoid brittle host-PHP assumptions for tests**
   Do instead: run tests via docker compose where `DB_HOST='mysql'` and container DNS match CI behavior.
10. **[2026-02-22] Prime new worktrees before first commit**
    Do instead: run `./scripts/setup-worktree.sh` to install `vendor/`, `node_modules/`, and vendor/theme assets.

## User Directives

1. **[2026-03-03] Keep `CONTINUITY.md` as canonical compaction-safe session briefing**
   Do instead: at the start of each turn read/update `CONTINUITY.md`, and refresh it immediately when goal, decisions, constraints, state, or key outcomes change.
2. **[2026-02-22] Follow AGENTS.md merge-ready contribution standard**
   Do instead: keep changes minimal and consistent with repo conventions, then report validation status and blockers clearly.
3. **[2026-02-22] Never revert unrelated dirty-worktree changes**
   Do instead: isolate edits to request scope and stop to ask if unexpected modifications appear in touched files.
4. **[2026-02-22] Keep collaboration updates concise and actionable**
   Do instead: send short progress updates during tool work and finish with changed files and test outcomes.
