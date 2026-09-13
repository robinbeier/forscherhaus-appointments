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

Across write paths, establish server-side authority before mutation, reject
without partial changes, and keep dependent effects consistent with commit
success. Follow the [write-path contracts](docs/ci-write-contracts.md) and
[database lock hierarchy](docs/database-lock-order.md), including their explicit
path-specific limits; the general parent ordering is not proof about every
maintenance or delete path. API response projections must not disclose stored
integration secrets.

The product supports `services.attendants_number = 1`, enforced by
[Services_model](application/models/Services_model.php). Other values are not
supported product behavior; this application rule is not a claim of a database
constraint.

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
