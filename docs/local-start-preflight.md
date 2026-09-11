# Read-only local start preflight

For agent worktrees, run this before setup, branch synchronization, local CI
gates or publication:

```bash
python3 scripts/ci/start_preflight.py
```

The command inspects the local checkout and prerequisites without modifying
Git, Docker, Linear or GitHub. It never fetches, pushes, creates a worktree,
installs hooks, builds/pulls images, starts services or creates networks/volumes.

Resolve each prerequisite before its affected operation, not before the operation
that establishes it. On a fresh clone, a missing managed hook is a commit blocker;
run the normal authorized `./scripts/setup-worktree.sh` to install it, then rerun
the preflight. Do not commit until the managed executable hook is confirmed.
An existing custom hook still requires the documented hook-installation workflow;
the preflight does not authorize overwriting it.
Run `./scripts/setup-worktree.sh` and the existing CI helpers only after resolving
the relevant prerequisites through the normal runtime approval path.

## Runtime inputs are declarations, not permissions

Supply each writable root from the active runtime with `--writable-root` and
any nested read-only exceptions with `--read-only-root`. Read-only exclusions
take precedence, including a read-only `.git` inside an otherwise writable repository.
Do not infer the roots from a successful read, Unix mode bits, `$PWD`, or a previous
session. Paths are resolved before checking containment so a symlink cannot
make an outside Git common directory appear inside a declared root.

Set `--network-policy` to the runtime's actual `restricted`, `allowed`, or
`unknown` value. Omission leaves it unknown. Neither this option nor any writable
root grants access. The actual tool runtime can still require approval for a
specific Git, Docker or network command. A declared policy and filesystem
accessibility are separate observations.

`--base-ref` defaults to the local `origin/main` reference. This is a local
snapshot, not proof that the remote has no newer commits. The preflight does
not refresh it; use the normal authorized fetch/sync workflow afterward.

Git metadata and writable bind mounts are checked conservatively as subtrees:
a declared read-only descendant also blocks a whole-tree readiness claim. The
report does not guess whether a particular commit will need that descendant.

## Docker scope

Daemon probes require a confirmed local Unix/named-pipe Docker endpoint. A
remote or unresolved context is reported as blocked and is not contacted; select
the intended local context through the normal operator workflow.

This mode describes the local CI helper stack, not the normal Quickstart
`docker compose up -d` operation. It does not certify that ordinary project's
containers, published ports or all-service image requirements. Use it with the
local pre-PR gate workflow described here.

The default planned services are `php-fpm`, `mysql` and `nginx`. Repeat `--service`
to select the services for the planned operation, including `openldap` for LDAP
gates. The preflight uses the local CI helper's worktree-specific project name
and portless Compose configuration by default. Explicit
`CI_DOCKER_COMPOSE_PROJECT_NAME` and supported Compose overrides must match the
subsequent gate. `EA_LOCAL_CI_PORTLESS_COMPOSE=0` selects the ordinary published-port
configuration. Portless configuration does not remove ports from optional
services that still publish them.

Compose configuration is captured for inspection but never printed: it may
contain passwords or other sensitive environment values. Image/resource names,
connection errors and command output must not become raw logs in the report.
A missing build image, pull image, internal network or internal volume is an
environment prerequisite; a missing declared external resource requires separate
preparation. Existing internal networks and volumes must carry the expected
Compose project and resource labels; missing or conflicting labels block reuse.
Declared external resources do not require Compose ownership labels. An invalid
inspection response remains unknown. None is evidence of an application regression.

The preflight does not reserve ports, allocate a subnet or create a trial
container. Visible conflicts can be reported, but resource creation capacity
and future availability cannot be guaranteed by read-only inspection. Keep
unobservable address-pool exhaustion or capacity unknown rather than treating
absence of an error as proof that creation will succeed.

## GitHub and external operations

No external network probe runs by default. `--probe-github` enables a bounded,
read-only GitHub API availability check using the existing CLI authentication.
It does not print account data, tokens or raw errors and does not refresh login.
A successful read does not prove push or merge permission, nor does it authorize
an external mutation.

Fetch needs network and Git metadata writes; commit needs worktree/Git writes
and the existing hooks; tests need their normal local files, Docker resources
and any missing dependency downloads; push and PR operations additionally need
the appropriate remote permissions. Use only the concrete missing categories
reported for the planned operation. Never turn them into a blanket persistent
authorization request.

## Using the result

Use `--json` for structured output. Exit `1` means an observed blocked
prerequisite, `2` means invalid arguments, and `0` means no blocked prerequisite
was observed. Exit `0` still retains unknown permissions and allocation capacity;
it is never a blanket readiness or authorization guarantee. Keep observed readiness, blocked prerequisites
and unknown capabilities distinct. Resolve failures before the affected action;
do not disable hooks, weaken CI, edit permissions, or bypass the sandbox to make
the report green. Re-run after the environment or runtime boundary changes.

The start check supplements the existing workflow. It does not replace
`pre_pr_full.sh`, independent review, current blocking CI, or the final
SHA-bound merge checks in [WORKFLOW.md](../WORKFLOW.md).
