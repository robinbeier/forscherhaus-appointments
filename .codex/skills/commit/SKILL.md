---
name: commit
description: Create a repo-conformant git commit from the current worktree, including a
    short imperative subject, rationale, and the validation that was run.
---

# Commit

Use this skill when Symphony must finalize local work before a push, PR update,
or merge handoff.

## Goals

-   Stage exactly the intended files for the current issue.
-   Write a short imperative commit subject that matches this repo.
-   Record what changed, why, and which checks were run.

## Steps

1. Inspect `git status`, `git diff`, and `git diff --staged`.
2. Stage only the intended scope. Do not pick up unrelated dirty files.
3. Sanity-check newly added files before committing.
4. Write a concise subject in imperative mood.
5. In the body, include:
    - `Summary:` with the key changes
    - `Rationale:` with the reason or trade-off
    - `Tests:` with the exact commands run, or `not run (reason)`
6. Use `git commit -F <file>` or a here-doc so line breaks are literal.

## Repo Rules

-   Keep the message consistent with [AGENTS.md](../../../AGENTS.md).
-   If frontend source files changed, include rebuilt artifacts when required.
-   If tests were intentionally deferred, say so explicitly in the commit body.
-   Do not commit logs, tmp files, or unrelated generated output.

## Template

```text
<short imperative subject>

Summary:
- <change>
- <change>

Rationale:
- <why>

Tests:
- <command or "not run (reason)">
```
