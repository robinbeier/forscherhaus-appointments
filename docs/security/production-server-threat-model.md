# Production-Server-Threat-Model

Stand: 2026-05-21

Scope: realer Production-Server fuer Forscherhaus Appointments. Dieses Dokument
ist keine erneute Code-Security-Review. Es beschreibt Server-, Deployment-,
Netzwerk-, Runtime-, Secrets-, Monitoring- und Betriebsrisiken, die die
Software-Risiken verstaerken oder abfedern koennen.

## 1. Executive Summary

Der Server-Sicherheitsauftrag ist:

- nur die vorgesehenen HTTP(S)-Oberflaechen der App und des Monitorings
  oeffentlich erreichbar halten;
- App-Code, `storage/`, Sessions, Cache, Logs, `vendor/`, `config.php`,
  Release-Artefakte, Backups und Host-Secrets serverseitig vor Webzugriff
  schuetzen;
- Datenbank, Backups, Uptime Kuma Push-Secrets, Deep-Health-Token, Sentry
  Konfiguration und Deployment-Credentials host-lokal halten;
- Aenderungen ueber kleine, validierbare Gates fahren, mit Rollback- oder
  Stop-Bedingungen vor Live-Writes;
- Monitoring so betreiben, dass echte Incidents sichtbar bleiben, ohne Scanner-
  oder Log-Noisy-False-Positives blind zu ignorieren.

App-Risiken, die stark an Serverkonfiguration haengen:

- Session- und PII-Verlust durch Webserver-Fehlkonfiguration fuer `storage/**`.
- Host-Header-, TLS- oder Proxy-Fehlkonfiguration, die sichere Cookies,
  erzeugte Links oder Deep-Health-Monitoring schwaecht.
- Massendatenverlust durch oeffentlich erreichbare Datenbank- oder Backup-Pfade.
- Monitoring-Integritaetsverlust durch geleakte Kuma Push-URLs oder
  Deep-Health-Token.
- Vollstaendige Systemkompromittierung bei SSH/root-Kompromiss.
- SSRF- oder interne Erreichbarkeit ueber Renderer-, Webhook- oder
  Integrationsgrenzen.

## 2. Assets

- PII und Termin-/Schuldaten: Namen, Kontaktdaten, Terminzeiten, Notizen,
  Schul- und Organisationskontext.
- MariaDB-Datenbank und aggregierte Betriebsdaten.
- Backups, Restore-Marker, Backup- und Release-Artefakte.
- Runtime-Secrets: `config.php`, Datenbankzugang, API-Token, Sentry DSN,
  Health-Token, Deployment- und Zero-Surprise-Credentials.
- SSH/root-Zugang und serverlokale Operator-Dateien.
- TLS-Zertifikate und Certbot-Renewal-Zustand.
- Uptime Kuma SQLite-Daten, Push-Monitor-URLs, Headerwerte und Notification
  Credentials.
- Sentry-Konfiguration und Scrubbing-Regeln.
- Logs, Sessions, Cache und temporare Gate-/CI-/Release-Artefakte.

## 3. Trust Boundaries

- Internet zu Apache/App: untrusted HTTP(S) Requests treffen auf Apache und die
  PHP-App.
- Public booking vs authenticated backoffice: App-Sessions und Server-Side
  Session-Dateien trennen oeffentliche Buchung und Staff/Admin-UI.
- Apache zu PHP-FPM/App-Runtime: Webserver-Konfiguration, FPM-Pool,
  Runtime-Env und Dateirechte sind die zentrale Durchsetzungsgrenze.
- App zu MariaDB: lokale DB-Erreichbarkeit und DB-Credentials sind
  hochsensitiv.
- App zu Docker-PDF-Renderer: Renderer laeuft containerisiert und soll nur ueber
  die definierte lokale Grenze erreichbar sein.
- App/Host zu Sentry, LDAP, CalDAV/Google und Webhooks: ausgehende
  Netzwerkgrenzen koennen Daten oder Credentials exponieren, wenn falsch
  konfiguriert.
- Kuma zu Health Endpoints und Push-Monitoren: Kuma liest HTTP/JSON-Signale und
  nimmt Push-Signale entgegen; Push-URLs und Health-Header sind Secrets.
- Operator-Mac zu Prod-SSH: lokale Agentenarbeit darf nur redacted/read-only
  starten; Live-Writes brauchen explizite Freigabe.
- Host-local secrets vs repo state: Git dokumentiert Interfaces und Templates,
  niemals Live-Secrets, rohe Runtime-Konfiguration oder Produktionsdaten.

## 4. Attack Surface

- 80/443/TLS/Apache/vhosts/Host-header boundary: oeffentliche App,
  Weiterleitungen, TLS, Header und vhost routing.
- SSH/admin access: root-Zugang ist ein Total-Compromise-Pfad.
- PHP runtime and filesystem permissions: App-Code, `storage/`, Sessions,
  Cache, Logs, `vendor/`, `application/`, `system/` und `config.php`.
- MariaDB exposure and local listen/socket boundary: DB darf nicht als
  Internet-Service erscheinen.
- Docker renderer and loopback binding: PDF Renderer muss lokal begrenzt
  bleiben.
- Uptime Kuma UI, monitor definitions, push URLs: Monitoring selbst ist ein
  Integritaets- und Geheimnisziel.
- Backups, restore markers, release archives: hohe Daten- und Rollback-Relevanz.
- Cron/systemd timers: Push-Monitore, Backups, Restore-Verify, Certbot.
- Logs/session/cache exposure: PII, Session-Daten, technische Fehler und
  Betriebsindikatoren.
- Health endpoints: `/health` ist public shallow health;
  `/index.php/healthz` ist token-geschuetzter Deep Health.

## 5. Existing Mitigations

- HTTPS/TLS ist fuer die App-Endpunkte aktiv; Certbot ist vorhanden und ein
  Renewal-Timer wurde im read-only Snapshot gesehen.
- `apache2`, `php8.5-fpm`, `mariadb`, `docker`, `fail2ban`, `cron`,
  `unattended-upgrades` und `fh-pdf-renderer` waren im Snapshot aktiv.
- PDF Renderer und Uptime Kuma laufen in Docker; Uptime Kuma ist als
  host-lokale/Proxy-gebundene Monitoring-Oberflaeche dokumentiert.
- Host Node/npm sind bewusst abwesend, passend zum Artifact-Deployment-Modell.
- Deep Health nutzt ein Token und darf nur mit host-lokalen/Kuma-Konfigurationen
  abgefragt werden.
- Sentry hat eine dokumentierte Scrubber-/Datenpolitik; Push-URLs, DSNs,
  Request-Bodies, Authorization Header und PII duerfen nicht gesendet werden.
- Uptime Kuma Desired State ist ohne Live-Secrets im Repo dokumentiert.
- `prod_doctor.sh`, `prod_logs_summary.sh` und
  `prod_validate_after_change.sh` bilden einen redacted Ops-Harness.
- ROB-394 hat repo-seitig einen Sensitive-Path-Regressions-Gate ergaenzt; der
  aktuelle Prod-Snapshot meldete den neuen Helper noch als nicht auf dem Host
  verfuegbar, weil kein Deploy Teil von ROB-395 ist.

## 6. Server-Specific Attacker Stories

- Ein Internet-Angreifer nutzt eine Apache- oder vhost-Fehlkonfiguration, um
  `storage/`, Session-Dateien, Cache, Logs oder `config.php` zu lesen. Impact:
  Session-Hijacking, PII-Leakage, DB/API-Secret-Leakage.
- Ein Host-Header- oder Proxy-Fehler beeinflusst generierte Links oder
  Cookie-Secure-Verhalten. Impact: Phishing, Session-Diebstahl oder
  fehlgeleitete Deep-Health-/App-URLs.
- Eine DB- oder Backup-Grenze wird versehentlich oeffentlich erreichbar. Impact:
  massenhafter PII-Verlust, Offline-Cracking von Staff-Zugaengen,
  vollstaendige Termin- und Settings-Manipulation.
- Eine Kuma Push-URL oder ein Deep-Health-Token leakt. Impact:
  Monitoring-Integritaet sinkt, Angreifer koennen Signale faelschen,
  unterdruecken oder interne Health-Informationen abrufen.
- SSH/root wird kompromittiert. Impact: Totalverlust von Host, App, DB,
  Backups, Secrets, Monitoring und Deployment-Kette.
- Renderer-, Webhook- oder Integrationspfade erlauben interne Erreichbarkeit.
  Impact: SSRF- oder Datenexfiltrationspfade, besonders bei kompromittiertem
  Admin oder falsch gesetzten Outbound-Grenzen.
- Log-Klassifizierung wird zu breit. Impact: echte Incidents werden als
  Scanner-/Proxy-Noise versteckt. Umgekehrt kann zu enge Klassifizierung zu
  Alarmrauschen und Abstumpfung fuehren.
- Release- oder Rollback-Artefakte werden im Webroot oder Repo abgelegt.
  Impact: Secret-/Config-Leakage und einfache Rekonstruktion der
  Produktionsumgebung.

## 7. Criticality Calibration

Critical:

- Remote Host Compromise oder RCE auf Apache/PHP/Host.
- Oeffentlich erreichbare DB, Backups, `config.php`, Session-Dateien oder
  sensitive Release-Artefakte.
- Secret-Leakage, die Admin/API/SSH/Serverzugriff ermoeglicht.

High:

- Webserver-Konfiguration exponiert `storage/`, Logs, Sessions, `vendor/`,
  `application/` oder `system/`.
- SSH-Hardening-Gaps oder unsichere root-Zugangswege.
- TLS-/Host-/Proxy-Konfiguration schwaecht Sessions, sichere Cookies oder
  generierte Links.
- Kuma Push-URLs oder Deep-Health-Token werden geleakt.

Medium:

- Monitoring-Blindspots, falsch klassifizierte Logs oder fehlende
  Nachweisbarkeit von Backup-/Restore-Freshness.
- TLS/Certbot Drift ohne unmittelbare Kompromittierung.
- Nicht-intrusive DoS- oder Log-Noise-Klassen, die Betrieb und Monitoring
  stoeren.
- Unklare Deployment-/Runtime-Isolation, solange keine Datenexposition
  nachgewiesen ist.

Low:

- Kleine Informationsleaks ohne Secrets oder PII.
- Lokale Hardening-Verbesserungen ohne externe Erreichbarkeit.
- Dokumentationsdrift, die nicht direkt zu einem unsicheren Live-Zustand fuehrt.

## 8. Findings vs Gaps

### Confirmed Observations

- Hostname `booking-server`, Ubuntu 26.04 LTS und Kernelklasse wurden durch
  `prod_doctor.sh` bestaetigt.
- App, `www`, Monitor-Redirect, Renderer und Deep Health antworteten im
  Snapshot mit erwarteten HTTP-Statusklassen.
- Apache, PHP-FPM, MariaDB, Docker, fail2ban, cron, unattended-upgrades und
  `fh-pdf-renderer` waren aktiv.
- PDF Renderer und Uptime Kuma Container waren laufend; Kuma meldete 13 aktive
  Monitore und 13 latest green.
- Host Node/npm waren absent.
- Root-Disk und Speicher waren im Snapshot nicht am Warnlimit.
- Certbot-Zertifikat und Renewal-Timer waren vorhanden; Zertifikat war im
  Snapshot noch gueltig.
- Redacted Log-Summary meldete fuer 24 Stunden keine Warnungen in den
  beobachteten Service-Journals und keine app-error-like Lines.
- ROB-393 hat den akuten public `storage`/Session-Befund als Live-Hotfix
  behandelt; ROB-394 hat danach repo-seitige Sensitive-Path-Gates ergaenzt.

### Plausible Risks Needing Focused Follow-Up

- Der neue ROB-394 Sensitive-Path-Helper war im Prod-Snapshot noch
  `helper_missing`. Das ist erwartbar ohne Deploy, bleibt aber ein
  Follow-up-Gate fuer die naechste Produktionsvalidierung.
- HSTS, Security Header, SSH-Policy-Klassen, Firewall/Port-Klassen und
  loopback-only Guarantees sollten in ROB-396 read-only bewertet werden.
- Backup- und Restore-Freshness ist monitoriert, beweist aber nicht alleine
  Off-host-Retention oder vollstaendige Restorebarkeit.
- Log-Klassifizierung muss eng bleiben, damit Scanner-Noise nicht echte App-
  oder PHP-Fehler verdeckt.
- Uptime Kuma Desired State ist dokumentiert, aber Live-Kuma-DB und Push-URLs
  bleiben host-lokal; Drift muss ueber redacted Checks statt Rohdaten erkannt
  werden.

### Explicit Non-Findings

- Keine aktuellen Service-Warnungen oder app-error-like Lines im redacted
  24h-Snapshot.
- Keine Hinweise im Snapshot auf Host Node/npm im Produktionspfad.
- Kein Hinweis im Snapshot auf ausgefallene Core Services, Renderer oder Kuma.
- Kein Secret-Wert wurde fuer dieses Dokument benoetigt oder gespeichert.

### Checks Not Performed

- Keine raw Apache-, SSH-, Firewall-, PHP-FPM-, Kuma-DB- oder `/etc/fh`-Dumps.
- Keine DB-Dumps, DB-Zeilen, Session-/Cache-Inhalte, Backups oder
  Release-Archive wurden geoeffnet.
- Keine Certbot-Aktion, Service-Restarts, Deploys, Paketupdates, Cron-Aenderung,
  Sentry-Smoke, Kuma-Aenderung oder intrusive Scans.
- Keine Live-Ausfuehrung des neuen ROB-394 Sensitive-Path-Helpers auf Prod, weil
  ein Deploy/Install ein separater Live-Write-Gate waere.

## 9. Recommended Follow-Up Backlog

Repo-only docs/scripts/tests:

- ROB-395: Dieses Dokument reviewen und aktuell halten.
- ROB-397: `prod_doctor.sh` um zusaetzliche redacted posture facts erweitern,
  falls ROB-396 sichere Klassen definiert.
- Long-Horizon-Dokumentation fuer ROB-292 nach jedem Milestone aktualisieren.

Read-only prod verification:

- Nach dem naechsten Deploy pruefen, ob der ROB-394 Sensitive-Path-Helper auf
  Prod verfuegbar ist und statusklassig berichtet.
- ROB-396: Header-, SSH-, Firewall-, Port- und loopback-Boundary als Klassen
  aufnehmen, ohne raw configs oder Secrets auszugeben.
- Backup-/Restore-Marker weiter als Freshness-Signale pruefen und
  Restorebarkeit separat durch einen genehmigten Restore-Smoke nachweisen.

Production changes requiring explicit approval:

- Deploy eines Release-Artefakts, das ROB-394 und ROB-395 enthaelt.
- Apache-, SSH-, Firewall-, Certbot-, Kuma-, Cron- oder Sentry-Aenderungen.
- Jede Loesch-, Quarantaene-, Restore-, Backup- oder Service-Restart-Aktion.

## 10. Evidence Appendix

- `AGENTS.md`: Repo-Regeln, Secret-Verbot, Validierungserwartungen und
  Ops-Dokumentenrouting.
- `docs/ops/agent-operations.md`: Production Map, read-only-first Workflow,
  Stop Conditions und redacted Ops-Harness.
- `docs/observability.md`: Sentry-, Kuma-, Health-Endpoint- und
  Scrubbing-Boundaries.
- `docs/deployment.md`: Artifact-Deployment, Host-local Secrets, Deploy- und
  Rollback-Modell.
- `docs/uptime-kuma.md`: Desired State fuer Monitoring ohne Push-Secrets,
  Health-Token-Grenze und Backup/Restore-Kontext.
- `scripts/ops/README.md`: Ops-Script-Inventar, Push-Monitor-Semantik,
  Sensitive-Path-Validation und Log-Klassifizierung.
- `docs/long-horizon/ROB-292-prod-security-hardening/Plan.md`: ROB-393 bis
  ROB-397 Milestone-Gates.
- `docs/long-horizon/ROB-292-prod-security-hardening/Documentation.md`:
  Koordinationsentscheidungen und Baseline-Notizen.
- `bash scripts/ops/prod_doctor.sh`: redacted read-only Snapshot vom
  2026-05-21T13:49:22Z.
- `bash scripts/ops/prod_logs_summary.sh --since "24 hours ago"`: redacted
  read-only Log-Summary vom 2026-05-21T13:49:10Z.
- GitHub PR #296 / ROB-394: repo-seitige Sensitive-Path-Regression-Gates wurden
  vor ROB-395 gemerged.
- GitHub PR #295 / ROB-292: Long-Horizon-Koordinationspaket wurde vor ROB-395
  gemerged.
