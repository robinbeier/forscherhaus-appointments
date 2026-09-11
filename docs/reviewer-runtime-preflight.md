# Reviewer runtime preflight

Use this protocol before dispatching an independent review agent. It checks
that the reviewer can actually start and remain within the intended boundary;
it does not replace the review itself.

## Readiness check

1. Pin the exact repository base and head, and record the requested review
   scope before selecting a reviewer.
2. Inspect the current runtime's actual spawn interface and registered roles.
   Record the resolved model and reasoning effort, then inspect the same
   runtime's supported capabilities. Do not infer availability from a file
   name, role name, or another endpoint's catalog.
3. If the requested role or capability is explicitly unsupported, reject the
   dispatch before launch. If support is unknown, perform one minimal,
   no-diff startup handshake in the candidate read-only role.

The handshake may confirm only that the candidate launches under the requested
runtime. It must produce no review, finding, repository content, secret,
connector access, or mutation. A successful actual launch is compatibility
evidence; the model's self-report is not.

Permission evidence is supplied by the live runtime. It is never inferred or
granted by this document. Prompt wording that says “read-only” does not prove
tool or filesystem isolation.

## Reviewer selection and boundary

Select a preferred correctness and security reviewer independently of model
brand or a fixed model name. If it is unavailable, use an equally qualified
available reviewer in an enforced read-only runtime whose coverage includes:

- correctness, regressions, and security;
- design and maintainability;
- tests and regression coverage.

If no agent meets that contract, use a qualified independent human reviewer.

Fallbacks must preserve the effective filesystem, tool, connector, credential,
Git, and merge boundaries. If read-only isolation is unavailable or unknown,
do not dispatch an agent fallback: use a human reviewer or block.

Bind the verified repository, base, head, and exact scope before the real
review. The handshake is not review evidence. Use the same ready reviewer session
for the actual review and follow-up questions; preserve its enforced boundary. Before dispatching the real review or a follow-up,
revalidate the head, base, scope, runtime, role, and capabilities. Rebind the review target after a head/base change. A runtime reset, changed
model/role/tools or expired reviewer session requires a fresh startup check.

A runtime launch or tool failure is a harness issue, not a PR finding. Do not
repeat a known-unsupported role or weaken a review gate to obtain output.

## Short readiness request

```text
Start a no-diff compatibility handshake only.
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
Boundary: no mutation, credentials, connectors, Git writes, merge, or publish
Runtime preflight: <compatibility evidence and timestamp>

Return an independent final review result covering correctness/security,
design/maintainability, and tests/regressions. Separate confirmed findings
from hypotheses and report harness failures as harness failures.
```
