# ROB-405 Apache Scanner Blocking Gate

Status: repo-side repeatability and validation package for the separately
approved live gate. This PR does not deploy or claim ownership of live
`/etc/apache2` or `/etc/fail2ban` state.

Scope: reduce repeated `Security - Scanner Activity` red windows by blocking
known scanner probes before PHP/CodeIgniter and by adding a narrow Fail2ban jail
for burst IPs. This gate intentionally excludes Kuma monitor changes, threshold
changes, UFW/provider-firewall work, HSTS, Certbot, deploy, database, and PHP
runtime changes.

## Baseline Evidence

Source: production observations on 2026-05-21 and 2026-05-22.

- Monitor #13 (`Security - Scanner Activity`) correctly turned red when the
  5-minute scanner count exceeded the threshold of 50.
- App health stayed green while scanner bursts happened:
  - `/`
  - `/health`
  - `/index.php/healthz`
- Observed scanner IPs included:
  - `168.144.37.240`, repeated bursts including 2026-05-21 21:26-21:31 Berlin
    and 2026-05-22 14:48-14:51 Berlin.
  - `179.43.146.227`, 126 `.env`-style probes on 2026-05-21 23:11-23:15 Berlin.
  - `192.253.248.169`, 54 `.env`/`phpinfo` probes on 2026-05-22 00:35-00:39
    Berlin.
- Responses were 403/404 for the counted scanner probes in the later samples;
  earlier query-style probes such as `/?page=phpinfo` reached normal app
  routing and returned 200.
- Fail2ban is installed and active, but currently only the `sshd` jail is
  active.
- Apache has `rewrite_module` loaded.
- A host-local sensitive-path guard already exists at
  `/etc/apache2/conf-available/fh-easyappointments-sensitive-paths.conf`; this
  gate adds a separate scanner-focused guard instead of widening ROB-393.

## Preconditions

Before any live write:

- A separate operator approval explicitly authorizes ROB-405 live Apache and
  Fail2ban changes, config tests, reloads, and post-change validation.
- The current production baseline still matches `docs/ops/agent-operations.md`.
- `bash scripts/ops/prod_doctor.sh` completes without a new unrelated blocker.
- `bash scripts/ops/prod_validate_after_change.sh` passes before the change.
- `apache2ctl configtest` is already clean.
- `fail2ban-client -t` is already clean.
- Rollback file paths and backup filenames are chosen before editing.

## Live Procedure

Do not execute this section without the separate approval above.

1. Capture read-only baseline:
   - `bash scripts/ops/prod_doctor.sh`
   - `bash scripts/ops/prod_validate_after_change.sh`
   - `apache2ctl configtest`
   - `fail2ban-client status`
   - `fail2ban-client -t`
2. Back up any file that will be changed under
   `/root/backups/easyappointments/install-snapshots/<UTC timestamp>/`.
3. Create `/etc/apache2/conf-available/fh-scanner-path-blocking.conf`:

   ```apache
   # Forscherhaus Appointments scanner-path hardening for ROB-405.
   # Host-local Apache guard. Values only, no secrets.
   <IfModule mod_rewrite.c>
       RewriteEngine On

       RewriteCond %{REQUEST_URI} (^|/)(\.env($|[./_-])|\.environment$|\.git(/|$)|wp-config\.php$|wp-login\.php$|xmlrpc\.php$|phpinfo\.php$|server-status$|vendor/phpunit|boaform|HNAP1|cgi-bin) [NC,OR]
       RewriteCond %{QUERY_STRING} (^|&)(page=phpinfo|phpinfo=1)(&|$) [NC]
       RewriteRule ^ - [F]
   </IfModule>
   ```

4. Enable the Apache conf with `a2enconf fh-scanner-path-blocking`.
5. Include the same conf inside each App/WWW and Monitor vhost before the
   existing redirect/proxy rules:

   ```apache
   Include /etc/apache2/conf-available/fh-scanner-path-blocking.conf
   ```

   This is required because the scanner-blocking rewrite must run in vhost
   context for HTTPS App, HTTPS Monitor, and query-string probes.
6. Create `/etc/fail2ban/filter.d/fh-apache-scanner.conf`:

   ```ini
   [Definition]
   failregex = ^<HOST> .*"(GET|POST|HEAD) [^"]*(/\.env|/\.environment|/\.git/|/wp-config\.php|/wp-login\.php|/xmlrpc\.php|phpinfo|/server-status|/vendor/phpunit|/boaform|/HNAP1|/cgi-bin/|\?(page=phpinfo|phpinfo=1))[^"]*" (403|404)\b
   ignoreregex =
   ```

7. Create `/etc/fail2ban/jail.d/fh-apache-scanner.local`:

   ```ini
   [fh-apache-scanner]
   enabled = true
   filter = fh-apache-scanner
   logpath = /var/log/apache2/*access.log
   port = http,https
   findtime = 5m
   maxretry = 20
   bantime = 6h
   backend = auto
   ```

8. Test before reload:
   - `apache2ctl configtest`
   - `fail2ban-client -t`
   - `fail2ban-regex /var/log/apache2/dasforscherhaus-leg_access.log /etc/fail2ban/filter.d/fh-apache-scanner.conf`
9. Stop without reload if any test fails.
10. If all tests pass:
   - `systemctl reload apache2`
   - `systemctl reload fail2ban`
11. Run validation:
   - `bash scripts/ops/prod_validate_after_change.sh --require-scanner-blocking`
   - `bash scripts/ops/prod_doctor.sh`
   - `fail2ban-client status fh-apache-scanner`
12. Observe Monitor #13 for at least 24 hours before changing thresholds or
    declaring further tuning necessary.

## Expected Post-Change Evidence

- App, `www`, monitor, renderer, and deep health remain healthy.
- Fixed scanner classes return non-2xx status classes:
  - `scanner_path.*=403|404`
  - `scanner_query.*=403|404`
  - `scanner_path_failures=0`
- `prod_validate_after_change.sh --require-scanner-blocking` passes.
- `fail2ban-client status fh-apache-scanner` reports an active jail.
- Monitor #13 stays active; it may still show red for bursts already above the
  threshold before Fail2ban bans an IP, but repeated same-IP bursts should be
  shortened or eliminated.

## Stop Conditions

Stop and do not reload Apache or Fail2ban if:

- Pre-change app or deep health is unhealthy.
- `prod_validate_after_change.sh` fails before ROB-405 changes.
- `apache2ctl configtest` is not clean before or after editing.
- `fail2ban-client -t` is not clean before or after editing.
- The Fail2ban regex matches normal health checks, login routes, dashboard
  routes, assets, or monitor routes in sampled logs.
- The change would touch Kuma monitors, thresholds, UFW/provider firewall,
  HSTS, Certbot, deploy, DB, PHP runtime, Sentry, or package installation.
- Any required command would print secret-bearing files, Push URLs, health
  tokens, DB rows, Kuma DB contents, session/cache contents, or raw app config.

Stop and roll back if after reload:

- `/`, `/health`, `/index.php/healthz`, App/WWW HTTPS, Monitor HTTPS, or the PDF
  renderer health fails.
- New app-log or PHP-FPM error classes appear.
- Legitimate operator or Kuma traffic is banned by `fh-apache-scanner`.
- The scanner validator returns HTTP 2xx for any fixed scanner class.

## Rollback

Rollback must be available before live edit:

1. Disable Apache scanner conf:
   - `a2disconf fh-scanner-path-blocking`
   - `apache2ctl configtest`
   - `systemctl reload apache2`
2. Disable Fail2ban scanner jail:
   - Move or rename `/etc/fail2ban/jail.d/fh-apache-scanner.local`.
   - `fail2ban-client -t`
   - `systemctl reload fail2ban`
3. If required, unban only addresses in the ROB-405 jail:
   - `fail2ban-client status fh-apache-scanner`
   - `fail2ban-client set fh-apache-scanner unbanip <ip>`
4. Run:
   - `bash scripts/ops/prod_validate_after_change.sh`
   - `bash scripts/ops/prod_doctor.sh`

## Evidence Boundary

Allowed in chat, Linear, PR, and docs:

- Status classes and validation pass/fail.
- `apache.configtest=ok|failed`.
- `fail2ban.configtest=ok|failed`.
- Jail name and active/inactive class.
- Scanner class labels and HTTP status classes.
- Sanitized Monitor #13 counter values.

Not allowed:

- Push URLs, tokens, passwords, DSNs, health-token values.
- `/etc/fh` contents, `config.php`, raw app config, DB rows, session/cache
  contents, Kuma DB rows.
- Raw Apache vhost config or full raw access-log dumps in durable artifacts.
