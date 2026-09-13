---
name: defense-cycle-closeout
description: Assess supplied and persisted Defense Factory verification evidence with an invariant matrix and explicit release-level gaps.
---

# Defense cycle closeout

Use this skill to consume an existing verification report, run evidence, or
persisted closeout for a bounded Defense Factory cycle. It assesses evidence; it
does not run production probes, initiate a scan, or create an offensive
reproduction.

Read [SECURITY.md](../../../SECURITY.md), the [defense release
gate](../../../docs/release-gate-defense-cycle.md), and [WORKFLOW.md](../../../WORKFLOW.md).
Read a dated retrospective only when needed to interpret historical evidence.
Use existing operator docs for mechanics and preserve their lock and cleanup
contracts.

For every requested invariant, maintain a matrix with these fields:

`invariant | commit | environment | method | expected | observed | coverage |
cleanup | status | concrete gap | provenance`

The commit must identify the exact release under test. Distinguish source,
locally isolated, merged, deployed, and independently production-verified
evidence. A missing, failed, unsafe, or unexecutable check is not passed.
Keep failed and unsafe runs by run identity; a later success does not erase them.
Reuse unchanged relevant evidence only with recorded provenance, release/method/
scope compatibility, and the current requirement's acceptance. Never promote
isolated or source evidence to production coverage.

For each row, require an observed result and a complete cleanup receipt for any
mutating verification; mark cleanup not applicable for read-only/source evidence.
Treat an incomplete cleanup, unresolved marker, unknown request completion, missing
journal, or relationship drift as a gap requiring controlled recovery. Preserve
the existing shared production lock and current operations restrictions. A real
two-hour wait is a planned evidence operation only when the invariant requires
it; record its window and status, and do not replace production proof with a
shortened or backdated timer. Deployment, backup, retention, and other work
requiring the same lock wait; independent local/read-only work may continue.

The closeout can conclude only when every required invariant has current exact-
release evidence, complete cleanup where applicable, and the required independent review. Report
remaining gaps precisely, including unavailable runtime, authorization denial,
environment failure, and application failure as distinct categories. Merge,
local testing, deployment, and production verification are separate evidence and
decision gates.

Use the existing report and, where applicable, the single persisted `## Codex
Workpad` comment. A locally saved pending update can preserve text after an
external write denial, but it is not a persisted external update. Continue
unaffected permitted work and retain the precise denial category. Do not make
production changes, issue changes, or publication decisions from this skill.

Route durable learning by kind: system knowledge to [SECURITY.md](../../../SECURITY.md),
procedure to this skill, mechanics to existing operator docs/tests, history to a
dated retrospective, and changing live state to the private report/workpad.
