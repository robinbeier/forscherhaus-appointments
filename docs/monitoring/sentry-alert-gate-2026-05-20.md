# Sentry Alert Gate - 2026-05-20

Purpose: configure the first production Sentry issue alert after the ingestion
gate while keeping notifications quiet, email-based, and scoped away from
synthetic smoke events.

## Scope

Allowed live changes:

- create or update one minimal Sentry issue alert rule;
- use email/Sentry-internal notifications only;
- filter to `environment=production`;
- exclude synthetic smoke events with `area=sentry_smoke`;
- document only redacted rule metadata and validation results.

Out of scope:

- PagerDuty, Opsgenie, Slack, Teams, or other noisy escalation channels;
- Uptime Kuma changes;
- server/deploy changes;
- sending another synthetic Sentry smoke event;
- exposing Sentry auth tokens, DSNs, raw event payloads, stack traces,
  customer data, request URLs, appointment hashes, or production config.

## Planned Rule

Name: `Production: new error issue excluding smoke`

- Tool: Sentry issue alert rule.
- Environment: `production`.
- Trigger: new issue or regression from resolved to unresolved.
- Filters:
  - event level is error or higher;
  - tag `area` is not `sentry_smoke`.
- Action: email notification to Issue Owners; if none can be found, notify
  Active Members.
- Frequency: at most once per issue per 1440 minutes.
- Night behavior: no pager or real-time escalation. Delivery is limited to
  normal Sentry/email notification behavior.

## Stop Conditions

Stop before changing Sentry if:

- existing rules cannot be read;
- Sentry cannot express `environment=production`;
- Sentry cannot exclude `area=sentry_smoke`;
- the token lacks alert write permissions;
- the only available action would be a noisy escalation channel;
- creating the rule would require exposing secrets or raw event data.

## Execution Log

Status: stopped before live configuration change.

- 2026-05-20: read-only Sentry API snapshot succeeded.
  - Project: `forscherhaus-appointments-prod`.
  - Project status: active.
  - Platform: PHP.
  - Existing issue alert rule count: `1`.
  - Existing active rule: `Send a notification for high priority issues`.
  - Existing rule action: Sentry email notification to Issue Owners with
    Active Members fallback.
  - Unresolved production issue count in the 24h snapshot: `1`.
  - The unresolved issue was the controlled smoke issue
    `FORSCHERHAUS-APPOINTMENTS-PROD-9`.
- 2026-05-20: attempted to create the planned minimal issue alert rule.
  - Sentry returned HTTP `403`.
  - No Sentry alert rule was created or changed.
  - Stop condition `token lacks alert write permissions` was reached.

## Required Follow-Up

To complete this gate, use one of these paths:

1. Grant the local Sentry auth token one of the Sentry-required write scopes for
   issue alert rules, preferably `alerts:write` if available. Sentry also
   documents `project:write` or `project:admin` as accepted alternatives.
2. Create the rule manually in the Sentry UI using the planned rule above, then
   run a read-only verification snapshot and update this protocol.

Do not broaden this into a scheduled smoke monitor yet. The next useful live
change is only the minimal production issue alert rule described above.
