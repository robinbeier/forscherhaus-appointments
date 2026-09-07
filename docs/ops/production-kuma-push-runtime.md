# Production Kuma Push Runtime

The nine monitoring entrypoints run from the root-controlled directory
`/usr/local/libexec/fh-kuma-push-runtime-v1`, outside the mutable application
release. Production cron does not execute monitoring code from that release.
The one-time ROB-489 installation/cron migration is complete; its installer and
wrapper are retired. The `v1` directory name remains the installed path, not a
requirement to build another package-version framework.

## Package and current state

`scripts/ops/config/kuma_push_runtime_bundle_v1.json` lists the complete payload:
nine entrypoints, two shell libraries, the dashboard PDF gate and its three PHP
libraries. Each row binds its source/install path, role and SHA-256. Keep the
manifest and changed source hashes together in the same reviewed commit. Repository checks retain the closed
payload, cron contract and execution of the bundled PDF gate.

The canonical cron file is `scripts/ops/config/fh-uptime-kuma-push.cron`: ten
invocations of nine entrypoints, including the twice-per-minute app-log check.
Changing a package does not authorize changing its schedules, environment file,
log targets, monitor identities or notification settings.

Installed files remain regular, root:root, mode 0555 and single-link. Directories
are root:root, mode 0755; ancestors must not be group/world writable. Reject
symlinks, hard links, extra/missing files and unexpected hashes. Keep the Push
environment and credentials host-local and protected.

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

1. **Bind and inspect.** Select an independently reviewed, merged commit with
   green required checks. Resolve the exact Git object with replacement refs
   disabled; export only the manifest's closed payload from that object, never
   from a writable worktree. Validate paths, hashes, ownership, modes and links
   in private root-controlled staging. Extraction must discard archived owner
   and mode metadata. Record current installed hashes and the exact cron bytes
   and identity without printing secrets.
2. **Coordinate and preserve.** Acquire `/var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock`
   nonblocking after verifying root ownership, mode 0600, single-link regular
   type and identity; revalidate its identity after acquisition. Confirm no
   conflicting operation is active. Preserve a complete root-only, same-filesystem
   copy of the old runtime and cron, verified against the recorded installed
   manifest, and retain it through postflight. Atomically replace the exact
   canonical cron object with a saved paused form of its ten invocations.
   The reviewed operation must define how it identifies and drains their complete
   process trees, including PDF children, with a bounded timeout; if ownership
   or completion cannot be established, abort and restore the guarded cron. A directory rename alone does
   not protect a running script that subsequently opens another bundled file.
   Do not stop the host cron service or alter other jobs.
3. **Exchange and verify.** Publish the complete validated directory atomically
   on the same filesystem and durably sync the publication. Preserve the old
   directory for rollback. Revalidate the installed closed payload, metadata,
   the changed resource-monitor behavior, bundled PDF execution and exact original
   cron configuration before restoring
   the paused invocations. Do not overwrite a concurrently changed cron object;
   verify its identity and expected bytes before any replacement.
4. **Validate or return.** Check the normal production health/log summaries and
   wait for fresh regular monitor results, including the slower Push monitors.
   On a known failure, pause/drain these invocations again and restore the
   verified old directory and exact prior cron together, then verify recovery.
   Restore cron only if its current inode, trusted metadata and bytes still
   match this operation's expected object. Preserve any concurrent writer's
   object and both runtime snapshots; report failed rollback for a separate
   recovery decision rather than overwriting it.
   A failed or uncertain rollback must be reported as such. After an unknown
   transport/mutation result, retain both versions and staging, inspect read-only
   and make a separate recovery decision; never retry blindly or delete evidence.

Use [agent-operations.md](agent-operations.md) for current production diagnostics.
Keep the operation's backup and evidence root-only. Review any later cleanup
separately; a successful replacement is not permission to delete recovery data.
