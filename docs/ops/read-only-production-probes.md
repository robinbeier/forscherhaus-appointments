# Read-only production probes

`run_read_only_http_probe.sh` checks the existing anonymous booking-confirmation
and ICS controller routes through the fixed local ingress `http://127.0.0.1`.
It never sends the probe to the public DNS origin. In production mode, the
wrapper first verifies the fixed, non-symlinked app root
`/var/www/html/easyappointments`, its root-owned `_RELEASE` marker, and the
caller-supplied `READ_ONLY_PROBE_EXPECTED_RELEASE` expectation. The marker and app-root device/inode
are checked again after the requests. The expected release is an expectation;
the active root and marker remain the authority.

The application classifies only `GET` requests from loopback clients to the
exact 12- or 64-character lowercase capability routes. Those requests use a
non-persistent session driver, pass the existing loopback rate-limit guard, and
silence only the empty ICS 404 log path. Public requests keep the normal file
session driver, rate limiting, and error logging.

The wrapper reuses one cookie context and removes its local cookie jar and
header files. The receipt is secret-free and contains only closed security
classes. It proves the active release's application behavior through the local
ingress and the application-side state-free design. It does not prove public
DNS, TLS, proxy routing, or behavior outside the exact classified routes.

The first production run remains separately approved. Unknown, mismatched, or
changed release/root state is a fail-closed result and must not be retried
automatically.

The `read_only_probe.v1` terminal receipt remains the closed contract. A
successful receipt has this shape (values are fixed classes or booleans; no
URL, capability, release ID, header value, or path is emitted):

```json
{"schema":"read_only_probe.v1","probe":"anonymous_booking_download_capabilities","target_class":"production","outcome":"passed","exit_code":0,"checks":{"modern_confirmation_redirect":true,"legacy_confirmation_redirect":true,"modern_ics_missing":true,"legacy_ics_missing":true,"modern_ics_headers_safe":true,"legacy_ics_headers_safe":true},"redirect_class":{"modern":"appointments","legacy":"appointments"},"ics_header_class":{"modern":"not_calendar_no_disposition","legacy":"not_calendar_no_disposition"},"check_count":6,"state":{"session":"unchanged","rate_limit":"unchanged","app_log":"unchanged"},"cleanup":"not_applicable"}
```

`state.session`, `state.rate_limit`, and `state.app_log` are `unchanged` only
after the production pre/post inventory and probe-session check agree. Local
synthetic runs use `not_applicable` because they do not inspect a production
filesystem. `unknown` or `changed` state produces a non-passing receipt;
`cleanup` remains `not_verified` until the production post-measurement has
completed, then becomes `not_applicable`. Parallel application activity can
make the conservative log comparison fail closed.
