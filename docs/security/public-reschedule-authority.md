# Public Reschedule Authority

Status: repository contract for ROB-494. This document records the pre-change
write path on base commit `6744595b32a0e22d7bd489d7cc54efd7a5441f2d`
and the fail-closed authority boundary implemented by this change. It does not
authorize deployment, production access, or live-data mutation.

## Pre-change authority and write path

Before ROB-494, the public reschedule flow had no server-verifiable authority
at the write boundary:

1. `GET /booking/reschedule/{hash}` passed the route value to
   `Booking::reschedule()` and `Booking::index()`.
2. `Booking::index()` looked up an appointment by `hash`, enabled the browser's
   `manage_mode`, and exposed appointment, provider, and customer data. The
   separate ten-minute customer privacy token was not a reschedule authority.
3. The browser submitted `POST /booking/register` with caller-controlled
   `manage_mode`, appointment data (including `id`), and customer data.
4. `Booking_request_dto_factory` normalized those values without binding them
   to the earlier route lookup.
5. `Booking::register()` used the caller's appointment ID as the availability
   exclusion, resolved a customer from caller content, saved that customer,
   and then passed the caller's appointment ID to `Appointments_model::save()`.
6. An appointment ID caused an update. No hash, session, one-time token,
   canonical appointment identity, or state snapshot was verified first.
7. Synchronization, notifications, and the save webhook ran after the database
   writes. Customer and appointment writes were not enclosed by one controller
   transaction.

Consequently, a forged `manage_mode` or appointment ID could reach an existing
appointment mutation, and a later rejection could not prove that both the
appointment and customer remained unchanged.

## Authority source

The route hash remains a lookup capability, but never becomes write authority
by itself. A reschedule page load must:

- resolve the hash server-side to one canonical, non-unavailability
  appointment;
- apply the existing provider-smoke and advance-time boundaries;
- load the canonical appointment, customer, provider, and service records;
- create a cryptographically random, ten-minute reschedule authority;
- store only a SHA-256 digest of the opaque authority in the database and keep
  the raw value only in the server-side session;
- bind the record to the exact appointment, customer, server-side session
  context, expiry, and a canonical state fingerprint; and
- replace any earlier authority for that same appointment.

The raw authority is never exposed to browser JavaScript or submitted as a
request field. It must not appear in logs, exception messages, tests as a fixed
value, CI reports, PR evidence, or Linear.

## Write-boundary verification

Any public register request that supplies `manage_mode`, an appointment ID, or
a reschedule authority is an attempted existing-appointment mutation. It must
fail closed unless all of these hold:

- the opaque authority is present and its digest resolves exactly once;
- the authority is unused and unexpired;
- the server-side session context matches;
- the caller's appointment ID equals the authority's appointment ID;
- the caller's customer ID, when present, equals the authority's customer ID;
- the canonical appointment still has the same customer, provider, service,
  hash, content, and scheduling state captured at issuance;
- the canonical customer, provider availability state, provider-service
  assignment, and service state still match the issuance fingerprint; and
- the requested target provider, service, time, customer overlap, CAPTCHA,
  availability, buffer, and reserved-smoke boundaries still pass.

The caller's `manage_mode`, IDs, hash, path, or token-shaped input is never
sufficient on its own. Normal creation is the request shape with none of those
existing-appointment mutation signals; caller-supplied IDs are never allowed to
turn that path into an update.

## Expiry, single use, and replay

Authority rows are stored in an InnoDB table with unique constraints on the
token digest and appointment ID. Issuance replaces the single row for an
appointment, bounding retained state to at most one row per appointment.

Claiming is serialized by a row lock. The server marks the authority consumed
and commits that claim before any customer or appointment mutation begins.
Concurrent or later claims therefore fail. A failed validation may consume the
authority, but it cannot mutate appointment or customer data; the user must
reload the canonical reschedule link to obtain a fresh authority.

## Identity, content, availability, and race boundaries

The issuance fingerprint is a deterministic digest of mutation-relevant
appointment and customer state plus provider availability/service assignment
and service scheduling state. Raw personal or capability data is not stored in
the authority row.

After a successful claim, the write path starts one outer database transaction
and locks the canonical appointment, its customer, original provider and
service, and the requested target scheduling context. It then recomputes the
fingerprint and reruns the existing availability and overlap checks. Public
register writes for the same target provider serialize before their final
availability check.

Consent, customer, appointment, and generated buffer writes happen inside that
outer transaction. Any exception or failed check rolls the transaction back.
Synchronization, notifications, and webhooks run only after commit, preserving
their existing behavior without allowing them to weaken the authority gate.

## Rejection contract

Missing, expired, replayed, foreign, manipulated, session-mismatched, or
state-drifted authority receives the same non-sensitive JSON rejection. The
response does not distinguish which authority check failed and does not expose
IDs, hashes, digests, tokens, customer data, or snapshots.

For every rejected existing-appointment request:

- the appointment row is unchanged by the request;
- the customer row is unchanged by the request;
- no consent, synchronization, notification, or webhook side effect runs; and
- existing CAPTCHA, availability, overlap, buffer, provider-smoke, and
  booking-conflict behavior remains in force.

## API and operations boundary

The authenticated REST API keeps its own Basic/Bearer authorization and is not
granted or restricted by this public authority. Public reschedule authority is
accepted only by `POST /booking/register` and cannot authorize REST API,
backoffice, cancellation, privacy, deployment, SSH, or production operations.

Local and CI evidence may contain only pass/fail classes and aggregate test
results. A repository merge is not deployment authority; production rollout
and live-data validation remain separate, explicitly approved work.
