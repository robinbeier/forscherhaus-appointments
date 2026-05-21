# ROB-292 Production Security Hardening Plan

## Operating Model

Work milestone by milestone. A milestone is complete only when:

- the Linear issue boundary is clear;
- the diff is scoped to that issue;
- narrow validation passed or a blocker is recorded;
- `Documentation.md` is updated;
- any live write need is handled as an explicit gate.

Do not start the next milestone while the current milestone has unresolved red
validation unless the failure is unrelated and explicitly recorded as a
blocker.

## Milestone 0 - Current Baseline

Status: completed by ROB-393 before this coordination package starts.

### Scope

- Treat the live sensitive-path Apache block as present but host-local.
- Treat session/cache quarantine as completed evidence, not a repo artifact.
- Keep all future references sanitized: status classes and counts only.

### Validation Reference

- Sensitive paths returned `403` after ROB-393.
- Direct sample probes returned `403`.
- `prod_validate_after_change.sh` passed.
- Uptime Kuma was 13/13 green.

## Milestone 1 - ROB-394 Sensitive-Path Regression Gates

### Scope

- Add a repeatable, redacted check for sensitive public paths.
- Prefer shared helper logic if both `prod_doctor.sh` and
  `prod_validate_after_change.sh` need the same classification.
- Check path classes only: status code, directory-listing marker, body-size
  class when useful, and sensitive-marker presence. Do not print paths to
  individual files, file names, or response bodies.

### Acceptance Criteria

- The check fails or reports a clear stop condition if `storage/**`,
  `storage/sessions/**`, `storage/cache/**`, `storage/logs/**`, `vendor/**`,
  `config.php`, `application/**`, or `system/**` is publicly listable or
  directly readable.
- Existing app, health, deep-health, Kuma, and renderer checks remain unchanged.
- The check is documented as read-only and secret-safe.

### Validation

- `bash -n` for changed shell scripts.
- Targeted local/static test if a helper is introduced.
- `git diff --check`.
- Targeted secret grep over changed docs/scripts.
- `bash ./scripts/ci/pre_pr_quick.sh` if scripts changed materially.

### Stop Conditions

- A probe would need to print response contents, file names, or secret-bearing
  values.
- The check would require a production write to prove behavior.

## Milestone 2 - ROB-395 Production Server Threat Model

### Scope

- Add a durable Markdown threat model, likely
  `docs/security/production-server-threat-model.md`.
- Cover production server, deployment, network boundaries, secrets, monitoring,
  backups, runtime isolation, and server-specific attacker stories.
- Link ROB-393 through ROB-397 as follow-up work.

### Acceptance Criteria

- Confirmed observations, plausible risks, explicit non-findings, and skipped
  checks are separated.
- No secrets, raw config, DB rows, session/cache contents, raw Push URLs, or raw
  Kuma DB state are recorded.
- The document distinguishes repo-only work from live gates.

### Validation

- `git diff --check`.
- Secret/PII grep over changed docs.

## Milestone 3 - ROB-396 Baseline Server Posture Decision

### Scope

- Read-only review of headers, SSH policy classes, firewall posture, and public
  port classes.
- Produce a decision: implement, defer, or intentionally leave unchanged.
- Split any approved live changes into separate small gates instead of rolling
  them into this coordination package.

### Acceptance Criteria

- HSTS is treated cautiously and only recommended after HTTPS/subdomain impact
  is explicit.
- SSH and firewall recommendations include rollback/stop conditions.
- No live change happens without operator approval for that specific change.

### Validation

- Redacted read-only production snapshot if needed.
- `git diff --check` if docs are changed.
- Secret grep over changed docs.

### Stop Conditions

- A recommended change could lock out SSH or break certificate renewal without
  a tested rollback.
- A check requires printing raw Apache, SSH, firewall, or `/etc/fh` config.

## Milestone 4 - ROB-397 Redacted Prod Doctor Posture Checks

### Scope

- Extend the production operations harness with safe posture facts discovered
  during ROB-393 and ROB-394.
- Keep output to classes/flags/counts.
- Update `docs/ops/agent-operations.md` and `scripts/ops/README.md` if the
  operator entrypoint changes.

### Acceptance Criteria

- `prod_doctor.sh` or a focused helper reports sensitive-path posture without
  file names or contents.
- Optional posture facts may include header presence, SSH policy classes,
  public/loopback port classes, UFW status, and Sentry env presence flags.
- The script remains read-only.

### Validation

- `bash -n` for changed shell scripts.
- Narrow helper tests if added.
- `bash ./scripts/ci/pre_pr_quick.sh`.
- One redacted production run may be used to prove output shape.

### Stop Conditions

- Output would reveal secrets, raw config, raw IP detail beyond approved status
  classes, or production file names.
- A live write would be needed.

## Final Review Package

- Keep each milestone in its own branch/PR unless the operator explicitly
  approves combining docs-only work.
- Before marking review-ready, run the narrowest relevant checks plus the repo
  quick gate when scripts changed.
- Record final status and residual gates in `Documentation.md`.
