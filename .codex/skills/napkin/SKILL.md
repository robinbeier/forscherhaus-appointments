---
name: napkin
description: Maintain the repo-local napkin in `.claude/napkin.md` as a compact,
    continuously curated runbook for future Symphony worker turns.
---

# Napkin

Use this repo-local wrapper so Symphony workers can resolve the napkin skill
inside fresh issue worktrees without depending on the operator's global skill
path.

## Required Behavior

1. Read `.claude/napkin.md` before substantive work.
2. Apply the guidance silently during the run.
3. Curate the napkin when you learn something reusable.
4. Keep it as a runbook, not a session log.

## Curation Rules

-   Re-prioritize by execution risk first.
-   Keep only recurring, high-signal guidance.
-   Merge duplicates and remove stale notes.
-   Keep category caps tight.
-   Every entry must include a concrete `Do instead:` action.

## Worker Notes

-   Prefer the repo-local `.claude/napkin.md` over generic advice.
-   If the napkin is missing, create it in the established repo format.
-   Do not duplicate long command output or ticket history there.
