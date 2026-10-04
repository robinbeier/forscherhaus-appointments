# Reviewer runtime preflight

Use this protocol before dispatching an independent review agent. The required
outcome is an independent review of the exact change without reviewer side
effects. Prefer technical isolation, but do not make one particular mechanism
a universal gate. This checks dispatch and evidence conditions; it does not
replace the review itself.

## Readiness check

1. Pin the exact repository base and head, and record the requested review
   scope before selecting a reviewer.
2. Inspect the current runtime's actual spawn interface and registered roles.
   Record the resolved model and reasoning effort, then inspect the same
   runtime's supported capabilities. Do not infer availability from a file
   name, role name, or another endpoint's catalog.
3. If the requested role or capability is explicitly unsupported, reject the
   dispatch before launch. If support is unknown, perform one minimal,
   no-diff startup handshake when the runtime can provide a fresh context.
   Use `fork_turns="none"` or an equivalent runtime-native fresh context for
   every probe. Supply only the self-contained readiness request, never prior
   turns, attachments, repository extracts, or diff output. If a fresh context
   cannot be established, do not probe; availability remains unknown.

The handshake may confirm only that the candidate launches under the requested
runtime. It must produce no review, finding, repository content, secret,
connector access, or mutation. A successful actual launch is compatibility
evidence; the model's self-report is not.

Permission evidence is supplied by the live runtime. It is never inferred or
granted by this document. Prompt wording that says “read-only” does not prove
tool or filesystem isolation.

## Reviewer selection and boundary

The project registers `reviewer_correctness` through
`.codex/config.toml`, with `gpt-6-astra` / `high` and `read-only` in
`.codex/agents/reviewer-correctness.toml`. This explicit binding avoids inheriting
an obsolete default model. An already running session may retain its earlier
role catalog; loading the updated project configuration in a fresh session and
checking actual launch compatibility are still necessary. App and separately
installed CLI versions can differ. A launch failure in an older CLI does not
establish that the current app lacks access. Do not update global tools or
misstate effective permissions merely to complete a review.

Select a preferred correctness and security reviewer independently of model
brand or a fixed model name. For an ordinary independent PR code review,
prefer an effectively read-only runtime whose coverage includes:

- correctness, regressions, and security;
- design and maintainability;
- tests and regression coverage.

If read-only isolation is unavailable, a controlled independent agent with
write-capable tools may review an ordinary PR only when all of these are
recorded: exact base, exact head, and exact scope; a fresh context; no
credentials or production data; no mutations, connectors, or external actions;
primary-owned Git, GitHub, Linear, and merge operations; and post-review
verification that the repository and head are unchanged plus a review of tool
activity. This exception is a practical boundary, not proof of technical
isolation, and it cannot undo an external action if one occurred.

If no authorized agent meets the coverage and boundary contract, use a
qualified independent human reviewer or leave the review pending.

For a review that needs production access, credentials, or any external action,
require a truly restricted runtime or a qualified human reviewer. Do not use
the ordinary-review exception for that work.

Automatic approval refusal remains binding. User-specific model and data
authorization remains bound to the user and requested action; a model switch
does not bypass either requirement. Prompt wording such as “read-only” does not
grant permission or prove tool isolation.

Bind the verified repository, base, head, and exact scope before the real
review. The handshake is not review evidence. Use the same ready reviewer session
for the actual review and follow-up questions where its boundary remains valid.
When the ordinary-review exception is used, record its controls and the
post-review unchanged-state and tool-activity checks instead.
Before dispatching the real review or a follow-up, revalidate the head, base,
scope, runtime, role, and capabilities. Rebind the review target after a head/base
change. If the base changed, update the branch and require fresh blocking CI for
the resulting pair before landing. A runtime reset, changed model/role/tools,
or expired reviewer session requires a fresh startup check.
The current head-only merge compare-and-swap does not atomically guard the base;
record the last observed base and this limit unless strict up-to-date protection
or an equivalent merge queue has been verified.

A runtime launch or tool failure is a harness issue, not a PR finding. Do not
repeat a known-unsupported role or weaken a review gate to obtain output.

## Daybreak defensive role

The optional `daybreak_defensive` project role pins `gpt-daybreak-blue-latest`,
high reasoning, `sandbox_mode = "read-only"`, and `approval_policy = "never"`.
It assesses bounded local source and supplied ordinary regression evidence;
the primary owns tests, implementation, connectors, and external writes.

Check the current spawn surface, not only the file. Use a fresh context with no
repository extracts for the first handshake. Capture actual session/model and
effective permission evidence. Parent runtime overrides can change child
permissions; a role file alone does not prove effective isolation. Also check
that mutating connector tools are unavailable or denied; filesystem read-only
alone does not constrain remote actions.

If a running app session has a stale role catalog, a fresh supported runtime may
load the role. Record the surface and configuration used. A separately launched
read-only CLI assessment is a disclosed alternate surface, not proof that app
subagent dispatch worked. It must preserve context, tool, credential, network,
and approval boundaries, and is never a route around a refused method.
If model support cannot be established, leave Daybreak pending; an allowed
fallback must not mark the model plan fulfilled. If effective read-only
isolation cannot be established, use only the controlled ordinary code-review
path above and record the actual write-capable runtime. Never change global
access or managed policy to make a launch succeed.

## Short readiness request

```text
Start a no-diff compatibility handshake only.
Context: fork_turns="none" or equivalent fresh runtime context; no inherited turns.
Runtime: <runtime identifier>; resolved model/effort: <values>.
Role: <registered read-only role>; repository: <path or identifier>.
Confirm only that this role launches with the runtime's supplied read tools.
Do not inspect repository content, secrets, connectors, or Git state.
Do not report findings, modify files, or perform any external action.
Return only a readiness acknowledgement; do not claim verified permissions.
The primary classifies launched/unsupported/unknown from the runtime result.
```

## Reviewer handoff

```text
Review target: <repository>
Base: <exact ref and commit>
Head: <exact ref and commit>
Scope: <files/modules and requested questions>
Reviewer: <registered role>; resolved model/effort: <values>
Available read tools: <runtime-supplied list>
Boundary: preferred no mutation, credentials, connectors, Git writes, merge, or publish;
if ordinary-review exception applies, record its controls and post-review checks
Runtime preflight: <compatibility evidence and timestamp>

Return an independent final review result covering correctness/security,
design/maintainability, and tests/regressions. Separate confirmed findings
from hypotheses and report harness failures as harness failures.
```

Before handoff, note the exact base/head SHA, reviewed scope, reviewer, and UTC
capture time. The primary records that result alongside separate CI, comment,
and landing facts in the [final-head evidence](../WORKFLOW.md#final-head-evidence)
format. Mark a bot completion for an older head as `stale`, rather than using
it as proof for the current head. Revalidate the exact base/head and scope
before recording the result and again immediately before landing; a changed
base requires review of the affected diff even when the PR head is unchanged.
