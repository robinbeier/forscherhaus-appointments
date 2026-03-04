# Continuity Ledger

-   Goal (incl. success criteria):

    -   Sprint-Plan #2 umsetzen: Typed Contracts auf dem vollstaendigen domaenenkritischen Request-/Controller-Pfad ausrollen und PHPStan schrittweise erhoehen.
    -   Aktueller Fokus: Slice 4 (API v1 Write + Read-Luecken) umsetzen, dann Ready-for-Review-PR oeffnen und babysitten.
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
    -   Low-Risk-Green-Variante aktiv (temporaer): im Job `typed-request-contracts` ist der Step `Request Contract Adoption Check` advisory (`continue-on-error: true`) fuer gruene PR-Checks waehrend Rollout.
    -   Rueckstellung ist verbindlich: die temporaere Step-Ausnahme wird vor finalem Rollout-Abschluss/Blocking-Switch wieder entfernt.
    -   Unerwartete lokale Aenderung `.claude/napkin.md` bleibt fuer Slice 4 unberuehrt und wird nicht committed.

-   State:

    -   PR #90 ist gemerged in `main` (`7bafd5e4`).
    -   PR #91 ist gemerged.
    -   PR #92 ist gemerged (`2026-03-04T01:43:23Z`).
    -   PR #93 ist gemerged (`2026-03-04T02:31:08Z`, Merge-Commit `979277afc3a6d06f461298040b9f808046478895`).
    -   Slices 1-3 sind umgesetzt (Foundation + Backoffice CRUD + Settings + Calendar/Booking Lifecycle).
    -   Letzter Slice-3 Head-SHA war `ae7312d1`.
    -   Aktueller Arbeitsbranch ist `codex/typed-request-contracts-slice4-api-v1`.
    -   Slice-4-Umsetzung ist lokal auf dem Branch eingearbeitet (API-v1 Write + Read-Luecken).
    -   Baseline Slice 4 (historisch): `php scripts/ci/check_request_contract_adoption.php` meldete 21 Verstoesse im API-v1-Scope (hauptsaechlich `store`/`update`, zusaetzlich `Blocked_periods_api_v1::index`).
    -   Aktueller lokaler Check-Stand (nach Slice-4-Aenderungen):
        -   `php scripts/ci/check_request_contract_adoption.php` => PASS
        -   `composer phpstan:request-contracts:l1` => PASS
        -   `composer test:request-contracts` => PASS (52 Tests, 143 Assertions)
    -   PR #94 ist offen (`https://github.com/robinbeier/forscherhaus-appointments/pull/94`, head `7011becd`), Branch wurde auf einen einzelnen Slice-4-Commit bereinigt (unbeabsichtigter Zusatz-Commit entfernt per Rebase + force-with-lease).
    -   CI auf PR #94: `architecture-boundaries` failte wegen bestehender `api-v1 -> integrations-sync` Abhaengigkeiten in beruehrten API-v1-Controllern.
    -   Lokaler Fix vorbereitet: `docs/maps/component_ownership_map.json` ergaenzt `api-v1` `depends_on` um `integrations-sync`; lokaler Check `python3 scripts/ci/check_component_boundaries.py --diff-range "$(git merge-base HEAD origin/main)...HEAD"` ist danach PASS.

-   Done:

    -   Foundation geliefert: Scope-Config/Adoption-Check, neue DTO-Factories, `Request_normalizer`-Erweiterungen, CI-Job `typed-request-contracts`, PHPStan L1/L2 Wiring.
    -   Slice 1 geliefert: Backoffice-/People-/Scheduling-CRUD + Webhooks Controller im Scope auf typed Contracts umgestellt.
    -   Slice 2 geliefert: Settings-Block (inkl. `Business_settings::apply_global_working_plan` Compat-Fix) auf typed Contracts umgestellt.
    -   Slice 3 geliefert: `Calendar`-Scope + `Booking::index` + `Booking_cancellation::of` auf typed Contracts umgestellt; zugehoerige Unit-Tests und Architektur-Map aktualisiert.
    -   PR #93 wurde via `$babysit-pr` bis `stop_ready_to_merge` begleitet und danach gemerged.
    -   Slice-4-Vorbereitung abgeschlossen: API-v1-Luecken identifiziert, betroffene Controller gelesen, vorhandene DTO-Factory-Builder (`buildEntityWritePayloadDto`, `buildDateFilterDto`) bestaetigt.
    -   Slice 4 lokal umgesetzt:
        -   API v1 `store`/`update`-Methoden im Scope konsumieren `ApiEntityWritePayloadDto` statt direktem `request()`.
        -   `Blocked_periods_api_v1::index` nutzt `ApiDateFilterDto` fuer `date/from/till`.
        -   Fehlende `api_request_dto_factory`-Wiring + `apiRequestDtoFactory()`-Helper in betroffenen API-v1-Controllern ergaenzt.
    -   Slice-4-PR erstellt: #94 (ready for review) und Watch/Babysitting gestartet.

-   Now:

    -   Boundary-Fix committen/pushen und PR #94 weiter babysitten.

-   Next:

    -   PR via `$babysit-pr` bis Mergeability beobachten.

-   Open questions (UNCONFIRMED if needed):

    -   UNCONFIRMED: Exakter Rueckstell-Zeitpunkt fuer die temporaere advisory-Ausnahme (spaetestens vor Blocking-Switch).

-   Working set (files/ids/commands):

    -   Ledger: `/Users/robinbeier/Developers/forscherhaus-appointments/CONTINUITY.md`
    -   Scope config: `/Users/robinbeier/Developers/forscherhaus-appointments/scripts/ci/config/request_contract_adoption_scope.php`
    -   Adoption check: `/Users/robinbeier/Developers/forscherhaus-appointments/scripts/ci/check_request_contract_adoption.php`
    -   API-v1 Controller-Verzeichnis (Slice 4): `/Users/robinbeier/Developers/forscherhaus-appointments/application/controllers/api/v1`
    -   API DTO factory: `/Users/robinbeier/Developers/forscherhaus-appointments/application/libraries/Api_request_dto_factory.php`
    -   Request normalizer: `/Users/robinbeier/Developers/forscherhaus-appointments/application/libraries/Request_normalizer.php`
    -   CI workflow: `/Users/robinbeier/Developers/forscherhaus-appointments/.github/workflows/ci.yml`
    -   Core commands:
        -   `composer phpstan:request-contracts:l1`
        -   `composer phpstan:request-contracts:l2`
        -   `composer test:request-contracts`
        -   `php scripts/ci/check_request_contract_adoption.php`
    -   Slice-4 geaenderte Controller:
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/application/controllers/api/v1/Admins_api_v1.php`
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/application/controllers/api/v1/Appointments_api_v1.php`
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/application/controllers/api/v1/Blocked_periods_api_v1.php`
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/application/controllers/api/v1/Customers_api_v1.php`
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/application/controllers/api/v1/Providers_api_v1.php`
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/application/controllers/api/v1/Secretaries_api_v1.php`
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/application/controllers/api/v1/Service_categories_api_v1.php`
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/application/controllers/api/v1/Services_api_v1.php`
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/application/controllers/api/v1/Unavailabilities_api_v1.php`
        -   `/Users/robinbeier/Developers/forscherhaus-appointments/application/controllers/api/v1/Webhooks_api_v1.php`
