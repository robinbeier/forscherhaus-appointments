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
| Evidence format, test mechanics, shared operations lock, recovery, and cleanup | [Defense release gate](release-gate-defense-cycle.md) and linked operator docs |
| What happened in a particular round and why | [Dated first-cycle retrospective](retrospectives/defense-factory-2026-09-13.md) |
| Current release identities, per-run results, open gaps, pending updates | Existing private evidence report and, where applicable, the single `## Codex Workpad` |

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
