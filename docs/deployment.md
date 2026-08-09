# Deployment

This repository uses artifact-based deployment as the preferred production path.
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

## Build

Build from a clean, validated repository checkout:

```bash
./build_release.sh --rel ea_YYYYMMDD_HHMM --project "$PWD" --skip-upload
```

For local hardening or rebuild rehearsal work, keep the build on the Node 24
tooling target and disable upload explicitly:

```bash
mise x node@24 -- ./build_release.sh --rel ea_YYYYMMDD_HHMM --project "$PWD" --skip-upload
```

For the current production host upload path:

```bash
./build_release.sh --rel ea_YYYYMMDD_HHMM --project "$PWD" \
  --upload root@188.245.244.123 --remote-dir /root/releases
```

The builder:

- refreshes frontend release assets with `npm run assets:refresh`
- fails if generated frontend assets drift
- copies only release-relevant files into a temporary stage
- includes the zero-surprise Docker assets required for predeploy replay
- installs production Composer dependencies into the stage
- validates the staged tree and final archive with
  `scripts/release-gate/validate_release_artifact.php`
- verifies upload checksum and required archive entries when upload is enabled

Local release archives are written to `/tmp/<REL>.tar.gz` and include the
staged application config. Treat local archives and `/tmp/build_ea_<REL>.log`
as sensitive operator artifacts: do not commit, attach, or paste their contents,
and remove them after recording validation evidence unless they are intentionally
retained for a follow-up rehearsal.

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

When the host runs the PDF renderer as an external/containerized service and
does not install host-level Node.js, add:

```bash
--renderer-deploy-mode external
```

`deploy_ea.sh` performs these safety checks before switching traffic:

- archive exists and contains required release files
- production `config.php` exists in the live app directory
- host deploy script byte-matches the deploy script inside the archive
- zero-surprise breakglass policy is valid when any gate is bypassed
- staged runtime config is generated for isolated predeploy replay
- zero-surprise predeploy restore-dump replay passes
- generated predeploy report validates
- renderer dependency lockfile exists before switch when using the default
  host-managed renderer mode
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

## Deploy Timing Baseline

`deploy_ea.sh` emits one machine-readable line per completed timing phase and
one terminal summary. Each line starts with `DEPLOY_TIMING ` followed by a JSON
object using schema `deploy_timing.v1`. These stdout lines are observational,
not authoritative: wrappers, SSH capture, or log forwarding may duplicate them.
For a real root-run deploy, the authoritative secret-free source is the unique
`/var/log/fh-deploy-timing/<run_id>.jsonl` file. The directory is root-owned mode
`0700`; each run file is root-owned mode `0600` with one hardlink. Durations come
from a monotonic clock; production Linux reads `/proc/uptime` without starting a
subprocess. The PHP `hrtime()` fallback exists for non-Linux local rehearsal
only.

The measured end-to-end boundary starts after the deploy invocation and trusted
log stream have been accepted. It ends at success, pre-switch failure,
post-switch failure, or rollback completion. Off-host archive build and upload
are outside this host-side measurement. The stable deploy phases are:

| Phase | Included work |
| --- | --- |
| `preparation_artifact` | Archive checks, prerequisites, staged extraction, artifact validation, and host/stage deploy-script drift check. |
| `predeploy` | Breakglass validation when applicable, isolated stage runtime preparation, zero-surprise replay, and report validation. |
| `permissions_stage` | Live config/storage staging, renderer dependency preparation, final generic permissions, and fail-closed stage/live runtime-config contracts. |
| `switch` | Atomic live-to-previous and stage-to-live directory switch. |
| `postdeploy_validation` | Active/previous permission checks, renderer restart/health, deep health, live canary, release marker, reloads, and the non-blocking localhost check. |
| `rollback` | Automatic or manual rollback switch, permission contracts, renderer recovery, and health validation. This phase is recorded only when rollback runs. |

Every record contains the same random UUIDv4 `run_id` and a sequence starting at
`1`. Phase events contain only fixed schema/sequence/mode/phase/status values,
monotonic `duration_ms`/`elapsed_ms`, and the `dry_run` boolean. Summary events
add only a fixed outcome, numeric exit code, and `total_ms`. They deliberately
omit release IDs, URLs, paths, credentials, config contents, customer data, and
free-form error text. Validate the authoritative file before baseline use:

```bash
php scripts/ops/validate_deploy_timing_sample.php \
  --file=/var/log/fh-deploy-timing/<run_id>.jsonl
```

The validator fails closed unless the protected source contains one run with
sequences `1` through `6`, all five successful core phases in order, and exactly
one successful summary. Missing, duplicate, mixed-run, out-of-order, dry-run, or
unexpected-field records invalidate the complete sample. Do not reconstruct or
deduplicate a sample from stdout or the surrounding sensitive operator log.

Timing is strictly observational: clock or output-write failures disable or drop
telemetry records but never change deploy, validation, rollback, or exit status.
An unavailable or invalid authoritative source makes the run unusable for the
baseline; it does not weaken or alter deploy gates.

If the live app has already moved to the previous-release path but the staged
release cannot move into place, the `switch` phase fails and the summary outcome
is `failed_switch_recovery_required`. This distinct state is neither reported as
a pre-switch failure nor treated as a successful atomic switch; existing manual
recovery and rollback controls remain authoritative.

Baseline collection requires at least five later, successful, representative
production deploys under a separate live approval. Keep a measurement only when
all of the following are comparable:

1. schema `deploy_timing.v1`, the same renderer deploy mode, and the same enabled
   Zero-Surprise/canary gates;
2. the normal artifact-based production path on the same host class, without
   concurrent maintenance known to distort the run;
3. `dry_run=false`, outcome `succeeded`, exit code `0`, and all five core phases
   present exactly once in the documented order;
4. the root-protected authoritative JSONL passes
   `validate_deploy_timing_sample.php` without reconstruction or deduplication,
   while any rollback or failed run is kept as diagnostic evidence but excluded
   from the successful baseline set.

After five comparable samples, calculate the median `total_ms` and each phase
median, retain the individual samples and collection conditions, and review
obvious environmental outliers without silently deleting them. ROB-447 remains
blocked until that evidence exists; this instrumentation does not set an
optimization target or claim an improvement.

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

For the long-horizon LTS migration project, the old production server remains a
separate rollback target until the new server has been rehearsed and explicitly
accepted.

## Production Server Rebuild Target

For a fresh Ubuntu LTS server, keep this deployment model:

1. Provision OS packages, PHP-FPM, Apache, MariaDB client access, Composer,
   Docker, and the PDF renderer service. Host-level Node.js is not required
   when the PDF renderer runs as a container and release artifacts are built
   off-host.
2. Restore or migrate the application database separately.
3. Upload a release archive to `/root/releases`.
4. Place host-local secrets and credentials under `/etc/fh`.
5. Run `deploy_ea.sh` with zero-surprise predeploy and canary enabled.
6. Keep the old server available until production checks and Uptime Kuma
   monitors are green after cutover.

The full rebuild checklist lives in `docs/server-rebuild-runbook.md`.

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
