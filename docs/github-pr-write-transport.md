# GitHub PR Write Transport

The machine-readable authority for this Primary-only helper is
`.codex/contracts/agent-workflow.json`. The helper exists for the narrow cases
where `gh pr edit` would require unrelated GitHub Projects permissions.

## Authority and Authentication

Only the Primary may invoke `scripts/agent/github_pr_write_transport.php`.
Authentication stays inside the native `gh` credential store. The helper never
calls `gh auth token`, exports a token, or forwards ambient token variables. It
starts `gh` with a small environment allowlist and sends the JSON request on
standard input, so request content is absent from process arguments.

The transport has exactly two operations:

```bash
php scripts/agent/github_pr_write_transport.php update-pr \
  --repo owner/repository --number 123 \
  --title-file /private/path/title.txt --body-file /private/path/body.md

php scripts/agent/github_pr_write_transport.php create-comment \
  --repo owner/repository --number 123 --body-file /private/path/comment.md
```

`update-pr` maps only to `PATCH /repos/{owner}/{repo}/pulls/{number}` and may
set only `title` and `body`. `create-comment` maps only to
`POST /repos/{owner}/{repo}/issues/{number}/comments` and always creates a new
comment. Callers cannot provide an HTTP method or endpoint.

## Exact Bytes and Failure Policy

Input files are bounded readable regular files containing valid UTF-8 without
NUL bytes. Their content is read without trimming. In particular, a comment
file without a final line feed produces a comment body without an added line
feed. Titles must be one non-empty unterminated line.

Invalid input, missing native authentication, and GitHub API failures are
rejected before success is reported. Remote response bodies and `gh` diagnostic
text are never echoed. Output contains only a minimal operation status and safe
numeric identifier. The helper has no merge, branch-write, check-rerun, review,
or Linear operation.
