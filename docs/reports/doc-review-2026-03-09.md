# Documentation Drift Review - 2026-03-09

## Executive summary (overall drift risk)

Overall drift risk: **medium**.

Two high-impact drifts were identified and fixed in this run:
- A dangerous update instruction that previously suggested removing `/system`.
- A missing API contract note for the fork invariant `services.attendants_number = 1`.

No broken internal Markdown links were detected in `README.md`, `AGENTS.md`, and `docs/**/*.md`.

## Findings table

| Severity | Doc path | Stale claim | Implementation evidence | Recommended fix |
|---|---|---|---|---|
| P0 | `docs/update-guide.md:50-55` | The old text implied `/system` was unnecessary and could be removed during updates. | Bootstrap expects `system` at runtime (`index.php:151-155`) and the directory exists in-repo (`system/`). Removing it breaks app boot. | Replace with explicit keep guidance. **Applied** in `docs/update-guide.md:50-55`. |
| P1 | `docs/rest-api.md:232-238` | Services section documented `attendantsNumber` but did not state the fork restriction to value `1`. | Validation enforces exactly `1` and rejects other values (`application/models/Services_model.php:189-198`). | Add explicit API note in services resource section. **Applied** in `docs/rest-api.md:238`. |
| P2 | `docs/get-involved.md:13-21` | Contribution/contact paths point to upstream Easy!Appointments channels (submission form, maintainer email, legacy feedback form), not clearly to this fork workflow. | Fork contribution flow is defined in repo runbook (`AGENTS.md:439-448`) with PR/gate expectations in this repository. | Align this page to fork contribution channels (GitHub issues/PRs for this repo) or mark page as upstream-only. **Needs maintainer input**. |

## Proposed text edits (copy-ready)

### 1) Update guide safety (already applied)

File: `docs/update-guide.md`

```md
###### Step 3: Remove unnecessary files 

No folder removals are required in this repository.

- Keep `/system` in place (the runtime bootstrap in `index.php` depends on it).
- Keep Composer autoload files managed via `composer install`.
```

### 2) REST API invariant note (already applied)

File: `docs/rest-api.md`

```md
* The `availabilitiesType` must be either `flexible` or `fixed`.
* In this fork, `attendantsNumber` must be `1`; higher values are rejected by validation.
```

### 3) Get involved fork alignment (proposed, not applied)

File: `docs/get-involved.md`

```md
### Suggestions

For this fork, open feature requests and bug reports in the repository issue tracker:
`https://github.com/robinbeier/forscherhaus-appointments/issues`.

### Translation

Submit translation updates via pull request in this repository.

### User Feedback

Use GitHub Discussions/Issues in this repository for operational feedback specific to the Forscherhaus fork.
```

## Open questions needing maintainer input

1. Should `docs/get-involved.md` be fork-specific, or intentionally remain upstream-branded as historical/reference content?
2. If fork-specific: should we link to GitHub Issues only, or also introduce Discussions as the preferred feedback channel?

## Next-week watchlist

1. Re-validate contributor/onboarding pages (`docs/get-involved.md`, `docs/readme.md`, root `README.md`) for consistent fork branding and support paths.
2. Spot-check `docs/update-guide.md` version sections for historical duplication/legacy instructions that may no longer map cleanly to current branch strategy.
3. Re-check API docs vs. fork invariants (`services.attendants_number=1`, booking conflict semantics) when API write-contract checks evolve.
