# Defense Factory: context and reusable skills

This guide routes the Defense Factory cycle's shared context and reusable
procedures. [OpenAI's article](https://openai.com/the-defense-factory/) describes
`SECURITY.md` as shared security context across the cycle and skills as reusable
workflows. Here, each kind of knowledge has one maintained home:

| Knowledge | Canonical home |
| --- | --- |
| System boundaries, required security properties, reportability, evidence limits | [Root SECURITY.md](../SECURITY.md) |
| Resume existing findings and scope the next achievable evidence step | [defense-cycle-context](../.agents/skills/defense-cycle-context/SKILL.md) |
| Assess existing verification evidence and decide whether the defined cycle is complete | [defense-cycle-closeout](../.agents/skills/defense-cycle-closeout/SKILL.md) |
| Runtime, delegation, validation, external actions, and ticket-to-merge flow | [WORKFLOW.md](../WORKFLOW.md) |
| Defense-cycle role selection and evidence-bound transitions | [Role and transition contract below](#role-and-transition-contract) |
| Evidence format, test mechanics, shared operations lock, recovery, and cleanup | [Defense release gate](release-gate-defense-cycle.md) and linked operator docs |
| What happened in a particular round and why | [Dated first-cycle retrospective](retrospectives/defense-factory-2026-09-13.md) |
| Current release identities, per-run results, open gaps, pending updates | Existing private evidence report and, where applicable, the single `## Codex Workpad` |

The accepted [Provider regression follow-ups](provider-api-regression-followups.md)
record concrete improvements to existing tests; their implementation status does
not change the evidence level of a prior pilot.

## Use the skills at the relevant handoff

The repo skills live under `.agents/skills/`, Codex's repository skill location.
They can be selected for a matching request or invoked explicitly:

- `$defense-cycle-context`: resume a cycle from existing findings, the latest
  closeout, ownership, release provenance, and observed runtime capabilities.
- `$defense-cycle-closeout`: evaluate the supplied evidence per invariant,
  including coverage, independent review, cleanup, and concrete remaining gaps.

These two skills capture the handoffs that caused repeated friction in the first
round. Discovery, triage, remediation, and specialist reviews continue to use
the applicable existing security skills when the requested task calls for them.
There is no mandatory dispatch of every skill or automatic new discovery round.
The context skill routes inventory and ownership information; the closeout skill
assesses the verified-remediation evidence. Actual evidence collection follows
the existing release gate and the authorization for that operation.

## Role and transition contract

The primary agent uses this contract when preparing or resuming a bounded cycle.
It defines routing defaults, not an automatic scanner, a model capability
guarantee, or a standing authorization for future cycles. WORKFLOW remains the
authority for delegation, independent review, external writes, and landing.

| Responsibility | Preferred model/role | Required output |
| --- | --- | --- |
| Context, scope, coordination, architecture, integration, and closeout | Astra primary | Current cycle record, acceptance criteria, evidence gaps, and next permitted action |
| Bounded defensive source assessment and finding triage | Registered `daybreak_defensive`, when available with applicable access | Source-bound observations, existing-finding comparison, uncertainty, and affected acceptance criteria |
| Assessment of existing local regression evidence and patch validation | Separate Daybreak Blue assessment | Observed results tied to the tested version, covered criteria, and remaining gaps |
| Security-sensitive implementation decisions | Astra primary | Narrow remediation and its rationale |
| Independently verifiable implementation slices | Registered `implementation_worker` | Owned local diff and focused validation; model/runtime resolution follows WORKFLOW |
| Independent PR review | Registered read-only reviewer, currently Astra/high | Reviewed head, scope, findings, and coverage limits; separate from the implementation session |
| Release verification planning and evidence integration | Astra primary, with specialist assessment where useful | Feasible acceptance plan using existing operator procedures and explicit release-level evidence gaps |

These are project routing choices, not measured superiority claims. Daybreak Blue
supports defensive code review, triage, remediation, and patch validation;
availability and approved access must be checked on the actual product surface.
See [OpenAI's model guidance](https://learn.chatgpt.com/docs/cyber-safety#choose-the-right-model).
The actual registered role configuration takes precedence over a remembered model
name. A different model does not itself make a review independent.

### Transition rules

1. **Prepare before assessment.** Reuse the context skill and existing findings.
   Bind the source version, bounded scope, owner, current action grants, available
   roles, acceptance criteria, and existing local checks. Define feasible release
   acceptance evidence before implementation; do not defer testability or cleanup
   questions until production. Do not start broad discovery merely to fill a phase.
2. **Assess before assigning a fix.** Record each candidate as an observation with
   supporting source/evidence, duplicate status, uncertainty, and a proposed narrow
   remediation. Model agreement alone does not confirm a finding. Keep unsupported
   candidates out of claims of verified defects.
3. **Implement within the grant.** The primary owns security and architecture
   decisions and may delegate bounded local slices under WORKFLOW. Reuse ordinary
   regression checks. A missing feasible check remains an explicit evidence gap;
   it does not automatically justify a new harness PR.
4. **Validate and independently review.** Bind actual check results and independent
   review to the changed source version. Relevant code or test changes invalidate
   affected earlier evidence. Return actionable review findings to implementation;
   preserve failed attempts and unresolved gaps in the cycle record.
5. **Land and close at the evidenced level.** Follow WORKFLOW's current-head CI,
   review, and merge requirements. Deployment and production execution require
   their applicable separate grants. Use the closeout skill to distinguish local
   verification, merge, deployment, and observed production behavior. An unavailable
   check is `nicht sicher prüfbar`, never a pass.

The primary may dispatch the next bounded role without asking again when the
existing grant covers that action, required evidence is present, and the runtime
supports the role. This document does not implement a scheduler. The project
registers `daybreak_defensive` in `.codex/config.toml` with its model, high reasoning,
read-only sandbox, and no approval escalation in `.codex/agents/daybreak-defensive.toml`.
Record the actual role/model at dispatch; do not claim a
preferred model ran when it did not.

Before the first substantive Daybreak handoff, follow the
[runtime preflight](reviewer-runtime-preflight.md#daybreak-defensive-role): verify
the actual model and effective isolation in a fresh no-content handshake. An old
session may not expose a newly registered role. Do not substitute a generic
workspace-write agent just to obtain the requested model name.

Record two independent completion fields in every cycle: `assessment_status`
(complete/partial/blocked at the stated evidence level) and `model_plan_status`
(fulfilled/fallback/not_started). A successful Astra fallback can complete a
bounded assessment, but does not fulfill a planned Daybreak handoff. Record the
fallback reason and remaining model-validation step explicitly. A no-content
handshake is not a completed assessment. Test changes that were excluded from a
run cannot make zero harness corrections evidence of an improved correction rate.

For unavailable tooling or models, record the technical limitation and use only
a disclosed, capable fallback permitted by WORKFLOW and the current grant. A
policy refusal or missing authorization is a different condition: preserve the
checkpoint and stop that method; do not reroute it through another model, tool,
or prompt. Continue unaffected permitted work. Require a new decision for scope
expansion, unresolved security-critical uncertainty, or unapproved external actions.

### Minimal handoff record

Use the existing private cycle report and, where applicable, its single Codex
Workpad; do not create a second changing status ledger. Each transition records:

- cycle identifier, bounded scope, source/test/review versions;
- current phase, sending role and actual model, next role and bounded task;
- applicable action grant and excluded actions;
- acceptance criteria, evidence locations, actual results, and unresolved gaps;
- completion condition for the receiving role and any stop reason;
- whether the transition completed, remains pending, or requires a user decision.

Keep per-run approvals and observations here in the run record, not in SECURITY.md
or a permanent default. Skills consume this contract through WORKFLOW and this
guide; runtime role registration remains in `.codex/config.toml` and its role files.

## Improve the next round from observed results

After a round, turn confirmed system knowledge into a narrow `SECURITY.md` update,
repeated decision procedures into skill improvements, and mechanical failures
into fixes or tests in the existing harness. Keep dated incidents and model/runtime
observations in the retrospective. Keep changing status and pending external text
in the private report/workpad. Do not turn one observation into permanent proof,
a new exclusion, or an unsupported model capability promise.

Record human interruptions, elapsed time to verified evidence, and harness rework
against a named gap in the next run's existing note. These measurements support
comparison without inventing historical values or adding another tracking system.

## Ordinary local staff HTTP regressions

`StaffSettingsApiHttpTest` runs only in the fresh synthetic stack owned by
`scripts/ci/run_defense_cycle.sh`, using the existing loopback HTTP helper.
It exercises the real Admin, Secretary and Settings controller entry points:
Basic/Bearer collection and detail reads, unauthenticated challenges, public staff
response fields, and ordinary Admin/Secretary create-update persistence. Password
omission and clearing Secretary provider assignments are checked against stored
rows. Owned identities are registered before writes and cleaned with the fixture.

The six staff/settings read paths also cover absent credentials, a wrong synthetic
Admin password, a nonexistent synthetic username, and an invalid Bearer token.
Each case requires HTTP401 and a nonempty authentication challenge, with no
fixture marker or seeded staff secret values in the response body. This read
matrix does not establish invalid-write behavior or every authentication
configuration.

These tests supplement model rollback tests; they do not inject HTTP failures,
prove every concurrent schedule or verify production. Settings API token visibility
retains its existing privileged contract; staff secret-projection assertions do
not imply that every Settings value is public or that tokens are write-only.

## Ordinary blocked-period controller regressions

`BlockedPeriodPostGuardTest` covers the store, update and destroy controller
handoffs with synthetic request, authorization and model doubles. Existing
capability checks precede the POST requirement; authorized GET, HEAD, PUT and
DELETE stop before DTO creation or model calls with a 405/Allow: POST handoff.
Ordinary POST cases assert the DTO fields, exact saved payload, returned ID and
response; denied capabilities and model failures cannot report success.
These controller tests do not prove real HTTP headers, authentication, CSRF,
database persistence, date validation or production behavior. The existing
blocked-period JavaScript client uses POST for all three mutations.
