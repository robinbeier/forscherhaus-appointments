# Doc Drift Review - 2026-02-23

## Executive Summary
Low-to-moderate drift risk. Two FAQ snippets conflicted with current routing/base URL behavior and could mislead setup (P1). Console docs missed migrate step commands (P2). All high-impact setup docs otherwise aligned with code/config defaults.

## Findings Table
| Severity | Doc Path | Stale Claim | Implementation Evidence | Recommended Fix |
| --- | --- | --- | --- | --- |
| P1 | `docs/faq.md#L10` | OAuth redirect example ends with `/google/callback` instead of `/google/oauth_callback`. | `application/controllers/Google.php#L300-L305` documents `/google/oauth_callback` as the redirect path. | Update the example redirect URL to use `/google/oauth_callback`. |
| P1 | `docs/faq.md#L15` | BASE_URL requires a trailing slash. | `application/config/config.php#L33` trims trailing slashes from base_url. | State BASE_URL should be set without a trailing slash and update example URLs. |
| P2 | `docs/console.md#L37-L47` | Migrate up/down commands are missing from docs. | `application/controllers/Console.php#L179-L182` advertises `migrate up` and `migrate down`. | Add `php index.php console migrate up` and `... migrate down` with short descriptions. |

## Proposed Text Edits (Copy-Ready)

### `docs/faq.md`
- Replace the OAuth redirect example sentence with:
  - `For example if E!A is installed on the "ea" folder on the web root directory the valid redirect url would be "http://my-domain/ea/google/oauth_callback".`
- Replace the BASE_URL guidance sentence with:
  - `... set the BASE_URL constant to "https://url/to/easyappointments/folder" (no trailing slash), for example "http://easyappointments.org/ea-installation".`

### `docs/console.md`
- Insert after the `migrate fresh` section:
  - `php index.php console migrate up` (apply next migration step)
  - `php index.php console migrate down` (roll back the latest migration step)

## Open Questions Needing Maintainer Input
- None.

## Next-Week Watchlist
- If fork-specific branding/links are desired, review README upstream links and badges for possible updates.
