# ROB-292 Production Security Hardening Implementation Instructions

## Start Here

1. Read `Prompt.md`.
2. Read `Plan.md`.
3. Read `Documentation.md`.
4. Read the current Linear issue for the milestone.
5. Confirm the current branch and worktree state with `git status --short`.

## Execution Rules

- Work only on the current milestone.
- Keep each real fix tied to its Linear issue.
- Keep each repo change in a separate small PR unless the operator explicitly
  approves combining docs-only milestones.
- Preserve unrelated user changes.
- Do not edit `system/` unless applying an explicit upstream patch.
- Do not commit secrets or local credentials.

## Production Access Rules

Default production access is read-only:

- allowed: existing read-only ops scripts, service status, health endpoints,
  redacted log summaries, redacted path/status probes;
- not allowed: Apache changes, SSH changes, firewall changes, deploys, DB
  writes, Kuma writes, Sentry writes, printing secrets, printing DB rows,
  printing raw production config.

Live write gates require explicit operator approval and a rollback or stop
plan. A previous ROB-393 approval does not grant future write permission for
ROB-394 through ROB-397.

## Linear Rules

- ROB-393 is completed context.
- ROB-394 through ROB-397 are the active roadmap issues.
- Use the single persistent `## Codex Workpad` comment when directly updating
  issue progress.
- If Linear is unavailable but the issue boundary is already clear, continue
  repo-only work and record the pending Linear update in `Documentation.md`.

## Validation Loop

For every milestone:

1. Run the narrowest relevant checks.
2. Run `git diff --check`.
3. Run targeted secret/PII checks over changed docs/scripts.
4. Run `bash ./scripts/ci/pre_pr_quick.sh` when code or ops scripts changed.
5. Record validation in `Documentation.md`.

## Secret-Safe Output Rules

Never print or persist:

- session file names or contents;
- cache file names or contents;
- DB rows or dumps;
- Push URLs;
- health tokens;
- Sentry DSNs or auth tokens;
- `/etc/fh` contents;
- `config.php` contents;
- raw Kuma database rows;
- raw production Apache/SSH/firewall config.

Use sanitized classes instead: present/absent, public/loopback, 200/403/404,
directory-listing yes/no, sensitive-marker yes/no, active/inactive, green/red.

## Stop Conditions

Stop and ask before continuing if:

- a step would reveal or store a secret;
- a step would require a live write not explicitly approved for the current
  milestone;
- a check would need to print file names or response bodies from production;
- a security hardening change could break SSH access, app routing, health
  checks, Certbot renewal, or Kuma monitoring without a rollback;
- validation fails for a reason that cannot be repaired inside the current
  milestone.

## Final Reporting

Each milestone final report must include:

- Linear issue ID;
- files changed;
- validation run and result;
- live gates executed or intentionally not executed;
- remaining risk and next issue.
