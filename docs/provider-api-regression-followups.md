# Provider API regression follow-ups

The bounded Provider write-only pilot identified four partial acceptance rows in
the existing regression evidence. These are test-quality improvements, not
confirmed product vulnerabilities. Implementation is pending; this document
records the accepted follow-up scope.

Primary file: `tests/Integration/Controllers/ApiIntegrationSecretsWriteOnlyFlowTest.php`.
Preserve its synthetic fixtures, existing local execution context, and cleanup.

| Row | Existing evidence gap | Required improvement |
| --- | --- | --- |
| R1: list/search | Absence checks can accept an empty result | Assert successful, nonempty expected results and the concrete fixture identity before checking secret absence. Empty results and error objects must fail the acceptance assertion. |
| R2: detail/projection/expansion | Expected successful response and expansion are not positively asserted | Assert the expected response shape and fixture identity where selected, and the expected service relationship for expansion. Respect intentional `fields` projection when choosing identity assertions. Then assert secret absence. |
| R3: store/update responses | Update absence checks do not independently establish a successful response | Assert success, the expected response identity/shape and intended persisted result, then secret absence. An error response must not satisfy the successful-response criterion. |
| W2: single-secret rotation | Both secrets rotate together in the existing flow | Rotate each synthetic secret separately while omitting its sibling, and assert the sibling remains unchanged in both directions. Preserve the existing joint rotation, repeated-value, omission, and null cases. |

Validate these improvements in the existing integration test context. Check that
the assertions reject empty/error responses and accidental sibling changes;
do not merely mirror implementation details. Record test selection, results and
synthetic-resource cleanup. No new harness or application change is implied.

These controller-level improvements do not establish full HTTP routing,
authentication, deployment, or production coverage. Keep those evidence levels
separate. Refer to [Defense Factory](defense-factory.md) for cycle handoffs.
