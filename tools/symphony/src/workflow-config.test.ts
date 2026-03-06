import assert from 'node:assert/strict';
import {mkdtemp, unlink, writeFile} from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import type {Logger} from './logger.js';
import {parseWorkflowConfig, validateDispatchPreflight, WorkflowConfigError, WorkflowConfigStore} from './workflow.js';

function createLoggerStub(records: Array<Record<string, unknown>>): Logger {
    return {
        info(message, fields) {
            records.push({level: 'info', message, ...(fields ?? {})});
        },
        warn(message, fields) {
            records.push({level: 'warn', message, ...(fields ?? {})});
        },
        error(message, fields) {
            records.push({level: 'error', message, ...(fields ?? {})});
        },
    };
}

test('parseWorkflowConfig resolves env variables and home expansion', () => {
    const workflowPath = '/repo/WORKFLOW.md';
    const contents = `---
tracker:
  api_key: $LINEAR_API_KEY
  project_slug: school-appointments
workspace:
  root: ~/pilot/workspaces
codex:
  command: $SYMPHONY_CODEX_COMMAND
---
Issue {{issue.identifier}} (attempt {{attempt}})
`;

    const config = parseWorkflowConfig({
        workflowPath,
        contents,
        env: {
            LINEAR_API_KEY: 'secret-token',
            SYMPHONY_CODEX_COMMAND: 'codex --app-server',
        },
        homeDir: '/home/robin',
    });

    assert.equal(config.tracker.apiKey, 'secret-token');
    assert.equal(config.codex.command, 'codex --app-server');
    assert.equal(config.workspace.root, '/home/robin/pilot/workspaces');
});

test('validateDispatchPreflight classifies missing required fields', () => {
    const workflowPath = '/repo/WORKFLOW.md';
    const contents = `---
tracker:
  project_slug: school-appointments
codex:
  command: codex --app-server
---
Issue {{issue.identifier}}
`;

    const config = parseWorkflowConfig({
        workflowPath,
        contents,
        env: {},
        homeDir: '/home/robin',
    });

    assert.throws(
        () => validateDispatchPreflight(config),
        (error) => error instanceof WorkflowConfigError && error.errorClass === 'preflight_missing_tracker_api_key',
    );
});

test('WorkflowConfigStore keeps last known good config when reload is invalid', async () => {
    const temporaryDirectory = await mkdtemp(path.join(os.tmpdir(), 'symphony-workflow-store-'));
    const workflowPath = path.join(temporaryDirectory, 'WORKFLOW.md');
    const logs: Array<Record<string, unknown>> = [];

    await writeFile(
        workflowPath,
        `---
tracker:
  api_key: token-a
  project_slug: project-a
codex:
  command: codex run
---
Issue {{issue.identifier}}
`,
        'utf8',
    );

    const store = new WorkflowConfigStore({
        workflowPath,
        logger: createLoggerStub(logs),
        env: {},
        homeDir: '/home/robin',
    });

    const initialConfig = await store.initialize();
    assert.equal(initialConfig.tracker.projectSlug, 'project-a');

    await new Promise((resolve) => setTimeout(resolve, 20));

    await writeFile(
        workflowPath,
        `---
tracker: [invalid
---
Issue {{issue.identifier}}
`,
        'utf8',
    );

    const reloaded = await store.reloadIfChanged();
    assert.equal(reloaded, false);
    assert.equal(store.getCurrentConfig().tracker.projectSlug, 'project-a');

    const errorLog = logs.find((entry) => entry.level === 'error');
    assert.ok(errorLog);
    assert.equal(errorLog?.errorClass, 'invalid_workflow');
});

test('WorkflowConfigStore keeps last known good config when workflow file disappears', async () => {
    const temporaryDirectory = await mkdtemp(path.join(os.tmpdir(), 'symphony-workflow-store-missing-'));
    const workflowPath = path.join(temporaryDirectory, 'WORKFLOW.md');
    const logs: Array<Record<string, unknown>> = [];

    await writeFile(
        workflowPath,
        `---
tracker:
  api_key: token-a
  project_slug: project-a
codex:
  command: codex run
---
Issue {{issue.identifier}}
`,
        'utf8',
    );

    const store = new WorkflowConfigStore({
        workflowPath,
        logger: createLoggerStub(logs),
        env: {},
        homeDir: '/home/robin',
    });

    await store.initialize();
    await unlink(workflowPath);

    const reloaded = await store.reloadIfChanged();
    assert.equal(reloaded, false);
    assert.equal(store.getCurrentConfig().tracker.projectSlug, 'project-a');

    const errorLog = logs.find((entry) => entry.level === 'error');
    assert.ok(errorLog);
    assert.equal(errorLog?.errorClass, 'missing_workflow');
});

test('WorkflowConfigStore applies valid reload for new dispatches', async () => {
    const temporaryDirectory = await mkdtemp(path.join(os.tmpdir(), 'symphony-workflow-store-reload-'));
    const workflowPath = path.join(temporaryDirectory, 'WORKFLOW.md');
    const logs: Array<Record<string, unknown>> = [];

    await writeFile(
        workflowPath,
        `---
tracker:
  api_key: token-a
  project_slug: project-a
codex:
  command: codex run
---
Issue {{issue.identifier}} attempt {{attempt}}
`,
        'utf8',
    );

    const store = new WorkflowConfigStore({
        workflowPath,
        logger: createLoggerStub(logs),
        env: {},
        homeDir: '/home/robin',
    });

    await store.initialize();
    store.validateCurrentPreflight();

    await new Promise((resolve) => setTimeout(resolve, 20));

    await writeFile(
        workflowPath,
        `---
tracker:
  api_key: token-b
  project_slug: project-b
codex:
  command: codex run
---
Issue {{issue.identifier}} / {{issue.title}} / {{attempt}}
`,
        'utf8',
    );

    const dispatch = await store.buildDispatchPrompt({
        issue: {
            identifier: 'ROB-9',
            title: 'Workflow loader',
        },
        attempt: 3,
    });

    assert.equal(dispatch.config.tracker.projectSlug, 'project-b');
    assert.equal(dispatch.prompt, 'Issue ROB-9 / Workflow loader / 3');
});
