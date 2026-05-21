# Production Server Posture Decision

Stand: 2026-05-21

Scope: ROB-396 bewertet die Baseline-Posture des Production-Servers fuer
Forscherhaus Appointments. Dieses Dokument ist read-only/docs-only. Es fuehrt
keine Apache-, SSH-, Firewall-, Certbot-, Kuma-, Sentry-, Deploy- oder
Service-Aenderung aus.

## Summary

Die ROB-396-Entscheidung lautet: mehrere mittlere Hardening-Themen sind
bestaetigt, aber jede Live-Aenderung bleibt ein separates Gate mit eigener
Freigabe, Stop Condition und Rollback-Validierung.

- Basic Security Headers fuer App, `www` und Monitor: implementieren, aber als
  eigenes Live-Gate.
- HSTS: zurueckstellen, bis HTTPS- und Subdomain-Auswirkungen explizit
  akzeptiert sind.
- CSP: zurueckstellen, weil die App legacy JS, Bootstrap, jQuery und
  FullCalendar nutzt.
- SSH: Passwortauth, X11-Forwarding und TCP-Forwarding haerten, aber nur als
  separate SSH-Gates.
- Firewall/UFW: minimale Allowlist fuer `22/80/443` vorbereiten, aber nicht in
  ROB-396 aktivieren.
- Public ports and loopback boundaries: keine Live-Aenderung, solange nur
  erwartete Public Listener und loopback-only interne Dienste bestaetigt sind.

## Validation Rubric

- [x] Evidence ist aktuell und stammt aus read-only Checks.
- [x] Findings sind als Klassen/Flags dokumentiert, nicht als raw config.
- [x] Jede Empfehlung hat `implement`, `defer`, `no-change` oder
  `follow-up required`.
- [x] Live-Aenderungen sind explizit aus ROB-396 ausgeschlossen.
- [x] Stop Conditions und Rollback-/Validierungsanforderungen sind je Gate
  genannt.

## Evidence Summary

### Standard Harness

Quelle: `bash scripts/ops/prod_doctor.sh`, read-only Snapshot
`2026-05-21T17:12:44Z`.

- Hostklasse: `booking-server`, Ubuntu 26.04 LTS.
- App, `www`, Renderer und Deep Health antworteten mit erwarteten
  Erfolgsstatusklassen; Monitor antwortete als erwarteter Redirect.
- Apache, PHP-FPM, MariaDB, Docker, fail2ban, cron, unattended-upgrades und
  `fh-pdf-renderer` waren aktiv.
- Uptime Kuma meldete 13 aktive Monitore und 13 latest green.
- Host Node/npm waren absent.
- Certbot-Zertifikat und Renewal-Timer waren vorhanden.
- ROB-394 Sensitive-Path-Helper war auf Prod noch `helper_missing`, erwartet
  vor einem separaten Deploy-Gate.

Quelle: `bash scripts/ops/prod_logs_summary.sh --since "24 hours ago"`,
read-only Snapshot `2026-05-21T17:12:47Z`.

- Keine Warning-Eintraege fuer Apache, PHP-FPM, MariaDB, PDF Renderer, Docker
  oder cron im betrachteten Fenster.
- Keine app-error-like Lines in 24 Stunden.

### Focused Posture Snapshot

Quelle: sanitized read-only SSH command class, Snapshot
`2026-05-21T17:13:25Z`.

HTTP Header Presence:

| Surface | HSTS | CSP | X-Frame-Options | Referrer-Policy | Permissions-Policy | X-Content-Type-Options |
| --- | --- | --- | --- | --- | --- | --- |
| App HTTPS | missing | missing | missing | missing | missing | missing |
| WWW HTTPS | missing | missing | missing | missing | missing | missing |
| Monitor HTTPS | missing | missing | present | missing | missing | missing |

Redirect classes:

| Surface | HTTP redirect class |
| --- | --- |
| App HTTP | `301_to_https` |
| WWW HTTP | `301_to_https` |
| Monitor HTTP | `301_to_https` |

SSH effective policy classes:

| Policy | Observed class |
| --- | --- |
| `PermitRootLogin` | `prohibit-password` |
| `PubkeyAuthentication` | `yes` |
| `PasswordAuthentication` | `yes` |
| `X11Forwarding` | `yes` |
| `AllowTcpForwarding` | `yes` |

Firewall and listener classes:

| Item | Observed class |
| --- | --- |
| UFW | `inactive` |
| TCP 22 | expected public listener class |
| TCP 80 | expected public listener class |
| TCP 443 | expected public listener class |
| TCP 3001 | loopback |
| TCP 3003 | loopback |
| TCP 3306 | loopback |
| Unexpected public listeners | `0` |

## Decision Matrix

| Area | Decision | Rationale | Next gate |
| --- | --- | --- | --- |
| App/WWW baseline headers | implement | App and `www` lack low-risk browser hardening headers. | Separate Apache header gate. |
| Monitor baseline headers | implement | Monitor already has `X-Frame-Options`, but other baseline headers are missing. | Separate monitor header gate. |
| HSTS | defer | HSTS can affect all HTTPS/subdomain behavior and should be accepted explicitly. | HSTS decision gate after subdomain review. |
| CSP | defer | Legacy JS and third-party client behavior need compatibility review before enforcement. | CSP design/spike gate. |
| SSH password auth | implement | Pubkey auth is enabled and password auth increases brute-force/credential risk. | Separate SSH auth hardening gate. |
| SSH X11 forwarding | implement | No app/operator dependency is documented; leaving it enabled widens SSH capability. | Separate SSH forwarding gate. |
| SSH TCP forwarding | implement | No app/operator dependency is documented; disabling reduces pivot capability. | Separate SSH forwarding gate. |
| `PermitRootLogin prohibit-password` | no-change | Current root key path is the accepted operator access; no tested sudo alternative is documented. | Revisit only after alternate admin path exists. |
| UFW inactive | follow-up required | Public listener posture is currently clean, but host firewall would add defense in depth. | Separate UFW allowlist gate. |
| Public listeners | no-change | Only expected public listener classes were observed. | Recheck in ROB-397 via redacted doctor. |
| Loopback services | no-change | Kuma, renderer and MariaDB were loopback-bound in the snapshot. | Recheck in ROB-397 via redacted doctor. |

## Gate Requirements

### Header Gate

Recommended scope:

- Add baseline response headers for App/WWW and monitor where compatible:
  `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, and
  `X-Frame-Options`.
- Treat `Content-Security-Policy` and HSTS as separate gates.

Stop conditions:

- Header change breaks app login, booking, admin UI, FullCalendar behavior,
  PDF rendering, monitor access, deep health, or certificate renewal.
- Validation requires printing raw vhost configuration or secret-bearing
  environment.

Rollback/validation:

- Run `apache2ctl configtest` before reload.
- Reload only after explicit approval.
- Run `prod_validate_after_change.sh` and a header presence check after reload.

### HSTS Gate

Recommended scope:

- Decide separately whether to enable HSTS and whether to include subdomains.
- Do not use `preload` by default.

Stop conditions:

- Any required subdomain, monitor path, recovery path, or certificate renewal
  path is not confidently HTTPS-safe.

Rollback/validation:

- Document expected max-age and subdomain impact before change.
- Validate App, `www`, monitor and Certbot timer after change.

### SSH Gate

Recommended scope:

- Disable `PasswordAuthentication` only after active key access is confirmed.
- Disable `X11Forwarding` and `AllowTcpForwarding` unless an operator workflow
  explicitly needs them.
- Do not change `PermitRootLogin` until an alternate sudo-capable admin path is
  created and tested.

Stop conditions:

- There is no second confirmed SSH session before reload/restart.
- Any operator workflow depends on password auth, X11 forwarding or TCP
  forwarding.
- The rollback path would require console access that is not currently
  available.

Rollback/validation:

- Keep an existing SSH session open.
- Validate `sshd -t` before reload.
- Open a second SSH session after reload before closing the first.
- Run `prod_doctor.sh` after the change.

### Firewall/UFW Gate

Recommended scope:

- Allow only expected public services `22/80/443`.
- Keep loopback-only services loopback-only.
- Do not use ROB-396 to enable UFW live.

Stop conditions:

- SSH access cannot be proven preserved before enabling.
- Certbot renewal path or Apache routing impact is unclear.
- Any unexpected listener needs investigation before firewall activation.

Rollback/validation:

- Prepare explicit disable/rollback command before enable.
- Keep an active SSH session open.
- Validate App, `www`, monitor, renderer, deep health and Kuma after enable.
- Run `prod_validate_after_change.sh`.

## Findings vs Non-Findings

Confirmed findings:

- Baseline security headers are missing on App/WWW and mostly missing on
  Monitor.
- SSH effective policy still permits password authentication, X11 forwarding
  and TCP forwarding.
- UFW is inactive.

Explicit non-findings:

- No unexpected public listener class was observed.
- Renderer, Kuma and MariaDB listener classes were loopback-bound.
- HTTP to HTTPS redirects were present for App, `www` and Monitor.
- No recent service warnings or app-error-like lines were reported by the
  redacted log summary.

Checks intentionally not performed:

- No raw Apache, SSH, firewall, PHP-FPM, Kuma DB or `/etc/fh` dumps.
- No key, user, DB row, session/cache, Push URL, health-token, DSN or password
  output.
- No service reload, restart, deploy, Certbot action, UFW write, Kuma write,
  Sentry smoke or package update.

## ROB-397 Input

ROB-397 may turn these ROB-396 classes into redacted `prod_doctor.sh` posture
checks:

- Header presence per surface for the six baseline header names above.
- SSH policy flags for the five selected `sshd -T` keys above.
- UFW status as active/inactive/missing/unknown.
- Listener classes for expected public ports and loopback-only internal ports.
- Unexpected public listener count.

ROB-397 must keep the same redaction boundary: no raw config, no raw listener
addresses, no secrets, no discovered file names, and no production data.

## Evidence Appendix

- `docs/security/production-server-threat-model.md`: ROB-395 server threat
  model and ROB-396 follow-up framing.
- `docs/long-horizon/ROB-292-prod-security-hardening/Plan.md`: Milestone 3
  scope and stop conditions.
- `docs/ops/agent-operations.md`: read-only-first production operations
  workflow.
- `scripts/ops/README.md`: redacted ops harness and sensitive-path validation
  output policy.
- `bash scripts/ops/prod_doctor.sh`: read-only snapshot
  `2026-05-21T17:12:44Z`.
- `bash scripts/ops/prod_logs_summary.sh --since "24 hours ago"`: read-only
  log snapshot `2026-05-21T17:12:47Z`.
- Sanitized read-only SSH command class: header, SSH effective policy, UFW and
  listener-class snapshot `2026-05-21T17:13:25Z`.
