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
- writer-authority manifest:
  `scripts/ops/config/kuma_monitoring_env_writer_authority.v1.json`

`/run/fh-kuma-monitoring-v1.lock` is the canonical exclusive writer-authority
lock. Every supported post-bootstrap writer of the protected Env must acquire
and hold this lock across revalidation, mutation, rollback and cleanup. The
ROB-490 helper is currently the only supported post-bootstrap Env writer;
Kuma push scripts are readers only. Initial secret population is a separate
pre-authority bootstrap phase. Manual later edits to the protected Env are
unsupported and do not receive the transaction's no-clobber or rollback
guarantees.

The no-clobber and rollback guarantees apply to coordinated writers that honor
this authority contract. Arbitrary non-cooperative root mutation is outside the
supported authority. The helper nevertheless validates identity and content
again at every guarded boundary and treats any detected drift as a fail-closed
hard failure; it never silently treats such drift as a successful transaction.
The first confirmed Execute may create the canonical mode-`0600` lock with
no-clobber semantics. That durable namespace change is reported as
`mutation_performed=true` even when the Env was already converged; subsequent
confirmed runs reuse the exact lock and remain mutation-free when no other
change is required.

The versioned manifest is the machine-readable repository inventory for this
authority. Contract tests bind its fixed Env path, lock metadata and complete
supported-writer set to the helper and this runbook. It is not a production
runtime import: the helper, installer and operator wrapper deliberately keep
their small trust primitives self-contained so an already hash-bound artifact
cannot acquire a second mutable import dependency between verification and
execution. Any future supported writer must first update the manifest, adopt
the canonical lock and pass the same contract tests.

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
with value `0` or `1` on a physical LF-delimited line. CRLF on the target line,
Unicode/non-shell line separators, embedded occurrences and other ambiguous
shell boundaries fail closed. The target line must also be a top-level,
self-contained Bash assignment. An exact-looking line inside a multiline
quote, heredoc, command substitution, function, brace/subshell group or shell
control block or conditional compound is rejected. A missing key is appended,
adding one preceding newline only when the existing final byte is not a
newline; an open shell context or odd trailing backslash before that boundary
is rejected because Bash would not evaluate the append as the requested
assignment. A terminal continued operator and an early shell control transfer
such as top-level `return`, `exit` or `exec` are likewise unsupported and fail
before mutation. An actually invoked `return`, `exit` or `exec` outside an
uninvoked function definition is unsupported regardless of subshell, pipeline
or background placement, including controls preceded by assignment or
redirection prefixes. The helper does not try to prove those contexts safe:
Env-defined aliases, functions, traps, command resolution and later waits can
all change their effective status. `command -v`/`-V` queries remain queries
rather than calls. Actually executed `eval`, `source` and dot-source commands
are unsupported for the same reason; those commands appearing only inside an
uninvoked function definition stay definition-only. Top-level `coproc` is also
unsupported because a coprocess can invoke an Env-defined function and a later
`wait` can propagate its status before the appended assignment. Executed
`alias`, `unalias`, `declare`, `enable`, `hash`, `let`, `mapfile`, `readarray`,
`readonly`, `set`, `shopt`, `trap` and `typeset` commands are unsupported
because they can change later command resolution, variable attributes, shell
behavior, arithmetic status, invoke dynamic callbacks or affect the appended
assignment itself. A `command_not_found_handle`
definition is also unsupported because Bash can invoke that function implicitly
for a later unresolved command; the helper does not execute the Env to prove
that every command will resolve. Function definitions named `command` or
`builtin` are unsupported because they shadow the wrappers used by the static
command grammar. Expansion-produced command words, ANSI-C/locale-quoted command
words and quoted `builtin`/`command` wrappers are likewise unsupported; the
helper never evaluates or normalizes arbitrary Env bytes to infer their runtime
command identity. Other uninvoked function definitions, including
same-line `if`, `for`,
`select`, `until` and `while` bodies and definition-only output redirections
with quoted or expanded targets, remain read-only shell data. Same-line `case`
bodies and command-bearing tails after a same-line function block are outside
the supported grammar and fail closed before mutation. Their
unescaped physical newlines stay command boundaries, and `[[ ... ]]` operands
remain conditional syntax rather than projected commands. Arithmetic-command
operands inside uninvoked function definitions likewise stay out of the
command projection. An actually executed arithmetic command is unsupported
because zero evaluates to failure, nonzero to success, and the helper never
evaluates Env expressions; structurally invalid arithmetic also fails closed.
The helper does not use a literal-`false &&` execution exemption. Command
resolution can be changed by aliases, builtin enablement, the command hash table
or shell options, while enclosing groups can propagate the failed left-hand
status. Controls and arithmetic commands behind that spelling therefore remain
unsupported and fail closed.
Top-level process substitutions remain read-only only while no later executed
`wait` can consume their asynchronous status. A later `wait` is unsupported and
fails closed before mutation, including across physical lines.
Assignment-position, status-only, redirection-only and combined
assignment/redirection-only command substitutions, including substitutions
after one or more completed assignments and command substitutions nested in a
parameter expansion in those positions, are unsupported because Bash can
propagate their dynamically produced status and the helper never executes
arbitrary Env code to simulate it. This includes both `$(...)` and legacy
backtick substitutions. Quoted and unquoted arithmetic expansions remain value
expansions, and the same status checks do not execute inside uninvoked function
definitions. A `0`
changes at exactly its single value-byte position. A `1` is already converged
and is never rewritten.

The desired Env must also remain within the same bounded Env-size contract.
An append that would cross that limit fails during read-only preflight before
recovery publication or any exchange.

Duplicate, commented, disabled, prefixed, embedded, CRLF-terminated or
differently valued definitions fail closed. All unrelated bytes, including
unrelated line endings, Push URLs, tokens, monitor identities and every other
Env value, remain byte-for-byte unchanged. Output contains only a closed
aggregate JSON result and never emits Env bytes, hashes, URLs, tokens or monitor
identities.

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
identity recheck and is not overwritten. Every supported writer is excluded
from the final identity-check/Exchange and identity-check/unlink windows by the
canonical exclusive lock, whose pathname, descriptor identity and trust
metadata are revalidated immediately before each pathname mutation. A failure
after Exchange rolls back only while the live object is still the exact
helper-owned replacement and the displaced object is still the exact bound
original. A detected newer object during recovery is never restored or
unlinked; the result becomes
`rollback_failed`, and private displaced evidence is retained for a separate
read-only recovery decision.

Linux does not provide a pathname operation that combines `RENAME_EXCHANGE` or
`unlink` with an expected-inode compare-and-swap condition. The transaction's
race guarantee therefore depends on the coordinated writer authority above.
An arbitrary non-cooperative root process can violate that authority; this is
an unsupported host mutation, never a successful or simulated transaction.

The recovery pair is revalidated again after the live directory is durable and
before the displaced original is unlinked. Recovery drift while the exact
Exchange pair still exists therefore routes through the same guarded rollback.
An unsupported or failed Exchange durably removes only the still-exact private
replacement before reporting failure; a foreign pending object is retained.

This guarded rollback applies to both classified contract failures and
unexpected runtime failures while the exact pair is still present. After the
displaced original has been durably unlinked, failures are reported as unknown
mutating outcomes and are never retried.

Within the coordinated writer authority, only an object proven to be the
helper's exact private replacement while the canonical lock remains bound may
be unlinked. Recovery publication or any begun Exchange makes
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
exercise the same no-clobber contract. Every original ancestor component is
validated without resolving through symlinks. The target pathname is re-lstat'd
after each descriptor-bound read; if a post-publication writer replaces it, the
installer fails and preserves `mutation_performed=true` for the completed
publication.

Immediately before staged inspection or either installed-helper invocation,
the trusted staged installer opens the selected source with `O_NOFOLLOW`, binds
its complete identity and exact merged hash, reads the bounded helper bytes
from that descriptor, and copies those bytes into a private anonymous execution
snapshot. The isolated Python bootstrap inherits only the snapshot descriptor
plus its expected size and hash, verifies both again, and then executes the
snapshot. It neither reopens the helper pathname nor transports the helper
source through process arguments. The wrapper never executes a path based only
on an earlier preflight, and it does not depend on runtime-specific
`/proc/self/fd` script-path behavior. On Linux, the bootstrap re-enters the
already-running Python interpreter through the kernel-bound `/proc/self/exe`;
invocation therefore does not depend on `PATH` or `sys.executable` surviving a
caller's reduced environment.

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
