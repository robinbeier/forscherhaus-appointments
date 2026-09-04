# GitHub PR Write Transport

The machine-readable authority for this Primary-only helper is
`.codex/contracts/agent-workflow.json`. The helper exists for the narrow cases
where `gh pr edit` would require unrelated GitHub Projects permissions.

## Authority and Authentication

Only the Primary may invoke `scripts/agent/github_pr_write_transport.php`.
Authentication stays inside the native `gh` credential store. The helper never
calls `gh auth token`, exports a token, or forwards ambient token variables. It
resolves `gh` only from a fixed absolute-path allowlist, verifies the resolved
binary's ownership and mode, and starts it with a fixed `PATH` plus a small
environment allowlist. JSON request content arrives only on standard input, so
neither content nor a caller-chosen payload path appears in process arguments.

The target repository is fixed to `robinbeier/forscherhaus-appointments`.
Before every write, the helper reads the target PR through GitHub REST and
requires an open PR into `main` whose base and head repositories are canonical
and whose head SHA equals the local checkout's exact `HEAD`. A caller-supplied
repository name or PR number alone therefore grants no write authority.

The transport has exactly two operations:

```bash
php scripts/agent/github_pr_write_transport.php update-pr \
  --repo robinbeier/forscherhaus-appointments --number 123 \
  < /private/path/update-request.json

php scripts/agent/github_pr_write_transport.php create-comment \
  --repo robinbeier/forscherhaus-appointments --number 123 \
  < /private/path/comment-request.json
```

`update-pr` maps only to `PATCH /repos/{owner}/{repo}/pulls/{number}` and may
set only `title` and `body`. `create-comment` maps only to
`POST /repos/{owner}/{repo}/issues/{number}/comments` and always creates a new
comment. The bounded stdin JSON object is `{"title":"...","body":"..."}` for an
update (either field may be omitted) or exactly `{"body":"..."}` for a comment.
Callers cannot provide a payload-file option, HTTP method, endpoint, foreign
repository, or unbound PR target.

## Exact Bytes and Failure Policy

The stdin document and decoded strings are size-bounded valid UTF-8 without NUL
bytes. Content is decoded and re-encoded without trimming. In particular, a
comment body without a final line feed remains without an added line feed.
Titles must be one non-empty unterminated line. Build any request file in a
private location and verify it before redirecting it; the helper itself never
opens a caller-selected content path.

Invalid input, missing native authentication, and GitHub API failures are
rejected before success is reported. Target drift is rejected before the write.
Remote response bodies and `gh` diagnostic text are never echoed. Child output
is bounded; successful output contains only a minimal operation status and safe
numeric identifier. The helper has no merge, branch-write, check-rerun, review,
or Linear operation.
