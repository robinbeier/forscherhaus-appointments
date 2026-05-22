# ROB-401 SSH Forwarding Policy Gate

Status: prepared, live write not executed.

Scope: disable production SSH X11 forwarding and TCP forwarding only after
operator workflows are confirmed not to depend on them, key-based SSH access is
proven, rollback is clear, and a second SSH session is proven. This gate
intentionally excludes password authentication, `PermitRootLogin`, firewall/UFW,
Apache, Kuma, App, database, deploy, package updates, and host reboot behavior.

## Baseline Evidence

Source: `bash scripts/ops/prod_doctor.sh`, read-only snapshot
`2026-05-22T10:32:40Z`, after ROB-400 completed.

- SSH effective policy classes:
  - `permitrootlogin=prohibit-password`
  - `pubkeyauthentication=yes`
  - `passwordauthentication=no`, intentionally out of scope for ROB-401
  - `x11forwarding=yes`
  - `allowtcpforwarding=yes`
- App, `www`, monitor, renderer, and deep health returned expected status
  classes.
- Uptime Kuma reported 13 active monitors and 13 latest green.
- No unexpected public listener class was reported.
- No recent Apache/PHP-FPM/service warning class or app-error-like log class was
  reported.

Source: `bash scripts/ops/prod_validate_after_change.sh`, read-only snapshot
`2026-05-22T10:32Z`.

- App, `www`, monitor redirect, renderer, deep health, services, containers,
  Kuma, host Node policy, certbot timer, and log classes passed.
- `validation=passed`.

## Target Change

The live gate may apply only these effective SSH policy changes:

```sshconfig
X11Forwarding no
AllowTcpForwarding no
```

Preferred implementation:

- Use a minimal sshd drop-in, for example
  `/etc/ssh/sshd_config.d/99-rob-401-forwarding.conf`, if drop-ins are
  supported and loaded by the effective sshd configuration.
- Do not edit broad existing sshd configuration files when a drop-in is safely
  available.
- Do not change `PasswordAuthentication`, `PermitRootLogin`, or
  `PubkeyAuthentication`.

## Preconditions

Before any live write:

- A separate operator approval explicitly authorizes ROB-401 live SSH forwarding
  changes, `sshd -t`, sshd reload, second-session checks, and post-change
  validation.
- No required operator workflow depends on SSH X11 forwarding or TCP forwarding.
- `prod_validate_after_change.sh` passes before the change.
- `prod_doctor.sh` confirms the current effective classes:
  - `posture_ssh.permitrootlogin=prohibit-password`
  - `posture_ssh.pubkeyauthentication=yes`
  - `posture_ssh.passwordauthentication=no`
  - `posture_ssh.x11forwarding=yes`
  - `posture_ssh.allowtcpforwarding=yes`
- Active SSH access is key-based. Evidence may be reported only as a class such
  as `ssh.auth_method=publickey`; never print keys, fingerprints, agent state,
  users, or raw sshd config.
- A first SSH guard session stays open while the change is applied.
- A second independent key-based SSH session succeeds before the write.
- The sshd drop-in/include scope is identifiable without printing raw config.
- `sshd -t` is already clean before editing.
- Rollback is clear before editing.

## Live Procedure

Do not execute this section without the separate approval above.

1. Capture read-only baseline:
   - `bash scripts/ops/prod_validate_after_change.sh`
   - `bash scripts/ops/prod_doctor.sh`
2. Run a focused sanitized SSH prerequisite check for:
   - `ssh.auth_method=publickey`
   - `ssh.second_session=ok`
   - `sshd.configtest=ok`
   - `sshd.dropin_scope=supported`
   - `ssh.forwarding_operator_dependency=none`
3. Open and keep a first SSH guard session active.
4. Confirm a second independent key-based SSH session works.
5. Write only the ROB-401 forwarding-policy drop-in.
6. Run `sshd -t`.
7. Stop without reload if `sshd -t` fails; remove or revert the ROB-401 drop-in
   before retrying.
8. If `sshd -t` passes, reload sshd only. Do not reboot the host.
9. Confirm a new second key-based SSH session works after reload before closing
   the original guard session.
10. Run post-change validation:
    - `bash scripts/ops/prod_doctor.sh`
    - `bash scripts/ops/prod_validate_after_change.sh`
11. Record only effective policy classes, session success classes, and
    validation outcome.

## Expected Post-Change Evidence

Expected `prod_doctor.sh` posture classes:

- `posture_ssh.permitrootlogin=prohibit-password`
- `posture_ssh.pubkeyauthentication=yes`
- `posture_ssh.passwordauthentication=no`, unchanged and still out of scope
- `posture_ssh.x11forwarding=no`
- `posture_ssh.allowtcpforwarding=no`

Expected health classes:

- A new key-based SSH session succeeds after reload.
- App HTTPS, `www` HTTPS, monitor, deep health, and renderer remain healthy.
- Uptime Kuma remains 13 active monitors and 13 latest green.
- No new service warning class or app-error-like log class.

## Stop Conditions

Stop and do not reload sshd if:

- Any operator workflow depends on SSH X11 forwarding or TCP forwarding.
- The change would touch `PasswordAuthentication`, `PermitRootLogin`,
  `PubkeyAuthentication`, firewall/UFW, Apache, Kuma, App, database, deploy,
  packages, or host reboot behavior.
- Current access cannot be confirmed as key-based without printing secrets.
- A second independent SSH session cannot be opened before the write.
- `sshd -t` is not clean before editing.
- The drop-in/include scope cannot be identified without raw config dumps.
- The rollback path is unclear.
- Editing would require printing raw sshd config, `/etc/fh`, keys, users,
  passwords, DSNs, tokens, Push URLs, DB rows, session/cache contents, or Kuma
  data.

Stop and roll back if after reload:

- A new second key-based SSH session fails.
- `prod_doctor.sh` does not show `posture_ssh.x11forwarding=no` and
  `posture_ssh.allowtcpforwarding=no`.
- `PasswordAuthentication`, `PermitRootLogin`, or `PubkeyAuthentication` changes
  unexpectedly.
- App, `www`, monitor, deep health, renderer, or Kuma health classes fail.

## Rollback

Rollback must be available before live edit:

1. Remove the ROB-401 drop-in or change only its ROB-401 lines back to
   `X11Forwarding yes` and `AllowTcpForwarding yes`.
2. Run `sshd -t`.
3. If `sshd -t` passes, reload sshd.
4. Confirm a new SSH session works before closing the guard session.
5. Run `bash scripts/ops/prod_doctor.sh`.
6. Run `bash scripts/ops/prod_validate_after_change.sh`.
7. Record only class/flag evidence.

## Evidence Boundary

Allowed in chat, Linear, PR, and docs:

- Effective SSH policy classes.
- `ssh.auth_method=publickey|unknown|failed`.
- `ssh.second_session=ok|failed`.
- `ssh.forwarding_operator_dependency=none|present|unknown`.
- `sshd.configtest=ok|failed`.
- `sshd.reload=ok|failed`.
- Health status classes.
- `prod_validate_after_change.sh` pass/fail outcome.

Not allowed:

- Raw sshd config.
- SSH keys, fingerprints, agent contents, usernames, or user lists.
- Secret-bearing file contents.
- Tokens, passwords, DSNs, Push URLs, health-token values.
- DB rows, session/cache contents, Kuma DB rows.
