# Production legacy release hold

`legacy_release_hold.v1` is the compatibility contract for a host-local safety
record protecting two archive-only legacy releases without a provable historical
commit. Historical provenance is never reconstructed. New releases receive
provenance through the normal build path.

On 28 September 2026, the production host's two legacy archives and their hold
were retired in a separately authorized, one-time manual cleanup. The current
and direct rollback release pairs remained protected; automatic retention stayed
disabled. The [ROB-641 Codex Workpad](https://linear.app/robins-beiers-workspace/issue/ROB-641/historische-release-holds-entpackte-kopien-nach-restore-nachweis)
records the release identities, bounded deletion plan, journal and final checks.
This dated observation is not a substitute for a fresh host inventory.

The hold makes no provenance claim and does not create or modify archives or
provenance sidecars. When present, the canonical file is root-owned mode `0600`,
single-link, at `/etc/fh/legacy-release-hold.v1.json`.

The one-time provisioning helper has been retired from the repository. The
hold is not recreated for deployments or routine cleanup. If restoring a host
snapshot that still contains it, preserve the exact hold/archive relationship
until a separately authorized disposition is proven. Do not restore a retired
hold merely because an older document described it as permanent.

The retention helper reads and validates the hold directly; it does not import
or invoke the retired provisioning helper. Its inspection remains available
through `prod_release_archive_dump_retention.sh` in the default read-only mode.

When a valid hold is present, retention treats an exact held archive as
`legacy_unverifiable_hold`. It remains protected after marker rotation and
never appears in a deletion set. An archive-only prefix that does not exactly
match a hold target by release ID, name, SHA-256, and size fails closed. A
missing or unsafe archive or target named by an installed hold also fails
closed. Absence of both the hold file and archive-only legacy artifacts is
a valid no-hold state. No commit, release provenance, or caller-supplied
identity is accepted.

The hash-pinned helper comments call this protection "permanent": that means a
valid installed hold is never pruned by ordinary retention. It does not
assert that every host must keep a hold after separately authorized retirement.
This documentation correction does not change the installed helper or its hash.

For every held archive, retention re-hashes and re-runs the same bounded safe
Tar contract on one stable file descriptor. The live capacity bounds must match
the canonical hold exactly before they can influence the capacity projection.
The retained scanner tests in
`tests/Unit/Scripts/release_archive_dump_retention_v1_test.py` cover malformed
Tar entries and capacity accounting and run with the root deployment regressions.

Repository cleanup does not remove any installed host file or authorize
retention execution, timer changes, or other production mutations.
