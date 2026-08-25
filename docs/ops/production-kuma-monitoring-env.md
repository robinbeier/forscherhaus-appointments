# Production Kuma Monitoring Env Transaction V1

ROB-490 provides the versioned, fail-closed transaction for enabling the
existing ROB-453 retention-success Push signal in the protected Kuma Env file.
It replaces one-off host scripts with a fixed helper, an immutable installation
path and an exact-commit production wrapper.

Repository delivery does not authorize SSH, helper installation, an Env write,
a Push, timer enablement, timer start, retention execution or deletion. Each
production phase remains a separate explicit gate.

## Fixed paths and authority

- repository helper: `scripts/ops/libexec/kuma_monitoring_env_v1.py`
- repository installer: `scripts/ops/libexec/kuma_monitoring_env_install_v1.py`
- installed helper: `/usr/local/libexec/fh-kuma-monitoring-env-v1`
- protected Env: `/root/backups/uptime-kuma-push.env`
- recovery state: `/var/lib/fh-kuma-monitoring-v1`
- transaction lock: `/run/fh-kuma-monitoring-v1.lock`

The installed helper must be a regular `root:root`, mode `0555`, Single-Link
file beneath root-controlled ancestors with no group/world write bit. The Env
and both recovery files must be regular `root:root`, mode `0600`, Single-Link
files. The recovery directory is mode `0700`. Symlinks, hard-link identities,
wrong owners or modes, unexpected directory entries and untrusted ancestors
fail before the Env mutation.

The helper accepts a root-prefix only for isolated regression fixtures. The
production paths cannot be supplied by a caller.

## Closed Env change

The only allowed semantic change is:

```text
KUMA_RELEASE_RETENTION_MONITOR_ENABLED=1
```

The source may contain no active definition or exactly one active definition
with value `0` or `1`. A missing key is appended, adding one preceding newline
only when the existing final byte is not a newline. A `0` changes at exactly
its single value-byte position. A `1` is already converged and is never
rewritten.

Duplicate, commented, disabled, prefixed, embedded or differently valued
definitions fail closed. All other bytes, including line endings, Push URLs,
tokens, monitor identities and every unrelated Env value, remain byte-for-byte
unchanged. Output contains only a closed aggregate JSON result and never emits
Env bytes, hashes, URLs, tokens or monitor identities.

## Recovery adoption and publication

The recovery directory contains exactly two trusted files: one original Env
backup and one JSON manifest. Leaf names are not recovery authority; this lets
the helper adopt the exact two-file ROB-488 evidence already published by the
failed first gate. Authority instead requires all of the following:

- exact directory and file trust metadata;
- one and only one closed `fh_kuma_monitoring_recovery.v1` manifest;
- fixed Env path and issue `ROB-488` or `ROB-490`;
- exact original and desired SHA-256 bindings;
- backup bytes that transform to the desired bytes under the same closed Env
  rule;
- current Env bytes equal to the bound original before activation or to the
  bound desired bytes after activation.

Existing recovery evidence is never overwritten, renamed or deleted. Drift is
a hard failure. When no recovery exists, confirmed execution constructs the
two files in a private mode-`0700` same-filesystem directory, fsyncs them and
publishes the complete directory with Linux `renameat2(RENAME_NOREPLACE)`.
Concurrent exact evidence can converge; conflicting evidence remains intact
and blocks execution.

An already-enabled Env without exact recovery evidence fails. The helper never
invents retrospective recovery authority.

## Exchange and identity contract

Immediately before the first transaction mutation the helper binds the full
Env identity, including ctime. It creates and fsyncs a private mode-`0600`
replacement in the same directory and uses Linux
`renameat2(RENAME_EXCHANGE)`.

The supported Linux regression proves that Exchange updates only `st_ctime_ns`
for both participating inodes. Post-Exchange identity therefore excludes only
ctime and continues to bind bytes, device, inode, type/mode, uid, gid, link
count, size and mtime for both the displaced original and the published
replacement. No other metadata normalization is supported.

A concurrent writer before Exchange is detected by the full immediate
identity recheck and is not overwritten. A failure after Exchange rolls back
only while the live object is still the exact helper-owned replacement and the
displaced object is still the exact bound original. A newer writer during
recovery is never overwritten or unlinked; the result becomes
`rollback_failed`, and private displaced evidence is retained for a separate
read-only recovery decision.

Only an object proven to be the helper's exact private replacement may be
unlinked. Recovery publication or any begun Exchange makes
`mutation_performed=true`, including after a successful rollback. Every result
contains `rollback_outcome=not_required|succeeded|failed`. Unknown transport
outcomes are never retried.

## Wrapper modes

The wrapper is local plan-only by default and does not contact SSH:

```bash
bash scripts/ops/prod_kuma_monitoring_env_v1.sh
```

After merge, every remote mode requires one exact lowercase merge commit that
must equal local `HEAD`, local `origin/main` and live `origin/main`. The wrapper
derives the helper hash from that immutable commit and transfers only the
helper and installer with `git archive`. A dirty tracked worktree, stale ref,
hash mismatch or unknown result stops without retry.

SSH uses `StrictHostKeyChecking=yes`; the Tailscale host identity must already
exist in the operator's trusted `known_hosts`. A missing or changed host key is
a hard stop and is never accepted automatically.

Read-only staged inspection:

```bash
bash scripts/ops/prod_kuma_monitoring_env_v1.sh \
  --inspect \
  --expected-commit 40_HEX_MERGE_COMMIT
```

Separately approved no-clobber installation, without an Env write:

```bash
bash scripts/ops/prod_kuma_monitoring_env_v1.sh \
  --install \
  --confirm-live-write ROB-490 \
  --expected-commit 40_HEX_MERGE_COMMIT
```

The installer publishes only the exact hash-bound helper at the fixed path via
`RENAME_NOREPLACE`. An exact existing helper is idempotent; a different target
is never replaced. Linux production requires `renameat2`; the Darwin
`renameatx_np(RENAME_EXCL)` path exists only so isolated macOS fixtures can
exercise the same no-clobber contract.

Immediately before either installed-helper invocation, the trusted staged
installer opens the fixed target with `O_NOFOLLOW`, binds its complete identity
and exact merged hash, then invokes that already-open descriptor through
`/proc/self/fd`. The wrapper never executes a path based only on an earlier
install preflight.

Separately approved single Env transaction, only after exact installation:

```bash
bash scripts/ops/prod_kuma_monitoring_env_v1.sh \
  --execute \
  --confirm-live-write ROB-490 \
  --expected-commit 40_HEX_MERGE_COMMIT
```

Install and Execute are deliberately separate modes. Neither mode performs a
Kuma Push, changes a systemd unit, enables or starts a timer, runs retention,
publishes the retention marker or changes another production component.

The installer and installed helper intentionally keep their small trust
primitives self-contained: production installation is one atomic Single-Link
helper artifact, not a multi-file runtime whose imports could drift between
verification and execution. Fixture-only fault injection is disabled whenever
the fixed production root `/` is selected.

## Supported outcomes

- Disabled or missing key with absent/exact recovery: read-only `pass`,
  `execution_ready=true`, `monitoring_state=would_enable`.
- Enabled key with exact recovery: converged read-only `pass`, no mutation.
- Enabled key without exact recovery: fail before mutation.
- Missing Linux rename support, root authority, fixed path, trust metadata,
  recovery binding or exact installed artifact: unsupported production profile
  and hard failure, never a skip or simulated pass.
- Identity, content, race, lock, rollback, durability or postflight drift: hard
  failure. Do not issue a second Execute.

After a known successful Env transaction, the still-separate ROB-488 gate may
run exactly one fixed ROB-489 Push, verify the existing monitor set, and only
then enable the retention timer without starting it. Timer start remains a
later gate because `Persistent=true` can cause an immediate catch-up run.

## Validation

Focused contract tests:

```bash
docker compose run --rm --no-deps php-fpm \
  php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php \
  tests/Unit/Scripts/KumaMonitoringEnvV1Test.php

docker compose run --rm --no-deps php-fpm \
  php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php \
  tests/Unit/Scripts/KumaMonitoringEnvInstallerV1Test.php

docker compose run --rm --no-deps php-fpm \
  php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php \
  tests/Unit/Scripts/KumaMonitoringEnvProductionWrapperTest.php
```
