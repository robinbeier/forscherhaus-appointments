# Production Legacy Release Provenance (ROB-468)

This document is the contract and operator boundary for a future installed root
helper plus operator wrapper. It covers exactly the two protected legacy
archives named by the host as `current` and `rollback`. It does not authorize a
host run, SSH/SCP transfer, or production activation.
Merge is not production approval.

## Closed implementation boundary

The expected repository sources are:

- `scripts/ops/libexec/legacy_release_provenance_v1.py`
- `scripts/ops/prod_legacy_release_provenance.sh`

The production copy of the helper must be installed separately, root-owned and
mode `0555`, as `/usr/local/libexec/fh-legacy-release-provenance-v1`. The
wrapper may expose only aggregate status and may invoke only
`/usr/bin/python3 -I -B /usr/local/libexec/fh-legacy-release-provenance-v1`
with fixed `inspect`, or fixed `execute ROB-468`, through the normal operator
SSH boundary. It must
derive the two target paths, release identities, archive hashes, tar member
names, commit values, and temporary paths from the host-local authorization and
installed `deploy_ea`; callers may not supply any of them.

Authorization is the fixed canonical host-local file
`/etc/fh/legacy-release-provenance-authorization.v1.json`; `/etc/fh` is
root-owned mode `0700`, and the file is root-owned mode `0600` with one link.
The helper rejects an absent, symlinked, non-canonical, incorrectly owned, or
incorrectly permissioned authorization. It accepts no caller path, release ID,
hash, commit, member name, or temp path.

The exact canonical JSON shape is:

```json
{"schema":"legacy_release_provenance_authorization.v1","targets":[{"expected_commit":"<40 lowercase hex>","release_id":"<host-local current ID>","required_members":{"build_release.sh":"<sha256>","composer.lock":"<sha256>","deploy_ea.sh":"<sha256>","package-lock.json":"<sha256>"},"role":"current"},{"expected_commit":"<40 lowercase hex>","release_id":"<host-local rollback ID>","required_members":{"build_release.sh":"<sha256>","composer.lock":"<sha256>","deploy_ea.sh":"<sha256>","package-lock.json":"<sha256>"},"role":"rollback"}]}
```

The actual file must be canonical compact JSON with exactly those keys, a
single trailing newline, `current` first and `rollback` second. The two release
IDs must differ. Placeholder values above are documentation only and are not a
valid production authorization. Production values must be derived and
provisioned on the host through a separately approved process; they must never
be passed to the wrapper, printed, committed, or copied into an operator log.

## Fixed host prerequisites

The helper does not create or repair its authority boundary. Before a future
inspection, a separately authorized installation must verify all of these
fixed objects:

- `/var/lib/fh-deploy-orchestrator` and its `locks` child are root-owned mode
  `0700`;
- `/var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock` is an
  existing, empty, root-owned mode `0600` regular file;
- `/root/releases` is root-owned mode `0700`, and its existing
  `.release-pair.lock` is an empty, root-owned mode `0600` regular file;
- `/root/deploy_ea.sh` is a root-owned, single-link regular file with mode
  `0555` or `0755`;
- the active marker is the fixed `/var/www/html/easyappointments/_RELEASE`,
  and the rollback directory is derived only as
  `/var/www/html/easyappointments_prev_<current marker ID>`;
- both markers, both authorized `<release>.tar.gz` archives, and any existing
  authorized sidecars satisfy their closed owner, mode, link, and size checks.

The installed helper, authorization, locks, markers, archives, and
`deploy_ea.sh` are inputs to inspection. Repository delivery does not install,
create, edit, or inspect any of those host objects.

## Safety and publication contract

Every invocation acquires the global production lock first, then the
publication lock. It performs complete authorization, metadata, marker, path,
file-descriptor, tar, required-member-hash, and installed-`deploy_ea` binding
preflight for both `current` and `rollback` before the first mutation. A failure
in either target blocks the entire pass. The authorization's `deploy_ea.sh`
digest for both targets must equal the digest of the fixed installed
`/root/deploy_ea.sh`.

The tar contract is closed: stable marker and path identity checks use
`O_NOFOLLOW`, directory/file-descriptor checks, and canonical paths. Tar
entries are streamed under fixed count and unpacked-size ceilings; duplicate,
absolute, traversal, control-character, backslash, link, device, FIFO, socket,
and other non-file/non-directory entries are rejected. The four required root
members named in the authorization must be regular files and must match their
exact SHA-256 values. The provenance sidecar is canonical
`release_build_provenance.v1`, binding the authorized release and commit, the
observed archive digest and size, exact source hashes, and conservative stage
capacity bounds.

Publication writes only the two authorized
`<release>.build-provenance.json` sidecars and helper-owned
`.<release>.build-provenance.json.rob468-<32 lowercase hex>.tmp` files below
`/root/releases`. It fsyncs file and directory state, then attaches the exact
bytes with Linux `renameat2(..., RENAME_NOREPLACE)`. Existing canonical exact
sidecars are attached without mutation; existing mismatched sidecars block the
pass and are never replaced. An exact helper-owned temp may be reused or
removed only in execute mode after the two-target preflight; a foreign or
mismatched helper temp blocks the pass. Archives, markers, other sidecars, and
other files are never changed or deleted.

The result schema is `legacy_release_provenance_result.v1`. It contains only
mode, status, one public reason class, the aggregate counts `preflighted`,
`pending`, `published`, and `attached`, plus the aggregate mutation counts
`sidecars_published`, `temp_files_created`, and `temp_files_removed`. Internal
validation details are mapped to the public classes `archive_invalid`,
`authorization_invalid`, `host_contract_invalid`, `lock_busy`,
`metadata_invalid`, `publication_blocked`, or `internal_error`.

`mutation_outcome` always distinguishes `none`, `known`, or `unknown`.
`unknown` is mandatory when a process, signal, or host connection ends after a
mutation boundary but before one canonical result is available; it must never
be converted to `none`. If the wrapper cannot validate one complete canonical
remote result, it emits only `transport_result_unavailable`; execute is then
`unknown`, while inspect remains `none` because inspect has no mutation path.
Output is aggregate-only: no release IDs, paths, hashes, commits, tar member
names, temporary names, raw authorization values, or unvalidated remote output
appear.

## Operator modes

Inspection is read-only by default and must not create, replace, or remove any
file. Both wrapper and installed helper require the exact token `ROB-468` for
execute mode in addition to the explicit execute switch; near-miss issue tokens
and caller-supplied target arguments are rejected.

The intended local inspection shape is:

```bash
bash scripts/ops/prod_legacy_release_provenance.sh
```

The execute shape is:

```bash
bash scripts/ops/prod_legacy_release_provenance.sh \
  --execute \
  --confirm-live-write ROB-468
```

That command is documentation, not authorization. It may be used only in a
later production task after fresh host-state verification and separate direct
approval for that exact execution. This repository task does not run it,
install it, prepare host authorization, connect to a host, or grant production
authority.

## Required integration matrix

The implementation and its integration harness must cover, without exposing
production values:

| Area | Required cases |
| --- | --- |
| Metadata | canonical schema and key set; fixed role order; distinct targets; marker/authorization/commit binding; installed `deploy_ea` binding; missing or stale metadata |
| Tar | streaming limits; safe regular files/directories; traversal, absolute, duplicate, link, device and malformed entry rejection; exact required member set and hashes |
| Authorization | fixed canonical path; root ownership; single link; exact mode `0600`; canonical bytes; symlink, permission, owner and identity rejection |
| Host-Script | fixed installed helper and Python argv; no SCP or deploy-tree source; inspect default; exact execute token; rejection of caller-supplied sensitive options |
| Crash | failures before mutation remain `none`; confirmed mutations remain counted as `known`; uncertain syscall, signal, partial result, and connection-loss boundaries report `unknown` and never `none` |
| Concurrency | existing global lock before existing publication lock; nonblocking contention; stable FD/path identity; exact no-replace attach race; both-target preflight before mutation |
| Redaction | canonical aggregate-only result; public reason classes; invalid arguments and invalid/partial remote results cannot echo IDs, paths, hashes, commits, member names, temp names, or secrets |
| Retention | emitted sidecars validate against the existing retention consumer; only the two current/rollback sidecars and exact helper-owned temps are mutable; no archive or unrelated-sidecar deletion |

All repository checks are local and deterministic: they run no SSH, no SCP, no
host inventory, and no production execution. Shipping the closed SSH invocation in the
operator wrapper is not permission to run it. Any later host rollout needs
fresh authorization, installation verification, read-only inspection, and a
separate production decision before execute mode.

## Future rollout and rollback boundary

A future production task must be separately authorized and proceed in this
order: verify the merged commit and host prerequisites; install the root-owned
mode `0555` helper; securely create the root-owned canonical mode `0600`
authorization from independently verified host facts; run the default
read-only inspection; obtain a new direct execute approval; run execute once;
then repeat read-only inspection and the retention compatibility checks.

Rollback or uninstall may remove only the installed helper and, under its own
separate approval, the authorization file. It must not delete either published
sidecar, either archive, any other sidecar, or an unresolved helper temp. An
unresolved exact temp is reconciled only by a later verified helper execute;
uncertain state is investigated, never guessed or converted to `none`.
