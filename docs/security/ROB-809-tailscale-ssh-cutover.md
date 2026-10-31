# ROB-809: optional Tailscale-only SSH cutover

Status (2026-10-30): **implemented and live-verified**. Robin requested the SSH
hardening and confirmed a successful current Hetzner console login before the
cutover. Public SSH permits were removed for IPv4 and IPv6; ordinary OpenSSH
over Tailscale remains available. The global ROB-812 maintenance protocol was
not a prerequisite. The procedure below remains the safety contract for any
future repetition; the completed run does not authorize another change.

## Completed cutover · 2026-10-30

The local and remote `main` were `c690a504d8da4f63afac5f57d3cffdf03e5f807a`.
Fresh read-only health, effective firewall rules and configuration hashes were
captured before the change. Robin's console test supplied the independent
recovery-path confirmation. New direct public connections proved SSH protocol
reachability, HTTP `301` and certificate-validated HTTPS `200` for both address
families before mutation.

The independently reviewed one-run operator retained the existing global
production-change lock continuously from preflight through timer activation,
mutation, external verification and rollback settlement. It removed only the
general `allow 22/tcp` permits, preserving the interface-specific Tailscale SSH
rules and HTTP/HTTPS. Exact user-rule bytes and normalized effective IPv4/IPv6
filter tables matched the expected two-rule delta; unrelated UFW configuration
hashes remained unchanged. No SSH authentication, user, key, Tailscale policy,
application, package or database change was included.

Validation:

- Nine isolated regression tests passed, including a real blocking file-lock
  waiter and simulated timer-dispatch/cancellation races. Systemd command
  responses were doubles, not a live systemd integration test.
- A disposable network-isolated container with real UFW 0.36.2 tested exact
  IPv4/IPv6 rule deletion, restoration and idempotent restoration. Its Debian
  package revision differed from the production Ubuntu package revision.
- After the live change, two new public SSH connections per address family
  failed while HTTP `301` and certificate-validated HTTPS `200` continued on
  the same pinned public addresses. New non-multiplexed Tailscale SSH
  connections succeeded.
- `prod_validate_after_change.sh` passed, including app/deep health, renderer,
  monitor endpoint, services, certificates and actionable-log checks.
- The timer and rollback service were inactive, no rollback job was queued,
  and no rollback had run before the lock was released. A final new SSH
  connection rechecked the exact firewall state and available global lock.

The private host-side operator, pre-change rule files, manifest and terminal
success receipt are retained under `/root/fh-ssh-cutover-20261030/` (`0700`).
The reviewed operator SHA-256 is
`508a0627909350bcd13b8ea8bc48f0e04c4832ab74b928d2a27f9d8868cdb353`.
Its automatic rollback is inactive after successful settlement; this retained
run is evidence, not a reusable permission to reopen public SSH. Existing SSH
sessions were not forcibly disconnected. A later firewall or network change
invalidates this reachability evidence.

## Intended result

Remove public-key SSH reachability on the server's public IPv4 and IPv6 paths
while retaining ordinary OpenSSH over Tailscale. Keep the current keys, users,
`sshd`, Tailscale policy, HTTPS, and application unchanged. The retired
Provider UI synthetic smoke is not part of this cutover or its release path.

## Fresh preflight for a future night window

1. Confirm the exact host and Tailscale identity, the current `main` and active
   release, application and monitor health, and the still-working Hetzner
   console. Retain one existing SSH session and prove a second new OpenSSH
   connection actually uses the Tailscale route.
2. Inventory the effective firewall for **both** published address families,
   not only the abbreviated UFW status. Bind the ordered rules, default policy,
   relevant rules-file hashes and exact public versus `tailscale0` SSH permits.
   Unknown or overlapping rules stop the cutover. From a controlled public-route
   client, prove SSH and HTTPS separately for **each published address family**
   before the change. If either control cannot be established for a published
   family, stop instead of treating that family as untested.
3. Prepare one reviewed, narrowly scoped rollback for exactly the removed
   public SSH permits. Test scheduling, cancellation, dispatch races and
   idempotent restore in isolation. A dispatched rollback must wait for the
   shared production-change lock before writing, not exit because it is held.
   After restoring the exact public SSH permits, it must verify the effective
   firewall rules and record a classified result. An unknown or ineffective
   restore remains a recovery state for inspection through the console.
   The Hetzner console is the independent recovery path; the rollback
   is an additional short-window guard, not a replacement for it. No global
   maintenance-admission record or application release is needed for this
   SSH-only change.

## One bounded cutover

Verify the bound preflight again, then acquire and validate the shared
production-change lock **before** scheduling the rollback. Keep that same lock
continuously through firewall mutation, verification and terminal rollback
settlement. Remove only the verified public SSH permits after the rollback is
armed.
After the change, prove a fresh Tailscale SSH connection. For **each published
address family** that passed the required pre-change controls, prove public SSH
nonreachability and continued public HTTPS. Verify the intended firewall diff
and application/monitor health. While holding the shared lock, cancel the
armed rollback only after those facts and the final firewall state agree.
Before declaring success or releasing the lock, prove that neither the timer
nor a dispatched rollback service is queued or running. If rollback has already
started, abandon the success path, release the lock so it can finish, and
verify the restored public SSH permits and service state. Unknown rollback
state requires inspection through the confirmed console; never cancel an
active restoration to manufacture success. Never interpret a lost session as
proof of success or rollback.

If identity, rule state, route, rollback state or outcome is unknown, stop and
use the confirmed console path to inspect or restore the exact public permits.
Do not retry blindly or change unrelated rules. Record only redacted result
classes and time-bound identities in Linear; keep raw addresses and keys out of
shared evidence.

The future operator and test must be reviewed against the **then-current**
firewall inventory. This proposal does not change backups, restore evidence,
deployment canary or fallback requirements for application releases. The
historical [ROB-402 UFW gate](ROB-402-ufw-allowlist-gate.md) remains separate.
