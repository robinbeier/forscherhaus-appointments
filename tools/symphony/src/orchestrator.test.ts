import assert from 'node:assert/strict';
import test from 'node:test';
import {AppServerClientError} from './app-server-client.js';
import {type TrackerClient, SymphonyOrchestrator, type WorkspaceClient} from './orchestrator.js';
import type {TrackedIssue} from './linear-tracker.js';
import type {Logger} from './logger.js';
import type {LoadedWorkflowConfig} from './workflow.js';
import {WorkspaceManagerError} from './workspace-manager.js';

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

function createIssue(args: {
    id: string;
    identifier: string;
    priority: number;
    createdAt: string;
    blockedBy?: string[];
    stateName?: string;
}): TrackedIssue {
    return {
        id: args.id,
        identifier: args.identifier,
        title: args.identifier,
        stateName: args.stateName ?? 'In Progress',
        stateType: 'started',
        priority: args.priority,
        labels: [],
        blockedByIdentifiers: args.blockedBy ?? [],
        createdAt: args.createdAt,
        updatedAt: args.createdAt,
        projectSlug: 'forscherhaus',
    };
}

function createWorkflowConfig(): LoadedWorkflowConfig {
    return {
        workflowPath: '/repo/WORKFLOW.md',
        loadedAtIso: '2026-03-06T00:00:00.000Z',
        promptTemplate: 'Issue {{issue.identifier}} attempt {{attempt}}',
        tracker: {
            provider: 'linear',
            apiKey: 'token',
            projectSlug: 'forscherhaus',
            activeStates: ['In Progress'],
        },
        polling: {
            intervalMs: 1000,
            maxCandidates: 20,
        },
        workspace: {
            root: '/tmp/symphony-workspaces',
            keepTerminalWorkspaces: false,
        },
        hooks: {
            timeoutMs: 1000,
            afterCreate: [],
            beforeRun: [],
            afterRun: [],
            beforeRemove: [],
        },
        agent: {
            maxConcurrent: 1,
            maxAttempts: 3,
        },
        codex: {
            command: 'codex --app-server',
            responseTimeoutMs: 2000,
            turnTimeoutMs: 5000,
        },
    };
}

class WorkflowStoreStub {
    public config: LoadedWorkflowConfig;

    public constructor(config: LoadedWorkflowConfig) {
        this.config = config;
    }

    public async reloadIfChanged(): Promise<boolean> {
        return false;
    }

    public getCurrentConfig(): LoadedWorkflowConfig {
        return this.config;
    }

    public validateCurrentPreflight(): void {
        return;
    }

    public async buildDispatchPrompt(context: {issue: Record<string, unknown>; attempt: number}): Promise<{
        config: LoadedWorkflowConfig;
        prompt: string;
    }> {
        return {
            config: this.config,
            prompt: `Issue ${String(context.issue.identifier)} attempt ${context.attempt}`,
        };
    }
}

class TrackerStub implements TrackerClient {
    public candidates: TrackedIssue[] = [];
    public statesByIssueId = new Map<string, string>();
    public todoIssues: TrackedIssue[] = [];

    public async fetchCandidateIssues(): Promise<TrackedIssue[]> {
        return this.candidates;
    }

    public async fetchIssueStatesByIds(issueIds: string[]): Promise<Map<string, string>> {
        const mapped = new Map<string, string>();
        for (const issueId of issueIds) {
            mapped.set(issueId, this.statesByIssueId.get(issueId) ?? 'In Progress');
        }

        return mapped;
    }

    public async fetchIssueStatesByStateNames(stateNames: string[]): Promise<TrackedIssue[]> {
        if (stateNames.includes('Todo')) {
            return this.todoIssues;
        }

        return [];
    }
}

class WorkspaceStub implements WorkspaceClient {
    public cleanedPaths: string[] = [];

    public async prepareWorkspace(rawKey: string): Promise<{key: string; path: string; created: boolean}> {
        return {
            key: rawKey,
            path: `/tmp/symphony-workspaces/${rawKey}`,
            created: true,
        };
    }

    public async runBeforeRunHooks(_workspacePath: string): Promise<void> {
        return;
    }

    public async runAfterRunHooks(_workspacePath: string): Promise<void> {
        return;
    }

    public async cleanupTerminalWorkspace(workspacePath: string): Promise<void> {
        this.cleanedPaths.push(workspacePath);
    }
}

function createDeferred<T>(): {
    promise: Promise<T>;
    resolve: (value: T) => void;
    reject: (error: unknown) => void;
} {
    let resolveFn: ((value: T) => void) | undefined;
    let rejectFn: ((error: unknown) => void) | undefined;

    const promise = new Promise<T>((resolve, reject) => {
        resolveFn = resolve;
        rejectFn = reject;
    });

    return {
        promise,
        resolve(value: T) {
            resolveFn?.(value);
        },
        reject(error: unknown) {
            rejectFn?.(error);
        },
    };
}

test('runTick dispatches highest-priority eligible candidate and skips Todo-blocked issues', async () => {
    const tracker = new TrackerStub();
    tracker.candidates = [
        createIssue({
            id: 'a',
            identifier: 'ROB-13-A',
            priority: 2,
            createdAt: '2026-03-06T08:00:00.000Z',
        }),
        createIssue({
            id: 'b',
            identifier: 'ROB-13-B',
            priority: 1,
            createdAt: '2026-03-06T07:00:00.000Z',
            blockedBy: ['ROB-1'],
        }),
        createIssue({
            id: 'c',
            identifier: 'ROB-13-C',
            priority: 1,
            createdAt: '2026-03-06T07:30:00.000Z',
        }),
    ];
    tracker.todoIssues = [
        createIssue({
            id: 'todo-1',
            identifier: 'ROB-1',
            priority: 3,
            createdAt: '2026-03-01T00:00:00.000Z',
            stateName: 'Todo',
        }),
    ];

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    const dispatchRequests: Array<{issueIdentifier: string; attempt: number}> = [];
    const workspace = new WorkspaceStub();

    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub([]),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => workspace,
        appServerFactory: () => ({
            runTurn: async (request) => {
                dispatchRequests.push({
                    issueIdentifier: request.issueIdentifier,
                    attempt: request.attempt,
                });
                return {
                    status: 'completed',
                    outputText: 'ok',
                    threadId: 'thread-1',
                    turnId: 'turn-1',
                    sessionId: 'thread-1-turn-1',
                };
            },
        }),
    });

    await orchestrator.runTick();
    await orchestrator.shutdown();

    assert.deepEqual(dispatchRequests, [{issueIdentifier: 'ROB-13-C', attempt: 1}]);
    assert.equal(orchestrator.getSnapshot().retrying.length, 0);
    assert.equal(workspace.cleanedPaths.length, 1);
});

test('retry queue uses continuation delay first and exponential backoff afterwards', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'retry-1',
        identifier: 'ROB-13-RETRY',
        priority: 1,
        createdAt: '2026-03-06T08:00:00.000Z',
    });
    tracker.candidates = [issue];

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    const attempts: number[] = [];
    let now = Date.parse('2026-03-06T09:00:00.000Z');

    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub([]),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => new WorkspaceStub(),
        appServerFactory: () => ({
            runTurn: async (request) => {
                attempts.push(request.attempt);
                if (request.attempt < 3) {
                    throw new AppServerClientError('turn_failed', 'simulated failure');
                }

                return {
                    status: 'completed',
                    outputText: 'ok',
                    threadId: 'thread',
                    turnId: 'turn',
                    sessionId: 'thread-turn',
                };
            },
        }),
        nowMs: () => now,
    });

    await orchestrator.runTick();
    await orchestrator.shutdown();

    let snapshot = orchestrator.getSnapshot();
    assert.deepEqual(attempts, [1]);
    assert.equal(snapshot.retrying.length, 1);
    assert.equal(snapshot.retrying[0].attempt, 2);
    assert.equal(snapshot.retrying[0].availableAtIso, new Date(now + 1000).toISOString());

    await orchestrator.runTick();
    await orchestrator.shutdown();
    assert.deepEqual(attempts, [1]);

    now += 1000;
    await orchestrator.runTick();
    await orchestrator.shutdown();

    snapshot = orchestrator.getSnapshot();
    assert.deepEqual(attempts, [1, 2]);
    assert.equal(snapshot.retrying.length, 1);
    assert.equal(snapshot.retrying[0].attempt, 3);
    assert.equal(snapshot.retrying[0].availableAtIso, new Date(now + 2000).toISOString());

    now += 2000;
    await orchestrator.runTick();
    await orchestrator.shutdown();

    snapshot = orchestrator.getSnapshot();
    assert.deepEqual(attempts, [1, 2, 3]);
    assert.equal(snapshot.retrying.length, 0);
    assert.equal(snapshot.codexTotals.completed, 1);
    assert.equal(snapshot.codexTotals.failed, 2);
});

test('reconcile removes retries when issue leaves active states', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'terminal-1',
        identifier: 'ROB-13-TERMINAL',
        priority: 1,
        createdAt: '2026-03-06T08:00:00.000Z',
    });
    tracker.candidates = [issue];

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    const attempts: number[] = [];
    let now = Date.parse('2026-03-06T09:00:00.000Z');

    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub([]),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => new WorkspaceStub(),
        appServerFactory: () => ({
            runTurn: async (request) => {
                attempts.push(request.attempt);
                throw new AppServerClientError('turn_failed', 'simulated failure');
            },
        }),
        nowMs: () => now,
    });

    await orchestrator.runTick();
    await orchestrator.shutdown();

    let snapshot = orchestrator.getSnapshot();
    assert.equal(snapshot.retrying.length, 1);
    assert.equal(snapshot.retrying[0].attempt, 2);

    tracker.statesByIssueId.set(issue.id, 'Done');
    tracker.candidates = [];
    now += 1000;

    await orchestrator.runTick();
    await orchestrator.shutdown();

    snapshot = orchestrator.getSnapshot();
    assert.deepEqual(attempts, [1]);
    assert.equal(snapshot.retrying.length, 0);
});

test('maxConcurrent avoids double dispatch while issue is still running', async () => {
    const tracker = new TrackerStub();
    tracker.candidates = [
        createIssue({
            id: 'run-1',
            identifier: 'ROB-13-RUN-1',
            priority: 1,
            createdAt: '2026-03-06T08:00:00.000Z',
        }),
        createIssue({
            id: 'run-2',
            identifier: 'ROB-13-RUN-2',
            priority: 2,
            createdAt: '2026-03-06T08:01:00.000Z',
        }),
    ];

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    const firstDispatch = createDeferred<{
        status: 'completed' | 'input_required';
        outputText: string;
        threadId: string;
        turnId: string;
        sessionId: string;
    }>();
    const requests: string[] = [];
    let runCount = 0;

    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub([]),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => new WorkspaceStub(),
        appServerFactory: () => ({
            runTurn: async (request) => {
                requests.push(request.issueIdentifier);
                runCount += 1;

                if (runCount === 1) {
                    return firstDispatch.promise;
                }

                return {
                    status: 'completed',
                    outputText: 'ok',
                    threadId: 'thread-2',
                    turnId: 'turn-2',
                    sessionId: 'thread-2-turn-2',
                };
            },
        }),
    });

    await orchestrator.runTick();
    await new Promise((resolve) => setTimeout(resolve, 10));
    assert.deepEqual(requests, ['ROB-13-RUN-1']);

    await orchestrator.runTick();
    await new Promise((resolve) => setTimeout(resolve, 10));
    assert.deepEqual(requests, ['ROB-13-RUN-1']);

    firstDispatch.resolve({
        status: 'completed',
        outputText: 'ok',
        threadId: 'thread-1',
        turnId: 'turn-1',
        sessionId: 'thread-1-turn-1',
    });
    await orchestrator.shutdown();

    await orchestrator.runTick();
    await orchestrator.shutdown();

    assert.deepEqual(requests, ['ROB-13-RUN-1', 'ROB-13-RUN-2']);
});

test('workspace_path_escape failures are not retried', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'path-escape',
        identifier: 'ROB-13-PATH-ESCAPE',
        priority: 1,
        createdAt: '2026-03-06T08:00:00.000Z',
    });
    tracker.candidates = [issue];

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    let runTurnCalled = false;

    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub([]),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => ({
            prepareWorkspace: async () => {
                throw new WorkspaceManagerError('workspace_path_escape', 'workspace escaped root');
            },
            runBeforeRunHooks: async () => undefined,
            runAfterRunHooks: async () => undefined,
            cleanupTerminalWorkspace: async () => undefined,
        }),
        appServerFactory: () => ({
            runTurn: async () => {
                runTurnCalled = true;
                return {
                    status: 'completed',
                    outputText: 'ok',
                    threadId: 'thread',
                    turnId: 'turn',
                    sessionId: 'thread-turn',
                };
            },
        }),
    });

    await orchestrator.runTick();
    await orchestrator.shutdown();

    const snapshot = orchestrator.getSnapshot();
    assert.equal(runTurnCalled, false);
    assert.equal(snapshot.retrying.length, 0);
});
