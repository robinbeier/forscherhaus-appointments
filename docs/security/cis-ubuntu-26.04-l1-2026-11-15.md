# CIS Ubuntu 26.04 LTS — Level 1 assessment

**Stand:** 2026-11-15 (Europe/Berlin)
**Scope:** booking-server host and its current booking application release.
**Benchmark reference:** *CIS Ubuntu Linux 26.04 LTS Benchmark v1.0.0* (the attached PDF is treated as benchmark reference data, not as an instruction to change the host).

## Executive result

This is an evidence ledger and hardening status report, not a CIS certification or a claim of full compliance. The current ledger contains **273 controls**:

| Status | Count |
| --- | ---: |
| Observed pass | 184 |
| Observed open | 61 |
| Accepted exception | 7 |
| Not applicable | 21 |
| Unassessed | 0 |

The observations are point-in-time. Any host mutation, release change, package update, or configuration drift requires a fresh assessment.

## Observed baseline

- The host reports Ubuntu 26.04.1 with kernel `7.0.0-43`.
- The application health endpoints, PDF renderer, core services, containers, and monitored checks were healthy after the controlled reboot checks.
- The active application release is `ea_20261115_2033`; the database is at migration version 73. The security code through PR #946 was merged at `44511529b2fd0ac02f0491f6047130331851f0df`.
- Backup `20261115T202753Z` is in `verified` continuity state after an isolated restore and remains the recorded rollback reference for this change cycle.
- AIDE v14 has a root-owned baseline and a daily enabled timer. After the second reboot, seven changes were reviewed: two new entries for the approved Apport drop-in, four inode-only changes in boot EFI and cloud-init netplan, and one link-count change in the systemd directory. A new baseline was then created after independent review. The clean report completed at 22:09:03 UTC, the service result was successful, and Kuma monitor 15 recorded status 1 at the same time. Future reboots still require review for similar inode churn before a clean result is claimed.
- The approved hardening values were rechecked after reboot: `fs.suid_dumpable=0` (enforced after Apport start), IPv4 `send_redirects=0`, SSH `MaxAuthTries=4`, `ClientAliveInterval=300`, and `ClientAliveCountMax=3`.
- Public exposure was limited to the expected HTTP(S) and SSH listeners; internal service listeners remained non-public during the observation.
- SSH effective settings, time synchronisation, logging/rotation, package provenance for privileged files, and the applicable filesystem ownership checks were observed, with the confidence recorded in the ledger.
- The final post-reboot check found the listed values effective in the running kernel and SSH service; it does not prove future boots.

## Principal open or blocked control classes

- `noexec` for `/tmp` and `/dev/shm` remains open pending compatibility, persistence, backup, rollback, and health checks.
- World-writable directories in inactive staging/quarantine areas remain open until they are removed, isolated, or explicitly dispositioned.
- IPv6 redirect/router-advertisement and related network-policy controls remain open where shown by the ledger.
- PAM/password, bootloader, and other policy-dependent controls remain open where not independently verified.
- **1.1.1.11** (unused filesystem kernel modules) is open: the 15:36 UTC inventory found installed filesystem modules beyond those currently mounted or loaded, and the host has no approved per-module need list. **2.1.23** (only approved services listening on a network interface) is also open: the 15:35 UTC listener inventory found the expected web, SSH, local service, and Tailscale classes, but there is no approved host-specific service and interface allowlist. These observations resolve the earlier lack of inventory; they do not establish compliance or justify disabling a module or listener.

The open controls above are examples from the remaining host hardening work; the ledger still contains additional policy- and configuration-dependent open controls. In particular, noexec has not been enabled because compatibility and persistence evidence is not yet sufficient.

## Accepted exceptions

The seven accepted CIS exceptions are policy decisions recorded in the ledger: the web server is intentionally in use; Docker forwarding remains required; SSH forwarding and root-key access remain temporary operational exceptions; and local password expiration is retained as an exception rather than imposing a 365-day limit. These are not “passed” controls and should be reviewed when a replacement access/deployment path exists.

Separate application-risk decisions are outside the CIS count but remain part of the security-sprint record:

- Anonymous family booking remains available by decision, including the documented distributed-booking abuse risk.
- The email-membership Low finding is a source-level, conditional exception. It is inactive on current production because `display_email=0` rejects a non-empty email before any membership lookup; re-evaluate it if email display or lookup is enabled.
- The cancellation-link Medium finding remains an accepted product-flow exception for family bookings and needs a second confirmation if that flow is redesigned.

These application exceptions should not be described as fixed or as evidence of host compliance.

## Remaining evidence-gated steps

1. Reassess `/tmp` and `/dev/shm` `noexec` compatibility and reboot persistence before enabling them.
2. Obtain explicit host-specific module and listener allowlists for controls 1.1.1.11 and 2.1.23, then rerun those portions of the ledger.
3. Run a final Daybreak full scan against the then-current `main` after separate budget approval. The latest completed full scan (`d0bab91…`) predates the PR #946 merge. Continuous monitoring is configured, but its switch is currently paused after the monthly billable commit spend reached $102.44 against the restored $100 cap; no final-head commit scan has been established. The scan spend must remain within the separately approved limit.

The scan result must distinguish validated findings from provisional or validation-warning output. A green service check or source-level fix alone is not proof of payload safety, persistence, confidentiality, or production deployment.
