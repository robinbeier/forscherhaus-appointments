# AGENTS.md - Forscherhaus Appointments

Purpose: durable repo instructions for Codex. Keep this file short; long or topic-specific details belong in canonical docs.

## Canonical Sources

- `README.md`: onboarding, local setup, services, shortest operator path.
- `WORKFLOW.md`: agent runtime, Linear states, Codex Workpad, and ticket-to-merge flow.
- `code_review.md`: canonical review priorities, findings format, and repo-specific review checks.
- `docs/agent-harness-index.md`: routing across CI, architecture, ownership, Symphony, and specialist docs.
- `.github/workflows/ci.yml`: source of truth for CI triggers, blocking status, and artifacts.

## Hard Rules

- Keep production code in `application/`.
- Do not edit `system/` unless applying an explicit upstream patch.
- Use CodeIgniter migrations for DB schema changes, including rollback.
- Never commit secrets or local credentials; keep `config.php` local.
- Treat `services.attendants_number` as fixed to `1` unless product scope changes explicitly.
- If `docs/maps/component_ownership_map.json` marks a component as `single-owner` or `manual_approval_required`, keep changes narrow and conservative.
- Prefer small, mergeable, low-risk diffs over broad rewrites, speculative cleanup, or wide refactors.

## Default Path

Start here for most review-ready changes:

```bash
./scripts/setup-worktree.sh
docker compose run --rm php-fpm composer test
PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh
```

Faster local gate:

```bash
bash ./scripts/ci/pre_pr_quick.sh
```

## Topic Routing

- Docker stack, local services, dump restore, PDF renderer, LDAP, Baikal: `docs/docker.md`
- Console commands: `docs/console.md`
- Write-path contracts: `docs/ci-write-contracts.md`
- Release gates: `docs/release-gate-dashboard.md`, `docs/release-gate-booking-confirmation-pdf.md`, `docs/release-gate-zero-surprise.md`
- Observability and ops monitors: `docs/observability.md`, `scripts/ops/README.md`
- Architecture and ownership: `docs/architecture-map.md`, `docs/ownership-map.md`, `docs/maps/component_ownership_map.json`
- Symphony runtime and pilot docs: `tools/symphony/README.md`, `docs/symphony/STAGING_PILOT_RUNBOOK.md`

## Working Defaults

- `composer test` may create `config.php` from `config-sample.php` if missing.
- `DB_HOST='mysql'` is the Docker-default path; host-side PHP needs host-compatible DB config.
- With host PHP and Docker PDF renderer, set `PDF_RENDERER_URL=http://localhost:3003`.
- Use a unique Docker Compose project name per worktree.
- Prefer `docker compose run --rm php-fpm composer deptrac:analyze` over host `composer deptrac:analyze`.
- For Symphony pilot readiness, run `bash ./scripts/ci/run_symphony_pilot_checks.sh`; append `--with-full-gate` only when you also need the repo-wide pre-PR baseline.
- `./scripts/setup-worktree.sh` installs managed hooks; refresh with `./scripts/install-git-hooks.sh` (or replace older custom hooks with `FORCE_HOOK_INSTALL=1 ./scripts/install-git-hooks.sh`).
- `bash ./scripts/ci/pre_pr_full.sh` enables LDAP guardrails only when LDAP/runtime paths changed; override explicitly via `PRE_PR_INCLUDE_LDAP_GUARDRAIL=1` or `0`.
- On cold local Docker stacks, `bash ./scripts/ci/pre_pr_full.sh` may need longer Playwright startup via `PRE_PR_INTEGRATION_SMOKE_BROWSER_BOOTSTRAP_TIMEOUT=600` and `PRE_PR_INTEGRATION_SMOKE_BROWSER_OPEN_TIMEOUT=60`.
- For deterministic LDAP fixtures, run `bash ./scripts/ldap/reset_directory.sh` and `bash ./scripts/ldap/smoke.sh`.
- For local production dump restore, run `bash ./scripts/import_prod_backup.sh` (or append `--core-services-only` to keep only mysql/php-fpm/nginx running).
- For conservative local cleanup, run `bash ./scripts/cleanup_local_artifacts.sh`; append `--with-deps` only when you also want to remove reproducible dependency directories.

## Validation Expectations

- Before review, run the narrowest relevant checks plus the full pre-PR gate for review-ready changes.
- Bei Bugfixes nach Moeglichkeit einen passenden Regressionstest ergaenzen.
- Update docs when setup, behavior, validation expectations, or routing change.
- CI truth lives in `.github/workflows/ci.yml`; do not duplicate long job lists here.
- If a validation flow needs detailed repro steps, keep them in the topic doc and link from here.

## PR Expectations

- Keep commits short, imperative, and scoped.
- Multi-PR work stays sequential: finish and merge one PR before starting the next.
- Link Symphony or infrastructure PRs to Linear only when they truly belong to that issue.
- For UI-visible changes, update supporting docs or evidence when the repo workflow expects it.

## Agent Notes

- `WORKFLOW.md` is the canonical source for Linear states, publish/merge flow, and Codex Workpad behavior.
- `README.md` stays operator-first.
- `code_review.md` defines the durable review rubric for `/review` and normal Codex turns.
- `docs/agent-harness-index.md` stays the routing map.
- Use nested `AGENTS.md` files only when a subtree genuinely needs stricter local rules than the repo root.

## Harness Guardrails

- Keep references between `README.md`, `WORKFLOW.md`, `AGENTS.md`, and `docs/agent-harness-index.md` intact.
- When CI truth changes, update `.github/workflows/ci.yml` first and then only the shortest necessary summaries here.
- Do not remove the `docs/agent-harness-index.md` reference from this file.
- Do not turn this file back into a duplicate command matrix or CI explainer.

## Anti-Drift Rule

Keep this file as a compact operating contract.
If a section turns into a long checklist, command matrix, troubleshooting guide, or CI explainer, move that detail into the canonical topic doc and leave only a short summary plus link here.
