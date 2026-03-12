import assert from 'node:assert/strict';
import {access, mkdir, mkdtemp, rm} from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import {AppServerClientError} from './app-server-client.js';
import {type TrackerClient, SymphonyOrchestrator, type WorkspaceClient} from './orchestrator.js';
import type {TrackedIssue} from './linear-tracker.js';
import type {Logger} from './logger.js';
import type {LoadedWorkflowConfig} from './workflow.js';
import {WorkspaceManagerError, type WorkspaceCleanupOptions, type WorkspaceStateSnapshot} from './workspace-manager.js';

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
    priority: number | null;
    createdAt: string;
    blockedBy?: string[];
    blockedByStates?: Record<string, string | null>;
    stateName?: string;
    description?: string | null;
}): TrackedIssue {
    return {
        id: args.id,
        identifier: args.identifier,
        title: args.identifier,
        description: args.description ?? null,
        stateName: args.stateName ?? 'In Progress',
        stateType: 'started',
        priority: args.priority,
        branchName: null,
        url: null,
        labels: [],
        blockedBy: (args.blockedBy ?? []).map((identifier) => ({
            id: null,
            identifier,
            state: args.blockedByStates?.[identifier] ?? null,
        })),
        blockedByIdentifiers: args.blockedBy ?? [],
        createdAt: args.createdAt,
        updatedAt: args.createdAt,
        projectSlug: 'forscherhaus',
        workpadCommentId: null,
        workpadCommentBody: null,
        workpadCommentUrl: null,
    };
}

function createWorkflowConfig(): LoadedWorkflowConfig {
    return {
        workflowPath: '/repo/WORKFLOW.md',
        loadedAtIso: '2026-03-06T00:00:00.000Z',
        promptTemplate: 'Issue {{issue.identifier}} attempt {{attempt}}',
        tracker: {
            kind: 'linear',
            provider: 'linear',
            endpoint: 'https://api.linear.app/graphql',
            apiKey: 'token',
            projectSlug: 'forscherhaus',
            activeStates: ['In Progress'],
            terminalStates: ['Done'],
            reviewStateName: 'In Review',
            mergeStateName: 'Ready to Merge',
        },
        polling: {
            intervalMs: 1000,
            maxCandidates: 20,
        },
        server: {},
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
            maxTurns: 1,
            maxRetryBackoffMs: 60000,
            maxConcurrentByState: {},
            commitRequiredStates: ['Todo', 'In Progress', 'Rework'],
        },
        codex: {
            command: 'codex app-server',
            readTimeoutMs: 2000,
            responseTimeoutMs: 2000,
            turnTimeoutMs: 5000,
            stallTimeoutMs: 1000,
            publishNetworkAccess: false,
        },
    };
}

class WorkflowStoreStub {
    public config: LoadedWorkflowConfig;
    public promptContexts: Array<{
        issue: Record<string, unknown>;
        attempt: number | null;
    }> = [];

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

    public async buildDispatchPrompt(context: {issue: Record<string, unknown>; attempt: number | null}): Promise<{
        config: LoadedWorkflowConfig;
        prompt: string;
    }> {
        this.promptContexts.push(context);
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
    public prepareIssueForRunCalls: string[] = [];
    public moveIssueToStateByNameCalls: Array<{identifier: string; stateName: string}> = [];
    public syncIssueWorkpadToStateCalls: Array<{identifier: string; stateName: string}> = [];

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

    public async prepareIssueForRun(issue: TrackedIssue): Promise<TrackedIssue> {
        this.prepareIssueForRunCalls.push(issue.identifier);
        return {
            ...issue,
            stateName: issue.stateName === 'Todo' ? 'In Progress' : issue.stateName,
            workpadCommentId: issue.workpadCommentId ?? `workpad-${issue.id}`,
            workpadCommentBody: issue.workpadCommentBody ?? '## Codex Workpad',
            workpadCommentUrl: issue.workpadCommentUrl,
        };
    }

    public async moveIssueToStateByName(issue: TrackedIssue, stateName: string): Promise<TrackedIssue> {
        this.moveIssueToStateByNameCalls.push({
            identifier: issue.identifier,
            stateName,
        });
        this.statesByIssueId.set(issue.id, stateName);
        return {
            ...issue,
            stateName,
        };
    }

    public async syncIssueWorkpadToState(issue: TrackedIssue): Promise<TrackedIssue> {
        this.syncIssueWorkpadToStateCalls.push({
            identifier: issue.identifier,
            stateName: issue.stateName,
        });

        return {
            ...issue,
            workpadCommentBody: `## Codex Workpad\n\nState: ${issue.stateName}`,
        };
    }
}

class WorkspaceStub implements WorkspaceClient {
    public cleanedPaths: string[] = [];
    public cleanupCalls: Array<{workspacePath: string; options?: WorkspaceCleanupOptions}> = [];
    public stateSnapshots: WorkspaceStateSnapshot[] = [
        {headSha: 'head-before', statusText: '', branchName: 'codex/symphony-test'},
        {headSha: 'head-after', statusText: '', branchName: 'codex/symphony-test'},
    ];
    public beforeRunEnvOverrides: Record<string, string | undefined> | undefined;
    private stateCaptureCount = 0;

    public async prepareWorkspace(rawKey: string): Promise<{key: string; path: string; created: boolean}> {
        return {
            key: rawKey,
            path: `/tmp/symphony-workspaces/${rawKey}`,
            created: true,
        };
    }

    public resolveWorkspacePath(rawKey: string): string {
        return `/tmp/symphony-workspaces/${rawKey}`;
    }

    public async runBeforeRunHooks(
        _workspacePath: string,
        envOverrides?: Record<string, string | undefined>,
    ): Promise<void> {
        this.beforeRunEnvOverrides = envOverrides;
        return;
    }

    public async runAfterRunHooks(_workspacePath: string): Promise<void> {
        return;
    }

    public async cleanupTerminalWorkspace(workspacePath: string, options?: WorkspaceCleanupOptions): Promise<void> {
        this.cleanedPaths.push(workspacePath);
        this.cleanupCalls.push({workspacePath, options});
    }

    public async captureWorkspaceState(_workspacePath: string): Promise<WorkspaceStateSnapshot> {
        const index = Math.min(this.stateCaptureCount, this.stateSnapshots.length - 1);
        this.stateCaptureCount += 1;
        return this.stateSnapshots[index];
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

test('runTick dispatches highest-priority eligible candidate and skips issues with non-terminal blockers', async () => {
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
            blockedByStates: {'ROB-1': 'In Progress'},
        }),
        createIssue({
            id: 'c',
            identifier: 'ROB-13-C',
            priority: 1,
            createdAt: '2026-03-06T07:30:00.000Z',
        }),
        createIssue({
            id: 'd',
            identifier: 'ROB-13-D',
            priority: 1,
            createdAt: '2026-03-06T06:30:00.000Z',
            blockedBy: ['ROB-2'],
            blockedByStates: {'ROB-2': 'Done'},
        }),
    ];
    tracker.statesByIssueId.set('c', 'Done');

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    const dispatchRequests: Array<{issueIdentifier: string; attempt: number | null}> = [];
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
                if (request.issueIdentifier === 'ROB-13-D') {
                    tracker.statesByIssueId.set('d', 'Done');
                }
                return {
                    status: 'completed',
                    outputText: 'ok',
                    threadId: 'thread-1',
                    turnId: 'turn-1',
                    sessionId: 'thread-1-turn-1',
                };
            },
            stop: async () => undefined,
        }),
    });

    await orchestrator.runTick();
    await orchestrator.shutdown();

    assert.deepEqual(dispatchRequests, [{issueIdentifier: 'ROB-13-D', attempt: null}]);
    assert.equal(orchestrator.getSnapshot().retrying.length, 0);
    assert.equal(workspace.cleanedPaths.length, 1);
});

test('worker reuses the same thread across continuation turns and only retries after maxTurns', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'multi-turn-1',
        identifier: 'ROB-13-MULTI',
        priority: 1,
        createdAt: '2026-03-06T08:00:00.000Z',
    });
    tracker.candidates = [issue];

    const config = createWorkflowConfig();
    config.agent.maxTurns = 2;
    const workflowStore = new WorkflowStoreStub(config);
    const workspace = new WorkspaceStub();
    const requests: Array<{attempt: number | null; prompt: string; threadId?: string}> = [];

    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub([]),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => workspace,
        appServerFactory: () => ({
            runTurn: async (request) => {
                requests.push({
                    attempt: request.attempt,
                    prompt: request.prompt,
                    threadId: request.threadId,
                });

                if (requests.length === 2) {
                    tracker.statesByIssueId.set(issue.id, 'Done');
                }

                return {
                    status: 'completed',
                    outputText: 'ok',
                    threadId: 'thread-shared',
                    turnId: `turn-${requests.length}`,
                    sessionId: `thread-shared-turn-${requests.length}`,
                };
            },
            stop: async () => undefined,
        }),
    });

    await orchestrator.runTick();
    await orchestrator.shutdown();

    const snapshot = orchestrator.getSnapshot();
    assert.equal(requests.length, 2);
    assert.equal(requests[0].threadId, undefined);
    assert.equal(requests[1].threadId, 'thread-shared');
    assert.match(requests[1].prompt, /Continuation guidance:/);
    assert.match(requests[1].prompt, /continuation turn 2 of 2/i);
    assert.match(requests[1].prompt, /do not end the turn while the issue stays active unless you are truly blocked/i);
    assert.equal(snapshot.retrying.length, 0);
    assert.equal(snapshot.counts.completed, 1);
    assert.equal(workspace.cleanedPaths.length, 1);
});

test('before_run hooks receive the Linear issue branch and prompt context uses the effective workspace branch', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'branch-alignment-1',
        identifier: 'ROB-13-BRANCH',
        priority: 1,
        createdAt: '2026-03-06T08:00:00.000Z',
    });
    issue.branchName = 'feature/rob-13-branch';
    tracker.candidates = [issue];
    tracker.statesByIssueId.set(issue.id, 'Done');

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    const workspace = new WorkspaceStub();
    workspace.stateSnapshots = [
        {headSha: 'head-before', statusText: '', branchName: 'beierrobin/rob-13-branch'},
        {headSha: 'head-after', statusText: '', branchName: 'beierrobin/rob-13-branch'},
    ];

    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub([]),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => workspace,
        appServerFactory: () => ({
            runTurn: async () => ({
                status: 'completed',
                outputText: 'ok',
                threadId: 'thread-1',
                turnId: 'turn-1',
                sessionId: 'thread-1-turn-1',
            }),
            stop: async () => undefined,
        }),
    });

    await orchestrator.runTick();
    await orchestrator.shutdown();

    assert.equal(workspace.beforeRunEnvOverrides?.SYMPHONY_ISSUE_BRANCH_NAME, 'feature/rob-13-branch');
    assert.equal(workflowStore.promptContexts[0].issue.branch_name, 'beierrobin/rob-13-branch');
    assert.equal(workflowStore.promptContexts[0].issue.branch_name_or_default, 'beierrobin/rob-13-branch');
});

test('completed run without committed workspace changes is retried and not counted as success', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'empty-output-1',
        identifier: 'ROB-13-EMPTY',
        priority: 1,
        createdAt: '2026-03-06T08:00:00.000Z',
    });
    tracker.candidates = [issue];

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    const workspace = new WorkspaceStub();
    workspace.stateSnapshots = [
        {headSha: 'same-head', statusText: '', branchName: 'codex/symphony-test'},
        {
            headSha: 'same-head',
            statusText: ' M docs/symphony/STAGING_PILOT_RUNBOOK.md',
            branchName: 'codex/symphony-test',
        },
    ];

    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub([]),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => workspace,
        appServerFactory: () => ({
            runTurn: async () => ({
                status: 'completed',
                outputText: 'ok',
                threadId: 'thread-1',
                turnId: 'turn-1',
                sessionId: 'thread-1-turn-1',
            }),
            stop: async () => undefined,
        }),
    });

    await orchestrator.runTick();
    await orchestrator.shutdown();

    const snapshot = orchestrator.getSnapshot();
    assert.equal(snapshot.counts.completed, 0);
    assert.equal(snapshot.counts.failed, 1);
    assert.equal(snapshot.retrying.length, 1);
    assert.equal(snapshot.retrying[0].errorClass, 'workspace_no_committed_output');
    assert.equal(workspace.cleanedPaths.length, 0);
});

test('state transition out of commit-required states counts as success without local commit', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'review-state-1',
        identifier: 'ROB-13-REVIEW',
        priority: 1,
        createdAt: '2026-03-06T08:00:00.000Z',
    });
    tracker.candidates = [issue];
    tracker.statesByIssueId.set(issue.id, 'In Review');

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    const workspace = new WorkspaceStub();
    workspace.stateSnapshots = [
        {headSha: 'same-head', statusText: '', branchName: 'codex/symphony-test'},
        {headSha: 'same-head', statusText: '', branchName: 'codex/symphony-test'},
    ];

    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub([]),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => workspace,
        appServerFactory: () => ({
            runTurn: async () => ({
                status: 'completed',
                outputText: 'opened pr',
                threadId: 'thread-1',
                turnId: 'turn-1',
                sessionId: 'thread-1-turn-1',
            }),
            stop: async () => undefined,
        }),
    });

    await orchestrator.runTick();
    await orchestrator.shutdown();

    const snapshot = orchestrator.getSnapshot();
    assert.equal(snapshot.counts.completed, 1);
    assert.equal(snapshot.counts.failed, 0);
    assert.equal(snapshot.retrying.length, 0);
    assert.equal(workspace.cleanedPaths.length, 0);
});

test('successful publish turn moves issue to In Review and stops the active run', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'publish-review-1',
        identifier: 'ROB-13-PUBLISH',
        priority: 1,
        createdAt: '2026-03-06T08:00:00.000Z',
    });
    tracker.candidates = [issue];
    tracker.statesByIssueId.set(issue.id, 'In Progress');

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    const workspace = new WorkspaceStub();
    workspace.stateSnapshots = [
        {headSha: 'head-before', statusText: '', branchName: 'beierrobin/rob-13-publish'},
        {headSha: 'head-after', statusText: '', branchName: 'beierrobin/rob-13-publish'},
        {headSha: 'head-after', statusText: '', branchName: 'beierrobin/rob-13-publish'},
    ];

    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub([]),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => workspace,
        appServerFactory: ({emitEvent}) => ({
            runTurn: async () => {
                emitEvent({
                    type: 'session',
                    threadId: 'thread-publish-review',
                    turnId: 'turn-1',
                    sessionId: 'thread-publish-review-turn-1',
                });
                emitEvent({
                    type: 'raw_event',
                    payload: {
                        method: 'codex/event/exec_command_end',
                        params: {
                            msg: {
                                command:
                                    "git push -u origin HEAD && gh pr create --base main --title 'Review-ready PR'",
                                exitCode: 0,
                            },
                        },
                    },
                });

                return {
                    status: 'completed',
                    outputText: 'published',
                    threadId: 'thread-publish-review',
                    turnId: 'turn-1',
                    sessionId: 'thread-publish-review-turn-1',
                };
            },
            stop: async () => undefined,
        }),
    });

    await orchestrator.runTick();
    await orchestrator.shutdown();

    const snapshot = orchestrator.getSnapshot();
    assert.deepEqual(tracker.moveIssueToStateByNameCalls, [
        {
            identifier: 'ROB-13-PUBLISH',
            stateName: 'In Review',
        },
    ]);
    assert.equal(snapshot.counts.completed, 1);
    assert.equal(snapshot.counts.failed, 0);
    assert.equal(snapshot.retrying.length, 0);
    assert.equal(workspace.cleanedPaths.length, 1);
    assert.deepEqual(workspace.cleanupCalls, [
        {
            workspacePath: '/tmp/symphony-workspaces/ROB-13-PUBLISH',
            options: {
                closeOpenPrs: false,
                reason: 'review_handoff',
            },
        },
    ]);
});

test('successful Linear review handoff during a publish turn stops immediately and syncs the workpad', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'review-handoff-1',
        identifier: 'ROB-13-REVIEW-HANDOFF',
        priority: 1,
        createdAt: '2026-03-06T08:00:00.000Z',
    });
    issue.branchName = 'beierrobin/rob-13-review-handoff';
    tracker.candidates = [issue];
    tracker.statesByIssueId.set(issue.id, 'In Progress');

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    const workspace = new WorkspaceStub();
    workspace.stateSnapshots = [
        {headSha: 'head-before', statusText: '', branchName: 'beierrobin/rob-13-review-handoff'},
        {headSha: 'head-after', statusText: '', branchName: 'beierrobin/rob-13-review-handoff'},
    ];
    const firstDispatch = createDeferred<{
        status: 'completed' | 'input_required';
        outputText: string;
        threadId: string;
        turnId: string;
        sessionId: string;
    }>();
    let stopCalls = 0;

    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub([]),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => workspace,
        appServerFactory: ({emitEvent}) => ({
            runTurn: async () => {
                emitEvent({
                    type: 'session',
                    threadId: 'thread-review',
                    turnId: 'turn-review',
                    sessionId: 'thread-review-turn-review',
                });
                tracker.statesByIssueId.set(issue.id, 'In Review');
                emitEvent({
                    type: 'raw_event',
                    payload: {
                        method: 'codex/event/exec_command_end',
                        params: {
                            msg: {
                                command:
                                    "git push -u origin HEAD && gh pr create --base main --title 'Review-ready PR'",
                                exitCode: 0,
                            },
                        },
                    },
                });
                emitEvent({
                    type: 'trace',
                    category: 'tool',
                    eventType: 'tool/call/responded',
                    message: 'Dynamic tool response sent for linear_graphql.',
                    details: {
                        tool: 'linear_graphql',
                        success: true,
                    },
                });

                return firstDispatch.promise;
            },
            stop: async () => {
                stopCalls += 1;
                firstDispatch.reject(
                    new AppServerClientError(
                        'turn_cancelled',
                        'App-server session was cancelled after review handoff.',
                    ),
                );
            },
        }),
    });

    await orchestrator.runTick();
    await new Promise((resolve) => setTimeout(resolve, 25));
    await orchestrator.shutdown();

    const snapshot = orchestrator.getSnapshot();
    assert.ok(stopCalls >= 1);
    assert.equal(snapshot.counts.completed, 1);
    assert.equal(snapshot.counts.failed, 0);
    assert.equal(snapshot.retrying.length, 0);
    assert.equal(workspace.cleanedPaths.length, 1);
    assert.deepEqual(workspace.cleanupCalls, [
        {
            workspacePath: '/tmp/symphony-workspaces/ROB-13-REVIEW-HANDOFF',
            options: {
                closeOpenPrs: false,
                reason: 'review_handoff',
            },
        },
    ]);
    assert.deepEqual(tracker.syncIssueWorkpadToStateCalls, [
        {
            identifier: 'ROB-13-REVIEW-HANDOFF',
            stateName: 'In Review',
        },
    ]);

    const issueDetails = orchestrator.getIssueDetails('ROB-13-REVIEW-HANDOFF');
    assert.ok(issueDetails);
    assert.equal(issueDetails?.status, 'completed');
    const terminal = issueDetails?.terminal as Record<string, unknown>;
    assert.equal(terminal.state, 'In Review');
    const trace = issueDetails?.trace as Record<string, unknown>;
    assert.ok(
        (trace.recent as Array<Record<string, unknown>>).some(
            (entry) => entry.eventType === 'turn/review_handoff_checkpoint',
        ),
    );
});

test('publish turn does not treat In Review as a successful handoff without push or PR evidence', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'review-handoff-no-evidence-1',
        identifier: 'ROB-65-REVIEW-HANDOFF-NO-EVIDENCE',
        priority: 1,
        createdAt: '2026-03-09T08:00:00.000Z',
    });
    issue.branchName = 'beierrobin/rob-65-review-handoff-no-evidence';
    tracker.candidates = [issue];
    tracker.statesByIssueId.set(issue.id, 'In Progress');

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    const workspace = new WorkspaceStub();
    workspace.stateSnapshots = [
        {headSha: 'head-before', statusText: '', branchName: 'beierrobin/rob-65-review-handoff-no-evidence'},
        {headSha: 'head-after', statusText: '', branchName: 'beierrobin/rob-65-review-handoff-no-evidence'},
    ];
    let stopCalls = 0;

    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub([]),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => workspace,
        appServerFactory: ({emitEvent}) => ({
            runTurn: async () => {
                emitEvent({
                    type: 'session',
                    threadId: 'thread-review-no-evidence',
                    turnId: 'turn-review-no-evidence',
                    sessionId: 'thread-review-no-evidence-turn-review-no-evidence',
                });
                tracker.statesByIssueId.set(issue.id, 'In Review');
                emitEvent({
                    type: 'trace',
                    category: 'tool',
                    eventType: 'tool/call/responded',
                    message: 'Dynamic tool response sent for linear_graphql.',
                    details: {
                        tool: 'linear_graphql',
                        success: true,
                    },
                });
                await new Promise((resolve) => setTimeout(resolve, 25));

                return {
                    status: 'completed',
                    outputText: 'state moved without publish evidence',
                    threadId: 'thread-review-no-evidence',
                    turnId: 'turn-review-no-evidence',
                    sessionId: 'thread-review-no-evidence-turn-review-no-evidence',
                };
            },
            stop: async () => {
                stopCalls += 1;
            },
        }),
    });

    await orchestrator.runTick();
    await orchestrator.shutdown();

    const snapshot = orchestrator.getSnapshot();
    assert.equal(stopCalls, 1);
    assert.equal(snapshot.counts.completed, 1);
    assert.equal(snapshot.counts.failed, 0);
    assert.equal(snapshot.retrying.length, 0);
    assert.equal(workspace.cleanedPaths.length, 0);
    assert.deepEqual(tracker.syncIssueWorkpadToStateCalls, [
        {
            identifier: 'ROB-65-REVIEW-HANDOFF-NO-EVIDENCE',
            stateName: 'In Review',
        },
    ]);

    const issueDetails = orchestrator.getIssueDetails('ROB-65-REVIEW-HANDOFF-NO-EVIDENCE');
    assert.ok(issueDetails);
    assert.equal(issueDetails?.status, 'completed');
    const trace = issueDetails?.trace as Record<string, unknown>;
    assert.equal(
        (trace.recent as Array<Record<string, unknown>>).some(
            (entry) => entry.eventType === 'turn/review_handoff_checkpoint',
        ),
        false,
    );
});

test('publish turn treats PR mutation evidence as a successful review handoff without branch push', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'review-handoff-pr-mutation-only-1',
        identifier: 'ROB-65-REVIEW-HANDOFF-PR-MUTATION-ONLY',
        priority: 1,
        createdAt: '2026-03-10T08:00:00.000Z',
    });
    issue.branchName = 'beierrobin/rob-65-review-handoff-pr-mutation-only';
    tracker.candidates = [issue];
    tracker.statesByIssueId.set(issue.id, 'In Progress');

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    const workspace = new WorkspaceStub();
    workspace.stateSnapshots = [
        {headSha: 'head-before', statusText: '', branchName: 'beierrobin/rob-65-review-handoff-pr-mutation-only'},
        {headSha: 'head-after', statusText: '', branchName: 'beierrobin/rob-65-review-handoff-pr-mutation-only'},
    ];
    const firstDispatch = createDeferred<{
        status: 'completed' | 'input_required';
        outputText: string;
        threadId: string;
        turnId: string;
        sessionId: string;
    }>();
    let stopCalls = 0;

    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub([]),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => workspace,
        appServerFactory: ({emitEvent}) => ({
            runTurn: async () => {
                emitEvent({
                    type: 'session',
                    threadId: 'thread-review-pr-mutation',
                    turnId: 'turn-review-pr-mutation',
                    sessionId: 'thread-review-pr-mutation-turn-review-pr-mutation',
                });
                tracker.statesByIssueId.set(issue.id, 'In Review');
                emitEvent({
                    type: 'raw_event',
                    payload: {
                        method: 'codex/event/exec_command_end',
                        params: {
                            msg: {
                                command: "gh pr edit --title 'Review-ready PR'",
                                exitCode: 0,
                            },
                        },
                    },
                });
                emitEvent({
                    type: 'trace',
                    category: 'tool',
                    eventType: 'tool/call/responded',
                    message: 'Dynamic tool response sent for linear_graphql.',
                    details: {
                        tool: 'linear_graphql',
                        success: true,
                    },
                });

                return firstDispatch.promise;
            },
            stop: async () => {
                stopCalls += 1;
                firstDispatch.reject(
                    new AppServerClientError(
                        'turn_cancelled',
                        'App-server session was cancelled after review handoff.',
                    ),
                );
            },
        }),
    });

    await orchestrator.runTick();
    await new Promise((resolve) => setTimeout(resolve, 25));
    await orchestrator.shutdown();

    const snapshot = orchestrator.getSnapshot();
    assert.ok(stopCalls >= 1);
    assert.equal(snapshot.counts.completed, 1);
    assert.equal(snapshot.counts.failed, 0);
    assert.equal(snapshot.retrying.length, 0);
    assert.deepEqual(tracker.syncIssueWorkpadToStateCalls, [
        {
            identifier: 'ROB-65-REVIEW-HANDOFF-PR-MUTATION-ONLY',
            stateName: 'In Review',
        },
    ]);
    assert.deepEqual(workspace.cleanupCalls, [
        {
            workspacePath: '/tmp/symphony-workspaces/ROB-65-REVIEW-HANDOFF-PR-MUTATION-ONLY',
            options: {
                closeOpenPrs: false,
                reason: 'review_handoff',
            },
        },
    ]);

    const issueDetails = orchestrator.getIssueDetails('ROB-65-REVIEW-HANDOFF-PR-MUTATION-ONLY');
    assert.ok(issueDetails);
    const trace = issueDetails?.trace as Record<string, unknown>;
    assert.ok(
        (trace.recent as Array<Record<string, unknown>>).some(
            (entry) => entry.eventType === 'turn/review_handoff_checkpoint',
        ),
    );
});

test('review handoff stays successful when workpad sync fails after moving to In Review', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'review-handoff-sync-failure-1',
        identifier: 'ROB-13-REVIEW-HANDOFF-SYNC-FAIL',
        priority: 1,
        createdAt: '2026-03-06T08:00:00.000Z',
    });
    issue.branchName = 'beierrobin/rob-13-review-handoff-sync-fail';
    tracker.candidates = [issue];
    tracker.statesByIssueId.set(issue.id, 'In Progress');
    tracker.syncIssueWorkpadToState = async (trackedIssue) => {
        tracker.syncIssueWorkpadToStateCalls.push({
            identifier: trackedIssue.identifier,
            stateName: trackedIssue.stateName,
        });
        throw new Error('Linear comment update failed');
    };

    const logRecords: Array<Record<string, unknown>> = [];
    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    const workspace = new WorkspaceStub();
    workspace.stateSnapshots = [
        {headSha: 'head-before', statusText: '', branchName: 'beierrobin/rob-13-review-handoff-sync-fail'},
        {headSha: 'head-after', statusText: '', branchName: 'beierrobin/rob-13-review-handoff-sync-fail'},
        {headSha: 'head-after', statusText: '', branchName: 'beierrobin/rob-13-review-handoff-sync-fail'},
    ];

    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub(logRecords),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => workspace,
        appServerFactory: ({emitEvent}) => ({
            runTurn: async () => {
                emitEvent({
                    type: 'session',
                    threadId: 'thread-review-sync-fail',
                    turnId: 'turn-1',
                    sessionId: 'thread-review-sync-fail-turn-1',
                });
                emitEvent({
                    type: 'raw_event',
                    payload: {
                        method: 'codex/event/exec_command_end',
                        params: {
                            msg: {
                                command:
                                    "git push -u origin HEAD && gh pr create --base main --title 'Review-ready PR'",
                                exitCode: 0,
                            },
                        },
                    },
                });

                return {
                    status: 'completed',
                    outputText: 'published',
                    threadId: 'thread-review-sync-fail',
                    turnId: 'turn-1',
                    sessionId: 'thread-review-sync-fail-turn-1',
                };
            },
            stop: async () => undefined,
        }),
    });

    await orchestrator.runTick();
    await orchestrator.shutdown();

    const snapshot = orchestrator.getSnapshot();
    assert.equal(snapshot.counts.completed, 1);
    assert.equal(snapshot.counts.failed, 0);
    assert.equal(snapshot.retrying.length, 0);
    assert.deepEqual(tracker.moveIssueToStateByNameCalls, [
        {
            identifier: 'ROB-13-REVIEW-HANDOFF-SYNC-FAIL',
            stateName: 'In Review',
        },
    ]);
    assert.deepEqual(tracker.syncIssueWorkpadToStateCalls, [
        {
            identifier: 'ROB-13-REVIEW-HANDOFF-SYNC-FAIL',
            stateName: 'In Review',
        },
    ]);
    assert.ok(
        logRecords.some(
            (record) =>
                record.level === 'warn' &&
                record.message === 'Failed to synchronize issue workpad after moving issue to review state.' &&
                record.errorClass === 'orchestrator_unknown_error' &&
                record.error === 'Linear comment update failed',
        ),
    );

    const issueDetails = orchestrator.getIssueDetails('ROB-13-REVIEW-HANDOFF-SYNC-FAIL');
    assert.ok(issueDetails);
    assert.equal(issueDetails?.status, 'completed');
    const terminal = issueDetails?.terminal as Record<string, unknown>;
    assert.equal(terminal.state, 'In Review');
});

test('helper-repo publish handoff stops on the real workspace and cleans temporary helper repos', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'helper-review-handoff-1',
        identifier: 'ROB-51-HELPER-HANDOFF',
        priority: 1,
        createdAt: '2026-03-08T08:00:00.000Z',
    });
    issue.branchName = 'beierrobin/rob-51-helper-handoff';
    tracker.candidates = [issue];
    tracker.statesByIssueId.set(issue.id, 'In Progress');

    const workspaceRoot = await mkdtemp(path.join(os.tmpdir(), 'symphony-helper-handoff-'));
    const workspacePath = path.join(workspaceRoot, issue.identifier);
    const helperRepoPath = path.join(workspacePath, '.git-codex-local-publish');
    await mkdir(helperRepoPath, {recursive: true});

    const workspace: WorkspaceClient = {
        async prepareWorkspace(rawKey: string) {
            assert.equal(rawKey, issue.identifier);
            return {
                key: rawKey,
                path: workspacePath,
                created: false,
            };
        },
        resolveWorkspacePath(rawKey: string) {
            return path.join(workspaceRoot, rawKey);
        },
        async runBeforeRunHooks() {
            return;
        },
        async runAfterRunHooks() {
            return;
        },
        async cleanupTerminalWorkspace() {
            return;
        },
        async captureWorkspaceState() {
            return {
                headSha: tracker.statesByIssueId.get(issue.id) === 'In Review' ? 'head-after' : 'head-before',
                statusText: '',
                branchName: 'beierrobin/rob-51-helper-handoff',
            };
        },
    };

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    const firstDispatch = createDeferred<{
        status: 'completed' | 'input_required';
        outputText: string;
        threadId: string;
        turnId: string;
        sessionId: string;
    }>();
    let stopCalls = 0;

    try {
        const orchestrator = new SymphonyOrchestrator({
            logger: createLoggerStub([]),
            workflowConfigStore: workflowStore,
            trackerFactory: () => tracker,
            workspaceFactory: () => workspace,
            appServerFactory: ({emitEvent}) => ({
                runTurn: async () => {
                    emitEvent({
                        type: 'session',
                        threadId: 'thread-helper-review',
                        turnId: 'turn-helper-review',
                        sessionId: 'thread-helper-review-turn-1',
                    });
                    tracker.statesByIssueId.set(issue.id, 'In Review');
                    emitEvent({
                        type: 'raw_event',
                        payload: {
                            method: 'codex/event/exec_command_end',
                            params: {
                                msg: {
                                    command:
                                        "git push -u origin HEAD && gh pr create --base main --title 'Review-ready PR'",
                                    cwd: helperRepoPath,
                                    exitCode: 0,
                                },
                            },
                        },
                    });

                    return firstDispatch.promise;
                },
                stop: async () => {
                    stopCalls += 1;
                    firstDispatch.reject(
                        new AppServerClientError(
                            'turn_cancelled',
                            'App-server session was cancelled after helper publish handoff.',
                        ),
                    );
                },
            }),
        });

        await orchestrator.runTick();
        await new Promise((resolve) => setTimeout(resolve, 25));
        await orchestrator.shutdown();

        const snapshot = orchestrator.getSnapshot();
        assert.ok(stopCalls >= 1);
        assert.equal(snapshot.counts.completed, 1);
        assert.equal(snapshot.counts.failed, 0);
        assert.equal(snapshot.retrying.length, 0);
        await assert.rejects(() => access(helperRepoPath));

        const issueDetails = orchestrator.getIssueDetails(issue.identifier);
        assert.ok(issueDetails);
        assert.equal(issueDetails?.status, 'completed');
        const terminal = issueDetails?.terminal as Record<string, unknown>;
        assert.equal(terminal.state, 'In Review');
        assert.equal(terminal.workspace_source_of_truth_confirmed, true);
        assert.deepEqual(terminal.helper_repo_paths, [helperRepoPath]);

        const trace = issueDetails?.trace as Record<string, unknown>;
        assert.ok(
            (trace.recent as Array<Record<string, unknown>>).some(
                (entry) => entry.eventType === 'workspace/helper_repo_cleaned',
            ),
        );
    } finally {
        await rm(workspaceRoot, {recursive: true, force: true});
    }
});

test('helper-repo review handoff does not stop early when the issue workspace is not the source of truth', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'helper-review-unconfirmed-1',
        identifier: 'ROB-51-HELPER-UNCONFIRMED',
        priority: 1,
        createdAt: '2026-03-08T08:00:00.000Z',
    });
    issue.branchName = 'beierrobin/rob-51-helper-unconfirmed';
    tracker.candidates = [issue];
    tracker.statesByIssueId.set(issue.id, 'In Progress');

    const workspaceRoot = await mkdtemp(path.join(os.tmpdir(), 'symphony-helper-unconfirmed-'));
    const workspacePath = path.join(workspaceRoot, issue.identifier);
    const helperRepoPath = path.join(workspacePath, '.git-codex-local-publish');
    await mkdir(helperRepoPath, {recursive: true});

    const workspace: WorkspaceClient = {
        async prepareWorkspace(rawKey: string) {
            assert.equal(rawKey, issue.identifier);
            return {
                key: rawKey,
                path: workspacePath,
                created: false,
            };
        },
        resolveWorkspacePath(rawKey: string) {
            return path.join(workspaceRoot, rawKey);
        },
        async runBeforeRunHooks() {
            return;
        },
        async runAfterRunHooks() {
            return;
        },
        async cleanupTerminalWorkspace() {
            return;
        },
        async captureWorkspaceState() {
            return {
                headSha: 'same-head',
                statusText: '',
                branchName: null,
            };
        },
    };

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    let stopCalls = 0;

    try {
        const orchestrator = new SymphonyOrchestrator({
            logger: createLoggerStub([]),
            workflowConfigStore: workflowStore,
            trackerFactory: () => tracker,
            workspaceFactory: () => workspace,
            appServerFactory: ({emitEvent}) => ({
                runTurn: async () => {
                    emitEvent({
                        type: 'session',
                        threadId: 'thread-helper-review-unconfirmed',
                        turnId: 'turn-helper-review-unconfirmed',
                        sessionId: 'thread-helper-review-unconfirmed-turn-1',
                    });
                    tracker.statesByIssueId.set(issue.id, 'In Review');
                    emitEvent({
                        type: 'raw_event',
                        payload: {
                            method: 'codex/event/exec_command_end',
                            params: {
                                msg: {
                                    command:
                                        "git push -u origin HEAD && gh pr create --base main --title 'Review-ready PR'",
                                    cwd: helperRepoPath,
                                    exitCode: 0,
                                },
                            },
                        },
                    });

                    return {
                        status: 'completed',
                        outputText: 'helper publish finished',
                        threadId: 'thread-helper-review-unconfirmed',
                        turnId: 'turn-helper-review-unconfirmed',
                        sessionId: 'thread-helper-review-unconfirmed-turn-1',
                    };
                },
                stop: async () => {
                    stopCalls += 1;
                },
            }),
        });

        await orchestrator.runTick();
        await orchestrator.shutdown();

        assert.equal(stopCalls, 1);
        const issueDetails = orchestrator.getIssueDetails(issue.identifier);
        assert.ok(issueDetails);
        assert.equal(issueDetails?.status, 'completed');
        const terminal = issueDetails?.terminal as Record<string, unknown>;
        assert.equal(terminal.workspace_source_of_truth_confirmed, false);
        await access(helperRepoPath);

        const trace = issueDetails?.trace as Record<string, unknown>;
        assert.ok(
            (trace.recent as Array<Record<string, unknown>>).some(
                (entry) => entry.eventType === 'workspace/review_handoff_skipped_unconfirmed_source',
            ),
        );
    } finally {
        await rm(workspaceRoot, {recursive: true, force: true});
    }
});

test('helper-repo review handoff does not stop early when the issue workspace is still dirty', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'helper-review-dirty-1',
        identifier: 'ROB-51-HELPER-DIRTY',
        priority: 1,
        createdAt: '2026-03-08T08:00:00.000Z',
    });
    issue.branchName = 'beierrobin/rob-51-helper-dirty';
    tracker.candidates = [issue];
    tracker.statesByIssueId.set(issue.id, 'In Progress');

    const workspaceRoot = await mkdtemp(path.join(os.tmpdir(), 'symphony-helper-dirty-'));
    const workspacePath = path.join(workspaceRoot, issue.identifier);
    const helperRepoPath = path.join(workspacePath, '.git-codex-local-publish');
    await mkdir(helperRepoPath, {recursive: true});

    let stateCaptureCount = 0;
    const workspace: WorkspaceClient = {
        async prepareWorkspace(rawKey: string) {
            assert.equal(rawKey, issue.identifier);
            return {
                key: rawKey,
                path: workspacePath,
                created: false,
            };
        },
        resolveWorkspacePath(rawKey: string) {
            return path.join(workspaceRoot, rawKey);
        },
        async runBeforeRunHooks() {
            return;
        },
        async runAfterRunHooks() {
            return;
        },
        async cleanupTerminalWorkspace() {
            return;
        },
        async captureWorkspaceState() {
            stateCaptureCount += 1;
            return {
                headSha: stateCaptureCount === 1 ? 'head-before' : 'head-after',
                statusText: stateCaptureCount >= 3 ? ' M tools/symphony/src/orchestrator.ts' : '',
                branchName: 'beierrobin/rob-51-helper-dirty',
            };
        },
    };

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    let stopCalls = 0;

    try {
        const orchestrator = new SymphonyOrchestrator({
            logger: createLoggerStub([]),
            workflowConfigStore: workflowStore,
            trackerFactory: () => tracker,
            workspaceFactory: () => workspace,
            appServerFactory: ({emitEvent}) => ({
                runTurn: async () => {
                    emitEvent({
                        type: 'session',
                        threadId: 'thread-helper-review-dirty',
                        turnId: 'turn-helper-review-dirty',
                        sessionId: 'thread-helper-review-dirty-turn-1',
                    });
                    tracker.statesByIssueId.set(issue.id, 'In Review');
                    emitEvent({
                        type: 'raw_event',
                        payload: {
                            method: 'codex/event/exec_command_end',
                            params: {
                                msg: {
                                    command:
                                        "git push -u origin HEAD && gh pr create --base main --title 'Review-ready PR'",
                                    cwd: helperRepoPath,
                                    exitCode: 0,
                                },
                            },
                        },
                    });

                    return {
                        status: 'completed',
                        outputText: 'helper publish finished',
                        threadId: 'thread-helper-review-dirty',
                        turnId: 'turn-helper-review-dirty',
                        sessionId: 'thread-helper-review-dirty-turn-1',
                    };
                },
                stop: async () => {
                    stopCalls += 1;
                },
            }),
        });

        await orchestrator.runTick();
        await orchestrator.shutdown();

        assert.equal(stopCalls, 1);
        const issueDetails = orchestrator.getIssueDetails(issue.identifier);
        assert.ok(issueDetails);
        assert.equal(issueDetails?.status, 'completed');
        const trace = issueDetails?.trace as Record<string, unknown>;
        assert.ok(
            (trace.recent as Array<Record<string, unknown>>).some(
                (entry) => entry.eventType === 'workspace/review_handoff_skipped_dirty',
            ),
        );
    } finally {
        await rm(workspaceRoot, {recursive: true, force: true});
    }
});

test('non-commit merge states can continue without local commit output', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'merge-state-1',
        identifier: 'ROB-13-MERGE',
        priority: 1,
        createdAt: '2026-03-06T08:00:00.000Z',
        stateName: 'Ready to Merge',
    });
    tracker.candidates = [issue];
    tracker.statesByIssueId.set(issue.id, 'Ready to Merge');

    const config = createWorkflowConfig();
    config.tracker.activeStates = ['In Progress', 'Ready to Merge'];
    config.agent.commitRequiredStates = ['Todo', 'In Progress', 'Rework'];
    const workflowStore = new WorkflowStoreStub(config);
    const workspace = new WorkspaceStub();
    workspace.stateSnapshots = [
        {headSha: 'same-head', statusText: '', branchName: 'codex/symphony-test'},
        {headSha: 'same-head', statusText: '', branchName: 'codex/symphony-test'},
    ];

    const attempts: Array<number | null> = [];
    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub([]),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => workspace,
        appServerFactory: () => ({
            runTurn: async (request) => {
                attempts.push(request.attempt);
                return {
                    status: 'completed',
                    outputText: 'waiting for merge',
                    threadId: 'thread-1',
                    turnId: 'turn-1',
                    sessionId: 'thread-1-turn-1',
                };
            },
            stop: async () => undefined,
        }),
    });

    await orchestrator.runTick();
    await orchestrator.shutdown();

    const snapshot = orchestrator.getSnapshot();
    assert.deepEqual(attempts, [null]);
    assert.equal(snapshot.counts.completed, 1);
    assert.equal(snapshot.counts.failed, 0);
    assert.equal(snapshot.retrying.length, 1);
    assert.equal(snapshot.retrying[0].reason, 'continuation');
});

test('turn_timeout preserves workspace for debugging and schedules a retry', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'timeout-1',
        identifier: 'ROB-13-TIMEOUT',
        priority: 1,
        createdAt: '2026-03-06T08:00:00.000Z',
    });
    tracker.candidates = [issue];

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    const workspace = new WorkspaceStub();

    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub([]),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => workspace,
        appServerFactory: () => ({
            runTurn: async () => {
                throw new AppServerClientError('turn_timeout', 'App-server turn exceeded turn timeout.');
            },
            stop: async () => undefined,
        }),
    });

    await orchestrator.runTick();
    await orchestrator.shutdown();

    const snapshot = orchestrator.getSnapshot();
    assert.equal(snapshot.counts.completed, 0);
    assert.equal(snapshot.counts.failed, 1);
    assert.equal(snapshot.counts.turn_timeouts, 1);
    assert.equal(snapshot.retrying.length, 1);
    assert.equal(snapshot.retrying[0].errorClass, 'turn_timeout');
    assert.equal(workspace.cleanedPaths.length, 0);
});

test('turn_timeout in review state is not misclassified as a successful review handoff', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'timeout-review-1',
        identifier: 'ROB-53-TIMEOUT-REVIEW',
        priority: 1,
        createdAt: '2026-03-08T08:00:00.000Z',
        stateName: 'In Review',
    });
    issue.branchName = 'beierrobin/rob-53-timeout-review';
    tracker.candidates = [issue];
    tracker.statesByIssueId.set(issue.id, 'In Review');

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    const workspace = new WorkspaceStub();
    const logRecords: Array<Record<string, unknown>> = [];

    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub(logRecords),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => workspace,
        appServerFactory: () => ({
            runTurn: async () => {
                throw new AppServerClientError('turn_timeout', 'App-server turn exceeded turn timeout.');
            },
            stop: async () => undefined,
        }),
    });

    await orchestrator.runTick();
    await orchestrator.shutdown();

    const snapshot = orchestrator.getSnapshot();
    assert.equal(snapshot.counts.completed, 0);
    assert.equal(snapshot.counts.failed, 1);
    assert.equal(snapshot.counts.turn_timeouts, 1);
    assert.equal(snapshot.retrying.length, 1);
    assert.equal(workspace.cleanedPaths.length, 0);

    const issueDetails = orchestrator.getIssueDetails(issue.identifier);
    assert.ok(issueDetails);
    assert.equal(issueDetails?.status, 'retrying');
    const health = issueDetails?.health as Record<string, unknown>;
    assert.equal(health.overall, 'error');
    assert.ok(
        logRecords.some(
            (record) =>
                record.level === 'error' &&
                record.message === 'Issue dispatch failed' &&
                record.errorClass === 'turn_timeout',
        ),
    );
});

test('approval_required preserves workspace and does not schedule a retry', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'approval-1',
        identifier: 'ROB-13-APPROVAL',
        priority: 1,
        createdAt: '2026-03-06T08:00:00.000Z',
    });
    tracker.candidates = [issue];

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    const workspace = new WorkspaceStub();

    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub([]),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => workspace,
        appServerFactory: () => ({
            runTurn: async () => {
                throw new AppServerClientError(
                    'approval_required',
                    'Codex app-server requested approval but the current approval policy does not allow auto-approval.',
                );
            },
            stop: async () => undefined,
        }),
    });

    await orchestrator.runTick();
    await orchestrator.shutdown();

    const snapshot = orchestrator.getSnapshot();
    assert.equal(snapshot.counts.failed, 1);
    assert.equal(snapshot.retrying.length, 0);
    assert.equal(workspace.cleanedPaths.length, 0);
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
    const attempts: Array<number | null> = [];
    let now = Date.parse('2026-03-06T09:00:00.000Z');

    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub([]),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => new WorkspaceStub(),
        appServerFactory: () => ({
            runTurn: async (request) => {
                attempts.push(request.attempt);
                if ((request.attempt ?? 0) < 2) {
                    throw new AppServerClientError('turn_failed', 'simulated failure');
                }

                tracker.statesByIssueId.set(issue.id, 'Done');

                return {
                    status: 'completed',
                    outputText: 'ok',
                    threadId: 'thread',
                    turnId: 'turn',
                    sessionId: 'thread-turn',
                };
            },
            stop: async () => undefined,
        }),
        nowMs: () => now,
    });

    await orchestrator.runTick();
    await orchestrator.shutdown();

    let snapshot = orchestrator.getSnapshot();
    assert.deepEqual(attempts, [null]);
    assert.equal(snapshot.retrying.length, 1);
    assert.equal(snapshot.retrying[0].attempt, 1);
    assert.equal(snapshot.retrying[0].available_at, new Date(now + 10000).toISOString());
    assert.equal(snapshot.retrying[0].availableAtIso, new Date(now + 10000).toISOString());

    await orchestrator.runTick();
    await orchestrator.shutdown();
    assert.deepEqual(attempts, [null]);

    now += 10000;
    await orchestrator.runTick();
    await orchestrator.shutdown();

    snapshot = orchestrator.getSnapshot();
    assert.deepEqual(attempts, [null, 1]);
    assert.equal(snapshot.retrying.length, 1);
    assert.equal(snapshot.retrying[0].attempt, 2);
    assert.equal(snapshot.retrying[0].available_at, new Date(now + 20000).toISOString());
    assert.equal(snapshot.retrying[0].availableAtIso, new Date(now + 20000).toISOString());

    now += 20000;
    await orchestrator.runTick();
    await orchestrator.shutdown();

    snapshot = orchestrator.getSnapshot();
    assert.deepEqual(attempts, [null, 1, 2]);
    assert.equal(snapshot.retrying.length, 0);
    assert.equal(snapshot.counts.completed, 1);
    assert.equal(snapshot.counts.failed, 2);
});

test('first-turn repo diffs do not trigger a synthetic checkpoint retry', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'post-diff-checkpoint-1',
        identifier: 'ROB-13-POST-DIFF',
        priority: 1,
        createdAt: '2026-03-06T08:00:00.000Z',
        description: 'Update `docs/symphony/STAGING_PILOT_RUNBOOK.md` only.',
    });
    tracker.candidates = [issue];

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    const workspace = new WorkspaceStub();
    workspace.stateSnapshots = [
        {headSha: 'head-before', statusText: '', branchName: 'codex/symphony-test'},
        {
            headSha: 'head-before',
            statusText: ' M docs/symphony/STAGING_PILOT_RUNBOOK.md',
            branchName: 'codex/symphony-test',
        },
    ];

    const logRecords: Array<Record<string, unknown>> = [];

    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub(logRecords),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => workspace,
        appServerFactory: ({emitEvent}) => ({
            runTurn: async () => {
                emitEvent({
                    type: 'session',
                    threadId: 'thread-post-diff',
                    turnId: 'turn-1',
                    sessionId: 'thread-post-diff-turn-1',
                });
                emitEvent({
                    type: 'raw_event',
                    payload: {
                        method: 'codex/event/diff',
                        params: {
                            msg: {
                                payload: {
                                    summary: 'updated docs/symphony/STAGING_PILOT_RUNBOOK.md',
                                },
                            },
                        },
                    },
                });
                await new Promise((resolve) => setTimeout(resolve, 5));
                emitEvent({
                    type: 'token_usage',
                    payload: {
                        total: {
                            totalTokens: 100000,
                        },
                        last: {
                            totalTokens: 1600,
                        },
                        model_context_window: 258400,
                    },
                });
                tracker.statesByIssueId.set(issue.id, 'Done');
                return {
                    status: 'completed',
                    outputText: 'updated docs',
                    threadId: 'thread-post-diff',
                    turnId: 'turn-1',
                    sessionId: 'thread-post-diff-turn-1',
                };
            },
            stop: async () => undefined,
        }),
    });

    await orchestrator.runTick();
    await orchestrator.shutdown();

    const snapshot = orchestrator.getSnapshot();
    assert.equal(snapshot.counts.completed, 1);
    assert.equal(snapshot.counts.failed, 0);
    assert.equal(snapshot.retrying.length, 0);
    assert.ok(
        !logRecords.some(
            (record) =>
                record.level === 'info' &&
                record.message === 'Stopping issue after first repo diff to continue in a narrower follow-up turn.',
        ),
    );

    const issueDetails = orchestrator.getIssueDetails('ROB-13-POST-DIFF');
    assert.ok(issueDetails);
    assert.equal(issueDetails?.status, 'completed');
});

test('prompt target path extraction ignores shell commands in backticks', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'target-path-filter-1',
        identifier: 'ROB-13-TARGET-FILTER',
        priority: 1,
        createdAt: '2026-03-06T08:00:00.000Z',
        description:
            '## Scope\n\n- Update `deptrac.yaml`.\n\n## Validation\n\n- `PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh`',
    });
    tracker.candidates = [issue];

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());

    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub([]),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => new WorkspaceStub(),
        appServerFactory: () => ({
            runTurn: async () => {
                tracker.statesByIssueId.set(issue.id, 'Done');
                return {
                    status: 'completed',
                    outputText: 'updated deptrac',
                    threadId: 'thread-target',
                    turnId: 'turn-target',
                    sessionId: 'thread-target-turn-target',
                };
            },
            stop: async () => {},
        }),
    });

    await orchestrator.runTick();
    await orchestrator.shutdown();

    assert.equal(workflowStore.promptContexts[0].issue.first_repo_target_path_or_default, 'deptrac.yaml');
    assert.doesNotMatch(
        String(workflowStore.promptContexts[0].issue.target_paths_hint_or_default),
        /PRE_PR_RUN_COVERAGE=1 bash/,
    );
});

test('tracked issue branches start in publish-capable mode', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'tracked-branch-1',
        identifier: 'ROB-13-BRANCH',
        priority: 1,
        createdAt: '2026-03-06T08:00:00.000Z',
        description: 'Publish the existing branch.',
        stateName: 'In Progress',
    });
    issue.branchName = 'beierrobin/rob-13-existing';
    tracker.candidates = [issue];

    const requests: Array<{publishMode?: boolean}> = [];
    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub([]),
        workflowConfigStore: new WorkflowStoreStub(createWorkflowConfig()),
        trackerFactory: () => tracker,
        workspaceFactory: () => new WorkspaceStub(),
        appServerFactory: () => ({
            runTurn: async (request) => {
                requests.push({publishMode: request.publishMode});
                return {
                    status: 'completed',
                    outputText: 'Publish-capable branch turn.',
                    threadId: 'thread-branch',
                    turnId: 'turn-branch',
                    sessionId: 'thread-branch-turn-branch',
                };
            },
            stop: async () => {},
        }),
    });

    await orchestrator.runTick();
    await orchestrator.shutdown();

    assert.equal(requests[0]?.publishMode, true);
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
    const attempts: Array<number | null> = [];
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
            stop: async () => undefined,
        }),
        nowMs: () => now,
    });

    await orchestrator.runTick();
    await orchestrator.shutdown();

    let snapshot = orchestrator.getSnapshot();
    assert.equal(snapshot.retrying.length, 1);
    assert.equal(snapshot.retrying[0].attempt, 1);

    tracker.statesByIssueId.set(issue.id, 'Done');
    tracker.candidates = [];
    now += 10000;

    await orchestrator.runTick();
    await orchestrator.shutdown();

    snapshot = orchestrator.getSnapshot();
    assert.deepEqual(attempts, [null]);
    assert.equal(snapshot.retrying.length, 0);
});

test('dispatch prepares Todo issues for the first turn and exposes workpad context to the prompt builder', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'bootstrap-1',
        identifier: 'ROB-13-BOOTSTRAP',
        priority: 1,
        createdAt: '2026-03-06T08:00:00.000Z',
        stateName: 'Todo',
        description: '## Scope\n\n- Update `docs/symphony/STAGING_PILOT_RUNBOOK.md`.\n- Mention `.env.symphony.pilot`.',
    });
    tracker.candidates = [issue];

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    let firstPrompt = '';
    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub([]),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => new WorkspaceStub(),
        appServerFactory: () => ({
            runTurn: async (request) => {
                firstPrompt = request.prompt;
                return {
                    status: 'completed',
                    outputText: 'ok',
                    threadId: 'thread-bootstrap',
                    turnId: 'turn-1',
                    sessionId: 'thread-bootstrap-turn-1',
                };
            },
            stop: async () => undefined,
        }),
    });

    await orchestrator.runTick();
    await orchestrator.shutdown();

    assert.deepEqual(tracker.prepareIssueForRunCalls, ['ROB-13-BOOTSTRAP']);
    assert.equal(workflowStore.promptContexts.length, 1);
    assert.equal(workflowStore.promptContexts[0].issue.state, 'In Progress');
    assert.equal(
        workflowStore.promptContexts[0].issue.description_or_default,
        '## Scope\n\n- Update `docs/symphony/STAGING_PILOT_RUNBOOK.md`.\n- Mention `.env.symphony.pilot`.',
    );
    assert.equal(workflowStore.promptContexts[0].issue.workpad_comment_id, 'workpad-bootstrap-1');
    assert.equal(workflowStore.promptContexts[0].issue.workpad_comment_body, '## Codex Workpad');
    assert.equal(workflowStore.promptContexts[0].issue.workpad_comment_body_or_default, '## Codex Workpad');
    assert.equal(
        workflowStore.promptContexts[0].issue.target_paths_hint_or_default,
        '- docs/symphony/STAGING_PILOT_RUNBOOK.md\n- .env.symphony.pilot',
    );
    assert.equal(
        workflowStore.promptContexts[0].issue.first_repo_target_path_or_default,
        'docs/symphony/STAGING_PILOT_RUNBOOK.md',
    );
    assert.match(
        String(workflowStore.promptContexts[0].issue.first_repo_step_contract_or_default),
        /open and edit `docs\/symphony\/STAGING_PILOT_RUNBOOK\.md`/,
    );
    assert.doesNotMatch(firstPrompt, /Runtime first-turn directive:/);
    assert.equal(firstPrompt, 'Issue ROB-13-BOOTSTRAP attempt null');
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
            stop: async () => undefined,
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

test('reconciliation stops a running issue that moved to a terminal state and cleans the workspace', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'terminal-stop-1',
        identifier: 'ROB-13-STOP',
        priority: 1,
        createdAt: '2026-03-06T08:00:00.000Z',
    });
    tracker.candidates = [issue];

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    const workspace = new WorkspaceStub();
    const firstDispatch = createDeferred<{
        status: 'completed' | 'input_required';
        outputText: string;
        threadId: string;
        turnId: string;
        sessionId: string;
    }>();
    let stopCalls = 0;

    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub([]),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => workspace,
        appServerFactory: () => ({
            runTurn: async () => firstDispatch.promise,
            stop: async () => {
                stopCalls += 1;
                firstDispatch.reject(
                    new AppServerClientError('turn_cancelled', 'App-server session was cancelled by reconciliation.'),
                );
            },
        }),
    });

    await orchestrator.runTick();
    await new Promise((resolve) => setTimeout(resolve, 10));

    tracker.statesByIssueId.set(issue.id, 'Done');
    tracker.candidates = [];
    await orchestrator.runTick();
    await orchestrator.shutdown();

    const snapshot = orchestrator.getSnapshot();
    assert.ok(stopCalls >= 1);
    assert.equal(snapshot.counts.completed, 0);
    assert.equal(snapshot.counts.failed, 0);
    assert.equal(snapshot.retrying.length, 0);
    assert.equal(workspace.cleanedPaths.length, 1);
});

test('merge-triggered terminal reconciliation is treated as a successful completion', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'terminal-merge-1',
        identifier: 'ROB-13-MERGE',
        priority: 1,
        stateName: 'Ready to Merge',
        createdAt: '2026-03-06T08:00:00.000Z',
    });
    issue.branchName = 'beierrobin/rob-13-merge';
    tracker.candidates = [issue];
    tracker.statesByIssueId.set(issue.id, 'Ready to Merge');

    const config = createWorkflowConfig();
    config.tracker.activeStates = ['In Progress', 'Ready to Merge'];
    const workflowStore = new WorkflowStoreStub(config);
    const workspace = new WorkspaceStub();
    const firstDispatch = createDeferred<{
        status: 'completed' | 'input_required';
        outputText: string;
        threadId: string;
        turnId: string;
        sessionId: string;
    }>();
    let stopCalls = 0;

    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub([]),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => workspace,
        appServerFactory: ({emitEvent}) => ({
            runTurn: async () => {
                emitEvent({
                    type: 'session',
                    threadId: 'thread-merge',
                    turnId: 'turn-1',
                    sessionId: 'thread-merge-turn-1',
                });
                emitEvent({
                    type: 'raw_event',
                    payload: {
                        method: 'codex/event/exec_command_end',
                        params: {
                            msg: {
                                command: '/bin/bash -lc gh pr merge 135 --squash --delete-branch',
                                cwd: '/tmp/symphony-workspaces/ROB-13-MERGE',
                                exitCode: 1,
                            },
                        },
                    },
                });

                return firstDispatch.promise;
            },
            stop: async () => {
                stopCalls += 1;
                firstDispatch.reject(
                    new AppServerClientError('turn_cancelled', 'App-server session was cancelled after merge.'),
                );
            },
        }),
    });

    await orchestrator.runTick();
    await new Promise((resolve) => setTimeout(resolve, 10));

    tracker.statesByIssueId.set(issue.id, 'Done');
    tracker.candidates = [];
    await orchestrator.runTick();
    await orchestrator.shutdown();

    const snapshot = orchestrator.getSnapshot();
    assert.ok(stopCalls >= 1);
    assert.equal(snapshot.counts.completed, 1);
    assert.equal(snapshot.counts.failed, 0);
    assert.equal(snapshot.retrying.length, 0);
    assert.equal(workspace.cleanedPaths.length, 1);

    const issueDetails = orchestrator.getIssueDetails('ROB-13-MERGE');
    assert.ok(issueDetails);
    assert.equal(issueDetails?.status, 'completed');
    assert.equal(issueDetails?.error_class, null);
    assert.equal(issueDetails?.error, null);
    const terminal = issueDetails?.terminal as Record<string, unknown>;
    assert.equal(terminal.state, 'Done');
    const trace = issueDetails?.trace as Record<string, unknown>;
    assert.ok(
        (trace.recent as Array<Record<string, unknown>>).some(
            (entry) => entry.eventType === 'dispatch/completed_after_terminal_merge',
        ),
    );
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
            resolveWorkspacePath: (rawKey: string) => `/tmp/symphony-workspaces/${rawKey}`,
            runBeforeRunHooks: async () => undefined,
            runAfterRunHooks: async () => undefined,
            cleanupTerminalWorkspace: async () => undefined,
            captureWorkspaceState: async () => ({headSha: 'head', statusText: '', branchName: null}),
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
            stop: async () => undefined,
        }),
    });

    await orchestrator.runTick();
    await orchestrator.shutdown();

    const snapshot = orchestrator.getSnapshot();
    assert.equal(runTurnCalled, false);
    assert.equal(snapshot.retrying.length, 0);
});

test('running snapshot surfaces humanized activity and context headroom', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'telemetry-1',
        identifier: 'ROB-13-TELEMETRY',
        priority: 1,
        createdAt: '2026-03-06T08:00:00.000Z',
    });
    tracker.candidates = [issue];

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    const workspace = new WorkspaceStub();
    const firstDispatch = createDeferred<{
        status: 'completed' | 'input_required';
        outputText: string;
        threadId: string;
        turnId: string;
        sessionId: string;
    }>();
    let now = Date.parse('2026-03-06T09:00:00.000Z');

    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub([]),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => workspace,
        appServerFactory: ({emitEvent}) => ({
            runTurn: async () => {
                emitEvent({
                    type: 'session',
                    threadId: 'thread-ctx',
                    turnId: 'turn-1',
                    sessionId: 'thread-ctx-turn-1',
                });
                emitEvent({
                    type: 'token_usage',
                    payload: {
                        total: {
                            inputTokens: 121000,
                            outputTokens: 72468,
                            totalTokens: 193468,
                        },
                        last: {
                            totalTokens: 1440,
                        },
                        model_context_window: 258400,
                    },
                });
                emitEvent({
                    type: 'raw_event',
                    payload: {
                        method: 'item/agentMessage/delta',
                    },
                });

                return firstDispatch.promise;
            },
            stop: async () => undefined,
        }),
        nowMs: () => now,
    });

    await orchestrator.runTick();
    await new Promise((resolve) => setTimeout(resolve, 10));

    now += 42000;

    const snapshot = orchestrator.getSnapshot();
    assert.equal(snapshot.generated_at, '2026-03-06T09:00:42.000Z');
    assert.equal(snapshot.last_tick_at, '2026-03-06T09:00:00.000Z');
    assert.equal(snapshot.lastTickAtIso, '2026-03-06T09:00:00.000Z');
    assert.equal(snapshot.counts.running, 1);
    assert.equal(snapshot.counts.retrying, 0);
    assert.equal(snapshot.running.length, 1);
    assert.equal(snapshot.running[0].issue_identifier, 'ROB-13-TELEMETRY');
    assert.equal(snapshot.running[0].thread_id, 'thread-ctx');
    assert.equal(snapshot.running[0].last_event, 'item/agentMessage/delta');
    assert.equal(snapshot.running[0].last_activity, 'Codex is streaming a response.');
    assert.equal(snapshot.running[0].runtime_seconds, 42);
    assert.equal(snapshot.running[0].idle_seconds, 42);
    assert.equal(snapshot.running[0].total_tokens, 193468);
    assert.equal(snapshot.running[0].last_turn_tokens, 1440);
    assert.equal(snapshot.running[0].context_window_tokens, 258400);
    assert.equal(snapshot.running[0].context_headroom_tokens, 64932);
    assert.equal(snapshot.running[0].context_utilization_percent, 74.9);
    assert.ok(Array.isArray(snapshot.running[0].trace_tail));
    assert.ok(snapshot.running[0].trace_tail.some((entry) => entry.eventType === 'session/started'));
    assert.equal(snapshot.running[0].issueIdentifier, 'ROB-13-TELEMETRY');
    assert.equal(snapshot.running[0].threadId, 'thread-ctx');
    assert.equal(snapshot.running[0].lastEvent, 'item/agentMessage/delta');
    assert.equal(snapshot.running[0].lastActivity, 'Codex is streaming a response.');
    assert.equal(snapshot.running[0].runtimeSeconds, 42);
    assert.equal(snapshot.running[0].idleSeconds, 42);
    assert.equal(snapshot.running[0].totalTokens, 193468);
    assert.equal(snapshot.running[0].lastTurnTokens, 1440);
    assert.equal(snapshot.running[0].contextWindowTokens, 258400);
    assert.equal(snapshot.running[0].contextHeadroomTokens, 64932);
    assert.equal(snapshot.running[0].contextUtilizationPercent, 74.9);
    assert.equal(snapshot.totals.input_tokens, 121000);
    assert.equal(snapshot.totals.output_tokens, 72468);
    assert.equal(snapshot.totals.total_tokens, 193468);
    assert.equal(snapshot.totals.runtime_seconds, 42);
    assert.equal(snapshot.codex_totals.input_tokens, 121000);
    assert.equal(snapshot.codex_totals.output_tokens, 72468);
    assert.equal(snapshot.codex_totals.total_tokens, 193468);
    assert.equal(snapshot.codex_totals.seconds_running, 42);
    assert.equal(snapshot.health.overall, 'ok');
    assert.equal(snapshot.recent_events[0]?.issue_identifier, 'ROB-13-TELEMETRY');
    assert.ok(Array.isArray(snapshot.running[0].traceTail));
    assert.ok(snapshot.running[0].traceTail.some((entry) => entry.eventType === 'session/started'));

    const issueDetails = orchestrator.getIssueDetails('ROB-13-TELEMETRY');
    assert.ok(issueDetails);
    const running = issueDetails?.running as Record<string, unknown>;
    assert.equal(running.thread_id, 'thread-ctx');
    assert.equal(running.last_event, 'item/agentMessage/delta');
    assert.equal(running.last_message, 'Codex is streaming a response.');
    assert.equal(running.runtime_seconds, 42);
    assert.equal(running.idle_seconds, 42);
    assert.equal(running.total_tokens, 193468);
    assert.equal(running.last_turn_tokens, 1440);
    assert.equal(running.context_window_tokens, 258400);
    assert.equal(running.context_headroom_tokens, 64932);
    assert.equal(running.context_utilization_percent, 74.9);
    const health = issueDetails?.health as Record<string, unknown>;
    assert.equal(health.overall, 'ok');
    assert.equal(
        ((issueDetails?.recent_events as Array<Record<string, unknown>>)[0] ?? {}).issue_identifier,
        'ROB-13-TELEMETRY',
    );
    const trace = issueDetails?.trace as Record<string, unknown>;
    assert.ok(Array.isArray(trace.recent));
    assert.ok((trace.recent as Array<Record<string, unknown>>).some((entry) => entry.eventType === 'session/started'));

    tracker.statesByIssueId.set(issue.id, 'Done');
    now += 1000;
    firstDispatch.resolve({
        status: 'completed',
        outputText: 'ok',
        threadId: 'thread-ctx',
        turnId: 'turn-1',
        sessionId: 'thread-ctx-turn-1',
    });
    await orchestrator.shutdown();

    assert.equal(workspace.cleanedPaths.length, 1);
});

test('snapshot codex_totals uses the latest cumulative token update per run without double-counting', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'snapshot-double-count-1',
        identifier: 'ROB-13-SNAPSHOT-DOUBLE',
        priority: 1,
        createdAt: '2026-03-06T08:00:00.000Z',
    });
    tracker.candidates = [issue];

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    const workspace = new WorkspaceStub();
    const firstDispatch = createDeferred<{
        status: 'completed';
        outputText: string;
        threadId: string;
        turnId: string;
        sessionId: string;
    }>();
    let now = Date.parse('2026-03-06T09:30:00.000Z');

    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub([]),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => workspace,
        appServerFactory: ({emitEvent}) => ({
            runTurn: async () => {
                emitEvent({
                    type: 'session',
                    threadId: 'thread-double-count',
                    turnId: 'turn-1',
                    sessionId: 'thread-double-count-turn-1',
                });
                emitEvent({
                    type: 'token_usage',
                    payload: {
                        total: {
                            inputTokens: 100,
                            outputTokens: 25,
                            totalTokens: 125,
                        },
                    },
                });
                emitEvent({
                    type: 'token_usage',
                    payload: {
                        total: {
                            inputTokens: 320,
                            outputTokens: 80,
                            totalTokens: 400,
                        },
                    },
                });

                return firstDispatch.promise;
            },
            stop: async () => undefined,
        }),
        nowMs: () => now,
    });

    await orchestrator.runTick();
    await new Promise((resolve) => setTimeout(resolve, 10));

    now += 11000;

    const snapshot = orchestrator.getSnapshot();
    assert.equal(snapshot.totals.input_tokens, 320);
    assert.equal(snapshot.totals.output_tokens, 80);
    assert.equal(snapshot.totals.total_tokens, 400);
    assert.equal(snapshot.totals.runtime_seconds, 11);
    assert.equal(snapshot.codex_totals.input_tokens, 320);
    assert.equal(snapshot.codex_totals.output_tokens, 80);
    assert.equal(snapshot.codex_totals.total_tokens, 400);
    assert.equal(snapshot.codex_totals.seconds_running, 11);

    tracker.statesByIssueId.set(issue.id, 'Done');
    firstDispatch.resolve({
        status: 'completed',
        outputText: 'ok',
        threadId: 'thread-double-count',
        turnId: 'turn-1',
        sessionId: 'thread-double-count-turn-1',
    });
    await orchestrator.shutdown();
});

test('issue details preserve terminal input_required payload for debugging after dispatch failure', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'input-required-1',
        identifier: 'ROB-13-INPUT',
        priority: 1,
        createdAt: '2026-03-06T08:00:00.000Z',
    });
    tracker.candidates = [issue];

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    const workspace = new WorkspaceStub();
    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub([]),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => workspace,
        appServerFactory: () => ({
            runTurn: async () => ({
                status: 'input_required',
                outputText: '',
                threadId: 'thread-input',
                turnId: 'turn-input',
                sessionId: 'thread-input-turn-input',
                inputRequiredType: 'item/tool/requestUserInput',
                inputRequiredPayload: {
                    method: 'item/tool/requestUserInput',
                    params: {
                        question: 'Should I continue?',
                    },
                },
            }),
            stop: async () => undefined,
        }),
    });

    await orchestrator.runTick();
    await orchestrator.shutdown();

    const issueDetails = orchestrator.getIssueDetails('ROB-13-INPUT');
    assert.ok(issueDetails);
    assert.equal(issueDetails?.status, 'failed');
    assert.equal(issueDetails?.error_class, 'turn_input_required');
    assert.equal(issueDetails?.error, 'Tool requires user input: Should I continue?');

    const terminal = issueDetails?.terminal as Record<string, unknown>;
    assert.equal(terminal.last_event, 'item/tool/requestUserInput');
    assert.equal(terminal.last_message, 'Tool requires user input: Should I continue?');

    const inputRequired = issueDetails?.input_required as Record<string, unknown>;
    assert.equal(inputRequired.event_type, 'item/tool/requestUserInput');
    assert.deepEqual(inputRequired.payload, {
        method: 'item/tool/requestUserInput',
        params: {
            question: 'Should I continue?',
        },
    });
    const health = issueDetails?.health as Record<string, unknown>;
    assert.equal(health.overall, 'warning');
    assert.equal(((health.indicators as Array<Record<string, unknown>>)[0] ?? {}).code, 'error_state');
});

test('first-turn trace captures pre-edit command execution details without forcing an early retry', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'first-turn-command-trace-1',
        identifier: 'ROB-13-COMMAND-TRACE',
        priority: 1,
        createdAt: '2026-03-06T08:00:00.000Z',
        description: 'Update `docs/symphony/STAGING_PILOT_RUNBOOK.md` only.',
    });
    tracker.candidates = [issue];

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    const workspace = new WorkspaceStub();
    workspace.stateSnapshots = [
        {headSha: 'head-before', statusText: '', branchName: 'codex/symphony-test'},
        {
            headSha: 'head-after',
            statusText: ' M docs/symphony/STAGING_PILOT_RUNBOOK.md',
            branchName: 'codex/symphony-test',
        },
    ];

    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub([]),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => workspace,
        appServerFactory: ({emitEvent}) => ({
            runTurn: async () => {
                emitEvent({
                    type: 'session',
                    threadId: 'thread-command-trace',
                    turnId: 'turn-1',
                    sessionId: 'thread-command-trace-turn-1',
                });
                emitEvent({
                    type: 'raw_event',
                    payload: {
                        method: 'codex/event/exec_command_begin',
                        params: {
                            msg: {
                                parsed_cmd: {
                                    command: 'ls',
                                    args: ['-la'],
                                },
                                cwd: '/tmp/symphony-workspaces/ROB-13-COMMAND-TRACE',
                            },
                        },
                    },
                });
                emitEvent({
                    type: 'raw_event',
                    payload: {
                        method: 'codex/event/exec_command_begin',
                        params: {
                            msg: {
                                parsed_cmd: ['sed', '-n', '1,200p', 'WORKFLOW.md'],
                                cwd: '/tmp/symphony-workspaces/ROB-13-COMMAND-TRACE',
                            },
                        },
                    },
                });
                tracker.statesByIssueId.set(issue.id, 'Done');
                return {
                    status: 'completed',
                    outputText: 'updated docs',
                    threadId: 'thread-command-trace',
                    turnId: 'turn-1',
                    sessionId: 'thread-command-trace-turn-1',
                };
            },
            stop: async () => undefined,
        }),
    });

    await orchestrator.runTick();
    await orchestrator.shutdown();

    const snapshot = orchestrator.getSnapshot();
    assert.equal(snapshot.retrying.length, 0);
    assert.equal(snapshot.counts.completed, 1);

    const issueDetails = orchestrator.getIssueDetails('ROB-13-COMMAND-TRACE');
    assert.ok(issueDetails);
    assert.equal(issueDetails?.status, 'completed');
    const trace = issueDetails?.trace as Record<string, unknown>;
    assert.ok(Array.isArray(trace.first_turn));
    assert.ok(
        (trace.first_turn as Array<Record<string, unknown>>).some(
            (entry) =>
                entry.eventType === 'codex/event/exec_command_begin' &&
                typeof entry.details === 'object' &&
                entry.details !== null &&
                (entry.details as Record<string, unknown>).command === 'ls -la',
        ),
    );
    assert.ok(
        (trace.first_turn as Array<Record<string, unknown>>).some(
            (entry) =>
                entry.eventType === 'codex/event/exec_command_begin' &&
                typeof entry.details === 'object' &&
                entry.details !== null &&
                (entry.details as Record<string, unknown>).command === 'sed -n 1,200p WORKFLOW.md',
        ),
    );
    assert.ok(
        !(trace.first_turn as Array<Record<string, unknown>>).some(
            (entry) => entry.eventType === 'guard/first_command_drift',
        ),
    );
});

test('first-turn trace captures plan-only candidates without stopping the run early', async () => {
    const tracker = new TrackerStub();
    const issue = createIssue({
        id: 'plan-only-trace-1',
        identifier: 'ROB-13-PLAN-ONLY-TRACE',
        priority: 1,
        createdAt: '2026-03-06T08:00:00.000Z',
        description: 'Update `docs/symphony/STAGING_PILOT_RUNBOOK.md` only.',
    });
    tracker.candidates = [issue];

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    const workspace = new WorkspaceStub();
    workspace.stateSnapshots = [
        {headSha: 'head-before', statusText: '', branchName: 'codex/symphony-test'},
        {
            headSha: 'head-after',
            statusText: ' M docs/symphony/STAGING_PILOT_RUNBOOK.md',
            branchName: 'codex/symphony-test',
        },
    ];

    const orchestrator = new SymphonyOrchestrator({
        logger: createLoggerStub([]),
        workflowConfigStore: workflowStore,
        trackerFactory: () => tracker,
        workspaceFactory: () => workspace,
        appServerFactory: ({emitEvent}) => ({
            runTurn: async () => {
                emitEvent({
                    type: 'session',
                    threadId: 'thread-plan-only-trace',
                    turnId: 'turn-1',
                    sessionId: 'thread-plan-only-trace-turn-1',
                });
                emitEvent({
                    type: 'raw_event',
                    payload: {
                        method: 'item/started',
                        params: {
                            item: {
                                kind: 'agentMessage',
                            },
                        },
                    },
                });
                emitEvent({
                    type: 'raw_event',
                    payload: {
                        method: 'codex/event/agent_message_delta',
                        params: {
                            msg: {
                                payload: {
                                    delta: "I'll inspect the target file",
                                },
                            },
                        },
                    },
                });
                emitEvent({
                    type: 'raw_event',
                    payload: {
                        method: 'codex/event/agent_message_delta',
                        params: {
                            msg: {
                                payload: {
                                    delta: "I'll inspect the target file first and then update it.",
                                },
                            },
                        },
                    },
                });
                emitEvent({
                    type: 'raw_event',
                    payload: {
                        method: 'item/completed',
                        params: {
                            item: {
                                kind: 'agentMessage',
                            },
                        },
                    },
                });
                emitEvent({
                    type: 'token_usage',
                    payload: {
                        total: {
                            totalTokens: 32000,
                        },
                        last: {
                            totalTokens: 900,
                        },
                        model_context_window: 258400,
                    },
                });
                tracker.statesByIssueId.set(issue.id, 'Done');
                return {
                    status: 'completed',
                    outputText: 'updated docs',
                    threadId: 'thread-plan-only-trace',
                    turnId: 'turn-1',
                    sessionId: 'thread-plan-only-trace-turn-1',
                };
            },
            stop: async () => undefined,
        }),
    });

    await orchestrator.runTick();
    await orchestrator.shutdown();

    const snapshot = orchestrator.getSnapshot();
    assert.equal(snapshot.retrying.length, 0);

    const issueDetails = orchestrator.getIssueDetails('ROB-13-PLAN-ONLY-TRACE');
    assert.ok(issueDetails);
    assert.equal(issueDetails?.status, 'completed');
    const terminal = issueDetails?.terminal as Record<string, unknown>;
    assert.equal(terminal.first_turn_agent_message, "I'll inspect the target file first and then update it.");
    const trace = issueDetails?.trace as Record<string, unknown>;
    assert.ok(Array.isArray(trace.first_turn));
    assert.ok(
        (trace.first_turn as Array<Record<string, unknown>>).some(
            (entry) =>
                entry.eventType === 'agent/message_completed' &&
                typeof entry.details === 'object' &&
                entry.details !== null &&
                (entry.details as Record<string, unknown>).text ===
                    "I'll inspect the target file first and then update it.",
        ),
    );
    assert.ok(
        (trace.first_turn as Array<Record<string, unknown>>).some(
            (entry) => entry.eventType === 'guard/first_plan_only_candidate',
        ),
    );
    assert.ok(
        !(trace.first_turn as Array<Record<string, unknown>>).some(
            (entry) => entry.eventType === 'guard/first_plan_only',
        ),
    );
});
