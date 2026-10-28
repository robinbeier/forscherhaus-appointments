# Password verifier rollout

Local authentication accepts the historical salted SHA-256 verifier and native
Argon2id. New verifiers use 19,456 KiB, two passes and one thread. The existing
100-byte password maximum remains; no bcrypt truncation is allowed. The password
column already holds 512 characters, so no schema change is needed.

The parameters follow the [OWASP Argon2id minimum configuration](https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html#argon2id).
Native format and salt handling follow [PHP password_hash](https://www.php.net/manual/en/function.password-hash.php).

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
doubles. The legacy-login regression checks unchanged credentials in the reader phase.
Its coordinated upgrade/reset branch runs after activation and remains an
explicit activation gate; the reader phase does not establish that SQL schedule.
