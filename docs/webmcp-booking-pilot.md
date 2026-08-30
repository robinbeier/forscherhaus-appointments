# WebMCP booking pilot

Status: bounded, experimental draft (27 August 2026). This document describes
the public booking-page pilot only; it is not a production rollout procedure.

## Scope and non-goals

The pilot exposes exactly three WebMCP tools on the public booking page:
`list_services`, `find_available_slots`, and `prepare_booking`. It is an
assistive layer around the existing booking form, not a second booking API.

It does not store data, confirm or complete a booking, handle contact or
customer data, collect consent, solve CAPTCHA, cancel, reschedule, delete, or
operate back-office workflows. Browserless automation and any replacement for
the normal booking assistant are out of scope. The `booking-public` component
remains single-owner and requires manual approval; see the ownership map and
the [write-path contract](ci-write-contracts.md).

## Configuration and rollback

The feature is disabled by default. The implementation-owned feature switch
must be explicit, localisable for QA, and must not alter existing validation,
rate limits, CSRF, CAPTCHA, or other protection boundaries. If WebMCP is
unavailable, the page falls back to the normal booking assistant unchanged.

Rollback is the reversible operation of disabling the switch and confirming
that the ordinary assistant still works. No schema, persisted state, or
production configuration change is part of this pilot. Do not invent a public
default or enablement value outside the implementation and its review evidence.

## Tool contracts (inputs, outputs, side effects)

The names and data shapes below are the complete pilot contract. They are
deliberately limited to information already visible in the form.

| Tool | Inputs | Outputs | Side effects |
| --- | --- | --- | --- |
| `list_services` | None beyond the tool invocation. | The already public, minimal service-selection data needed to choose a service. No contact or appointment data. | None; read-only. |
| `find_available_slots` | A service/selection identifier and the other selection fields already accepted by the visible form. | Available slots obtained through the existing server-side availability logic. | None; read-only. |
| `prepare_booking` | The currently visible local state of the existing booking form. | A preparation result representing that local state for the normal form flow; it does not submit or confirm anything. | No storage, network booking write, confirmation, or customer-data transfer. |

Inputs must be validated by the existing form/server boundaries. The tools
must not create parallel availability or booking semantics.

The `service_N` and `provider_N` values are deliberately opaque,
document-scoped selection keys. They are valid only for the currently loaded
booking page: clients must obtain them from `list_services` after every reload,
navigation, or catalog change and must never persist, cache, or reinterpret
them as stable identifiers. This avoids exposing internal database IDs while
making catalog reordering an explicit relisting boundary.

`prepare_booking` delegates through the single
`App.Pages.Booking.prepareBookingSelection` integration seam. The adapter does
not reach into wizard markup or duplicate navigation choreography. Shared slot
projection in `App.Http.Booking.projectAvailableHour` keeps normal UI and tool
results on the same timezone and selected-day rules.

## Security boundaries

The pilot cannot weaken or bypass existing validation, rate limiting, CSRF,
CAPTCHA, authentication/authorization checks, or abuse protections. In
particular, `find_available_slots` remains read-only and delegates only to the
existing server-side availability logic; `prepare_booking` is limited to
visible local form state.

WebMCP metadata such as `readOnlyHint` and `untrustedContentHint` is advisory,
not an enforced security boundary. Treat tool arguments and returned content
as untrusted input and retain the application's existing output and navigation
constraints. The public page must not expose hidden contact, customer,
appointment, operational, or back-office data.

## Browser support

This is a dated experiment, based on the 27 August 2026 status: the Draft
Community Group Report uses `document.modelContext.registerTool` and an
`AbortSignal`-based lifecycle. ChatGPT Desktop supports WebMCP; Chrome 149
offers an Origin Trial/flag; Firefox and Safari are status/position tracking
only. Unsupported browsers must remain on the normal booking assistant.

Primary references: [WebMCP](https://webmachinelearning.github.io/webmcp/),
[implementation status](https://github.com/webmachinelearning/webmcp/blob/main/implementation-status.md),
[security and privacy questionnaire](https://github.com/webmachinelearning/webmcp/blob/main/security-privacy-questionnaire.md),
[Chrome WebMCP documentation](https://developer.chrome.com/docs/ai/webmcp), and
[OpenAI WebMCP Challenge](https://openai.com/webmcp-challenge/).

## Local QA

Keep QA local and record evidence for both switch states:

- With the feature off, verify the ordinary booking assistant and its existing
  validation/protection behavior.
- With the feature on in a supported WebMCP environment, verify that exactly
  the three named tools are registered, their inputs/outputs stay within this
  contract, and no booking write or persistence occurs.
- Exercise `list_services` with only publicly visible choices; exercise
  `find_available_slots` against the existing availability path; exercise
  `prepare_booking` with visible form state only.
- Repeat the off/on checks in the in-app browser. Also verify the unsupported
  or unavailable-WebMCP fallback to the ordinary assistant.

Do not use real customer data or production endpoints for this QA slice.

## Production and merge boundary

This PR does not authorize production rollout, enablement, upload, or other
live action. Merge remains blocked until ROB-488 and ROB-461 are complete, or
until a new explicit merge approval is recorded. Any later release still
requires the repository's normal owner, security, QA, and production gates.
