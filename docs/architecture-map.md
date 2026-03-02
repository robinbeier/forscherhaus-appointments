# Architecture Map

Canonical source: `docs/maps/component_ownership_map.json`

This map defines component boundaries, path ownership scope, and dependency edges.

## Component Overview

| Component | Role | Depends On | Path Prefixes | Key Files |
|---|---|---|---:|---:|
| `auth-session` | Access & Session | None | 11 | 3 |
| `booking-public` | Public Booking | settings-compliance | 5 | 3 |
| `booking-lifecycle` | Booking Confirmation/Cancellation | booking-public | 5 | 3 |
| `scheduling-backoffice` | Calendar & Scheduling | people-services-admin, settings-compliance | 15 | 3 |
| `dashboard-exports` | Dashboard & Exports | scheduling-backoffice, people-services-admin | 10 | 3 |
| `people-services-admin` | People, Providers, Services | settings-compliance | 24 | 3 |
| `settings-compliance` | Settings & Compliance | auth-session | 23 | 3 |
| `integrations-sync` | Integrations & Sync | auth-session, people-services-admin, settings-compliance | 16 | 3 |
| `api-v1` | REST API v1 | auth-session, people-services-admin, scheduling-backoffice, settings-compliance | 3 | 3 |
| `platform-quality-tooling` | Platform, CI, Release Gates | dashboard-exports, booking-public, api-v1 | 5 | 3 |

## Component Details

### `auth-session` - Access & Session

Authentication, recovery and account-session flows for backend users.

Dependencies:
- None

Path prefixes:
- `application/controllers/Login.php`
- `application/controllers/Logout.php`
- `application/controllers/Recovery.php`
- `application/controllers/Account.php`
- `application/views/pages/login.php`
- `application/views/pages/logout.php`
- `application/views/pages/recovery.php`
- `application/views/pages/account.php`
- `assets/js/pages/login.js`
- `assets/js/pages/recovery.js`
- `assets/js/pages/account.js`

Key files:
- `application/controllers/Login.php`
- `application/controllers/Recovery.php`
- `assets/js/pages/login.js`

### `booking-public` - Public Booking

Public booking wizard read/write path up to booking completion handoff.

Dependencies:
- `settings-compliance`

Path prefixes:
- `application/controllers/Booking.php`
- `application/views/pages/booking.php`
- `application/views/components/booking_`
- `assets/js/pages/booking.js`
- `application/libraries/Availability.php`

Key files:
- `application/controllers/Booking.php`
- `application/views/pages/booking.php`
- `assets/js/pages/booking.js`

### `booking-lifecycle` - Booking Confirmation/Cancellation

Post-booking confirmation and cancellation customer flows.

Dependencies:
- `booking-public`

Path prefixes:
- `application/controllers/Booking_confirmation.php`
- `application/controllers/Booking_cancellation.php`
- `application/views/pages/booking_confirmation.php`
- `application/views/pages/booking_cancellation.php`
- `application/views/pages/booking_message.php`

Key files:
- `application/controllers/Booking_confirmation.php`
- `application/controllers/Booking_cancellation.php`
- `application/views/pages/booking_confirmation.php`

### `scheduling-backoffice` - Calendar & Scheduling

Backoffice scheduling operations, calendar interactions and appointment orchestration.

Dependencies:
- `people-services-admin`
- `settings-compliance`

Path prefixes:
- `application/controllers/Calendar.php`
- `application/controllers/Appointments.php`
- `application/controllers/Blocked_periods.php`
- `application/controllers/Unavailabilities.php`
- `application/controllers/Backend.php`
- `application/controllers/Backend_api.php`
- `application/models/Appointments_model.php`
- `application/models/Blocked_periods_model.php`
- `application/models/Unavailabilities_model.php`
- `application/views/pages/calendar.php`
- `application/views/pages/blocked_periods.php`
- `assets/js/pages/calendar.js`
- `assets/js/pages/blocked_periods.js`
- `assets/js/components/appointments_modal.js`
- `assets/js/components/unavailabilities_modal.js`

Key files:
- `application/controllers/Calendar.php`
- `application/models/Appointments_model.php`
- `assets/js/pages/calendar.js`

### `dashboard-exports` - Dashboard & Exports

Operational dashboards, metrics aggregation and export/report output paths.

Dependencies:
- `scheduling-backoffice`
- `people-services-admin`

Path prefixes:
- `application/controllers/Dashboard.php`
- `application/controllers/Dashboard_export.php`
- `application/controllers/Healthz.php`
- `application/libraries/Dashboard_metrics.php`
- `application/libraries/Dashboard_heatmap.php`
- `application/libraries/Provider_utilization.php`
- `application/views/pages/dashboard.php`
- `application/views/pages/dashboard_teacher.php`
- `assets/js/pages/dashboard.js`
- `assets/js/pages/dashboard_teacher.js`

Key files:
- `application/controllers/Dashboard.php`
- `application/libraries/Dashboard_metrics.php`
- `assets/js/pages/dashboard.js`

### `people-services-admin` - People, Providers, Services

Admin CRUD surfaces for providers, customers, services and service categories.

Dependencies:
- `settings-compliance`

Path prefixes:
- `application/controllers/Providers.php`
- `application/controllers/Customers.php`
- `application/controllers/Admins.php`
- `application/controllers/Secretaries.php`
- `application/controllers/Services.php`
- `application/controllers/Service_categories.php`
- `application/models/Providers_model.php`
- `application/models/Customers_model.php`
- `application/models/Admins_model.php`
- `application/models/Secretaries_model.php`
- `application/models/Services_model.php`
- `application/models/Service_categories_model.php`
- `application/views/pages/providers.php`
- `application/views/pages/customers.php`
- `application/views/pages/admins.php`
- `application/views/pages/secretaries.php`
- `application/views/pages/services.php`
- `application/views/pages/service_categories.php`
- `assets/js/pages/providers.js`
- `assets/js/pages/customers.js`
- `assets/js/pages/admins.js`
- `assets/js/pages/secretaries.js`
- `assets/js/pages/services.js`
- `assets/js/pages/service_categories.js`

Key files:
- `application/controllers/Providers.php`
- `application/models/Services_model.php`
- `assets/js/pages/services.js`

### `settings-compliance` - Settings & Compliance

Business, legal, analytics and API settings including privacy/consent controls.

Dependencies:
- `auth-session`

Path prefixes:
- `application/controllers/Api_settings.php`
- `application/controllers/Booking_settings.php`
- `application/controllers/Business_settings.php`
- `application/controllers/General_settings.php`
- `application/controllers/Google_analytics_settings.php`
- `application/controllers/Matomo_analytics_settings.php`
- `application/controllers/Legal_settings.php`
- `application/controllers/Consents.php`
- `application/controllers/Privacy.php`
- `application/views/pages/api_settings.php`
- `application/views/pages/booking_settings.php`
- `application/views/pages/business_settings.php`
- `application/views/pages/general_settings.php`
- `application/views/pages/google_analytics_settings.php`
- `application/views/pages/matomo_analytics_settings.php`
- `application/views/pages/legal_settings.php`
- `assets/js/pages/api_settings.js`
- `assets/js/pages/booking_settings.js`
- `assets/js/pages/business_settings.js`
- `assets/js/pages/general_settings.js`
- `assets/js/pages/google_analytics_settings.js`
- `assets/js/pages/matomo_analytics_settings.js`
- `assets/js/pages/legal_settings.js`

Key files:
- `application/controllers/Business_settings.php`
- `application/controllers/Legal_settings.php`
- `assets/js/pages/business_settings.js`

### `integrations-sync` - Integrations & Sync

External sync and integration adapters (Google, CalDAV, LDAP, webhooks).

Dependencies:
- `auth-session`
- `people-services-admin`
- `settings-compliance`

Path prefixes:
- `application/controllers/Google.php`
- `application/controllers/Caldav.php`
- `application/controllers/Webhooks.php`
- `application/controllers/Integrations.php`
- `application/controllers/Ldap_settings.php`
- `application/libraries/Google_sync.php`
- `application/libraries/Caldav_sync.php`
- `application/libraries/Synchronization.php`
- `application/libraries/Webhooks_client.php`
- `application/libraries/Ldap_client.php`
- `application/models/Webhooks_model.php`
- `application/views/pages/integrations.php`
- `application/views/pages/webhooks.php`
- `application/views/pages/ldap_settings.php`
- `assets/js/pages/webhooks.js`
- `assets/js/pages/ldap_settings.js`

Key files:
- `application/controllers/Integrations.php`
- `application/libraries/Synchronization.php`
- `application/libraries/Webhooks_client.php`

### `api-v1` - REST API v1

External API surface for appointment-domain entities with auth and schema ties.

Dependencies:
- `auth-session`
- `people-services-admin`
- `scheduling-backoffice`
- `settings-compliance`

Path prefixes:
- `application/controllers/api/v1/`
- `openapi.yml`
- `docs/rest-api.md`

Key files:
- `application/controllers/api/v1/Appointments_api_v1.php`
- `application/controllers/api/v1/Availabilities_api_v1.php`
- `openapi.yml`

### `platform-quality-tooling` - Platform, CI, Release Gates

CI workflows, smoke/release gates, and quality automation scripts.

Dependencies:
- `dashboard-exports`
- `booking-public`
- `api-v1`

Path prefixes:
- `scripts/ci/`
- `scripts/release-gate/`
- `.github/workflows/ci.yml`
- `docs/release-gate-dashboard.md`
- `docs/release-gate-booking-confirmation-pdf.md`

Key files:
- `.github/workflows/ci.yml`
- `scripts/ci/dashboard_integration_smoke.php`
- `scripts/release-gate/dashboard_release_gate.php`
