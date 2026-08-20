# Production legacy release hold

`legacy_release_hold.v1` is a permanent, host-local safety record for the two
legacy Current/Rollback archives that cannot be assigned a historical commit.
It makes no provenance claim and does not create or modify archives or
provenance sidecars. The canonical file is root-owned mode `0600`, single-link,
at `/etc/fh/legacy-release-hold.v1.json`.

The helper has a read-only default inspection mode. Provisioning is accepted
only as the exact argument vector `provision ROB-470-HOLD`; the operator wrapper
requires `--provision --confirm-live-write ROB-470-HOLD`. Both fixed markers and
both archives are completely preflighted before the global production lock and
publication lock are crossed. Archive metadata is streamed under bounded tar
entry, member, inode, and unpacked-byte limits. Only bounded helper-owned nonce
temps under `/etc/fh` may be created or removed, and an existing identical hold is
left untouched.

Provisioning performs an initial aggregate preflight, acquires and verifies the
global production lock followed by `.release-pair.lock`, then repeats the
complete marker/archive/hold preflight under both locks before the first
mutation. Publication uses directory-FD anchored Linux `renameat2` with
`RENAME_NOREPLACE`; the attached file is re-opened and verified as root-owned,
`0600`, single-link bytes before the directory fsync.

Retention treats an exact held archive as `legacy_unverifiable_hold`. It is
permanently protected, including after marker rotation, and never appears in a
deletion set. An archive-only prefix that does not exactly match a hold target
by release ID, name, SHA-256, and size fails closed. A missing or unsafe held
archive, target, or hold file also fails closed. No commit, release provenance,
or caller-supplied identity is accepted.

For every held archive, retention re-hashes and re-runs the same bounded safe
Tar contract on one stable file descriptor. The live capacity bounds must match
the canonical hold exactly before they can influence the capacity projection.

Repository delivery, tests, CI, and merge do not authorize installation,
provisioning, retention execution, timer activation, or any other production
action. This repository work does not authorize installation. Those require
separate explicit approvals.
