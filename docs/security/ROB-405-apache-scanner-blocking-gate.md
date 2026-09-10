# ROB-405 Apache Scanner Protection Decision

Status: repository decision record. It does not claim a production change or
live verification.

Apache protection for sensitive and scanner paths remains required. Requests
for paths such as `.env`, `.git`, WordPress administration/login endpoints,
`phpinfo`, `server-status`, and known probe families must be rejected before
normal application routing, including on default and unmatched host surfaces.
The existing Apache sensitive-path protection remains in scope, and SSH
administration remains untouched.

The `fh-apache-scanner` Fail2ban jail is retired from the desired state and
must not be recreated. Browser-triggerable blocked requests can be generated
by legitimate users behind shared addresses, so banning an address based on
these requests can deny unrelated legitimate traffic. Scanner-path rejection
at Apache remains the protection boundary; IP banning is not required to
preserve it.

This decision preserves the Apache path-blocking intention while removing the
unnecessary scanner-IP jail and its installation procedure. It does not authorize
or document a live Apache, Fail2ban, firewall, or SSH change.

For the current repository-side operational boundaries and redacted checks,
see [production operations](../ops/agent-operations.md) and the
[monitoring tools](../../scripts/ops/README.md). A repository decision or
passing local check is not proof of the live Apache or SSH state; live
verification must be performed separately through the approved operations
workflow.
