# Documentation Drift Review - 2026-03-16

## Executive summary (overall drift risk)

Overall drift risk: **Moderate**.

High-impact setup docs had three concrete version drifts that could cause failed local setup (PHP minimum mismatch) or wasted triage time (Docker MySQL version mismatch). Those high-confidence items were fixed in this run. The remaining FAQ item has now been resolved by policy: `docs/faq.md` is not fork-authoritative for setup requirements and now redirects readers to `README.md` and `docs/installation-guide.md` for canonical runtime/setup truth.

## Findings table

| Severity | Doc path | Stale claim | Implementation evidence | Recommended fix |
| --- | --- | --- | --- | --- |
| P0 | `README.md` (pre-fix in this run at line 10) | Stack listed `PHP >=8.1` with `8.2+ recommended`. | `composer.json:28` requires `"php": ">=8.3.6"`. | Updated README stack requirement to `PHP >=8.3.6` (fixed in this run). |
| P0 | `docs/installation-guide.md` (pre-fix in this run at line 11) | Installation prerequisites listed `PHP(v8.1+)`. | `composer.json:28` requires `"php": ">=8.3.6"`. | Updated installation guide prerequisite to `PHP(v8.3.6+)` (fixed in this run). |
| P1 | `docs/docker.md` (pre-fix in this run at line 52) | Docker guide claimed the dev stack pins MySQL `8.0`. | `docker-compose.yml:24` uses `image: 'mysql:8.4.8'`. | Updated Docker guide text to MySQL `8.4.8` (fixed in this run). |
| P2 | `docs/faq.md:1` | FAQ previously repeated setup prerequisites in a way that could drift from current fork runtime requirements. | `composer.json:29-34` requires `curl`, `json`, `mbstring`, `gd`, `simplexml`, `fileinfo`; `scripts/setup-worktree.sh:17-22` requires `php`, `composer`, `node`, `npm`, `npx`, and Node `20.19.0+`. | Resolved in follow-up: make FAQ non-authoritative for setup and point to `README.md` + `docs/installation-guide.md` for canonical requirements. |

## Proposed text edits (copy-ready)

1. `README.md` stack line

```md
- Stack: PHP `>=8.3.6`, CodeIgniter, MySQL, jQuery/Bootstrap/FullCalendar
```

2. `README.md` quickstart prerequisites block

```md
Prerequisites on host (required by `./scripts/setup-worktree.sh`):

- PHP `>=8.3.6`
- Composer
- Node.js `>=20.19.0` plus `npm`/`npx`
- Docker + Docker Compose
```

3. `docs/installation-guide.md` prerequisite sentence

```md
1. **Make sure that your server has at least the following applications/tools installed: Apache(v2.4), PHP(v8.3.6+) and MySQL(v5.7+).**
```

4. `docs/docker.md` MySQL pin sentence

```md
The development stack pins MySQL `8.4.8` in `docker-compose.yml` for CI parity, while application migrations remain compatible with MySQL `5.7+`.
```

5. Follow-up applied to `docs/faq.md`

```md
This FAQ keeps common troubleshooting answers and older upstream guidance in one place. For fork-authoritative setup and runtime requirements, use `README.md` and `docs/installation-guide.md` as the canonical sources.
```

## Open questions needing maintainer input

- Should documentation version footers remain at `v1.5.2` lineage wording, or be updated to include fork patch version semantics (`1.5.2.1`)?

## Next-week watchlist

- Recheck runtime-version drift whenever these files change together: `composer.json`, `docker-compose.yml`, `scripts/setup-worktree.sh`, and top-level setup docs.
- Verify CI/runtime version references after dependency bumps (`.github/workflows/ci.yml` currently uses PHP `8.4.1` and Node `20.19.0`).
- Audit `docs/faq.md` and `docs/get-involved.md` for remaining upstream wording that can misroute fork contributors.
