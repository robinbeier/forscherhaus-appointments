import assert from 'node:assert/strict';
import test from 'node:test';
import {resolveStateApiConfig} from './service.js';
import type {LoadedWorkflowConfig} from './workflow.js';

function createWorkflowConfig(overrides?: Partial<LoadedWorkflowConfig>): LoadedWorkflowConfig {
    return {
        workflowPath: '/repo/WORKFLOW.md',
        loadedAtIso: '2026-03-12T00:00:00.000Z',
        promptTemplate: 'Issue {{issue.identifier}}',
        tracker: {
            kind: 'linear',
            provider: 'linear',
            endpoint: 'https://api.linear.app/graphql',
            apiKey: 'token',
            projectSlug: 'school-appointments',
            activeStates: ['Todo', 'In Progress'],
            terminalStates: ['Done'],
            reviewStateName: 'In Review',
            mergeStateName: 'Ready to Merge',
        },
        polling: {
            intervalMs: 30000,
            maxCandidates: 20,
        },
        server: {},
        workspace: {
            root: '/tmp/symphony_workspaces',
            keepTerminalWorkspaces: false,
        },
        hooks: {
            timeoutMs: 30000,
            afterCreate: [],
            beforeRun: [],
            afterRun: [],
            beforeRemove: [],
        },
        agent: {
            maxConcurrent: 1,
            maxAttempts: 2,
            maxTurns: 20,
            maxRetryBackoffMs: 300000,
            maxConcurrentByState: {},
            commitRequiredStates: ['Todo', 'In Progress', 'Rework'],
        },
        codex: {
            command: 'codex app-server',
            readTimeoutMs: 5000,
            responseTimeoutMs: 5000,
            turnTimeoutMs: 3600000,
            stallTimeoutMs: 300000,
            publishNetworkAccess: false,
        },
        ...overrides,
    };
}

test('resolveStateApiConfig prefers CLI port over workflow and env settings', () => {
    const config = resolveStateApiConfig({
        workflowConfig: createWorkflowConfig({
            server: {
                host: '127.0.0.1',
                port: 8787,
            },
        }),
        env: {
            SYMPHONY_STATE_API_ENABLED: '1',
            SYMPHONY_STATE_API_HOST: '0.0.0.0',
            SYMPHONY_STATE_API_PORT: '9191',
        },
        cliStateApiPort: 9797,
    });

    assert.deepEqual(config, {
        enabled: true,
        host: '127.0.0.1',
        port: 9797,
        source: 'cli',
    });
});

test('resolveStateApiConfig keeps env host fallback when CLI port enables state API', () => {
    const config = resolveStateApiConfig({
        workflowConfig: createWorkflowConfig(),
        env: {
            SYMPHONY_STATE_API_HOST: '0.0.0.0',
        },
        cliStateApiPort: 9797,
    });

    assert.deepEqual(config, {
        enabled: true,
        host: '0.0.0.0',
        port: 9797,
        source: 'cli',
    });
});

test('resolveStateApiConfig enables state API from workflow server port', () => {
    const config = resolveStateApiConfig({
        workflowConfig: createWorkflowConfig({
            server: {
                port: 8788,
            },
        }),
        env: {},
    });

    assert.deepEqual(config, {
        enabled: true,
        host: '127.0.0.1',
        port: 8788,
        source: 'workflow',
    });
});

test('resolveStateApiConfig keeps workflow host default when only env host is set', () => {
    const config = resolveStateApiConfig({
        workflowConfig: createWorkflowConfig({
            server: {
                port: 8788,
            },
        }),
        env: {
            SYMPHONY_STATE_API_HOST: '0.0.0.0',
        },
    });

    assert.deepEqual(config, {
        enabled: true,
        host: '127.0.0.1',
        port: 8788,
        source: 'workflow',
    });
});

test('resolveStateApiConfig preserves env-driven fallback when workflow is unset', () => {
    const config = resolveStateApiConfig({
        workflowConfig: createWorkflowConfig(),
        env: {
            SYMPHONY_STATE_API_ENABLED: 'true',
            SYMPHONY_STATE_API_HOST: '0.0.0.0',
            SYMPHONY_STATE_API_PORT: '9898',
        },
    });

    assert.deepEqual(config, {
        enabled: true,
        host: '0.0.0.0',
        port: 9898,
        source: 'env',
    });
});

test('resolveStateApiConfig stays disabled when neither CLI, workflow, nor env enablement is set', () => {
    const config = resolveStateApiConfig({
        workflowConfig: createWorkflowConfig(),
        env: {},
    });

    assert.deepEqual(config, {
        enabled: false,
        host: '127.0.0.1',
        port: 8787,
        source: 'disabled',
    });
});
