# ROB-407 Short Host-Only HSTS Live Gate

Status: prepared, live write not executed.

Scope: prepare the production live gate for the ROB-403 HSTS decision. This gate
may later add only the first cautious HSTS header:

```http
Strict-Transport-Security: max-age=300
```

This document does not activate HSTS. Any production write, Apache configtest,
Apache reload, and post-change validation require a separate explicit
production approval.

## Decision Source

ROB-403 recorded the HSTS policy decision:

- Start with short host-only HSTS.
- Use `max-age=300`.
- Do not set `includeSubDomains`.
- Do not set `preload`.
- Treat any longer `max-age`, `includeSubDomains`, or `preload` as later
  separate policy decisions.

## Target Change

The future live gate may add only this response header on the intended HTTPS
surfaces:

```http
Strict-Transport-Security: max-age=300
```

The target is intentionally short-lived. If rollback is needed, browsers that
already observed the header may still enforce HTTPS until their cached
`max-age` expires, but the residual client-side effect should be brief.

## Non-Goals

- No `includeSubDomains`.
- No `preload`.
- No longer `max-age`.
- No Content-Security-Policy change.
- No baseline-header redesign.
- No Certbot action.
- No UFW, SSH, Kuma, App, database, deploy, package, Docker,
  provider-firewall, or host reboot change.
- No raw Apache config, raw production config, secrets, tokens, passwords,
  DSNs, Push URLs, health-token values, DB rows, session/cache contents, or
  Kuma data in chat, docs, Linear, or PR output.

## Preconditions

Before any live write:

- A separate operator approval explicitly authorizes the ROB-407 production
  live gate, Apache configtest, Apache reload, and post-change validation.
- `bash scripts/ops/prod_validate_after_change.sh` passes.
- `bash scripts/ops/prod_doctor.sh` reports healthy classes for App, `www`,
  Monitor, renderer, deep health, services, containers, Kuma, logs, UFW, and
  expected listener posture.
- App HTTPS returns the expected success class.
- `www` HTTPS returns the expected success class.
- Monitor HTTPS returns the expected redirect or success class.
- HTTP-to-HTTPS redirect behavior remains present for App, `www`, and Monitor.
- Certbot certificate and renewal timer presence are clear.
- No known recovery or operator path requires plain HTTP.
- HSTS is currently missing on the intended HTTPS target surfaces.
- The Apache change can be expressed as a narrow header-only change.
- Rollback is clear before editing.

## Live Procedure

Do not execute this section without the separate approval above.

1. Capture read-only baseline:
   - `bash scripts/ops/prod_validate_after_change.sh`
   - `bash scripts/ops/prod_doctor.sh`
2. Confirm the target surfaces are healthy and HSTS is missing.
3. Apply only the short host-only HSTS header.
4. Run `apache2ctl configtest`.
5. Stop without reload if configtest fails.
6. Reload Apache only if configtest passes.
7. Verify header classes:
   - HSTS is present on the intended HTTPS target surfaces.
   - The effective HSTS class is short host-only.
   - `includeSubDomains` is absent.
   - `preload` is absent.
8. Run post-change validation:
   - `bash scripts/ops/prod_doctor.sh`
   - `bash scripts/ops/prod_validate_after_change.sh`
9. Record only class/flag evidence.

## Expected Post-Change Evidence

Allowed evidence classes:

- `posture_header.<surface>.hsts=present`
- `hsts.max_age=300`
- `hsts.include_subdomains=absent`
- `hsts.preload=absent`
- App, `www`, Monitor, renderer, and deep-health status classes.
- Kuma latest green class.
- Certbot/timer presence classes.
- UFW/listener classes.
- `validation=passed`

Not allowed:

- Raw Apache config.
- Raw production config.
- Secret-bearing file contents.
- Tokens, passwords, DSNs, Push URLs, health-token values.
- DB rows, session/cache contents, Kuma DB rows.

## Stop Conditions

Stop before live activation if:

- A separate production approval has not been given.
- Any relevant HTTPS surface is unhealthy.
- HTTP-to-HTTPS redirect behavior is unclear.
- Certbot certificate or renewal timer status is unclear.
- A recovery or operator path depends on plain HTTP.
- The target change cannot stay header-only.
- The intended target surfaces are ambiguous.
- HSTS is already present with an unexpected policy.
- The rollback path is unclear.
- Validation would require printing raw Apache config, secret-bearing files,
  tokens, passwords, DSNs, Push URLs, health-token values, DB rows,
  session/cache contents, or Kuma data.

Stop and roll back if after reload:

- Any public endpoint, deep health, renderer, or Kuma class fails.
- The HSTS header is missing from an intended target surface.
- The HSTS header contains anything other than the approved short host-only
  policy.
- `includeSubDomains` or `preload` appears.
- `prod_validate_after_change.sh` fails.

## Rollback

Rollback must be available before live edit:

1. Remove only the ROB-407 HSTS header change.
2. Run `apache2ctl configtest`.
3. Reload Apache only if configtest passes.
4. Re-run `bash scripts/ops/prod_doctor.sh`.
5. Re-run `bash scripts/ops/prod_validate_after_change.sh`.
6. Record only class/flag evidence.

Rollback does not instantly clear HSTS from browsers that already cached the
header. The chosen `max-age=300` keeps that residual client-side effect short.

## Acceptance Criteria

- This docs PR is merged before live execution.
- Live HSTS activation happens only after a separate explicit production
  approval.
- The applied policy is exactly short host-only HSTS with `max-age=300`.
- `includeSubDomains` and `preload` remain absent.
- Apache configtest passes before reload.
- Post-change production validation passes.
- Evidence remains redacted and class-based.
