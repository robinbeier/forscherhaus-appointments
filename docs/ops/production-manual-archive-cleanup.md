# One-Time Manual Archive Cleanup

`manual_archive_cleanup_v1.py` is a bounded, operator-only path for the
specific maintenance case in which old release archives must be reduced to
complete archive/provenance pairs. It is separate from the general retention
service, which remains disabled and is never called by this tool.

The read-only invocation is:

```text
sudo python3 /usr/local/libexec/manual_archive_cleanup_v1.py plan
```

The returned plan digest is the only input accepted by execution:

```text
sudo python3 /usr/local/libexec/manual_archive_cleanup_v1.py execute PLAN_SHA256
```

The plan reads the active release marker and its direct rollback marker, then
binds their release IDs, every selected archive and canonical
`*.build-provenance.json` sidecar identity, SHA-256, size and timestamps. It
selects at most four oldest complete historical pairs. There is no arbitrary
age threshold. The active and direct rollback pairs, valid legacy-held pairs,
foreign files, incomplete pairs, and all unrelated directories remain
protected and are counted in the JSON result where applicable. Any ambiguous
pair inventory fails closed.

Before a run, the release owner must verify the installed helper is root-owned,
mode `0555`, and matches the pinned SHA-256 in the script. The production
change lock and cleanup lock must be available. The same nested-mount boundary
check used by the manual release operator runs against the web root; active
production work and nonterminal orchestrator runs block the operation. Review
the plan and obtain the normal release approval before passing its digest to
`execute`.

Execution revalidates the entire plan immediately before mutation. Each pair
is transferred into the root-only state directory under a no-clobber pending
name using an atomic hardlink followed by source unlink; this keeps destination
creation no-clobber while retaining recovery if the second step fails. The
parent directories are fsynced, and both files are rehashed and checked again.
Only after the pair passes those checks are the two exact pending files
unlinked. If a sidecar transfer, revalidation, open-file check, activity check or
post-quarantine check fails, the pending object is left in place and the JSON
result is blocked with exact mutation counts and an `unknown` outcome when a
partial move occurred; operators must classify and recover that state before
another run. A subsequent plan refuses to retry while any pending archive
marker remains. Directories, backups, dumps, stages,
failed releases and unknown foreign entries are never deletion targets.

Postflight must confirm that the active and direct rollback markers still open,
the expected pair count and exact mutation counts match the approved plan, and
the application health checks remain green. Installation, ownership changes,
approval, recovery and production execution are release-owner actions; this
document is an operating contract, not authorization to run it.
