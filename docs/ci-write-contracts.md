# CI Write Contracts

Mutation-kritische Contract-Smokes fuer Booking- und API-Write-Pfade.

## Scope

- Booking write-path contracts (`POST /booking/register`, `GET /booking/reschedule/{hash}`, `POST /booking_cancellation/of/{hash}`)
- API OpenAPI write contracts (`POST/PUT/DELETE` auf `customers` + `appointments`)

Der Booking-Contract legt einen normalen Termin weiterhin mit
`manage_mode=false` und ohne bestehende IDs an. Vor einem bestehenden
Termin-Update ruft derselbe HTTP-Client dagegen die kanonische
Reschedule-Route auf. Nur die dabei serverseitig und sitzungsgebunden erzeugte
Einmal-Authority darf das anschliessende Update freigeben. `manage_mode`, IDs
oder der Route-Hash allein reichen nicht. Der vollstaendige Sicherheitsvertrag
steht in [Public Reschedule Authority](security/public-reschedule-authority.md).

## Local Repro (Docker CI-Parity)

```bash
docker compose up -d mysql php-fpm nginx
until docker compose exec -T mysql mysqladmin ping -h localhost -uroot -psecret --silent; do sleep 2; done
until docker compose exec -T mysql mysql -uuser -ppassword -e "USE easyappointments; SELECT 1;" >/dev/null 2>&1; do sleep 2; done
for attempt in 1 2 3; do docker compose exec -T php-fpm php index.php console install && break; [ "$attempt" -eq 3 ] && exit 1; sleep 3; done

docker compose exec -T php-fpm composer contract-test:booking-write -- \
  --base-url=http://nginx --index-page=index.php \
  --username=administrator --password=administrator \
  --booking-search-days=14 --retry-count=1

docker compose exec -T php-fpm composer contract-test:api-openapi-write -- \
  --base-url=http://nginx --index-page=index.php --openapi-spec=/var/www/html/openapi.yml \
  --username=administrator --password=administrator \
  --retry-count=1 --booking-search-days=14

docker compose down -v --remove-orphans
```

Optional (beide hintereinander):

```bash
docker compose exec -T php-fpm composer contract-test:write-path -- \
  --base-url=http://nginx --index-page=index.php \
  --username=administrator --password=administrator \
  --openapi-spec=/var/www/html/openapi.yml \
  --retry-count=1 --booking-search-days=14
```

## Allgemeiner Mutation-Vertrag

Jeder mutation-kritische öffentliche Write folgt derselben festen Reihenfolge:

`Route -> Request-Klassifikation -> serverseitige Authority -> feste Lock-/Transaktionsgrenze -> Mutation -> Post-Commit-Effekte`.

Die Route klassifiziert den Request; die Autorisierung wird serverseitig aus
kanonischer Authority und aktuellem Datenbankzustand entschieden. Caller-
supplied Flags, IDs, Hashes, Tokens oder Pfade reichen dafür nie aus. Lock und
Transaktion grenzen die Prüfung und die anschließende Mutation gegen
konkurrierende Writes ab. Erst nach erfolgreichem Commit dürfen Nebenwirkungen
wie Events, Benachrichtigungen oder Cleanup ausgelöst werden. Wird ein Contract
verletzt, wird atomar abgelehnt: Es gibt keine Teilmutation und keine Post-
Commit-Effekte. Bei konkurrierenden Prüfungen sind eine zweite DB-Verbindung
und eine globale Lock-Reihenfolge ausdrücklich mitzudenken, damit keine
Prüfung ihre eigene uncommitted Sicht als Authority verwendet oder ein
Deadlock entsteht.

## Evidence-Privacy-Vertrag

Logs, Reports, Tests, PR-Evidenz und Linear-Einträge enthalten weder Secrets
noch Capability-, Authority- oder Tokenwerte, request- oder
personenbezogene Hashes oder personenbezogene Daten. Technische Commit-, Run-
und anonymisierte Fixture-IDs bleiben für Exact-Head- und CI-Evidenz zulässig.
Nachweise beschreiben nur Status, Typen, Zeitpunkte und redigierte
Fehlerklassen. Retries sind ausschließlich für transiente Laufzeitfehler
zulässig; bei einem Contract-Mismatch wird weder automatisch wiederholt noch
die Evidenz durch weitere Mutation vergrößert.

## Reports / Artifacts

- Booking write report: `storage/logs/ci/booking-write-contract-<UTC>.json`
- API write report: `storage/logs/ci/api-openapi-write-contract-<UTC>.json`
- CI uploads both report globs always (`if: always()`), inklusive Failure-Diagnostics

Jeder Report enthaelt:

- `run_id`
- check status + `duration_ms`
- retry metadata (`max_retries`, `attempts`, retry events)
- cleanup summary (`created`, `deleted`, `failures`)

Booking-Reports redigieren Route-Hashes, serverseitige Authority-/Tokenwerte,
Customer-Payloads und personenbezogene Felder. Diese Werte bleiben nur fuer die
laufende In-Process-Pruefung und das Cleanup verfuegbar.

## Flake Control

- Maximal ein Retry (`--retry-count=1`) nur bei transient runtime errors:
  - timeout / timed out
  - 502 / 503 / 504
  - connection reset/refused, failed/could-not-connect
- Kein Retry bei Contract-Mismatch (Status-/Schema-/Typverletzung)

## CI Jobs

- `write-contract-booking` ist aktuell blockierend.
- `write-contract-api` ist aktuell blockierend.
- Beide changed-file gated via `changes` outputs:
  - `write_contract_booking`
  - `write_contract_api`

Die verbindliche CI-Konfiguration und die tatsächlichen Gate-Bedingungen
stehen ausschließlich in [.github/workflows/ci.yml](../.github/workflows/ci.yml).

## Rollback Policy

Ein Rollback der Blocking-Eigenschaft ist nur als ausdrücklich begründete,
zeitlich begrenzte CI-Änderung zulässig; dabei bleibt
`.github/workflows/ci.yml` die alleinige CI-Quelle. Diese Dokumentation
schwächt kein Gate ab und beschreibt keine alternative Warnphase. Die in
[WORKFLOW.md](../WORKFLOW.md) geforderte Rückkehrfrist und das Follow-up-Issue
bleiben verbindlich.
