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

## Build

Build from a clean, validated repository checkout:

```bash
./build_release.sh --rel ea_YYYYMMDD_HHMM --project "$PWD" --skip-upload
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

`deploy_ea.sh` performs these safety checks before switching traffic:

- archive exists and contains required release files
- production `config.php` exists in the live app directory
- host deploy script byte-matches the deploy script inside the archive
- zero-surprise breakglass policy is valid when any gate is bypassed
- staged runtime config is generated for isolated predeploy replay
- zero-surprise predeploy restore-dump replay passes
- generated predeploy report validates
- renderer dependency lockfile exists before switch

After the atomic switch, `deploy_ea.sh` verifies:

- PDF renderer service restart
- renderer health endpoint
- app deep-health contract
- zero-surprise live canary

Any post-switch failure triggers automatic rollback to the previous app path.

## Rollback Model

During a successful deploy, the old app directory is moved to:

```text
/var/www/html/easyappointments_prev_<REL>
```

If post-switch validation fails, the failed release is moved aside and the
previous app directory is restored. The deploy exits with:

- `0` when deployment succeeds
- `30` when deployment failed and automatic rollback succeeded
- `31` when deployment failed and rollback failed or could not be verified

For the long-horizon LTS migration project, the old production server remains a
separate rollback target until the new server has been rehearsed and explicitly
accepted.

## Production Server Rebuild Target

For a fresh Ubuntu LTS server, keep this deployment model:

1. Provision OS packages, PHP-FPM, Apache, MariaDB client access, Node 24,
   Composer, Docker, and the PDF renderer service.
2. Restore or migrate the application database separately.
3. Upload a release archive to `/root/releases`.
4. Place host-local secrets and credentials under `/etc/fh`.
5. Run `deploy_ea.sh` with zero-surprise predeploy and canary enabled.
6. Keep the old server available until production checks and Uptime Kuma
   monitors are green after cutover.

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
