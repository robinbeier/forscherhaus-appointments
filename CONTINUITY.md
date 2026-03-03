# Continuity Ledger

-   Goal (incl. success criteria):

    -   Sprint-Plan #2 umsetzen: Typed Contracts auf dem vollstaendigen domaenenkritischen Request-/Controller-Pfad ausrollen und PHPStan schrittweise erhoehen.
    -   Erfolgskriterien: Alle Scope-Methoden ohne direkte `request()`/`$_GET`/`$_POST`-Zugriffe, `check_request_contract_adoption.php` gruen, `phpstan:request-contracts:l1` gruen (blocking), `phpstan:request-contracts:l2` advisory, `test:request-contracts` gruen, neuer CI-Job `typed-request-contracts` aktiv (warn-only -> blocking nach 7 gruennen PR-Laeufen), keine Route-/OpenAPI-Aenderungen.

-   Constraints/Assumptions:

    -   `compat-first` bleibt verbindlich: keine harte Validierungsverschaerfung und keine externen API-/Route-Aenderungen.
    -   Technische Controller (`Console`, `Update`, `Test`, `Installation`) bleiben ausserhalb des Scopes.
    -   `typed-request-dto` bleibt waehrend Ueberlappung unveraendert blocking.
    -   Sprint-Fokus: stabilitaetsorientiert, geringes Risiko, keine Major-Dependency-Upgrades.

-   Key decisions:

    -   Scope ist domaenenkritisch komplett (Auth, Booking/Lifecycle, Scheduling/Backoffice, People/Services Admin, Settings/Compliance, Integrations, API v1 Read+Write).
    -   Neuer Gate `typed-request-contracts` mit Rollout: Job zuerst warn-only, danach blocking nach 7 nicht-cancelled PR-Erfolgen.
    -   PHPStan-Policy fuer diesen Ausbau: L1 blocking, L2 advisory (step-level warn-only).
    -   Interne Contracts via neue/erweiterte DTO-Factories und `Request_normalizer`; externe Semantik bleibt kompatibel.
    -   Delivery-Strategie (low-risk priorisiert): restliche Controller-Migration in mehreren kleinen PRs mit stabilen Domainen-Slices statt Big-Bang-PR.
    -   Slicing-Reihenfolge bestaetigt:
        -   Slice 1: Backoffice CRUD Controller
        -   Slice 2: Settings-Controller Block
        -   Slice 3: Calendar + Booking Lifecycle
        -   Slice 4: API v1 Write-Pfade + restliche Read-Luecken
        -   Slice 5: Finaler Cleanup (Adoption-Guard-Reste + Doku/CI-Feinschliff)
    -   PR-Timing-Entscheidung: aktueller Foundation-Stand wird als eigener erster PR erstellt; Slice 1 folgt als separater Folge-PR.

-   State:

    -   Phase: Phase 0 (Scope/Gate/CI-Foundation) und Phase 1 (DTO/Normalizer-Foundation) umgesetzt.
    -   Controller-Migration auf DTO-Verbrauch (Phase 2-4) gestartet; erste Scope-Gruppe migriert.
    -   CI-Zielbild inkl. Step-Reihenfolge, Artefakte, Diagnostics und Rollback-Regel ist festgelegt.
    -   Neuer Job `typed-request-contracts` ist warn-only integriert; L2 bleibt advisory auf Step-Level.

-   Done:

    -   Sprint-Plan #2, Scope, CI-Policy, DTO/Normalizer-Contracts und Akzeptanzkriterien als canonical continuity briefing verdichtet.
    -   `CONTINUITY.md` als compaction-safe kanonische Arbeitsbasis erstellt.
    -   Ist-Check abgeschlossen: vorhandene Basis ist aktuell auf `typed-request-dto` (Booking/Dashboard/API-Read) begrenzt.
    -   `scripts/ci/config/request_contract_adoption_scope.php` mit vollem domaenenkritischen Scope erstellt.
    -   `scripts/ci/check_request_contract_adoption.php` erstellt (inkl. JSON-Report nach `storage/logs/ci/request-contract-adoption-latest.json`).
    -   Composer-Skripte angelegt: `phpstan:request-contracts:l1`, `phpstan:request-contracts:l2`, `test:request-contracts`, `check:request-contract-adoption`, `check:typed-request-contracts`.
    -   PHPStan-Configs `phpstan.request-contracts.l1.neon.dist` und `phpstan.request-contracts.l2.neon.dist` angelegt.
    -   PHPUnit-Config/Bootstrap fuer Request Contracts angelegt (`phpunit.request-contracts.xml`, `tests/bootstrap_request_contracts.php`).
    -   Neue DTO-Factories implementiert: `Auth_request_dto_factory`, `Backoffice_request_dto_factory`, `Calendar_request_dto_factory`, `Integrations_request_dto_factory`.
    -   `Request_normalizer` um `normalizeJsonAssocArray`, `normalizeDateTimeYmdHis`, `normalizeEnumString`, `normalizeFloat` erweitert.
    -   `Api_request_dto_factory` um Write-/Date-/Settings-DTOs erweitert (`ApiEntityWritePayloadDto`, `ApiDateFilterDto`, `ApiSettingsUpdateDto`).
    -   Neue Unit-Tests hinzugefuegt und gruen: Auth/Backoffice/Calendar/Integrations/API-Write + erweiterte RequestNormalizer-Tests.
    -   Dokumentation aktualisiert (`README.md`, `AGENTS.md`) inkl. Commands/Status-Tracking fuer `typed-request-contracts`.
    -   Erste Scope-Controller auf DTO-Verbrauch umgestellt:
        -   Auth/Session: `Account::{save,validate_username}`, `Login::validate`, `Recovery::perform`, `Localization::change_language`
        -   Privacy/Consent: `Privacy::delete_personal_information`, `Consents::save`
        -   Integrations/LDAP/API-Settings: `Caldav::{connect_to_server,disable_provider_sync}`, `Google::{oauth_callback,get_google_calendars,select_google_calendar,disable_provider_sync}`, `Ldap_settings::{save,search}`, `Settings_api_v1::update`
    -   Adoption-Guard Verletzungen reduziert von 106 auf 90.
    -   Branch erstellt: `codex/structural-typed-contracts-full-request-path`.
    -   Commit erstellt: `b94381f1` (`Add request-contracts foundation and initial migration`).

-   Now:

    -   Foundation-Stand als PR 1 erstellen (Gate/DTO-Foundation + initiale Migration).
    -   Danach mit Slice 1 (Backoffice CRUD) als naechstem PR fortsetzen.
    -   Adoption-Guard weiter schrittweise abbauen (aktuell 90 Verstoesse).

-   Next:

    -   PR 1 (aktueller Stand) pushen/oeffnen und CI beobachten.
    -   Danach Slice 1 branch-basiert umsetzen und als PR 2 einreichen.
    -   Nach jeder Teilmigration Adoption-Guard erneut laufen lassen bis `violation_count=0`.
    -   PHPStan-L2 advisory findings reduzieren (aktuell viele Unknown-Class-Meldungen im erweiterten Controller-Scope).
    -   Nach Controller-Migration: `typed-request-contracts` 7 nicht-cancelled gruene PR-Laeufe sammeln und Job-Level blocking schalten.

-   Open questions (UNCONFIRMED if needed):

    -   UNCONFIRMED: Maximale Zielgroesse pro Folge-PR (Dateien/Methoden), falls wir fuer Review-Speed noch feiner schneiden muessen.

-   Working set (files/ids/commands):
    -   Canonical ledger: `/Users/robinbeier/Developers/forscherhaus-appointments/CONTINUITY.md`
    -   Referenzplan: User-Vorgabe "Sprint-Plan #2: Typed Contracts auf gesamten Request-/Controller-Pfad + PHPStan schrittweise erhoehen"
    -   Kernbefehle:
        -   `composer phpstan:request-contracts:l1`
        -   `composer phpstan:request-contracts:l2`
        -   `composer test:request-contracts`
        -   `php scripts/ci/check_request_contract_adoption.php`
    -   Wichtige Outcomes:
        -   `composer phpstan:request-contracts:l1`: PASS.
        -   `composer test:request-contracts`: PASS (44 Tests, 132 Assertions).
        -   `php scripts/ci/check_request_contract_adoption.php`: FAIL (zuletzt 90 Violations, Report geschrieben).
        -   `composer phpstan:request-contracts:l2`: advisory FAIL erwartet; Report geschrieben nach `storage/logs/ci/phpstan-request-contracts-l2.raw` (807 Zeilen).
