# Daybreak follow-up: API authority, scheduling, LDAP, and session language

The repository scan of application commit `9d12919d` (run
`wfr_966439937abb8d23452c8d4b4f7b9cf0492374b80f9da1f9d947cc4b7d8ecf4c`)
completed with `output_validation_warning` and no validated canonical findings.
Its report contains four provisional Medium and two provisional Low observations.
The changes in this branch address the six source-supported behaviors; the
report alone is not evidence that the changes are effective. A new scan must
target the exact merged application commit before release.

| Boundary | Required property and observable result | Evidence and result classes |
| --- | --- | --- |
| Basic and Bearer API writes | A mutation commits only if the actor is still an admin with the same credential generation, or the Bearer token is still current, at final authorization. A successful self password change may advance its own generation once. Revocation returns 401 and leaves no write. | Unit transaction cases and isolated HTTP/database checks. Pass: committed authorized write or rolled-back 401. Fail: post-revocation commit, incorrect rollback of self change, or lock-order regression. |
| Basic API identity read | The authenticated user ID, role and credential generation come from one joined current database read after password verification. | Authentication and transaction regression tests. Fail: an admin role assembled from different user snapshots. |
| Customer reschedule | Any strict half-open overlap for one customer is rejected, including bookings on different providers; adjacent intervals remain valid. | Unit interval matrix plus real database fixture. Fail: partial or contained overlap accepted, or adjacency rejected. |
| LDAP DN binding | Changing a staff DN advances the credential generation. A bind that finishes after an identity or role change is rejected. An omitted API `ldapDn` field cannot restore an old DN. | Model and LDAP client regression tests plus LDAP guardrail. Fail: old session survives rebind or in-flight stale bind succeeds. |
| Language mutation | GET and HEAD return 405 with `Allow: POST` without changing the session; a valid CSRF-protected POST changes it. | Isolated real HTTP/session test. Fail: language changed by a read method or valid POST broken. |
| IDE Docker actions | Start, build, shell and stop address only this repository's Compose project. | Configuration test and direct command review. Fail: host-wide container stop or network prune. |

The local test environment owns disposable Docker Compose projects and
synthetic database records. The real HTTP tests use loopback-only servers and
temporary sessions. Their cleanup removes those resources; no production data
or credentials are used. The database tests bound their work to one fixture
per case and roll it back or delete it on teardown. The API transaction owns
its database rollback on any failed final check and releases its advisory
locks. Run the full pre-PR gate, including the isolated defense cycle and LDAP
guardrail, whenever these authority or request paths change. Current-head CI,
independent review, and a new Daybreak scan remain separate release evidence.
