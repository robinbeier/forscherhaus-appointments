# PHP-FPM service hardening

The reviewed drop-in is `scripts/ops/systemd/php-fpm-hardening.conf`. Install it
as `/etc/systemd/system/php8.5-fpm.service.d/fh-hardening.conf` only after the
backup, candidate trial and rollback prerequisites below. It preserves the
existing PHP unit and runtime environment drop-in.

## Boundary and required paths

`NoNewPrivileges=yes` prevents gaining privilege through executed programs.
`PrivateTmp=yes` separates PHP temporary files from other services.
`ProtectHome=yes` hides home directories. `ProtectSystem=strict` makes the
filesystem read-only except the explicitly writable parents and private tmp.

The writable exceptions are deliberately stable parent directories:

- `/var/www/html`: `deploy_ea.sh` renames the application and stage directories
  and then reloads FPM. A mount bound to the old `storage` inode would not follow
  that replacement. The existing deployment permission policy is therefore
  essential: code and application roots remain root-owned and not group/world
  writable; only runtime storage is web-owned. This is not a read-only mount of
  the application source. Worker write protection there also depends on Unix
  ownership/modes, verified before activation.
- `/run/php`: PID and FastCGI socket, including the existing socket helper.
- `/var/log`: PHP's master log and its rename/recreate/reopen lifecycle under
  the existing logrotate policy. Workers retain their existing unprivileged
  identity and cannot write the root-owned PHP log or log parent.

The application needs the complete storage tree: session files, logs and CSP
aggregate/temporary files, cache, root-level rate-limit lock files, uploads and
application backup output. A subdirectory-only allowlist would miss the lock
and storage-readiness contracts. Regular CAPTCHA uses `build()` in memory;
its unused OCR helper is not enabled by this change. PDF rendering communicates
with the existing renderer over HTTP; MariaDB socket access remains available.
No network isolation, syscall filter, PHP configuration or application code
change is included.

## Evidence contract and activation

1. Bind the active release, unit/drop-in hashes, master PID and confirmed paths.
   Confirm the root-owned source permission policy and a fresh application
   backup with isolated restore. Preserve the previous unit state privately.
2. Trial the exact properties in a transient systemd namespace on the same
   host. Prove filesystem write denial outside exceptions, private temporary
   storage, hidden home state, and writes inside a disposable synthetic
   directory. Include rename/recreate behavior under a stable writable parent.
3. Independently review the candidate and operator. Acquire the existing
   production-change lock. Install atomically, daemon-reload and restart FPM;
   preserve an SSH-independent automatic rollback on failed verification or
   interruption. An operator failure never restores database contents.
4. Verify actual FPM workers, not only CLI PHP: session write/reopen/destroy,
   storage create/read/remove, DB read, CAPTCHA generation and PDF rendering.
   Use a root-controlled temporary FastCGI script outside the public document
   root; it must print only booleans and clean up its exclusively owned files.
   Verify effective NoNewPrivs and namespace mounts, source write denial and
   temporary-directory isolation. Remove the probe after the run.
5. Run `bash scripts/ops/prod_validate_after_change.sh`, inspect classified new
   application/PHP errors and verify the unchanged active application release.
   Verify a graceful FPM reload and the normal log-reopen command before
   declaring the change complete. A trial directory rename is not a full live
   application deployment test.

For rollback, restore only the captured previous drop-in state, daemon-reload,
restart the same PHP service and rerun the functional checks. Preserve secrets,
application files, PHP configuration, database contents and independent backup
services. Do not blindly rerun an old operator against changed release/unit
identities. The retained run journal records completion versus rollback.

## Sources

The filesystem, privilege and temporary-directory semantics follow the
[systemd execution documentation](https://www.freedesktop.org/software/systemd/man/latest/systemd.exec.html).
The owning deployment behavior and permissions are in `deploy_ea.sh`;
application storage paths are in `application/config/config.php`,
`application/config/hooks.php`, `application/controllers/Healthz.php` and
`application/core/Csp_report_only.php`. See [production operations](agent-operations.md)
and [update maintenance](update-maintenance.md) for ongoing checks.
