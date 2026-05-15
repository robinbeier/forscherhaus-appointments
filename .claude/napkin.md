# Napkin Runbook

## Curation Rules

- Re-prioritize on every read.
- Keep recurring, high-value notes only.
- Max 10 items per category.
- Each item includes date + "Do instead".
- Keep items sorted by execution risk first.

## Repo Guardrails & Domain Behavior

1. **[2026-02-22] Keep production code inside `application/`**
   Do instead: place feature/controller/model/view changes in `application/` and avoid edits in `system/` unless explicitly requested.
2. **[2026-03-17] Keep dashboard capacity wording tied to planned slots**
   Do instead: when touching dashboard badges, hints, or exports, describe capacity as planned working-plan capacity for the selected period, not live remaining availability after bookings, and regression-test both the metric seam and the user-visible label seam together.
3. **[2026-03-09] Prefix helper functions in standalone CI scripts**
   Do instead: when adding `scripts/ci/*.php` files that unit tests may `require_once` together, give internal global helper functions a script-specific prefix so PHPUnit does not hit `Cannot redeclare function ...` collisions across multiple CLI scripts.
4. **[2026-02-25] Keep health endpoints versioned in app code**
   Do instead: implement monitoring endpoints as routes/controllers under `application/` and deploy them via release artifacts; avoid unmanaged root files like `healthz.php` that can disappear on deploy.
5. **[2026-02-22] Preserve single-attendant invariant**
   Do instead: treat `services.attendants_number` as fixed to `1` unless product scope explicitly changes to multi-attendant.
6. **[2026-02-22] Never commit runtime secrets or local config**
   Do instead: keep sensitive values in local `config.php` and adjust `config-sample.php` only for safe defaults/documentation.
7. **[2026-03-02] Document OAuth callback URLs with default index page behavior**
   Do instead: use `.../index.php/google/oauth_callback` for default examples and explicitly mention rewrite-mode alternatives when `index.php` is removed.
8. **[2026-03-19] Disable app rate limiting inside zero-surprise predeploy stage configs**
   Do instead: when preparing the isolated predeploy stage from `config-sample.php`, patch both `BASE_URL` and `Config::RATE_LIMITING = false`; replay traffic routed through `http://nginx` can otherwise hit the global rate limiter and fail with `429` before the real deploy starts.

## Shell & Command Reliability

1. **[2026-03-14] Remove linked worktrees before deleting merged local branches**
   Do instead: when a merged branch is attached to a linked worktree, run `git worktree remove <path>` first; in `workspace-write` sandboxes this can unregister the worktree even if directory deletion is denied, so re-check `git worktree list` and branch refs, then treat leftover directories as manual cleanup.
2. **[2026-03-10] Validate OpenClaw OAuth with `models status --check`, not JSON alone**
   Do instead: when OpenClaw reports `OAuth token refresh failed`, run `openclaw models status --check` because `--json` can still label an expired OAuth profile as `ok` while `--check` surfaces the real provider response such as `refresh_token_reused`.
3. **[2026-03-05] Pin pre-PR gate context explicitly for non-main targets**
   Do instead: set `PRE_PR_BASE_REF=<target-base>` for `scripts/ci/pre_pr_quick.sh`, `scripts/ci/pre_pr_full.sh`, or managed pre-push runs; add `PRE_PR_REQUEST_CONTRACTS_L2_BLOCKING=1` when strict local request-contracts L2 blocking is required.
4. **[2026-03-03] Run Deptrac via Docker for stable local output**
   Do instead: use `docker compose run --rm php-fpm composer deptrac:analyze` because newer host PHP runtimes can emit vendor-level deprecation noise.
5. **[2026-03-08] Run lockfile-refresh gates from a committed lockfile baseline**
   Do instead: for branches whose main change is `package-lock.json`, commit the converged lockfile before running `pre_pr_quick` or `pre_pr_full`; the quick gate intentionally re-runs `npm install --package-lock-only` and compares the result to `HEAD`, so running it before the lockfile commit produces a false drift failure.
6. **[2026-03-08] Use containerized Playwright when host browser permissions are noisy**
   Do instead: if host Playwright/Chrome fails with Crashpad permission errors or Firefox SIGABRT, run a targeted browser smoke from `mcr.microsoft.com/playwright` against the branch-local stack via `host.docker.internal`; that gives real-browser evidence without depending on the host browser sandbox.
7. **[2026-03-01] Fix MySQL InnoDB startup failures caused by FileProvider offload**
   Do instead: when logs show OS error 35 on `./#innodb_redo/*`, inspect `docker/mysql` with `ls -lO@`; rehydrate `compressed,dataless` files (for example `dd if='<file>' of=/dev/null bs=512 count=1`) and restart MySQL, or reset `docker/mysql` if recovery fails.

## User Directives

1. **[2026-02-22] Follow AGENTS.md merge-ready contribution standard**
   Do instead: keep changes minimal and consistent with repo conventions, then report validation status and blockers clearly.
2. **[2026-02-22] Never revert unrelated dirty-worktree changes**
   Do instead: isolate edits to request scope and stop to ask if unexpected modifications appear in touched files.
3. **[2026-02-22] Keep collaboration updates concise and actionable**
   Do instead: send short progress updates during tool work and finish with changed files and test outcomes.
4. **[2026-03-06] Execute multi-PR plans strictly one PR at a time**
   Do instead: for a plan with multiple PRs, push one PR, monitor it with [$babysit-pr](/Users/robinbeier/Developers/forscherhaus-appointments/.codex/skills/babysit-pr/SKILL.md) until CI is green, review-clean, and mergeable, merge it, then start the next PR.
