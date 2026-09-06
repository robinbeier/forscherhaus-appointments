# Production legacy release hold

`legacy_release_hold.v1` is a permanent, host-local safety record for the two
legacy Current/Rollback archives that cannot be assigned a historical commit.
It makes no provenance claim and does not create or modify archives or
provenance sidecars. The canonical file is root-owned mode `0600`, single-link,
at `/etc/fh/legacy-release-hold.v1.json`.

The one-time provisioning helper has been retired from the repository. The
single production server already has the completed hold; it is not recreated
for deployments or routine cleanup. Keep the existing record with the protected
host configuration, including when restoring that host. Removing the helper
is not authorization to remove or rewrite the record or its archives.

The retention helper reads and validates the hold directly; it does not import
or invoke the retired provisioning helper. Its inspection remains available
through `prod_release_archive_dump_retention.sh` in the default read-only mode.

Retention treats an exact held archive as `legacy_unverifiable_hold`. It is
permanently protected, including after marker rotation, and never appears in a
deletion set. An archive-only prefix that does not exactly match a hold target
by release ID, name, SHA-256, and size fails closed. A missing or unsafe held
archive, target, or hold file also fails closed. No commit, release provenance,
or caller-supplied identity is accepted.

For every held archive, retention re-hashes and re-runs the same bounded safe
Tar contract on one stable file descriptor. The live capacity bounds must match
the canonical hold exactly before they can influence the capacity projection.

Repository cleanup does not remove any installed host file or authorize
retention execution, timer changes, or other production mutations.
