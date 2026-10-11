# ROB-809: optional Tailscale-only SSH cutover

Status (2026-10-11): **deferred, not urgent**. No SSH or firewall change has
been made under ROB-809. Robin confirmed independent access through the Hetzner
console and approved a smaller one-time cutover plan. The global ROB-812
maintenance protocol is **not** a prerequisite. This document prepares a later
decision; it is not an executable operator or production-change approval.

## Intended result

Remove public-key SSH reachability on the server's public IPv4 and IPv6 paths
while retaining ordinary OpenSSH over Tailscale. Keep the current keys, users,
`sshd`, Tailscale policy, HTTPS, and application unchanged. The Provider UI
synthetic smoke may pause during the short SSH window.

## Fresh preflight for a future night window

1. Confirm the exact host and Tailscale identity, the current `main` and active
   release, application and monitor health, and the still-working Hetzner
   console. Retain one existing SSH session and prove a second new OpenSSH
   connection actually uses the Tailscale route.
2. Inventory the effective firewall for **both** published address families,
   not only the abbreviated UFW status. Bind the ordered rules, default policy,
   relevant rules-file hashes and exact public versus `tailscale0` SSH permits.
   Unknown or overlapping rules stop the cutover. Use a controlled public-route
   client with a positive SSH and HTTPS connection before the change.
3. Prepare one reviewed, narrowly scoped rollback for exactly the removed
   public SSH permits. Test its scheduling, cancellation, dispatch races and
   idempotent restore behavior in isolation. A dispatched rollback must wait
   for the shared lock and verify the restored rules; it must not exit merely
   because the lock is temporarily held. The Hetzner console is the independent
   recovery path;
   the rollback is an additional short-window guard, not a replacement for it.
   The shared production-change lock protects the firewall mutation and
   immediate verification; the rollback operator must acquire that same lock
   before its own write. No all-writer pending journal is needed for this
   SSH-only change.

## One bounded cutover

Pause the synthetic Provider UI test, verify the bound preflight again, then
acquire and validate the shared production-change lock **before** scheduling
the rollback. Keep that same lock continuously through timer activation,
firewall mutation, verification and terminal rollback settlement. Remove only
the verified public SSH permits after the rollback is armed.
After the change, prove a fresh Tailscale SSH connection, public SSH
nonreachability for each previously reachable family, continued public HTTPS,
the intended firewall diff, and application/monitor health. While holding the
shared lock, cancel the timer only after those facts and the final firewall
state agree. Before declaring success or releasing the lock, prove that neither
the timer nor a dispatched rollback service is queued or running. If rollback
has already started, abandon the success path, release the lock so it can
finish, and verify the restored public SSH permits and service state. Unknown
rollback state requires inspection through the confirmed console; never cancel
an active restoration to manufacture success. Resume the synthetic test only
after the SSH window has a verified final state. Never interpret a lost session
or timer expiry alone as proof of success or rollback.

If identity, rule state, route, rollback state or outcome is unknown, stop and
use the confirmed console path to inspect or restore the exact public permits.
Do not retry blindly or change unrelated rules. Record only redacted result
classes and time-bound identities in Linear; keep raw addresses and keys out of
shared evidence.

The future operator and test must be reviewed against the **then-current**
firewall inventory. This proposal does not change backups, restore evidence,
deployment canary or fallback requirements for application releases. The
historical [ROB-402 UFW gate](ROB-402-ufw-allowlist-gate.md) remains separate.
