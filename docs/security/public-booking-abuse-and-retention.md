# Public booking abuse and customer lifecycle

The public parent flow remains anonymous and does not require email. The owner
selected throttling with an explicitly retained distributed-abuse risk. A caller
using many independent source addresses can still consume finite capacity;
names, submitted contact fields, cookies and IP addresses are not verified
family identities. Do not describe this mitigation as capacity fairness.

## Allocation limits

The global 100-request/120-second counter runs in the application-owned
`pre_controller` hook, before controller/database/session construction. It uses
the framework's trusted client-address calculation, the existing serialized
cache keys, stable lock buckets, bounded waits and fail-closed storage handling.
CLI, configured disablement and genuine local loopback behavior remain supported.
The CSP-report receiver retains its separate bounded policy. OPTIONS requests
already terminate before controller dispatch.

New public booking attempts additionally consume per-address budgets of eight
per minute and forty per hour. Failed creation attempts consume budget, and
cancellation does not refund it. A reschedule is exempt only after its genuine
server-issued authority has been verified. Shared networks share these limits.
No extra CAPTCHA, email verification or invitation requirement is introduced.

## Future public customers and consents

Migration 070 adds a dedicated public-origin marker and nullable exact customer
links for new consent rows. Historical consent rows remain unlinked. Only an
actual new public customer insert gets a marker; canary reuse and historical
reschedules cannot adopt an existing customer.

The marker fingerprints the canonical stored customer row. Ordinary customer
updates retire it in the same transaction, after locking the customer. An
authorized public reschedule may preserve a marker only if it matched the
locked original state before that update. A mismatch permanently disqualifies
automatic cleanup.

The public cancellation controller locks customer/provider users in numeric
order, then the service and appointment, and rejects parent drift. The shared
appointment-delete model also discovers public-origin customers and takes those
parents before its appointment lock, so staff and API cancellation use the same
cleanup boundary. After deleting the
appointment and generated buffers, a current locking read must find no remaining
appointment of any type before a matching marked customer may be deleted.
The customer, marker and exactly linked consents disappear in the same outer
transaction. Failure restores all of them. Staff-edited, historical and still
referenced customers remain. This bounded path does not sweep historical orphans or change service cascades.

## Schema-first production sequence and rollback

Keep the verified dual-password-reader release as the rollback floor. Back up
and independently verify restoration before the schema change. Under the
existing production-change lock and recovery/canary guards, run only the reviewed
070 migration through the CodeIgniter console from the bound release artifact;
do not edit the active release to inject a migration. Verify schema 69 before
and 70 after, both foreign keys and nullable links, then check that the current
reader release remains healthy before activating this application code.

An application rollback keeps schema 70 and its attribution data; the preceding
reader can ignore the additional table/column. Do not automatically run `down()`
or restore an older database over intervening bookings. The explicit down
migration retains consent rows but removes their links and all origin markers;
re-upgrading cannot safely reconstruct that attribution. Never backfill by name,
email or IP.

Local evidence covers actual CI HTTP rejection before an unavailable database,
atomic counter concurrency, repeat book/cancel cycles, surviving appointments,
staff updates followed by public rescheduling, eligible public rescheduling and
a reached post-appointment-delete failure with complete rollback. These local
synthetic proofs do not certify every production interleaving.
