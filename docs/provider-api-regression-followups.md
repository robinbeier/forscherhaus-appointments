# Provider API regression follow-ups

The bounded Provider write-only pilot identified four partial acceptance rows in
the existing regression evidence. These are test-quality improvements, not
confirmed product vulnerabilities. The four improvements below are implemented
in the existing controller test. They establish stronger local controller-level
evidence; they do not retroactively change the result of an earlier pilot.

Primary file: `tests/Integration/Controllers/ApiIntegrationSecretsWriteOnlyFlowTest.php`.
Preserve its synthetic fixtures, existing local execution context, and cleanup.

| Row | Original evidence gap | Implemented improvement |
| --- | --- | --- |
| R1: list/search | Absence checks can accept an empty result | Assert successful, nonempty expected results and the concrete fixture identity before checking secret absence. Empty results and error objects must fail the acceptance assertion. |
| R2: detail/projection/expansion | Expected successful response and expansion are not positively asserted | Assert the expected response shape and fixture identity where selected, and the expected service relationship for expansion. Respect intentional `fields` projection when choosing identity assertions. Then assert secret absence. |
| R3: store/update responses | Update absence checks do not independently establish a successful response | Assert success, the expected response identity/shape and intended persisted result, then secret absence. An error response must not satisfy the successful-response criterion. |
| W2: single-secret rotation | Both secrets rotate together in the existing flow | Rotate each synthetic secret separately while omitting its sibling, and assert the sibling remains unchanged in both directions. Preserve the existing joint rotation, repeated-value, omission, and null cases. |

Validate these improvements in the existing integration test context. Check that
the assertions reject empty/error responses and accidental sibling changes;
do not merely mirror implementation details. Record test selection, results and
synthetic-resource cleanup. No new harness or application change is implied.

The same four controller test methods now check positive fixture identity,
settings projection identity, service expansion, input-derived store/update
results, and separate rotations followed by a repeated same-value write. The
unchanged model and OpenAPI tests remain part of the bounded pilot. Record fresh
test counts and outcomes in the private run report, not as permanent guarantees.

These controller-level improvements do not establish full HTTP routing,
authentication, deployment, or production coverage. Keep those evidence levels
separate. Refer to [Defense Factory](defense-factory.md) for cycle handoffs.

## Bounded HTTP authentication regression

`tests/Integration/Controllers/ProviderApiHttpAuthTest.php` extends the evidence
through the actual local HTTP router, Provider controller constructor, and API
authentication. It uses the existing isolated Defense-cycle lifecycle and only
reads its own synthetic Provider through list and detail routes.

The cases cover successful synthetic administrator Basic authentication and the
configured global API Bearer token, plus ordinary rejection without credentials,
with an incorrect synthetic password, with a synthetic Provider Basic account,
and with a nonmatching synthetic Bearer token. Successful responses must identify
the expected fixture and omit integration secrets even when the fixture stores
nonempty synthetic values. The global Bearer token has no per-user role mapping;
these tests do not introduce one.

The opt-in fixture setup restores the local API-token setting during cleanup.
The existing lifecycle removes owned fixture rows, HTTP resources and the disposable
Docker environment. Record actual test results and final resource checks in the
private cycle report. Use the existing `scripts/ci/run_defense_cycle.sh` entry point;
no external target or production data is needed.

This adds local HTTP read/authentication evidence. It does not establish Provider
write authorization, every non-admin role, mixed authentication precedence,
all header parsing behavior, production proxy configuration or a production
release. The prior controller/model/schema evidence retains its own scope.

## Bounded HTTP write regression

`tests/Integration/Controllers/ProviderApiHttpWriteTest.php` uses the existing
isolated lifecycle for ordinary Provider POST and PUT requests with JSON bodies.
Administrator Basic and the configured global Bearer each create and update a
separate synthetic Provider. Responses must identify the expected record; direct
database reads verify identity, notes, settings and service relationships.

Requests without authentication, with an incorrect synthetic password, with an
ordinary synthetic Provider Basic account, or with a nonmatching Bearer must
return 401 without changes to snapshots of users, services, appointments,
user settings and service-provider relations. Successful responses are checked
recursively for forbidden secret keys and for the synthetic secret values in the
complete normalized JSON. These are ordinary bounded regression cases, not a
complete authentication or data-integrity proof.

Before POST, the fixture registers the exact future synthetic email independently
of the response. Cleanup only removes its registered Provider identities with
expected roles and relationships, including partially created settings/links;
unexpected relationships fail cleanup and leave disposal to the owned stack.
An additional regression exercises repeated cleanup without relying on a decoded
creation response. API-token restoration and owned-resource teardown remain part
of the existing lifecycle.

The shared HTTP client adds a JSON-only POST/PUT entry point; existing form
requests retain their behavior. Its existing Kuma runtime-bundle manifest tracks
the changed source hash; this is not an installation or production update.
No application code or additional CI job changes.
The earlier controller tests remain the evidence for single-secret rotation,
omission and null handling. A bounded DELETE extension now covers authorized
synthetic administrator Basic and global Bearer deletion, direct absence of the
user/settings/service-link rows, unchanged unrelated fixture data, missing
authentication, and repeated deletion returning 404. The fixture registers each
future identity before its HTTP request and cleanup remains repeatable.
These cases are ordinary local HTTP evidence only; concurrent writes, other
roles, production HTTP configuration and deployment remain outside this pilot.

## Bounded Secretary DELETE regression

`tests/Integration/Controllers/StaffSettingsApiHttpTest.php` now covers
authorized synthetic Secretary deletion through administrator Basic and the
global Bearer token. Each case registers and creates its own Secretary with a
known Provider link, asserts a positive ID and settings/link preconditions,
then verifies HTTP `204`, direct absence of the user/settings/link rows, and
unchanged unrelated Provider and second-Secretary sentinel state. Repeated
deletion returns `404`; missing authentication returns `401` with a challenge
and leaves the fixture unchanged. The fixture cleanup is explicitly repeated
after DELETE and confirms both owned identities are absent.

This is ordinary local HTTP evidence for the API route and authentication
boundary. It does not cover administrator deletion, deletion with appointments,
concurrent writes, other roles, production configuration, or deployment.
