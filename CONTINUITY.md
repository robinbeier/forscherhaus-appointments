-   Goal (incl. success criteria):

    -   Sprint umsetzen: Architekturgrenzen technisch erzwingen via Hybrid-Gate (`Deptrac` + map-basierter Component-Boundary-Check + generiertes `CODEOWNERS`).
    -   Success criteria:
        -   `deptrac.yaml` vorhanden; `composer deptrac:analyze` laeuft.
        -   `check_component_boundaries.py` validiert gegen `depends_on` aus Component-Map.
        -   `.github/CODEOWNERS` wird aus `docs/maps/component_ownership_map.json` generiert und per Drift-Check (`--check`) erzwungen.
        -   CI-Job `architecture-boundaries` laeuft auf `pull_request(main)` + `push(main)`.
        -   Phase 1 warn-only (`continue-on-error: true`), Umschaltung auf blocking nach 7 nicht-cancelled `success`-PR-Laeufen.
        -   README/AGENTS/docs enthalten Repro-, Tracking- und Rollback-Regeln.
        -   Keine Runtime/API-Vertragsaenderung in `application/` erforderlich.

-   Constraints/Assumptions:

    -   Boundary-Modell ist festgelegt: Hybrid (Deptrac + Loader/Require-Boundary-Check).
    -   Gating fokussiert in diesem Sprint auf geaenderte Dateien; Legacy-Verstoesse ausserhalb Diff sind nicht blocker.
    -   Component-Map (`docs/maps/component_ownership_map.json`) ist Single Source of Truth fuer Ownership + `depends_on`.
    -   `CODEOWNERS` darf nicht manuell gepflegt werden; nur Generator-Output ist gueltig.
    -   CI-Jobname bleibt stabil: `architecture-boundaries`.
    -   Release-Phase-Guardrail: keine Major-Dependency-Upgrades ohne explizite Freigabe.
    -   Repo-Guardrails: kein Produktionscode ausserhalb `application/`; keine direkten Aenderungen in `system/`.

-   Key decisions:

    -   Deptrac-Layer-Regeln:
        -   `Controllers` -> `Libraries, Models, Helpers, Core`
        -   `Libraries` -> `Libraries, Models, Helpers, Core`
        -   `Models` -> `Models, Helpers, Core`
        -   `Helpers` -> `Helpers, Core`
        -   `Core` -> `Core, Helpers`
    -   Component-Boundary-Check wertet literal Loader-Calls (`model/library/helper`) und `require(_once) APPPATH...` gegen Component-Map aus.
    -   Dynamische/non-literal Loader-Ausdruecke werden als `unresolved` berichtet, nicht als violation gewertet.
    -   Rollback-Policy fuer Delivery-Blocker: `continue-on-error: true` reaktivieren + Follow-up-Issue (<=14 Tage) zur Rueckkehr auf blocking.
    -   Deptrac-Noise-Strategie: kein Dependency-Upgrade ad hoc; stattdessen minimaler Hardening-Schritt (klare lokale Ausfuehrung ueber Docker/CI-PHP) sofort, eigentlicher Dependency-Fix im geplanten Dependency-Sweep.

-   State:

    -   Planungsstand fuer den Sprint ist decision-complete.
    -   Phase 0-4 fuer den Architekturgrenzen-Sprint sind lokal umgesetzt und verifiziert.
    -   Rollout-Status bleibt warn-only fuer `architecture-boundaries` (blocking switch nach 7 gruenen PR-Laeufen ausstehend).
    -   Umsetzung ist committed auf Branch `codex/structural-architecture-boundary-gates` (Commit `800e2534`).

-   Done:

    -   Branch erstellt: `codex/structural-architecture-boundary-gates`.
    -   Composer/Dependency-Bootstrap umgesetzt:
        -   `deptrac/deptrac` in `require-dev`.
        -   Neue Composer-Contracts: `deptrac:analyze`, `check:component-boundaries`, `check:codeowners-sync`, `check:architecture-boundaries`.
    -   Deptrac-Layer-Gate umgesetzt:
        -   `deptrac.yaml` erstellt (Layers + Ruleset gemaess Sprint-Spec).
        -   `scripts/ci/run_deptrac_changed_gate.sh` erstellt (Diff-Range-Detection, changed-file Filter, JSON/GitHub-Actions Reports).
    -   Component-Boundary-Gate umgesetzt:
        -   `scripts/ci/check_component_boundaries.py` erstellt.
        -   `scripts/ci/config/component_boundary_scope.php` erstellt.
        -   Report-Pfad: `storage/logs/ci/component-boundary-latest.json`.
    -   CODEOWNERS-Flow umgesetzt:
        -   `scripts/docs/generate_codeowners_from_map.py` erstellt inkl. `--check`.
        -   `.github/CODEOWNERS` generiert und deterministisch sortiert.
    -   CI-Wiring umgesetzt:
        -   Neuer Job `architecture-boundaries` in `.github/workflows/ci.yml`.
        -   `continue-on-error: true`, Artifact-Upload + Failure-Diagnostics verdrahtet.
    -   Docs aktualisiert:
        -   `README.md`, `AGENTS.md`, `docs/readme.md`.
    -   Lokale Verifikation ausgefuehrt:
        -   `python3 scripts/docs/generate_codeowners_from_map.py --check` -> PASS
        -   `bash scripts/ci/run_deptrac_changed_gate.sh` -> PASS (skip, keine scoped PHP-Changes)
        -   `python3 scripts/ci/check_component_boundaries.py` -> PASS (skip, keine scoped PHP-Changes)
        -   `composer check:codeowners-sync` -> PASS
        -   `composer check:component-boundaries` -> PASS
        -   `composer check:architecture-boundaries` -> PASS
        -   `composer deptrac:analyze -- --no-progress` -> expected non-zero wegen Legacy-Verstoessen; lokal auf Host-PHP 8.5 zusaetzlich Deprecation-Noise aus Vendor.
    -   Commit erstellt: `800e2534` (`Add hybrid architecture boundary gates`).
    -   Deptrac Deprecation-Check geklaert:
        -   Unter Container-PHP 8.4 (`docker compose run --rm php-fpm ...`) keine Deprecation-Zeilen beobachtet; weiterhin erwarteter Exit 1 nur wegen Legacy-Violations.
        -   CI-Workflow `architecture-boundaries` bleibt auf `php_version: 8.3`.
    -   Hardening-Schritt ohne Dependency-Aenderung umgesetzt:
        -   `README.md`: Deptrac-Lokalaufruf in Testblock auf Docker (`docker compose run --rm php-fpm composer deptrac:analyze`) umgestellt.
        -   `README.md` + `AGENTS.md`: Hinweis ergaenzt, dass Host-PHP (z. B. 8.5) Vendor-Deprecation-Noise zeigen kann; fuer CI-paritaet Docker-Run verwenden.

-   Now:

    -   Separater Commit fuer Hardening-Aenderungen ist vom User angefordert und wird jetzt erstellt.
    -   Canonical Ledger ist auf Commit- und Verifikationsstand synchronisiert.

-   Next:

    -   Nach dem Commit: CI-Run im PR beobachten und `architecture-boundaries` Rollout-Streak tracken.
    -   Nach 7 nicht-cancelled SUCCESS-Laeufen: `continue-on-error` in einem separaten Commit entfernen.
    -   Optional: follow-up fuer Baseline-Debt planen (Repo-weite Deptrac-Bereinigung ausserhalb changed-file scope).

-   Open questions (UNCONFIRMED if needed):

    -   Keine offenen fachlichen Fragen im Plan.

-   Working set (files/ids/commands):
    -   Files (Plan/Scope):
        -   `CONTINUITY.md`
        -   `deptrac.yaml`
        -   `.github/CODEOWNERS`
        -   `.github/workflows/ci.yml`
        -   `composer.json`
        -   `composer.lock`
        -   `scripts/docs/generate_codeowners_from_map.py`
        -   `scripts/ci/run_deptrac_changed_gate.sh`
        -   `scripts/ci/check_component_boundaries.py`
        -   `scripts/ci/config/component_boundary_scope.php`
        -   `docs/maps/component_ownership_map.json`
        -   `README.md`
        -   `AGENTS.md`
        -   `docs/readme.md`
    -   Commands (wichtigste Outcomes):
        -   `composer update deptrac/deptrac --with-all-dependencies --no-interaction`
        -   `python3 scripts/docs/generate_codeowners_from_map.py`
        -   `python3 scripts/docs/generate_codeowners_from_map.py --check`
        -   `bash scripts/ci/run_deptrac_changed_gate.sh`
        -   `python3 scripts/ci/check_component_boundaries.py`
        -   `composer check:architecture-boundaries`
        -   `composer deptrac:analyze -- --no-progress`
        -   `docker compose run --rm php-fpm sh -lc 'php -v | head -n 1; composer deptrac:analyze -- --no-progress ...'`
