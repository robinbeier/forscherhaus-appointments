# Napkin Runbook

## Curation Rules

-   Re-prioritize on every read.
-   Keep recurring, high-value notes only.
-   Max 10 items per category.
-   Each item includes date + "Do instead".
-   Keep items sorted by execution risk first.

## Execution & Validation (Highest Priority)

1. **[2026-03-07] Parse Symphony wrapper events from nested Codex payloads**
   Do instead: when debugging Symphony first-turn behavior, extract agent text from `params.msg.payload.*` and command text from `params.msg.{command,parsed_cmd}`, and merge streaming deltas with overlap detection because Codex wrapper updates may be cumulative rather than purely incremental.
1. **[2026-03-07] End Symphony publish turns immediately after the review handoff**
   Do instead: once PR creation, Linear attachment, workpad sync, and the state transition to `In Review` are complete, stop the active turn immediately; a publish turn that keeps running after the review handoff is a regression and wastes context.
1. **[2026-03-07] Align fresh Symphony issue worktrees with the Linear branch context**
   Do instead: before a serious Symphony pilot rerun on a real issue, recreate the preserved issue worktree from `origin/main` on the branch name Linear already shows for the ticket (or update Linear to the branch Symphony will use) so the prompt branch context and actual workspace branch do not drift apart.
1. **[2026-03-07] Keep Symphony state and commit rules explicit in the workflow prompt**
   Do instead: state near the top of `WORKFLOW.md` that `In Progress`/other implementation states must create a local commit before ending, while clean `In Review`/`Ready to Merge`/terminal runs may finish without a new commit.
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
1. **[2026-03-07] Read Symphony token totals as spend telemetry, not raw live context**
   Do instead: debug pilot efficiency primarily with `time-to-first-diff`, handoff timing, per-event `last.totalTokens`, and final stop reason; the logged `totalTokens` values are cumulative session spend and can exceed the model context window without meaning the live prompt is that large.
1. **[2026-03-07] Re-check final workpad state after Symphony review or merge handoffs**
   Do instead: after a real pilot run reaches `In Review` or `Done`, verify that the last `## Codex Workpad` comment matches the actual PR/merge outcome; a correct Linear state alone can still leave stale operator guidance behind.

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

1. **[2026-03-06] Invoke Symphony workflow hook scripts through `bash`**
   Do instead: configure `before_run`/`before_remove` entries as `bash $SYMPHONY_REPO_ROOT/.../script.sh` so pilot runs do not depend on the executable bit surviving checkout or local file-mode settings.
2. **[2026-03-05] Pin pre-PR gate context explicitly for non-main targets**
   Do instead: set `PRE_PR_BASE_REF=<target-base>` for `scripts/ci/pre_pr_quick.sh`, `scripts/ci/pre_pr_full.sh`, or managed pre-push runs; add `PRE_PR_REQUEST_CONTRACTS_L2_BLOCKING=1` when strict local request-contracts L2 blocking is required.
3. **[2026-03-03] Run Deptrac via Docker for stable local output**
   Do instead: use `docker compose run --rm php-fpm composer deptrac:analyze` because newer host PHP runtimes can emit vendor-level deprecation noise.
4. **[2026-03-03] Scope `rg` to the repository/workspace root**
   Do instead: run `rg` from the project directory (or target folders explicitly) to avoid macOS permission noise and long scans across `~/Library`.
5. **[2026-03-08] Keep pre-PR diff checks worktree-safe inside Symphony workers**
   Do instead: avoid hard `git fetch` writes from worker worktrees whose git common dir lives outside the writable workspace; fall back to existing `origin/*` refs or a local `HEAD~1...HEAD` diff range so quick/full gates stay usable under `workspace-write`.
6. **[2026-02-26] Detect iCloud duplicate placeholders before release rsync**
   Do instead: check for `* 2.*`/`* 3.*` files (especially in `assets/vendor` and `vendor`) and remove/rehydrate them before `./build_release.sh`, because `rsync` can stall on FileProvider `compressed,dataless` entries.
7. **[2026-03-01] Fix MySQL InnoDB startup failures caused by FileProvider offload**
   Do instead: when logs show OS error 35 on `./#innodb_redo/*`, inspect `docker/mysql` with `ls -lO@`; rehydrate `compressed,dataless` files (for example `dd if='<file>' of=/dev/null bs=512 count=1`) and restart MySQL, or reset `docker/mysql` if recovery fails.
8. **[2026-02-25] Keep deploy archive ID aligned with `deploy_ea.sh --rel`**
   Do instead: build/upload `${REL}.tar.gz` (for example via `./build_release.sh --rel "$REL"`) before deploy; do not confuse DB rollback dumps like `predeploy-db-...sql.gz` with deploy archives.
9. **[2026-02-23] Use unique Compose project names per worktree**
   Do instead: start Docker with `docker compose -p <unique-name> ...` in each worktree and verify mounts via `docker inspect` before smoke-tests.
10. **[2026-02-22] Avoid brittle host-PHP assumptions for tests**
    Do instead: run tests via docker compose where `DB_HOST='mysql'` and container DNS match CI behavior.

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
