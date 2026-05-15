# Old-Server Rollback Drill

Purpose: make the current production server usable as an explicit
migration-level rollback target during a fresh-server rehearsal or final
cutover.

This drill documents decisions, checks, and evidence. It does not authorize
changes to the current production server. A later cutover task must explicitly
authorize any live DNS, database, deployment, or monitoring action.

## Rollback Layers

Keep these rollback layers separate:

- Same-host deploy rollback: `deploy_ea.sh` restores the previous app directory
  on the same target host after a failed artifact deploy or post-switch check.
  Exit `30` means automatic rollback succeeded. Exit `31` means rollback failed
  or could not be verified. See [deployment.md](deployment.md).
- Migration-level old-server rollback: DNS or traffic returns to the current
  production server while it remains intact. This is the rollback path for a
  failed fresh-server migration.

Do not use `deploy_ea.sh` rollback as the substitute for returning production
traffic to the old server.

## Timing Defaults

These are project defaults for rehearsal planning, not measured SLAs.

| Decision | Default |
| --- | --- |
| Rollback decision deadline | Decide within `10` minutes after a blocking post-cutover failure. |
| Maximum acceptable public downtime | `30` minutes for this migration project. |
| Old-server observation window | Keep the old server available for at least `7` days after accepted cutover. |

Replace these values only with an explicit cutover-window decision.

## Required Inputs

Record the existence, owner, path, TTL, or checksum. Do not record secret values.

| Input | Required Evidence |
| --- | --- |
| Rollback owner | Named operator and backup operator |
| Rollback decision deadline | Clock time or elapsed-time threshold |
| Maximum accepted downtime | Agreed minute threshold |
| Current production route | Hostnames and DNS provider/account owner |
| Current production IP | IP address or provider host reference |
| Target host route | Rehearsal route, future DNS target, or load-balancer route |
| DNS TTL | Current TTL and pre-cutover TTL target |
| Old-server health route | HTTP/HTTPS health URL or operator smoke route |
| DB write policy | Write-freeze, read-only window, or accepted write divergence decision |
| Kuma monitoring plan | Maintenance mode, paused monitors, or resume action |
| Sentry/log review owner | Operator responsible for post-rollback error check |

## Before Rehearsal

- Confirm the current production server remains unchanged.
- Confirm the old server can still serve the public app route.
- Confirm old-server secrets remain host-local and are not copied into Git,
  Linear attachments, chat, screenshots, or docs.
- Confirm production DNS has not been changed for the rehearsal unless final
  cutover has been explicitly approved.
- Confirm the target-host failure path does not require deleting target
  artifacts that may be needed for later diagnosis.
- Confirm who can execute DNS rollback and who can verify app health.

Stop if the old server is not healthy enough to be the migration-level rollback
target.

## Drill A: Abort Before DNS Switch

Use this path when a target-host validation fails before traffic is moved.

| Step | Action | Validation | Evidence |
| --- | --- | --- | --- |
| Trigger | Any pre-DNS go/no-go check fails. | Failure is confirmed by gate output, health probe, or operator smoke. | Failed check name and report path. |
| Decision | Rollback owner declares `abort before DNS`. | Old production DNS is still authoritative. | DNS lookup result and timestamp. |
| App | Keep current production serving traffic. | Old app route loads. | HTTP status and manual smoke result. |
| DB | Do not restore target DB into production. | Old production DB remains authoritative. | DB write policy note. |
| Kuma | Keep or resume normal old-server monitoring. | Required monitors are green or intentionally paused. | Kuma status summary without Push URLs. |
| Target | Mark target host as failed or paused. | No target route receives production traffic. | Target remediation note. |

No public rollback action is required if DNS never moved.

## Drill B: Revert After DNS Switch

Use this path when traffic has moved and a blocking post-cutover failure occurs
before final acceptance.

| Step | Action | Validation | Evidence |
| --- | --- | --- | --- |
| Trigger | Deep health, live canary, manual smoke, Kuma, or log checks fail. | Failure is reproducible or high-confidence. | Failed check, timestamp, report path. |
| Decision | Rollback owner decides within the rollback deadline. | Decision time is within the configured threshold. | Decision timestamp and operator name. |
| DNS | Point production DNS or traffic route back to the old server. | Authoritative DNS or route shows old-server target. | DNS query result, TTL, expected propagation. |
| App | Verify the public app route on the old server. | Homepage, login, booking, dashboard, and PDF smoke pass as applicable. | Smoke result and timestamp. |
| DB | Apply the selected write policy. | Write-freeze/read-only/divergence decision is recorded. | DB decision note without data contents. |
| Kuma | Resume old-server monitoring or end maintenance. | Required monitors are green or intentionally paused with reason. | Monitor status summary without Push URLs. |
| Target | Keep failed target intact for diagnosis unless it contains sensitive temporary artifacts that must be removed. | Target no longer receives production traffic. | Target state note. |

If DNS rollback cannot be validated inside the maximum accepted downtime, pause
and escalate instead of continuing speculative target fixes.

## Drill C: Target Same-Host Deploy Rollback

Use this path only for a failed artifact deploy on the target host.

| Step | Action | Validation | Evidence |
| --- | --- | --- | --- |
| Trigger | `deploy_ea.sh` fails during target-host deploy or post-switch validation. | Script exits non-zero. | Exit code and report path. |
| Same-host rollback | Let `deploy_ea.sh` restore the previous target app directory. | Exit `30` indicates automatic rollback succeeded. | Exit code `30` and target health result. |
| Escalation | If exit `31`, treat target host as failed and use old-server migration rollback if traffic moved. | Old production route is healthy or can be restored. | Exit code `31`, rollback owner decision. |
| Boundary | Do not assume target same-host rollback returned production traffic to the old server. | DNS/route is checked separately. | DNS or route evidence. |

## Kuma and Monitoring

- Put Kuma into maintenance only for a real endpoint move.
- Resume Kuma after renderer health, deep health, and live canary pass.
- If rolling back after DNS switch, confirm Kuma is again checking the old
  server route or intentionally paused.
- Keep Push URLs and monitor tokens in host-local files only.
- Do not attach Kuma screenshots if they expose Push URLs or notification
  secrets.

## Database and Write Safety

Choose one write policy before the cutover window:

- Write freeze: pause booking/admin writes during cutover and rollback window.
- Short divergence accepted: allow writes during cutover and accept that a
  rollback may require manual reconciliation.
- Read-only rollback: rollback serves availability first, then reconcile writes
  before reopening write paths.

The drill must record which policy applies. Do not document row contents or
personal data as evidence.

## Dry-Run Rehearsal

A rollback dry-run may be completed without changing DNS:

- Record the current authoritative DNS target and TTL.
- Confirm the operator can access the DNS change path.
- Confirm old-server app smoke steps are known.
- Confirm Kuma maintenance/resume steps are known.
- Confirm the rollback owner can make the decision within the default
  `10`-minute deadline.
- Confirm no step requires a secret value to be pasted into chat or docs.

Dry-run success means the rollback path is operable on paper. It does not prove
DNS propagation timing or live production behavior.

## Evidence To Record

- rollback owner and decision timestamp
- trigger and failed validation
- selected rollback layer
- DNS/route target before and after rollback
- old-server HTTP or manual smoke result
- DB write policy decision
- Kuma maintenance/resume status
- target-host final state
- final outcome: abort, rolled back, retried, accepted, or paused

## Stop Conditions

- The old production server is not healthy enough to serve rollback traffic.
- Rollback owner or backup operator is unknown.
- Rollback decision deadline or maximum accepted downtime is not agreed.
- DNS rollback access is unavailable or unverified.
- DB write policy is unclear.
- A rollback step would require documenting a secret value, Push URL,
  `config.php`, DB contents, Kuma DB contents, or archive contents.
- A later cutover task has not authorized production DNS, DB, deploy, Kuma, or
  server mutation.
