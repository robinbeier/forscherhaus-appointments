# ROB-586 Production CSP Report-Only Preparation

Status: the Report-Only implementation is deployed but inactive after the
first pilot was stopped and rolled back. This revision prepares the corrected
evidence path; it does not deploy it, install the activation file, execute the
new web-runtime write probe, or change Uptime Kuma.

The first pilot showed why the evidence path must follow the application rather
than a guessed process topology. Production used a valid PHP-FPM layout that
did not match the checker's fixed `/run/php` ownership assumption. The checker
therefore collapsed a topology mismatch into `runtime_failed`, while the
independent public-header observation remained a separate unresolved fact. A
checker that creates its own FastCGI client, temporary authorization tree, and
socket trust model adds machinery without proving more application behavior.

## Decision

Prepare one bounded `Content-Security-Policy-Report-Only` pilot for the App and
`www` HTTPS surfaces. The application owns both the header and a same-origin
collector. The collector reduces every accepted browser report to fixed classes
before durable storage. `Content-Security-Policy` enforcement remains disabled.

Uptime Kuma remains outside this pilot. Its UI, WebSocket, worker, and vendor
release requirements require a separate policy and evidence set.

## Five-step evidence redesign

The production proof was rebuilt with the five-step design process:

1. **Make the requirements less wrong.** The proof must answer five independent
   questions: Is the exact activation candidate installed? Do App and `www`
   actually deliver Report-Only while enforcement and Monitor stay unchanged?
   Can the real web process create, sync, and remove a file in the aggregate
   directory? Is the retained aggregate valid and class-only? Are the required
   application surfaces still healthy?
2. **Delete parts and assumptions.** The direct FastCGI protocol client, the
   temporary `/run/fh-csp-report-only-status` authorization tree, the fixed
   PHP-FPM socket, and ownership assumptions about `/run/php` are removed. They
   were properties of one deployment layout, not CSP safety requirements.
3. **Simplify the remaining proof.** Activation identity and the classified
   aggregate are read by a root-side, read-only state checker. Public headers
   and functional health come from the existing redacted doctor. Only runtime
   write readiness crosses the application boundary, through a dedicated
   POST-only, loopback-only, health-token-protected endpoint.
4. **Accelerate safely.** `--phase preflight` evaluates every condition that can
   be known before activation and is completely read-only. It verifies the
   deployed release identity, reviewed candidate, canonical pre-existing
   production lock, activation and run-state directories, absence of an active
   candidate or pilot lease, strict health-token identity, and HTTP-client
   readiness. It must pass before installation. Each component returns a stable
   result class instead of the former catch-all `runtime_failed`.
5. **Automate last.** The pilot runner performs one preflight, one activation,
   observations at 0, 15, and 60 minutes, and one identity-bound removal. The
   first failed, unknown, or contradictory result stops the sequence and invokes
   the same removal path once. It never retries activation or evidence. A
   root-owned mode-`0600` server-side lease binds removal to the pilot's random
   run ID, reviewed candidate hash, and initial release binding.

These five checks remain separate in every receipt:

| Check | Evidence source | Mutation |
| --- | --- | --- |
| Activation file | root-side identity, schema, and candidate-hash check | none |
| Public headers | external App/WWW/Monitor header classes | none |
| Runtime write readiness | actual web request creates, syncs, and immediately removes one exclusive probe file | bounded, active phase only |
| Classified aggregate | shared-lock read and finite class summary | none |
| Functional health | App, WWW, Monitor, renderer, and token-protected deep health | none |

The write-readiness endpoint exposes only its schema, pass/fail, and a fixed
result class. It accepts only POST from `127.0.0.1` or `::1` with the existing
health token. Its exact URI is exempt from the browser-oriented cookie CSRF
check because the root client has no session; the health token, loopback source,
numeric loopback destination, and verified peer form the authorization boundary.
It does not read or modify the aggregate or lock file, accept a
caller path, or return a path, token, payload, exception, or file content.
The root-side client connects to numeric loopback, disables inherited proxy
configuration, and rejects a response unless libcurl confirms a loopback peer.

The outer evidence receipt uses `header_posture_verified|mismatch`,
`functional_health_verified|mismatch`, `activation_state_verified`,
`aggregate_state_verified`, `preactivation_read_only`, `write_ready`, and fixed
receipt/remote failure classes. The read-only state receipt further separates
activation failures such as `activation_missing`, `activation_unexpected`, or
`activation_invalid` from aggregate failures such as
`aggregate_lock_missing`, `aggregate_identity_changed`, or
`aggregate_invalid`. The web-runtime receipt distinguishes directory, create,
identity, write, cleanup, and parent-sync failures. These enums are closed by
local validators before output is accepted; raw remote output is never relayed.
Validator success is accepted only with remote exit `0`; classified remote
failure is accepted only with a corresponding failure exit. A valid-looking
receipt paired with a contradictory SSH exit is an unknown result and stops the
pilot.

Every root-side state receipt also carries a release binding derived from the
root-controlled `_RELEASE` marker, its file identity, and its contents. The
pilot records the first binding and requires the same value at 0, 15, and
60 minutes and after cleanup. While the root-owned pilot lease exists, the
normal deployment entry point refuses every application switch under the same
production lock. The reviewed removal helper therefore remains available for
the whole supported pilot path. Observed release drift means an unsupported
external change: evidence collection stops, the original release helper makes
the single lease-bound removal attempt, and no automatic retry follows.

## Bound identities and baseline

The deployed inactive baseline is release `ea_csp_20260920_2033_a4e846ae` on
commit `a4e846aea3a990bb4e1c251fe3a1fdd292ce14ac`. A read-only production
snapshot after the stopped first pilot reported:

- App HTTPS `200`, `www` HTTPS `200`, Monitor HTTPS expected redirect;
- enforcement CSP and Report-Only CSP missing on all three surfaces;
- the other existing header classes present on all three surfaces;
- deep health and renderer `200`;
- seven active Uptime Kuma monitors with seven latest green;
- no unexpected public listener and two classified overlay listeners;
- no service warning class in the preceding hour;
- zero actionable application-error-like lines in the redacted 24-hour
  summary; two lines were classified as known noise.

A separate aggregate-only database observation reported both Google Analytics
and Matomo disabled. It exposed no analytics code, URL, or setting value.
This is a dated baseline, not proof for a later activation window.

## Activation contract

The feature is inactive unless the runtime can safely load the fixed file
`/var/lib/fh-app-config/csp-report-only.json`. The dedicated directory is
root-owned mode `0755`; the non-secret file is root-owned mode `0644` and
the application can read but cannot replace either path. The existing
`/etc/fh` stays private mode `0700` and unchanged. The reviewed source candidate lives at
`scripts/ops/config/csp_report_only.production.v1.json`; its presence in a
release does not activate it. The first production candidate is:

```json
{
  "schema": "csp_report_only_config.v1",
  "enabled": true,
  "app_host": "dasforscherhaus-leg.de",
  "www_host": "www.dasforscherhaus-leg.de",
  "google_analytics_enabled": false,
  "matomo_origin": null,
  "max_reports_per_minute": 120,
  "retention_hours": 48
}
```

The runtime must reject an unexpected key or type, a non-canonical or duplicate
host, credentials, a path/query/fragment in the optional Matomo origin, an
unsafe file identity, or an unsafe ancestor. A missing or invalid file means
disabled. The file contains no secret and does not make production evidence
public.
For an active receipt, the local validator must compare the observed production
hash with the SHA-256 of the versioned candidate file in the reviewed release;
drift or a missing candidate binding fails closed.

The header is eligible only when all of these are true:

- the activation file passes the trust and schema contract;
- the request uses HTTPS;
- the request host exactly matches `app_host` or `www_host`;
- the response is HTML;
- the request is not the collector endpoint.

APIs, downloads, renderer traffic, unmatched hosts, HTTP, and Monitor receive no
header from this feature.

## Candidate App and WWW policy

```text
default-src 'self';
base-uri 'self';
object-src 'none';
frame-ancestors 'self';
form-action 'self';
script-src 'self' 'unsafe-inline' <exact-google-origins-if-enabled> <exact-matomo-origin-if-configured>;
style-src 'self' 'unsafe-inline';
img-src 'self' data: <exact-google-origins-if-enabled> <exact-matomo-origin-if-configured>;
font-src 'self' data:;
connect-src 'self' <exact-google-origins-if-enabled> <exact-matomo-origin-if-configured>;
report-uri /csp-report;
```

`'unsafe-inline'` remains a measured compatibility concession. The policy does
not grant enforcement readiness, and it does not use a wildcard analytics
origin. Google origins are absent when `google_analytics_enabled=false`;
Matomo is absent when `matomo_origin=null`.

## Collector and retained evidence

The collector accepts only bounded CSP report media types on the exact POST
route. It reads at most 32 KiB, classifies the payload in memory, and retains
only this finite tuple:

```text
surface | directive | blocked-origin-class | disposition | count
```

Allowed surfaces are `app` and `www`. Directive and blocked-origin values are
mapped to fixed allowlists with an `other` or `unknown-external` class where
needed. Only `disposition=report` and exact configured document origins are
eligible. The bounded aggregate contains at most the configured hourly
retention window and uses a global per-minute acceptance limit.

Malformed or ineligible payloads receive the fixed empty response without
durable rejection accounting. They therefore cannot force an unauthenticated
per-request aggregate lock, rewrite, flush, or `fsync`. Only a successfully
classified report reaches the bounded aggregate and its acceptance limit.

Never retain or forward a raw payload, document URL, referrer, source file,
source sample, path, query, fragment, appointment capability, analytics code,
Matomo URL, IP address, user agent, cookie, authorization value, or request
header.

The aggregate file is an observation aid. Its identity is sampled only after
the shared aggregate lock is held, so a legitimate collector replacement while
the reader waits cannot be misclassified as an identity attack. Before the
first accepted report, a
missing aggregate is an explicit zero-observation state. The root-side state
check remains read-only and reports that state without inferring write access.
During an active observation, the separate web-runtime probe must additionally
return `write_ready`; only that real request proves the effective application
process can create, sync, and remove a sibling file. An invalid or unavailable
aggregate, a failed cleanup, a storage error, or an unknown class remains a
visible gap. Over-limit reports are rejected transiently with `429` and do not
create a durable rate-limit drop or rewrite the aggregate.

## Local App and WWW matrix

The matrix separates policy distribution from functional coverage. A
Report-Only policy cannot block a flow, so an existing functional regression is
still useful evidence; it does not prove that the flow emits no policy report.
The browser probe supplies a separate class-only receipt and persists no raw
report. Rows that were not exercised remain explicit gaps.

The executable distribution matrix is covered by
`CspReportOnlyTest::testAppWwwAnalyticsAndExcludedSurfaceMatrix`:

| Surface or response | Analytics state | Expected result | Local result |
| --- | --- | --- | --- |
| App HTML over exact HTTPS host | disabled | header present; no Google or Matomo origin | `passed` |
| WWW HTML over exact HTTPS host | disabled | header present; no Google or Matomo origin | `passed` |
| App and WWW HTML | synthetic Google enabled | only exact HTTPS Google Analytics and Tag Manager origins | `passed` |
| App and WWW HTML | synthetic Matomo enabled | only the exact configured origin, including an explicit port | `passed` |
| Monitor or any unmatched host | any | no header | `passed` |
| App API, PDF, ZIP, or other non-HTML response | any | no header | `passed` |
| collector route, HTTP request, or invalid activation file | any | no header | `passed` |

The full local gate was executed against the implementation with coverage. Its
deep-runtime suite passed the API contract, booking writes, API writes, booking
controller flows, and all 12 selected integration-smoke checks. A real local
Chrome run also produced one intentional class-only violation and an
intercepted report without persisting its body.

| Surface | State or flow | Local functional evidence | CSP-specific result |
| --- | --- | --- | --- |
| App public | booking page and availability selection | deep-runtime browser/readiness checks passed | common App policy and real Chrome receipt `passed` |
| App public | booking, reschedule, and cancellation writes | six booking write-contract checks passed | policy distribution `passed`; no per-write browser receipt required for Report-Only |
| App public | confirmation and ICS capability downloads | HTTP download regressions passed | non-HTML exclusion `passed`; confirmation-page browser receipt `not_tested` |
| App account | login | login readiness and authentication smoke passed | common App policy `passed` |
| App account | logout and own-account save | ordinary account regressions passed | authenticated browser receipt `not_tested` |
| App backoffice | dashboard and chart render | dashboard page and browser summary passed | authenticated browser receipt `not_tested` |
| App backoffice | calendar and representative settings | calendar/account/settings integration regressions passed | authenticated browser receipt `not_tested` |
| App export | representative PDF and ZIP | controller, view, and release-gate contract tests passed | non-HTML exclusion `passed`; live renderer download `not_tested` |
| App responsive | booking and login at mobile width | no dedicated mobile-width run in this slice | `not_tested` |
| App desktop | public core pages | booking and login readiness passed | common App policy and real Chrome chain `passed` |
| App analytics | disabled, synthetic Google, synthetic Matomo | exact policy and classifier matrix passed | real analytics-loaded browser fixture `not_tested` |
| WWW | desktop and mobile homepage | same application assets, but no local production-host virtual-host run | exact WWW policy `passed`; functional host run `not_tested` |

`not_tested` rows are stop conditions for the first production activation unless
the approved production window explicitly supplies the missing bounded evidence.
The matrix never uses real analytics values. Public CSP submissions are
unauthenticated and can be fabricated; class counts are diagnostic input, not
proof of a genuine browser or user journey. A locally clean result does not
prove production traffic, proxy behavior, or every user journey.

## Monitor decision

Do not copy the App/WWW policy to
`monitor.dasforscherhaus-leg.de`. Production currently runs the pinned Uptime
Kuma `2.5.5-slim` image on a loopback listener behind its own HTTPS surface. It
uses no browser monitors, but the dashboard itself can depend on WebSocket,
worker, blob, or data behavior that the App policy does not cover.

The Monitor follow-up must begin with a disposable instance of the pinned image
and a separate browser matrix. It must decide exact `connect-src`, WebSocket,
worker, blob, frame, and data requirements before even a Report-Only header is
considered. This App/WWW pilot must keep both enforcement and Report-Only CSP
missing on Monitor.

## Proposed first production window

This section is a future operation requiring the separate production approval.

1. Deploy the reviewed application release with the activation file absent.
2. Run the fully read-only gate. It checks inactive candidate state, public
   headers, any preserved class-only aggregate, functional health, the bound
   release, reviewed candidate, canonical production lock, activation and lease
   paths, and token/client readiness; runtime write readiness is explicitly
   `not_run`:

   ```bash
   bash scripts/ops/prod_csp_report_only_pilot.sh --phase preflight
   ```

3. After a separate approval bound to the deployed commit and candidate hash,
   run the pilot. The fixed remote helper acquires the shared production-change
   lock and installs only the candidate contained in the deployed release with
   no-clobber semantics. From that point through verified removal, the normal
   deployment entry point refuses a release switch while the pilot lease exists:

   ```bash
   bash scripts/ops/prod_csp_report_only_pilot.sh --phase pilot
   ```

4. The runner gathers the five independent evidence components at activation,
   15 minutes, and 60 minutes. The active phase alone makes one host-local HTTP
   request to the write-readiness endpoint per observation. Each request creates
   and immediately removes one exclusive sibling probe; it never touches the
   aggregate or its lock.
5. Run the separately approved synthetic App/WWW browser smokes in the pilot
   window with their existing cleanup receipts. Their results remain distinct
   from header delivery and aggregate evidence.
6. The first failed, unknown, release-drifted, contradictory, or cleanup-failed
   result stops the sequence. The runner makes no retry and invokes the run-,
   release-, identity-, and hash-bound removal action once.
7. After a clean 60-minute observation, the same removal action runs under the
   production lock and the runner repeats the read-only inactive preflight. A
   later enforcement or longer pilot is another decision.

The activation helper accepts no caller path, content, token, or arbitrary
release identifier. The caller supplies only the release binding observed by
the read-only gate and a random run ID; both are assertions, not authority.
Installation authority still comes from the fixed candidate, root-controlled
release identity, canonical pre-existing production lock, and root execution.
Before creating the activation file, the helper durably creates the root-owned
pilot lease. Removal requires the same run ID, release binding, and candidate
hash recorded in that lease and succeeds only while the installed file is still
the exact root-owned, single-link candidate. A mismatching file or lease is
preserved for controlled recovery. The deployment guard treats any present or
unresolved lease as a stop condition; it never removes pilot state itself.
If installation fails, the lease is removed only after the activation path is
confirmed absent before and after a directory sync. A present path or an
unverifiable cleanup retains the lease and therefore continues to block release
switches until controlled recovery.

## Stop conditions

Stop before activation when the deployed SHA, configuration identity, collector
readiness, pre-change health, current analytics state, lock, smoke coverage, or
rollback identity is unknown.

After activation, roll back when:

- enforcement CSP appears;
- Report-Only appears on Monitor, HTTP, an unmatched host, an API, or a download;
- App, `www`, Monitor, renderer, deep health, Kuma, or a required smoke regresses;
- a raw value reaches durable storage, logs, chat, Linear, or a third party;
- the aggregate is invalid, unavailable, unbounded, or unexpectedly noisy;
- sustained unexpected `429` rate-limit responses occur without an understood benign cause;
- a new actionable log/error class appears;
- the installed activation file no longer matches the recorded identity.

## Rollback

Hold the shared production-change lock. Remove only the activation file when its
current SHA-256 and file identity still match the installed candidate. If they
do not match, stop for controlled recovery instead of deleting an unknown file.
No Apache or PHP-FPM reload is required because the application reads the
root-controlled switch for each request.

Then verify:

- App and `www` Report-Only header class is missing;
- enforcement CSP remains missing everywhere;
- Monitor remains unchanged;
- existing production validation, doctor, deep health, renderer, Kuma, and
  redacted log summary pass;
- no synthetic fixture or pending operation marker remains.

Preserve the class-only aggregate and the rejected candidate identity for
review. Do not preserve raw browser reports because none may be written.

## Evidence boundary

Local tests can establish configuration parsing, host and response scoping,
policy construction, payload classification, bounded storage, rate limiting,
and the local flow matrix. A merge establishes reviewed source only. Deployment
with the feature disabled establishes release availability only. The first
production pilot is the earliest point that can establish actual Report-Only
delivery and class-only collection on that exact release.
