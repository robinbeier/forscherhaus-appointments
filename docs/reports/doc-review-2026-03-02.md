# Doc Drift Review - 2026-03-02

## Executive Summary
Moderate drift risk this week. Three P1 issues in high-impact docs could cause setup/runtime confusion (Google OAuth callback URL defaults, Google sync trigger behavior, and flaky local CI smoke repro steps). All three were fixed with doc-only edits in this run. One P2 discoverability gap (missing links to shipped operational docs) was also fixed.

## Findings Table
| Severity | Doc Path | Stale Claim | Implementation Evidence | Recommended Fix |
| --- | --- | --- | --- | --- |
| P1 | `docs/faq.md#L10` | Google OAuth redirect examples omitted `index.php`, which can break default installations. | `application/config/config.php#L45` sets `index_page` to `index.php` by default; `application/libraries/Google_sync.php#L73` builds redirect URI via `site_url('google/oauth_callback')`. | Document `.../index.php/google/oauth_callback` as default and note rewrite-mode alternative. (Fixed) |
| P1 | `docs/google-calendar-sync.md#L38` | Claimed sync can only be triggered from backend or appointment changes. | `application/controllers/Console.php#L129-L137` documents cron/CLI sync via `php index.php console sync`. | Update note to include CLI/cron trigger path. (Fixed) |
| P1 | `docs/release-gate-dashboard.md#L88-L92` | Local smoke repro used a single DB readiness check and single `console install` attempt, causing avoidable local failures. | Repo runbook uses stricter sequence with app-user DB readiness + install retries: `AGENTS.md#L76-L84`. | Align repro block with robust readiness + retry sequence. (Fixed) |
| P2 | `docs/readme.md#L5-L17` | Docs index omitted links to shipped operational docs (LDAP, provider room, release gates). | Docs exist at `docs/ldap.md`, `docs/feature-provider-room.md`, `docs/release-gate-dashboard.md`, `docs/release-gate-booking-confirmation-pdf.md`; scripts are wired in `composer.json#L55-L56`. | Add links in docs index for discoverability. (Fixed) |

## Proposed Text Edits (Copy-Ready)

### `docs/faq.md`
- Replace OAuth redirect examples with:
  - `http://domain-name/folder-to-ea-installation/index.php/google/oauth_callback`
  - `http://my-domain/ea/index.php/google/oauth_callback`
- Add rewrite note:
  - `if URL rewriting removes index.php, use the rewritten path instead`.

### `docs/google-calendar-sync.md`
- Replace sync-trigger note with:
  - `Synchronization can be triggered from the Easy!Appointments backend, automatically when appointment data changes, or manually from CLI (php index.php console sync, e.g. via cron).`

### `docs/release-gate-dashboard.md`
- Update local repro block to include:
  - app-user DB readiness check: `mysql -uuser -ppassword -e "USE easyappointments; SELECT 1;"`
  - `console install` retry loop (`for attempt in 1 2 3 ...`).

### `docs/readme.md`
- Add links:
  - `LDAP`
  - `Provider Room Feature`
  - `Dashboard Release Gate`
  - `Booking Confirmation PDF Gate`

## Open Questions Needing Maintainer Input
- Should the top-level `README.md` setup section stay upstream-branded (`alextselegidis/easyappointments`) or be fork-specific for contributors to this repository? This affects onboarding consistency but is not changed in this patch.

## Next-Week Watchlist
- Re-check Google OAuth callback examples across all docs after any routing/index-page default changes.
- Keep release-gate docs aligned with CI/AGENTS smoke command hardening.
- Review README branding/remote references for fork onboarding clarity.
