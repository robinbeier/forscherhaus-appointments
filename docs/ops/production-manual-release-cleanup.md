# One-Time Manual Release Cleanup

This path is a bounded, one-time operator procedure for removing selected old
release directories from the production web root. It is separate from the
inactive automatic archive, dump, and release retention service and does not
reactivate that service.

The procedure has two invocations: a read-only plan and an execution that must
be supplied the exact SHA-256 digest of that plan. The plan binds the selected
directory identities and root change times, contained release IDs, ages, sizes, inode counts,
metadata fingerprints of every nested path, and the complete archive/provenance
pair's identities and hashes. Any
change between planning and execution stops the run.

Before any file is unlinked, each selected directory is moved into the
root-only retention state directory. The tool checks the quarantined tree's
complete metadata fingerprint and open-file state again. A changed or open
tree stays quarantined as a pending recovery object and blocks another pass;
it is never silently deleted or automatically restored. The operator must
classify that state before any further production change.

The global cleanup lock and the production-change lock must be available. The
procedure stops on active production work, open candidate files, pending
cleanup markers, non-terminal runs, missing or unsafe archives, unknown
previous-release entries, identity drift, or any protected current/rollback change.

Only previous-release directories at least seven days old with a complete,
canonical archive/provenance pair can be selected. Releases named in the
permanent host-local legacy hold are preserved. The archive pair is checked
again after quarantine and before deletion. A missing or invalid pair blocks
the plan rather than authorizing deletion. At most four oldest candidates are
selected per plan. The active release and its exact rollback directory are
protected. Stage and failed directories are
ignored as deletion classes, even when their contents look unsafe. Archives,
backups, stages, the active release, and rollback are preserved.
The listing stops before recursive validation if the web root exceeds the
retention helper's 10,000-entry class limit or has more than 64 previous-release
entries; a larger backlog requires a separate decision.

The script is an operator-only production path. Production installation,
ownership and mode checks, lock setup, and post-run health verification are
release-gated operational work and must be documented and performed by the
primary release owner; this document does not authorize a production run.
