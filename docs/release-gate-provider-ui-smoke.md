# Provider UI Smoke Release Gate

Purpose: verify the provider dashboard and both provider PDF exports in
production without reusing a real account or exposing real customer data.

This is an on-demand postdeploy gate. It is not a Kuma monitor, cron job, or
background browser. It does not change Kuma, ROB-432, DNS, routes, firewall, or
the production host's deliberate Node/npm absence.

## Security Invariant

The durable production object is a dormant smoke principal, not a standing
provider login:

- reserved username: `__ea_provider_ui_smoke_v1`
- fixture key: `prod-provider-ui-smoke-v1`
- credential file: `/etc/fh/release-gate-provider-ui-smoke.env`
- lease state directory: `/var/lib/fh-provider-ui-smoke` (`root:root`, `0700`)
- active lease state: `active.json` (`root:root`, `0600`, present only while
  the lease is active)

While dormant, the principal has no provider permissions and its database
password verifier and salt are `NULL`. The root-only credential file therefore
does not create a usable standing login.

`activate` installs the verifier only for a bounded ten-minute lease and creates
an exact synthetic provider/service/customer/appointment fixture. The lifecycle
uses direct internal writes with notifications, calendar synchronization,
webhooks, and public booking disabled. `deactivate` removes the entire ephemeral
fixture, clears the verifier again, removes the lease state, and verifies the
dormant/clean invariant.

The reserved principal is additionally contained at the central authenticated
controller boundary. During its short lease it may reach only:

- the provider dashboard;
- its own provider metrics;
- the provider preparation PDF;
- the existing provider parent-appointments PDF;
- logout.

Calendar, customer, account, API, booking, admin, and all other authenticated
routes are denied for this username. Privacy flags are defense in depth and are
not treated as authorization.

## Components

- `scripts/ops/provider_ui_smoke_principal.sh`: server-local, root-only
  credential and lifecycle wrapper.
- `scripts/ops/prod_provider_ui_smoke.sh`: operator-side production
  orchestrator with preflight, bounded activation, browser gate, and mandatory
  cleanup.
- `scripts/release-gate/provider_ui_smoke.php`: PII-free HTTP, browser, and PDF
  assertions.
- `scripts/release-gate/playwright/playwright_cli.sh`: operator-side Playwright
  runtime. It is never run on the production server.
- `php index.php console provider_ui_smoke ...`: CLI-only application
  lifecycle.

The credential file is a two-key INI file. Its keys are
`PROVIDER_UI_SMOKE_USERNAME` and `PROVIDER_UI_SMOKE_PASSWORD`; their values must
never be printed, copied into a command line, committed, attached to a report,
or put in a shell trace.

## Prerequisites

Before the first production run:

1. Deploy the guarded application, lifecycle command, gate, and both ops
   wrappers through the normal artifact-based rollout.
2. Confirm the normal read-only production baseline is green.
3. Confirm the active release contains both wrappers and the Provider UI gate.
4. Keep host Node/npm absent.
5. On the operator workstation, provide:
   - PHP;
   - `npx`;
   - `pdfinfo` and `pdftotext`;
   - `curl` and `ssh`;
   - a working `scripts/release-gate/playwright/playwright_cli.sh`.

The orchestrator installs or verifies the operator-side browser before it
activates the remote lease. A first-time browser download therefore cannot
consume the ten-minute production lease.

## One-Time Production Bootstrap

Run this only after the guarded code release is active:

```bash
ssh root@booking-server \
  'bash /var/www/html/easyappointments/scripts/ops/provider_ui_smoke_principal.sh install'
```

`install` is idempotent. If the credential is absent, the wrapper:

1. generates 32 random bytes with OpenSSL and stores the resulting 64 lowercase
   hexadecimal characters;
2. writes the INI atomically as `root:root` `0600`;
3. creates the state directory as `root:root` `0700`;
4. installs the permanent dormant principal;
5. runs the application verifier.

For an existing credential, the wrapper fails closed on a symlink, multiple
hard links, unexpected owner, mode, size, username, password shape, or extra
INI keys. It never repairs or prints an unsafe file.

The safe read-only recheck is:

```bash
ssh root@booking-server \
  'bash /var/www/html/easyappointments/scripts/ops/provider_ui_smoke_principal.sh verify'
```

## Run The Production Smoke

From the clean operator checkout of the deployed code:

```bash
bash scripts/ops/prod_provider_ui_smoke.sh \
  --prod-ssh-target root@booking-server \
  --output-json storage/logs/release-gate/provider-ui-smoke-run.json
```

The operator defaults to Chrome on macOS and Firefox elsewhere. Use
`--browser=firefox|chrome|webkit|msedge` only when the selected operator
runtime has been prepared and verified.

The orchestrator:

1. checks local runtime dependencies and prepares Playwright;
2. checks the app endpoint;
3. performs a read-only remote preflight, including credential/state
   permissions, deployed-wrapper/template shape, dormant principal
   verification, production Node/npm absence, and absence of an existing
   cleanup lease;
4. arms a transient `fh-provider-ui-smoke-cleanup.timer` with
   `systemd-run --on-active=10m`;
5. activates the synthetic lease;
6. binds the locally reviewed four-line preparation template to the active
   deployment by SHA-256, then streams `ssh ... cat` directly into the local
   gate's standard input;
7. re-reads the active template SHA-256 after the browser/PDF assertions and
   fails closed if the deployment changed during the gate;
8. always deactivates and verifies from an `EXIT`/signal cleanup handler;
9. stops and resets the transient cleanup unit only after synchronous
   `deactivate` and dormant `verify` both pass.

During operator-to-gate transfer, the credential is never stored in a local
temporary file, shell variable, command argument, process title, report, or
log. The production path is the only visible argument to `cat`.

The remote template itself is neither copied nor executed on the operator
workstation. Only its validated lowercase SHA-256 digest crosses the SSH
boundary. The gate requires an exact digest match with the clean operator
checkout and independently verifies the four note-line elements and their
four-row layout there. A stale checkout, a stale deployment, a symlink/hard-link
template, or a release switch during the run is therefore a hard gate failure.

The transient systemd timer is independent of the operator shell. It runs the
same secret-free server-local `deactivate` wrapper if the workstation loses
power, SSH disappears, or the process is killed before its shell cleanup.
An already-active unit with the same name is a hard preflight stop; it is not
overwritten or assumed stale.

## Assertions And Safe Evidence

The gate uses only the synthetic fixture and verifies:

- the provider dashboard and preparation/parent PDF buttons are present;
- the provider ID is taken from the authenticated session;
- the primary period contains exactly the synthetic `Booked` appointment;
- a cancelled in-period appointment and a booked out-of-period appointment do
  not enter the result;
- an empty period produces the expected empty UI/PDF state;
- the preparation PDF is landscape and contains the synthetic parent name and
  date/time; the exact active template is bound to the reviewed four-empty-line
  source contract by the bracketed SHA-256 check;
- the preparation PDF contains no email address, phone number, or appointment
  note marker;
- the existing parent PDF still contains its expected synthetic appointment
  fields;
- forbidden customer/provider integration keys are absent from `window.vars`
  and delivered responses;
- the browser makes no unexpected dynamic request outside the narrow dashboard
  and export allowlist.

Browser storage state and downloaded synthetic PDFs live only in a randomly
named local directory with mode `0700`; contained files use `0600` and are
deleted in `finally`. The gate produces no screenshots, traces, raw network
logs, response bodies, cookies, names, contact fields, appointment notes,
database IDs, or bearer URLs.

The optional JSON report contains only pass/fail booleans, bounded counts,
durations, PDF metadata, and stable assertion labels. It is evidence of the
gate result, not a credential or fixture snapshot.

## Exit And Cleanup Semantics

The operator orchestrator uses:

| Exit | Meaning |
| ---: | --- |
| `0` | Browser gate passed; remote principal is dormant; fixture is clean. |
| `1` | A functional/browser assertion failed; cleanup still passed. |
| `2` | Gate runtime or configuration failed; cleanup still passed. |
| `20` | Local or read-only remote preflight failed; no lease was activated. |
| `21` | Activation failed; compensating deactivate was attempted. |
| `22` | Independent cleanup timer could not be armed; activation did not proceed. |
| `90` | Hard stop: deactivate, dormant verification, or timer disarm failed. |

Exit `90` overrides the original gate result. Keep the guarded release active,
do not clear the transient unit by hand, and investigate server-locally without
printing credential, state, database, or log contents.

## Removal And Rollback

Normal runs retain both the dormant principal and root credential. To remove
the principal, owned fixture state, and now-unreferenced dormant role while
retaining the credential:

```bash
ssh root@booking-server \
  'bash /var/www/html/easyappointments/scripts/ops/provider_ui_smoke_principal.sh remove'
```

Credential retirement is a separate explicit action and is allowed only after
two successful, idempotent application-level `remove` transactions have
confirmed complete absence:

```bash
ssh root@booking-server \
  'bash /var/www/html/easyappointments/scripts/ops/provider_ui_smoke_principal.sh remove --remove-credentials'
```

The latter moves the credential into a root-only retired directory instead of
destroying it irreversibly. The standard `remove` path keeps it.

Before rolling back to any release that predates the reserved-account route
containment:

1. finish or abort the browser run and close its lease;
2. securely retain one already-authenticated smoke session only for the
   rollback assertion;
3. run full principal `remove`;
4. against the still-guarded release, prove that the captured session now gets
   HTTP `403`;
5. prove that a fresh login with the retained root credential fails;
6. only then switch the app release.

Do not substitute a successful dormant `verify` for those two negative
authentication assertions. If removal, captured-session rejection, or fresh
login rejection is unclear, hold the guarded release (or maintenance state) and
escalate. Never roll an active or unverifiably removed smoke principal back
into an unguarded application.

## Operational Boundary

Run this gate after a successful artifact switch and before final
`prod_validate_after_change` / `prod_doctor` evidence. Do not schedule it in
Kuma or cron. Do not use real provider credentials, clone real provider
settings, point the fixture at a real service/customer, or retain browser/PDF
artifacts after the gate.
