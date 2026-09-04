# GitHub PR Write Transport

The machine-readable authority for this Primary-only helper is
`.codex/contracts/agent-workflow.json`. The helper exists for the narrow cases
where `gh pr edit` would require unrelated GitHub Projects permissions.

## Authority and Authentication

Only the Primary may invoke `scripts/agent/github_pr_write_transport.php`.
Authentication stays inside the native `gh` credential store. The helper never
calls `gh auth token`, exports a token, or forwards ambient token variables. It
resolves `gh` only from the committed absolute-path manifest, verifies its exact
resolved path, SHA-256, ownership, and mode, and starts it with a fixed `PATH`
plus a small environment allowlist. Each invocation creates a private
configuration directory containing only a link to the ownership- and
mode-validated native `hosts.yml`; it never exposes the caller's `config.yml`,
aliases, or extensions through `GH_CONFIG_DIR`. The link is removed when the
invocation ends, and the authentication file's contents are neither read nor
copied by the helper. JSON request content arrives only on standard input, so
neither content nor a caller-chosen payload path appears in process arguments.

The executable manifest is intentionally fail-closed. A GitHub CLI update or
Homebrew path change requires a reviewed repository change to the exact
resolved path and SHA-256 before this transport can run again.

The target repository is fixed to `robinbeier/forscherhaus-appointments`.
Immediately before and after every write, the helper resolves the local
checkout's exact `HEAD` and symbolic branch together from one Git porcelain-v2
status snapshot, reads the target PR through GitHub REST, and requires an open
PR into `main` whose base and head repositories are canonical and whose head SHA
and head branch equal that local target. The two local snapshots must also match
each other. After `update-pr`, the
postflight read must return every requested title/body field byte-for-byte. A
caller-supplied repository name, PR number, or SHA alone therefore grants no
write authority or successful-write result.

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

Invalid input, missing native authentication, and preflight target drift are
rejected without reporting a completed write. Once the write child has been
invoked, however, a nonzero exit or local transport failure cannot prove that
GitHub did not apply the mutation. Those outcomes enter the same nonretryable
postwrite-reconciliation path as any other uncertain result. GitHub's unsafe
REST writes used here do not provide an atomic head compare-and-swap
precondition, so this boundary must not be described as atomic mutation
rejection.

After the write invocation, the helper always performs its postflight checks
and returns exit `0` with one of two minimal statuses. `ok` means that `gh`
reported success, the independently re-resolved local SHA-and-branch target was
unchanged and confirmed remotely, and the requested PR fields or the new
comment's positive GitHub identifier were verified.
`write_completed_target_unverified` conservatively also covers a write that may
have completed: a nonzero write exit, local transport failure, local or remote
target drift, result drift, a missing stable comment identifier, or a postflight
read failure prevents confirmation. A positive comment identifier recovered
from the write response is returned as `comment_id` even when another
postflight condition is uncertain, so reconciliation can address the exact
created comment. Callers must not retry that operation: they must read the
remote PR or comment state and reconcile it before deciding on any further
write. Neither status grants landing authority.

PR metadata never grants landing authority. In particular, authority-bearing
review comments remain bound to their explicit reviewed SHA and are accepted
only when the independent exact-head mergegate revalidates them against the
current PR head. A concurrent head move therefore makes that evidence stale;
generic title or body metadata is never treated as review or merge evidence.
Sensitive remote fields and `gh` diagnostic text are never echoed. Child output
is bounded; completed-write output contains only a minimal operation status,
the safe PR number, and, when GitHub returned a valid one, the safe numeric
comment identifier. The helper has no merge, branch-write, check-rerun, review,
or Linear operation.
