# Forscherhaus Appointments

Forscherhaus school scheduling fork of Easy!Appointments.

This repository prioritizes stable, low-risk delivery for school operations and keeps compatibility with the existing Easy!Appointments architecture.

## Scope

- Fork base: Easy!Appointments (`v1.5.2` lineage)
- Stack: PHP `>=8.3.6`, CodeIgniter, MySQL, jQuery/Bootstrap/FullCalendar
- Primary goal: school-specific scheduling workflows and operational reliability

## Fork Invariants

- `services.attendants_number` is intentionally restricted to `1`.
- Do not implement multi-attendant behavior unless product scope changes explicitly.

## Quickstart (Recommended)

Prerequisites on host (required by `./scripts/setup-worktree.sh`):

- PHP `>=8.3.6`
- Composer
- Node.js `>=24.0.0` plus `npm`/`npx`
- Docker + Docker Compose

```bash
./scripts/setup-worktree.sh
docker compose up -d

# when you need deterministic LDAP fixtures for search/import/SSO work
bash ./scripts/ldap/reset_directory.sh
bash ./scripts/ldap/smoke.sh
```

Smoke-check:

```bash
docker compose run --rm php-fpm composer test
npm run build
```

`npm run build` compiles frontend assets only. Production release archives are
created with `build_release.sh`; see [Deployment](docs/deployment.md).

## Harness Guide

Need the shortest route to the right steering source?

- [Agent Harness Index](docs/agent-harness-index.md): routing across onboarding,
  agent runtime, CI, architecture, and ownership
- [WORKFLOW.md](WORKFLOW.md): agent runtime and ticket-to-merge rules
- [AGENTS.md](AGENTS.md): compact repo guardrails and entry points

## Core Commands

```bash
npm start

npm run build

npm run lint:js

PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh
```

For optional smoke tests, write-path contracts, deep runtime suites, release
gates, and CI-only signals, use [Agent Harness Index](docs/agent-harness-index.md)
as the routing map and [AGENTS.md](AGENTS.md) as the compact guardrail and entry-point hub.
The public existing-appointment write boundary is documented in
[Public Reschedule Authority](docs/security/public-reschedule-authority.md).

## Release Gates

Primary references:

- [Zero-surprise restore-dump replay + live canary](docs/release-gate-zero-surprise.md)
- [Dashboard release gate](docs/release-gate-dashboard.md)
- [Booking confirmation PDF gate](docs/release-gate-booking-confirmation-pdf.md)
- [Production Provider UI smoke](docs/release-gate-provider-ui-smoke.md)
- [Production Customers UI smoke](docs/release-gate-customers-ui-smoke.md)
- [Deployment runbook](docs/deployment.md)
- [Agent Harness Index](docs/agent-harness-index.md)

Release artifact builds should go through `./build_release.sh`. The builder now
requires `--expected-commit <full 40-hex commit>`, exports that exact clean Git
object, fails on uncommitted generated asset drift, and validates that
the exhaustive generated JS/CSS/vendor runtime manifest plus release-gate
tooling are present in both the staged tree and the final tarball.

## Local Services (Docker)

- App: `http://localhost`
- phpMyAdmin: `http://localhost:8080` (`root` / `secret`)
- Mailpit: `http://localhost:8025`
- PDF renderer: `http://localhost:3003`
- Baikal (CalDAV): `http://localhost:8100`

For deterministic LDAP fixtures, reset and smoke the local directory with:

```bash
bash ./scripts/ldap/reset_directory.sh
bash ./scripts/ldap/smoke.sh
```

If you run PHP on host with Docker PDF renderer, set:

```bash
export PDF_RENDERER_URL=http://localhost:3003
```

If the host runtime goes through Apache `mod_php`, set `PDF_RENDERER_URL` via
Apache `SetEnv` as well; PHP-FPM-only env wiring does not reach those requests.

## Worktree Safety

When using multiple git worktrees, always set a unique Docker Compose project name per worktree:

```bash
docker compose -p fh-main up -d
docker compose -p fh-hotfix up -d
```

This prevents mixed container mounts across worktrees.

## Documentation Map

- [Agent harness index](docs/agent-harness-index.md)
- [Compact guardrails and entry points](AGENTS.md)
- [Write-path CI contracts](docs/ci-write-contracts.md)
- [Architecture map](docs/architecture-map.md)
- [Ownership map](docs/ownership-map.md)
- [Deployment runbook](docs/deployment.md)
- [Docker guide](docs/docker.md)
- [Observability guide](docs/observability.md)
- [Production session retention](docs/ops/production-session-retention.md)
- [Console commands](docs/console.md)
- [REST API](docs/rest-api.md)
- [Google Calendar sync](docs/google-calendar-sync.md)
- [CalDAV sync](docs/caldav-calendar-sync.md)
- [LDAP](docs/ldap.md)
- [LDAP parallel replacement spike](docs/ldap-parallel-spike.md)
- [Provider room feature](docs/feature-provider-room.md)

## Contribution Rules

See [AGENTS.md](AGENTS.md) for repository guardrails, review expectations, and
the contributor entry path.

## Testing Before PR

Optional early focused check:

```bash
docker compose run --rm php-fpm composer test
```

Default review-ready path (the full gate includes this Composer test through
its quick-gate stage):

```bash
PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh
```

On cold local Docker stacks, `integration-smoke` may need extra Playwright
startup time. In that case, rerun the full gate with
`PRE_PR_INTEGRATION_SMOKE_BROWSER_BOOTSTRAP_TIMEOUT=600` and
`PRE_PR_INTEGRATION_SMOKE_BROWSER_OPEN_TIMEOUT=60`.

Use `bash ./scripts/ci/pre_pr_quick.sh` for source checks without rebuilding
frontend files once dependencies are installed. The full gate builds assets
once before its browser checks. The existing lockfile sync remains in both
gates: it requires committed dependency files and stops if npm's lockfile
refresh changes them. After editing frontend dependencies in `package.json`,
run `npm install`, review the updated lockfile, and commit both files. Use
`npm ci` to install an already matching committed lockfile. In normal local
development, both commands run the postinstall lifecycle and refresh assets;
production or `--omit=dev` installs skip that refresh, while `--ignore-scripts`
skips lifecycle scripts entirely, so build assets explicitly when needed.
Compiler errors fail `npm run build`, including builds started by hooks or
release preparation. `npm start` watches frontend files; after a failed
rebuild, correcting the file triggers the next attempt. Its initial build
must succeed before watching starts.

For the full optional matrix, scope-specific smokes, and rollback notes, see
[Agent Harness Index](docs/agent-harness-index.md) and [AGENTS.md](AGENTS.md).

## Local Cleanup

Local runtime artifacts can become much larger than the actual git checkout,
especially under `storage/logs/`.

Conservative cleanup that preserves local DB data in `docker/mysql/`:

```bash
bash ./scripts/cleanup_local_artifacts.sh
```

This removes everything under `storage/logs/` except the placeholder
`.htaccess` and `index.html` files, plus `build/`, `.phpunit.cache/`, and the
local `easyappointments-0.0.0.zip` artifact. That includes local CI, release,
and ops artifacts stored under `storage/logs/`. To also remove reproducible
dependency directories such as the root installs plus
`pdf-renderer/node_modules/`, opt in
explicitly only when you are fine reinstalling them afterwards:

```bash
bash ./scripts/cleanup_local_artifacts.sh --with-deps
```

### Git hooks

`./scripts/setup-worktree.sh` installs the managed `.git/hooks/pre-commit`
hook. The managed `pre-commit` runs fast formatting, syntax, and
changed-frontend checks. Node dependencies are required only for formatting or
frontend changes; the hook does not require Composer dependencies. PHP syntax
checks use a temporary container without starting the application or installing
its packages. Each host-side syntax check uses its own Compose project and
removes it afterward without deleting volumes. Pure shell changes need neither
dependency directory. The installer removes the old repository-managed
`pre-push` hook while preserving custom hooks. Run
`./scripts/install-git-hooks.sh` once in an existing clone to remove its old
managed push hook. Invoke
`bash ./scripts/ci/pre_pr_quick.sh` or
`PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh` explicitly according to
[WORKFLOW.md](WORKFLOW.md#3-validate-locally); pushing does not rerun them.
Linux root/host tests use the explicit Docker Desktop skip versus required
GitHub Actions failure contract documented in
[Linux root/host tests](docs/docker.md#linux-roothost-tests); local skips never
replace the required native-Linux CI proof.

Local checks attempt to clean up their Compose project on normal shell exit,
including failed service starts and early full-gate failures. Hard process kills
or an unavailable Docker daemon can still leave resources behind; images and
build cache are retained. This is not a cleanup of other projects.

CI note:

- [CI workflow](.github/workflows/ci.yml) is canonical for CI triggers,
  blocking jobs, and artifacts.
- [CI test execution](docs/ci-test-execution.md) covers changed-file test
  selection and job preparation.
- [Docker integration runtime](docs/docker.md#github-integration-runtime)
  describes the runner, PHP, MySQL, and browser environment.
- [Browser evidence](docs/release-gate-dashboard.md#agent-friendly-browser-evidence)
  documents integration-smoke failure diagnostics. The practical evidence
  path is `storage/logs/ci/deep-runtime-suite/integration-smoke-browser/`.

For doc-only/meta commits in constrained environments:

```bash
SKIP_PRECOMMIT=1 git commit -m "Your message"
```

## Upstream

This is a maintained fork of:

- [alextselegidis/easyappointments](https://github.com/alextselegidis/easyappointments)

Upstream merges are done selectively and scheduled according to release risk.

## License

Code licensed under [GPL v3.0](https://www.gnu.org/licenses/gpl-3.0.en.html).
Upstream project content is published under [CC BY 3.0](https://creativecommons.org/licenses/by/3.0/); keep attribution notices when redistributing modified content/docs.
See [LICENSE](LICENSE) and [NOTICE](NOTICE) for details.
