---
name: defense-cycle-context
description: Reconstruct and update bounded Defense Factory cycle context from existing findings and evidence before new harness work.
---

# Defense cycle context

Use this skill when a requested security cycle must resume prior work, or when
existing findings need a current scope and handoff. Build a compact context note
from persisted repository and evidence sources; do not start an automatic scan or
offensive reproduction.

Read [SECURITY.md](../../../SECURITY.md) for system context and
[WORKFLOW.md](../../../WORKFLOW.md) for procedure and authority. Use the
[defense release gate](../../../docs/release-gate-defense-cycle.md) for evidence
contracts and the latest relevant dated report for cycle state. The
[first-cycle retrospective](../../../docs/retrospectives/defense-factory-2026-09-13.md)
explains historical friction; it is not a mandatory input to every future run.

Record separately:

- repository base/head and reviewed source SHA;
- installed application/release SHA and deployment evidence;
- operator source commit and bundle SHA-256 with their provenance;
- latest closeout date, invariant statuses, failed attempts, and concrete gaps;
- requested scope, agreed coverage limits, existing findings/duplicates, and ownership;
- observed runtime, access, model/tool availability, and action grants.

Do not call one of these identities “current” for the others. Recheck any
provenance that can affect the requested observation. Reuse an old observation
only when the release requirement, method, scope, and relevant code/configuration
remain compatible, and record its source and reason. A prior closeout is context,
not proof for a changed release.

Before proposing a harness PR, name one concrete evidence gap, the smallest
feasible permitted method, its expected observation, owner, and cleanup receipt.
Use the existing checks; do not rerun an unchanged result already supplied by
the applicable gate. Follow [reviewer preflight](../../../docs/reviewer-runtime-preflight.md)
when launch capability is unknown; a no-diff handshake is not a review.
If the method is unavailable, refused, or unauthorized, preserve that exact
category and stop that method; do not switch models or tools to bypass a refusal.
Do not dispatch another model to evade a platform boundary.

Use the existing report and, where applicable, the single persisted `## Codex
Workpad` comment. A local pending note may preserve proposed text, but it is not
a persisted external update. External writes, issue ownership, and approvals
remain governed by WORKFLOW and the [authorization document](../../../docs/ticket-mutation-authorization.md).
Continue unaffected permitted work after a blocked method or external write;
preserve pending text privately and reread the persisted target before retrying.

Keep the handoff bounded: context, scope, evidence gap, feasible method, runtime
result, and next decision. Do not claim the first cycle is permanently current
or that all findings are complete.

Route durable learning by kind: system knowledge to [SECURITY.md](../../../SECURITY.md),
procedure to this skill, mechanics to existing operator docs/tests, history to a
dated retrospective, and changing live state to the private report/workpad.
