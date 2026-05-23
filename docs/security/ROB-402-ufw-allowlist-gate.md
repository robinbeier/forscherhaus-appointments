# ROB-402 UFW Allowlist Gate

Status: prepared, live write not executed.

Scope: prepare a production UFW live gate for the minimal public inbound
allowlist only. The intended public services are SSH, HTTP, and HTTPS:
`22/tcp`, `80/tcp`, and `443/tcp`. This gate intentionally excludes Apache
configuration changes, SSH policy changes, Certbot actions, Kuma changes, App
changes, database changes, deploys, package updates, Docker changes, provider
firewall changes, and host reboot behavior.

ROB-402 is higher lockout risk than the previous header and SSH policy gates.
The live gate must therefore prove current SSH reachability, keep a usable
rollback channel, and install a short-lived automatic rollback before enabling
UFW.

## Baseline Evidence

Latest relevant accepted baseline after ROB-401:

- Local `main` includes the ROB-401 gate documentation and prior posture
  decisions.
- `prod_doctor.sh` provides redacted posture classes for UFW status, expected
  listener classes, loopback-only internal services, and unexpected public
  listener count.
- ROB-396 confirmed that the UFW gate must remain separate from posture
  decision work.

Expected pre-change production posture classes for this gate:

- `posture_ufw.status=inactive`
- `posture_tcp.22.listen_class=wildcard|public`
- `posture_tcp.80.listen_class=wildcard|public`
- `posture_tcp.443.listen_class=wildcard|public`
- `posture_tcp.3001.listen_class=loopback`
- `posture_tcp.3003.listen_class=loopback`
- `posture_tcp.3306.listen_class=loopback`
- `posture_tcp.unexpected_public_listener_count=0`

Expected health baseline:

- App HTTPS, `www` HTTPS, monitor, renderer, and deep health return expected
  status classes.
- Uptime Kuma latest state is green for all active monitors.
- No recent service-warning class or app-error-like log class is reported by
  the redacted harness.

## Target Change

The live gate may apply only this inbound firewall posture:

```text
default deny incoming
allow 22/tcp
allow 80/tcp
allow 443/tcp
enable UFW
```

Expected effective outcome:

- `posture_ufw.status=active`
- Public inbound services remain limited to `22/tcp`, `80/tcp`, and `443/tcp`.
- App, `www`, monitor, renderer, deep health, and Kuma remain healthy.
- Loopback-only services remain loopback-only.

## Non-Goals

- Do not change Apache vhosts, redirects, headers, or TLS.
- Do not change SSH policy flags, users, keys, authorized keys, or root login
  behavior.
- Do not run Certbot or change certificate files.
- Do not change Kuma monitor definitions, Push URLs, or Kuma data.
- Do not change Docker Compose, container bindings, App config, database
  config, deployment artifacts, packages, or provider firewall rules.
- Do not reboot the host.
- Do not print raw firewall output, raw listener addresses, raw config, keys,
  users, tokens, passwords, DSNs, Push URLs, health-token values, DB rows,
  session/cache contents, or Kuma data.

## Preconditions

Before any live write:

- A separate operator approval explicitly authorizes ROB-402 live UFW writes,
  automatic rollback setup/cancel, UFW enable, and post-change validation.
- `prod_validate_after_change.sh` passes before the change.
- `prod_doctor.sh` confirms:
  - UFW is inactive.
  - Public listener classes are limited to `22/80/443`.
  - Internal service classes for `3001/3003/3306` are loopback.
  - Unexpected public listener count is `0`.
- Active SSH access is key-based. Evidence may be reported only as a class such
  as `ssh.auth_method=publickey`; never print keys, fingerprints, agent state,
  users, or raw ssh config.
- A controllable SSH guard session remains open while the change is applied.
- A second independent key-based SSH session succeeds before the write.
- The rollback path is explicit before enabling UFW.
- A short-lived automatic rollback is scheduled before enabling UFW, unless an
  equivalent console-level recovery path is explicitly available and accepted.
- The UFW command path and service state can be identified without raw config
  dumps.

## Live Procedure

Do not execute this section without the separate approval above.

1. Capture read-only baseline:
   - `bash scripts/ops/prod_validate_after_change.sh`
   - `bash scripts/ops/prod_doctor.sh`
2. Run focused sanitized SSH/firewall prerequisite checks:
   - `ssh.auth_method=publickey`
   - `ssh.second_session=ok`
   - `ssh.guard_session=controllable`
   - `ufw.command=present`
   - `ufw.status=inactive`
   - `posture_tcp.unexpected_public_listener_count=0`
   - `rollback.timer=schedulable`
3. Open and keep a controllable SSH guard session active.
4. Confirm a second independent key-based SSH session works before any write.
5. Schedule a short automatic rollback, for example a transient systemd unit
   that runs `ufw --force disable` after a short delay.
6. Apply only the minimal UFW policy:
   - default deny incoming
   - allow `22/tcp`
   - allow `80/tcp`
   - allow `443/tcp`
   - enable UFW
7. Confirm a new second key-based SSH session works after enabling UFW.
8. Run post-change validation:
   - `bash scripts/ops/prod_doctor.sh`
   - `bash scripts/ops/prod_validate_after_change.sh`
9. Cancel the automatic rollback only after SSH and production validation pass.
10. Record only class/flag evidence.

## Stop Conditions

Stop before enabling UFW if:

- A separate production approval has not been given.
- SSH access cannot be proven key-based without printing secrets.
- A second independent SSH session cannot be opened before the write.
- A controllable guard session is not available.
- A short automatic rollback cannot be scheduled and no accepted equivalent
  recovery path exists.
- Current UFW state is not clearly inactive or the existing UFW ruleset cannot
  be reasoned about without raw config dumps.
- Any unexpected public listener class is present.
- `22/80/443` are not the only expected public listener classes.
- `3001/3003/3306` are not loopback-only.
- Certbot, HTTP redirect, Apache routing, monitor reachability, or SSH
  preservation impact is unclear.
- The change would touch Apache, SSH policy, Certbot, Kuma, App, DB, deploy,
  packages, Docker, provider firewall, or reboot behavior.
- Validation would require printing raw firewall config, raw listener
  addresses, `/etc/fh`, keys, users, passwords, DSNs, tokens, Push URLs, DB
  rows, session/cache contents, or Kuma data.

Stop and roll back if after enabling UFW:

- A new second key-based SSH session fails.
- `prod_doctor.sh` does not show `posture_ufw.status=active`.
- `prod_doctor.sh` shows any unexpected public listener class.
- App, `www`, monitor, deep health, renderer, or Kuma health classes fail.
- `prod_validate_after_change.sh` fails.
- Any non-target area changes unexpectedly.

## Rollback

Rollback must be available before live edit:

1. Keep the controllable SSH guard session open.
2. Schedule an automatic rollback before enabling UFW.
3. If post-enable SSH or health validation fails, run the rollback immediately:
   - disable UFW, or restore the previously accepted firewall state.
4. Confirm a new SSH session works.
5. Run `bash scripts/ops/prod_doctor.sh`.
6. Run `bash scripts/ops/prod_validate_after_change.sh`.
7. Record only class/flag evidence.

Do not cancel the automatic rollback until:

- New post-enable SSH session succeeds.
- `prod_doctor.sh` shows expected UFW/listener classes.
- `prod_validate_after_change.sh` passes.

## Expected Post-Change Evidence

Allowed evidence classes:

- `ssh.auth_method=publickey`
- `ssh.second_session=ok`
- `ssh.second_session_post_enable=ok`
- `ssh.guard_session=controllable`
- `rollback.timer=scheduled|cancelled|fired`
- `ufw.command=present`
- `ufw.enable=ok`
- `posture_ufw.status=active`
- `posture_tcp.22.listen_class=wildcard|public`
- `posture_tcp.80.listen_class=wildcard|public`
- `posture_tcp.443.listen_class=wildcard|public`
- `posture_tcp.3001.listen_class=loopback`
- `posture_tcp.3003.listen_class=loopback`
- `posture_tcp.3306.listen_class=loopback`
- `posture_tcp.unexpected_public_listener_count=0`
- `validation=passed`

Not allowed:

- Raw UFW rule output.
- Raw `ss` or listener-address output.
- Raw Apache, SSH, Docker, or firewall config.
- SSH keys, fingerprints, agent contents, usernames, or user lists.
- Secret-bearing file contents.
- Tokens, passwords, DSNs, Push URLs, health-token values.
- DB rows, session/cache contents, Kuma DB rows.

## Acceptance Criteria

- The docs PR is merged before live execution.
- ROB-402 remains open until the live gate is separately approved, executed,
  and validated.
- Live evidence stays redacted and class-based.
- UFW activation is not performed from the docs PR.
