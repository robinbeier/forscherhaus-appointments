# Symphony Core-Conformance Matrix

This matrix maps SPEC core-conformance segments (`17.1` to `17.5`) to
deterministic automated tests in `tools/symphony`.

## Execute Matrix

```bash
cd tools/symphony
npm run test:conformance
```

## Coverage Mapping

| SPEC segment | Focus                                                                                 | Test coverage                                         |
| ------------ | ------------------------------------------------------------------------------------- | ----------------------------------------------------- |
| `17.1`       | Workflow contract + config parsing + strict templating                                | `src/workflow-config.test.ts`, `src/template.test.ts` |
| `17.2`       | Workspace safety invariants + hook semantics                                          | `src/workspace-manager.test.ts`                       |
| `17.3`       | Orchestrator poll/dispatch/reconcile/retry + claiming                                 | `src/orchestrator.test.ts`                            |
| `17.4`       | Codex app-server protocol behavior (handshake, stream parsing, timeout/error mapping) | `src/app-server-client.test.ts`                       |
| `17.5`       | Reproducible fake tracker/agent profiles for CI-like runs                             | `src/test-profiles.test.ts`                           |

## Notes

- The matrix intentionally references deterministic tests only.
- `src/test-profiles.ts` provides reusable fake Linear/Codex profiles for future
  conformance and regression scenarios.

## Supplemental Status Surface Proof

- `src/state-server.test.ts` deterministically covers `GET /`,
  `GET /api/v1/state`, `GET /api/v1/<issue_identifier>`, and
  `POST /api/v1/refresh` without requiring a live pilot.
