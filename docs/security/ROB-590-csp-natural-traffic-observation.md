# ROB-590: CSP Report-Only with natural traffic

ROB-586 proved a supervised 0/15/60-minute pilot. ROB-590 prepares a separate
observation campaign for natural App and WWW use. The one-hour pilot and its
activation candidate remain unchanged. The campaign cannot establish browser
compatibility before representative production traffic exists; a synthetic
probe is functional evidence only.

## Phase A: bounded segment contract

Each segment uses the reviewed v1 candidate as a template. The root-side
installer derives a v2 activation file at installation time with an exact
`starts_at_unix` and `expires_at_unix` window of 900–14,400 seconds. Its bytes
and SHA-256 are bound in the existing root-owned run lease. Outside that
half-open window, the application emits no Report-Only header and rejects CSP
report submissions before aggregate mutation. The collector checks the clock
again after acquiring the aggregate lock, so waiting at the expiry boundary
cannot extend a segment.

`prod_csp_report_only_segment.sh` has five explicit phases:

| Phase | Effect | Required evidence |
| --- | --- | --- |
| `preflight` | Read-only | Inactive CSP, exact release, healthy App/WWW/Monitor, activation prerequisites |
| `start` | One activation and one bounded runtime write-readiness probe | Private no-clobber journal created before installation; exact run, target, release, dynamic hash and server time window; active header and aggregate classes |
| `recover` | Read-only on production; reconstruct a local journal after a lost post-install update | Root-owned lease and activation must match the original run ID and release; derived hash, exact window, target and current public state must agree; this never repeats installation |
| `observe` | Read-only; repeatable after a transport interruption | Same release, activation hash/window, App/WWW header and health classes, classified aggregate; expired is distinct from active |
| `finish` | At most one lease-bound removal | Current activation checked against the journal before removal; inactive postflight; terminal journal retained |

Use a **unique** `CSP_SEGMENT_STATE_FILE` path for each planned segment. The
default is `/var/tmp/fh-csp-report-only-segment.state.json` and deliberately
blocks another start while its journal remains. The journal contains hashes,
times, result classes and a random run ID, not raw reports or private URLs.
Do not delete it to restart an incomplete segment. A local lock prevents
concurrent operator calls; a stale lock requires investigation.

A confirmed activation whose first evidence check fails gets one bound
cleanup attempt. An unknown installation, removal, postflight or journal
result stops the operator. If the local journal is still at the pre-install
checkpoint after a confirmed server-side install, `recover` can inspect the
root lease and activation without changing production. It writes a finishable
journal only when run, release, candidate hash, exact window and public state
agree. A storage failure or contradictory evidence remains a manual recovery
state. Neither activation nor removal is blindly retried.
The server-side expiry stops CSP effects even if the client disappears, but
the activation file and root lease can remain after expiry. That is a recovery
state: inspect it, remove it only through the verified run-bound path, and
confirm inactive postflight before another segment or deployment. The normal
deployment helper refuses a release switch while the lease remains.

Phase A comprises local implementation and tests only. **Starting the first
production segment requires a separate concrete approval** for the reviewed
release, activation, observations, and recovery path. A merge or deployment
does not activate CSP. No enforcement, Monitor policy, or Uptime Kuma change
is part of ROB-590.

## Phase B: evidence and conclusion

Observe only during actual traffic from mid-October through November 2026.
Bind each segment to its reviewed release, run ID, candidate hash, target,
window, header state, health state, classified aggregate, and verified
cleanup. The campaign Workpad groups segment receipts without flattening
distinct releases or treating repeated samples as independent users.

Assess App and WWW separately. Correlate classified CSP signals with
privacy-preserving evidence that public WWW, real booking paths, and naturally
used App/administration paths were exercised. Distinguish known synthetic
signals, expected resource classes, and unknown external violations. The
collector stores only fixed aggregate classes and counts; never paste raw CSP
payloads, complete URLs, customer data, secrets, or response bodies into
Linear. A zero-violation aggregate alone does not prove route coverage.

If traffic is too sparse or a needed path was not naturally used, record
`inconclusive_insufficient_traffic`; do not use the campaign as evidence for
CSP enforcement. A representative result still only informs a later,
separately approved enforcement decision.
