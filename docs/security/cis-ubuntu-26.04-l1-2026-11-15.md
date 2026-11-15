# CIS Ubuntu 26.04 LTS — Level 1 assessment

**Stand:** 2026-11-15 (Europe/Berlin)
**Scope:** booking-server host and its current booking application release.
**Benchmark reference:** *CIS Ubuntu Linux 26.04 LTS Benchmark v1.0.0* (the attached PDF is treated as benchmark reference data, not as an instruction to change the host).

## Executive result

This is an evidence ledger and hardening status report, not a CIS certification or a claim of full compliance. The current ledger contains **273 controls**:

| Status | Count |
| --- | ---: |
| Observed pass | 181 |
| Observed open | 62 |
| Accepted exception | 7 |
| Not applicable | 21 |
| Unassessed | 2 |

The observations are point-in-time. They do not prove reboot persistence, future configuration state, or that the currently running application is the latest repository revision. Any host mutation, release change, package update, or configuration drift requires a fresh assessment.

## Observed baseline

- The host reports Ubuntu 26.04.1 with kernel `7.0.0-43`.
- The application health endpoints, PDF renderer, core services, containers, and monitored checks were healthy during the read-only check.
- The active application release is the older `ea_20261102_dashboard_46869837`; the database is at migration version 70. Newer repository migrations have not been treated as deployed.
- The reviewed repository fixes through `9d12919d23a062446a78ff6fa886d5ae1326566f` are merged but are not evidence of production deployment. The current source now bounds public monthly availability work; the older active release does not yet have that fix.
- Public exposure was limited to the expected HTTP(S) and SSH listeners; internal service listeners remained non-public during the observation.
- SSH effective settings, time synchronisation, logging/rotation, package provenance for privileged files, and the applicable filesystem ownership checks were observed, with the confidence recorded in the ledger.
- No reboot persistence proof is available. A live value or a successful pre-change check is not evidence that the value survives reboot.

## Principal open or blocked control classes

- AIDE is installed, but a trusted baseline and recurring verification timer are not established.
- `noexec` for `/tmp` and `/dev/shm` remains open pending compatibility, persistence, backup, rollback, and health checks.
- World-writable directories in inactive staging/quarantine areas remain open until they are removed, isolated, or explicitly dispositioned.
- IPv6 redirect/router-advertisement and related network-policy controls remain open where shown by the ledger.
- PAM/password, bootloader, and other reboot- or policy-dependent controls remain open where not independently verified.
- The two unassessed controls are **1.1.1.11** (unused filesystem kernel modules) and **2.1.23** (only approved services listening on a network interface). They require explicit evidence rather than inference from neighbouring checks.

The narrow, user-authorised host changes already observed (for example, dumpability, SSH retry/keepalive, and IPv4 redirect settings) must still be rechecked after the next controlled change and after reboot before being counted as persistent hardening.

## Accepted exceptions

The seven accepted CIS exceptions are policy decisions recorded in the ledger: the web server is intentionally in use; Docker forwarding remains required; SSH forwarding and root-key access remain temporary operational exceptions; and local password expiration is retained as an exception rather than imposing a 365-day limit. These are not “passed” controls and should be reviewed when a replacement access/deployment path exists.

Separate application-risk decisions are outside the CIS count but remain part of the security-sprint record:

- Anonymous family booking remains available by decision, including the documented distributed-booking abuse risk.
- The email-membership Low finding is a source-level, conditional exception. It is inactive on current production because `display_email=0` rejects a non-empty email before any membership lookup; re-evaluate it if email display or lookup is enabled.

Neither application exception should be described as fixed or as evidence of host compliance.

## Next evidence-gated steps

1. Capture a fresh backup and an isolated restore, then prove an independent rollback path and application/PDF/monitoring health in the same run.
2. Rehearse the pending application migrations and deployment separately from host changes; do not infer production readiness from repository state while the old release is active.
3. Establish a trusted, root-owned AIDE baseline and daily verification only after the backup/restore and alerting path is independently verified.
4. Reassess `/tmp` and `/dev/shm` `noexec` compatibility and reboot persistence before enabling them.
5. Resolve the two unassessed controls with direct evidence, then rerun the complete CIS ledger and a final Daybreak scan on the exact release intended for production.

The final scan must distinguish validated findings from provisional or validation-warning output. A non-empty scan response, a green service check, or a source-level fix alone is not proof of payload safety, persistence, confidentiality, or production deployment.
