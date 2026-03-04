# CI Write Contracts

Mutation-kritische Contract-Smokes fuer Booking- und API-Write-Pfade.

## Scope

- Booking write-path contracts (`POST /booking/register`, `GET /booking/reschedule/{hash}`, `POST /booking_cancellation/of/{hash}`)
- API OpenAPI write contracts (`POST/PUT/DELETE` auf `customers` + `appointments`)
- Keine produktiven Route-/Runtime-Verhaltensaenderungen; nur Qualitaets-/CI-Layer

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

## Reports / Artifacts

- Booking write report: `storage/logs/ci/booking-write-contract-<UTC>.json`
- API write report: `storage/logs/ci/api-openapi-write-contract-<UTC>.json`
- CI uploads both report globs always (`if: always()`), inklusive Failure-Diagnostics

Jeder Report enthaelt:

- `run_id`
- check status + `duration_ms`
- retry metadata (`max_retries`, `attempts`, retry events)
- cleanup summary (`created`, `deleted`, `failures`)

## Flake Control

- Maximal ein Retry (`--retry-count=1`) nur bei transient runtime errors:
  - timeout / timed out
  - 502 / 503 / 504
  - connection reset/refused, failed/could-not-connect
- Kein Retry bei Contract-Mismatch (Status-/Schema-/Typverletzung)

## CI Jobs / Rollout

- `write-contract-booking` (warn-only in Rollout: `continue-on-error: true`)
- `write-contract-api` (warn-only in Rollout: `continue-on-error: true`)
- Beide changed-file gated via `changes` outputs:
  - `write_contract_booking`
  - `write_contract_api`

Blocking-Switch:

- Nach 7 non-cancelled, aufeinanderfolgenden `success`-PR-Runs je Job `continue-on-error` entfernen.

Tracking:

```bash
for run_id in $(gh run list --workflow CI --event pull_request --limit 40 --json databaseId -q '.[].databaseId'); do
  gh run view "$run_id" --json jobs -q '.jobs[] | select(.name=="write-contract-booking") | .conclusion'
done | awk '$1 != "cancelled"' | head -n 7

for run_id in $(gh run list --workflow CI --event pull_request --limit 40 --json databaseId -q '.[].databaseId'); do
  gh run view "$run_id" --json jobs -q '.jobs[] | select(.name=="write-contract-api") | .conclusion'
done | awk '$1 != "cancelled"' | head -n 7
```

## Rollback Policy

- Nur den betroffenen Job temporaer auf `continue-on-error: true` zurueckstellen.
- Follow-up-Issue mit Rueckkehrfrist <= 14 Tage zum Blocking-Modus.
