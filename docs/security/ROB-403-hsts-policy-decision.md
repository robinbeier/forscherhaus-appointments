# ROB-403 Production HSTS Policy Decision

Status: decision recorded, live write not executed.

Scope: decide the production HSTS posture for Forscherhaus Appointments without
activating HSTS. Any Apache header change remains a separate live gate with
explicit production approval.

## Decision

Recommended first HSTS policy:

```http
Strict-Transport-Security: max-age=300
```

This is a cautious starter policy:

- Enable HSTS only on the HTTPS surfaces that are intentionally configured for
  Forscherhaus Appointments.
- Start with a short `max-age` of 300 seconds.
- Do not set `includeSubDomains` initially.
- Do not set `preload`.
- Treat later increases of `max-age` as separate follow-up decisions after the
  short policy has been observed in production.

## Rationale

HSTS improves browser-side HTTPS enforcement. Once a browser observes the
header, it upgrades future HTTP attempts for that host to HTTPS before making
the request. This reduces downgrade and first-hop manipulation risk for a
system that handles school appointment data and PII.

The operational risk is that HSTS is sticky by design. A long `max-age`,
`includeSubDomains`, or `preload` can make HTTPS or certificate mistakes harder
to recover from for returning users. ROB-403 therefore chooses a reversible
first step that provides real protection while keeping the blast radius small.

## Options Considered

### No HSTS

- Lowest operational risk.
- Leaves downgrade protection weaker.
- Acceptable as a temporary baseline, but not the preferred long-term posture.

### Short host-only HSTS

- Recommended first step.
- Protects returning browsers for the exact host while keeping recovery fast if
  HTTPS routing or certificates regress.
- Does not affect unrelated or future subdomains.

### Long host-only HSTS

- Stronger persistent browser protection.
- Higher recovery cost if HTTPS or certificate handling breaks.
- Reasonable only after the short policy is observed successfully.

### `includeSubDomains`

- Protects every subdomain under the registrable domain.
- Risky if any current or future subdomain is not HTTPS-ready.
- Deferred until the full subdomain inventory and recovery path are accepted.

### `preload`

- Enables browser preload-list behavior before first visit.
- Requires a strict long-lived policy and is slow to reverse.
- Not recommended for the first Forscherhaus Appointments HSTS gate.

## Production Preconditions For A Future Live Gate

Before enabling even the short policy:

- App HTTPS returns the expected success class.
- `www` HTTPS returns the expected success class.
- Monitor HTTPS returns the expected redirect or success class.
- HTTP-to-HTTPS redirects remain present for App, `www`, and Monitor.
- Certbot certificate and renewal timer are present.
- No known recovery or operator path requires plain HTTP.
- UFW remains active with the expected `22/80/443` public listener classes.
- Kuma latest state is green for all active monitors.
- The change can be applied as an Apache header-only live gate with configtest,
  reload, and rollback.

## Future Live Gate Shape

The first HSTS live gate should:

1. Re-run `bash scripts/ops/prod_validate_after_change.sh`.
2. Re-run `bash scripts/ops/prod_doctor.sh`.
3. Confirm HSTS is currently missing on the target HTTPS surfaces.
4. Add only the short host-only HSTS header.
5. Run `apache2ctl configtest`.
6. Reload Apache only after configtest passes.
7. Confirm the header presence class is present.
8. Re-run `bash scripts/ops/prod_validate_after_change.sh`.
9. Re-run `bash scripts/ops/prod_doctor.sh`.

ROB-407 records the concrete follow-up live-gate plan for this first short
host-only HSTS change.

Expected target header:

```http
Strict-Transport-Security: max-age=300
```

Explicit non-goals for the first live gate:

- No `includeSubDomains`.
- No `preload`.
- No CSP change.
- No Certbot action.
- No UFW, SSH, Kuma, App, DB, deploy, package, Docker, provider-firewall, or
  reboot change.

## Stop Conditions

Stop before live activation if:

- Any relevant HTTPS surface is unhealthy.
- HTTP-to-HTTPS redirect behavior is unclear.
- Certbot certificate or renewal timer status is unclear.
- A recovery or operator path depends on plain HTTP.
- The target Apache change cannot be expressed as a narrow header-only change.
- `apache2ctl configtest` fails.
- Validation would require printing raw Apache config, secret-bearing files,
  tokens, passwords, DSNs, Push URLs, health-token values, DB rows,
  session/cache contents, or Kuma data.

Stop and roll back if after reload:

- Any public endpoint, deep health, renderer, or Kuma class fails.
- The HSTS header is missing or contains unexpected directives.
- `includeSubDomains` or `preload` appears unintentionally.
- `prod_validate_after_change.sh` fails.

## Rollback

Rollback for the first live gate:

1. Remove only the HSTS header line added for ROB-403 follow-up.
2. Run `apache2ctl configtest`.
3. Reload Apache only if configtest passes.
4. Re-run `bash scripts/ops/prod_validate_after_change.sh`.
5. Re-run `bash scripts/ops/prod_doctor.sh`.

Because browsers may cache HSTS for `max-age`, rollback does not immediately
clear HSTS from clients that already observed the header. The short initial
`max-age=300` keeps this residual client-side effect brief.

## Follow-Up Policy

After the short policy has been observed without incidents:

- Consider increasing to `max-age=86400`.
- Consider a later longer host-only policy only after operational confidence is
  established.
- Reconsider `includeSubDomains` only with an explicit subdomain inventory and
  accepted recovery plan.
- Do not pursue `preload` unless the domain is intentionally ready for long-term
  preload-list commitments.

## Evidence Boundary

Allowed evidence:

- Header presence classes.
- HTTPS and redirect status classes.
- Certbot timer/certificate presence classes.
- UFW/listener classes.
- Health status classes.
- `validation=passed|failed`.

Not allowed:

- Raw Apache config.
- Secret-bearing file contents.
- Tokens, passwords, DSNs, Push URLs, health-token values.
- DB rows, session/cache contents, Kuma DB rows.
