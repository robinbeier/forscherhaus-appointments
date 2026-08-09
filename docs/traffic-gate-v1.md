# Production Traffic Gate v1

Purpose: provide one passive, versioned and secret-free traffic decision for
production deploy and Customers UI smoke callers.

## CLI contract

Run the server-local producer before any caller-owned HTTP probe or production
mutation:

```bash
bash scripts/ops/prod_traffic_gate.sh \
  --purpose customers-ui-smoke \
  --mode no-business-traffic \
  --window-seconds 90 \
  --output-json /var/lib/fh-traffic-gate/customers-ui-smoke-latest.json
```

Supported purposes are `customers-ui-smoke` and `deploy`. Supported modes are
`normal` and `no-business-traffic`; the latter must be selected explicitly for
each run. The PHP evaluator is `scripts/ops/traffic_gate_v1.php` and the policy
implementation is shared by both purposes.

Exit codes are fixed:

- `0`: complete evidence with `allow` or `advisory` decision.
- `20`: complete evidence with a traffic `hard_stop`.
- `21`: invalid or incomplete evidence, including parse or rotation failure.
- `64`: invalid invocation.

## Evidence contract

`traffic_gate.v1` contains only fixed strings, booleans, non-negative aggregate
counters and SHA-256 fingerprints. Its canonical cutoff is
`window_end_epoch`; `window_start_epoch` and `window_seconds` bind the same
inclusive observation window. `producer_sha256` binds the evaluator, producer,
policy and catalog bytes. `log_set_sha256` binds the normalized log-member
metadata without hashing or exporting raw request data.

The six mutually exclusive classes are:

- `documented_health`
- `documented_periodic_ops`
- `denied_external`
- `public_read`
- `business_or_authenticated`
- `unclassified`

The class counters always sum to `lines_in_window`. Overlay counters retain
hard-stop causes such as writes, authenticated use, Customers/sensitive paths,
scanner success, 5xx, and unknown source, method, or target.

The report never contains source addresses, URLs, paths, query strings, user
agents, referrers, authenticated usernames, raw log lines, or log filenames.
Callers must not add those values to surrounding logs or Linear evidence.

Deploy evidence should retain only the report SHA-256 plus the normalized core
fields: schema, producer/policy/catalog versions, purpose, mode, window bounds,
log-set fingerprint, completeness booleans, decision, exit code, and aggregate
counters. It must not embed the complete traffic report inside a deploy record.

## Classification policy

Documented health and periodic operations require a loopback source together
with an exact catalog method, query-free path, and status tuple. User-Agent or
path text alone never establishes trust.

External scanner signatures are advisory only when a safe method receives
exactly `403` or `404`. Scanner success, redirects, other denials, unsafe
methods, 5xx, or malformed input remain hard stops. A query-free,
unauthenticated `GET` or `HEAD` public read is a hard stop in `normal` mode and
the only traffic class relaxed to advisory in explicit
`no-business-traffic` mode.

Authenticated traffic, writes, business/Customers/sensitive routes, queryful
non-scanner requests, unknown source/method/target, unclassified records, and
all 5xx remain hard stops in both modes.

## Rotation and completeness

The producer reads the complete canonical Apache access-log set: every current
`*access.log`, `.1`, and numbered `.gz` member. It never uses a line tail or a
fixed record limit. Every file must be a readable regular non-symlink with a
unique identity; gzip integrity is checked before parsing.
Both plain and gzip members are bounded to the byte size captured at the
canonical cutoff, so appends after the snapshot cannot change the decision.

The producer captures the log set before and after the window. Every pre-window
identity must survive with a non-decreasing size, including when current is
renamed to `.1`. Missing identities, truncation, duplicate identities, corrupt
gzip, unsupported format, malformed timestamps, or a wholly unparseable set
make the evidence incomplete and exit `21`.

Production activation of this contract is a separate serial operation. A
reviewed implementation or green CI does not itself activate the gate on the
host.
