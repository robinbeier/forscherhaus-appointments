# One-Time Manual Release Cleanup

This path is a bounded, one-time operator procedure for removing selected old
release directories from the production web root. It is separate from the
inactive automatic archive, dump, and release retention service and does not
reactivate that service.

The procedure has two invocations: a read-only plan and an execution that must
be supplied the exact SHA-256 digest of that plan. The plan binds the selected
directory identities, contained release IDs, ages, sizes, inode counts,
metadata fingerprints of every nested path, and archive identities. Any
change between planning and execution stops the run.

The global cleanup lock and the production-change lock must be available. The
procedure stops on active production work, open candidate files, pending
cleanup markers, non-terminal runs, missing or unsafe archives, unknown
previous-release entries, identity drift, or any protected current/rollback change.

Only previous-release directories at least seven days old can be selected,
with a maximum of four oldest candidates per plan. The active release and its
exact rollback directory are protected. Stage and failed directories are
ignored as deletion classes, even when their contents look unsafe. Archives,
backups, stages, the active release, and rollback are preserved.
The listing stops before recursive validation if the web root exceeds the
retention helper's 10,000-entry class limit or has more than 64 previous-release
entries; a larger backlog requires a separate decision.

The script is an operator-only production path. Production installation,
ownership and mode checks, lock setup, and post-run health verification are
release-gated operational work and must be documented and performed by the
primary release owner; this document does not authorize a production run.
