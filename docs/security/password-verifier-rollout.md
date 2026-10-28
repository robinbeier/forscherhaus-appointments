# Password verifier rollout

Local authentication accepts the historical salted SHA-256 verifier and native
Argon2id. New verifiers use 19,456 KiB, two passes and one thread. The existing
100-byte password maximum remains; no bcrypt truncation is allowed. The password
column already holds 512 characters, so no schema change is needed.

## Ordered release boundary

1. Deploy the dual-format reader with `MODERN_PASSWORD_WRITES = false`.
   Existing application writes remain readable by the preceding release.
   Verify modern and legacy synthetic login, Basic API authentication and cleanup.
2. Only after that release is independently verified, activate
   `MODERN_PASSWORD_WRITES = true` in a subsequent reviewed release.
   New writes use Argon2id. Successful legacy authentication upgrades that exact
   credential row with a compare-and-swap.
3. Keep the verified reader release as the rollback floor. After modern writes
   begin, a release without the dual reader cannot authenticate upgraded accounts.
   Hashes cannot be converted back without the plaintext password. Do not restore
   an old database merely to undo a verifier upgrade.

Login rechecks the credential snapshot after verification. A concurrent reset
or lost upgrade compare-and-swap denies that request; it does not overwrite
the replacement or authenticate another ID. During the mixed-format period,
every credential shape performs both legacy and current KDF work. This avoids
the additional known/unknown-user branch cost; it does not prove constant HTTP
timing. Existing verifiers upgrade on successful authentication, not instantly
at deployment. LDAP fallback remains separate. Check Argon2id availability and
resource cost on the target runtime before activation.

Release tools must verify passwords rather than recompute deterministic hashes.
Replay and canary tools are included in the application archive. Separately
retained ordinary operator bundles are immutable: use a reviewed updated bundle
before running them against modern-writer releases; never weaken ownership checks.

Tests distinguish real HTTP/DB authentication from in-memory reset-ordering
doubles. The coordinated legacy-upgrade/reset test runs only after activation;
its reader-phase skip is an explicit remaining activation gate.
