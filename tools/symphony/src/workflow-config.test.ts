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
            SYMPHONY_CODEX_COMMAND: 'codex app-server',
        },
        homeDir: '/home/robin',
    });

    assert.equal(config.tracker.apiKey, 'secret-token');
    assert.equal(config.codex.command, 'codex app-server');
    assert.equal(config.workspace.root, '/home/robin/pilot/workspaces');
    assert.deepEqual(config.agent.commitRequiredStates, ['Todo', 'In Progress', 'Rework']);
});

test('parseWorkflowConfig supports commit_required_states override', () => {
    const config = parseWorkflowConfig({
        workflowPath: '/repo/WORKFLOW.md',
        contents: `---
tracker:
  api_key: token
  project_slug: school-appointments
agent:
  commit_required_states:
    - Todo
    - In Progress
codex:
  command: codex app-server
---
Issue {{issue.identifier}}
`,
        env: {},
        homeDir: '/home/robin',
    });

    assert.deepEqual(config.agent.commitRequiredStates, ['Todo', 'In Progress']);
});

test('parseWorkflowConfig accepts optional server port and host', () => {
    const config = parseWorkflowConfig({
        workflowPath: '/repo/WORKFLOW.md',
        contents: `---
tracker:
  api_key: token
  project_slug: school-appointments
server:
  host: 127.0.0.1
  port: 9797
codex:
  command: codex app-server
---
Issue {{issue.identifier}}
`,
        env: {},
        homeDir: '/home/robin',
    });

    assert.equal(config.server.host, '127.0.0.1');
    assert.equal(config.server.port, 9797);
});

test('parseWorkflowConfig ignores invalid optional server port values', () => {
    const config = parseWorkflowConfig({
        workflowPath: '/repo/WORKFLOW.md',
        contents: `---
tracker:
  api_key: token
  project_slug: school-appointments
server:
  port: 70000
codex:
  command: codex app-server
---
Issue {{issue.identifier}}
`,
        env: {},
        homeDir: '/home/robin',
    });

    assert.equal(config.server.port, undefined);
});

test('parseWorkflowConfig accepts future non-empty codex approval and sandbox strings', () => {
    const config = parseWorkflowConfig({
        workflowPath: '/repo/WORKFLOW.md',
        contents: `---
tracker:
  api_key: token
  project_slug: school-appointments
codex:
  command: codex app-server
  approval_policy: future-policy
  thread_sandbox: future-sandbox
  turn_sandbox_policy:
    type: futureSandbox
    nested:
      flag: true
---
Issue {{issue.identifier}}
`,
        env: {},
        homeDir: '/home/robin',
    });

    assert.equal(config.codex.approvalPolicy, 'future-policy');
    assert.equal(config.codex.threadSandbox, 'future-sandbox');
    assert.deepEqual(config.codex.turnSandboxPolicy, {
        type: 'futureSandbox',
        nested: {
            flag: true,
        },
    });
});

test('parseWorkflowConfig accepts publish-capable codex settings', () => {
    const config = parseWorkflowConfig({
        workflowPath: '/repo/WORKFLOW.md',
        contents: `---
tracker:
  api_key: token
  project_slug: school-appointments
codex:
  command: codex app-server
  publish_approval_policy: never
  publish_network_access: true
---
Issue {{issue.identifier}}
`,
        env: {},
        homeDir: '/home/robin',
    });

    assert.equal(config.codex.publishApprovalPolicy, 'never');
    assert.equal(config.codex.publishNetworkAccess, true);
});

test('parseWorkflowConfig accepts custom review and merge state names', () => {
    const config = parseWorkflowConfig({
        workflowPath: '/repo/WORKFLOW.md',
        contents: `---
tracker:
  api_key: token
  project_slug: school-appointments
  review_state_name: In Review
  merge_state_name: Ready to Merge
  active_states:
    - Todo
    - In Progress
    - Rework
    - Ready to Merge
codex:
  command: codex app-server
---
Issue {{issue.identifier}}
`,
        env: {},
        homeDir: '/home/robin',
    });

    assert.equal(config.tracker.reviewStateName, 'In Review');
    assert.equal(config.tracker.mergeStateName, 'Ready to Merge');
    assert.deepEqual(config.tracker.activeStates, ['Todo', 'In Progress', 'Rework', 'Ready to Merge']);
});

test('parseWorkflowConfig rejects invalid codex approval_policy values', () => {
    assert.throws(
        () =>
            parseWorkflowConfig({
                workflowPath: '/repo/WORKFLOW.md',
                contents: `---
tracker:
  api_key: token
  project_slug: school-appointments
codex:
  command: codex app-server
  approval_policy: ""
---
Issue {{issue.identifier}}
`,
                env: {},
                homeDir: '/home/robin',
            }),
        (error) => error instanceof WorkflowConfigError && error.errorClass === 'invalid_codex_approval_policy',
    );

    assert.throws(
        () =>
            parseWorkflowConfig({
                workflowPath: '/repo/WORKFLOW.md',
                contents: `---
tracker:
  api_key: token
  project_slug: school-appointments
codex:
  command: codex app-server
  approval_policy: 123
---
Issue {{issue.identifier}}
`,
                env: {},
                homeDir: '/home/robin',
            }),
        (error) => error instanceof WorkflowConfigError && error.errorClass === 'invalid_codex_approval_policy',
    );
});

test('parseWorkflowConfig rejects invalid codex thread_sandbox and turn_sandbox_policy values', () => {
    assert.throws(
        () =>
            parseWorkflowConfig({
                workflowPath: '/repo/WORKFLOW.md',
                contents: `---
tracker:
  api_key: token
  project_slug: school-appointments
codex:
  command: codex app-server
  thread_sandbox: ""
---
Issue {{issue.identifier}}
`,
                env: {},
                homeDir: '/home/robin',
            }),
        (error) => error instanceof WorkflowConfigError && error.errorClass === 'invalid_codex_thread_sandbox',
    );

    assert.throws(
        () =>
            parseWorkflowConfig({
                workflowPath: '/repo/WORKFLOW.md',
                contents: `---
tracker:
  api_key: token
  project_slug: school-appointments
codex:
  command: codex app-server
  turn_sandbox_policy: bad
---
Issue {{issue.identifier}}
`,
                env: {},
                homeDir: '/home/robin',
            }),
        (error) => error instanceof WorkflowConfigError && error.errorClass === 'invalid_codex_turn_sandbox_policy',
    );
});

test('validateDispatchPreflight classifies missing required fields', () => {
    const workflowPath = '/repo/WORKFLOW.md';
    const contents = `---
tracker:
  project_slug: school-appointments
codex:
  command: codex app-server
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
    assert.equal(errorLog?.errorClass, 'workflow_parse_error');
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
    assert.equal(errorLog?.errorClass, 'missing_workflow_file');
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

test('parseWorkflowConfig rejects non-map front matter with workflow_front_matter_not_a_map', () => {
    assert.throws(
        () =>
            parseWorkflowConfig({
                workflowPath: '/repo/WORKFLOW.md',
                contents: `---
- not
- a
- map
---
Issue {{issue.identifier}}
`,
                env: {},
                homeDir: '/home/robin',
            }),
        (error) => error instanceof WorkflowConfigError && error.errorClass === 'workflow_front_matter_not_a_map',
    );
});

test('parseWorkflowConfig maps invalid template syntax to template_parse_error', () => {
    assert.throws(
        () =>
            parseWorkflowConfig({
                workflowPath: '/repo/WORKFLOW.md',
                contents: `---
tracker:
  api_key: token
  project_slug: project-a
codex:
  command: codex run
---
Issue {{invalid.identifier}}
`,
                env: {},
                homeDir: '/home/robin',
            }),
        (error) => error instanceof WorkflowConfigError && error.errorClass === 'template_parse_error',
    );
});
