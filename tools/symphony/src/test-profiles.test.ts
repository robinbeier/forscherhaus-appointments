import assert from 'node:assert/strict';
import test from 'node:test';
import {type SymphonyOrchestrator} from './orchestrator.js';
import type {TrackedIssue} from './linear-tracker.js';
import type {Logger} from './logger.js';
import {SymphonyOrchestrator as OrchestratorImpl} from './orchestrator.js';
import {FakeCodexProfile, FakeLinearProfile} from './test-profiles.js';
import type {LoadedWorkflowConfig} from './workflow.js';

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
    stateName?: string;
    blockedBy?: string[];
}): TrackedIssue {
    return {
        id: args.id,
        identifier: args.identifier,
        title: args.identifier,
        description: null,
        stateName: args.stateName ?? 'In Progress',
        stateType: 'started',
        priority: args.priority,
        branchName: null,
        url: null,
        labels: [],
        blockedBy: (args.blockedBy ?? []).map((identifier) => ({
            id: null,
            identifier,
            state: null,
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
            intervalMs: 60000,
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
    public readonly config: LoadedWorkflowConfig;

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
        return {
            config: this.config,
            prompt: `Issue ${String(context.issue.identifier)} attempt ${context.attempt}`,
        };
    }
}

function createNoopWorkspaceFactory() {
    return () => {
        let stateCaptureCount = 0;

        return {
            prepareWorkspace: async (rawKey: string) => ({
                key: rawKey,
                path: `/tmp/symphony-workspaces/${rawKey}`,
                created: true,
            }),
            resolveWorkspacePath: (rawKey: string) => `/tmp/symphony-workspaces/${rawKey}`,
            runBeforeRunHooks: async (_workspacePath: string, _envOverrides?: Record<string, string | undefined>) =>
                undefined,
            runAfterRunHooks: async (_workspacePath: string) => undefined,
            cleanupTerminalWorkspace: async (_workspacePath: string) => undefined,
            captureWorkspaceState: async (_workspacePath: string) => {
                const snapshot =
                    stateCaptureCount === 0
                        ? {headSha: 'head-before', statusText: '', branchName: 'codex/symphony-test'}
                        : {headSha: 'head-after', statusText: '', branchName: 'codex/symphony-test'};
                stateCaptureCount += 1;
                return snapshot;
            },
        };
    };
}

async function runTickAndDrain(orchestrator: SymphonyOrchestrator): Promise<void> {
    await orchestrator.runTick();
    await orchestrator.shutdown();
}

test('FakeLinearProfile returns deterministic candidates, states and Todo blockers', async () => {
    const candidate = createIssue({
        id: 'rob-15-1',
        identifier: 'ROB-15',
        priority: 2,
        createdAt: '2026-03-06T08:00:00.000Z',
    });
    const blocker = createIssue({
        id: 'rob-1',
        identifier: 'ROB-1',
        priority: 3,
        createdAt: '2026-03-01T08:00:00.000Z',
        stateName: 'Todo',
    });

    const profile = new FakeLinearProfile({
        candidates: [candidate],
        todoIssues: [blocker],
    });

    const candidates = await profile.fetchCandidateIssues();
    assert.equal(candidates.length, 1);
    assert.equal(candidates[0].identifier, 'ROB-15');

    const statesById = await profile.fetchIssueStatesByIds(['rob-15-1', 'missing']);
    assert.equal(statesById.get('rob-15-1'), 'In Progress');
    assert.equal(statesById.get('missing'), '');

    const todoIssues = await profile.fetchIssueStatesByStateNames(['Todo']);
    assert.deepEqual(
        todoIssues.map((issue) => issue.identifier),
        ['ROB-1'],
    );
});

test('FakeCodexProfile replays scripted outcomes deterministically', async () => {
    const profile = new FakeCodexProfile([
        {type: 'input_required', outputText: 'Need more context'},
        {type: 'failed', errorClass: 'turn_failed', message: 'scripted fail'},
        {type: 'completed', outputText: 'done'},
    ]);

    const first = await profile.runTurn({
        prompt: 'first',
        issueIdentifier: 'ROB-15',
        attempt: 1,
    });
    assert.equal(first.status, 'input_required');
    assert.equal(first.outputText, 'Need more context');

    await assert.rejects(
        () =>
            profile.runTurn({
                prompt: 'second',
                issueIdentifier: 'ROB-15',
                attempt: 2,
            }),
        /scripted fail/,
    );

    const third = await profile.runTurn({
        prompt: 'third',
        issueIdentifier: 'ROB-15',
        attempt: 3,
    });
    assert.equal(third.status, 'completed');
    assert.equal(third.outputText, 'done');
    assert.deepEqual(
        profile.seenRequests.map((request) => request.attempt),
        [1, 2, 3],
    );
});

test('Fake profiles drive deterministic orchestrator retry behavior in CI-like runs', async () => {
    const issue = createIssue({
        id: 'rob-15-orch',
        identifier: 'ROB-15-ORCH',
        priority: 1,
        createdAt: '2026-03-06T08:00:00.000Z',
    });

    const linearProfile = new FakeLinearProfile({
        candidates: [issue],
    });

    const codexProfile = new FakeCodexProfile([
        {type: 'failed', errorClass: 'turn_failed', message: 'retry me'},
        {type: 'completed', outputText: 'ok'},
    ]);

    const workflowStore = new WorkflowStoreStub(createWorkflowConfig());
    const logRecords: Array<Record<string, unknown>> = [];
    let now = Date.parse('2026-03-06T09:00:00.000Z');

    const orchestrator = new OrchestratorImpl({
        logger: createLoggerStub(logRecords),
        workflowConfigStore: workflowStore,
        trackerFactory: () => linearProfile,
        workspaceFactory: createNoopWorkspaceFactory(),
        appServerFactory: () => codexProfile,
        nowMs: () => now,
    });

    await runTickAndDrain(orchestrator);
    let snapshot = orchestrator.getSnapshot();

    assert.equal(codexProfile.seenRequests.length, 1);
    assert.equal(snapshot.retrying.length, 1);
    assert.equal(snapshot.retrying[0].attempt, 1);

    now += 10000;
    await runTickAndDrain(orchestrator);
    snapshot = orchestrator.getSnapshot();

    assert.equal(codexProfile.seenRequests.length, 2);
    assert.equal(snapshot.retrying.length, 1);
    assert.equal(snapshot.retrying[0].attempt, 2);
    assert.equal(snapshot.retrying[0].reason, 'continuation');
    assert.equal(snapshot.codex_totals.completed, 1);
    assert.equal(snapshot.codex_totals.failed, 1);

    const scheduledRetryLog = logRecords.find((entry) => entry.message === 'Scheduled issue retry');
    assert.ok(scheduledRetryLog);
});
