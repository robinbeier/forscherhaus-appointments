# Production Kuma Push Runtime V1

ROB-489 removes the privileged monitoring execution path from the mutable app
release tree. The nine root cron entrypoints run only from the immutable,
versioned runtime at:

```text
/usr/local/libexec/fh-kuma-push-runtime-v1
```

The runtime changes code provenance, not monitor behavior. Push URLs, tokens,
host-local environment values, monitor identities, schedules, log targets and
the two-per-minute app-log cadence remain unchanged.

## Closed bundle

`scripts/ops/config/kuma_push_runtime_bundle_v1.json` is the canonical source
manifest. It binds exactly:

- nine `scripts/ops/kuma_push_*.sh` entrypoints;
- `lib/kuma_push_common.sh` and `lib/app_log_classification.sh`;
- the dashboard PDF gate and its three PHP libraries.

Every manifest row fixes source path, identical relative installation path,
role and SHA-256. Installed files must be regular, `root:root`, mode `0555`,
Single-Link and beneath root-owned ancestors with no group/world write bit.
Symlinked, hard-linked, hash-divergent, extra or missing files fail closed.

The PDF entrypoint executes its bundled dashboard gate. It may read application
configuration and write the existing report beneath the app storage path, but
it does not execute PHP or shell code from the app release tree.

`KUMA_PDF_EXPORT_APP_ROOT` is the explicit optional app-data/config root. The
historical `KUMA_PDF_EXPORT_REPO_ROOT` name remains a backwards-compatible
fallback, so existing host-local values do not change during migration. Neither
variable selects executable gate code.

## Cron contract

The `/etc/cron.d` desired state is
`scripts/ops/config/fh-uptime-kuma-push.cron`. It contains ten invocations of
the nine entrypoints; only `kuma_push_app_logs.sh` occurs twice. Migration may
replace only the exact old prefix
`/var/www/html/easyappointments/scripts/ops/` with the fixed runtime prefix.
All other bytes, including schedules, env-file path and log targets, are fixed.

Production preflight requires the complete current cron file to equal either
the canonical legacy form or the canonical migrated form. Mixed roots,
unknown entrypoints, a different count, a caller-supplied path, or any other
byte drift blocks before mutation.

## Installer and rollback

`scripts/ops/libexec/kuma_push_runtime_v1.py` is dry-run by default. The live
wrapper is also plan-only by default and is pinned to the production Tailscale
target:

```bash
bash scripts/ops/prod_kuma_push_runtime_v1.sh
```

After merge, bind the live call to the exact clean `origin/main` commit:

```bash
bash scripts/ops/prod_kuma_push_runtime_v1.sh \
  --execute \
  --confirm-live-write ROB-489 \
  --expected-commit 40_HEX_MERGE_COMMIT
```

Before its first SSH call, the wrapper requires the expected commit to equal
local `HEAD`, the local `refs/remotes/origin/main` commit and the live
`refs/heads/main` value returned by `git ls-remote origin`. A stale local main,
an unmerged feature head or live remote drift is therefore a hard stop.

Execute stages only the manifest, helper and closed artifact list under a
private root directory. The helper verifies source ownership, modes, links and
hashes; publishes the runtime directory atomically with a Linux no-replace
rename; records the original cron bytes plus a secret-free recovery manifest
under `/var/lib/fh-kuma-push-runtime-v1`; and atomically migrates the cron file.
The wrapper deletes its private staging directory only after a known successful
result.

The helper records successful atomic cron publication before the fallible
directory durability sync. If that sync, any later check or postflight fails
after a new runtime publication, rollback first restores and durably syncs the
exact prior cron bytes and only then removes the newly published verified
runtime. Recovery records remain root-only. An unknown transport result is
never retried: retain the staging directory and perform read-only inventory
before requesting a separate recovery decision.

## Supported inspect, skip and fail states

- `pass`, legacy cron, runtime absent: execution is ready for the one bounded
  installation and migration.
- `pass`, migrated cron, exact runtime present: already converged; execute is
  idempotent and does not rewrite either object.
- Missing host prerequisites are not skips. Missing Python, `renameat2`, fixed
  directories, cron file, or required root authority makes the host profile
  unsupported and fails closed.
- Identity, hash, link, mode, owner, path, count, byte, recovery or rollback
  drift is a real contract failure, never a simulated success.

## Production postflight

After a known successful migration:

1. Re-run the helper read-only from the exact merged staged source before it is
   removed, or independently verify every installed hash against the manifest.
2. Verify the runtime and all files are root-owned, non-linked as specified and
   not group/world writable.
3. Verify the cron file is `root:root`, mode `0644`, Single-Link, matches the
   desired hash and contains exactly nine entrypoints / ten invocations with no
   app-release executable path.
4. Run exactly one already-existing Push entrypoint from the fixed runtime with
   the unchanged protected env file. Never print its URL or token.
5. Run `prod_doctor.sh`, `prod_logs_summary.sh` and
   `prod_validate_after_change.sh`; verify all existing monitors remain green.

This migration does not authorize a production deploy, new monitor, retention
execution, deletion, or any timer/service/monitor activation.
