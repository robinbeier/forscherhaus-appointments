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
Immediately before and after every write, the helper reads the target PR
through GitHub REST and requires an open PR into `main` whose base and head
repositories are canonical and whose head SHA equals the local checkout's
exact `HEAD`. Success is reported only if both checks pass. A caller-supplied
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
rejected before success is reported. Preflight target drift is rejected before
the write; drift detected by the postflight check rejects success after the
requested metadata write may already have occurred. GitHub's unsafe REST writes
used here do not provide an atomic head compare-and-swap precondition, so this
boundary must not be described as atomic mutation rejection.

PR metadata never grants landing authority. In particular, authority-bearing
review comments remain bound to their explicit reviewed SHA and are accepted
only when the independent exact-head mergegate revalidates them against the
current PR head. A concurrent head move therefore makes that evidence stale;
generic title or body metadata is never treated as review or merge evidence.
Remote response bodies and `gh` diagnostic text are never echoed. Child output
is bounded; successful output contains only a minimal operation status and safe
numeric identifier. The helper has no merge, branch-write, check-rerun, review,
or Linear operation.
