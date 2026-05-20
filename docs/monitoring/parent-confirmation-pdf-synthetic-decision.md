# Parent Booking Confirmation PDF Synthetic Decision

Status: no live synthetic monitor for now.

This decision belongs to ROB-387 and applies to the parent-facing booking
confirmation PDF flow at `booking_confirmation/of/<hash>`.

## Current Evidence

- The existing release gate is documented in
  [Booking Confirmation PDF Gate](../release-gate-booking-confirmation-pdf.md).
- The gate is read-only once it has a confirmation target: it opens the
  confirmation page, clicks the PDF button, and validates the downloaded PDF.
- The gate requires either `--confirmation-hash` or `--confirmation-url`.
- The gate report already avoids raw hash/URL output by recording only the
  confirmation source.

## Decision

Do not run this as a live Uptime Kuma monitor yet.

Reason:

- A production confirmation hash or full confirmation URL is bearer-like access
  to a real appointment confirmation page.
- The repo does not currently define a durable privacy-safe synthetic
  appointment whose confirmation page is safe to reuse for monitoring.
- Storing a reusable confirmation hash/link in Kuma or Git would violate the
  monitoring secret/data boundary.
- Using a real family/customer appointment would turn an availability monitor
  into production-data processing.

The current safe use remains:

- local release-gate validation with a known non-production or restored-data
  hash;
- one-off operator validation with a host-local value when explicitly approved;
- dashboard PDF synthetic monitoring, which uses backend gate credentials and
  does not require a parent-facing bearer link.

## Future Go Criteria

Reconsider only if all of these are true:

- The synthetic target is not a real family/customer appointment.
- The target is created and maintained by an explicit server-side runbook or
  script with rollback and cleanup semantics.
- Any bearer-like value stays in a host-local secret file, never in Git, Linear,
  chat, screenshots, or exported Kuma desired state.
- The Push output reports only status, duration, and gate result category; it
  never prints names, email addresses, confirmation hashes, full URLs, or PDF
  contents.
- The monitor owner accepts that this is a business-flow synthetic, not an
  availability monitor, and that failures may wait until working hours unless
  paired with broader PDF-renderer or app-health failures.

## Future Implementation Shape

If the future criteria are met, implement this as a separate gated issue:

1. Add a host-local config contract for a synthetic confirmation target without
   example values.
2. Add a Push wrapper around the existing booking confirmation PDF gate.
3. Keep the Push URL and confirmation target outside Git.
4. Add unit coverage proving the wrapper redacts hash/URL values from output.
5. Create the Kuma Push monitor only through an explicit Kuma write gate.

Until then, keep the monitor catalog entry as a documented no-go/needs-decision
item rather than an active desired-state monitor.
