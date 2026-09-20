# Read-only production probe receipt

`run_read_only_http_probe.sh` performs four anonymous `GET` requests against
the known App origin: `booking_confirmation/of/<capability>` and
`appointments/ics/<capability>` for one internally generated modern
capability (64 hex characters) and one legacy capability (12 hex characters).
It does not create fixtures or write application state.

The confirmation checks require HTTP `307` with a redirect classified only as
`appointments`. The ICS checks require HTTP `404`, no `Content-Disposition`,
and a `Content-Type` that is not `text/calendar`. URLs, capabilities,
redirect targets, header values, and response bodies never appear in output or
the receipt. The only receipt details are six fixed boolean checks and closed
classes.

The command emits exactly one canonical JSON line using
`read_only_probe.v1`, including on a controlled curl, assertion, or runtime
failure. The terminal outcomes are:

- `passed` / exit `0`: all six properties hold for both capability formats.
- `application_failed` / exit `20`: HTTP or header/redirect assertions fail.
- `environment_failed` / exit `21`: curl or required local runtime failed.
- `unknown` / exit `70`: the result could not be classified safely.

The production target is fixed to the known App origin. Local tests may use
only `127.0.0.1` with an explicit port. No outcome is retried automatically;
a missing or malformed receipt remains an evidence gap. The HTTP client
explicitly disables user configuration, forces GET, sets retries and redirect
following to zero, and does not follow redirects. Production execution
requires a separate, explicit release decision.
