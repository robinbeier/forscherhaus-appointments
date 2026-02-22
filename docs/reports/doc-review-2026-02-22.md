# Doc Drift Review (2026-02-22)

## Executive summary (overall drift risk)
Moderate drift found in installation requirements and API reference links. One installation requirement would fail on fresh setups (PHP minimum version). Other issues are minor but widespread (version footers) and could mislead about the current release.

## Findings table
| severity | doc path | stale claim | implementation evidence | recommended fix |
| --- | --- | --- | --- | --- |
| P0 | docs/installation-guide.md:11 | Lists PHP(v7.2+) as minimum requirement. | composer.json:31 requires `php >=8.1`. | Update minimum to PHP(v8.1+). (Fixed in patch) |
| P1 | README.md:85 | States PHP (8.2+) as required minimum. | composer.json:31 requires `php >=8.1`. | Relax minimum to PHP 8.1+ and note 8.2+ is recommended. (Fixed in patch) |
| P1 | docs/rest-api.md:9 | OpenAPI download points to upstream repo URL. | docker-compose.yml:46-54 mounts local `./openapi.yml` for swagger-ui. | Link to local `../openapi.yml`. (Fixed in patch) |
| P2 | docs/readme.md:15; docs/docker.md:121; docs/console.md:92; docs/caldav-calendar-sync.md:48; docs/ldap.md:97; docs/google-calendar-sync.md:50; docs/installation-guide.md:32; docs/faq.md:57; docs/manage-translations.md:17; docs/get-involved.md:23; docs/update-guide.md:134; docs/rest-api.md:501 | Footer says docs apply to v1.5.1. | composer.json:4 and application/config/app.php:12 show version 1.5.2. | Bump all footers to v1.5.2. (Fixed in patch) |
| P2 | docs/console.md:65 | Backup example path uses `folter` typo. | N/A (typo in documentation example). | Fix to `folder`. (Fixed in patch) |

## Proposed text edits (copy-ready)
- README.md: Replace “PHP (8.2+)” with “PHP (8.1+, 8.2+ recommended)”.
- docs/installation-guide.md: Replace “PHP(v7.2+)” with “PHP(v8.1+)”.
- docs/rest-api.md: Replace upstream OpenAPI URL with `../openapi.yml`.
- docs/console.md: Replace “/path/to/backup/folter” with “/path/to/backup/folder”.
- docs/*: Replace footer “v1.5.1” with “v1.5.2” in the listed files.

## Open questions needing maintainer input
- None for this run. MySQL 5.7 compatibility was re-validated on 2026-02-22 by running `php index.php console migrate fresh` in Docker against `mysql:5.7` (amd64 emulation), followed by `composer test` in the same stack.

## Next-week watchlist
- Bump documentation footers on each release when application/config/app.php version changes.
- Confirm PHP minimum in docs when composer.json constraints are updated.
- Keep REST API docs aligned with `openapi.yml` in repo root.
