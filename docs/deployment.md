# Deployment

This repository uses artifact-based deployment as the preferred production path.
`build_release.sh` creates the production release archive. `npm run build` only
compiles frontend assets and does not create a separate ZIP release.
Do not deploy production by editing files in-place or by turning the production
application directory into the Git checkout.

The intended flow is:

```text
repo checkout -> release archive -> upload -> staged extract -> predeploy gates -> atomic switch -> postdeploy gates -> rollback if needed
```

## Responsibilities

- `build_release.sh` builds and optionally uploads a release archive.
- `deploy_ea.sh` runs the host-side deployment from an uploaded archive.
- `docs/release-gate-zero-surprise.md` is the source of truth for predeploy
  replay, live canary, breakglass, and incident webhook behavior.
- `docs/release-gate-dashboard.md` and
  `docs/release-gate-booking-confirmation-pdf.md` document the lower-level gate
  checks used by the zero-surprise flow.
- `docs/release-gate-provider-ui-smoke.md` documents the separately authorized,
  on-demand postdeploy browser smoke with a synthetic provider lease.

Current rollout note: when no deployment is active, update the host runner and
`deploy_ea.sh` as one coordinated rollout. No compatibility promise is made for
old timing bundles.

## Build

Build from a clean, validated repository checkout:

```bash
./build_release.sh --rel ea_YYYYMMDD_HHMM --expected-commit "$(git rev-parse HEAD)" --project "$PWD" --skip-upload
```

For local hardening or rebuild rehearsal work, keep the build on the Node 24
tooling target and disable upload explicitly:

```bash
mise x node@24 -- ./build_release.sh --rel ea_YYYYMMDD_HHMM --expected-commit "$(git rev-parse HEAD)" --project "$PWD" --skip-upload
```

For the current production host upload path:

```bash
./build_release.sh --rel ea_YYYYMMDD_HHMM --expected-commit "$(git rev-parse HEAD)" --project "$PWD" \
  --upload root@188.245.244.123 --remote-dir /root/releases
```

The builder:

- refreshes frontend release assets with `npm run build`
- fails if generated frontend assets drift
- derives the exhaustive generated-runtime manifest from the exact committed
  JS/SCSS source tree and the closed vendor-output contract, then copies and
  validates every listed CSS/JS/vendor file in a temporary stage
- includes the zero-surprise Docker assets required for predeploy replay
- installs production Composer dependencies into the stage
- validates the staged tree and final archive with
  `scripts/release-gate/validate_release_artifact.php`
- when upload is enabled, verifies the uploaded archive and provenance files
  (including size and SHA-256) before publishing them without replacing existing
  release files

Local release archives and provenance sidecars are written below the randomized
`/tmp/<REL>.output.XXXXXX/` directory and include the staged application config.
Treat that output directory and `/tmp/build_ea_<REL>.log` as sensitive operator
artifacts: do not commit, attach, or paste their contents, and remove them after
recording validation evidence unless they are intentionally retained for a
follow-up rehearsal.

## Deploy

Run deploys from the production host, using the uploaded archive:

```bash
/root/deploy_ea.sh \
  --rel ea_YYYYMMDD_HHMM \
  --healthz-token-file /etc/fh/healthz.token \
  --zero-surprise-dump-file /path/to/easyappointments.sql.gz \
  --zero-surprise-predeploy-credentials-file /etc/fh/zero-surprise-predeploy.ini \
  --zero-surprise-canary-credentials-file /etc/fh/zero-surprise-canary.ini \
  --zero-surprise-incident-webhook-file /etc/fh/zero-surprise-incident.ini
```

Production uses the Docker-backed `fh-pdf-renderer` service. Deployment
restarts that service and checks renderer and application health; it does not
install Node/npm packages or create Puppeteer caches on the host. No renderer
mode or state-directory option is needed. Remove the former
`--renderer-deploy-mode external` and `--renderer-state-dir` options from saved
commands when updating deployment tools. New runner execution inputs omit
`renderer_deploy_mode`. Before upgrading installed tools, complete/reconcile old
runs with the old matching toolset and move completed runs out of the active
tree using the [contract upgrade procedure](deployment-run-v1.md#one-time-upgrade-after-traffic-check-removal).
Do not replace helpers while any old run or active claim remains unresolved;
previously pinned inputs must not be reused for a new run.

`deploy_ea.sh` performs these safety checks before switching traffic:

- archive exists and contains required release files
- production `config.php` exists in the live app directory
- host deploy script byte-matches the deploy script inside the archive
- zero-surprise breakglass policy is valid when any gate is bypassed
- staged runtime config is generated for isolated predeploy replay
- zero-surprise predeploy restore-dump replay passes
- generated predeploy report validates
- stage and current live runtime config permissions satisfy the fail-closed
  contract below after every generic ownership/mode pass

The runtime config contract is deliberately narrower than the generic release
permissions: the app root is `root:root` with mode `0755`, while root
`config.php` is `root:<web-user-primary-group>` with mode `0440`. Both paths
must be non-symlinks, `config.php` must be a regular file with exactly one
hardlink, the web user must be able to read but not write it, and the web user
must not be able to replace it through a writable app root. The verification
prints only these metadata results and never reads or prints config contents.
Privileged permission and rollback modes execute only through the root-owned,
non-symlink host script (normally `/root/deploy_ea.sh`), never through code in
stage, active, previous, or failed release trees. Its ancestor chain and every
release parent must be canonical, root-controlled, and non-writable by the web
user. Hardening locks the app root before inspecting `config.php`, pins device
and inode identities across every metadata mutation, and restores prior
ownership/mode metadata only while those identities still match. Otherwise it
leaves the root-protected state in place and fails for manual intervention.
Operationally, config writers must be quiescent before this pass: changing
Unix ownership or mode cannot revoke writes through an already-open writable
descriptor.

After the atomic switch, `deploy_ea.sh` verifies:

- the active and previous release still satisfy the runtime config contract
- PDF renderer service restart
- renderer health endpoint
- app deep-health contract
- zero-surprise live canary

Any post-switch failure triggers automatic rollback to the previous app path.

Normal deploy execution exposes a stable result seam for the host-side caller:

- `0` means the deploy completed successfully.
- `30` means the deploy failed before any live switch, or a post-switch failure
  was followed by a verified successful automatic rollback.
- `31` means an attempted rollback failed or is unverifiable.
- `32` means the first atomic move completed but the second did not, so the
  partial switch requires recovery.
- `143` means SIGTERM interrupted the deploy before any live switch.

For a machine-readable result candidate, the root caller passes
`--result-file` with an exact absolute path beneath an existing canonical
root-owned mode-`0700` directory. The result leaf must not exist; stale regular
files, symlinks, hardlinks, unsafe ancestors, and noncanonical paths are hard
stops and are never normalized or overwritten. The terminal `deploy_result.v1` receipt
contains only the closed keys `schema`, `outcome`, and `exit_code`, uses the six
fixed outcome/exit bindings documented in `docs/deployment-run-v1.md`, and is
published once through file fsync, atomic no-replace publication, and
parent-directory fsync. Receipt write, fsync, publication, or final identity
failure returns abnormal exit `74`, which is not a valid receipt outcome pair.
The later Host Runner accepts bytes only after independently observing the
terminal child result and proving its exact exit/outcome match under the global
and per-run locks; it then persists the exact receipt-byte SHA-256 in durable
runner state. Missing, invalid, mismatched, exit-`74`, killed, or unknown results
remain unknown and require manual recovery without respawn. Receipt bytes and
output are not standalone verdict oracles. `--result-file` is not
available in dry-run mode. Without it, existing deploy exits are unchanged.

An otherwise unhandled failure after a completed switch enters the same
automatic rollback path before returning `30` or `31`.

Storage transfer runs `rsync` directly without a separate detail-statistics
stream or recursive file/byte counting. Every non-zero `rsync` exit remains a
pre-switch error: deployment stops before the atomic switch, and the staged
directory remains recoverable for the next normal deploy attempt.

If the live app has already moved to the previous-release path but the staged
release cannot move into place, the `switch` phase fails and the summary outcome
is `failed_switch_recovery_required`. This distinct state is neither reported as
a pre-switch failure nor treated as a successful atomic switch; existing manual
recovery and rollback controls remain authoritative.

## Rollback Model

During a successful deploy, the old app directory is moved to:

```text
/var/www/html/easyappointments_prev_<REL>
```

If post-switch validation fails, the failed release is moved aside and the
previous app directory is restored. Automatic rollback re-applies and verifies
the runtime config contract for both the restored app and the failed release
before health checks; an unverifiable permission state is a rollback failure.
The printed manual fallback command calls the same trusted host-script mode as
automatic rollback, so its directory switch and fail-closed
hardening/verification do not drift from the automatic path. The deploy exits
with:

- `0` when deployment succeeds
- `30` when deployment failed and automatic rollback succeeded
- `31` when deployment failed and rollback failed or could not be verified

The release-pair publisher prepares `/root/releases` as `root:root` mode
`0700` before upload. On a documented legacy/rebuild host where that exact
root-owned directory still has mode `0755`, `--prepare` performs the single
inode-bound migration to `0700`, fsyncs it, and revalidates the same directory.
Other owners, types, symlinks, or modes are rejected unchanged.

## Future Server Rebuild Planning

A future rebuild needs a new plan based on the server and requirements at that
point. Before destructive work, verify database, host-configuration and
[Kuma backups](uptime-kuma.md#backup-and-restore), retain protected off-host
copies, and test the recovery path. Creating a provider snapshot alone is not
proof of a tested restore. Use the [database restore rehearsal](database-migration-rehearsal.md)
and current deployment guidance when preparing that plan.

## Required Host-Local Secrets

These files are intentionally not committed:

- application `config.php`
- `/etc/fh/healthz.token`
- `/etc/fh/zero-surprise-predeploy.ini`
- `/etc/fh/zero-surprise-canary.ini`
- `/etc/fh/zero-surprise-incident.ini`
- any Uptime Kuma push monitor URLs or tokens

## Breakglass

Gate bypasses are exceptional. Any `--require-zero-surprise=0` or
`--zero-surprise-canary-enabled=0` deploy must provide a readable breakglass JSON
accepted by `deploy_ea.sh`. See `docs/release-gate-zero-surprise.md` for the
required JSON shape.
