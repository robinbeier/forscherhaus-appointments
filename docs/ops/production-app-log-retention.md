# Production Application-Log Maintenance

Keep error monitoring and the existing disk-space warning. Daily application
logs do not need a repository-owned cleanup helper, timer, or success marker.
This repository does not impose automatic 60-day deletion. Unlike journald,
the application does not inherit systemd's native journal size limits.

## Inspect before deciding

Start with `bash scripts/ops/prod_doctor.sh` and
`bash scripts/ops/prod_cleanup_inventory.sh`. The inventory reports the log
root size; it does not decide that all files there may be deleted.

If disk pressure warrants further inspection, use the approved SSH target in
[agent-operations.md](agent-operations.md). Read only file metadata and report
aggregate counts and sizes by age for regular top-level `log-YYYY-MM-DD.php`
files under `/var/www/html/easyappointments/storage/logs`. Distinguish these
from diagnostic reports and nested directories; never print log contents.
File modification age is an observation, not proof that history is disposable.

## Manual cleanup when needed

1. Confirm host health, free space, and the category responsible for growth.
   Check whether old daily logs are large enough to justify cleanup.
2. Preserve records needed for current incidents. Agree on the exact daily-log
   selection and obtain explicit approval before deleting anything. A historic
   60-day threshold is not standing deletion authority.
3. Recheck the selected regular files' location, type, age, and identity before
   deletion. Exclude today's log, changed or unexpected objects, and all nested
   directories. Do not use a recursive deletion of the log root.
4. Compare aggregate sizes afterwards and run
   `bash scripts/ops/prod_validate_after_change.sh`.

Retain `release-gate/`, `ci/`, `ops/`, access-control files, and dashboard/provider
PDF diagnostic reports. Ordinary error detection through `kuma_push_app_logs.sh`
and its log-classification library remains unchanged. Deleted log history has
no in-place rollback; preserve needed evidence first.

If repeat inspections show meaningful sustained growth, first investigate its
cause, then consider the simplest suitable retention mechanism.

## Existing installations

On the host checked on 2026-09-05, the retired helper, service, timer, and success
marker were absent; its optional retention monitor was disabled. Repository
removal does not uninstall anything on another host.

The immutable Kuma v1 bundle remains byte-identical, including its old,
disabled-by-default app-log-retention branch. Do not enable that branch after
retiring the helper. If another host already uses it, retain its live helper
until its monitoring and scheduled cleanup are separately addressed. The
ordinary app-error and host-resource monitors remain available.
