# Read-only maintenance inventory

The bounded helper reports a secret-free JSON inventory only:

```sh
python3 scripts/ops/update_inventory.py --role booking
python3 scripts/ops/update_inventory.py --role learning
```

It never runs `apt update`, installs packages, pulls images, restarts services, or reboots. It checks fixed package lists, installed/candidate APT metadata, release-file freshness, fixed container names, selected immutable image metadata, running kernel/OS/reboot markers (without requiring an optional kernel meta-package), the learning Node binary, and only fixed APT timer statuses. Container image update availability remains explicitly `unknown` until a separate comparison with current manufacturer/registry releases or advisories. A container without a configured Docker healthcheck is reported as `not_configured`, not unhealthy; application HTTP validation is a separate probe and is not implied here.

Missing containers, failed APT/python-apt metadata, failed Node metadata, non-running services, or other required host inventory failures exit 2 and are fail-closed. Missing release metadata or a missing/older-than-seven-day successful APT refresh stamp is `status: review`, never healthy. A package candidate that cannot be compared, an absent package, or an untrusted origin is likewise review/fail-closed. Image update availability remains `unknown` and therefore gives exit 0 with `status: review`; it is not permission for an upgrade.

The intended review cadence is Monday at 09:00 Europe/Berlin through the Codex heartbeat. The heartbeat performs only this read-only check; any SSH invocation must use the existing host-specific path (`root@booking-server` or the Lernplattform `admin-ssh.conf`). Fresh package lists, official Docker/Tailscale advisories and releases, and the host Node release are reviewed separately. Findings are classified `current`, `remediable`, or `unknown`; controlled updates require a separate order, backup, testing, serial rollout, and rollback plan. No blind automatic upgrade is permitted.

Primary references: [Ubuntu automatic updates](https://ubuntu.com/server/docs/how-to/software/automatic-updates/), [Docker Engine install/update guidance](https://docs.docker.com/engine/install/), and [Tailscale package documentation](https://tailscale.com/kb/1031/install-linux).
