# Customers UI Smoke Release Gate

Purpose: verify the Customers page and `customers/search` for every built-in
backoffice role without using a real login or returning real customer data.

This is an on-demand postdeploy gate. It is not a Kuma monitor, cron job,
deploy hook, or background browser. Its first production execution remains a
separate live approval gate.

## Security Invariant

Four reserved synthetic principals exist permanently in a dormant state:

| Target role | Customers view/search |
| --- | --- |
| `admin` | allowed |
| `provider` | allowed |
| `secretary` | allowed |
| `customer` | denied with `403` |

While dormant, all four principals use a dedicated zero-rights role and have
`NULL` password verifiers and salts. Activation temporarily assigns the four
built-in roles and installs password verifiers for one bounded ten-minute
lease. Deactivation clears the verifiers, restores the dormant role, removes
the lease state, and verifies the clean invariant.

The central authenticated boundary limits all four identities to:

- login while a valid lease is active;
- `GET customers`;
- `POST customers/search`;
- logout.

Every account, calendar, provider, API, settings, write, integration, booking,
and other route is denied. Basic authentication is denied for every reserved
username.

## No-Customer-Data Contract

The lifecycle creates no customer, appointment, provider-service, or
secretary-provider fixture rows. For a reserved Customers smoke session, the
Customers controller does not call the normal customer search at all:

- the page's automatic empty-keyword search returns exactly `[]`;
- the exact marker `__EA_CUSTOMERS_UI_SMOKE_V1_EMPTY_SEARCH__` returns exactly
  `[]`;
- every other search keyword returns `403`.

Activation additionally proves that the marker is absent from the normal
customer search. This makes the browser flow useful while preventing real
customer rows from entering the response, DOM, report, or browser artifacts.

The gate verifies that `customer_filter_providers`, provider integration keys,
calendar integration keys, webhook secret keys, and credential material are
absent from the Customers HTML, `window.vars`, DOM, and search responses.

## Components

- `application/core/Customers_ui_smoke_access_policy.php`: reserved identities,
  role matrix, route allowlist, search marker, and lease format.
- `application/libraries/Customers_ui_smoke_fixture.php`: root-only dormant,
  activation, crash recovery, cleanup, and removal lifecycle.
- `scripts/ops/customers_ui_smoke_principals.sh`: server-local credential and
  lifecycle wrapper.
- `scripts/ops/prod_customers_ui_smoke.sh`: operator-side preflight, deployment
  binding, independent cleanup timer, local browser gate, and mandatory cleanup.
- `scripts/release-gate/customers_ui_smoke.php`: PII-free HTTP and browser role
  assertions.

The root credential file is
`/etc/fh/release-gate-customers-ui-smoke.env`. It contains exactly four fixed
reserved username keys and one generated 64-character password key. Values
must never be printed, copied into arguments, committed, attached to reports,
or stored on the operator workstation.

Lease state lives in `/var/lib/fh-customers-ui-smoke/active.json`. The directory
is `root:root` `0700`; the state file exists only during activation and is
`root:root` `0600`.

## One-Time Bootstrap

Only after the guarded release is deployed and the normal production baseline
is green:

```bash
ssh root@booking-server \
  'bash /var/www/html/easyappointments/scripts/ops/customers_ui_smoke_principals.sh install'
```

`install` is idempotent. It creates the root-only credential if absent, creates
the dormant role and principals, and verifies that no owned relations or
synthetic-marker collisions exist. Unsafe credential/state files, duplicate
markers, role drift, or permission-matrix drift fail closed.

Read-only recheck:

```bash
ssh root@booking-server \
  'bash /var/www/html/easyappointments/scripts/ops/customers_ui_smoke_principals.sh verify'
```

## Run

From a clean operator checkout of the deployed commit:

```bash
bash scripts/ops/prod_customers_ui_smoke.sh \
  --prod-ssh-target root@booking-server \
  --output-json storage/logs/release-gate/customers-ui-smoke-run.json
```

Before activation, the orchestrator:

1. verifies local PHP, curl, SSH, Playwright, and the app endpoint;
2. verifies the remote root-only credential/state/wrapper contract and host
   Node/npm absence;
3. verifies all principals are dormant and clean;
4. byte-binds the deployed Customers controller, policy, fixture, view, and JS
   contract to the clean operator checkout;
5. arms `fh-customers-ui-smoke-cleanup.timer` for ten minutes.

Credentials stream directly from remote `cat` into the local gate's standard
input. They never enter a local file, variable, command argument, report, or
process title. The orchestrator rechecks the deployed contract digest after the
browser flow.

The local gate creates a random `0700` temporary directory. Per-role browser
storage state is `0600` and deleted immediately after loading. The browser
produces no screenshots, traces, network logs, cookies, response bodies, names,
contact fields, or database identifiers. The whole directory is removed in
`finally`, including after a browser assertion or runtime failure.

For a structured browser assertion failure, the JSON report retains only the
fixed browser-result booleans and non-negative counters in the failed
`browser_role_<role>` check. Unknown fields, strings, invalid counters, and raw
browser output are rejected and are never copied into the report.

## Cleanup And Exit Codes

The shell `EXIT`/signal handler always calls `deactivate` and `verify`. An
independent systemd timer calls the same secret-free server-local wrapper if the
operator process, workstation, or SSH connection disappears. The timer is
disarmed only after synchronous cleanup and dormant verification both pass.

| Exit | Meaning |
| ---: | --- |
| `0` | Role/browser assertions passed; principals dormant; artifacts clean. |
| `1` | Functional assertion failed; cleanup still passed. |
| `2` | Gate runtime/configuration failed; cleanup still passed. |
| `20` | Local or remote preflight failed; no activation. |
| `21` | Activation failed; compensating cleanup attempted. |
| `22` | Independent cleanup timer could not be armed; no activation. |
| `90` | Hard stop: deactivate, verify, or timer disarm failed. |

Exit `90` overrides the original result. Keep the guarded release active and
investigate server-locally without printing credentials, state, database rows,
or raw logs.

## Removal And Operational Boundary

Normal runs retain the dormant principals and credential. Full principal
removal requires two successful idempotent application-level remove
transactions before credential retirement is allowed:

```bash
ssh root@booking-server \
  'bash /var/www/html/easyappointments/scripts/ops/customers_ui_smoke_principals.sh remove'
```

Credential retirement is a separate explicit `--remove-credentials` action and
moves the file to a root-only retired directory.

Do not run this gate with real accounts, change real customer rows, schedule it
in Kuma or cron, combine it with ROB-432, or treat the local/CI checks as proof
that the later production live gate passed.
