# Napkin Runbook

## Curation Rules
- Re-prioritize on every read.
- Keep recurring, high-value notes only.
- Max 10 items per category.
- Each item includes date + "Do instead".
- Keep items sorted by execution risk first.

## Execution & Validation (Highest Priority)
1. **[2026-02-22] Run CI-parity tests through docker compose before merge-sensitive changes**
   Do instead: execute `docker compose run --rm php-fpm composer test` and report failures or environment limits in handoff.
2. **[2026-02-22] Rebuild frontend bundles when touching `assets/js` or `assets/css`**
   Do instead: run `npx gulp scripts` and/or `npx gulp styles`, then verify updated artifacts in `build/`.
3. **[2026-02-22] Keep migration rollback path complete**
   Do instead: implement database changes only via CodeIgniter migrations and verify both migrate up and down behavior.

## Repo Guardrails & Domain Behavior
1. **[2026-02-22] Keep production code inside `application/`**
   Do instead: place feature/controller/model/view changes in `application/` and avoid edits in `system/` unless explicitly requested.
2. **[2026-02-22] Preserve single-attendant invariant**
   Do instead: treat `services.attendants_number` as fixed to `1` unless product scope explicitly changes to multi-attendant.
3. **[2026-02-22] Never commit runtime secrets or local config**
   Do instead: keep sensitive values in local `config.php` and adjust `config-sample.php` only for safe defaults/documentation.

## Shell & Command Reliability
1. **[2026-02-22] Prefer `rg` for fast search**
   Do instead: use `rg`/`rg --files` for code and file discovery; fall back only if unavailable.
2. **[2026-02-22] Avoid brittle host-PHP assumptions for tests**
   Do instead: run tests via docker compose where `DB_HOST='mysql'` and container DNS match CI behavior.
3. **[2026-02-22] Prime new worktrees before first commit**
   Do instead: run `./scripts/setup-worktree.sh` to install `vendor/`, `node_modules/`, and vendor/theme assets.

## User Directives
1. **[2026-02-22] Follow AGENTS.md merge-ready contribution standard**
   Do instead: keep changes minimal and consistent with repo conventions, then report validation status and blockers clearly.
2. **[2026-02-22] Never revert unrelated dirty-worktree changes**
   Do instead: isolate edits to request scope and stop to ask if unexpected modifications appear in touched files.
3. **[2026-02-22] Keep collaboration updates concise and actionable**
   Do instead: send short progress updates during tool work and finish with changed files and test outcomes.
