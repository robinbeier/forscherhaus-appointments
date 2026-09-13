# Defense Factory: orchestration guide

This guide makes a future defense cycle repeatable and reviewable. It orchestrates
the existing release gate, operator bundle, evidence report, workpad, and runtime
preflight; it does not add a script, database, release path, or gate. It is a
coordination aid for the primary owner. The primary retains integration,
publication, merge, issue state, and production authority.

## Start with provenance

Before selecting a method or reviewer, read the latest dated defense closeout and
record its date and evidence gaps. Separately read the exact SHA of the
application installed at the target and record it. Then record the repository
base/head and the SHA-256 of the reviewed operator/tool bundle. Never collapse
these identities into one “current” SHA: repository source, installed application,
and tool bundle can differ. A closeout is historical context, not current proof.

Recheck the installed application SHA immediately before any live evidence
collection. If relevant application code, configuration, deployment provenance,
or verifier behavior used by an observation changed, its affected evidence must
be collected again. A bundle change unrelated to that observation alone does not
invalidate it; record the relevant comparison. Evidence reuse also requires that the
current release requirements explicitly still accept the same method and scope.
Reuse never weakens an existing gate, replaces an exact-head check, or promotes
isolated evidence to production evidence.

## One compact run note

Use the existing report and, where the work is Linear-backed, the existing single
`## Codex Workpad` comment. Do not create a database or a parallel tracking
system. Before collection, write one concise run note containing:

```text
Run/date:
Scope and invariants:
Prior evidence and concrete gaps:
Allowed method and explicit limits:
Expected new evidence:
Actual runtime capabilities (observed preflight result):
Responsible owner:
Cleanup/receipt plan:
Estimated wait and planned operations window:
```

The report records descriptions, statuses, coverage, gaps, exact commit, method,
environment, and cleanup outcome. Keep credentials, cookies, personal data, raw
exceptions, bearer links, and session contents out of both report and workpad.
Keep the workpad concise and retain its status, plan, validation, and blocker
milestones. A local note is not a Linear update; reread the persisted workpad or
report after every authorized external write.

## Choose the smallest existing method

Select the applicable existing checks under [WORKFLOW.md](../WORKFLOW.md#3-validate-locally).
The full local gate already includes the isolated defense-cycle gate; do not add
a duplicate run for the same unchanged result. Isolated evidence stays isolated.
For live evidence, follow [the release gate](release-gate-defense-cycle.md), using
only reviewed methods supported by the runtime and covered by the existing
authorization. A process guide grants no production permission. Reuse the report
contract and cleanup receipts rather than creating another gate or authority path.

A test-harness PR is useful only when it names a specific reusable evidence gap
that its changed harness can actually close, with a stable acceptance observation
and cleanup proof. If it cannot close that gap, document the limitation in the
run note and continue the independent work that is allowed. A green harness or
ordinary smoke pass cannot substitute for a missing boundary test.

For an independent reviewer, follow [reviewer runtime preflight](reviewer-runtime-preflight.md)
with a fresh no-diff handshake when capability is unknown. Record the runtime's
actual model, role, tools, and result. Do not promise a model capability from a
name or file. A handshake proves launch compatibility only; it is not review
evidence. If no enforced read-only reviewer is available, use a qualified human
reviewer or record the boundary as blocked.

## Boundaries during a run

Reserve a planned operations window for a long session test. Preserve persistent
receipts, the run marker, journals, and independent cleanup lease until the
foreground result and cleanup are verified. Never shorten, backdate, or otherwise
manipulate application TTL or elapsed time. The existing shared production lock
means only independent read-only or local work may run in parallel; deployment,
backup, cleanup, and other lock-dependent work wait. Do not create an automatic
job to compensate for a missing window.

Every mutating probe uses owned synthetic identities and the existing cleanup
procedure. On interruption, unknown request completion, relationship drift, or
receipt failure, leave the recovery marker and escalate to controlled recovery;
do not make an empty database or timer result look successful. Archive the
non-secret receipt with the run evidence before another activation.

Classify the outcome precisely:

- cyber-policy refusal: preserve the exact safe error and stop that method;
- platform mutation rejection: record the rejected action and authorization
  boundary;
- unavailable model/runtime capability: record the observed preflight result;
- environment or harness failure: record it as a harness/environment issue;
- application test failure: record the failing invariant and its evidence gap.

Do not retry the same refusal by switching tools or models. Continue unaffected,
permitted work; a qualified human or approved supported access path may address a
missing capability only within platform constraints. Preserve exact errors safely,
with secrets and personal data redacted from durable artifacts.

## External writes and closeout

At the existing WORKFLOW milestones, save the pending workpad or PR text locally
before an authorized documentation write, then reread the target's persisted state.
Use an ignored, private run-artifact path; do not commit pending external text.
If the platform denies the write,
retain that pending text locally, record the denial category, and continue allowed
work. Do not retry until the state and authorization are clear. A local checkpoint
never counts as a Linear update. Keep one primary external writer and avoid
approval spam; ask once, bundled by action and scope, when new authority is needed.

Close the cycle only when each required invariant has a current, exact-release
observation, complete cleanup receipt, and independent review. Otherwise retain
the concrete gap and limitation in the existing report/workpad; do not begin a
new discovery round merely to replace missing proof. Merge, deployment, and
production verification remain separate decisions under `WORKFLOW.md`.

For the next run, collect these success measures in the run note rather than
inventing historical values: number of human interruptions, elapsed time from
start to verified evidence, and harness rework required to close a named gap.
