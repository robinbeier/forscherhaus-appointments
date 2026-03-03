# Continuity Ledger

-   Goal (incl. success criteria):

    -   Sprint-Plan #2 umsetzen: Typed Contracts auf dem vollstaendigen domaenenkritischen Request-/Controller-Pfad ausrollen und PHPStan schrittweise erhoehen.
    -   Erfolgskriterien: Alle Scope-Methoden ohne direkte `request()`/`$_GET`/`$_POST`-Zugriffe, `check_request_contract_adoption.php` gruen, `phpstan:request-contracts:l1` gruen (blocking), `phpstan:request-contracts:l2` advisory, `test:request-contracts` gruen, `typed-request-contracts` im CI aktiv, keine externen API-/Route-Aenderungen.

-   Constraints/Assumptions:

    -   `compat-first` bleibt verbindlich: keine harte Validierungsverschaerfung, keine Route-/OpenAPI-Aenderungen.
    -   Technische Controller (`Console`, `Update`, `Test`, `Installation`) bleiben ausserhalb des Scopes.
    -   `typed-request-dto` bleibt waehrend der Ueberlappung blocking.
    -   Low-risk slicing ist priorisiert (mehrere kleinere PRs statt Big-Bang).

-   Key decisions:

    -   Delivery in Slices:
        -   Slice 1: Backoffice CRUD Controller
        -   Slice 2: Settings-Controller Block
        -   Slice 3: Calendar + Booking Lifecycle
        -   Slice 4: API v1 Write + Read-Luecken
        -   Slice 5: Final Cleanup
    -   PR #90 (Foundation + initial migration) wurde zuerst separat gemerged; Slice 1 folgt als naechster PR.
    -   Low-Risk-Green-Variante aktiv (temporaer): im Job `typed-request-contracts` ist der Step `Request Contract Adoption Check` advisory (`continue-on-error: true`) fuer gruene PR-Checks waehrend Rollout.
    -   Rueckstellung ist verbindlich: die temporaere Step-Ausnahme wird vor finalem Rollout-Abschluss/Blocking-Switch wieder entfernt.

-   State:

    -   PR #90 ist gemerged in `main` (Merge-Commit `7bafd5e4`).
    -   Foundation steht: DTO/Normalizer-Erweiterungen, neue Factories, CI wiring, PHPStan L1/L2 configs, Request-Contract-Tests.
    -   Initial migrierte Controller aus PR #90: Auth/Session, Privacy/Consents, Teile Integrations/LDAP, `Settings_api_v1::update`.
    -   Aktueller Fokus: Slice 1 Branch `codex/typed-request-contracts-slice1-backoffice-crud`.
    -   Slice 1 ist implementiert fuer Backoffice-/People-Service-CRUD Controller inkl. Backoffice-Webhooks.
    -   Adoption-Guard Delta: 90 -> 40 Violations.
    -   Slice-1-Commit `19d848d5` ist auf `origin/codex/typed-request-contracts-slice1-backoffice-crud` gepusht.
    -   PR 2 ist offen: `https://github.com/robinbeier/forscherhaus-appointments/pull/91` (ready for review).
    -   CI-Lauf `22642974401` auf SHA `906856a2` hatte ein rotes Job-Signal: `architecture-boundaries` (`component boundary check`, 17 violations).
    -   Fix auf PR #91 gepusht: Commit `16ba19fb` passt Component-Dependencies in der Architektur-Map an; lokaler `check_component_boundaries` gegen `origin/main...HEAD` ist gruen.

-   Done:

    -   `request_contract_adoption_scope.php` und `check_request_contract_adoption.php` eingefuehrt.
    -   Neue Factories: `Auth_request_dto_factory`, `Backoffice_request_dto_factory`, `Calendar_request_dto_factory`, `Integrations_request_dto_factory`.
    -   `Request_normalizer` erweitert (`normalizeJsonAssocArray`, `normalizeDateTimeYmdHis`, `normalizeEnumString`, `normalizeFloat`).
    -   `Api_request_dto_factory` fuer Write-/Filter-/Settings-DTOs erweitert.
    -   CI-Job `typed-request-contracts` eingefuehrt; L2 advisory-step ist mit `if: always()` abgesichert.
    -   Review-Fixrunden fuer PR #90 abgeschlossen (Auth/Caldav/Ldap compat fixes, architecture-boundary fixes).
    -   Low-Risk-Green-Variante fuer `typed-request-contracts` gepusht in Commit `b6967130`.
    -   PR #90 erreichte vor Merge `11/11` gruen.
    -   Slice-1-Migration umgesetzt:
        -   `Appointments::{search,store,find,update,destroy}`
        -   `Blocked_periods::{search,store,find,update,destroy}`
        -   `Unavailabilities::{search,store,find,update,destroy}`
        -   `Admins::{search,store,find,update,destroy}`
        -   `Providers::{search,store,find,update,destroy}`
        -   `Customers::{find,search,store,update,destroy}`
        -   `Secretaries::{search,store,find,update,destroy}`
        -   `Services::{search,store,find,update,destroy}`
        -   `Service_categories::{search,store,find,update,destroy}`
        -   `Webhooks::{search,store,update,destroy,find}`
    -   Lokale Verifikation nach Slice-1-Migration:
        -   `composer test:request-contracts`: PASS (48 tests, 139 assertions)
        -   `composer phpstan:request-contracts:l1`: PASS
        -   `php scripts/ci/check_request_contract_adoption.php`: FAIL erwartet, aber reduziert auf `violation_count=40`
    -   PR-2-CI-Fix: `architecture-boundaries`-Failure durch aktualisierte `depends_on`-Kanten in `docs/maps/component_ownership_map.json` adressiert und `docs/architecture-map.md` regeneriert.

-   Now:

    -   PR 2 wird aktiv babysittet (`$babysit-pr`) bis ready-to-merge/closed oder user-help-needed.
    -   Watch-Loop laeuft auf neuem PR-SHA `16ba19fb`; Ziel: alle Checks gruen + keine neuen Review-Blocker.
    -   Restliche Violations liegen nach Slice 1 in Booking/Calendar/Settings/API-v1-Write.

-   Next:

    -   CI-/Review-Feedback auf PR #91 verarbeiten.
    -   Danach Slice 2 (Settings) starten.
    -   Nach Slice-1-PR weiterhin offene Verstoesse in Slice 2+ abbauen.
    -   Temporaere Low-Risk-Green-Ausnahme spaeter wieder auf strict zurueckstellen.

-   Open questions (UNCONFIRMED if needed):

    -   UNCONFIRMED: Exakter Rueckstell-Zeitpunkt fuer die temporaere advisory-Ausnahme (spaetestens vor Blocking-Switch).

-   Working set (files/ids/commands):

    -   Ledger: `/Users/robinbeier/Developers/forscherhaus-appointments/CONTINUITY.md`
    -   CI workflow: `/Users/robinbeier/Developers/forscherhaus-appointments/.github/workflows/ci.yml`
    -   CI run/job (vor Fix): `22642974401` / `65623661583`
    -   Boundary map: `/Users/robinbeier/Developers/forscherhaus-appointments/docs/maps/component_ownership_map.json`
    -   Boundary checker: `/Users/robinbeier/Developers/forscherhaus-appointments/scripts/ci/check_component_boundaries.py`
    -   Scope config: `/Users/robinbeier/Developers/forscherhaus-appointments/scripts/ci/config/request_contract_adoption_scope.php`
    -   Adoption check: `/Users/robinbeier/Developers/forscherhaus-appointments/scripts/ci/check_request_contract_adoption.php`
    -   Slice-1-Controller:
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/application/controllers/Appointments.php`
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/application/controllers/Blocked_periods.php`
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/application/controllers/Unavailabilities.php`
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/application/controllers/Admins.php`
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/application/controllers/Providers.php`
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/application/controllers/Customers.php`
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/application/controllers/Secretaries.php`
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/application/controllers/Services.php`
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/application/controllers/Service_categories.php`
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/application/controllers/Webhooks.php`
    -   Core commands:
        -   `composer phpstan:request-contracts:l1`
        -   `composer phpstan:request-contracts:l2`
        -   `composer test:request-contracts`
        -   `php scripts/ci/check_request_contract_adoption.php`
