# Ownership Map

Canonical source: `docs/maps/component_ownership_map.json`

Ownership model: Role + Handles plus explicit single-owner risk metadata.

Note: in `single-owner` mode, identical primary/secondary handles are intentional compatibility placeholders for tooling and do not imply an independent human fallback.

## Ownership Table

| Component | Role | Primary | Secondary | Ownership | Bus Factor | Agent Policy | Manual Approval |
|---|---|---|---|---|---:|---|---|
| `auth-session` | Access & Session | @robinbeier | @robinbeier | single-owner | 1 | conservative | yes |
| `installation-bootstrap` | Installation & Bootstrap | @robinbeier | @robinbeier | single-owner | 1 | conservative | yes |
| `booking-public` | Public Booking | @robinbeier | @robinbeier | single-owner | 1 | conservative | yes |
| `booking-lifecycle` | Booking Confirmation/Cancellation | @robinbeier | @robinbeier | single-owner | 1 | conservative | yes |
| `scheduling-backoffice` | Calendar & Scheduling | @robinbeier | @robinbeier | single-owner | 1 | conservative | yes |
| `dashboard-exports` | Dashboard & Exports | @robinbeier | @robinbeier | single-owner | 1 | conservative | yes |
| `people-services-admin` | People, Providers, Services | @robinbeier | @robinbeier | single-owner | 1 | conservative | yes |
| `settings-compliance` | Settings & Compliance | @robinbeier | @robinbeier | single-owner | 1 | conservative | yes |
| `integrations-sync` | Integrations & Sync | @robinbeier | @robinbeier | single-owner | 1 | conservative | yes |
| `api-v1` | REST API v1 | @robinbeier | @robinbeier | single-owner | 1 | conservative | yes |
| `shared-core` | Shared Core | @robinbeier | @robinbeier | single-owner | 1 | conservative | yes |
| `platform-quality-tooling` | Platform, CI, Release Gates | @robinbeier | @robinbeier | single-owner | 1 | conservative | yes |

## Ownership Scope by Component

### `auth-session`

- Role: Access & Session
- Primary: @robinbeier
- Secondary: @robinbeier
- Ownership mode: single-owner
- Human bus factor: 1
- Agent policy: conservative
- Manual approval required: yes
- Ownership notes: Single human owner; duplicate handles preserve tooling compatibility and do not imply independent secondary coverage.
- Key files:
  - `application/controllers/Login.php`
  - `application/controllers/Recovery.php`
  - `assets/js/pages/login.js`
- Path prefixes:
  - `application/controllers/Login.php`
  - `application/controllers/Logout.php`
  - `application/controllers/Recovery.php`
  - `application/controllers/Account.php`
  - `application/controllers/Localization.php`
  - `application/libraries/Auth_request_dto_factory.php`
  - `application/views/pages/login.php`
  - `application/views/pages/logout.php`
  - `application/views/pages/recovery.php`
  - `application/views/pages/account.php`
  - `assets/js/pages/login.js`
  - `assets/js/pages/recovery.js`
  - `assets/js/pages/account.js`

### `installation-bootstrap`

- Role: Installation & Bootstrap
- Primary: @robinbeier
- Secondary: @robinbeier
- Ownership mode: single-owner
- Human bus factor: 1
- Agent policy: conservative
- Manual approval required: yes
- Ownership notes: Single human owner; duplicate handles preserve tooling compatibility and do not imply independent secondary coverage.
- Key files:
  - `application/controllers/Installation.php`
  - `application/views/pages/installation.php`
  - `assets/js/pages/installation.js`
- Path prefixes:
  - `application/controllers/Installation.php`
  - `application/views/pages/installation.php`
  - `assets/js/pages/installation.js`

### `booking-public`

- Role: Public Booking
- Primary: @robinbeier
- Secondary: @robinbeier
- Ownership mode: single-owner
- Human bus factor: 1
- Agent policy: conservative
- Manual approval required: yes
- Ownership notes: Single human owner; duplicate handles preserve tooling compatibility and do not imply independent secondary coverage.
- Key files:
  - `application/controllers/Booking.php`
  - `application/views/pages/booking.php`
  - `application/libraries/Email_messages.php`
  - `assets/js/pages/booking.js`
- Path prefixes:
  - `application/controllers/Booking.php`
  - `application/views/pages/booking.php`
  - `application/views/components/booking_`
  - `assets/js/pages/booking.js`
  - `application/libraries/Availability.php`
  - `application/libraries/Email_messages.php`
  - `application/libraries/Booking_request_dto_factory.php`

### `booking-lifecycle`

- Role: Booking Confirmation/Cancellation
- Primary: @robinbeier
- Secondary: @robinbeier
- Ownership mode: single-owner
- Human bus factor: 1
- Agent policy: conservative
- Manual approval required: yes
- Ownership notes: Single human owner; duplicate handles preserve tooling compatibility and do not imply independent secondary coverage.
- Key files:
  - `application/controllers/Booking_confirmation.php`
  - `application/controllers/Booking_cancellation.php`
  - `application/views/pages/booking_confirmation.php`
- Path prefixes:
  - `application/controllers/Booking_confirmation.php`
  - `application/controllers/Booking_cancellation.php`
  - `application/views/pages/booking_confirmation.php`
  - `application/views/pages/booking_cancellation.php`
  - `application/views/pages/booking_message.php`

### `scheduling-backoffice`

- Role: Calendar & Scheduling
- Primary: @robinbeier
- Secondary: @robinbeier
- Ownership mode: single-owner
- Human bus factor: 1
- Agent policy: conservative
- Manual approval required: yes
- Ownership notes: Single human owner; duplicate handles preserve tooling compatibility and do not imply independent secondary coverage.
- Key files:
  - `application/controllers/Calendar.php`
  - `application/models/Appointments_model.php`
  - `assets/js/pages/calendar.js`
- Path prefixes:
  - `application/controllers/Calendar.php`
  - `application/controllers/Appointments.php`
  - `application/controllers/Blocked_periods.php`
  - `application/controllers/Unavailabilities.php`
  - `application/controllers/Backend.php`
  - `application/controllers/Backend_api.php`
  - `application/libraries/Booking_slot_analytics.php`
  - `application/libraries/Backoffice_request_dto_factory.php`
  - `application/libraries/Calendar_request_dto_factory.php`
  - `application/models/Appointments_model.php`
  - `application/models/Blocked_periods_model.php`
  - `application/models/Unavailabilities_model.php`
  - `application/views/pages/calendar.php`
  - `application/views/pages/blocked_periods.php`
  - `assets/js/pages/calendar.js`
  - `assets/js/pages/blocked_periods.js`
  - `assets/js/components/appointments_modal.js`
  - `assets/js/components/unavailabilities_modal.js`

### `dashboard-exports`

- Role: Dashboard & Exports
- Primary: @robinbeier
- Secondary: @robinbeier
- Ownership mode: single-owner
- Human bus factor: 1
- Agent policy: conservative
- Manual approval required: yes
- Ownership notes: Single human owner; duplicate handles preserve tooling compatibility and do not imply independent secondary coverage.
- Key files:
  - `application/controllers/Dashboard.php`
  - `application/libraries/Dashboard_metrics.php`
  - `assets/js/pages/dashboard.js`
- Path prefixes:
  - `application/controllers/Dashboard.php`
  - `application/controllers/Dashboard_export.php`
  - `application/controllers/Healthz.php`
  - `application/libraries/Dashboard_metrics.php`
  - `application/libraries/Dashboard_heatmap.php`
  - `application/libraries/Provider_utilization.php`
  - `application/libraries/Dashboard_request_dto_factory.php`
  - `application/views/pages/dashboard.php`
  - `application/views/pages/dashboard_teacher.php`
  - `assets/js/pages/dashboard.js`
  - `assets/js/pages/dashboard_teacher.js`

### `people-services-admin`

- Role: People, Providers, Services
- Primary: @robinbeier
- Secondary: @robinbeier
- Ownership mode: single-owner
- Human bus factor: 1
- Agent policy: conservative
- Manual approval required: yes
- Ownership notes: Single human owner; duplicate handles preserve tooling compatibility and do not imply independent secondary coverage.
- Key files:
  - `application/controllers/Providers.php`
  - `application/models/Services_model.php`
  - `assets/js/pages/services.js`
- Path prefixes:
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

### `settings-compliance`

- Role: Settings & Compliance
- Primary: @robinbeier
- Secondary: @robinbeier
- Ownership mode: single-owner
- Human bus factor: 1
- Agent policy: conservative
- Manual approval required: yes
- Ownership notes: Single human owner; duplicate handles preserve tooling compatibility and do not imply independent secondary coverage.
- Key files:
  - `application/controllers/Business_settings.php`
  - `application/controllers/Legal_settings.php`
  - `assets/js/pages/business_settings.js`
- Path prefixes:
  - `application/controllers/Api_settings.php`
  - `application/controllers/Booking_settings.php`
  - `application/controllers/Business_settings.php`
  - `application/controllers/General_settings.php`
  - `application/controllers/Google_analytics_settings.php`
  - `application/controllers/Matomo_analytics_settings.php`
  - `application/controllers/Legal_settings.php`
  - `application/controllers/Consents.php`
  - `application/controllers/Privacy.php`
  - `application/models/Settings_model.php`
  - `application/models/Consents_model.php`
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

### `integrations-sync`

- Role: Integrations & Sync
- Primary: @robinbeier
- Secondary: @robinbeier
- Ownership mode: single-owner
- Human bus factor: 1
- Agent policy: conservative
- Manual approval required: yes
- Ownership notes: Single human owner; duplicate handles preserve tooling compatibility and do not imply independent secondary coverage.
- Key files:
  - `application/controllers/Integrations.php`
  - `application/libraries/Synchronization.php`
  - `application/libraries/Webhooks_client.php`
- Path prefixes:
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
  - `application/libraries/Integrations_request_dto_factory.php`
  - `application/models/Webhooks_model.php`
  - `application/views/pages/integrations.php`
  - `application/views/pages/webhooks.php`
  - `application/views/pages/ldap_settings.php`
  - `assets/js/pages/webhooks.js`
  - `assets/js/pages/ldap_settings.js`

### `api-v1`

- Role: REST API v1
- Primary: @robinbeier
- Secondary: @robinbeier
- Ownership mode: single-owner
- Human bus factor: 1
- Agent policy: conservative
- Manual approval required: yes
- Ownership notes: Single human owner; duplicate handles preserve tooling compatibility and do not imply independent secondary coverage.
- Key files:
  - `application/controllers/api/v1/Appointments_api_v1.php`
  - `application/controllers/api/v1/Availabilities_api_v1.php`
  - `openapi.yml`
- Path prefixes:
  - `application/controllers/api/v1/`
  - `application/libraries/Api.php`
  - `application/libraries/Api_request_dto_factory.php`
  - `openapi.yml`
  - `docs/rest-api.md`

### `shared-core`

- Role: Shared Core
- Primary: @robinbeier
- Secondary: @robinbeier
- Ownership mode: single-owner
- Human bus factor: 1
- Agent policy: conservative
- Manual approval required: yes
- Ownership notes: Single human owner; duplicate handles preserve tooling compatibility and do not imply independent secondary coverage.
- Key files:
  - `application/libraries/Request_normalizer.php`
  - `application/libraries/Accounts.php`
  - `application/libraries/Notifications.php`
- Path prefixes:
  - `application/views/components/jquery_compat_inline.php`
  - `application/libraries/Accounts.php`
  - `application/libraries/Notifications.php`
  - `application/libraries/Pdf_renderer.php`
  - `application/libraries/Request_normalizer.php`
  - `application/libraries/Timezones.php`
  - `application/models/Roles_model.php`

### `platform-quality-tooling`

- Role: Platform, CI, Release Gates
- Primary: @robinbeier
- Secondary: @robinbeier
- Ownership mode: single-owner
- Human bus factor: 1
- Agent policy: conservative
- Manual approval required: yes
- Ownership notes: Single human owner; duplicate handles preserve tooling compatibility and do not imply independent secondary coverage.
- Key files:
  - `.github/workflows/ci.yml`
  - `scripts/ci/dashboard_integration_smoke.php`
  - `scripts/release-gate/dashboard_release_gate.php`
- Path prefixes:
  - `scripts/ci/`
  - `scripts/release-gate/`
  - `.github/workflows/ci.yml`
  - `docs/release-gate-dashboard.md`
  - `docs/release-gate-booking-confirmation-pdf.md`
