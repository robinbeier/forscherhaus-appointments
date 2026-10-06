# Security context: Forscherhaus Appointments

This repository-wide policy describes the system and properties a security review
must consider. These are required properties, not a claim that every path or
deployed release has been verified. Procedures live in [the cycle guide](docs/defense-factory.md)
and its skills; this file grants no execution, production, or disclosure authority.

## System and trust boundaries

Forscherhaus Appointments is a CodeIgniter appointment application with public
booking flows, authenticated administration, and API surfaces. Protect customer
and staff data, appointment relationships, authentication and link capabilities,
integration credentials, and the integrity of scheduling and release operations.
Use the [architecture map](docs/architecture-map.md) for component boundaries and
the [ownership map](docs/maps/component_ownership_map.json) for current owners.
Ownership labels do not demonstrate an independent security review.

- Public request data, IDs, flags, hashes, and paths are untrusted. Possession of
  an identifier or acceptance of a legacy link format does not create update
  authority; the server must establish the relevant authority for each operation.
- An authenticated session identifies an actor, not permission over every record.
  Customer, provider, secretary, and administrator boundaries still apply.
- Database records can change between an initial read and a write. Decisions
  depending on current ownership must remain valid at the committed mutation.
- Operational tooling can write data or change releases. Its fixtures, cleanup,
  credentials, and evidence have their own trust boundary and require review.
  A successful tool run does not establish the application's security by itself.

The product is used through its own website. Browser access from another origin
is not supported for public, backoffice, or API routes. Application responses
must not grant a foreign origin access through `Access-Control-Allow-*`, including
for `Origin: null` or requests carrying credentials. Same-origin browser requests
need no CORS grant, and server-to-server Basic/Bearer API clients do not use
browser CORS. Global OPTIONS handling ends before controller logic but does not
approve a cross-origin preflight. A simple cross-origin request can still reach
the server, so authentication, CSRF, and capability checks remain essential.
Any future foreign-browser integration needs an explicit, reviewed policy.

The global file-backed request limiter must count concurrent non-exempt requests
without losing increments. Requests sharing a cache key use the same stable
cross-process lock, chosen from 64 fixed buckets, and lock waiting is bounded
to two seconds. An unavailable file-cache adapter, missing or unavailable lock,
unreadable existing counter, or failed cache write rejects the request before
controller work. The isolated parallel-process regression proves the counter
boundary for its synthetic per-key cache; it does not establish production
throughput or a live rate-limit result.

The public monthly booking-availability read must not expand a month safely
beyond the configured future-booking window into one calculation per day and
provider. After request and reschedule-authority validation, it may return the
existing unavailable-month result early only with a conservative timezone
margin and valid service/provider identities. Months near the booking boundary
still use the normal per-provider calculation. The isolated
`BookingUnavailableDatesFutureLimitTest` checks this work bound with synthetic
providers and an availability spy; it does not measure production throughput
or replace the global request limiter.

The public single-day hours read applies the same conservative future-window
guard after request and reschedule-authority validation. A safely distant day
returns the existing empty-hours response before any provider slot analysis;
an invalid specific provider still fails. Near and boundary days use the normal
calculation. `BookingAvailableHoursFutureLimitTest` checks that behavior with
synthetic providers and an availability spy, not with production load.

The final public registration POST also skips per-provider slot analysis for a
definitely distant date after CAPTCHA and reschedule-authority checks. It keeps
service and specific-provider validation and returns the existing unavailable
response without creating a customer or appointment. Near and boundary dates
retain the normal calculation. `BookingRegisterFutureLimitTest` measures the
pre-transaction path with synthetic providers and a valid CAPTCHA; it does not
measure production throughput or replace the normal booking success tests.

JSON bodies read through `EA_Input::json()` are bounded to 1 MiB before
decoding, including requests whose `Content-Length` is absent or incorrect.
The authenticated Appointments API v1 POST/PUT DTO path has the same bound
before its separate raw-body read and JSON decode.
An oversized body is rejected with HTTP 413 before JSON-dependent business mutation;
subsequent field reads reuse the same decoded payload. PHP's `post_max_size`
does not by itself protect `php://input` for JSON requests. This contract
does not cover non-JSON uploads or the separate CSP report receiver.

Appointments API v1 collection and detail reads require a current Admin Basic
identity or the configured global Bearer token, including on direct controller
aliases. Only GET may reach the read handler. Requested `with` relations use
the respective API resource projections; they must not serialize raw database
rows or staff integration secrets. `fields` may narrow appointment fields
without preventing a requested relation from being loaded and projected.
The isolated `AppointmentsApiHttpReadTest` checks these boundaries with owned
synthetic records. It does not establish a live production response.

Providers API v1 collection and detail reads require the existing authorized
Basic identity or configured global Bearer token, including on direct
`providers_api_v1/index` and `show/{id}` aliases. Only GET may reach either
read handler; other methods on the direct aliases must fail before provider
data is loaded and must leave the target unchanged. A requested `with=services`
relation uses the bounded Services API resource projection rather than raw
database rows, even when selected provider fields omit `id`. The isolated
`ProviderApiHttpAuthTest` checks these boundaries with owned synthetic provider
and service records. It does not establish a live production response or broaden
Provider write authority.

Administrative dashboard PDF and ZIP exports must authorize the actor's current
stored administrator role on every request, including direct controller paths.
Only GET may reach export data loading or rendering. Teacher reports use parent
names but never substitute contact email or phone when a name is missing;
provider email is likewise not a display-name fallback in these reports.
Dashboard PDFs must not persist rendered HTML debug dumps. The isolated
`DashboardExportHttpTest` checks these properties with synthetic records and a
recording renderer; it does not prove production PDF bytes or downstream file
handling.

The interactive Dashboard page and its metrics, heatmap, provider-metrics and
threshold endpoints must check the actor's current stored Admin or Provider role
before loading dashboard data or changing a saved range or session threshold.
The direct `dashboard/index` path has the same boundary as `dashboard`.
`DashboardRoleRevocationHttpTest` checks post-login role changes through
isolated synthetic HTTP and database requests; it does not establish a live
production role-revocation race or browser behavior.

Legacy Admin backoffice reads (`admins`, `admins/index`, `admins/search`,
`admins/find`, and the search alias) require the actor's current stored
`users` view permission on every request. `find` addresses Admin records only;
search and detail responses expose only fields needed by the Admin form, never
stored integration credentials or unrelated shared-user fields. A denied page
read must not change the session destination. `AdminsReadProjectionHttpTest`
checks these boundaries with isolated synthetic HTTP and database records; it
does not prove a live production role-change race.

Legacy Secretary backoffice reads (`secretaries`, `secretaries/index`,
`secretaries/search`, `secretaries/find`, and the search alias) require the
actor's current stored `users` view permission on every request. `find`
addresses Secretary records only; search and detail responses expose only
fields used by the Secretary form and assigned provider IDs, never stored
integration credentials or unrelated shared-user fields. An authenticated
denial must not change the session destination; an anonymous Secretary deep
link keeps its login return target. `SecretariesReadProjectionHttpTest`
checks these boundaries with isolated synthetic HTTP and database records; it
does not prove a live production role-change race.

Legacy Services backoffice reads (`services`, `services/index`,
`services/search`, `services/find`, and the search alias) require the actor's
current stored `services` view permission on every request; `find` does not
require delete permission. Canonical search and find accept GET and POST; the
legacy search alias accepts GET only. Other methods must not reach service data.
Search and detail responses expose only the fields
used by the Services form, not internal timestamps or future database columns.
An authenticated denial must not change the session destination, while an
anonymous Services deep link retains its login return target.
`ServicesReadProjectionHttpTest` checks these properties with isolated
synthetic HTTP and database records; it does not prove a live production
role-change race.

Legacy Services writes (`services/store`, `services/update`, and
`services/destroy`, including direct aliases) require POST with the normal
CSRF check and the actor's current stored `services` action permission.
`update` requires an existing service ID; an edit-only role cannot create a
service through that endpoint. Rejected requests leave service rows unchanged.
`ServicesLegacyWriteHttpTest` checks these boundaries with isolated synthetic
HTTP and database records; it does not establish a live concurrent role-change
race or a production write result.

Legacy Customers writes (`customers/store`, `customers/update`, and
`customers/destroy`, including direct aliases) require POST with the normal
CSRF check and the actor's current stored `customers` action permission.
`customers/store` uses the insert-only model path: an email match cannot turn
create authority into an update to an existing customer.
The store visibility limit uses that stored role rather than the login-time
session role. A rejected write must leave customer rows unchanged.
For `update` and `destroy`, current permission and customer relationship are
rechecked after locking the actor, customer, and affected appointment scope in
one transaction. Removal of the last provider relationship while a request
waits must deny the write before mutation.
`CustomersLegacyWriteRoleHttpTest` checks sequential role demotion, the
visibility limit, and an authorized CRUD lifecycle with isolated synthetic
HTTP and database data. `CustomersLegacyRelationshipRaceHttpTest` checks the
actual customer-row lock wait and relationship revocation through canonical
and direct HTTP aliases with own synthetic data. These tests do not establish
serialization of unrelated role-permission or setting changes, or a production
customer write result.

Legacy General and Business Settings page reads (`general_settings`,
`general_settings/index`, `business_settings`, and `business_settings/index`)
require the actor's current stored `system_settings` view permission on every
request. The pages accept GET only and send each browser only the `name` and
`value` of settings used by that page. In particular, an unrelated API token,
LDAP credential, or future setting must not enter either page's script data.
An authenticated denial or unsupported method must not change the session
destination; anonymous GET deep links keep their login return target. The
rendered role and edit controls must use the same current stored role as the
read gate. The three directly coupled General/Business Settings write actions
must also recheck that stored role before any database change; a login-time
role slug cannot grant continued edit authority after demotion.
`SettingsReadProjectionHttpTest` checks these properties with
isolated synthetic HTTP and database data; it does not establish a live
production role-change race or validate unrelated settings write paths.

Classic LDAP Settings (`ldap_settings`, `ldap_settings/index`, and
`ldap_settings/save`) require the actor's current stored `system_settings`
permission. The page accepts GET only and projects only the LDAP switch, host,
and port as `name`/`value`. Save accepts POST with CSRF and may write only those
three names; submitted IDs and extra fields cannot select other rows. Unknown
or duplicate names reject the whole batch before mutation. An authenticated
denial does not change the session destination. `LdapSettingsLegacyHttpTest`
checks these boundaries in an isolated synthetic HTTP/database stack; it does
not prove a live production role-change race or enable LDAP in production.

Classic Legal Settings (`legal_settings`, `legal_settings/index`, and
`legal_settings/save`) require the actor's current stored `system_settings`
permission. The page accepts GET only and projects only its six legal settings
as `name`/`value`; unrelated tokens and credentials must not enter page script
data. Save accepts POST with CSRF and may write only those six names. Submitted
IDs and extra fields cannot select other rows; unknown or duplicate names
reject the whole batch before mutation. An authenticated denial does not
change the session destination. `LegalSettingsLegacyHttpTest` checks these
boundaries in an isolated synthetic HTTP/database stack; it does not prove a
live production role-change race or change production legal texts.

Classic Booking Settings (`booking_settings`, `booking_settings/index`, and
`booking_settings/save`) require the actor's current stored `system_settings`
permission. The page accepts GET only and projects only its 38 booking fields
as `name`/`value`; unrelated tokens and credentials must not enter page script
data. Save accepts POST with CSRF and may write only those booking names.
Submitted IDs and extra fields cannot select other rows; unknown or duplicate
names reject the whole batch before mutation. An authenticated denial does not
change the session destination. `BookingSettingsLegacyHttpTest` checks these
boundaries in an isolated synthetic HTTP/database stack; it does not prove a
live production role-change race or change production booking options.

Classic Matomo Settings (`matomo_analytics_settings`, its direct `index` alias,
and `save`) require the actor's current stored `system_settings` permission.
The page accepts GET only and projects only `matomo_analytics_url` and
`matomo_analytics_site_id` as `name`/`value`; unrelated or future same-prefix
rows do not enter page script data. Save accepts POST with CSRF and may write
only those two names. Submitted IDs and extra fields cannot select other rows;
unknown or duplicate names reject the whole batch before mutation. An
authenticated denial does not change the session destination.
`MatomoAnalyticsLegacyHttpTest` checks these boundaries in an isolated
synthetic HTTP/database stack; it does not prove a live production role-change
race or activate Matomo in production.

Classic Google Analytics Settings (`google_analytics_settings`, its direct
`index` alias, and `save`) require the actor's current stored
`system_settings` permission. The page accepts GET only and projects only
`google_analytics_code` as `name`/`value`; future same-prefix rows and database
metadata do not enter page script data. Save accepts POST with CSRF and may
write only that name. Submitted IDs and extra fields cannot select other rows;
unknown or duplicate names reject the whole batch before mutation. An
authenticated denial does not change the session destination.
`GoogleAnalyticsLegacyHttpTest` checks these boundaries in an isolated
synthetic HTTP/database stack; it does not prove a live production role-change
race or activate Google Analytics in production.

The classic Integrations page (`integrations` and its direct `index` alias)
accepts GET only and requires the actor's current stored `system_settings`
view permission. Its displayed role and privileges follow that same stored
role. An authenticated denial or unsupported method must not change the
session destination; anonymous GET deep links retain the Integrations return
target. The page lists integration destinations but must not expose stored API
tokens or LDAP credentials. `IntegrationsLegacyHttpTest` checks these
boundaries in an isolated synthetic HTTP/database stack; it does not prove a
live production role-change race or exercise integration configuration writes.

The classic `update` page and its direct `index` alias require the actor's
current stored `system_settings` edit permission before the migration library
is initialized. Anonymous and demoted sessions must not reach migration work;
GET displays the confirmation page, HEAD cannot initiate migration, and other
methods must be rejected. A POST additionally requires the framework's CSRF
check. Isolated HTTP/database checks may verify denial and unchanged
migration tracking, but must not use production to exercise migrations.

The classic `account` page and its direct `index` alias accept GET only and
require the current stored `user_settings` view permission. `account/save`
accepts POST with CSRF protection and requires the current stored edit
permission before parsing or updating the authenticated user's own account.
The page's Save control uses the same stored edit permission as the controller.
Demoting an existing session must deny both reads and writes without changing
account rows; caller-supplied account IDs cannot select another account.

## Required security properties

The first cycle sharpened the following six properties. They are examples of
important boundaries, not an exhaustive list of reportable security issues.

| Property                                                                                                                   | Source and interpretation                                                                                                                                                                                                                                                                                  |
| -------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| New appointment links use cryptographically secure randomness.                                                             | [Appointments_model::insert](application/models/Appointments_model.php) uses `random_bytes(32)`. A 64-character format check alone is not an entropy proof.                                                                                                                                                |
| Legacy parent-link compatibility preserves stored appointment relationships and required booking data.                     | [Appointments_model](application/models/Appointments_model.php) derives the parent relationship from storage. Compatibility must not allow caller-supplied relationship or authority changes; a synthetic legacy fixture does not prove all historical links.                                              |
| Authentication expires after the configured inactivity interval.                                                           | [EA_Session](application/core/EA_Session.php) enforces inactivity; [configuration](application/config/config.php) currently specifies 7,200 seconds. Retaining a session file or rotating its ID must not renew expired authentication. See [session retention](docs/ops/production-session-retention.md). |
| Calendar updates authorize the current appointment responsibility atomically.                                              | [Calendar](application/controllers/Calendar.php) and [Appointments_model](application/models/Appointments_model.php) recheck the locked record. An earlier authorized snapshot is insufficient after ownership changes.                                                                                    |
| Customer operations address customer records only.                                                                         | [Customers](application/controllers/Customers.php) and [Permissions](application/libraries/Permissions.php) require the customer role and the caller's applicable relationship/privilege. These routes must not treat provider or administrator target IDs as customer records.                            |
| Account updates require the permitted method, valid CSRF protection, and the current stored authority of the session user. | [Account](application/controllers/Account.php) requires POST and restricts fields and target identity; [EA_Security](application/core/EA_Security.php) handles CSRF validation. Rejected requests must not mutate state.                                                                                   |
| The application sends no appointment, staff, or account notification email.                                                | Booking confirmation is delivered through the existing on-page PDF download path; contact email fields remain stored for identity and contact data, and ICS/calendar downloads remain available.                                                                                                           |

Username availability is a POST-only,
CSRF-protected authenticated check on both the canonical account route and its
legacy alias. The current stored actor permission is checked before parsing the
request. A caller-provided user ID may exempt an existing username only for the
actor's own editable account or an Admin/Secretary account the actor may edit;
it does not confer update authority. The JSON `is_valid` value is a boolean,
and the Admin/Secretary pages must display a duplicate-username warning when it
is false. Isolated HTTP/DB and JavaScript tests cover those boundaries, but do
not establish behavior for real production accounts.

Public login validation creates an authenticated session only through POST with
the existing CSRF check. `Login::validate` rejects other methods before parsing
credentials or changing session identity, including through the direct
controller path. The isolated `LoginMethodHttpTest` verifies the method and
session boundary with synthetic accounts; it does not establish browser-specific
cross-site behavior or the presence of real-world GET-login clients. See the
[login contract](docs/ci-write-contracts.md#öffentliche-sitzungsvergabe).

The public reschedule link may issue a short-lived, session-bound authority
only on GET. A different HTTP method must not rotate an existing authority or
create a customer privacy token, including through a direct controller alias.
The isolated two-session HTTP regression establishes this boundary with own
synthetic records; it does not establish possession or secrecy of real links.
Before selecting a customer or issuing reschedule authority, the public page
must resolve the hash to exactly one appointment. An ambiguous historical hash
gets the same generic not-found response as a missing hash. The authenticated
calendar hash routes likewise reject duplicate matches before opening an edit
dialog. `BookingCalendarAmbiguousHashHttpTest` verifies both boundaries with
isolated synthetic records; it does not establish whether production contains
duplicate hashes.
The public cancellation POST must also resolve its hash to exactly one ordinary
appointment before selecting a deletion target. A duplicate historical hash
gets the same generic not-found response as an unknown hash and changes no
appointment or generated buffer. `BookingCancellationHttpTest` verifies this
with own isolated records; it does not prove that production has duplicates or
cover concurrent hash insertion.
See the [reschedule authority contract](docs/security/public-reschedule-authority.md).

Anonymous booking-confirmation and ICS downloads require a nonempty stored
appointment hash. Reject a missing capability before looking up appointment or
related data; nullable, empty, or ambiguous historical hash fields do not grant access.
Current and future appointments may use existing nonempty legacy hashes. Once
an appointment ends, its public confirmation and ICS links must not disclose
appointment data. Calendar files must omit customer and provider email
addresses, including organizer and attendee fields. The isolated HTTP regression
in `BookingDownloadHttpTest` covers these routes, not every booking operation.

Successful booking-confirmation HTML sets `Cache-Control: no-store` at the
application layer because it carries appointment details and the reschedule
manage URL. The isolated HTTP regression disables PHP's session cache limiter
to prove that this header is application-owned; proxy, browser, and production
cache behavior remain outside that local test.

Public reschedule HTML also sets `Cache-Control: no-store` at the application
layer because it displays appointment and customer details and issues management
authority. `RescheduleCacheHttpTest` disables PHP's session cache limiter and
checks valid, locked, and unknown hashes; it does not prove downstream cache
behavior.

The public reschedule response exposes only the appointment fields needed by
the booking UI and cancellation form. Keep this read projection separate from
the appointment write allowlist: the page needs the existing hash, but a caller
must not supply a hash for saving. `RescheduleMethodHttpTest` checks the rendered
projection and its authority/customer-token binding with synthetic data; it
does not establish who obtained a valid management link.

The authenticated calendar page may preload up to 50 recent customers the
current staff member may view and access according to the persisted role;
apply provider scope before the limit. A calendar URL
carrying an appointment hash may open an edit dialog only for an appointment
within the staff member's provider scope when the current role may view the
accessible customer. Calendar data requests use that current role for provider
scope, appointment-view permission, and private blocked-period notes as well.
Calendar mutations recheck the persisted role and action permission, including
the stored and requested provider, so a stale session role cannot retain write
authority after reassignment. Appointment create, update, and delete hold the
actor and affected parent rows while rechecking authority before mutation.
Manual unavailability save and delete also recheck the stored appointment
action after locking the actor, provider, and existing manual event. A request
waiting on that event must reject a permission revocation committed before it
resumes. `CalendarUnavailabilityPermissionRaceHttpTest` checks this with own
synthetic HTTP and database data; it does not establish a global lock protocol
for independent edits to role permission bits or a production write result.
Both the initial customer list and the edit
dialog use UI-specific read projections; write allowlists do not determine the
fields sent to the browser. `CalendarCustomerAccessHttpTest` checks these
boundaries with synthetic records and roles. It does not prove the safety of
real shared hashes or every other backoffice read endpoint.

The two authenticated calendar JSON feeds likewise return only the event fields
used by the calendar UI. They must not send an appointment's public management
hash, integration identifiers, or raw related model rows. The current persisted
role limits visible appointments to the provider or secretary scope before
related records are loaded. Customer details use the calendar read projection
only when that role may view customers; otherwise the appointment remains
visible with an empty customer value. When events are requested, both feeds
reject missing, invalid, reversed, or longer-than-62-day date ranges with HTTP
400 before event queries. A default-view request without a selected filter
continues to return an empty result without querying events.
The day, week, and month UI views fit within this limit. The isolated HTTP
regression checks both feeds with synthetic records and a post-login permission
change; it does not establish production browser behavior, throughput under
legitimate peak load, or the safety of other backoffice feeds.

The authenticated customer find and search responses use the actor's persisted
role and current `customers.view` permission. Customer search applies the
configured relationship scope before pagination, and includes appointments
only when the actor currently has `appointments.view`. Provider and secretary
appointment scope is enforced before related records are loaded or serialized;
customer, appointment, provider, and service data use UI-specific projections.
The customer page still needs an appointment hash for its existing edit link,
so only an in-scope appointment may carry that public management capability.
`CustomersReadProjectionHttpTest` checks these boundaries and the legacy search
alias with synthetic records. It does not establish production browser behavior
or secrecy of an otherwise authorized management link.

External Google and Outlook calendar links must carry only event details needed
to create the appointment. Do not put the reschedule capability or customer and
provider email addresses into third-party URL parameters. While the appointment
is current or future, the confirmation page, its PDF, and the local ICS download
retain their existing management paths; expired public links disclose no
appointment data. `BookingDownloadHttpTest` checks these boundaries with own fixtures.

Do not render third-party analytics scripts on public responses whose URL carries
an appointment hash, including reschedule, confirmation, and cancellation
responses. A sanitized pageview URL alone is insufficient because the external
script executes in the capability-bearing page. Ordinary booking without a
hash may retain configured analytics; the isolated HTTP regression checks both
sides with synthetic analytics settings.

Public booking renders stored company name, logo URL, and custom-field labels
as plain text or HTML attribute values. Escape those values at each output
context, including the disabled-booking page title; stored settings must not
create markup or attributes in the visitor's page. `BookingHtmlEscapingHttpTest`
checks the ordinary and disabled pages with synthetic settings in an isolated
HTTP/database stack. It does not change or validate production settings.

When public booking CAPTCHA is enabled, registration requires both a nonempty
server-generated challenge in the current session and a nonempty matching
request value before any booking or consent write. A correct challenge is
consumed before the booking attempt continues, so it cannot authorize a later
request in that session without a newly generated challenge. An incorrect value
does not consume the challenge. The booking UI's CAPTCHA image request is not a
substitute for this server-side check. The isolated `BookingCaptchaHttpTest`
covers direct HTTP rejection without mutation, one successful own booking and
consent, sequential replay rejection, and a fresh-challenge recovery. It does
not establish simultaneous-request behavior, full browser CAPTCHA usability,
or current production configuration.

An ordinary public booking creates a new customer record. The submitted email
is not proof of ownership of an existing customer: when the email field is
hidden, a nonempty email in a direct POST is rejected; when it is displayed,
an email collision is rejected without updating the old record. The insertion
path must not silently fall back to email-based update if another writer adds
that email after the collision check. A valid one-time reschedule claim may
update only its bound customer. The server-verified zero-surprise canary lease
may reuse only its own synthetic customer. Isolated HTTP/database regressions
cover rejection without partial writes, repeated name-only booking, and the
link-authorized reschedule control; they do not prove natural production use.

Provider parent-appointment and preparation PDFs contain personal appointment
data. Only GET may reach either export, including through direct controller
aliases. The authenticated session must identify a provider whose role is still
current in the database; request-supplied provider IDs cannot select another
provider's records. These two exports must not persist their rendered HTML to
fixed debug-dump files, even when the general PDF debug flag is enabled. Bundled
Nginx denies the whole storage tree, including the two historical dump URLs,
because storage from an older release may be copied forward. For other web
servers, verify their storage-deny rule
and the absence of those files before release. The isolated
`ProviderPdfExportHttpTest` covers two synthetic providers and the
controller-to-renderer HTML handoff. The separate provider UI smoke checks real
PDF rendering with one reserved synthetic identity; it does not prove
cross-provider isolation or stale-role denial in production.

The local Docker Nginx mounts the repository as its document root. It must
not serve dotfiles, internal source/data directories, or arbitrary files at
repository root, and only `index.php` may be executed through PHP-FPM.
Public assets, the health file, and application routing remain available.
`scripts/ci/nginx_private_paths_smoke.sh` checks this boundary with only
synthetic files in a separate loopback-bound container; it does not verify
production Apache or other published local ports.
The default local Compose app, MySQL, and phpMyAdmin host ports must also bind
to loopback, particularly when a production dump is imported for development.
Docker Engine `>=28.3.3` is required for the documented LAN boundary because
older Engines can expose localhost-published ports to same-network peers, and
some 28.x releases can lose the boundary after a firewalld reload.
`scripts/ci/compose_sensitive_ports_smoke.sh` checks that version and the
canonical resolved Compose bindings; custom daemon routing, overrides and
host firewalls are outside this check. Its `--config-only` mode is for CI
configuration regression only and never authorizes a production-dump import.

Across write paths, establish server-side authority before mutation, reject
without partial changes, and keep dependent effects consistent with commit
success. Follow the [write-path contracts](docs/ci-write-contracts.md) and
[database lock hierarchy](docs/database-lock-order.md), including their explicit
path-specific limits; the general parent ordering is not proof about every
maintenance or delete path. API response projections must not disclose stored
integration secrets.

API Basic authentication must resolve the actor's currently stored role for
each request. A demotion away from Admin revokes access to Settings API v1
through canonical and direct routes before a read or write; a rejected PUT
must leave the setting unchanged. The global Bearer token is separate from
that actor role. Within the Admin role, the generic API's existing authority
does not depend on the finer `system_settings` permission; see the
[Settings API contract](docs/ci-write-contracts.md#settings-api-v1). The
isolated synthetic role-revocation regression is not a productive account
test or a proof about concurrent role changes.

Settings API v1 `index` and `show` are GET-only through canonical routes and
direct controller aliases. Other methods on the direct read aliases return 405
with `Allow: GET` before the requested settings are read or disclosed; the
authenticated PUT update action remains separate. The isolated synthetic HTTP
regression checks method rejection and preservation of its own setting row,
not live production behavior.

The Customers API v1 collection and detail actions are GET-only, including
through direct controller aliases; other methods must return 405 with
`Allow: GET` without disclosing customer data or changing customer records,
while global OPTIONS preflight behavior remains unchanged. The controller's
source-level guard is placed before its model access. The isolated synthetic
HTTP regression proves the external rejection and preservation of its fixture
row; it does not establish behavior in production.

The authenticated Admins API v1 `index` and `show` read actions require GET,
including through direct controller aliases. Non-GET requests to those direct
actions return 405 with `Allow: GET` before querying or disclosing admin data.
Canonical POST, PUT, and DELETE routes remain separate write actions; global
OPTIONS preflight behavior is unchanged. The isolated synthetic HTTP/database
regression checks the alias method boundary and preservation of its own admin
row. It does not establish production behavior.

The authenticated Secretaries API v1 `index` and `show` read actions require
GET, including through direct controller aliases. Non-GET requests to those
direct actions return 405 with `Allow: GET` before querying or disclosing
secretary data. Canonical POST, PUT, and DELETE routes remain separate write
actions; global OPTIONS preflight behavior is unchanged. The isolated
synthetic HTTP/database regression checks the alias method boundary and
preservation of its own secretary, settings, and provider-link rows. It does
not establish production behavior.

The authenticated Services API v1 `index` and `show` read actions require GET,
including through direct controller aliases. Non-GET requests to those direct
actions return 405 with `Allow: GET` before querying service data. Canonical
POST, PUT, and DELETE routes remain separate write actions. Isolated
HTTP/database tests check the read response and preservation of their own
synthetic services; they do not establish production behavior.

The Customers API v1 `store` and `update/:id` actions require POST and PUT,
respectively, also through direct controller aliases. After API authentication,
other methods return 405 with `Allow: POST` or `Allow: PUT` before customer
payload parsing, target lookup, or mutation. Rejected writes must leave customer
and dependent records unchanged. `CustomersApiWriteAliasHttpTest` checks the
direct aliases, valid canonical and alias writes, and preservation of its own
synthetic customer rows in an isolated HTTP/database stack; it does not prove
production behavior or unchanged rows outside that fixture.

Customers and Providers API v1 deletion requires DELETE even through direct
controller aliases. Other authenticated methods return 405 with `Allow: DELETE`
before either model reads or mutates a record. Isolated synthetic HTTP/DB
tests check both aliases, unchanged dependent rows, canonical DELETE, and
authentication. Productive behavior is limited to release identity and health
until an owned, cleanly removable live fixture is established.

The public personal-information deletion endpoint is a destructive capability
path. It requires POST with CSRF, an enabled product setting, and a live
customer token issued from the stored appointment-to-customer relationship.
Tokens use cryptographically secure randomness and expire after ten minutes;
the cache mapping alone does not authorize deletion of a non-customer record.
Wrong methods, missing or invalid authority, and replay after a successful
deletion must leave customer and dependent records unchanged. See the
[privacy deletion contract](docs/ci-write-contracts.md#offentliche-datenschutz-loschung)
for the isolated evidence and its production limit.

The standalone public `consents/save` write route is retired. Public request
data, including a consent ID or client IP, must not create or change a consent
there. Successful booking records the configured privacy and terms consents
server-side when the booking commits. See the
[consent contract](docs/ci-write-contracts.md#offentliche-consent-erfassung)
for the HTTP evidence and its limits.

For global blocked periods, an authenticated API write must address only the
URL-selected record and use the declared HTTP method even through a direct
controller alias. A changed blocked period affects booking availability, so a
rejected request must leave the complete period row unchanged. The precise
contract and evidence boundary live in [CI write contracts](docs/ci-write-contracts.md#blocked-periods-api-v1).
Blocked Periods API v1 collection and detail reads require GET, including on
direct controller aliases. Other methods return 405 with `Allow: GET` before
the requested periods or notes are read or disclosed; the existing Admin Basic
and global Bearer authorities are unchanged. The isolated HTTP regression
checks the response and preservation of its own period row, not live production
behavior.
The isolated `PublicBookingBlockedPeriodRaceHttpTest` additionally checks one
ordered overlap: a global block committed while a public booking waits for its
provider lock is visible to the post-lock availability check, and the rejected
booking leaves no customer, appointment, or consent behind. It does not prove
every interleaving with a blocked-period edit or a live production write.
The isolated `PublicRescheduleBlockedPeriodRaceHttpTest` checks the corresponding
ordered overlap for an authorized public reschedule: the newly committed block
rejects the waiting POST without changing its appointment, customer, service,
or consent. The one-time reschedule authority is consumed; a new authority is
required before the positive control succeeds after the synthetic block is
removed. This is local evidence for one interleaving, not a production test.
For a provider-owned manual unavailability, the public availability response
must hide its overlapping slot while leaving an adjacent free slot available.
A direct public booking POST into that blocked slot must reject without creating
a customer, appointment, or consent. The isolated
`BookingManualUnavailabilityHttpTest` checks this with a run-owned synthetic
provider, removes the manual block, and verifies that the same slot can then
be booked and all synthetic records cleaned up. It does not establish a
concurrent edit interleaving or a production booking result.
For an authorized public reschedule, the same provider-owned manual
unavailability must remove its overlapping target from the displayed hours and
reject a direct `booking/register` manage-mode POST without partially changing
the original appointment, customer, service, or consent. The isolated
`BookingManualUnavailabilityRescheduleHttpTest` checks this with a run-owned
appointment and session-bound authority; after a rejected attempt consumes
that authority, removing the exact manual block and issuing a new authority
allows the positive control. This is one local ordered flow, not evidence for
concurrent manual edits or a production reschedule.
The isolated `PublicBookingManualUnavailabilityRaceHttpTest` checks one
concurrent booking schedule: a second connection inserts a run-owned manual
block but holds its transaction open while the public POST completes its first
availability read and waits for the provider lock. The block commits before
the booking resumes; an independent connection verifies the committed row.
The POST then rejects with 409 and leaves no customer, appointment, or consent
partial write. A separate local control books the slot after its own block is
removed. This does not cover the Calendar or API HTTP writer, every possible
interleaving, or a live production booking.
The isolated `PublicRescheduleManualUnavailabilityRaceHttpTest` covers the
corresponding authorized public reschedule schedule: a run-owned manual block
commits while the POST waits on the provider lock. An independent connection
confirms the committed block; this observation may occur after the POST resumes.
The POST rejects with 409 without changing the original appointment, customer,
service, or consent; the attempted use consumes its one-time authority. A new
authority allows a positive local control after the owned block is removed. This proves
one ordered interleaving, not the Calendar or API HTTP writer, every concurrent
schedule, or a productive reschedule.

For service categories, authenticated API writes must likewise bind PUT to the
URL-selected category and enforce the declared method on direct aliases.
Rejected writes must not change another category or a linked service. A
successful category deletion follows the existing foreign-key rule: linked
services remain while their category reference becomes null. See the
[Service Categories API contract](docs/ci-write-contracts.md#service-categories-api-v1)
for the exact local evidence and limits.
Service Categories API v1 collection and detail reads require GET, including
on direct controller aliases. Other methods return 405 with `Allow: GET`
before the requested category is read or disclosed; existing Admin Basic and
global Bearer authority stays unchanged. The isolated HTTP regression checks
the response and preservation of its own category row, not live production
behavior.
Availabilities API v1 requires GET on its direct read alias before provider,
service, or availability lookup. Other methods return 405 with `Allow: GET`;
Admin Basic and global Bearer authentication still run first. The isolated
HTTP regression covers the owned service's response, denied identities, and
rejected methods without a database change; it is not a live production test.

For authenticated staff API v1 PUT requests, the URL selects the Admin,
Provider, or Secretary record. A body ID cannot redirect the update to another
user in the shared table, and a direct controller alias cannot change it under
a different HTTP method. See the [Staff API PUT contract](docs/ci-write-contracts.md#staff-api-v1-put)
for the bounded local evidence and its limits.

The classic `providers/store` path accepts only POST with CSRF and requires
the actor's current stored `users.add` permission. It creates a new Provider
only: a caller ID may be absent or exactly empty, but cannot select an
existing shared-table user; a caller role cannot set the new account's role.
The actor and requested service rows are checked under locks before the
user, settings, and service associations are written in one transaction.
Rejected requests and downstream failures must leave no partial Provider.
The isolated `ProvidersStoreHttpTest` checks methods and aliases, sequential
demotion, ID/type, CSRF, normal creation, and failed association writes with
synthetic data. A concurrent edit to a role's permission bits is not
serialized by the actor-user lock; the local tests do not establish every
concurrent schedule or a production account write.

The classic `admins/store` path accepts only POST with CSRF and requires the
actor's current stored `users.add` permission. It creates a new Admin only:
the caller cannot select an existing shared-table user with an ID or choose
the new account's role. The actor and its current role row are locked through
the atomic user and settings write, so a concurrent role-permission change is
ordered before or after the create decision. A failed write leaves neither row
behind. The isolated
`AdminsStoreHttpTest` checks methods and aliases, sequential demotion,
create-only IDs, CSRF, normal creation, and a downstream settings failure
with synthetic data. A coordinated two-connection test verifies that a
concurrent role-bit revocation blocks the create decision and leaves no user
behind. The tests do not prove every concurrent schedule or a production
account write.

The classic `providers/update` path accepts only POST with CSRF and requires
the actor's current stored `users.edit` permission. It must update an existing
Provider only: an omitted ID cannot create one, a shared-table ID of another
role cannot become a Provider, and a request-supplied role cannot change the
target's role. The actor and target user rows are locked in numeric ID order,
followed by current and requested service parents, through the atomic user,
setting, and service-association write. A concurrent
edit to a role's permission bits is not serialized by these user-row locks.
The isolated `ProvidersUpdateHttpTest` checks the method, sequential demotion,
ID/type, role-field, CSRF, normal update, and downstream-failure boundaries
with synthetic data; it does not prove every concurrent role-change schedule
or a production account write.

The classic `providers/destroy` path accepts only POST with CSRF. It requires
the actor's current stored `users.delete` permission and a positive target ID.
An actor whose account has disappeared after login is denied with 403.
The target must still have the Provider role in the shared users table. Actor
and target rows are locked in numeric ID order before the permission and role
checks; deletion is limited to that ID and role, and succeeds only when exactly
one row is affected. Rejected requests must leave the target unchanged. The
isolated `ProvidersDestroyHttpTest` covers methods and direct alias, CSRF,
sequential demotion, ID/type, and a synthetic successful delete. This local
proof does not establish every concurrent role-change schedule or a
production account write.

The classic `secretaries/destroy` path and its `backend_api/ajax_delete_secretary`
alias accept only POST with CSRF. The actor must retain the current stored
`users.delete` permission; actor and target rows are locked in numeric ID order
and rechecked before deletion. Only a target still carrying the Secretary role
may be deleted, and the model requires exactly one affected row. The isolated
`SecretariesDestroyRoleHttpTest` covers method and CSRF rejection, sequential
role demotion, target type, and a synthetic successful delete on both paths.
It does not prove every concurrent role-permission edit or a production
account write.

The classic `secretaries/update` path accepts only POST with CSRF and a positive
existing Secretary ID. It cannot create an account when that ID is missing.
The actor must still have the stored `users.edit` permission after a role
change. The actor, Secretary, and requested provider users are locked together
in numeric ID order; the actor's role permission and target role are checked
before the model joins the transaction for user, settings, and assignment
writes. The isolated `SecretariesUpdateRoleHttpTest` covers methods, the direct
`index.php` alias, CSRF, missing ID, other-role target, sequential demotion,
and a successful update with synthetic data. It does not prove every concurrent
role-permission edit or a production account write.

The classic `secretaries/store` path accepts only POST with CSRF and the
actor's current stored `users.add` permission. It rejects caller-supplied
target and role IDs so a create request cannot become an update or choose its
own role. The actor and requested provider users are locked in numeric ID
order; the actor's current role permission is rechecked before the model joins
the transaction for the user, settings, and provider assignments. The isolated
`SecretariesStoreRoleHttpTest` covers methods, direct `index.php` alias, CSRF,
sequential role demotion, ID/role rejection, and authorized creation with
synthetic data. It does not prove every concurrent role-permission edit or a
production account write.

The product supports `services.attendants_number = 1`, enforced by
[Services_model](application/models/Services_model.php). Other values are not
supported product behavior; this application rule is not a claim of a database
constraint.

Browser security-policy reports are untrusted public input and can contain
complete document, referrer, source, and blocked-resource URLs. Classify them
before persistence and retain only fixed surface, directive, blocked-origin,
disposition, and count classes. Never persist or forward a raw report, URL,
path, query, fragment, source sample, user agent, cookie, authorization value,
or capability token. App and `www` may share a reviewed Report-Only measurement
policy, but the Uptime Kuma monitor is a separate vendor surface and requires
its own compatibility evidence and rollout decision. A Report-Only observation
does not authorize CSP enforcement.

## Findings, scope, and evidence limits

A report should explain the affected boundary, realistic reachability, impact,
and the source/evidence supporting it. Separate a candidate suspicion from a
confirmed control failure and record unresolved assumptions. A well-supported
source finding need not have a live exploit demonstration to be reportable.
Do not dismiss a finding merely because a production test cannot be performed.

This policy introduces no component exclusions, finding-class suppressions, or
accepted risks. Editing restrictions on `system/` and dependency code do not
exclude relevant vulnerabilities from review. Legacy compatibility and existing
tests are not blanket compensating controls. Material scope or risk acceptance
decisions remain with the owner.

Source review, isolated tests, merge, deployment, and independent production
verification provide different evidence. Each observation is bounded by its
release, configuration, method, environment, and coverage. Missing, failed,
unsafe, or unexecutable checks remain gaps. A deterministic concurrency test
covers its tested schedule; a shortened timeout covers its configured interval.
Neither establishes all production interleavings or the production timeout.
The [release gate](docs/release-gate-defense-cycle.md) defines the evidence and
cleanup contracts. The [first-cycle retrospective](docs/retrospectives/defense-factory-2026-09-13.md)
records a dated closeout of six invariants, not a repository-wide certification.

Reports and policy must omit credentials, usable booking links, cookies, session
contents, and personal data. Keep changing run status and redacted operational
receipts in the existing private evidence report/workpad, not in this policy.
The inherited [upstream reporting notice](.github/SECURITY.md) remains separate;
it does not establish a fork-specific disclosure contact or scanner exclusion.
