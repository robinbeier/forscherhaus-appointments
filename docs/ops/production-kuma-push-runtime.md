# Production Kuma Push Runtime

The monitoring entrypoints run from the root-controlled directory
`/usr/local/libexec/fh-kuma-push-runtime-v1`, outside the mutable application
release. Production cron does not execute monitoring code from that release.
The one-time ROB-489 installation/cron migration is complete; its installer and
wrapper are retired. The `v1` directory name remains the installed path, not a
requirement to build another package-version framework.

## Package and current state

The repository payload excludes the retired journal, Host Services and Scanner
Activity monitors. An older installed bundle may retain their unused scripts for rollback;
retirement of their cron entries is a separate approved production operation.
See [Kuma operations](../uptime-kuma.md#retired-monitors).
Compare installed files with their recorded installed revision, not a newer
manifest that has not been installed.

`scripts/ops/config/kuma_push_runtime_bundle_v1.json` lists the complete payload:
five entrypoints, two shell libraries, the dashboard PDF gate and its three PHP
libraries. Each row binds its source/install path, role and SHA-256. Keep the
manifest and changed source hashes together in the same reviewed commit. Repository checks retain the closed
payload, cron contract and execution of the bundled PDF gate.

The canonical cron file is `scripts/ops/config/fh-uptime-kuma-push.cron`: six
invocations of five entrypoints, including the twice-per-minute app-log check.
Changing a package does not authorize changing its schedules, environment file,
log targets, monitor identities or notification settings.

Installed files remain regular, root:root, mode 0555 and single-link. Directories
are root:root, mode 0755; ancestors must not be group/world writable. Reject
symlinks, hard links, extra/missing files and unexpected hashes. Keep the Push
environment and credentials host-local and protected. The PDF check passes the
password to its bundled gate through standard input, never process arguments.

The PDF entrypoint uses only its bundled gate code. `KUMA_PDF_EXPORT_APP_ROOT`
selects application data/config, never executable code. The historical
`KUMA_PDF_EXPORT_REPO_ROOT` name remains a compatibility fallback.

A merged repository change is not proof that production has been updated.
Compare installed hashes with the manifest from the recorded installed commit;
use the new commit's manifest only for the proposed replacement. Old migration
recovery records remain host-owned; retiring their writer does not authorize
removing those records or make them a mandatory dependency of every update.

## Bounded package replacement

There is no general-purpose updater. Prepare a concrete, independently reviewed
operation for each required replacement and obtain explicit production approval.
The procedure below is a planning boundary, not an executable update command.

Use a single-file exchange only when the old and new immutable commit manifests
prove that exactly one installed entrypoint differs. All other payload files,
paths and metadata, cron bytes, environment, monitor configuration and the
caller/interpreter contract must be unchanged. Check dependencies in both
versions: neither may reopen its own source or re-execute itself, and all loaded
code must remain unchanged. Keep existing runtime locks and lock paths; prove
state-format compatibility in both directions during overlapping old/new calls
and rollback. Any uncertainty, shared-library change, multiple changed files or
incompatibility requires the coordinated directory exchange below.

1. **Bind and inspect.** Select an independently reviewed, merged commit with
   green required checks. Resolve the exact Git object with replacement refs
   disabled. Export the selected replacement file or complete directory from
   the manifest's closed payload, never from a writable worktree. Validate
   paths, hashes, ownership, modes and links in private root-controlled staging
   outside the closed runtime and on the same filesystem. Extraction must discard
   archived owner and mode metadata. Record current installed hashes and exact
   cron bytes and identity without printing secrets.
2. **Coordinate and preserve.** Acquire `/var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock`
   nonblocking after verifying root ownership, mode 0600, single-link regular
   type and identity; revalidate its identity after acquisition. Hold it through
   postflight or rollback and confirm no conflicting operation is active.
   Preserve the old file (single-file exchange) or complete old runtime
   (coordinated exchange), plus the cron snapshot, as root-only same-filesystem
   backups verified against the recorded installed manifest. Prepare and verify
   an executable rollback before publication; retain backups through postflight.
   For a single-file exchange, leave cron and runtime locks untouched: no pause
   or process drain is needed, and running Bash processes retain their old file
   inode. For a coordinated exchange, atomically replace the exact canonical
   cron object with a saved paused form of its recorded invocations. The
   reviewed operation must identify and drain their complete process trees,
   including PDF children, with a bounded timeout. If ownership or completion
   cannot be established, abort and restore the guarded cron. A directory rename
   alone does not protect a running script that later opens another bundled
   file. Do not stop the host cron service or alter other jobs.
3. **Exchange and verify.** Immediately before publication, revalidate the global
   change-lock identity, staging, and the expected target identity, hash and
   metadata. Never write the installed file in place. Publish the validated file
   by atomic rename, or the complete directory by atomic directory exchange, on
   the same filesystem. Durably sync staged content and the publication. Revalidate the complete installed
   payload against the target manifest, metadata, changed monitor behavior,
   bundled PDF execution and exact original cron configuration. For a coordinated
   exchange, restore paused invocations only after these checks. Do not overwrite
   a concurrently changed cron object; verify its identity and expected bytes
   before any replacement.
4. **Validate or return.** Check normal production health/log summaries and wait
   for fresh regular monitor results, including slower Push monitors. On a known
   failure, restore the verified old file by the same guarded atomic exchange;
   cron remains untouched for the single-file path. For a coordinated failure,
   pause/drain the invocations again and restore the old directory and exact
   prior cron together. Verify recovery in either case. Restore only when the
   current target identity, hash and metadata still match this operation's
   expected state; apply the same identity/metadata/bytes check to any cron
   restoration. Never overwrite a concurrent writer. Preserve their object and
   both versions, and report a failed or uncertain rollback for a separate
   recovery decision. After an unknown transport/mutation result, retain both
   versions and staging, inspect read-only and decide recovery separately;
   never retry blindly or delete evidence.

Use [agent-operations.md](agent-operations.md) for current production diagnostics.
Keep the operation's backup and evidence root-only. Review any later cleanup
separately; a successful replacement is not permission to delete recovery data.
