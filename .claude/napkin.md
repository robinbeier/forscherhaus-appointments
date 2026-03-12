# Napkin Runbook

## Curation Rules

- Re-prioritize on every read.
- Keep recurring, high-value notes only.
- Max 10 items per category.
- Each item includes date + "Do instead".
- Keep items sorted by execution risk first.

## Execution & Validation (Highest Priority)

1. **[2026-03-07] Parse Symphony wrapper events from nested Codex payloads**
   Do instead: when debugging Symphony first-turn behavior, extract agent text from `params.msg.payload.*` and command text from `params.msg.{command,parsed_cmd}`, merge streaming deltas with overlap detection because Codex wrapper updates may be cumulative rather than purely incremental, and sanity-check derived helper fields like `first_repo_target_path` before trusting them because slashy package identifiers can be misclassified as file targets.
1. **[2026-03-12] Treat Symphony status-surface scoring as optional unless operator minimums are unmet**
   Do instead: when re-scoring against the upstream SPEC, read the current section numbering and verify whether the status surface is explicitly optional before treating it as a `9.5/10` blocker; structured logs plus the machine-readable state API can satisfy Point 2's observability minimum even when `/` has no human-readable dashboard yet.
1. **[2026-03-07] End Symphony publish turns immediately after the review handoff**
   Do instead: once PR creation, Linear attachment, workpad sync, and the state transition to `In Review` are complete, stop the active turn immediately; a publish turn that keeps running after the review handoff is a regression and wastes context, and the resulting `reconciliation_non_active` / `turn_cancelled` stop is an expected success-path handoff, not a failure.
1. **[2026-03-07] Align fresh Symphony issue worktrees with the Linear branch context**
   Do instead: before a serious Symphony pilot rerun on a real issue, recreate the preserved issue worktree from `origin/main` on the branch name Linear already shows for the ticket (or update Linear to the branch Symphony will use) so the prompt branch context and actual workspace branch do not drift apart.
1. **[2026-03-08] Enforce Symphony campaign sequencing from real `blockedBy` states**
   Do instead: in upgrade campaigns, dispatch a Linear issue only when every `blockedBy` issue is already in a terminal state; treating only `Todo` blockers as blocking lets later tickets run out of order and weakens the whole batch.
1. **[2026-03-06] Preserve failed Symphony worktrees for inspection**
   Do instead: clean up issue worktrees only after successful runs; keep timed-out or failed workspaces on disk so you can inspect what the agent changed before it got stuck.
1. **[2026-03-07] Keep repo-local Symphony skills and napkin available inside worker worktrees**
   Do instead: sync `.codex/skills/` and `.claude/napkin.md` into each issue worktree, keep skill front matter YAML-valid, and treat these files as runtime dependencies rather than operator-only docs.
1. **[2026-03-07] Treat Symphony merge reconciliation as a possible success path**
   Do instead: after `gh pr merge` exits non-zero or a run stops with `reconciliation_terminal`, re-check GitHub and Linear before retrying because the PR may already be merged and the issue may already be `Done`.
1. **[2026-03-06] Keep Symphony Linear GraphQL queries aligned with current schema**
   Do instead: use `project.slugId` (not `project.slug`) and relation-based issue links (`relations`/`inverseRelations`) instead of removed fields like `blockedByIssues`; include response-body details for non-2xx tracker errors to speed up diagnosis.
1. **[2026-03-07] Read Symphony token totals as spend telemetry and interpret post-diff stops separately**
   Do instead: debug pilot efficiency primarily with `time-to-first-diff`, handoff timing, per-event `last.totalTokens`, and final stop reason; the logged `totalTokens` values are cumulative session spend and can exceed the model context window without meaning the live prompt is that large, and a `post_diff_checkpoint` that exhausts retry budget is a continuation-budget problem rather than proof that the code path itself failed, especially when the first observed diff is only governance/gate work such as ownership-map or pre-PR script updates.
1. **[2026-03-08] Re-check final workpad state and actual PR/Linear outcome after apparent post-push hangs**
   Do instead: when a Symphony run looks stuck after `git push`, verify the live state API, PR existence, and the last `## Codex Workpad` comment before intervening; the run may already have created the PR, moved the issue to `In Review`, and stopped correctly via `reconciliation_non_active`, while older first-turn summaries still describe an earlier validation blocker.

## Repo Guardrails & Domain Behavior

1. **[2026-02-22] Keep production code inside `application/`**
   Do instead: place feature/controller/model/view changes in `application/` and avoid edits in `system/` unless explicitly requested.
2. **[2026-03-09] Prefix helper functions in standalone CI scripts**
   Do instead: when adding `scripts/ci/*.php` files that unit tests may `require_once` together, give internal global helper functions a script-specific prefix so PHPUnit does not hit `Cannot redeclare function ...` collisions across multiple CLI scripts.
3. **[2026-02-25] Keep health endpoints versioned in app code**
   Do instead: implement monitoring endpoints as routes/controllers under `application/` and deploy them via release artifacts; avoid unmanaged root files like `healthz.php` that can disappear on deploy.
4. **[2026-02-22] Preserve single-attendant invariant**
   Do instead: treat `services.attendants_number` as fixed to `1` unless product scope explicitly changes to multi-attendant.
5. **[2026-02-22] Never commit runtime secrets or local config**
   Do instead: keep sensitive values in local `config.php` and adjust `config-sample.php` only for safe defaults/documentation.
6. **[2026-03-02] Document OAuth callback URLs with default index page behavior**
   Do instead: use `.../index.php/google/oauth_callback` for default examples and explicitly mention rewrite-mode alternatives when `index.php` is removed.

## Shell & Command Reliability

1. **[2026-03-10] Validate OpenClaw OAuth with `models status --check`, not JSON alone**
   Do instead: when OpenClaw reports `OAuth token refresh failed`, run `openclaw models status --check` because `--json` can still label an expired OAuth profile as `ok` while `--check` surfaces the real provider response such as `refresh_token_reused`.
2. **[2026-03-09] Run Symphony tests without `tsx` IPC in sandboxed workers**
   Do instead: when `npm --prefix tools/symphony test` fails with `listen EPERM` on `tsx` pipes, run `npm --prefix tools/symphony run build` and execute `node --test tools/symphony/dist/orchestrator.test.js` for deterministic local verification.
3. **[2026-03-06] Invoke Symphony workflow hook scripts through `bash`**
   Do instead: configure `before_run`/`before_remove` entries as `bash $SYMPHONY_REPO_ROOT/.../script.sh` so pilot runs do not depend on the executable bit surviving checkout or local file-mode settings.
4. **[2026-03-05] Pin pre-PR gate context explicitly for non-main targets**
   Do instead: set `PRE_PR_BASE_REF=<target-base>` for `scripts/ci/pre_pr_quick.sh`, `scripts/ci/pre_pr_full.sh`, or managed pre-push runs; add `PRE_PR_REQUEST_CONTRACTS_L2_BLOCKING=1` when strict local request-contracts L2 blocking is required.
5. **[2026-03-03] Run Deptrac via Docker for stable local output**
   Do instead: use `docker compose run --rm php-fpm composer deptrac:analyze` because newer host PHP runtimes can emit vendor-level deprecation noise.
6. **[2026-03-08] Keep pre-PR diff checks worktree-safe inside Symphony workers**
   Do instead: avoid hard `git fetch` writes from worker worktrees whose git common dir lives outside the writable workspace; fall back to existing `origin/*` refs or a local `HEAD~1...HEAD` diff range so quick/full gates stay usable under `workspace-write`, make sure no host stack is still binding the worktree's expected MySQL/HTTP ports before commit or pre-push hooks run, and re-check support containers before treating frontend/browser failures as code regressions because a stopped branch-local MySQL or app stack can mimic a broken dependency spike.
7. **[2026-03-08] Run lockfile-refresh gates from a committed lockfile baseline**
   Do instead: for branches whose main change is `package-lock.json`, commit the converged lockfile before running `pre_pr_quick` or `pre_pr_full`; the quick gate intentionally re-runs `npm install --package-lock-only` and compares the result to `HEAD`, so running it before the lockfile commit produces a false drift failure.
8. **[2026-03-08] Use containerized Playwright when host browser permissions are noisy**
   Do instead: if host Playwright/Chrome fails with Crashpad permission errors or Firefox SIGABRT, run a targeted browser smoke from `mcr.microsoft.com/playwright` against the branch-local stack via `host.docker.internal`; that gives real-browser evidence without depending on the host browser sandbox.
9. **[2026-03-01] Fix MySQL InnoDB startup failures caused by FileProvider offload**
   Do instead: when logs show OS error 35 on `./#innodb_redo/*`, inspect `docker/mysql` with `ls -lO@`; rehydrate `compressed,dataless` files (for example `dd if='<file>' of=/dev/null bs=512 count=1`) and restart MySQL, or reset `docker/mysql` if recovery fails.
10. **[2026-02-23] Use unique Compose project names per worktree**
    Do instead: start Docker with `docker compose -p <unique-name> ...` in each worktree and verify mounts via `docker inspect` before smoke-tests.

## User Directives

1. **[2026-02-22] Follow AGENTS.md merge-ready contribution standard**
   Do instead: keep changes minimal and consistent with repo conventions, then report validation status and blockers clearly.
2. **[2026-02-22] Never revert unrelated dirty-worktree changes**
   Do instead: isolate edits to request scope and stop to ask if unexpected modifications appear in touched files.
3. **[2026-02-22] Keep collaboration updates concise and actionable**
   Do instead: send short progress updates during tool work and finish with changed files and test outcomes.
4. **[2026-03-06] Execute multi-PR plans strictly one PR at a time**
   Do instead: for a plan with multiple PRs, push one PR, monitor it with [$babysit-pr](/Users/robinbeier/Developers/forscherhaus-appointments/.codex/skills/babysit-pr/SKILL.md) until CI is green, review-clean, and mergeable, merge it, then start the next PR.
5. **[2026-03-07] Lint Symphony PR bodies before creating or editing the PR**
   Do instead: render the final PR body into a file and run `npm --prefix tools/symphony run pr-body-check -- --file <body-file>` before `gh pr create` or `gh pr edit`.
