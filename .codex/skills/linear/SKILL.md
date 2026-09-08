---
name: linear
description: |
    Use available Linear tooling for issue reads, state changes, PR attachments,
    and the single persistent `## Codex Workpad` comment.
---

# Linear

Follow the canonical [state model](../../../WORKFLOW.md#linear-states),
[workpad rules](../../../WORKFLOW.md#codex-workpad), and
[primary-agent authority](../../../WORKFLOW.md#model-aware-delegation).
Use the configured Linear tools and their actual input schema; this skill does
not require a particular GraphQL transport or tool name.

## Working sequence

1. Read the issue, its current state, and current comments.
2. Find the persistent comment starting with `## Codex Workpad`. Create it
   only if none exists. If several exist, update only the newest authoritative
   workpad; do not delete other comments. If authority is unclear, leave that
   mutation pending.
3. Update the workpad in place at the points defined in `WORKFLOW.md`, including
   before implementation, publication, and state changes. Keep current evidence
   and next actions concise; do not repeat the issue title or paste raw logs.
4. Resolve the destination from the live team states and pass its exact name
   or identifier as supported by the available tool.
5. Attach the actual GitHub PR after it exists. Keep the PR URL on the issue
   attachment, not in the workpad.
6. Verify the resulting issue, comment, or attachment state. Treat reported
   errors as failures and do not infer success from a partial response. Check
   existing state before retrying an uncertain write to avoid duplicates.

If Linear access is unavailable, report the affected action as blocked and
continue independent authorized work. Never invent issue states, identifiers,
comments, attachments, or mutation results.

Request only the information needed for the current action. Keep secrets,
raw tokens, personal data, and raw logs out of normal output and workpads.
Do not add raw-token shell helpers or another ad hoc Linear transport.
