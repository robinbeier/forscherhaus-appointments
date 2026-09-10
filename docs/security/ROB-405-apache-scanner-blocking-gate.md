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

## Apache Guard Maintenance Reference

Keep the existing Apache guard in vhost context before redirect or proxy rules,
including the default and unmatched host vhosts, so rejected probes cannot
become redirects or reach application routing:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On

    RewriteCond %{REQUEST_URI} (^|/)(\.env($|[./_~-])|\.environment$|\.git(/|$)|wp-admin(/|$)|wp-config\.php$|wp-login\.php$|xmlrpc\.php$|phpinfo\.php$|server-status$|vendor/phpunit|boaform|HNAP1|cgi-bin) [NC,OR]
    RewriteCond %{QUERY_STRING} (^|&)(page=phpinfo|phpinfo=1)(&|$) [NC]
    RewriteRule ^ - [F]
</IfModule>
```

For an approved maintenance change, back up the existing Apache files first,
run `apache2ctl configtest`, and reload Apache only after the test passes. Run
`bash scripts/ops/prod_validate_after_change.sh --require-scanner-blocking`
afterward, following the access and redaction rules in
[production operations](../ops/agent-operations.md). This makes scanner-path
and default-host failures blocking; record status classes only. If a check fails, restore the prior
files, rerun `apache2ctl configtest`, and reload the restored configuration.
This reference does not claim that the live guard is currently installed or
verified.

For the current repository-side operational boundaries and redacted checks,
see [production operations](../ops/agent-operations.md) and the
[monitoring tools](../../scripts/ops/README.md). A repository decision or
passing local check is not proof of the live Apache or SSH state; live
verification must be performed separately through the approved operations
workflow.
