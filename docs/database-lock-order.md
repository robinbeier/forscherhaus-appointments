# Database lock hierarchy

This is the source-grounded lock contract for appointment, service, customer,
calendar, booking, and generated-buffer writes. It describes the current code;
it does not promise a stronger guarantee than the implementation provides.

## Hierarchy

The shared parent-first hierarchy is:

```text
users -> services -> appointments -> generated buffer children
```

For a write that touches several rows at one level, the IDs are converted to
positive integers, deduplicated, sorted numerically, and locked in ascending
order. `Appointments_model::lock_update_parents()` applies this to customer
and provider users, then services. `Reschedule_authority` applies the same
rule to its user and service parent sets. The multi-row parent and cascade
helpers use `ORDER BY id ASC` (or the corresponding ID field) before
`FOR UPDATE`; individual-row locks do not need an ordering clause.

The hierarchy is a partial order, not a requirement to lock every table for
every operation. A permitted subset starts at the first resource it owns and
continues down the hierarchy: a service-only change may lock `services`, then
its appointment parents and generated children; a customer delete may lock
the customer row, then its appointment parents and generated children. A
reentrant call may acquire a row already held by the same transaction when
the framework/database permits it; it must still use the same order for any
new rows it adds.

Generated buffer rows are children of their ordinary appointment. They are
deleted or regenerated only after the ordinary appointment rows are selected
or locked. Buffer rows must not become a new parent level.

## Transaction ownership

The outer operation owns the business transaction and the commit/rollback
boundary. Model methods may join an active CodeIgniter transaction; they must
not commit work that an outer caller still owns. `Services_model::update()`
explicitly records whether it started the transaction. The service controller,
API service update, calendar save, and public booking controller establish an
outer transaction around their multi-model writes. Appointment insert/update
and service/customer delete methods own a transaction when called directly.

Buffer resynchronization is part of the same transaction as the service or
appointment mutation. Its parent helper requires an active transaction and
locks the provider users, then the service, then the ordinary appointments
before replacing generated children. A direct resync call opens its own
transaction; a nested call joins the caller's transaction depth.

## Productive paths

| Path | Current lock sequence and transaction evidence |
| --- | --- |
| Calendar save/update (`Calendar::save_appointment`) | Outer transaction; existing appointment path locks user parents, service parents, then the appointment before rechecking authorization. Customer and appointment model saves then join that transaction. New appointment creation delegates parent locking to `Appointments_model::insert()`. |
| Public booking and reschedule (`Booking::register`) | Outer booking transaction begins after authority preparation. `Reschedule_authority` locks sorted users, services, provider settings/assignments, then the appointment for reschedule; creation locks provider/customer users, service, settings, and assignments. Appointment save then locks its parents and creates buffers in the same transaction. |
| Appointment insert/update | `Appointments_model::insert()` and `update()` start/join the appointment transaction, lock user parents, then service parents, mutate the ordinary appointment, and synchronize buffers before commit. |
| Appointment delete | The method owns a transaction and locks the ordinary appointment row, deletes generated children, then deletes the parent appointment. The current implementation does not lock the user or service parents first; callers must not infer the full hierarchy for this path. |
| Customer delete cascade | Own transaction; locks the customer user row, then ordinary customer appointments ordered by ID, deletes generated children, then deletes the customer. Provider/service parents are not locked by this path. |
| Service update and buffer resync | Service controller/API owns the outer transaction. Buffer changes lock provider users, then the service, then ordinary appointments; generated children are deleted and regenerated before commit. The implementation checks that provider IDs did not change between the parent reads. The current provider-set assertion locks ordinary appointment rows ordered by ID. Resync reads those already-held parents ordered by ID before replacing their generated children. |
| Service delete cascade | Own transaction; locks the service row, then ordinary service appointments ordered by ID, deletes generated children, then deletes the service. Provider/customer users are not locked by this path. |

## Review constraints and evidence gaps

- Do not acquire a new parent lock after locking an appointment. Calendar and
  reschedule compare the earlier snapshot with the locked appointment and fail
  on parent-ID drift instead.
- Do not assume that a nonlocking read establishes authority or serialization.
  The authoritative checks in booking, rescheduling, and calendar are the
  transaction-scoped `FOR UPDATE` reads.
- The code does not establish one universal order for every write in the
  application: appointment delete and the customer/service delete cascades
  intentionally use narrower subsets. Any new cross-resource path must be
  reviewed against this hierarchy and its actual transaction owner.
- This document inventories productive paths found in the current source. It
  does not claim that unrelated maintenance, fixture, or migration code uses
  the hierarchy; those paths require separate inspection.

Primary source locations: `application/models/Appointments_model.php`,
`application/models/Services_model.php`, `application/models/Customers_model.php`,
`application/controllers/Calendar.php`, `application/controllers/Booking.php`,
and `application/libraries/Reschedule_authority.php`.


## Automated evidence

The normal PHPUnit suite runs the following contract regressions:

- `AppointmentsModelLockOrderTest`: numeric parent sorting/deduplication;
  insert, update/reschedule and delete before buffer cleanup; real service
  parent locking during standalone buffer regeneration; reentrant service
  reads use the already-held service ID.
- `ServicesModelLockOrderTest`: provider users before service and appointment
  locks, ID-ordered appointment current reads, sparse service-only updates,
  rollback boundaries, and service cascade before child cleanup.
- `CustomersModelLockOrderTest` and `ParentCascadeBufferCleanupTest`: customer
  parent and appointment locks before generated-child cleanup and cascade.
- `RescheduleAuthorityLockOrderTest`: booking/reschedule parent order and
  current reads before writes. Calendar DB integration coverage is in
  `CalendarCombinedAuthorizationTest`.

These tests observe production method calls and SQL through test doubles or
DB integration. Swapping the observed parent/child operations or dropping the
asserted ordering clause fails the corresponding contract test. They are not
proof of every physical InnoDB index/gap lock or every concurrent schedule;
the [two-connection test harness](two-connection-test-harness.md) provides
focused administrative consistency and parent-lock contention references.
