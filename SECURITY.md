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

## Required security properties

The first cycle sharpened the following six properties. They are examples of
important boundaries, not an exhaustive list of reportable security issues.

| Property                                                                                               | Source and interpretation                                                                                                                                                                                                                                                                                  |
| ------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| New appointment links use cryptographically secure randomness.                                         | [Appointments_model::insert](application/models/Appointments_model.php) uses `random_bytes(32)`. A 64-character format check alone is not an entropy proof.                                                                                                                                                |
| Legacy parent-link compatibility preserves stored appointment relationships and required booking data. | [Appointments_model](application/models/Appointments_model.php) derives the parent relationship from storage. Compatibility must not allow caller-supplied relationship or authority changes; a synthetic legacy fixture does not prove all historical links.                                              |
| Authentication expires after the configured inactivity interval.                                       | [EA_Session](application/core/EA_Session.php) enforces inactivity; [configuration](application/config/config.php) currently specifies 7,200 seconds. Retaining a session file or rotating its ID must not renew expired authentication. See [session retention](docs/ops/production-session-retention.md). |
| Calendar updates authorize the current appointment responsibility atomically.                          | [Calendar](application/controllers/Calendar.php) and [Appointments_model](application/models/Appointments_model.php) recheck the locked record. An earlier authorized snapshot is insufficient after ownership changes.                                                                                    |
| Customer operations address customer records only.                                                     | [Customers](application/controllers/Customers.php) and [Permissions](application/libraries/Permissions.php) require the customer role and the caller's applicable relationship/privilege. These routes must not treat provider or administrator target IDs as customer records.                            |
| Account updates require the permitted method, valid CSRF protection, and the current user's authority. | [Account](application/controllers/Account.php) requires POST and restricts fields and target identity; [EA_Security](application/core/EA_Security.php) handles CSRF validation. Rejected requests must not mutate state.                                                                                   |
| The application sends no appointment, staff, or account notification email.                            | Booking confirmation is delivered through the existing on-page PDF download path; contact email fields remain stored for identity and contact data, and ICS/calendar downloads remain available.                                                                                                           |

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
visible with an empty customer value. The isolated HTTP regression checks both
feeds with synthetic records and a post-login permission change. It does not
establish production browser behavior or the safety of other backoffice feeds.

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
Nginx denies the two historical dump URLs because storage from an older release
may be copied forward. For other web servers, verify their storage-deny rule
and the absence of those files before release. The isolated
`ProviderPdfExportHttpTest` covers two synthetic providers and the
controller-to-renderer HTML handoff. The separate provider UI smoke checks real
PDF rendering with one reserved synthetic identity; it does not prove
cross-provider isolation or stale-role denial in production.

Across write paths, establish server-side authority before mutation, reject
without partial changes, and keep dependent effects consistent with commit
success. Follow the [write-path contracts](docs/ci-write-contracts.md) and
[database lock hierarchy](docs/database-lock-order.md), including their explicit
path-specific limits; the general parent ordering is not proof about every
maintenance or delete path. API response projections must not disclose stored
integration secrets.

The Customers API v1 collection and detail actions are GET-only, including
through direct controller aliases; other methods must return 405 with
`Allow: GET` without disclosing customer data or changing customer records,
while global OPTIONS preflight behavior remains unchanged. The controller's
source-level guard is placed before its model access. The isolated synthetic
HTTP regression proves the external rejection and preservation of its fixture
row; it does not establish behavior in production.

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

For service categories, authenticated API writes must likewise bind PUT to the
URL-selected category and enforce the declared method on direct aliases.
Rejected writes must not change another category or a linked service. A
successful category deletion follows the existing foreign-key rule: linked
services remain while their category reference becomes null. See the
[Service Categories API contract](docs/ci-write-contracts.md#service-categories-api-v1)
for the exact local evidence and limits.

For authenticated staff API v1 PUT requests, the URL selects the Admin,
Provider, or Secretary record. A body ID cannot redirect the update to another
user in the shared table, and a direct controller alias cannot change it under
a different HTTP method. See the [Staff API PUT contract](docs/ci-write-contracts.md#staff-api-v1-put)
for the bounded local evidence and its limits.

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
