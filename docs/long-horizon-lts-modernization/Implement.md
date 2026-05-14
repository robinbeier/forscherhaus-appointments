# Implement: Execution Instructions

## Required Reading Before Work

At the start of every work session:

1. Read `Prompt.md`.
2. Read `Plan.md`.
3. Read `Documentation.md`.
4. Check `git status --short --branch`.
5. Identify the current milestone and only work inside that milestone unless the user explicitly changes scope.

## Work Loop

For each milestone:

1. State the milestone being worked.
2. Gather current facts with non-destructive commands.
3. Make the smallest scoped change set that satisfies the milestone.
4. Run the milestone validations from `Plan.md`.
5. If validation fails, fix the failure before proceeding.
6. Update `Documentation.md` with:
   - status,
   - decisions,
   - files changed,
   - validation commands and results,
   - blockers or follow-ups.
7. Stop at the milestone boundary unless the next milestone is explicitly in scope.

## Safety Rules

- Do not mutate production unless the user explicitly asks for that production step.
- Production SSH commands should be read-only unless explicitly approved for a deployment or migration action.
- Never print or commit secrets, push URLs, notification credentials, private keys, `config.php`, Kuma database contents, or full environment dumps.
- Keep Kuma runtime state out of Git.
- Keep host-local config out of Git; commit templates and documented variable names only.
- Prefer artifact-based deployment documentation over ad hoc server edits.
- Do not convert MariaDB to MySQL in this task.
- Do not add external PHP package repositories unless the user explicitly changes the runtime policy.

## Validation Defaults

Use the narrowest validation that proves the current change. Before considering a milestone complete, run the milestone validations listed in `Plan.md`.

Common repo commands:

```bash
docker compose run --rm php-fpm composer test
docker compose run --rm php-fpm composer deptrac:analyze
bash ./scripts/ci/pre_pr_quick.sh
PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh
```

Common release-gate commands:

```bash
./build_release.sh
php scripts/release-gate/validate_release_artifact.php --archive=/absolute/path/to/archive
php scripts/release-gate/zero_surprise_replay.php \
  --release-id=ea_YYYYMMDD_HHMM \
  --dump-file=/absolute/path/to/easyappointments.sql.gz \
  --credentials-file=/etc/fh/zero-surprise-predeploy.ini \
  --profile=school-day-default
```

Common npm surfaces:

```bash
npm outdated --json --include=dev
npm run build
npm run lint:js
```

Run npm checks in the relevant directory: repo root, `pdf-renderer`, or `tools/symphony`.

## Production SSH Guidance

Allowed without explicit deployment intent:

- version checks,
- service status checks,
- disk and memory checks,
- file/path existence checks,
- Docker inspect without environment values,
- listing monitor names and job names without secrets.

Avoid unless explicitly requested:

- package installs/upgrades,
- service restarts,
- database dumps,
- Docker container recreation,
- writing files,
- reading secret files,
- dumping env vars,
- printing crontabs if they may include tokens.

## Documentation Discipline

`Documentation.md` is the live audit log. Update it after every meaningful step. Use short entries with:

- UTC timestamp,
- milestone,
- summary,
- validation result,
- next action.

When a decision is made, add it to the decisions table and include the reason. When a blocker is found, add it to known issues with the exact command or evidence that revealed it.

## Completion Rule

Do not mark a milestone complete unless:

- all listed deliverables exist,
- all required validations passed or have documented user-approved exceptions,
- `Documentation.md` has been updated,
- the diff is scoped to the milestone.
