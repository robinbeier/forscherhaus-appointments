# Provider UI Smoke Release Gate

> **Retirement completed (2026-10-12 06:30 UTC):** the separately approved,
> independently reviewed one-shot operator used the hash-bound wrapper from
> the active older release under the shared production lock. It verified the
> dormant principal, then ran `remove --remove-credentials` exactly once.
> Independent checks found no reserved principal, role, note, service, or
> appointment; the live credential path is absent and its unchanged file is
> retained in a root-only recovery directory. The original installed wrapper
> remains in the active older app release until a later app deployment, but
> re-bootstrap is not authorized. See the ROB-812 Workpad for identities,
> hashes, cleanup counts, and health checks. Local HTTP, authorization, and
> PDF-handoff tests remain.

**Status (2026-10-12): production principal and credential retired.** This
optional production smoke is not a required release gate. The source change
removes its lifecycle commands and browser gate. The repository's local HTTP,
authorization, and PDF-handoff tests remain active.
Before retirement, an interrupted run could leave an active synthetic login
that ordinary backup or deployment did not veto. The principal was verified
dormant and clean before its controlled removal. The instructions below document the
historical design and are not authority for another production run.

Local provider HTTP tests cover authorization and the renderer handoff;
the shared PDF monitor and release canary cover the common renderer path.
They do not prove the provider-specific browser journey or both printable
PDFs on production. Record that gap rather than treating another check as
equivalent.

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

Fixture ownership is deliberately narrow. Appointment and principal notes,
customer email, and service name/description fields are not global ownership
markers: unrelated rows carrying the same text must never block the Provider
UI fixture. The dormant principal is identified through its dedicated role;
while active it is bound to the exact state-file ID or, after state loss, to
the root-held credential verifier. Username, email, and notes are editable and
cannot establish ownership. The synthetic appointment marker is bound to that provider.
The private synthetic customer uses `email = NULL`, and its server-side notes
sentinel is checked only on the run-owned customer ID. If the state file is
lost, the customer and service IDs are reconstructed from the reserved
provider's exact appointments and service relation, not from global text
searches. Unrelated rows with matching text cannot claim fixture ownership or
block cleanup.
This prevents cleanup and verification from claiming unrelated real data while
preserving the no-email production contract.

`activate` installs the verifier only for a bounded ten-minute lease and creates
an exact synthetic provider/service/customer/appointment fixture. The lifecycle
uses direct internal writes with notifications and public booking
disabled. `deactivate` removes the entire ephemeral
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
- `scripts/ops/libexec/provider_ui_smoke_admission_v1.py`: server-local
  admission coordinator for the durable maintenance-pending record.
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

1. Deploy the guarded application, lifecycle command, coordinator, gate, and
   both ops wrappers as one reviewed, hash-bound set. Install the compatible
   maintenance-pending core and protocol epoch first under the ROB-812 rollout
   contract. An app release alone does not complete this prerequisite.
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

The wrapper registers a pending maintenance run before creating a credential
or state directory. Every mutating application action holds the canonical
production lock throughout its transaction and verifies that exact run through
the inherited lock descriptor. Direct mutating `console provider_ui_smoke`
calls without this capability are rejected. A failed or unknown operation can
leave the pending record for explicit recovery; do not retry it blindly.

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

An operator-only diagnostic revision may run from a later clean, reviewed
`main` without redeploying the application only if the diff from the deployed
commit contains **only** the local gate, its diagnostic helper, the release
artifact validator's required-path list, their tests, and this documentation.
Bind both commits, verify that the active release and its
preparation-template SHA-256 still match the existing preflight, and keep the
remote wrapper, browser snippet, fixture contract, and application bytes
unchanged. Any wider diff or identity mismatch requires a new release review
or stops the probe. A successful local diagnostic change is not itself a
production proof.

The orchestrator:

1. checks local runtime dependencies and prepares Playwright;
2. checks the app endpoint;
3. performs a read-only remote preflight, including credential/state
   permissions, deployed-wrapper/template shape, production Node/npm absence,
   and absence of an existing cleanup lease;
4. binds the deployed template, proves the principal is dormant while holding
   the private and shared admission locks, then registers the durable pending
   run. A failed dormant proof publishes no pending run and does not proceed;
5. arms a transient `fh-provider-ui-smoke-cleanup.timer` with
   `systemd-run --on-active=10m`, and durably acknowledges its exact loaded
   identity under the private and shared locks before activation;
6. activates the synthetic lease;
   the server rechecks the acknowledged, still-waiting timer under the same
   shared lock as the fixture transaction immediately before activation. It
   reads the timer's monotonic deadline through systemd's D-Bus API and
   requires at least five minutes of remaining cleanup time. An expired,
   nearly expired, or unknown deadline refuses activation without rearming
   the timer;
7. binds the locally reviewed four-line preparation template to the active
   deployment by SHA-256, then streams `ssh ... cat` directly into the local
   gate's standard input;
8. re-reads the active template SHA-256 after the browser/PDF assertions and
   fails closed if the deployment changed during the gate;
9. deactivates and verifies from an `EXIT`/signal cleanup handler;
10. stops and resets the transient cleanup unit only after synchronous
    `deactivate` and dormant `verify` both pass;
11. clears the pending run only after a fresh, lock-held terminal proof bound
    to the durable timer acknowledgement. Unknown arm or acknowledgement
    results leave the pending veto for deliberate recovery.

An intact, root-bound `active.json` contains the exact provider ID needed for
`deactivate`; that cleanup must still disable the live fixture if the protected
credential file is missing or malformed. Reconstruction after state-file loss
requires the credential verifier and fails closed without it. The subsequent
independent dormant `verify` still requires a valid credential file, so a
missing or damaged file may retain Pending after the active fixture has been
disabled; it does not authorize clearing the veto without repair and proof.
Likewise, a `removed` result requires the valid protected credential: the
verifier must exclude an active principal whose state and now-unreferenced
dormant role were both lost. `install`, `remove`, and `verify` fail closed on
that orphan instead of accepting absence of editable marker text as proof.

The systemd proof binds the cleanup service's complete command to this app,
credential, state directory and run ID. If pending was already cleared but an
interruption left only the acknowledgement, the separate `recover-arm-ack`
action retains its original boot binding and requires a fresh terminal proof
before removing that orphan, including after a reboot. It is not used to infer
success from an unknown timer-arm result. A lost arm acknowledgement instead
leaves pending in place. An explicit server-local `recover-unarmed` action may
clear only an exact smoke record with no acknowledgement after both bound units
are terminal or absent and a fresh dormant proof succeeds under both locks;
ordinary settlement never clears a missing-ack record. A previous operator's root-owned,
empty private lock at mode `0644` is narrowed in place to `0600` under that
lock before the coordinator admits work.

An interrupted `install` before fixture creation has its own explicit
server-local `recover-install-empty` action. It accepts the exact install
record from the current or previous boot only after proving that the
acknowledgement and its temporary files are absent, no credential temporary
file exists, and the PHP verifier reports the `removed` state. A pre-existing
valid credential file may remain untouched; this recovery never deletes or
repairs it. Dormant or partial state, a credential temporary, or any
run/resource binding mismatch retains the pending veto. This recovery is
distinct from the smoke path and is not an automatic retry.

An interrupted `remove` that left the fixture fully dormant can use the
separate server-local `recover-remove-dormant` abort action for the exact
current- or prior-boot remove run. It holds the private and shared locks,
proves that no arm acknowledgement or temporary credential file remains,
that cleanup units are absent or terminal, and that the PHP verifier still
reports `dormant` before clearing Pending. It does not retry removal, delete
credentials, or accept an active, removed, partial, or unknown fixture. If
the remove transaction committed, ordinary settlement instead requires the
`removed` proof.

If ACK publication stops after writing its temporary file but before creating
the final hardlink, `recover-interrupted-arm-ack-temp` is the explicit
server-local path. It accepts only the sole root-owned, run-bound temporary
ACK with no final name. Under the private and shared locks, it requires no
credential temporary, terminal cleanup units, and a fresh dormant proof before
removing that exact temporary name and settling the pending run with another
proof. A crash after its removal can be handled by `recover-unarmed`; unknown
content, identity, or extra temporaries retain the veto.

If ACK publication is interrupted after the final hardlink appears but before
the temporary name is removed, `recover-interrupted-arm-ack` is the explicit
server-local path. Under the private and shared locks, it requires the exact
two-link final/temporary inode and ACK content bound to the pending run, no
credential temporary, terminal cleanup units, and a fresh dormant proof. It
removes only the verified temporary name, then settles the same pending run;
an extra link, mismatched content, active unit, or unknown fixture retains the
veto. This is not an automatic rerun of the smoke.

If settlement has already created the core's immutable `clear-state.json`
marker and is interrupted, the separate server-local `recover-clear-marker`
action may finish only that exact transition. It validates the marker schema,
protocol epoch, operation, run ID and complete resource identity. For a smoke
record it accepts either the exact acknowledgement or a proven absence of both
acknowledgement and temporary acknowledgement files, then requires both bound
units to be terminal or absent and a fresh dormant proof. A remove record uses
the corresponding removed-state proof. Every operation also requires the
credential temporary-file check, and install accepts only the exact verifier
state (`dormant` or `removed`). Marker-only and marker-plus-pending states are
both handled by the core; stale ACKs, active units, unknown fixture state,
credential temporaries or an interrupted acknowledgement retain the recovery
veto. This action is explicit and server-local; ordinary settlement never
clears a marker.

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
The one-shot cleanup waits up to five minutes for the canonical production
lock before it reports a failure; brief competing admissions therefore cannot
strand the active fixture. A longer conflict remains an explicit recovery
state with the durable pending veto intact, not an unobserved retry.
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
durations, PDF metadata, and stable assertion labels. For a browser-flow
failure it adds a fixed `stage` and `diagnostic_class`: browser open, storage
state load, or run-code process launch, exit, timeout or CLI error;
structured-result parsing or assertion; browser close; or downloaded-file
permissions. It never records raw process output, command
arguments, exception text, URLs, cookies, or browser artifacts. A historical
report with only `runtime_error` cannot be retroactively classified. The
report is evidence of the gate result, not a credential or fixture snapshot.

The smoke uses one reserved provider with stronger route and role containment
than an ordinary provider session. It checks real rendering and cleanup for
that identity, but does not establish cross-provider isolation, rejection after
an ordinary account's role changes, or the absence of sensitive debug dumps
under every web-server configuration. Those properties have separate isolated
regressions and release-specific evidence limits.

## Exit And Cleanup Semantics

The operator orchestrator uses:

| Exit | Meaning |
| ---: | --- |
| `0` | Browser gate passed; remote principal is dormant; fixture is clean. |
| `1` | A functional/browser assertion failed; cleanup still passed. |
| `2` | Gate runtime or configuration failed; cleanup still passed. |
| `20` | Local or read-only remote preflight failed; no lease was activated. |
| `21` | Activation failed; compensating deactivate was attempted. |
| `22` | Independent cleanup timer could not be armed; activation did not proceed. A lost response leaves the pending run unresolved. |
| `90` | Hard stop: lifecycle cleanup, dormant verification, timer disarm, or admission settlement is unproven. |

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

The later maintenance-enrolled source wrapper refused
`remove --remove-credentials`; it was **not** used for the completed retirement.
The installed older-release wrapper did support that option and was invoked
only through the separately reviewed, hash-bound one-shot operator described
above. The live credential has already been retired, so the command in this
historical section must not be repeated. Its protected recovery copy remains.

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
