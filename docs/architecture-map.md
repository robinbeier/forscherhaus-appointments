# Architecture Map

Canonical source: `docs/maps/component_ownership_map.json`

Every path rule declares `match: directory`, `match: exact_file`, or `match: filename_prefix`; path spelling has no implicit match semantics.

This map defines component boundaries, path ownership scope, and dependency edges.

## Component Overview

| Component | Role | Depends On | Path Rules | Key Files |
|---|---|---|---:|---:|
| `auth-session` | Access & Session | integrations-sync, people-services-admin, scheduling-backoffice, shared-core | 13 | 3 |
| `installation-bootstrap` | Installation & Bootstrap | people-services-admin, settings-compliance, shared-core | 4 | 3 |
| `booking-public` | Public Booking | integrations-sync, people-services-admin, scheduling-backoffice, settings-compliance, shared-core | 10 | 7 |
| `booking-lifecycle` | Booking Confirmation/Cancellation | booking-public, integrations-sync, people-services-admin, scheduling-backoffice, shared-core | 5 | 3 |
| `scheduling-backoffice` | Calendar & Scheduling | integrations-sync, people-services-admin, settings-compliance, shared-core | 18 | 3 |
| `dashboard-exports` | Dashboard & Exports | scheduling-backoffice, people-services-admin, shared-core | 11 | 3 |
| `people-services-admin` | People, Providers, Services | integrations-sync, scheduling-backoffice, settings-compliance, shared-core | 24 | 3 |
| `settings-compliance` | Settings & Compliance | auth-session, integrations-sync, people-services-admin, scheduling-backoffice | 25 | 3 |
| `integrations-sync` | Integrations & Sync | auth-session, people-services-admin, scheduling-backoffice, settings-compliance, shared-core | 17 | 3 |
| `api-v1` | REST API v1 | auth-session, integrations-sync, people-services-admin, scheduling-backoffice, settings-compliance, shared-core | 5 | 3 |
| `shared-core` | Shared Core | None | 7 | 3 |
| `platform-quality-tooling` | Platform, CI, Release Gates | api-v1, booking-public, dashboard-exports, installation-bootstrap, people-services-admin, settings-compliance, shared-core | 27 | 16 |

## Component Details

### `auth-session` - Access & Session

Authentication, recovery and account-session flows for backend users.

Dependencies:
- `integrations-sync`
- `people-services-admin`
- `scheduling-backoffice`
- `shared-core`

Path rules:
- `application/controllers/Login.php` (exact_file)
- `application/controllers/Logout.php` (exact_file)
- `application/controllers/Recovery.php` (exact_file)
- `application/controllers/Account.php` (exact_file)
- `application/controllers/Localization.php` (exact_file)
- `application/libraries/Auth_request_dto_factory.php` (exact_file)
- `application/views/pages/login.php` (exact_file)
- `application/views/pages/logout.php` (exact_file)
- `application/views/pages/recovery.php` (exact_file)
- `application/views/pages/account.php` (exact_file)
- `assets/js/pages/login.js` (exact_file)
- `assets/js/pages/recovery.js` (exact_file)
- `assets/js/pages/account.js` (exact_file)

Key files:
- `application/controllers/Login.php`
- `application/controllers/Recovery.php`
- `assets/js/pages/login.js`

### `installation-bootstrap` - Installation & Bootstrap

First-run installation flow that seeds the initial admin, company, and demo booking data.

Dependencies:
- `people-services-admin`
- `settings-compliance`
- `shared-core`

Path rules:
- `application/controllers/Installation.php` (exact_file)
- `application/libraries/Instance.php` (exact_file)
- `application/views/pages/installation.php` (exact_file)
- `assets/js/pages/installation.js` (exact_file)

Key files:
- `application/controllers/Installation.php`
- `application/views/pages/installation.php`
- `assets/js/pages/installation.js`

### `booking-public` - Public Booking

Public booking wizard read/write path, including server-side reschedule authority, up to booking completion handoff.

Dependencies:
- `integrations-sync`
- `people-services-admin`
- `scheduling-backoffice`
- `settings-compliance`
- `shared-core`

Path rules:
- `application/controllers/Booking.php` (exact_file)
- `application/views/pages/booking.php` (exact_file)
- `application/views/components/booking_` (filename_prefix)
- `assets/js/http/booking_http_client.js` (exact_file)
- `assets/js/pages/booking.js` (exact_file)
- `assets/js/pages/booking_webmcp.js` (exact_file)
- `application/libraries/Availability.php` (exact_file)
- `application/libraries/Email_messages.php` (exact_file)
- `application/libraries/Booking_request_dto_factory.php` (exact_file)
- `application/libraries/Reschedule_authority.php` (exact_file)

Key files:
- `application/controllers/Booking.php`
- `application/views/pages/booking.php`
- `assets/js/http/booking_http_client.js`
- `application/libraries/Email_messages.php`
- `application/libraries/Reschedule_authority.php`
- `assets/js/pages/booking.js`
- `assets/js/pages/booking_webmcp.js`

### `booking-lifecycle` - Booking Confirmation/Cancellation

Post-booking confirmation and cancellation customer flows.

Dependencies:
- `booking-public`
- `integrations-sync`
- `people-services-admin`
- `scheduling-backoffice`
- `shared-core`

Path rules:
- `application/controllers/Booking_confirmation.php` (exact_file)
- `application/controllers/Booking_cancellation.php` (exact_file)
- `application/views/pages/booking_confirmation.php` (exact_file)
- `application/views/pages/booking_cancellation.php` (exact_file)
- `application/views/pages/booking_message.php` (exact_file)

Key files:
- `application/controllers/Booking_confirmation.php`
- `application/controllers/Booking_cancellation.php`
- `application/views/pages/booking_confirmation.php`

### `scheduling-backoffice` - Calendar & Scheduling

Backoffice scheduling operations, calendar interactions and appointment orchestration.

Dependencies:
- `integrations-sync`
- `people-services-admin`
- `settings-compliance`
- `shared-core`

Path rules:
- `application/controllers/Calendar.php` (exact_file)
- `application/controllers/Appointments.php` (exact_file)
- `application/controllers/Blocked_periods.php` (exact_file)
- `application/controllers/Unavailabilities.php` (exact_file)
- `application/controllers/Backend.php` (exact_file)
- `application/controllers/Backend_api.php` (exact_file)
- `application/libraries/Booking_slot_analytics.php` (exact_file)
- `application/libraries/Backoffice_request_dto_factory.php` (exact_file)
- `application/libraries/Calendar_request_dto_factory.php` (exact_file)
- `application/models/Appointments_model.php` (exact_file)
- `application/models/Blocked_periods_model.php` (exact_file)
- `application/models/Unavailabilities_model.php` (exact_file)
- `application/views/pages/calendar.php` (exact_file)
- `application/views/pages/blocked_periods.php` (exact_file)
- `assets/js/pages/calendar.js` (exact_file)
- `assets/js/pages/blocked_periods.js` (exact_file)
- `assets/js/components/appointments_modal.js` (exact_file)
- `assets/js/components/unavailabilities_modal.js` (exact_file)

Key files:
- `application/controllers/Calendar.php`
- `application/models/Appointments_model.php`
- `assets/js/pages/calendar.js`

### `dashboard-exports` - Dashboard & Exports

Operational dashboards, metrics aggregation and export/report output paths.

Dependencies:
- `scheduling-backoffice`
- `people-services-admin`
- `shared-core`

Path rules:
- `application/controllers/Dashboard.php` (exact_file)
- `application/controllers/Dashboard_export.php` (exact_file)
- `application/controllers/Healthz.php` (exact_file)
- `application/libraries/Dashboard_metrics.php` (exact_file)
- `application/libraries/Dashboard_heatmap.php` (exact_file)
- `application/libraries/Provider_utilization.php` (exact_file)
- `application/libraries/Dashboard_request_dto_factory.php` (exact_file)
- `application/views/pages/dashboard.php` (exact_file)
- `application/views/pages/dashboard_teacher.php` (exact_file)
- `assets/js/pages/dashboard.js` (exact_file)
- `assets/js/pages/dashboard_teacher.js` (exact_file)

Key files:
- `application/controllers/Dashboard.php`
- `application/libraries/Dashboard_metrics.php`
- `assets/js/pages/dashboard.js`

### `people-services-admin` - People, Providers, Services

Admin CRUD surfaces for providers, customers, services and service categories.

Dependencies:
- `integrations-sync`
- `scheduling-backoffice`
- `settings-compliance`
- `shared-core`

Path rules:
- `application/controllers/Providers.php` (exact_file)
- `application/controllers/Customers.php` (exact_file)
- `application/controllers/Admins.php` (exact_file)
- `application/controllers/Secretaries.php` (exact_file)
- `application/controllers/Services.php` (exact_file)
- `application/controllers/Service_categories.php` (exact_file)
- `application/models/Providers_model.php` (exact_file)
- `application/models/Customers_model.php` (exact_file)
- `application/models/Admins_model.php` (exact_file)
- `application/models/Secretaries_model.php` (exact_file)
- `application/models/Services_model.php` (exact_file)
- `application/models/Service_categories_model.php` (exact_file)
- `application/views/pages/providers.php` (exact_file)
- `application/views/pages/customers.php` (exact_file)
- `application/views/pages/admins.php` (exact_file)
- `application/views/pages/secretaries.php` (exact_file)
- `application/views/pages/services.php` (exact_file)
- `application/views/pages/service_categories.php` (exact_file)
- `assets/js/pages/providers.js` (exact_file)
- `assets/js/pages/customers.js` (exact_file)
- `assets/js/pages/admins.js` (exact_file)
- `assets/js/pages/secretaries.js` (exact_file)
- `assets/js/pages/services.js` (exact_file)
- `assets/js/pages/service_categories.js` (exact_file)

Key files:
- `application/controllers/Providers.php`
- `application/models/Services_model.php`
- `assets/js/pages/services.js`

### `settings-compliance` - Settings & Compliance

Business, legal, analytics and API settings including privacy/consent controls.

Dependencies:
- `auth-session`
- `integrations-sync`
- `people-services-admin`
- `scheduling-backoffice`

Path rules:
- `application/controllers/Api_settings.php` (exact_file)
- `application/controllers/Booking_settings.php` (exact_file)
- `application/controllers/Business_settings.php` (exact_file)
- `application/controllers/General_settings.php` (exact_file)
- `application/controllers/Google_analytics_settings.php` (exact_file)
- `application/controllers/Matomo_analytics_settings.php` (exact_file)
- `application/controllers/Legal_settings.php` (exact_file)
- `application/controllers/Consents.php` (exact_file)
- `application/controllers/Privacy.php` (exact_file)
- `application/models/Settings_model.php` (exact_file)
- `application/models/Consents_model.php` (exact_file)
- `application/views/pages/api_settings.php` (exact_file)
- `application/views/pages/booking_settings.php` (exact_file)
- `application/views/pages/business_settings.php` (exact_file)
- `application/views/pages/general_settings.php` (exact_file)
- `application/views/pages/google_analytics_settings.php` (exact_file)
- `application/views/pages/matomo_analytics_settings.php` (exact_file)
- `application/views/pages/legal_settings.php` (exact_file)
- `assets/js/pages/api_settings.js` (exact_file)
- `assets/js/pages/booking_settings.js` (exact_file)
- `assets/js/pages/business_settings.js` (exact_file)
- `assets/js/pages/general_settings.js` (exact_file)
- `assets/js/pages/google_analytics_settings.js` (exact_file)
- `assets/js/pages/matomo_analytics_settings.js` (exact_file)
- `assets/js/pages/legal_settings.js` (exact_file)

Key files:
- `application/controllers/Business_settings.php`
- `application/controllers/Legal_settings.php`
- `assets/js/pages/business_settings.js`

### `integrations-sync` - Integrations & Sync

External sync and integration adapters (Google, CalDAV, LDAP, webhooks).

Dependencies:
- `auth-session`
- `people-services-admin`
- `scheduling-backoffice`
- `settings-compliance`
- `shared-core`

Path rules:
- `application/controllers/Google.php` (exact_file)
- `application/controllers/Caldav.php` (exact_file)
- `application/controllers/Webhooks.php` (exact_file)
- `application/controllers/Integrations.php` (exact_file)
- `application/controllers/Ldap_settings.php` (exact_file)
- `application/libraries/Google_sync.php` (exact_file)
- `application/libraries/Caldav_sync.php` (exact_file)
- `application/libraries/Synchronization.php` (exact_file)
- `application/libraries/Webhooks_client.php` (exact_file)
- `application/libraries/Ldap_client.php` (exact_file)
- `application/libraries/Integrations_request_dto_factory.php` (exact_file)
- `application/models/Webhooks_model.php` (exact_file)
- `application/views/pages/integrations.php` (exact_file)
- `application/views/pages/webhooks.php` (exact_file)
- `application/views/pages/ldap_settings.php` (exact_file)
- `assets/js/pages/webhooks.js` (exact_file)
- `assets/js/pages/ldap_settings.js` (exact_file)

Key files:
- `application/controllers/Integrations.php`
- `application/libraries/Synchronization.php`
- `application/libraries/Webhooks_client.php`

### `api-v1` - REST API v1

External API surface for appointment-domain entities with auth and schema ties.

Dependencies:
- `auth-session`
- `integrations-sync`
- `people-services-admin`
- `scheduling-backoffice`
- `settings-compliance`
- `shared-core`

Path rules:
- `application/controllers/api/v1` (directory)
- `application/libraries/Api.php` (exact_file)
- `application/libraries/Api_request_dto_factory.php` (exact_file)
- `openapi.yml` (exact_file)
- `docs/rest-api.md` (exact_file)

Key files:
- `application/controllers/api/v1/Appointments_api_v1.php`
- `application/controllers/api/v1/Availabilities_api_v1.php`
- `openapi.yml`

### `shared-core` - Shared Core

Cross-cutting reusable libraries/models consumed by multiple business components.

Dependencies:
- None

Path rules:
- `application/views/components/jquery_compat_inline.php` (exact_file)
- `application/libraries/Accounts.php` (exact_file)
- `application/libraries/Notifications.php` (exact_file)
- `application/libraries/Pdf_renderer.php` (exact_file)
- `application/libraries/Request_normalizer.php` (exact_file)
- `application/libraries/Timezones.php` (exact_file)
- `application/models/Roles_model.php` (exact_file)

Key files:
- `application/libraries/Request_normalizer.php`
- `application/libraries/Accounts.php`
- `application/libraries/Notifications.php`

### `platform-quality-tooling` - Platform, CI, Release Gates

CI workflows, smoke/release gates, and quality automation scripts.

Dependencies:
- `api-v1`
- `booking-public`
- `dashboard-exports`
- `installation-bootstrap`
- `people-services-admin`
- `settings-compliance`
- `shared-core`

Path rules:
- `application/controllers/Console.php` (exact_file)
- `application/core/Customers_ui_smoke_access_policy.php` (exact_file)
- `application/core/Provider_ui_smoke_access_policy.php` (exact_file)
- `application/libraries/Customers_ui_smoke_fixture.php` (exact_file)
- `application/libraries/Provider_ui_smoke_fixture.php` (exact_file)
- `deploy_ea.sh` (exact_file)
- `scripts/ci` (directory)
- `scripts/ops/customers_ui_smoke_principals.sh` (exact_file)
- `scripts/ops/config/traffic_gate_catalog.v1.json` (exact_file)
- `scripts/ops/lib/DeployResultV1.php` (exact_file)
- `scripts/ops/lib/DeploymentContractV1.php` (exact_file)
- `scripts/ops/lib/DeploymentHostRunnerContractV1.php` (exact_file)
- `scripts/ops/lib/TrafficGateV1.php` (exact_file)
- `scripts/ops/prod_customers_ui_smoke.sh` (exact_file)
- `scripts/ops/libexec/zero_surprise_image_cleanup_v1.py` (exact_file)
- `scripts/ops/prod_traffic_gate.sh` (exact_file)
- `scripts/ops/prod_provider_ui_smoke.sh` (exact_file)
- `scripts/ops/prod_zero_surprise_image_cleanup.sh` (exact_file)
- `scripts/ops/provider_ui_smoke_principal.sh` (exact_file)
- `scripts/ops/traffic_gate_v1.php` (exact_file)
- `scripts/ops/validate_deployment_contract_v1.php` (exact_file)
- `scripts/release-gate` (directory)
- `.github/workflows/ci.yml` (exact_file)
- `docs/release-gate-dashboard.md` (exact_file)
- `docs/release-gate-booking-confirmation-pdf.md` (exact_file)
- `docs/release-gate-customers-ui-smoke.md` (exact_file)
- `docs/release-gate-provider-ui-smoke.md` (exact_file)

Key files:
- `application/controllers/Console.php`
- `application/libraries/Customers_ui_smoke_fixture.php`
- `application/libraries/Provider_ui_smoke_fixture.php`
- `.github/workflows/ci.yml`
- `deploy_ea.sh`
- `scripts/ci/dashboard_integration_smoke.php`
- `scripts/ops/lib/DeployResultV1.php`
- `scripts/ops/lib/TrafficGateV1.php`
- `scripts/ops/lib/DeploymentContractV1.php`
- `scripts/ops/lib/DeploymentHostRunnerContractV1.php`
- `scripts/ops/prod_traffic_gate.sh`
- `scripts/ops/prod_zero_surprise_image_cleanup.sh`
- `scripts/ops/validate_deployment_contract_v1.php`
- `scripts/release-gate/dashboard_release_gate.php`
- `scripts/release-gate/customers_ui_smoke.php`
- `scripts/release-gate/provider_ui_smoke.php`
