# Production Cleanup Inventory

`scripts/ops/prod_cleanup_inventory.sh` is the read-only entry point for
ROB-425-style production cleanup assessment. It prepares cleanup decisions; it
does not delete or mutate anything.

Use it after normal health checks when disk usage, file counts, or old deploy
artifacts look suspicious:

```bash
bash scripts/ops/prod_cleanup_inventory.sh
```

The script connects to the production host and emits stable key/value facts for:

- current release marker;
- root disk usage;
- old release directories under the web root;
- uploaded release archives;
- backup and restore-verification markers;
- rebuild restore-input artifacts;
- app `storage/sessions`, `storage/cache`, `storage/logs`, and
  `storage/uploads`;
- session-retention timer state and aggregate success-marker freshness, without
  exposing session names or marker bytes;
- explicit cleanup candidate classes.

## Output Boundary

The output is intentionally aggregate-only. It may show counts, size totals,
age summaries, path existence classes, and cleanup candidate classes.

It must not show:

- session filenames or contents;
- cache filenames or contents;
- raw app logs;
- backup filenames or dump contents;
- DB rows;
- `/etc/fh` contents;
- tokens, Push URLs, DSNs, health tokens, passwords, or webhook URLs;
- raw host-local config values.

## Interpreting Candidates

Candidate classes are decision aids, not deletion commands.

- `safe_candidate`: usually safe to consider for a later explicit cleanup gate,
  but still requires a live write approval before deletion.
- `needs_review`: likely cleanup opportunity, but retention/rollback context
  must be checked first.
- `needs_retention_decision`: never delete automatically. Decide retention
  policy first.
- `missing_rollback_directory`: stop before cleanup planning because no previous
  release rollback directory was identified.
- `keep_current_rollback`: keep the current previous release rollback directory.
- `observe`: no cleanup pressure from that class right now.
- `none`: no candidate in that class.

## Follow-up Flow

1. Run `prod_doctor.sh` first if the host is unhealthy.
2. Run `prod_cleanup_inventory.sh` read-only.
3. Summarize only redacted aggregate output.
4. Decide separately which class is in scope for cleanup.
5. Create an explicit live write gate for any deletion.
6. Re-run `prod_doctor.sh` and `prod_cleanup_inventory.sh` after cleanup.

Docker build cache is a separate retention class. Use
`scripts/ops/prod_build_cache_retention.sh` and the fixed policy in
`docs/ops/production-build-cache-retention.md`; never infer permission for a
builder, image, or system prune from this general inventory.

Release directories, exact archive/provenance pairs, and independently
restore-verified dump sets use the separate ROB-453 contract in
[`production-release-archive-dump-retention.md`](production-release-archive-dump-retention.md).
The default wrapper remains read-only; this inventory and a green dry-run do
not authorize its execute mode or systemd activation.

## Stop Conditions

Stop before any cleanup plan if:

- the current release or rollback directory cannot be identified;
- backup retention is unclear;
- inventory commands would need to print raw filenames or file contents;
- disk pressure is caused by an unknown path outside the known classes;
- health, deep health, renderer, or Kuma are already red for unrelated reasons.

ROB-425 is repo-only until the inventory script is merged. Running it on
production is a separate live read-only gate and requires explicit approval.
