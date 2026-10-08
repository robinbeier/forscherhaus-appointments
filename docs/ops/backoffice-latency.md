# Backoffice request-duration evidence (ROB-787)

This is a preparation contract, not production activation. ROB-736 has status
and error evidence from the first staff use, but the active App Apache access
log does not record request duration. Healthz and Uptime Kuma measure their own
checks, not authenticated staff requests. No historical staff latency can be
reconstructed from those signals.

## Property and observation

For a later, separately authorized observation, append Apache `%D` (elapsed
microseconds) to the existing `combined` fields in the **App HTTPS vhost only**.
Use a distinct named format and retain the same access-log path, permissions,
daily rotation, and compression. Do not change the WWW or Monitor vhosts or add
request bodies, cookies, headers, IPs, URLs, or user agents to the format.
The existing `combined` fields already contain potentially sensitive request
metadata; raw lines must remain on the protected host.

`scripts/ops/backoffice_latency_aggregate.py` consumes those lines on stdin.
It requires an explicit UTC half-open window and emits only fixed operation,
HTTP-method/status-class, duration-bucket, and parsing counters as JSON. It
does not write files, echo a line, or emit a request target or identifier. The
operation list is closed: login page/validation, dashboard page/data, calendar
page/event read/unavailability save, and separate legacy redirect aliases.
The direct `/index.php/` entrypoint is canonical and stays in the same operation
as its corresponding controller path. Unrecognized paths are excluded.

Before emitting any counts, the CLI fails closed with only a
`privacy_suppressed` result if fewer than 20 measurements exist overall or for
any populated operation, or if **any** positive output cell contains fewer than
five observations. This includes uncommon error and alias cells as well as
duration buckets. An operator must additionally use a broad window with a
separately supported multi-person population; event-count thresholds alone do
not establish distinct people or anonymize a known individual's session.
Never store a per-session or narrow single-user result in Linear.

The duration buckets are below 100 ms, 100–250 ms, 250–500 ms, 500 ms–1 s,
1–3 s, and at least 3 s. A result is `no_measurement`,
`incomplete_format`, `insufficient_samples` (fewer than 20 observations), or
`measured` inside an eligible summary. Each operation has its own result class.
Even `measured` is only a
descriptive server-side distribution: it does not establish p95, a browser
experience, a user's role, real versus synthetic traffic, or an improvement
over a nonexistent historical baseline. Malformed lines without a parseable
timestamp are counted outside the window classification; interpret them as an
explicit completeness limit.

Local validation uses synthetic log lines only:

```bash
python3 -B -m unittest -v tests.Python.test_backoffice_latency_aggregate
```

## Later production decision and recovery

Before any server change, recheck the exact active vhost, log path, file
identity, ownership, mode, hash, rotation policy, current release, services,
lock, and health. Bind the reviewed helper and candidate Apache config by
SHA-256; retain a no-clobber copy of the previous config. Install and run the
helper **on the protected host** so raw access logs never cross SSH or enter
GitHub/Linear. Change only the App HTTPS log-format reference, validate with
`apache2ctl configtest`, then reload Apache once. Immediately recheck App,
WWW, Deep Health, Monitor, and error classes. On an unambiguous regression,
restore the bound prior config, validate, reload, and recheck; on contradictory
identity or unknown outcome stop without blind retry.

Only after that may one bounded UTC window be aggregated. Record release,
config/helper hashes, window, fixed counters, result classes, and the limits
above in ROB-736. Exclude known synthetic run windows if their separate
operator journal permits that, but do not infer identity from User-Agent or
request path. If no such independent classification exists, mark traffic origin
unclassified. The first measurement creates a prospective baseline, not a
before/after comparison.
