# Documentation Drift Review (2026-02-23)

## Executive Summary
Low-to-moderate drift risk. No critical issues found. Two doc updates were required to align installation/FAQ guidance with current config and PHP extension requirements.

## Findings Table
| Severity | Doc path | Stale claim | Implementation evidence | Recommended fix |
| --- | --- | --- | --- | --- |
| P1 | docs/installation-guide.md:11 | Installation prerequisites omit required PHP extensions (only mentions `php_curl`). | composer.json:31-37 requires `ext-curl`, `ext-json`, `ext-mbstring`, `ext-gd`, `ext-simplexml`, `ext-fileinfo`. | Add the required PHP extensions list to step 1 (done). |
| P1 | docs/faq.md:15,39 | FAQ references `configuration.php` and `$base_url` parameter. | config-sample.php:16-25,33 documents `config.php` with `BASE_URL` constant; CHANGELOG.md:401 notes rename from `configuration.php`. | Update FAQ to `config.php` and `BASE_URL` constant (done). |

## Proposed Text Edits (Copy-Ready)
- docs/installation-guide.md:11
  - **Replace** sentence ending with “...php_curl extension installed and enabled as well.”
  - **With:** “PHP should have the `curl`, `json`, `mbstring`, `gd`, `simplexml`, and `fileinfo` extensions enabled (the `curl` extension is required for Google Calendar synchronization).”
- docs/faq.md:15
  - **Replace** “configuration.php” with “config.php” and `$base_url` with `BASE_URL`.
- docs/faq.md:39
  - **Replace** “configuration.php” with “config.php” and `$base_url` with `BASE_URL`.

## Open Questions Needing Maintainer Input
- None for this run.

## Next-Week Watchlist
- Re-verify PHP extension requirements if composer.json constraints change.
- If the fork plans to diverge branding/URLs, decide whether README.md should be updated to repo-specific links.
