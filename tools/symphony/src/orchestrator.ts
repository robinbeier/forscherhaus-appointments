import {AppServerClientError, CodexAppServerClient, type OrchestratorEvent} from './app-server-client.js';
import {LinearTrackerAdapter, LinearTrackerError, type TrackedIssue} from './linear-tracker.js';
import type {Logger} from './logger.js';
import {type LoadedWorkflowConfig, type WorkflowConfigStore, WorkflowConfigError} from './workflow.js';
import {
    WorkspaceManager,
    WorkspaceManagerError,
    type WorkspaceHandle,
    type WorkspaceStateSnapshot,
} from './workspace-manager.js';

type WorkflowConfigStoreLike = Pick<
    WorkflowConfigStore,
    'reloadIfChanged' | 'getCurrentConfig' | 'validateCurrentPreflight' | 'buildDispatchPrompt'
>;

export interface TrackerClient {
    fetchCandidateIssues(): Promise<TrackedIssue[]>;
    fetchIssueStatesByIds(issueIds: string[]): Promise<Map<string, string>>;
    fetchIssueStatesByStateNames(stateNames: string[]): Promise<TrackedIssue[]>;
}

export interface WorkspaceClient {
    prepareWorkspace(rawKey: string): Promise<WorkspaceHandle>;
    runBeforeRunHooks(workspacePath: string): Promise<void>;
    runAfterRunHooks(workspacePath: string): Promise<void>;
    cleanupTerminalWorkspace(workspacePath: string): Promise<void>;
    captureWorkspaceState(workspacePath: string): Promise<WorkspaceStateSnapshot>;
}

export interface AppServerClient {
    runTurn(request: {
        prompt: string;
        issueIdentifier: string;
        attempt: number;
        responseTimeoutMs?: number;
        turnTimeoutMs?: number;
    }): Promise<{
        status: 'completed' | 'input_required';
        outputText: string;
        threadId: string;
        turnId: string;
        sessionId: string;
    }>;
}

type TrackerFactory = (config: LoadedWorkflowConfig) => TrackerClient;
type WorkspaceFactory = (config: LoadedWorkflowConfig) => WorkspaceClient;
type AppServerFactory = (args: {
    config: LoadedWorkflowConfig;
    workspacePath: string;
    emitEvent: (event: OrchestratorEvent) => void;
}) => AppServerClient;

interface SymphonyOrchestratorArgs {
    logger: Logger;
    workflowConfigStore: WorkflowConfigStoreLike;
    trackerFactory?: TrackerFactory;
    workspaceFactory?: WorkspaceFactory;
    appServerFactory?: AppServerFactory;
    nowMs?: () => number;
    continuationDelayMs?: number;
    retryMaxDelayMs?: number;
    stallGraceMs?: number;
}

type RetryReason = 'continuation' | 'dispatch_failed';
type DispatchSource = 'candidate' | 'retry';

interface RetryEntry {
    issue: TrackedIssue;
    attempt: number;
    reason: RetryReason;
    availableAtMs: number;
    errorClass?: string;
}

interface RunningEntry {
    issue: TrackedIssue;
    attempt: number;
    source: DispatchSource;
    startedAtMs: number;
    suppressRetry: boolean;
    stallLogged: boolean;
    sessionId?: string;
}

type OrchestratorGuardrailErrorClass = 'workspace_no_committed_output';

class OrchestratorGuardrailError extends Error {
    public readonly errorClass: OrchestratorGuardrailErrorClass;
    public readonly details: Record<string, unknown>;

    public constructor(
        errorClass: OrchestratorGuardrailErrorClass,
        message: string,
        details: Record<string, unknown> = {},
    ) {
        super(message);
        this.name = 'OrchestratorGuardrailError';
        this.errorClass = errorClass;
        this.details = details;
    }
}

export interface OrchestratorSnapshot {
    lastTickAtIso?: string;
    running: Array<{
        issueId: string;
        issueIdentifier: string;
        attempt: number;
        source: DispatchSource;
        startedAtIso: string;
        suppressRetry: boolean;
        sessionId: string | null;
    }>;
    retrying: Array<{
        issueId: string;
        issueIdentifier: string;
        attempt: number;
        reason: RetryReason;
        availableAtIso: string;
        errorClass?: string;
    }>;
    codex_totals: {
        completed: number;
        inputRequired: number;
        failed: number;
        responseTimeouts: number;
        turnTimeouts: number;
        launchFailures: number;
    };
    rate_limits: Record<string, unknown>;
}

const CONTINUATION_DELAY_MS = 1000;
const RETRY_MAX_DELAY_MS = 60000;
const STALL_GRACE_MS = 5000;

function normalizeStateName(value: string | undefined): string {
    return (value ?? '').trim().toLowerCase();
}

function normalizePositiveInteger(value: number, fallback = 0): number {
    if (!Number.isFinite(value)) {
        return fallback;
    }

    return Math.max(0, Math.floor(value));
}

function prioritySortWeight(priority: number): number {
    if (!Number.isFinite(priority) || priority <= 0) {
        return 5;
    }

    return priority;
}

function compareCandidateIssues(left: TrackedIssue, right: TrackedIssue): number {
    const priorityDelta = prioritySortWeight(left.priority) - prioritySortWeight(right.priority);
    if (priorityDelta !== 0) {
        return priorityDelta;
    }

    const leftCreated = Date.parse(left.createdAt);
    const rightCreated = Date.parse(right.createdAt);
    if (leftCreated !== rightCreated) {
        return leftCreated - rightCreated;
    }

    return left.identifier.localeCompare(right.identifier);
}

function toIssueTemplatePayload(issue: TrackedIssue): Record<string, unknown> {
    return {
        id: issue.id,
        identifier: issue.identifier,
        title: issue.title,
        state: issue.stateName,
        priority: issue.priority,
        labels: issue.labels,
        blocked_by: issue.blockedByIdentifiers,
        created_at: issue.createdAt,
        updated_at: issue.updatedAt,
        project_slug: issue.projectSlug,
    };
}

function issueLogFields(issue: TrackedIssue, sessionId?: string): Record<string, unknown> {
    return {
        issue_id: issue.id,
        issue_identifier: issue.identifier,
        session_id: sessionId ?? null,
    };
}

function didWorkspaceHeadAdvance(before: WorkspaceStateSnapshot, after: WorkspaceStateSnapshot): boolean {
    return before.headSha !== after.headSha;
}

export class SymphonyOrchestrator {
    private readonly logger: Logger;
    private readonly workflowConfigStore: WorkflowConfigStoreLike;
    private readonly trackerFactory: TrackerFactory;
    private readonly workspaceFactory: WorkspaceFactory;
    private readonly appServerFactory: AppServerFactory;
    private readonly nowMs: () => number;
    private readonly continuationDelayMs: number;
    private readonly retryMaxDelayMs: number;
    private readonly stallGraceMs: number;

    private readonly runningByIssueId = new Map<string, RunningEntry>();
    private readonly retryByIssueId = new Map<string, RetryEntry>();
    private readonly claimedIssueVersions = new Map<string, string>();
    private readonly runningDispatches = new Set<Promise<void>>();

    private lastTickAtIso?: string;
    private lastRateLimits: Record<string, unknown> = {};
    private codexTotals = {
        completed: 0,
        inputRequired: 0,
        failed: 0,
        responseTimeouts: 0,
        turnTimeouts: 0,
        launchFailures: 0,
    };
    private tickInProgress = false;

    public constructor(args: SymphonyOrchestratorArgs) {
        this.logger = args.logger;
        this.workflowConfigStore = args.workflowConfigStore;
        this.nowMs = args.nowMs ?? (() => Date.now());
        this.continuationDelayMs = args.continuationDelayMs ?? CONTINUATION_DELAY_MS;
        this.retryMaxDelayMs = args.retryMaxDelayMs ?? RETRY_MAX_DELAY_MS;
        this.stallGraceMs = args.stallGraceMs ?? STALL_GRACE_MS;

        this.trackerFactory =
            args.trackerFactory ??
            ((config) =>
                new LinearTrackerAdapter({
                    config: {
                        apiKey: config.tracker.apiKey,
                        projectSlug: config.tracker.projectSlug,
                        activeStates: config.tracker.activeStates,
                    },
                }));

        this.workspaceFactory =
            args.workspaceFactory ??
            ((config) =>
                new WorkspaceManager({
                    logger: this.logger,
                    config: {
                        root: config.workspace.root,
                        hooks: config.hooks,
                    },
                }));

        this.appServerFactory =
            args.appServerFactory ??
            ((factoryArgs) =>
                new CodexAppServerClient({
                    logger: this.logger,
                    config: {
                        command: factoryArgs.config.codex.command,
                        workspacePath: factoryArgs.workspacePath,
                        responseTimeoutMs: factoryArgs.config.codex.responseTimeoutMs,
                        turnTimeoutMs: factoryArgs.config.codex.turnTimeoutMs,
                    },
                    emitEvent: factoryArgs.emitEvent,
                }));
    }

    public getSnapshot(): OrchestratorSnapshot {
        return {
            lastTickAtIso: this.lastTickAtIso,
            running: Array.from(this.runningByIssueId.values())
                .map((entry) => ({
                    issueId: entry.issue.id,
                    issueIdentifier: entry.issue.identifier,
                    attempt: entry.attempt,
                    source: entry.source,
                    startedAtIso: new Date(entry.startedAtMs).toISOString(),
                    suppressRetry: entry.suppressRetry,
                    sessionId: entry.sessionId ?? null,
                }))
                .sort((left, right) => left.issueIdentifier.localeCompare(right.issueIdentifier)),
            retrying: Array.from(this.retryByIssueId.values())
                .map((entry) => ({
                    issueId: entry.issue.id,
                    issueIdentifier: entry.issue.identifier,
                    attempt: entry.attempt,
                    reason: entry.reason,
                    availableAtIso: new Date(entry.availableAtMs).toISOString(),
                    errorClass: entry.errorClass,
                }))
                .sort((left, right) => left.availableAtIso.localeCompare(right.availableAtIso)),
            codex_totals: {...this.codexTotals},
            rate_limits: {...this.lastRateLimits},
        };
    }

    public async runTick(): Promise<void> {
        if (this.tickInProgress) {
            this.logger.warn('Skipping poll tick because previous tick is still running.');
            return;
        }

        this.tickInProgress = true;
        this.lastTickAtIso = new Date(this.nowMs()).toISOString();

        try {
            await this.workflowConfigStore.reloadIfChanged();
            const config = this.workflowConfigStore.getCurrentConfig();
            this.workflowConfigStore.validateCurrentPreflight();

            const tracker = this.trackerFactory(config);
            await this.reconcileRunningAndRetryState(config, tracker);

            const maxConcurrent = normalizePositiveInteger(config.agent.maxConcurrent);
            let availableSlots = Math.max(0, maxConcurrent - this.runningByIssueId.size);
            if (availableSlots <= 0) {
                return;
            }

            const dispatchedRetries = this.dispatchDueRetries(config, availableSlots);
            availableSlots -= dispatchedRetries;
            if (availableSlots <= 0) {
                return;
            }

            const candidates = await tracker.fetchCandidateIssues();
            this.pruneClaimedIssueVersions(candidates);
            const todoBlockerIdentifiers = await this.loadTodoBlockers(tracker);
            const maxCandidates = normalizePositiveInteger(config.polling.maxCandidates);
            const eligibleCandidates = this.selectEligibleCandidates(
                candidates,
                todoBlockerIdentifiers,
                maxCandidates,
            ).slice(0, availableSlots);

            for (const issue of eligibleCandidates) {
                this.dispatchIssue(config, issue, 1, 'candidate');
            }
        } catch (error) {
            const classified = this.classifyError(error);
            this.logger.error('Orchestrator tick failed', {
                errorClass: classified.errorClass,
                error: classified.message,
            });
        } finally {
            this.tickInProgress = false;
        }
    }

    public async shutdown(): Promise<void> {
        await Promise.allSettled(Array.from(this.runningDispatches));
    }

    private async reconcileRunningAndRetryState(config: LoadedWorkflowConfig, tracker: TrackerClient): Promise<void> {
        const trackedIssueIds = new Set<string>();
        for (const issueId of this.runningByIssueId.keys()) {
            trackedIssueIds.add(issueId);
        }
        for (const issueId of this.retryByIssueId.keys()) {
            trackedIssueIds.add(issueId);
        }

        if (trackedIssueIds.size > 0) {
            const statesByIssueId = await tracker.fetchIssueStatesByIds(Array.from(trackedIssueIds));
            const activeStates = new Set(config.tracker.activeStates.map((state) => normalizeStateName(state)));

            for (const [issueId, runningEntry] of this.runningByIssueId.entries()) {
                const currentState = normalizeStateName(statesByIssueId.get(issueId));
                if (!activeStates.has(currentState) && !runningEntry.suppressRetry) {
                    runningEntry.suppressRetry = true;
                    this.logger.info('Marking running issue as terminal due to tracker state change', {
                        ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
                        currentState: statesByIssueId.get(issueId) ?? '',
                    });
                }

                const runtimeMs = this.nowMs() - runningEntry.startedAtMs;
                const stallThresholdMs = config.codex.turnTimeoutMs + this.stallGraceMs;
                if (runtimeMs > stallThresholdMs && !runningEntry.stallLogged) {
                    runningEntry.stallLogged = true;
                    runningEntry.suppressRetry = true;
                    this.logger.warn('Detected stalled running issue session', {
                        ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
                        runtimeMs,
                        stallThresholdMs,
                    });
                }
            }

            for (const [issueId, retryEntry] of this.retryByIssueId.entries()) {
                const currentState = normalizeStateName(statesByIssueId.get(issueId));
                if (activeStates.has(currentState)) {
                    continue;
                }

                this.retryByIssueId.delete(issueId);
                this.logger.info('Dropping retry because issue is no longer in active states', {
                    ...issueLogFields(retryEntry.issue),
                    currentState: statesByIssueId.get(issueId) ?? '',
                });
            }
        }
    }

    private dispatchDueRetries(config: LoadedWorkflowConfig, availableSlots: number): number {
        const nowMs = this.nowMs();
        const dueRetries = Array.from(this.retryByIssueId.values())
            .filter((entry) => entry.availableAtMs <= nowMs && !this.runningByIssueId.has(entry.issue.id))
            .sort((left, right) => {
                if (left.availableAtMs !== right.availableAtMs) {
                    return left.availableAtMs - right.availableAtMs;
                }

                return compareCandidateIssues(left.issue, right.issue);
            })
            .slice(0, availableSlots);

        for (const retryEntry of dueRetries) {
            this.retryByIssueId.delete(retryEntry.issue.id);
            this.dispatchIssue(config, retryEntry.issue, retryEntry.attempt, 'retry');
        }

        return dueRetries.length;
    }

    private async loadTodoBlockers(tracker: TrackerClient): Promise<Set<string>> {
        const todoIssues = await tracker.fetchIssueStatesByStateNames(['Todo']);
        return new Set(todoIssues.map((issue) => issue.identifier));
    }

    private selectEligibleCandidates(
        candidates: TrackedIssue[],
        todoBlockerIdentifiers: Set<string>,
        maxCandidates: number,
    ): TrackedIssue[] {
        const sorted = candidates.slice().sort(compareCandidateIssues).slice(0, maxCandidates);

        return sorted.filter((issue) => {
            if (this.runningByIssueId.has(issue.id) || this.retryByIssueId.has(issue.id)) {
                return false;
            }

            const claimedVersion = this.claimedIssueVersions.get(issue.id);
            if (claimedVersion && claimedVersion === issue.updatedAt) {
                return false;
            }

            if (claimedVersion && claimedVersion !== issue.updatedAt) {
                this.claimedIssueVersions.delete(issue.id);
            }

            const blockedByTodo = issue.blockedByIdentifiers.some((blocker) => todoBlockerIdentifiers.has(blocker));
            if (blockedByTodo) {
                this.logger.info('Skipping candidate issue because blocker is in Todo state', {
                    ...issueLogFields(issue),
                    blockers: issue.blockedByIdentifiers,
                });
                return false;
            }

            return true;
        });
    }

    private pruneClaimedIssueVersions(candidates: TrackedIssue[]): void {
        const activeIssueIds = new Set(candidates.map((issue) => issue.id));
        for (const issueId of this.claimedIssueVersions.keys()) {
            if (activeIssueIds.has(issueId) || this.runningByIssueId.has(issueId) || this.retryByIssueId.has(issueId)) {
                continue;
            }

            this.claimedIssueVersions.delete(issueId);
        }
    }

    private dispatchIssue(
        config: LoadedWorkflowConfig,
        issue: TrackedIssue,
        attempt: number,
        source: DispatchSource,
    ): void {
        if (this.runningByIssueId.has(issue.id)) {
            return;
        }

        const runningEntry: RunningEntry = {
            issue,
            attempt,
            source,
            startedAtMs: this.nowMs(),
            suppressRetry: false,
            stallLogged: false,
        };
        this.claimedIssueVersions.set(issue.id, issue.updatedAt);
        this.runningByIssueId.set(issue.id, runningEntry);

        const dispatchPromise = this.executeIssueDispatch(config, runningEntry).finally(() => {
            this.runningByIssueId.delete(issue.id);
            this.runningDispatches.delete(dispatchPromise);
        });

        this.runningDispatches.add(dispatchPromise);
        void dispatchPromise;
    }

    private async executeIssueDispatch(config: LoadedWorkflowConfig, runningEntry: RunningEntry): Promise<void> {
        let workspaceClient: WorkspaceClient | undefined;
        let workspacePath: string | undefined;
        let effectiveConfig = config;
        let beforeRunCompleted = false;
        let baselineWorkspaceState: WorkspaceStateSnapshot | undefined;
        let turnResult:
            | {
                  status: 'completed' | 'input_required';
                  outputText: string;
                  threadId: string;
                  turnId: string;
                  sessionId: string;
              }
            | undefined;
        let dispatchError: unknown;

        try {
            workspaceClient = this.workspaceFactory(config);
            const workspaceHandle = await workspaceClient.prepareWorkspace(runningEntry.issue.identifier);
            workspacePath = workspaceHandle.path;

            await workspaceClient.runBeforeRunHooks(workspacePath);
            beforeRunCompleted = true;
            baselineWorkspaceState = await workspaceClient.captureWorkspaceState(workspacePath);

            const dispatch = await this.workflowConfigStore.buildDispatchPrompt({
                issue: toIssueTemplatePayload(runningEntry.issue),
                attempt: runningEntry.attempt,
            });
            effectiveConfig = dispatch.config;

            const appServer = this.appServerFactory({
                config: effectiveConfig,
                workspacePath,
                emitEvent: (event) => this.handleOrchestratorEvent(event, runningEntry),
            });

            turnResult = await appServer.runTurn({
                prompt: dispatch.prompt,
                issueIdentifier: runningEntry.issue.identifier,
                attempt: runningEntry.attempt,
                responseTimeoutMs: effectiveConfig.codex.responseTimeoutMs,
                turnTimeoutMs: effectiveConfig.codex.turnTimeoutMs,
            });
        } catch (error) {
            dispatchError = error;
        }

        try {
            if (beforeRunCompleted && workspaceClient && workspacePath) {
                try {
                    await workspaceClient.runAfterRunHooks(workspacePath);
                } catch (error) {
                    const classified = this.classifyError(error);
                    this.logger.error('after_run hooks failed', {
                        ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
                        errorClass: classified.errorClass,
                        error: classified.message,
                    });
                }
            }

            if (dispatchError) {
                throw dispatchError;
            }

            if (!turnResult) {
                throw new OrchestratorGuardrailError(
                    'workspace_no_committed_output',
                    'Issue turn finished without a turn result.',
                );
            }

            if (turnResult.status === 'completed') {
                runningEntry.sessionId = turnResult.sessionId;

                if (workspaceClient && workspacePath && baselineWorkspaceState) {
                    const finalWorkspaceState = await workspaceClient.captureWorkspaceState(workspacePath);

                    if (!didWorkspaceHeadAdvance(baselineWorkspaceState, finalWorkspaceState)) {
                        throw new OrchestratorGuardrailError(
                            'workspace_no_committed_output',
                            'Completed turn produced no committed workspace changes.',
                            {
                                workspacePath,
                                headSha: finalWorkspaceState.headSha,
                                dirtyStateChanged: baselineWorkspaceState.statusText !== finalWorkspaceState.statusText,
                                hasDirtyWorkspace: finalWorkspaceState.statusText.length > 0,
                            },
                        );
                    }
                }

                this.codexTotals.completed += 1;
                this.retryByIssueId.delete(runningEntry.issue.id);
                this.logger.info('Issue turn completed', {
                    ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
                    attempt: runningEntry.attempt,
                    source: runningEntry.source,
                });
                return;
            }

            this.codexTotals.inputRequired += 1;

            if (runningEntry.suppressRetry) {
                this.logger.info('Skipping continuation retry because issue is terminal', {
                    ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
                    attempt: runningEntry.attempt,
                });
                this.retryByIssueId.delete(runningEntry.issue.id);
                return;
            }

            this.scheduleRetry({
                issue: runningEntry.issue,
                attempt: runningEntry.attempt + 1,
                reason: 'continuation',
                delayMs: this.continuationDelayMs,
                maxAttempts: effectiveConfig.agent.maxAttempts,
            });
        } catch (error) {
            const classified = this.classifyError(error);
            this.codexTotals.failed += 1;

            if (classified.errorClass === 'response_timeout') {
                this.codexTotals.responseTimeouts += 1;
            } else if (classified.errorClass === 'turn_timeout') {
                this.codexTotals.turnTimeouts += 1;
            } else if (classified.errorClass === 'launch_failed') {
                this.codexTotals.launchFailures += 1;
            }

            this.logger.error('Issue dispatch failed', {
                ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
                attempt: runningEntry.attempt,
                source: runningEntry.source,
                errorClass: classified.errorClass,
                error: classified.message,
            });

            if (!runningEntry.suppressRetry && this.isRetryableError(error)) {
                this.scheduleRetry({
                    issue: runningEntry.issue,
                    attempt: runningEntry.attempt + 1,
                    reason: 'dispatch_failed',
                    delayMs: this.computeFailureRetryDelayMs(runningEntry.attempt),
                    maxAttempts: effectiveConfig.agent.maxAttempts,
                    errorClass: classified.errorClass,
                });
            } else {
                this.retryByIssueId.delete(runningEntry.issue.id);
            }
        } finally {
            if (workspaceClient && workspacePath) {
                if (!effectiveConfig.workspace.keepTerminalWorkspaces) {
                    try {
                        await workspaceClient.cleanupTerminalWorkspace(workspacePath);
                    } catch (error) {
                        const classified = this.classifyError(error);
                        this.logger.error('Workspace cleanup failed', {
                            ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
                            errorClass: classified.errorClass,
                            error: classified.message,
                        });
                    }
                }
            }
        }
    }

    private scheduleRetry(args: {
        issue: TrackedIssue;
        attempt: number;
        reason: RetryReason;
        delayMs: number;
        maxAttempts: number;
        errorClass?: string;
    }): void {
        if (args.attempt > args.maxAttempts) {
            this.retryByIssueId.delete(args.issue.id);
            this.logger.warn('Retry budget exhausted for issue', {
                ...issueLogFields(args.issue),
                maxAttempts: args.maxAttempts,
            });
            return;
        }

        const availableAtMs = this.nowMs() + Math.max(args.delayMs, 0);
        this.retryByIssueId.set(args.issue.id, {
            issue: args.issue,
            attempt: args.attempt,
            reason: args.reason,
            availableAtMs,
            errorClass: args.errorClass,
        });

        this.logger.info('Scheduled issue retry', {
            ...issueLogFields(args.issue),
            attempt: args.attempt,
            reason: args.reason,
            availableAtIso: new Date(availableAtMs).toISOString(),
            errorClass: args.errorClass,
        });
    }

    private computeFailureRetryDelayMs(previousAttempt: number): number {
        if (previousAttempt <= 1) {
            return this.continuationDelayMs;
        }

        const exponentialDelay = this.continuationDelayMs * Math.pow(2, previousAttempt - 1);
        return Math.min(exponentialDelay, this.retryMaxDelayMs);
    }

    private handleOrchestratorEvent(event: OrchestratorEvent, runningEntry: RunningEntry): void {
        if (event.type === 'rate_limit') {
            this.lastRateLimits = {...event.payload};
            return;
        }

        if (event.type === 'session') {
            runningEntry.sessionId = event.sessionId;
            this.logger.info('Codex session created', {
                ...issueLogFields(runningEntry.issue, event.sessionId),
                threadId: event.threadId,
                turnId: event.turnId,
            });
            return;
        }

        if (event.type === 'diagnostic') {
            this.logger.info('Codex diagnostic event', {
                ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
                stream: event.stream,
                message: event.message,
            });
            return;
        }

        if (event.type === 'token_usage') {
            this.logger.info('Codex token usage event', {
                ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
                usage: event.payload,
            });
        }
    }

    private isRetryableError(error: unknown): boolean {
        if (error instanceof OrchestratorGuardrailError) {
            return true;
        }

        if (error instanceof AppServerClientError) {
            return true;
        }

        if (error instanceof WorkspaceManagerError) {
            return error.errorClass !== 'workspace_path_escape';
        }

        return false;
    }

    private classifyError(error: unknown): {errorClass: string; message: string} {
        if (error instanceof OrchestratorGuardrailError) {
            return {
                errorClass: error.errorClass,
                message: error.message,
            };
        }

        if (error instanceof AppServerClientError) {
            return {
                errorClass: error.errorClass,
                message: error.message,
            };
        }

        if (error instanceof WorkspaceManagerError) {
            return {
                errorClass: error.errorClass,
                message: error.message,
            };
        }

        if (error instanceof WorkflowConfigError) {
            return {
                errorClass: error.errorClass,
                message: error.message,
            };
        }

        if (error instanceof LinearTrackerError) {
            return {
                errorClass: error.errorClass,
                message: error.message,
            };
        }

        if (error instanceof Error) {
            return {
                errorClass: 'orchestrator_unknown_error',
                message: error.message,
            };
        }

        return {
            errorClass: 'orchestrator_unknown_error',
            message: String(error),
        };
    }
}
