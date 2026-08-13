# Production Session-Mode Normalization

ROB-464 is a one-time repair for legacy CodeIgniter session files whose
filesystem mode is still `0644` instead of the required `0600`. Repository
delivery does not authorize a production command, does not schedule recurring
execution, and does not replace the separate ROB-440 retention policy.

## Fixed Normalization Class

The scope is deliberately narrow:

- exact root: `/var/www/html/easyappointments/storage/sessions`;
- exact Easy!Appointments session-name grammar already enforced by ROB-440;
- only regular, single-link `www-data:www-data` files with mode `0600` or
  `0644`;
- only the transition `0644` to `0600`; `0600` is already compliant and every
  other mode blocks the complete initial preflight;
- stable path/open/file identity, a nonblocking exclusive session lock,
  `fchmod()` on that same descriptor, file `fsync()`, and an exact post-check;
- unchanged contents, inode, owner, group, link count, size, and modification
  time; the helper never reads, rewrites, truncates, renames, or deletes a
  payload;
- at most 10,000 changes per pass;
- canonical aggregate output only, without session names or contents;
- no timer, cron, probabilistic PHP garbage collection, or recurring
  repository-delivered activation.

Any matching symlink, FIFO, socket, device, directory, unexpected owner, group,
mode, hard link, or identity drift blocks before the first mutation. A locked
session remains `0644` and makes the pass partial. Foreign names are counted and
left untouched.

Execute mode holds the existing private retention-directory lock and the shared
production-change lock. It repeats the ROB-440 activity checks before and after
the full preflight and during a large pass. Deploy, recovery, dump, replay,
traffic-gate, Provider/Customers smoke, or another cleanup therefore blocks the
operation.

The recurring ROB-440 service remains unchanged and capability-bounded to
`CAP_DAC_OVERRIDE`. The manual ROB-464 wrapper runs the one-time action with
exactly `CAP_DAC_OVERRIDE` and `CAP_FOWNER`, with no inheritable or ambient
capabilities. No normalization service or timer is installed.

## Dry-run And Live-write Boundary

The operator wrapper is read-only by default:

```bash
bash scripts/ops/prod_session_mode_normalization.sh
```

The only live CLI shape is:

```bash
bash scripts/ops/prod_session_mode_normalization.sh \
  --execute \
  --confirm-live-write ROB-464
```

`--execute` without that exact token is invalid. Merge, tests, or a green
dry-run are not live authorization. Dry-run reports only aggregate counts for
scanned, foreign, secure, legacy, locked, changed, and bounded candidates.

No completion marker is created. A complete final rescan is the authority for
`pass`. The normalizer never writes, refreshes, removes, or cleans the ROB-440
retention marker or its temporary files.

## Separate Production Rollout

These steps describe a later operation and are not merge authorization:

1. Confirm no deploy, recovery, dump, restore, replay, traffic gate, smoke, or
   other production mutation is active. Run the standard read-only doctor and
   cleanup inventory.
2. Install the updated helper as the protected host copy. Never execute the
   deploy-tree copy as root. Do not install or enable another unit or timer.
3. Run the new wrapper in default dry-run mode and retain only its aggregate
   result. Stop on any blocked or unexpected class.
4. Obtain a separate live-write approval.
5. Run one bounded execute pass. Exit `75` with `status=partial` means locked,
   changed, or cap-limited legacy files remain. Re-inventory before any retry.
6. After `pass`, rerun the normalizer dry-run and require zero legacy files.
7. Run the ROB-440 wrapper in default dry-run mode and require its strict
   `0600` contract to pass:

   ```bash
   bash scripts/ops/prod_session_retention.sh
   ```

8. Run standard post-change health and monitoring checks. Timer activation
   remains a later ROB-440 rollout decision.

## Stop Conditions

Stop without widening or ad-hoc shell repair when:

- the doctor, cleanup inventory, or health checks are unclear;
- cooperating production work is active or expected to start;
- a matching entry has an unexpected type, owner, group, mode, link count, or
  identity;
- a lock, race, or cap leaves the pass partial;
- the host differs from the tested Linux session-file model.

Do not substitute `find`, path-based `chmod`, glob loops, payload edits, SQL,
mass session deletion, or PHP request-time garbage collection.

## Rollback And Recovery

The secure mode transition is intentionally not reversed: restoring `0644`
would re-expose session bytes. Rollback means stopping further passes,
preserving aggregate evidence, and validating the application. Session payloads
and directory entries are unchanged.

A crash before `fchmod()` leaves `0644`; a crash after it leaves `0600` once the
file metadata is durable. A later bounded pass rescans the complete namespace
and converges safely. Never chmod a file back to `0644`, delete all sessions,
rename the session root, or alter the ROB-440 marker while investigating.
