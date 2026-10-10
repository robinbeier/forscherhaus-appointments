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
- Ordinary key-based OpenSSH routed over the host's Tailscale address works.
  This does not rely on the separate Tailscale SSH service.
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
  independent new ordinary OpenSSH session to the bound Tailscale address
  before removing any rule. Confirm its route uses the tailnet interface.
- Acquire the shared production lock and keep it for the complete change and
  evidence window.
- Stage a one-shot, short-lived rollback before the write. If confirmation
  fails, it may restore only the two currently bound general `22/tcp` allows,
  one per published address family. It must not alter the default policy, the
  `tailscale0` rules, SSH policy, or unrelated firewall rules.

Stop before the write if host or Tailscale identity, published families, rule
binding, the second session, the lock, or rollback scope cannot be confirmed,
or if newer evidence makes the confirmed console recovery path unreliable.

## Exact UFW mutation and rollback binding

The only supported removal is `ufw delete allow 22/tcp`. UFW documents this
form as deleting both the IPv4 and IPv6 halves of a generic rule in one
invocation; deleting a numbered rule would remove only one half. The inverse
rollback command is `ufw allow 22/tcp`. Neither command names an interface,
changes the default policy, or touches the tailnet-specific rules.

Before staging rollback, confirm all of the following from the same host:

- `ufw status` reports active with incoming default deny, IPv4 and IPv6
  enabled, exactly one general `ALLOW IN 22/tcp` row per family, and exactly
  one `tailscale0`-specific `ALLOW IN 22/tcp` row per family. Record only
  classes and counts.
- `ufw show added` contains **exactly one** literal `ufw allow 22/tcp` command
  and no other general SSH allowance. A different command, comment-bearing
  variant, duplicate, family count, or rule order is a stop, not an invitation
  to choose a similar-looking numbered rule.
- Bind the UFW version and SHA-256 of `/etc/ufw/user.rules` and
  `/etc/ufw/user6.rules` to the run. Recheck both hashes immediately before
  `ufw delete allow 22/tcp` and confirm the four SSH rows still have the
  expected classes. Any drift stops before mutation.
- Stage a single delayed root-owned rollback that runs only the inverse
  `ufw allow 22/tcp`, then checks both general rows and both tailnet-specific
  rows. The rollback is armed **before** deletion, has a recorded job identity,
  and is cancelled only after all postconditions pass. Keep its output and
  cancellation result as private recovery evidence. If it runs or its outcome
  is unknown, stop and inspect; do not issue the command a second time.

Immediately after deletion, compare the full redacted UFW rule-class inventory
with the bound inventory. The only allowed difference is the absence of the
two general SSH rows. Confirm the two tailnet-specific rows, all other rule
classes, and both rules-file hashes' *new* identities. An unexpected
difference is a rollback/stop condition. The same narrow checks apply after
rollback, with the original rule classes restored; do not assert byte-for-byte
restoration of UFW's rewritten files.

The exact command pair and dual-stack behavior follow the
[Ubuntu UFW manual](https://manpages.ubuntu.com/manpages/noble/man8/ufw.8.html).
Do not run the cutover if the live UFW version or observed rule representation
does not meet this contract.

## Public-path probe contract

Use one controlled client path that reaches each *published* public address
family without traversing Tailscale. Bind its source network, target DNS
answer, address family, and route interface privately; record only classes in
Linear. The client may itself run Tailscale, but the route for each public
  target must be verified to use the ordinary network interface, never a
  tailnet tunnel. Bind the host's current public interface addresses from a
  narrow read-only interface inventory and compare them with public DNS;
  **do not rely on DNS alone**, because a directly reachable IPv6 address may
  lack an AAAA record. On the local macOS client, check each bound address with
  `route -n get -inet ADDRESS` or `route -n get -inet6 ADDRESS`, and require
  an ordinary network interface rather than `utun`/Tailscale. Use the local
  `nc` with `-4` or `-6`, `-G 5`, and `-z` against each bound numeric address
  and port. Before mutation, `nc` must establish TCP/22 **and** TCP/443 for
  every family from this exact route. Classify exit status and elapsed time
  without retaining raw addresses in shared evidence.
If either family has no route or no pre-change positive control, stop.

After mutation, reuse the same client, targets, families, and route. Require
two bounded TCP/22 connection attempts per family to fail while TCP/443 still
works and the server-side UFW diff matches the exact intended rule removal.
Classify each family separately as `blocked`, `reachable`, or `unknown`.
Changed DNS, route, source network, failed TCP/443 control, command timeout
without the other evidence, or ambiguous socket errors are `unknown` and
cannot authorize cancellation of rollback. No raw address or client identity
is copied into Linear.

## Night-window procedure

1. Run the standard redacted production doctor and post-change validation as a
   fresh baseline, plus the focused SSH/UFW re-inventory. Record only class or
   boolean results and the time-bound identity binding.
2. Confirm the console recovery path, controllable guard session, second
   independent OpenSSH-over-Tailscale session, shared lock, both positive
   public-path controls, and staged one-shot rollback.
3. Recheck the bound rules-file hashes and rule classes, then run only
   `ufw delete allow 22/tcp`. Do not use a broad rule flush, reset,
   default-policy change, or numbered-rule deletion.
4. From a new session, verify ordinary OpenSSH over Tailscale to the bound
   host. Run the per-family public-path probes under the contract above.
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
