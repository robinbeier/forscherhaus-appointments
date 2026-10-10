# ROB-809 Tailscale-only SSH cutover

Status: prepared; the night-window live write has not been executed.

This runbook defines a narrowly bounded production SSH reachability change. It
removes the two currently bound general `22/tcp` UFW allows (one per published
address family) after a fresh preflight, while retaining the two existing
`tailscale0`-specific `22/tcp` allows. It does not change `sshd`, keys, users,
Tailscale policy, provider firewall settings, or any application service.

## Current read-only facts

These facts are preparation evidence, not live authorization and not a
substitute for the immediate preflight:

- UFW default incoming policy is deny.
- UFW has exactly two general `22/tcp` allows, one for each currently
  published address family, and exactly two `tailscale0`-specific `22/tcp`
  allows.
- `sshd` listens on a wildcard address. That describes the daemon listener;
  it does not prove public reachability or prove that a firewall cutover is
  safe.
- Password login is disabled; key login and fail2ban are active.
- A Tailscale SSH path works.
- Robin has independently confirmed that the Hetzner console currently works.
  Codex has not used that console path.

Re-inventory every one of these facts immediately before the write and bind
the result to the intended production host and its current Tailscale identity.
Do not record raw addresses, keys, fingerprints, tokens, or configuration.

## Preconditions and guardrails

- Confirm that the active user authorization covers this production write in
  the agreed night window, the shared production lock, rollback
  setup/cancellation, and post-change validation. A previously granted scope
  does not need to be approved again solely because an identity hash changes.
- Immediately before the write, perform a redacted re-inventory of host
  identity, Tailscale identity, published address families, UFW policy/rules,
  effective SSH authentication policy, and application/monitor health.
- Treat Robin's confirmed Hetzner console access as the independent recovery
  path. Check whether any newer fact invalidates that confirmation before the
  write. If it is no longer reliable, stop; do not infer that a rollback timer
  replaces independent access.
- Keep the existing controllable SSH session open and obtain a second,
  independent new SSH session over the tailnet before removing any rule.
- Acquire the shared production lock and keep it for the complete change and
  evidence window.
- Stage a one-shot, short-lived rollback before the write. If confirmation
  fails, it may restore only the two currently bound general `22/tcp` allows,
  one per published address family. It must not alter the default policy, the
  `tailscale0` rules, SSH policy, or unrelated firewall rules.

Stop before the write if host or Tailscale identity, published families, rule
binding, the second session, the lock, or rollback scope cannot be confirmed,
or if newer evidence makes the confirmed console recovery path unreliable.

## Night-window procedure

1. Run the standard redacted production doctor and post-change validation as a
   fresh baseline, plus the focused SSH/UFW re-inventory. Record only class or
   boolean results and the time-bound identity binding.
2. Confirm the console recovery path, controllable guard session, second
   independent tailnet SSH session, shared lock, and staged one-shot rollback.
3. Remove only the two general `22/tcp` allows identified by that immediate
   re-inventory. Do not use a broad rule flush, reset, default-policy change,
   or rule deletion by an unverified position.
4. From the second independent session, verify a new tailnet SSH connection to
   the bound host. Verify that public `22/tcp` is unreachable from outside the
   tailnet for every currently published address family. This test must be
   performed from an actually outside-tailnet vantage point; a local failure
   or an SSH timeout alone is not sufficient evidence.
5. Run the production doctor, post-change validation, application health, and
   monitor checks. Confirm that only the intended general rules changed and
   both `tailscale0`-specific rules remain.
6. Cancel the one-shot rollback only after all SSH, public nonreachability,
   firewall, application, and monitor evidence passes. Record redacted result
   classes and release the lock.

## Stop, rollback, and unknown results

Stop and invoke the staged rollback if the new tailnet session fails, public
`22/tcp` remains reachable, either tailnet-specific rule is missing, host or
identity binding changes, application or monitor health fails, or the
post-change validation fails. Recheck the restored general rules and obtain a
new SSH session before any further decision.

If any command or confirmation has an unknown result, stop without blind
retries. Keep the console recovery path and the staged rollback available,
preserve the production lock, and report the unresolved evidence. Do not
claim the cutover or its rollback until the relevant postcondition is observed.

## Non-goals and evidence boundary

This procedure does not alter `sshd` listeners or authentication settings,
Tailscale enrollment/policy, provider firewalls, HTTP/HTTPS, application
files, databases, monitoring definitions, or unrelated UFW rules. The
wildcard `sshd` listener remains compatible with the resulting firewall
posture but is never used as reachability evidence.

Keep the historical [ROB-402 UFW allowlist gate](ROB-402-ufw-allowlist-gate.md)
intact. ROB-809 is a separate, later SSH reachability decision and does not
retroactively change ROB-402’s facts or status.
