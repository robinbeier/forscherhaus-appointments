import {readdir, rm} from 'node:fs/promises';
import path from 'node:path';
import {
    AppServerClientError,
    CodexAppServerClient,
    type OrchestratorEvent,
    type OrchestratorTraceCategory,
} from './app-server-client.js';
import {LinearTrackerAdapter, LinearTrackerError, type TrackedIssue} from './linear-tracker.js';
import type {Logger} from './logger.js';
import {type LoadedWorkflowConfig, type WorkflowConfigStore, WorkflowConfigError} from './workflow.js';
import {
    WorkspaceManager,
    WorkspaceManagerError,
    type WorkspaceCleanupOptions,
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
    prepareIssueForRun?(issue: TrackedIssue): Promise<TrackedIssue>;
    moveIssueToStateByName?(issue: TrackedIssue, stateName: string): Promise<TrackedIssue>;
    syncIssueWorkpadToState?(issue: TrackedIssue): Promise<TrackedIssue>;
}

export interface WorkspaceClient {
    prepareWorkspace(rawKey: string): Promise<WorkspaceHandle>;
    resolveWorkspacePath(rawKey: string): string;
    runBeforeRunHooks(workspacePath: string, envOverrides?: Record<string, string | undefined>): Promise<void>;
    runAfterRunHooks(workspacePath: string): Promise<void>;
    cleanupTerminalWorkspace(workspacePath: string, options?: WorkspaceCleanupOptions): Promise<void>;
    captureWorkspaceState(workspacePath: string): Promise<WorkspaceStateSnapshot>;
}

export interface AppServerClient {
    runTurn(request: {
        prompt: string;
        issueIdentifier: string;
        issueTitle?: string;
        attempt: number | null;
        publishMode?: boolean;
        threadId?: string;
        responseTimeoutMs?: number;
        turnTimeoutMs?: number;
    }): Promise<{
        status: 'completed' | 'input_required';
        outputText: string;
        threadId: string;
        turnId: string;
        sessionId: string;
        inputRequiredType?: string;
        inputRequiredPayload?: Record<string, unknown>;
    }>;
    stop(): Promise<void>;
}

type TrackerFactory = (config: LoadedWorkflowConfig) => TrackerClient;
type WorkspaceFactory = (config: LoadedWorkflowConfig) => WorkspaceClient;
type AppServerFactory = (args: {
    config: LoadedWorkflowConfig;
    workspacePath: string;
    runningEntry: RunningEntry;
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
    publishMode: boolean;
    errorClass?: string;
    retryDirective?: string;
}

interface RunningEntry {
    issue: TrackedIssue;
    attempt: number | null;
    source: DispatchSource;
    startedAtMs: number;
    lastEventAtMs: number;
    turnCount: number;
    suppressRetry: boolean;
    stallLogged: boolean;
    stopRequested: boolean;
    cleanupWorkspaceOnStop: boolean;
    stopCurrentState?: string;
    publishStateCheckpointInFlight: boolean;
    stopReason?:
        | 'reconciliation_terminal'
        | 'reconciliation_non_active'
        | 'review_handoff'
        | 'stall_timeout'
        | 'first_repo_step_guard'
        | 'first_command_drift_guard'
        | 'first_plan_only_guard'
        | 'post_diff_checkpoint';
    workspacePath?: string;
    workspaceClient?: WorkspaceClient;
    baselineWorkspaceState?: WorkspaceStateSnapshot;
    appServer?: AppServerClient;
    threadId?: string;
    sessionId?: string;
    lastEventType?: string;
    lastEventMessage?: string;
    lastTokenUsage?: Record<string, unknown>;
    hasObservedWorkspaceDiff: boolean;
    firstTurnItemLifecycleEvents: number;
    firstTurnCommandExecutionCount: number;
    firstTurnBroadCommandExecutionCount: number;
    workspaceStateCheckInFlight: boolean;
    lastWorkspaceStateCheckAtMs?: number;
    firstRepoStepGuardTriggered: boolean;
    firstCommandDriftGuardTriggered: boolean;
    firstPlanOnlyGuardTriggered: boolean;
    firstRepoTargetPath?: string | null;
    currentAgentMessageBuffer: string;
    firstTurnAgentMessageText?: string;
    firstTurnPlanOnlyCandidateAtMs?: number;
    firstTurnPlanOnlyCandidateMessage?: string;
    firstDiffObservedAtMs?: number;
    postDiffCheckpointTriggered: boolean;
    retryDirective?: string;
    pendingGuardrailError?: OrchestratorGuardrailError;
    publishMode: boolean;
    observedPullRequestMutation: boolean;
    observedOpenPullRequest: boolean;
    observedBranchPush: boolean;
    observedPullRequestMergeAttempt: boolean;
    observedHelperRepoPaths: string[];
    workspaceBranchSourceOfTruthConfirmed: boolean;
    trackerClient?: TrackerClient;
    reviewStateName?: string;
    terminalStates?: string[];
    traceEntries: TraceEntry[];
    firstTurnTraceEntries: TraceEntry[];
}

interface TokenUsageSummary {
    totalTokens: number | null;
    lastTurnTokens: number | null;
    contextWindowTokens: number | null;
    contextHeadroomTokens: number | null;
    contextUtilizationPercent: number | null;
}

interface TraceEntry {
    atIso: string;
    category: OrchestratorTraceCategory;
    eventType: string;
    message: string;
    details?: Record<string, unknown>;
}

interface RawEventSummary {
    eventType: string;
    category: OrchestratorTraceCategory;
    message: string;
    details?: Record<string, unknown>;
}

type OrchestratorGuardrailErrorClass =
    | 'workspace_no_committed_output'
    | 'workspace_no_first_repo_step'
    | 'workspace_first_repo_step_command_drift'
    | 'workspace_first_repo_step_plan_only';

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
        attempt: number | null;
        source: DispatchSource;
        startedAtIso: string;
        runtimeSeconds: number;
        lastActivityAtIso: string;
        idleSeconds: number;
        suppressRetry: boolean;
        sessionId: string | null;
        threadId: string | null;
        turnCount: number;
        lastEvent: string | null;
        lastActivity: string | null;
        totalTokens: number | null;
        lastTurnTokens: number | null;
        contextWindowTokens: number | null;
        contextHeadroomTokens: number | null;
        contextUtilizationPercent: number | null;
        traceTail: TraceEntry[];
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
const FIRST_REPO_STEP_GUARD_MIN_RUNTIME_MS = 15000;
const FIRST_REPO_STEP_GUARD_CONTEXT_UTILIZATION_PERCENT = 45;
const FIRST_REPO_STEP_GUARD_ITEM_LIFECYCLE_EVENTS = 6;
const WORKSPACE_PROGRESS_CHECK_MIN_INTERVAL_MS = 2000;
const FIRST_COMMAND_DRIFT_GUARD_MIN_RUNTIME_MS = 5000;
const FIRST_COMMAND_DRIFT_GUARD_TOTAL_COMMAND_EXECUTIONS = 3;
const FIRST_COMMAND_DRIFT_GUARD_BROAD_COMMAND_EXECUTIONS = 2;
const FIRST_PLAN_ONLY_MESSAGE_MIN_LENGTH = 24;
const FIRST_PLAN_ONLY_GUARD_GRACE_MS = 1000;
const POST_DIFF_CHECKPOINT_MIN_RUNTIME_AFTER_DIFF_MS = 15000;
const POST_DIFF_CHECKPOINT_CONTEXT_UTILIZATION_PERCENT = 35;
const FIRST_AGENT_MESSAGE_MAX_CHARS = 1200;
const TRACE_MAX_ENTRIES = 40;
const FIRST_TURN_TRACE_MAX_ENTRIES = 20;
const SNAPSHOT_TRACE_TAIL_ENTRIES = 5;

function normalizeStateName(value: string | undefined): string {
    return (value ?? '').trim().toLowerCase();
}

function normalizePositiveInteger(value: number, fallback = 0): number {
    if (!Number.isFinite(value)) {
        return fallback;
    }

    return Math.max(0, Math.floor(value));
}

function prioritySortWeight(priority: number | null): number {
    if (priority === null || !Number.isFinite(priority)) {
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

function compactPromptText(value: string | null | undefined, fallback: string, maxChars: number): string {
    const normalized = typeof value === 'string' ? value.replace(/\r\n/g, '\n').trim() : '';
    if (normalized.length === 0) {
        return fallback;
    }

    if (normalized.length <= maxChars) {
        return normalized;
    }

    return `${normalized.slice(0, Math.max(0, maxChars - 15)).trimEnd()}\n[truncated]`;
}

function isLikelyRepoPathToken(candidate: string): boolean {
    const normalized = candidate.trim();
    if (normalized.length === 0) {
        return false;
    }

    if (/\s/.test(normalized)) {
        return false;
    }

    if (/[;&|<>]/.test(normalized)) {
        return false;
    }

    if (
        normalized.includes('://') ||
        normalized.startsWith('http://') ||
        normalized.startsWith('https://') ||
        normalized.startsWith('www.')
    ) {
        return false;
    }

    if (/^[A-Za-z_][A-Za-z0-9_]*=/.test(normalized)) {
        return false;
    }

    if (/^\d+(?:\.\d+)+$/.test(normalized)) {
        return false;
    }

    if (!normalized.includes('/') && !normalized.includes('.')) {
        return false;
    }

    if (normalized === '.' || normalized === '..') {
        return false;
    }

    return /^(?:\.{1,2}\/)?(?:[A-Za-z0-9._-]+\/)*[A-Za-z0-9._-]+$/.test(normalized);
}

function extractLikelyRepoPaths(description: string | null | undefined): string[] {
    const normalized = typeof description === 'string' ? description : '';
    if (normalized.trim().length === 0) {
        return [];
    }

    const candidates: string[] = [];
    const backtickPattern = /`([^`\n]+)`/g;
    for (const match of normalized.matchAll(backtickPattern)) {
        const candidate = match[1]?.trim() ?? '';
        if (isLikelyRepoPathToken(candidate)) {
            candidates.push(candidate);
        }
    }

    const strippedBackticks = normalized.replace(backtickPattern, ' ');
    const pathPattern = /(?:^|[^A-Za-z0-9_])((?:\.{1,2}\/)?(?:[A-Za-z0-9._-]+\/)*[A-Za-z0-9._-]+)(?=$|[^A-Za-z0-9_])/g;
    for (const match of strippedBackticks.matchAll(pathPattern)) {
        const candidate = match[1]?.trim() ?? '';
        if (isLikelyRepoPathToken(candidate)) {
            candidates.push(candidate);
        }
    }

    const uniqueCandidates: string[] = [];
    for (const candidate of candidates) {
        if (!uniqueCandidates.includes(candidate)) {
            uniqueCandidates.push(candidate);
        }

        if (uniqueCandidates.length >= 5) {
            break;
        }
    }

    return uniqueCandidates;
}

function scoreRepoTargetPath(candidate: string): number {
    const normalized = candidate.trim();
    if (normalized.length === 0) {
        return Number.NEGATIVE_INFINITY;
    }

    if (
        normalized.startsWith('vendor/') ||
        normalized.startsWith('system/') ||
        normalized.startsWith('node_modules/') ||
        normalized.startsWith('.git/')
    ) {
        return Number.NEGATIVE_INFINITY;
    }

    let score = 0;
    if (normalized.startsWith('application/')) {
        score += 120;
    } else if (normalized.startsWith('tests/')) {
        score += 110;
    } else if (normalized.startsWith('docs/')) {
        score += 100;
    } else if (normalized.startsWith('assets/')) {
        score += 95;
    } else if (normalized.startsWith('scripts/')) {
        score += 90;
    } else if (normalized.startsWith('build/')) {
        score += 80;
    } else if (normalized.startsWith('.github/')) {
        score += 70;
    } else if (normalized.startsWith('.')) {
        score += 40;
    }

    if (/\.[A-Za-z0-9]+$/.test(normalized)) {
        score += 15;
    }

    if (/\.env(\.|$)/.test(normalized)) {
        score -= 40;
    }

    score -= normalized.split('/').length;
    return score;
}

function pickFirstRepoTargetPath(targetPaths: string[]): string | null {
    if (targetPaths.length === 0) {
        return null;
    }

    return (
        targetPaths
            .map((candidate, index) => ({
                candidate,
                index,
                score: scoreRepoTargetPath(candidate),
            }))
            .sort((left, right) => {
                if (left.score !== right.score) {
                    return right.score - left.score;
                }

                return left.index - right.index;
            })[0]?.candidate ?? null
    );
}

function buildFirstRepoStepContract(firstRepoTargetPath: string | null): string {
    if (!firstRepoTargetPath) {
        return 'Before broader exploration, make the smallest valid repo diff in the narrowest file implied by the issue. The runtime will stop and retry the turn if no repo diff appears.';
    }

    const scopeHint =
        firstRepoTargetPath.startsWith('docs/') || firstRepoTargetPath.endsWith('.md')
            ? 'Keep the first diff in docs scope unless the issue explicitly requires more.'
            : 'Do not widen scope before this first diff exists.';

    return `Before broader exploration, open and edit \`${firstRepoTargetPath}\`. Produce the smallest valid repo diff there in this first turn. ${scopeHint} The runtime will stop and retry the turn if no repo diff appears.`;
}

function appendMergedCappedText(existing: string, delta: string, maxLength: number): string {
    if (delta.length === 0 || maxLength <= 0) {
        return existing;
    }

    let merged = delta;
    if (existing.length > 0) {
        if (delta.startsWith(existing)) {
            merged = delta;
        } else if (existing.startsWith(delta)) {
            merged = existing;
        } else {
            let overlapLength = 0;
            const maxOverlap = Math.min(existing.length, delta.length);
            for (let length = maxOverlap; length > 0; length -= 1) {
                if (existing.slice(-length) === delta.slice(0, length)) {
                    overlapLength = length;
                    break;
                }
            }

            merged = `${existing}${delta.slice(overlapLength)}`;
        }
    }

    if (merged.length <= maxLength) {
        return merged;
    }

    return merged.slice(0, maxLength);
}

function normalizeAgentMessageText(value: string, maxLength = FIRST_AGENT_MESSAGE_MAX_CHARS): string {
    return normalizeText(value.replace(/\s+/g, ' '), maxLength);
}

function isAgentMessageItemKind(itemKind: unknown): boolean {
    return typeof itemKind === 'string' && itemKind.trim().toLowerCase() === 'agentmessage';
}

function hasPlanOnlyLead(text: string): boolean {
    return [
        /\b(i(?:'|’)ll|i will|i am going to|i'm going to|let me|plan|approach)\b/i,
        /\b(first|next|then|after that|before editing)\b/i,
        /\b(editing|updating|adding|checking|reviewing|keeping scope)\b/i,
        /\b(ich werde|ich prüfe|ich aktualisiere zunächst|zuerst|danach)\b/i,
    ].some((pattern) => pattern.test(text));
}

function hasCompletedWorkSignal(text: string): boolean {
    return [
        /\b(updated|edited|changed|applied|patched|added|removed|ran|validated|committed|pushed|created|implemented|fixed|moved)\b/i,
        /\b(aktualisiert|bearbeitet|geändert|angewendet|hinzugefügt|entfernt|ausgeführt|validiert|committed|gepusht)\b/i,
    ].some((pattern) => pattern.test(text));
}

function hasTrueBlockerSignal(text: string): boolean {
    return [
        /\b(blocked|cannot|can't|unable|missing (?:permission|permissions|secret|secrets|auth|authentication)|need(?:s)? (?:permission|permissions|auth|credentials)|no access)\b/i,
        /\b(blockiert|fehlende(?:n)? (?:rechte|berechtigungen|zugänge|secrets?)|kein zugriff|brauche (?:rechte|zugang))\b/i,
    ].some((pattern) => pattern.test(text));
}

function isPlanOnlyFirstTurnMessage(text: string): boolean {
    const normalized = normalizeAgentMessageText(text, FIRST_AGENT_MESSAGE_MAX_CHARS);
    if (normalized.length < FIRST_PLAN_ONLY_MESSAGE_MIN_LENGTH) {
        return false;
    }

    if (hasTrueBlockerSignal(normalized) || hasCompletedWorkSignal(normalized)) {
        return false;
    }

    return hasPlanOnlyLead(normalized);
}

function buildRetryDirective(
    firstRepoTargetPath: string | null | undefined,
    firstTurnAgentMessageText: string | undefined,
    errorClass: string | undefined,
): string | undefined {
    if (!errorClass) {
        return undefined;
    }

    const firstStep =
        firstRepoTargetPath && firstRepoTargetPath.trim().length > 0
            ? `Open and edit \`${firstRepoTargetPath}\` before any broader explanation.`
            : 'Open and edit the narrowest repo file implied by the issue before any broader explanation.';
    const priorReply = firstTurnAgentMessageText
        ? ` Do not repeat a reply like: "${normalizeText(firstTurnAgentMessageText, 220)}"`
        : '';

    if (errorClass === 'workspace_first_repo_step_plan_only') {
        return `The previous first turn ended in a plan/status reply without a repo diff.${priorReply} ${firstStep}`;
    }

    if (errorClass === 'workspace_no_first_repo_step') {
        return `The previous first turn still produced no repo diff.${priorReply} ${firstStep}`;
    }

    if (errorClass === 'workspace_first_repo_step_command_drift') {
        return `The previous first turn drifted into shell exploration before editing.${priorReply} ${firstStep}`;
    }

    return undefined;
}

function buildPostDiffCheckpointRetryDirective(firstRepoTargetPath: string | null | undefined): string {
    const firstStep =
        firstRepoTargetPath && firstRepoTargetPath.trim().length > 0
            ? `The required repo diff already exists in \`${firstRepoTargetPath}\`.`
            : 'The required repo diff already exists in the workspace.';

    return `${firstStep} Resume from the current workspace and thread state. Prioritize the narrowest remaining validation, local commit, and publish/state-update work before any broader exploration.`;
}

function commandMatches(command: string, pattern: RegExp): boolean {
    return pattern.test(command);
}

function didCommandObserveOpenPullRequest(command: string | null, exitCode: number | null): boolean {
    if (command === null || exitCode !== 0) {
        return false;
    }

    return /\bgh pr (?:create|edit|view|list)\b/.test(command);
}

function didCommandMutatePullRequest(command: string | null, exitCode: number | null): boolean {
    if (command === null || exitCode !== 0) {
        return false;
    }

    return /\bgh pr (?:create|edit)\b/.test(command);
}

function didCommandPushBranch(command: string | null, exitCode: number | null): boolean {
    if (command === null || exitCode !== 0) {
        return false;
    }

    return commandMatches(command, /\bgit push\b/);
}

function didCommandAttemptPullRequestMerge(command: string | null): boolean {
    if (command === null) {
        return false;
    }

    return /\bgh pr merge\b/.test(command);
}

function normalizeWorkspacePath(value: string): string {
    return path.resolve(value);
}

function isPathWithinWorkspace(candidatePath: string, workspacePath: string): boolean {
    const normalizedCandidate = normalizeWorkspacePath(candidatePath);
    const normalizedWorkspace = normalizeWorkspacePath(workspacePath);
    const relativePath = path.relative(normalizedWorkspace, normalizedCandidate);

    return relativePath === '' || (!relativePath.startsWith('..') && !path.isAbsolute(relativePath));
}

function isHelperRepoPath(candidatePath: string | null): boolean {
    if (!candidatePath) {
        return false;
    }

    return normalizeWorkspacePath(candidatePath)
        .split(path.sep)
        .some((segment) => segment.startsWith('.git-codex-local-'));
}

function addUniquePath(target: string[], candidatePath: string): void {
    const normalizedCandidate = normalizeWorkspacePath(candidatePath);
    if (!target.includes(normalizedCandidate)) {
        target.push(normalizedCandidate);
    }
}

function toIssueTemplatePayload(issue: TrackedIssue): Record<string, unknown> {
    const targetPaths = extractLikelyRepoPaths(issue.description);
    const firstRepoTargetPath = pickFirstRepoTargetPath(targetPaths);
    const firstRepoStepContract = buildFirstRepoStepContract(firstRepoTargetPath);

    return {
        id: issue.id,
        identifier: issue.identifier,
        title: issue.title,
        title_or_identifier: issue.title.trim().length > 0 ? issue.title : issue.identifier,
        description: issue.description,
        description_or_default: compactPromptText(issue.description, 'No description provided.', 4000),
        state: issue.stateName,
        priority: issue.priority,
        branch_name: issue.branchName,
        branch_name_or_default: issue.branchName ?? '(no branch recorded yet)',
        url: issue.url,
        labels: issue.labels,
        blocked_by: issue.blockedBy,
        blocked_by_identifiers: issue.blockedByIdentifiers,
        created_at: issue.createdAt,
        updated_at: issue.updatedAt,
        project_slug: issue.projectSlug,
        workpad_comment_id: issue.workpadCommentId,
        workpad_comment_body: issue.workpadCommentBody,
        workpad_comment_body_or_default: compactPromptText(
            issue.workpadCommentBody,
            'No workpad comment is available yet.',
            4000,
        ),
        workpad_comment_url: issue.workpadCommentUrl,
        target_paths: targetPaths,
        target_paths_hint_or_default:
            targetPaths.length > 0
                ? targetPaths.map((targetPath) => `- ${targetPath}`).join('\n')
                : '- No explicit target paths identified.',
        first_repo_target_path: firstRepoTargetPath,
        first_repo_target_path_or_default:
            firstRepoTargetPath ?? '(use the narrowest repo file implied by the issue brief)',
        first_repo_step_contract: firstRepoStepContract,
        first_repo_step_contract_or_default: firstRepoStepContract,
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

function normalizeStateNames(values: string[]): Set<string> {
    return new Set(values.map((value) => normalizeStateName(value)).filter((value) => value.length > 0));
}

function getOutstandingBlockers(
    issue: Pick<TrackedIssue, 'blockedBy'>,
    terminalStates: Set<string>,
): Array<{id: string | null; identifier: string | null; state: string | null}> {
    return issue.blockedBy.filter((blocker) => {
        const hasIdentity =
            (typeof blocker.id === 'string' && blocker.id.trim().length > 0) ||
            (typeof blocker.identifier === 'string' && blocker.identifier.trim().length > 0);

        if (!hasIdentity) {
            return false;
        }

        const normalizedBlockerState = normalizeStateName(blocker.state ?? undefined);
        if (normalizedBlockerState.length === 0) {
            return true;
        }

        return !terminalStates.has(normalizedBlockerState);
    });
}

function nextRetryAttempt(attempt: number | null): number {
    return attempt === null ? 1 : attempt + 1;
}

function hasTrackedIssueBranch(issue: TrackedIssue): boolean {
    return typeof issue.branchName === 'string' && issue.branchName.trim().length > 0;
}

function isMergeState(issue: TrackedIssue, mergeStateName: string): boolean {
    return normalizeStateName(issue.stateName) === normalizeStateName(mergeStateName);
}

function shouldStartPublishMode(
    issue: TrackedIssue,
    mergeStateName: string,
    runningEntry?: Pick<RunningEntry, 'publishMode'>,
): boolean {
    if (runningEntry?.publishMode) {
        return true;
    }

    if (isMergeState(issue, mergeStateName)) {
        return true;
    }

    return hasTrackedIssueBranch(issue);
}

function shouldUsePublishMode(
    issue: TrackedIssue,
    mergeStateName: string,
    runningEntry?: Pick<RunningEntry, 'publishMode'>,
): boolean {
    if (runningEntry?.publishMode) {
        return true;
    }

    return isMergeState(issue, mergeStateName);
}

function isReviewState(stateName: string, reviewStateName: string): boolean {
    return normalizeStateName(stateName) === normalizeStateName(reviewStateName);
}

function hasReviewHandoffEvidence(
    runningEntry: Pick<
        RunningEntry,
        'publishMode' | 'observedOpenPullRequest' | 'observedPullRequestMutation' | 'observedBranchPush'
    >,
): boolean {
    return (
        runningEntry.publishMode ||
        runningEntry.observedOpenPullRequest ||
        runningEntry.observedPullRequestMutation ||
        runningEntry.observedBranchPush
    );
}

function isSuccessfulReviewHandoff(
    stateName: string,
    reviewStateName: string,
    runningEntry: Pick<
        RunningEntry,
        'publishMode' | 'observedOpenPullRequest' | 'observedPullRequestMutation' | 'observedBranchPush'
    >,
): boolean {
    return isReviewState(stateName, reviewStateName) && hasReviewHandoffEvidence(runningEntry);
}

function isReviewHandoffStop(runningEntry: Pick<RunningEntry, 'stopReason'>): boolean {
    return runningEntry.stopReason === 'review_handoff';
}

function createContinuationPrompt(
    issue: TrackedIssue,
    turnNumber: number,
    maxTurns: number,
    attempt: number | null,
): string {
    return [
        'Continuation guidance:',
        '',
        '- The previous Codex turn completed normally, but the Linear issue is still in an active state.',
        `- This is continuation turn ${turnNumber} of ${maxTurns} for the current agent run.`,
        '- Resume from the current workspace and workpad state instead of restarting from scratch.',
        '- The original task instructions and prior turn context are already present in this thread, so do not restate them before acting.',
        '- Focus on the remaining ticket work and do not end the turn while the issue stays active unless you are truly blocked.',
        attempt === null
            ? '- This worker session started from the initial dispatch.'
            : `- This worker session is running retry/continuation attempt ${attempt}.`,
        `- Current tracker state: ${issue.stateName}.`,
    ].join('\n');
}

function asFiniteNumber(value: unknown): number | undefined {
    if (typeof value === 'number' && Number.isFinite(value)) {
        return value;
    }

    if (typeof value === 'string' && value.trim().length > 0) {
        const parsed = Number(value);
        if (Number.isFinite(parsed)) {
            return parsed;
        }
    }

    return undefined;
}

function readPath(value: unknown, path: string[]): unknown {
    let current: unknown = value;

    for (const segment of path) {
        if (!current || typeof current !== 'object' || Array.isArray(current)) {
            return undefined;
        }

        current = (current as Record<string, unknown>)[segment];
    }

    return current;
}

function firstFiniteNumber(source: Record<string, unknown>, candidatePaths: string[][]): number | undefined {
    for (const path of candidatePaths) {
        const value = asFiniteNumber(readPath(source, path));
        if (value !== undefined) {
            return value;
        }
    }

    return undefined;
}

function firstStringValue(source: Record<string, unknown>, candidatePaths: string[][]): string | undefined {
    for (const path of candidatePaths) {
        const value = readPath(source, path);
        if (typeof value === 'string' && value.trim().length > 0) {
            return value;
        }
    }

    return undefined;
}

function firstDefinedValue(source: Record<string, unknown>, candidatePaths: string[][]): unknown {
    for (const path of candidatePaths) {
        const value = readPath(source, path);
        if (value !== undefined && value !== null) {
            return value;
        }
    }

    return undefined;
}

function normalizeCommandValue(value: unknown): string | undefined {
    if (typeof value === 'string') {
        const trimmed = value.trim();
        return trimmed.length > 0 ? trimmed : undefined;
    }

    if (Array.isArray(value) && value.every((part) => typeof part === 'string')) {
        const joined = value.join(' ').trim();
        return joined.length > 0 ? joined : undefined;
    }

    if (value && typeof value === 'object' && !Array.isArray(value)) {
        const record = value as Record<string, unknown>;
        const binaryCommand = normalizeCommandValue(
            record.parsedCmd ?? record.parsed_cmd ?? record.command ?? record.cmd,
        );
        const args = normalizeCommandValue(record.args ?? record.argv);
        if (binaryCommand && args) {
            return `${binaryCommand} ${args}`.trim();
        }

        return binaryCommand ?? args;
    }

    return undefined;
}

function extractStreamingText(payload: Record<string, unknown>): string | undefined {
    const text = firstDefinedValue(payload, [
        ['params', 'delta'],
        ['params', 'msg', 'delta'],
        ['params', 'textDelta'],
        ['params', 'msg', 'textDelta'],
        ['params', 'outputDelta'],
        ['params', 'msg', 'outputDelta'],
        ['params', 'text'],
        ['params', 'msg', 'text'],
        ['params', 'summaryText'],
        ['params', 'msg', 'summaryText'],
        ['params', 'content'],
        ['params', 'msg', 'content'],
        ['params', 'msg', 'payload', 'delta'],
        ['params', 'msg', 'payload', 'textDelta'],
        ['params', 'msg', 'payload', 'outputDelta'],
        ['params', 'msg', 'payload', 'text'],
        ['params', 'msg', 'payload', 'summaryText'],
        ['params', 'msg', 'payload', 'content'],
    ]);

    return typeof text === 'string' && text.trim().length > 0 ? text : undefined;
}

function humanizeItemKind(itemKind: string | null): string | null {
    if (!itemKind) {
        return null;
    }

    return itemKind
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replace(/[_-]+/g, ' ')
        .trim()
        .toLowerCase();
}

function extractExecCommandDetails(payload: Record<string, unknown>): {
    command: string | null;
    cwd: string | null;
    exitCode: number | null;
} {
    return {
        command:
            normalizeCommandValue(
                firstDefinedValue(payload, [
                    ['params', 'msg', 'command'],
                    ['params', 'msg', 'parsed_cmd'],
                    ['params', 'msg', 'parsedCmd'],
                    ['params', 'msg', 'cmd'],
                    ['params', 'msg', 'argv'],
                    ['params', 'msg', 'args'],
                    ['params', 'msg', 'payload', 'command'],
                    ['params', 'msg', 'payload', 'parsed_cmd'],
                    ['params', 'msg', 'payload', 'parsedCmd'],
                    ['params', 'msg', 'payload', 'cmd'],
                    ['params', 'msg', 'payload', 'argv'],
                    ['params', 'msg', 'payload', 'args'],
                    ['params', 'command'],
                    ['params', 'parsed_cmd'],
                    ['params', 'parsedCmd'],
                    ['params', 'cmd'],
                    ['params', 'argv'],
                    ['params', 'args'],
                    ['command'],
                    ['parsed_cmd'],
                    ['parsedCmd'],
                    ['cmd'],
                    ['argv'],
                    ['args'],
                ]),
            ) ?? null,
        cwd:
            firstStringValue(payload, [
                ['params', 'msg', 'cwd'],
                ['params', 'msg', 'payload', 'cwd'],
                ['params', 'cwd'],
                ['cwd'],
            ]) ?? null,
        exitCode:
            firstFiniteNumber(payload, [
                ['params', 'msg', 'exitCode'],
                ['params', 'msg', 'exit_code'],
                ['params', 'exitCode'],
                ['params', 'exit_code'],
                ['exitCode'],
                ['exit_code'],
            ]) ?? null,
    };
}

function commandMentionsTargetPath(command: string, firstRepoTargetPath: string | null | undefined): boolean {
    if (!firstRepoTargetPath) {
        return false;
    }

    const normalizedCommand = command.toLowerCase();
    const normalizedTargetPath = firstRepoTargetPath.toLowerCase();
    if (normalizedCommand.includes(normalizedTargetPath)) {
        return true;
    }

    const targetBasename = normalizedTargetPath.split('/').pop();
    return typeof targetBasename === 'string' && targetBasename.length > 0
        ? normalizedCommand.includes(targetBasename)
        : false;
}

function isBroadCommandBeforeFirstDiff(command: string, firstRepoTargetPath: string | null | undefined): boolean {
    const normalized = command.trim().toLowerCase();
    if (normalized.length === 0) {
        return false;
    }

    if (commandMentionsTargetPath(normalized, firstRepoTargetPath)) {
        return false;
    }

    if (/^(pwd|true|echo)\b/.test(normalized) || /^git (status|rev-parse|branch|log)\b/.test(normalized)) {
        return false;
    }

    return (
        /^(ls|tree|find|fd|rg|grep|sed|cat|head|tail)\b/.test(normalized) ||
        /^git (show|diff)\b/.test(normalized) ||
        normalized.includes('workflow.md') ||
        normalized.includes('agents.md')
    );
}

function normalizeText(value: string, maxLength = 240): string {
    const normalized = value.replace(/\s+/g, ' ').trim();
    if (normalized.length <= maxLength) {
        return normalized;
    }

    return `${normalized.slice(0, Math.max(0, maxLength - 1)).trimEnd()}…`;
}

function formatTokenCount(value: number): string {
    return value.toLocaleString('en-US');
}

function summarizeTokenUsage(payload?: Record<string, unknown>): TokenUsageSummary | null {
    if (!payload) {
        return null;
    }

    const totalTokens =
        firstFiniteNumber(payload, [
            ['total'],
            ['totalTokens'],
            ['total_tokens'],
            ['total', 'totalTokens'],
            ['total', 'total_tokens'],
            ['total', 'total'],
            ['totalTokenUsage'],
            ['totalTokenUsage', 'totalTokens'],
            ['totalTokenUsage', 'total_tokens'],
            ['total_token_usage'],
            ['total_token_usage', 'totalTokens'],
            ['total_token_usage', 'total_tokens'],
        ]) ?? null;
    const lastTurnTokens =
        firstFiniteNumber(payload, [
            ['last'],
            ['lastTokens'],
            ['last_tokens'],
            ['last', 'totalTokens'],
            ['last', 'total_tokens'],
            ['last', 'total'],
            ['lastTokenUsage'],
            ['lastTokenUsage', 'totalTokens'],
            ['lastTokenUsage', 'total_tokens'],
            ['last_token_usage'],
            ['last_token_usage', 'totalTokens'],
            ['last_token_usage', 'total_tokens'],
        ]) ?? null;
    const contextWindowTokens =
        firstFiniteNumber(payload, [
            ['modelContextWindow'],
            ['model_context_window'],
            ['contextWindow'],
            ['context_window'],
            ['modelContextWindowTokens'],
            ['model_context_window_tokens'],
            ['total', 'modelContextWindow'],
            ['total', 'model_context_window'],
            ['totalTokenUsage', 'modelContextWindow'],
            ['totalTokenUsage', 'model_context_window'],
            ['total_token_usage', 'modelContextWindow'],
            ['total_token_usage', 'model_context_window'],
        ]) ?? null;

    if (totalTokens === null && lastTurnTokens === null && contextWindowTokens === null) {
        return null;
    }

    const contextHeadroomTokens =
        totalTokens !== null && contextWindowTokens !== null ? Math.max(contextWindowTokens - totalTokens, 0) : null;
    const contextUtilizationPercent =
        totalTokens !== null && contextWindowTokens !== null && contextWindowTokens > 0
            ? Math.round(Math.min(totalTokens / contextWindowTokens, 1) * 1000) / 10
            : null;

    return {
        totalTokens,
        lastTurnTokens,
        contextWindowTokens,
        contextHeadroomTokens,
        contextUtilizationPercent,
    };
}

function describeTokenUsage(summary: TokenUsageSummary | null): string {
    if (!summary) {
        return 'Token usage updated.';
    }

    const parts: string[] = [];

    if (summary.totalTokens !== null && summary.contextWindowTokens !== null) {
        const utilization =
            summary.contextUtilizationPercent !== null ? `${summary.contextUtilizationPercent.toFixed(1)}%` : 'n/a';
        parts.push(
            `Context ${formatTokenCount(summary.totalTokens)} / ${formatTokenCount(summary.contextWindowTokens)} tokens (${utilization} used)`,
        );
    } else if (summary.totalTokens !== null) {
        parts.push(`Total tokens ${formatTokenCount(summary.totalTokens)}`);
    }

    if (summary.lastTurnTokens !== null) {
        parts.push(`last turn ${formatTokenCount(summary.lastTurnTokens)}`);
    }

    return parts.length > 0 ? `${parts.join(', ')}.` : 'Token usage updated.';
}

function summarizeRawEvent(payload: Record<string, unknown>): RawEventSummary {
    const eventType =
        typeof payload.method === 'string'
            ? payload.method
            : typeof payload.type === 'string'
              ? payload.type
              : 'raw_event';
    const normalizedType = eventType.trim().toLowerCase();

    if (
        normalizedType === 'item/agentmessage/delta' ||
        normalizedType === 'codex/event/agent_message_delta' ||
        normalizedType === 'codex/event/agent_message_content_delta'
    ) {
        const delta = extractStreamingText(payload) ?? '';
        return {
            eventType,
            category: 'agent',
            message: 'Codex is streaming a response.',
            details: delta.length > 0 ? {delta: normalizeText(delta, 240)} : undefined,
        };
    }

    if (normalizedType.includes('agentmessage') || normalizedType.includes('agent_message')) {
        return {
            eventType,
            category: 'agent',
            message: 'Codex is streaming a response.',
            details: (() => {
                const delta = extractStreamingText(payload);
                return delta ? {delta: normalizeText(delta, 240)} : undefined;
            })(),
        };
    }

    if (normalizedType.includes('turn') && normalizedType.includes('started')) {
        return {
            eventType,
            category: 'turn',
            message: 'Turn started.',
        };
    }

    if (normalizedType.includes('turn') && normalizedType.includes('completed')) {
        return {
            eventType,
            category: 'turn',
            message: 'Turn completed.',
        };
    }

    if (normalizedType.includes('tool') && normalizedType.includes('call')) {
        const toolName = firstStringValue(payload, [['params', 'tool'], ['tool']]) ?? null;
        const callId = firstStringValue(payload, [['params', 'callId'], ['callId']]) ?? null;
        return {
            eventType,
            category: 'tool',
            message: toolName ? `Tool call requested: ${toolName}.` : 'Tool call requested.',
            details: {
                tool: toolName,
                call_id: callId,
            },
        };
    }

    if (normalizedType.includes('requestuserinput') || normalizedType.includes('elicitation')) {
        const params = readPath(payload, ['params']);
        const questions =
            params && typeof params === 'object' && !Array.isArray(params)
                ? ((params as Record<string, unknown>).questions as unknown)
                : undefined;
        const firstQuestion =
            Array.isArray(questions) && questions.length > 0 && questions[0] && typeof questions[0] === 'object'
                ? (questions[0] as Record<string, unknown>)
                : undefined;
        const questionText =
            firstStringValue(payload, [['params', 'question']]) ??
            (typeof firstQuestion?.question === 'string' ? firstQuestion.question : undefined);

        return {
            eventType,
            category: 'approval',
            message: questionText
                ? `Tool requires user input: ${normalizeText(questionText)}`
                : 'Tool requires user input.',
            details: questionText
                ? {
                      question: normalizeText(questionText, 400),
                  }
                : undefined,
        };
    }

    if (normalizedType.includes('requestapproval')) {
        const command =
            firstStringValue(payload, [
                ['params', 'parsedCmd'],
                ['params', 'command'],
            ]) ?? null;
        const targetPath =
            firstStringValue(payload, [
                ['params', 'path'],
                ['params', 'targetPath'],
            ]) ?? null;

        return {
            eventType,
            category: 'approval',
            message: command
                ? `Approval requested for command: ${normalizeText(command)}`
                : targetPath
                  ? `Approval requested for file change: ${normalizeText(targetPath)}`
                  : 'Approval requested.',
            details: {
                command,
                path: targetPath,
            },
        };
    }

    if (normalizedType === 'codex/event/exec_command_begin' || normalizedType === 'codex/event/exec_command_end') {
        const details = extractExecCommandDetails(payload);
        return {
            eventType,
            category: 'command',
            message:
                normalizedType === 'codex/event/exec_command_begin'
                    ? details.command
                        ? normalizeText(details.command, 400)
                        : 'Command execution started.'
                    : details.command
                      ? `${normalizeText(details.command, 320)}${details.exitCode !== null ? ` (exit ${details.exitCode})` : ''}`
                      : details.exitCode !== null
                        ? `Command execution finished (exit ${details.exitCode}).`
                        : 'Command execution finished.',
            details: {
                command: details.command,
                cwd: details.cwd,
                exit_code: details.exitCode,
            },
        };
    }

    if (normalizedType === 'item/started' || normalizedType === 'item/completed') {
        const itemKind =
            firstStringValue(payload, [
                ['params', 'item', 'kind'],
                ['params', 'item', 'type'],
                ['params', 'kind'],
            ]) ?? null;
        const humanizedItemKind = humanizeItemKind(itemKind);

        return {
            eventType,
            category: 'turn',
            message:
                normalizedType === 'item/started'
                    ? humanizedItemKind
                        ? `Work item started: ${humanizedItemKind}.`
                        : 'Work item started.'
                    : humanizedItemKind
                      ? `Work item completed: ${humanizedItemKind}.`
                      : 'Work item completed.',
            details: {
                item_kind: itemKind,
            },
        };
    }

    if (normalizedType.includes('diff')) {
        return {
            eventType,
            category: 'workspace',
            message: 'Workspace diff event reported.',
        };
    }

    const extractedMessage = firstStringValue(payload, [
        ['message'],
        ['params', 'message'],
        ['params', 'status'],
        ['params', 'turn', 'status'],
        ['error', 'message'],
    ]);

    if (extractedMessage) {
        return {
            eventType,
            category: 'runtime',
            message: normalizeText(extractedMessage),
        };
    }

    return {
        eventType,
        category: 'runtime',
        message: eventType === 'raw_event' ? 'Received app-server activity event.' : `Received ${eventType}.`,
    };
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
    private readonly terminalIssueDetailsByIdentifier = new Map<string, Record<string, unknown>>();
    private readonly claimedIssueVersions = new Map<string, string>();
    private readonly runningDispatches = new Set<Promise<void>>();

    private lastTickAtIso?: string;
    private lastRateLimits: Record<string, unknown> = {};
    private startupCleanupCompleted = false;
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
                        terminalStates: config.tracker.terminalStates,
                        apiUrl: config.tracker.endpoint,
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
            ((factoryArgs) => {
                const linearToolAdapter =
                    factoryArgs.config.tracker.kind === 'linear'
                        ? new LinearTrackerAdapter({
                              config: {
                                  apiKey: factoryArgs.config.tracker.apiKey,
                                  projectSlug: factoryArgs.config.tracker.projectSlug,
                                  activeStates: factoryArgs.config.tracker.activeStates,
                                  terminalStates: factoryArgs.config.tracker.terminalStates,
                                  apiUrl: factoryArgs.config.tracker.endpoint,
                              },
                          })
                        : undefined;

                return new CodexAppServerClient({
                    logger: this.logger,
                    config: {
                        command: factoryArgs.config.codex.command,
                        workspacePath: factoryArgs.workspacePath,
                        readTimeoutMs: factoryArgs.config.codex.readTimeoutMs,
                        turnTimeoutMs: factoryArgs.config.codex.turnTimeoutMs,
                        approvalPolicy: factoryArgs.config.codex.approvalPolicy,
                        publishApprovalPolicy: factoryArgs.config.codex.publishApprovalPolicy,
                        publishNetworkAccess: factoryArgs.config.codex.publishNetworkAccess,
                        threadSandbox: factoryArgs.config.codex.threadSandbox,
                        turnSandboxPolicy: factoryArgs.config.codex.turnSandboxPolicy,
                    },
                    emitEvent: factoryArgs.emitEvent,
                    allowTrackerWriteToolApprovals: () =>
                        factoryArgs.runningEntry.hasObservedWorkspaceDiff || factoryArgs.runningEntry.turnCount > 0,
                    dynamicTools:
                        factoryArgs.config.tracker.kind === 'linear' && factoryArgs.config.tracker.apiKey.trim() !== ''
                            ? [
                                  {
                                      name: 'linear_graphql',
                                      description:
                                          "Execute raw Linear GraphQL queries or mutations using Symphony's configured tracker auth.",
                                      inputSchema: {
                                          type: 'object',
                                          additionalProperties: false,
                                          required: ['query'],
                                          properties: {
                                              query: {
                                                  type: 'string',
                                                  description:
                                                      'GraphQL query or mutation document to execute against Linear.',
                                              },
                                              variables: {
                                                  type: ['object', 'null'],
                                                  description: 'Optional GraphQL variables object.',
                                                  additionalProperties: true,
                                              },
                                          },
                                      },
                                  },
                              ]
                            : [],
                    dynamicToolCallHandler: async (request) => {
                        if (request.tool !== 'linear_graphql') {
                            return undefined;
                        }

                        if (factoryArgs.config.tracker.kind !== 'linear') {
                            return {
                                success: false,
                                contentItems: [
                                    {
                                        type: 'inputText',
                                        text:
                                            JSON.stringify({
                                                error: 'tool_unavailable',
                                                message: 'linear_graphql requires tracker.kind == "linear".',
                                            }) ?? 'null',
                                    },
                                ],
                            };
                        }

                        if (factoryArgs.config.tracker.apiKey.trim() === '') {
                            return {
                                success: false,
                                contentItems: [
                                    {
                                        type: 'inputText',
                                        text:
                                            JSON.stringify({
                                                error: 'missing_tracker_api_key',
                                                message: 'linear_graphql requires a configured Linear API key.',
                                            }) ?? 'null',
                                    },
                                ],
                            };
                        }

                        const toolResult = await linearToolAdapter?.executeLinearGraphQlToolCall(request.arguments);

                        return {
                            success: toolResult?.success ?? false,
                            contentItems: [
                                {
                                    type: 'inputText',
                                    text:
                                        JSON.stringify(
                                            toolResult?.payload ?? {
                                                error: 'tool_unavailable',
                                                message: 'linear_graphql is not available for this session.',
                                            },
                                        ) ?? 'null',
                                },
                            ],
                        };
                    },
                });
            });
    }

    public async runStartupCleanup(): Promise<void> {
        if (this.startupCleanupCompleted) {
            return;
        }

        this.startupCleanupCompleted = true;

        const config = this.workflowConfigStore.getCurrentConfig();
        const tracker = this.trackerFactory(config);
        const workspaceClient = this.workspaceFactory(config);

        try {
            const terminalIssues = await tracker.fetchIssueStatesByStateNames(config.tracker.terminalStates);
            const seenWorkspacePaths = new Set<string>();

            for (const issue of terminalIssues) {
                const workspacePath = workspaceClient.resolveWorkspacePath(issue.identifier);
                if (seenWorkspacePaths.has(workspacePath)) {
                    continue;
                }

                seenWorkspacePaths.add(workspacePath);

                try {
                    await workspaceClient.cleanupTerminalWorkspace(workspacePath);
                } catch (error) {
                    const classified = this.classifyError(error);
                    this.logger.warn('Startup terminal workspace cleanup failed for issue.', {
                        ...issueLogFields(issue),
                        workspacePath,
                        errorClass: classified.errorClass,
                        error: classified.message,
                    });
                }
            }
        } catch (error) {
            const classified = this.classifyError(error);
            this.logger.warn('Startup terminal workspace cleanup skipped due to tracker failure.', {
                errorClass: classified.errorClass,
                error: classified.message,
            });
        }
    }

    public getSnapshot(): OrchestratorSnapshot {
        return {
            lastTickAtIso: this.lastTickAtIso,
            running: Array.from(this.runningByIssueId.values())
                .map((entry) => {
                    const activity = this.buildRunningActivity(entry);

                    return {
                        issueId: entry.issue.id,
                        issueIdentifier: entry.issue.identifier,
                        attempt: entry.attempt,
                        source: entry.source,
                        startedAtIso: activity.startedAtIso,
                        runtimeSeconds: activity.runtimeSeconds,
                        lastActivityAtIso: activity.lastActivityAtIso,
                        idleSeconds: activity.idleSeconds,
                        suppressRetry: entry.suppressRetry,
                        sessionId: entry.sessionId ?? null,
                        threadId: entry.threadId ?? null,
                        turnCount: entry.turnCount,
                        lastEvent: entry.lastEventType ?? null,
                        lastActivity: entry.lastEventMessage ?? null,
                        totalTokens: activity.tokenUsage?.totalTokens ?? null,
                        lastTurnTokens: activity.tokenUsage?.lastTurnTokens ?? null,
                        contextWindowTokens: activity.tokenUsage?.contextWindowTokens ?? null,
                        contextHeadroomTokens: activity.tokenUsage?.contextHeadroomTokens ?? null,
                        contextUtilizationPercent: activity.tokenUsage?.contextUtilizationPercent ?? null,
                        traceTail: entry.traceEntries.slice(-SNAPSHOT_TRACE_TAIL_ENTRIES),
                    };
                })
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

    public getIssueDetails(issueIdentifier: string): Record<string, unknown> | undefined {
        const runningEntry = Array.from(this.runningByIssueId.values()).find(
            (entry) => entry.issue.identifier === issueIdentifier,
        );
        const retryEntry = Array.from(this.retryByIssueId.values()).find(
            (entry) => entry.issue.identifier === issueIdentifier,
        );
        const issue = runningEntry?.issue ?? retryEntry?.issue;
        const terminalIssueDetails = this.terminalIssueDetailsByIdentifier.get(issueIdentifier);

        if (!issue) {
            return terminalIssueDetails;
        }

        const payload = this.buildIssueDebugPayload({
            issue,
            status: runningEntry ? 'running' : retryEntry ? 'retrying' : 'idle',
            runningEntry,
            retryEntry,
        });

        if (retryEntry && !runningEntry && terminalIssueDetails) {
            return {
                ...terminalIssueDetails,
                ...payload,
                status: 'retrying',
                attempts: payload.attempts,
                retry: payload.retry,
                terminal: terminalIssueDetails.terminal ?? payload.terminal,
                trace: terminalIssueDetails.trace ?? payload.trace,
                error_class: terminalIssueDetails.error_class ?? payload.error_class,
                error: terminalIssueDetails.error ?? payload.error,
                input_required: terminalIssueDetails.input_required ?? payload.input_required,
            };
        }

        return payload;
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

            const tracker = this.trackerFactory(config);
            await this.reconcileRunningAndRetryState(config, tracker);
            this.workflowConfigStore.validateCurrentPreflight();

            if (this.runningByIssueId.size >= normalizePositiveInteger(config.agent.maxConcurrent)) {
                return;
            }

            this.dispatchDueRetries(config);
            if (this.runningByIssueId.size >= normalizePositiveInteger(config.agent.maxConcurrent)) {
                return;
            }

            const candidates = await tracker.fetchCandidateIssues();
            this.pruneClaimedIssueVersions(candidates);
            const maxCandidates = normalizePositiveInteger(config.polling.maxCandidates);
            const eligibleCandidates = this.selectEligibleCandidates(candidates, maxCandidates);

            for (const issue of eligibleCandidates) {
                if (!this.canDispatchIssue(config, issue)) {
                    continue;
                }

                this.dispatchIssue(config, issue, null, 'candidate');

                if (this.runningByIssueId.size >= normalizePositiveInteger(config.agent.maxConcurrent)) {
                    break;
                }
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

    private buildWorkspaceCleanupOptions(runningEntry: RunningEntry): WorkspaceCleanupOptions {
        const reviewStateName = runningEntry.reviewStateName ?? '';
        const completedReviewHandoff =
            reviewStateName.length > 0 &&
            isSuccessfulReviewHandoff(runningEntry.issue.stateName, reviewStateName, runningEntry);

        if (isReviewHandoffStop(runningEntry) || completedReviewHandoff) {
            return {
                closeOpenPrs: false,
                reason: 'review_handoff',
            };
        }

        return {
            closeOpenPrs: true,
            reason: runningEntry.stopReason ?? 'terminal_cleanup',
        };
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
            const terminalStates = new Set(config.tracker.terminalStates.map((state) => normalizeStateName(state)));
            const normalizedReviewState = normalizeStateName(config.tracker.reviewStateName);

            for (const [issueId, runningEntry] of this.runningByIssueId.entries()) {
                const currentState = normalizeStateName(statesByIssueId.get(issueId));
                if (activeStates.has(currentState)) {
                    runningEntry.issue = {
                        ...runningEntry.issue,
                        stateName: statesByIssueId.get(issueId) ?? runningEntry.issue.stateName,
                    };
                } else if (
                    currentState === normalizedReviewState &&
                    isSuccessfulReviewHandoff(
                        statesByIssueId.get(issueId) ?? runningEntry.issue.stateName,
                        config.tracker.reviewStateName,
                        runningEntry,
                    )
                ) {
                    await this.requestStopForRunningIssue(runningEntry, {
                        suppressRetry: true,
                        cleanupWorkspace: true,
                        reason: 'review_handoff',
                        currentState: statesByIssueId.get(issueId) ?? '',
                    });
                } else if (terminalStates.has(currentState)) {
                    await this.requestStopForRunningIssue(runningEntry, {
                        suppressRetry: true,
                        cleanupWorkspace: true,
                        reason: 'reconciliation_terminal',
                        currentState: statesByIssueId.get(issueId) ?? '',
                    });
                } else if (!runningEntry.suppressRetry) {
                    await this.requestStopForRunningIssue(runningEntry, {
                        suppressRetry: true,
                        cleanupWorkspace: false,
                        reason: 'reconciliation_non_active',
                        currentState: statesByIssueId.get(issueId) ?? '',
                    });
                }

                const stallTimeoutMs = normalizePositiveInteger(config.codex.stallTimeoutMs);
                if (stallTimeoutMs > 0) {
                    const elapsedSinceLastEventMs = this.nowMs() - runningEntry.lastEventAtMs;
                    const stallThresholdMs = stallTimeoutMs + this.stallGraceMs;
                    if (elapsedSinceLastEventMs > stallThresholdMs && !runningEntry.stallLogged) {
                        runningEntry.stallLogged = true;
                        this.logger.warn('Detected stalled running issue session', {
                            ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
                            elapsedSinceLastEventMs,
                            stallThresholdMs,
                        });
                        await this.requestStopForRunningIssue(runningEntry, {
                            suppressRetry: false,
                            cleanupWorkspace: false,
                            reason: 'stall_timeout',
                            currentState: statesByIssueId.get(issueId) ?? '',
                        });
                    }
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

    private dispatchDueRetries(config: LoadedWorkflowConfig): void {
        const nowMs = this.nowMs();
        const dueRetries = Array.from(this.retryByIssueId.values())
            .filter((entry) => entry.availableAtMs <= nowMs && !this.runningByIssueId.has(entry.issue.id))
            .sort((left, right) => {
                if (left.availableAtMs !== right.availableAtMs) {
                    return left.availableAtMs - right.availableAtMs;
                }

                return compareCandidateIssues(left.issue, right.issue);
            });

        for (const retryEntry of dueRetries) {
            if (this.runningByIssueId.size >= normalizePositiveInteger(config.agent.maxConcurrent)) {
                break;
            }

            if (!this.canDispatchIssue(config, retryEntry.issue)) {
                continue;
            }

            this.retryByIssueId.delete(retryEntry.issue.id);
            this.dispatchIssue(config, retryEntry.issue, retryEntry.attempt, 'retry', retryEntry);
        }
    }

    private selectEligibleCandidates(candidates: TrackedIssue[], maxCandidates: number): TrackedIssue[] {
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

    private canDispatchIssue(config: LoadedWorkflowConfig, issue: TrackedIssue): boolean {
        if (this.runningByIssueId.size >= normalizePositiveInteger(config.agent.maxConcurrent)) {
            return false;
        }

        const terminalStates = normalizeStateNames(config.tracker.terminalStates);
        const outstandingBlockers = getOutstandingBlockers(issue, terminalStates);
        if (outstandingBlockers.length > 0) {
            this.logger.info('Skipping issue because blockedBy issues are not terminal yet', {
                ...issueLogFields(issue),
                blockers: outstandingBlockers.map((blocker) => ({
                    id: blocker.id,
                    identifier: blocker.identifier,
                    state: blocker.state,
                })),
            });
            return false;
        }

        const normalizedState = normalizeStateName(issue.stateName);
        if (normalizedState.length === 0) {
            return true;
        }

        const perStateLimit = config.agent.maxConcurrentByState[normalizedState];
        if (!Number.isFinite(perStateLimit) || perStateLimit <= 0) {
            return true;
        }

        let runningInStateCount = 0;
        for (const runningEntry of this.runningByIssueId.values()) {
            if (normalizeStateName(runningEntry.issue.stateName) === normalizedState) {
                runningInStateCount += 1;
            }
        }

        return runningInStateCount < perStateLimit;
    }

    private dispatchIssue(
        config: LoadedWorkflowConfig,
        issue: TrackedIssue,
        attempt: number | null,
        source: DispatchSource,
        retryEntry?: RetryEntry,
    ): void {
        if (this.runningByIssueId.has(issue.id)) {
            return;
        }

        const runningEntry: RunningEntry = {
            issue,
            attempt,
            source,
            startedAtMs: this.nowMs(),
            lastEventAtMs: this.nowMs(),
            turnCount: 0,
            suppressRetry: false,
            stallLogged: false,
            stopRequested: false,
            cleanupWorkspaceOnStop: false,
            stopCurrentState: undefined,
            publishStateCheckpointInFlight: false,
            hasObservedWorkspaceDiff: false,
            firstTurnItemLifecycleEvents: 0,
            firstTurnCommandExecutionCount: 0,
            firstTurnBroadCommandExecutionCount: 0,
            workspaceStateCheckInFlight: false,
            firstRepoStepGuardTriggered: false,
            firstCommandDriftGuardTriggered: false,
            firstPlanOnlyGuardTriggered: false,
            firstRepoTargetPath: pickFirstRepoTargetPath(extractLikelyRepoPaths(issue.description)),
            currentAgentMessageBuffer: '',
            firstTurnAgentMessageText: undefined,
            firstTurnPlanOnlyCandidateAtMs: undefined,
            firstTurnPlanOnlyCandidateMessage: undefined,
            firstDiffObservedAtMs: undefined,
            postDiffCheckpointTriggered: false,
            retryDirective: retryEntry?.retryDirective,
            publishMode: retryEntry?.publishMode ?? shouldStartPublishMode(issue, config.tracker.mergeStateName),
            observedPullRequestMutation: false,
            observedOpenPullRequest: false,
            observedBranchPush: false,
            observedPullRequestMergeAttempt: false,
            observedHelperRepoPaths: [],
            workspaceBranchSourceOfTruthConfirmed: false,
            trackerClient: undefined,
            reviewStateName: undefined,
            terminalStates: undefined,
            traceEntries: [],
            firstTurnTraceEntries: [],
        };
        this.claimedIssueVersions.set(issue.id, issue.updatedAt);
        this.terminalIssueDetailsByIdentifier.delete(issue.identifier);
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
        let shouldCleanupWorkspace = false;
        let baselineWorkspaceState: WorkspaceStateSnapshot | undefined;
        let dispatchError: unknown;
        let completedTurnCount = 0;
        let shouldScheduleContinuationRetry = false;
        let terminalStatus: 'completed' | 'failed' | 'stopped' = 'failed';
        let terminalErrorClass: string | undefined;
        let terminalErrorMessage: string | undefined;
        let terminalInputRequiredType: string | undefined;
        let terminalInputRequiredPayload: Record<string, unknown> | undefined;
        let currentIssue = runningEntry.issue;
        let currentThreadId: string | undefined;
        const tracker = this.trackerFactory(config);
        const activeStates = normalizeStateNames(config.tracker.activeStates);
        const terminalStates = normalizeStateNames(config.tracker.terminalStates);
        const commitRequiredStates = normalizeStateNames(config.agent.commitRequiredStates);
        const maxTurns = Math.max(1, normalizePositiveInteger(config.agent.maxTurns, 1));
        const initialIssueState = currentIssue.stateName;

        try {
            if (tracker.prepareIssueForRun) {
                currentIssue = await tracker.prepareIssueForRun(currentIssue);
                runningEntry.issue = currentIssue;
                runningEntry.publishMode = shouldStartPublishMode(
                    currentIssue,
                    effectiveConfig.tracker.mergeStateName,
                    runningEntry,
                );
            }
            runningEntry.firstRepoTargetPath = pickFirstRepoTargetPath(
                extractLikelyRepoPaths(currentIssue.description),
            );
            this.recordTrace(runningEntry, 'runtime', 'issue/prepared', 'Issue prepared for execution.', {
                state: currentIssue.stateName,
                workpad_comment_id: currentIssue.workpadCommentId ?? null,
                first_repo_target_path: runningEntry.firstRepoTargetPath,
                publish_mode: runningEntry.publishMode,
            });

            workspaceClient = this.workspaceFactory(config);
            runningEntry.workspaceClient = workspaceClient;
            const workspaceHandle = await workspaceClient.prepareWorkspace(runningEntry.issue.identifier);
            workspacePath = workspaceHandle.path;
            runningEntry.workspacePath = workspacePath;
            runningEntry.trackerClient = tracker;
            runningEntry.reviewStateName = effectiveConfig.tracker.reviewStateName;
            runningEntry.terminalStates = effectiveConfig.tracker.terminalStates;
            this.recordTrace(runningEntry, 'workspace', 'workspace/prepared', 'Workspace prepared for issue.', {
                workspace_path: workspacePath,
            });

            this.throwIfDispatchStopped(runningEntry, 'Issue dispatch was cancelled before before_run hooks.');
            await workspaceClient.runBeforeRunHooks(workspacePath, {
                SYMPHONY_ISSUE_BRANCH_NAME: currentIssue.branchName ?? undefined,
            });
            beforeRunCompleted = true;
            this.throwIfDispatchStopped(runningEntry, 'Issue dispatch was cancelled before workspace state capture.');
            baselineWorkspaceState = await workspaceClient.captureWorkspaceState(workspacePath);
            runningEntry.baselineWorkspaceState = baselineWorkspaceState;
            if (baselineWorkspaceState.branchName) {
                currentIssue = {
                    ...currentIssue,
                    branchName: baselineWorkspaceState.branchName,
                };
                runningEntry.issue = currentIssue;
            }
            this.recordTrace(
                runningEntry,
                'workspace',
                'workspace/baseline_captured',
                'Captured baseline workspace state.',
                {
                    workspace_path: workspacePath,
                    head_sha: baselineWorkspaceState.headSha,
                    status_text: baselineWorkspaceState.statusText,
                    branch_name: baselineWorkspaceState.branchName,
                    publish_mode: runningEntry.publishMode,
                },
            );

            runningEntry.appServer = this.appServerFactory({
                config: effectiveConfig,
                workspacePath,
                runningEntry,
                emitEvent: (event) => this.handleOrchestratorEvent(event, runningEntry),
            });

            for (let turnNumber = 1; turnNumber <= maxTurns; turnNumber += 1) {
                this.throwIfDispatchStopped(runningEntry, 'Issue dispatch was cancelled before turn start.');

                const issueTemplatePayload = toIssueTemplatePayload(currentIssue);
                const dispatch = await this.workflowConfigStore.buildDispatchPrompt({
                    issue: issueTemplatePayload,
                    attempt: runningEntry.attempt,
                });
                effectiveConfig = dispatch.config;
                this.recordTrace(
                    runningEntry,
                    'turn',
                    turnNumber === 1 ? 'turn/dispatch_first' : 'turn/dispatch_continuation',
                    turnNumber === 1
                        ? `Starting first turn with first repo target ${String(
                              issueTemplatePayload.first_repo_target_path_or_default,
                          )}.`
                        : `Starting continuation turn ${turnNumber} of ${maxTurns}.`,
                    {
                        turn_number: turnNumber,
                        max_turns: maxTurns,
                        first_repo_target_path: issueTemplatePayload.first_repo_target_path ?? null,
                        first_repo_step_contract: issueTemplatePayload.first_repo_step_contract ?? null,
                        publish_mode: shouldUsePublishMode(
                            currentIssue,
                            effectiveConfig.tracker.mergeStateName,
                            runningEntry,
                        ),
                    },
                );

                const prompt =
                    turnNumber === 1
                        ? dispatch.prompt
                        : createContinuationPrompt(currentIssue, turnNumber, maxTurns, runningEntry.attempt);

                const turnResult = await runningEntry.appServer.runTurn({
                    prompt,
                    issueIdentifier: currentIssue.identifier,
                    issueTitle: currentIssue.title,
                    attempt: runningEntry.attempt,
                    publishMode: shouldUsePublishMode(
                        currentIssue,
                        effectiveConfig.tracker.mergeStateName,
                        runningEntry,
                    ),
                    threadId: currentThreadId,
                    responseTimeoutMs: effectiveConfig.codex.readTimeoutMs,
                    turnTimeoutMs: effectiveConfig.codex.turnTimeoutMs,
                });

                currentThreadId = turnResult.threadId;
                runningEntry.threadId = turnResult.threadId;
                runningEntry.sessionId = turnResult.sessionId;

                if (turnResult.status === 'input_required') {
                    this.codexTotals.inputRequired += 1;
                    const inputRequiredSummary = turnResult.inputRequiredPayload
                        ? summarizeRawEvent(turnResult.inputRequiredPayload)
                        : undefined;
                    if (inputRequiredSummary) {
                        runningEntry.lastEventAtMs = this.nowMs();
                        runningEntry.lastEventType = inputRequiredSummary.eventType;
                        runningEntry.lastEventMessage = inputRequiredSummary.message;
                    }

                    throw new AppServerClientError(
                        'turn_input_required',
                        inputRequiredSummary?.message ?? 'App-server turn requested user input.',
                        {
                            sessionId: turnResult.sessionId,
                            issueIdentifier: currentIssue.identifier,
                            inputRequiredType: turnResult.inputRequiredType ?? inputRequiredSummary?.eventType ?? null,
                            inputRequiredPayload: turnResult.inputRequiredPayload ?? null,
                        },
                    );
                }

                completedTurnCount += 1;
                runningEntry.turnCount += 1;
                runningEntry.lastEventAtMs = this.nowMs();
                runningEntry.lastEventType = 'turn_completed';
                runningEntry.lastEventMessage = `Turn ${turnResult.turnId} completed.`;
                this.recordTrace(runningEntry, 'turn', 'turn/completed', `Turn ${turnResult.turnId} completed.`, {
                    turn_id: turnResult.turnId,
                    session_id: turnResult.sessionId,
                    turn_number: turnNumber,
                });
                this.logger.info('Issue turn completed', {
                    ...issueLogFields(currentIssue, turnResult.sessionId),
                    attempt: runningEntry.attempt,
                    source: runningEntry.source,
                    turnNumber,
                    maxTurns,
                });

                const refreshedStates = await tracker.fetchIssueStatesByIds([currentIssue.id]);
                const refreshedState = refreshedStates.get(currentIssue.id) ?? currentIssue.stateName;
                currentIssue = {
                    ...currentIssue,
                    stateName: refreshedState,
                };
                runningEntry.issue = currentIssue;

                const autoMovedToReviewState = await this.maybeAutoMoveIssueToReviewState({
                    tracker,
                    runningEntry,
                    workspaceClient,
                    workspacePath,
                    currentIssue,
                    reviewStateName: effectiveConfig.tracker.reviewStateName,
                    mergeStateName: effectiveConfig.tracker.mergeStateName,
                });
                if (autoMovedToReviewState) {
                    currentIssue = autoMovedToReviewState;
                    runningEntry.issue = currentIssue;
                }

                const normalizedCurrentState = normalizeStateName(currentIssue.stateName);
                if (
                    !autoMovedToReviewState &&
                    tracker.syncIssueWorkpadToState &&
                    (isReviewState(currentIssue.stateName, effectiveConfig.tracker.reviewStateName) ||
                        terminalStates.has(normalizedCurrentState))
                ) {
                    currentIssue = await this.syncIssueWorkpadToStateBestEffort({
                        tracker,
                        issue: currentIssue,
                        sessionId: runningEntry.sessionId,
                        warningMessage: 'Failed to synchronize issue workpad after state refresh.',
                    });
                    runningEntry.issue = currentIssue;
                }

                if (runningEntry.stopRequested) {
                    shouldCleanupWorkspace = shouldCleanupWorkspace || runningEntry.cleanupWorkspaceOnStop;
                    break;
                }

                if (activeStates.has(normalizedCurrentState)) {
                    if (turnNumber >= maxTurns) {
                        shouldScheduleContinuationRetry = true;
                        break;
                    }

                    continue;
                }

                if (
                    isSuccessfulReviewHandoff(
                        currentIssue.stateName,
                        effectiveConfig.tracker.reviewStateName,
                        runningEntry,
                    )
                ) {
                    shouldCleanupWorkspace = true;
                }

                if (terminalStates.has(normalizedCurrentState)) {
                    shouldCleanupWorkspace = true;
                }

                break;
            }
        } catch (error) {
            dispatchError = error;
        }

        try {
            if (runningEntry.appServer) {
                await runningEntry.appServer.stop();
            }

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

            if (completedTurnCount === 0) {
                if (runningEntry.stopRequested && runningEntry.suppressRetry) {
                    this.retryByIssueId.delete(runningEntry.issue.id);
                    return;
                }

                throw new OrchestratorGuardrailError(
                    'workspace_no_committed_output',
                    'Issue turn finished without a completed turn result.',
                );
            }

            if (workspaceClient && workspacePath && baselineWorkspaceState) {
                const finalWorkspaceState = await workspaceClient.captureWorkspaceState(workspacePath);
                const normalizedFinalState = normalizeStateName(runningEntry.issue.stateName);
                const requiresCommittedWorkspaceProgress =
                    normalizedFinalState.length === 0 || commitRequiredStates.has(normalizedFinalState);

                if (
                    requiresCommittedWorkspaceProgress &&
                    !didWorkspaceHeadAdvance(baselineWorkspaceState, finalWorkspaceState)
                ) {
                    throw new OrchestratorGuardrailError(
                        'workspace_no_committed_output',
                        'Completed turn produced no committed workspace changes.',
                        {
                            workspacePath,
                            initialState: initialIssueState,
                            finalState: runningEntry.issue.stateName,
                            headSha: finalWorkspaceState.headSha,
                            dirtyStateChanged: baselineWorkspaceState.statusText !== finalWorkspaceState.statusText,
                            hasDirtyWorkspace: finalWorkspaceState.statusText.length > 0,
                        },
                    );
                }
            }

            this.codexTotals.completed += 1;
            this.retryByIssueId.delete(runningEntry.issue.id);

            if (shouldScheduleContinuationRetry && !runningEntry.suppressRetry) {
                this.scheduleRetry({
                    issue: runningEntry.issue,
                    attempt: nextRetryAttempt(runningEntry.attempt),
                    reason: 'continuation',
                    publishMode: runningEntry.publishMode,
                    delayMs: this.continuationDelayMs,
                    maxAttempts: effectiveConfig.agent.maxAttempts,
                });
                return;
            }

            if (shouldCleanupWorkspace || runningEntry.cleanupWorkspaceOnStop) {
                shouldCleanupWorkspace = true;
            }
            terminalStatus = 'completed';
        } catch (error) {
            const effectiveError =
                error instanceof AppServerClientError &&
                error.errorClass === 'turn_cancelled' &&
                (runningEntry.stopReason === 'first_repo_step_guard' ||
                    runningEntry.stopReason === 'first_command_drift_guard' ||
                    runningEntry.stopReason === 'first_plan_only_guard') &&
                runningEntry.pendingGuardrailError
                    ? runningEntry.pendingGuardrailError
                    : error;
            const classified = this.classifyError(effectiveError);
            const cancelledByReconciliation = classified.errorClass === 'turn_cancelled' && runningEntry.suppressRetry;
            const cancelledForPostDiffCheckpoint =
                classified.errorClass === 'turn_cancelled' && runningEntry.stopReason === 'post_diff_checkpoint';
            const cancelledAfterReviewHandoff =
                classified.errorClass === 'turn_cancelled' &&
                isReviewHandoffStop(runningEntry) &&
                isSuccessfulReviewHandoff(
                    runningEntry.stopCurrentState ?? runningEntry.issue.stateName,
                    effectiveConfig.tracker.reviewStateName,
                    runningEntry,
                );
            const cancelledAfterMergeCompletion =
                classified.errorClass === 'turn_cancelled' &&
                runningEntry.stopReason === 'reconciliation_terminal' &&
                runningEntry.publishMode &&
                runningEntry.observedPullRequestMergeAttempt &&
                normalizeStateNames(effectiveConfig.tracker.terminalStates).has(
                    normalizeStateName(runningEntry.stopCurrentState ?? runningEntry.issue.stateName),
                );
            terminalStatus =
                cancelledAfterMergeCompletion || cancelledAfterReviewHandoff
                    ? 'completed'
                    : cancelledByReconciliation || cancelledForPostDiffCheckpoint
                      ? 'stopped'
                      : 'failed';
            terminalErrorClass =
                cancelledAfterMergeCompletion || cancelledAfterReviewHandoff ? undefined : classified.errorClass;
            terminalErrorMessage =
                cancelledAfterMergeCompletion || cancelledAfterReviewHandoff ? undefined : classified.message;
            if (cancelledAfterMergeCompletion) {
                this.recordTrace(
                    runningEntry,
                    'runtime',
                    'dispatch/completed_after_terminal_merge',
                    'Issue reached a terminal tracker state after a PR merge attempt; treating the cancelled session as successful completion.',
                );
            } else if (cancelledAfterReviewHandoff) {
                shouldCleanupWorkspace = true;
                this.recordTrace(
                    runningEntry,
                    'runtime',
                    'dispatch/completed_after_review_handoff',
                    'Issue reached the review handoff state; treating the cancelled session as successful completion.',
                );
            } else {
                this.recordTrace(runningEntry, 'runtime', 'dispatch/failed', classified.message, {
                    error_class: classified.errorClass,
                });
            }

            if (effectiveError instanceof AppServerClientError && effectiveError.errorClass === 'turn_input_required') {
                terminalInputRequiredType =
                    typeof effectiveError.details.inputRequiredType === 'string'
                        ? effectiveError.details.inputRequiredType
                        : undefined;
                terminalInputRequiredPayload =
                    effectiveError.details.inputRequiredPayload &&
                    typeof effectiveError.details.inputRequiredPayload === 'object' &&
                    !Array.isArray(effectiveError.details.inputRequiredPayload)
                        ? (effectiveError.details.inputRequiredPayload as Record<string, unknown>)
                        : undefined;
            }

            if (cancelledAfterMergeCompletion || cancelledAfterReviewHandoff) {
                this.codexTotals.completed += 1;
            } else if (!cancelledByReconciliation && !cancelledForPostDiffCheckpoint) {
                this.codexTotals.failed += 1;
            }

            if (classified.errorClass === 'response_timeout') {
                this.codexTotals.responseTimeouts += 1;
            } else if (classified.errorClass === 'turn_timeout') {
                this.codexTotals.turnTimeouts += 1;
            } else if (classified.errorClass === 'launch_failed') {
                this.codexTotals.launchFailures += 1;
            }

            if (cancelledByReconciliation) {
                if (cancelledAfterMergeCompletion) {
                    this.logger.info('Issue dispatch completed after merge-triggered terminal reconciliation', {
                        ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
                        attempt: runningEntry.attempt,
                        source: runningEntry.source,
                        stopReason: runningEntry.stopReason ?? null,
                        currentState: runningEntry.stopCurrentState ?? runningEntry.issue.stateName,
                    });
                } else if (cancelledAfterReviewHandoff) {
                    this.logger.info('Issue dispatch completed after review handoff reconciliation', {
                        ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
                        attempt: runningEntry.attempt,
                        source: runningEntry.source,
                        stopReason: runningEntry.stopReason ?? null,
                        currentState: runningEntry.stopCurrentState ?? runningEntry.issue.stateName,
                    });
                } else {
                    this.logger.info('Issue dispatch cancelled by reconciliation', {
                        ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
                        attempt: runningEntry.attempt,
                        source: runningEntry.source,
                        stopReason: runningEntry.stopReason ?? null,
                    });
                }
            } else if (cancelledForPostDiffCheckpoint) {
                this.logger.info('Issue dispatch paused after first repo diff to continue in a follow-up turn', {
                    ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
                    attempt: runningEntry.attempt,
                    source: runningEntry.source,
                    stopReason: runningEntry.stopReason ?? null,
                });
            } else {
                this.logger.error('Issue dispatch failed', {
                    ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
                    attempt: runningEntry.attempt,
                    source: runningEntry.source,
                    errorClass: classified.errorClass,
                    error: classified.message,
                });
            }

            if (cancelledForPostDiffCheckpoint && !runningEntry.suppressRetry) {
                this.scheduleRetry({
                    issue: runningEntry.issue,
                    attempt: nextRetryAttempt(runningEntry.attempt),
                    reason: 'continuation',
                    publishMode: true,
                    delayMs: this.continuationDelayMs,
                    maxAttempts: effectiveConfig.agent.maxAttempts,
                    retryDirective: buildPostDiffCheckpointRetryDirective(runningEntry.firstRepoTargetPath),
                });
            } else if (!runningEntry.suppressRetry && this.isRetryableError(effectiveError)) {
                const retryAttempt = nextRetryAttempt(runningEntry.attempt);
                this.scheduleRetry({
                    issue: runningEntry.issue,
                    attempt: retryAttempt,
                    reason: 'dispatch_failed',
                    publishMode: runningEntry.publishMode,
                    delayMs: this.computeFailureRetryDelayMs(retryAttempt, effectiveConfig.agent.maxRetryBackoffMs),
                    maxAttempts: effectiveConfig.agent.maxAttempts,
                    errorClass: classified.errorClass,
                    retryDirective: buildRetryDirective(
                        runningEntry.firstRepoTargetPath,
                        runningEntry.firstTurnAgentMessageText,
                        classified.errorClass,
                    ),
                });
            } else {
                this.retryByIssueId.delete(runningEntry.issue.id);
            }
        } finally {
            runningEntry.appServer = undefined;

            if (workspaceClient && workspacePath) {
                const normalizedTerminalState = normalizeStateName(runningEntry.issue.stateName);
                const shouldCleanupHelperRepos =
                    runningEntry.workspaceBranchSourceOfTruthConfirmed &&
                    (isReviewHandoffStop(runningEntry) ||
                        runningEntry.stopReason === 'reconciliation_non_active' ||
                        runningEntry.stopReason === 'reconciliation_terminal' ||
                        normalizeStateNames(effectiveConfig.tracker.terminalStates).has(normalizedTerminalState));

                if (shouldCleanupHelperRepos) {
                    await this.cleanupObservedHelperReposBestEffort(runningEntry);
                }

                const shouldRemoveWorkspace = shouldCleanupWorkspace || runningEntry.cleanupWorkspaceOnStop;

                if (!effectiveConfig.workspace.keepTerminalWorkspaces && shouldRemoveWorkspace) {
                    try {
                        await workspaceClient.cleanupTerminalWorkspace(
                            workspacePath,
                            this.buildWorkspaceCleanupOptions(runningEntry),
                        );
                    } catch (error) {
                        const classified = this.classifyError(error);
                        this.logger.error('Workspace cleanup failed', {
                            ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
                            errorClass: classified.errorClass,
                            error: classified.message,
                        });
                    }
                } else if (!effectiveConfig.workspace.keepTerminalWorkspaces && !shouldRemoveWorkspace) {
                    this.logger.info('Preserving workspace for debugging because issue did not complete successfully', {
                        ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
                        workspacePath,
                    });
                }
            }

            this.terminalIssueDetailsByIdentifier.set(
                runningEntry.issue.identifier,
                this.buildIssueDebugPayload({
                    issue: runningEntry.issue,
                    status: terminalStatus,
                    runningEntry,
                    workspacePath,
                    errorClass: terminalErrorClass,
                    error: terminalErrorMessage,
                    inputRequiredType: terminalInputRequiredType,
                    inputRequiredPayload: terminalInputRequiredPayload,
                }),
            );
        }
    }

    private async requestStopForRunningIssue(
        runningEntry: RunningEntry,
        args: {
            suppressRetry: boolean;
            cleanupWorkspace: boolean;
            reason: RunningEntry['stopReason'];
            currentState: string;
        },
    ): Promise<void> {
        runningEntry.stopRequested = true;
        runningEntry.suppressRetry = args.suppressRetry;
        runningEntry.cleanupWorkspaceOnStop = runningEntry.cleanupWorkspaceOnStop || args.cleanupWorkspace;
        runningEntry.stopReason = args.reason;
        runningEntry.stopCurrentState = args.currentState;
        if (args.currentState.trim().length > 0) {
            runningEntry.issue = {
                ...runningEntry.issue,
                stateName: args.currentState,
            };
        }

        this.logger.info('Stopping running issue due to reconciliation decision', {
            ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
            currentState: args.currentState,
            suppressRetry: args.suppressRetry,
            cleanupWorkspace: runningEntry.cleanupWorkspaceOnStop,
            reason: args.reason,
        });
        this.recordTrace(
            runningEntry,
            args.reason === 'first_repo_step_guard' ||
                args.reason === 'first_command_drift_guard' ||
                args.reason === 'first_plan_only_guard'
                ? 'guard'
                : 'runtime',
            'runtime/stop_requested',
            `Stop requested for running issue (${args.reason}).`,
            {
                reason: args.reason,
                current_state: args.currentState,
                suppress_retry: args.suppressRetry,
                cleanup_workspace: runningEntry.cleanupWorkspaceOnStop,
            },
        );

        if (runningEntry.appServer) {
            await runningEntry.appServer.stop();
        }
    }

    private throwIfDispatchStopped(runningEntry: RunningEntry, message: string): void {
        if (!runningEntry.stopRequested) {
            return;
        }

        throw new AppServerClientError('turn_cancelled', message, {
            issueIdentifier: runningEntry.issue.identifier,
            stopReason: runningEntry.stopReason ?? null,
        });
    }

    private scheduleRetry(args: {
        issue: TrackedIssue;
        attempt: number;
        reason: RetryReason;
        publishMode: boolean;
        delayMs: number;
        maxAttempts: number;
        errorClass?: string;
        retryDirective?: string;
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
            publishMode: args.publishMode,
            errorClass: args.errorClass,
            retryDirective: args.retryDirective,
        });

        this.logger.info('Scheduled issue retry', {
            ...issueLogFields(args.issue),
            attempt: args.attempt,
            reason: args.reason,
            publishMode: args.publishMode,
            availableAtIso: new Date(availableAtMs).toISOString(),
            errorClass: args.errorClass,
            retryDirective: args.retryDirective ?? null,
        });
    }

    private buildRunningActivity(runningEntry: RunningEntry): {
        startedAtIso: string;
        runtimeSeconds: number;
        lastActivityAtIso: string;
        idleSeconds: number;
        tokenUsage: TokenUsageSummary | null;
    } {
        const nowMs = this.nowMs();

        return {
            startedAtIso: new Date(runningEntry.startedAtMs).toISOString(),
            runtimeSeconds: Math.max(0, Math.floor((nowMs - runningEntry.startedAtMs) / 1000)),
            lastActivityAtIso: new Date(runningEntry.lastEventAtMs).toISOString(),
            idleSeconds: Math.max(0, Math.floor((nowMs - runningEntry.lastEventAtMs) / 1000)),
            tokenUsage: summarizeTokenUsage(runningEntry.lastTokenUsage),
        };
    }

    private buildIssueDebugPayload(args: {
        issue: TrackedIssue;
        status: string;
        runningEntry?: RunningEntry;
        retryEntry?: RetryEntry;
        workspacePath?: string;
        errorClass?: string;
        error?: string;
        inputRequiredType?: string;
        inputRequiredPayload?: Record<string, unknown>;
    }): Record<string, unknown> {
        const activity = args.runningEntry ? this.buildRunningActivity(args.runningEntry) : undefined;
        const tokenUsage = activity?.tokenUsage ?? null;
        const workspacePath = args.workspacePath ?? args.runningEntry?.workspacePath;

        return {
            issue_identifier: args.issue.identifier,
            issue_id: args.issue.id,
            status: args.status,
            workspace: workspacePath
                ? {
                      path: workspacePath,
                  }
                : null,
            attempts: {
                restart_count: args.retryEntry
                    ? Math.max(args.retryEntry.attempt - 1, 0)
                    : args.runningEntry?.attempt ?? 0,
                current_retry_attempt: args.retryEntry?.attempt ?? args.runningEntry?.attempt ?? null,
            },
            running:
                args.status === 'running' && args.runningEntry
                    ? {
                          session_id: args.runningEntry.sessionId ?? null,
                          thread_id: args.runningEntry.threadId ?? null,
                          turn_count: args.runningEntry.turnCount,
                          state: args.runningEntry.issue.stateName,
                          started_at: activity?.startedAtIso ?? null,
                          runtime_seconds: activity?.runtimeSeconds ?? null,
                          last_event: args.runningEntry.lastEventType ?? null,
                          last_message: args.runningEntry.lastEventMessage ?? null,
                          last_event_at: activity?.lastActivityAtIso ?? null,
                          idle_seconds: activity?.idleSeconds ?? null,
                          tokens: args.runningEntry.lastTokenUsage ?? null,
                          total_tokens: tokenUsage?.totalTokens ?? null,
                          last_turn_tokens: tokenUsage?.lastTurnTokens ?? null,
                          context_window_tokens: tokenUsage?.contextWindowTokens ?? null,
                          context_headroom_tokens: tokenUsage?.contextHeadroomTokens ?? null,
                          context_utilization_percent: tokenUsage?.contextUtilizationPercent ?? null,
                          has_observed_workspace_diff: args.runningEntry.hasObservedWorkspaceDiff,
                          publish_mode: args.runningEntry.publishMode,
                          workspace_source_of_truth_confirmed: args.runningEntry.workspaceBranchSourceOfTruthConfirmed,
                          helper_repo_paths: args.runningEntry.observedHelperRepoPaths,
                          first_repo_target_path: args.runningEntry.firstRepoTargetPath ?? null,
                          first_turn_agent_message: args.runningEntry.firstTurnAgentMessageText ?? null,
                          first_turn_plan_only_candidate_message:
                              args.runningEntry.firstTurnPlanOnlyCandidateMessage ?? null,
                          first_turn_command_execution_count: args.runningEntry.firstTurnCommandExecutionCount,
                          first_turn_broad_command_execution_count:
                              args.runningEntry.firstTurnBroadCommandExecutionCount,
                          trace_tail: args.runningEntry.traceEntries.slice(-SNAPSHOT_TRACE_TAIL_ENTRIES),
                      }
                    : null,
            terminal:
                args.status !== 'running' && args.runningEntry
                    ? {
                          session_id: args.runningEntry.sessionId ?? null,
                          thread_id: args.runningEntry.threadId ?? null,
                          turn_count: args.runningEntry.turnCount,
                          state: args.runningEntry.issue.stateName,
                          started_at: activity?.startedAtIso ?? null,
                          runtime_seconds: activity?.runtimeSeconds ?? null,
                          last_event: args.runningEntry.lastEventType ?? null,
                          last_message: args.runningEntry.lastEventMessage ?? null,
                          last_event_at: activity?.lastActivityAtIso ?? null,
                          idle_seconds: activity?.idleSeconds ?? null,
                          tokens: args.runningEntry.lastTokenUsage ?? null,
                          total_tokens: tokenUsage?.totalTokens ?? null,
                          last_turn_tokens: tokenUsage?.lastTurnTokens ?? null,
                          context_window_tokens: tokenUsage?.contextWindowTokens ?? null,
                          context_headroom_tokens: tokenUsage?.contextHeadroomTokens ?? null,
                          context_utilization_percent: tokenUsage?.contextUtilizationPercent ?? null,
                          has_observed_workspace_diff: args.runningEntry.hasObservedWorkspaceDiff,
                          publish_mode: args.runningEntry.publishMode,
                          workspace_source_of_truth_confirmed: args.runningEntry.workspaceBranchSourceOfTruthConfirmed,
                          helper_repo_paths: args.runningEntry.observedHelperRepoPaths,
                          first_repo_target_path: args.runningEntry.firstRepoTargetPath ?? null,
                          first_turn_agent_message: args.runningEntry.firstTurnAgentMessageText ?? null,
                          first_turn_plan_only_candidate_message:
                              args.runningEntry.firstTurnPlanOnlyCandidateMessage ?? null,
                          first_turn_command_execution_count: args.runningEntry.firstTurnCommandExecutionCount,
                          first_turn_broad_command_execution_count:
                              args.runningEntry.firstTurnBroadCommandExecutionCount,
                          trace_tail: args.runningEntry.traceEntries.slice(-SNAPSHOT_TRACE_TAIL_ENTRIES),
                      }
                    : null,
            trace: args.runningEntry
                ? {
                      recent: args.runningEntry.traceEntries,
                      first_turn: args.runningEntry.firstTurnTraceEntries,
                  }
                : null,
            retry: args.retryEntry
                ? {
                      attempt: args.retryEntry.attempt,
                      reason: args.retryEntry.reason,
                      due_at: new Date(args.retryEntry.availableAtMs).toISOString(),
                      publish_mode: args.retryEntry.publishMode,
                      error_class: args.retryEntry.errorClass ?? null,
                      retry_directive: args.retryEntry.retryDirective ?? null,
                  }
                : null,
            error_class: args.errorClass ?? null,
            error: args.error ?? null,
            input_required:
                args.inputRequiredType || args.inputRequiredPayload
                    ? {
                          event_type: args.inputRequiredType ?? null,
                          payload: args.inputRequiredPayload ?? null,
                      }
                    : null,
            rate_limits: Object.keys(this.lastRateLimits).length > 0 ? this.lastRateLimits : null,
        };
    }

    private computeFailureRetryDelayMs(retryAttempt: number, maxRetryBackoffMs: number): number {
        const cappedBackoffMs =
            maxRetryBackoffMs > 0 && Number.isFinite(maxRetryBackoffMs) ? maxRetryBackoffMs : this.retryMaxDelayMs;
        const exponentialDelay = 10000 * Math.pow(2, Math.max(retryAttempt, 1) - 1);
        return Math.min(exponentialDelay, cappedBackoffMs);
    }

    private shouldEvaluateFirstRepoStepGuard(runningEntry: RunningEntry): boolean {
        if (
            runningEntry.turnCount > 0 ||
            runningEntry.hasObservedWorkspaceDiff ||
            runningEntry.firstRepoStepGuardTriggered ||
            runningEntry.stopRequested ||
            !runningEntry.workspaceClient ||
            !runningEntry.workspacePath ||
            !runningEntry.baselineWorkspaceState
        ) {
            return false;
        }

        return this.nowMs() - runningEntry.startedAtMs >= FIRST_REPO_STEP_GUARD_MIN_RUNTIME_MS;
    }

    private shouldEvaluateFirstCommandDriftGuard(runningEntry: RunningEntry): boolean {
        if (
            runningEntry.turnCount > 0 ||
            runningEntry.hasObservedWorkspaceDiff ||
            runningEntry.firstCommandDriftGuardTriggered ||
            runningEntry.stopRequested
        ) {
            return false;
        }

        return this.nowMs() - runningEntry.startedAtMs >= FIRST_COMMAND_DRIFT_GUARD_MIN_RUNTIME_MS;
    }

    private shouldEvaluateFirstPlanOnlyGuard(runningEntry: RunningEntry): boolean {
        if (
            runningEntry.turnCount > 0 ||
            runningEntry.hasObservedWorkspaceDiff ||
            runningEntry.firstPlanOnlyGuardTriggered ||
            runningEntry.stopRequested ||
            runningEntry.firstTurnPlanOnlyCandidateAtMs === undefined ||
            runningEntry.firstTurnCommandExecutionCount > 0
        ) {
            return false;
        }

        return (
            runningEntry.lastEventType === 'item/completed' ||
            this.nowMs() - runningEntry.firstTurnPlanOnlyCandidateAtMs >= FIRST_PLAN_ONLY_GUARD_GRACE_MS
        );
    }

    private shouldEvaluatePostDiffCheckpoint(runningEntry: RunningEntry): boolean {
        void runningEntry;
        // Upstream keeps the turn alive after the first repo diff and relies on
        // normal turn completion plus issue-state checks to decide whether the
        // agent should continue. Disabling the synthetic first-diff checkpoint
        // prevents continuation handoffs from consuming the normal retry budget.
        return false;
    }

    private hasReachedPostDiffCheckpointThreshold(runningEntry: RunningEntry): boolean {
        if (runningEntry.firstDiffObservedAtMs === undefined) {
            return false;
        }

        const tokenUsage = summarizeTokenUsage(runningEntry.lastTokenUsage);
        const contextUtilizationPercent = tokenUsage?.contextUtilizationPercent ?? null;

        if (
            contextUtilizationPercent !== null &&
            contextUtilizationPercent >= POST_DIFF_CHECKPOINT_CONTEXT_UTILIZATION_PERCENT
        ) {
            return true;
        }

        return this.nowMs() - runningEntry.firstDiffObservedAtMs >= POST_DIFF_CHECKPOINT_MIN_RUNTIME_AFTER_DIFF_MS;
    }

    private hasReachedFirstCommandDriftThreshold(runningEntry: RunningEntry): boolean {
        if (runningEntry.firstTurnBroadCommandExecutionCount >= FIRST_COMMAND_DRIFT_GUARD_BROAD_COMMAND_EXECUTIONS) {
            return true;
        }

        return runningEntry.firstTurnCommandExecutionCount >= FIRST_COMMAND_DRIFT_GUARD_TOTAL_COMMAND_EXECUTIONS;
    }

    private hasReachedFirstRepoStepGuardThreshold(runningEntry: RunningEntry): boolean {
        const tokenUsage = summarizeTokenUsage(runningEntry.lastTokenUsage);
        const contextUtilizationPercent = tokenUsage?.contextUtilizationPercent ?? null;

        if (
            contextUtilizationPercent !== null &&
            contextUtilizationPercent >= FIRST_REPO_STEP_GUARD_CONTEXT_UTILIZATION_PERCENT
        ) {
            return true;
        }

        return runningEntry.firstTurnItemLifecycleEvents >= FIRST_REPO_STEP_GUARD_ITEM_LIFECYCLE_EVENTS;
    }

    private async refreshWorkspaceDiffObservation(runningEntry: RunningEntry, force = false): Promise<boolean> {
        if (
            !runningEntry.workspaceClient ||
            !runningEntry.workspacePath ||
            !runningEntry.baselineWorkspaceState ||
            runningEntry.hasObservedWorkspaceDiff ||
            runningEntry.workspaceStateCheckInFlight
        ) {
            return runningEntry.hasObservedWorkspaceDiff;
        }

        const nowMs = this.nowMs();
        if (
            !force &&
            runningEntry.lastWorkspaceStateCheckAtMs !== undefined &&
            nowMs - runningEntry.lastWorkspaceStateCheckAtMs < WORKSPACE_PROGRESS_CHECK_MIN_INTERVAL_MS
        ) {
            return runningEntry.hasObservedWorkspaceDiff;
        }

        runningEntry.workspaceStateCheckInFlight = true;
        runningEntry.lastWorkspaceStateCheckAtMs = nowMs;

        try {
            const currentWorkspaceState = await runningEntry.workspaceClient.captureWorkspaceState(
                runningEntry.workspacePath,
            );
            if (
                didWorkspaceHeadAdvance(runningEntry.baselineWorkspaceState, currentWorkspaceState) ||
                runningEntry.baselineWorkspaceState.statusText !== currentWorkspaceState.statusText
            ) {
                runningEntry.hasObservedWorkspaceDiff = true;
                runningEntry.publishMode = true;
                runningEntry.firstDiffObservedAtMs ??= this.nowMs();
                runningEntry.firstTurnPlanOnlyCandidateAtMs = undefined;
                runningEntry.firstTurnPlanOnlyCandidateMessage = undefined;
                this.recordTrace(
                    runningEntry,
                    'workspace',
                    'workspace/first_diff_observed',
                    'Observed first repo diff/workspace progress.',
                    {
                        workspace_path: runningEntry.workspacePath,
                        head_sha: currentWorkspaceState.headSha,
                        status_text: currentWorkspaceState.statusText,
                        publish_mode: runningEntry.publishMode,
                    },
                );
                this.logger.info('Observed first repo diff/workspace progress during active turn.', {
                    ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
                    workspacePath: runningEntry.workspacePath,
                });
            }
        } catch (error) {
            const classified = this.classifyError(error);
            this.logger.warn('Workspace progress check failed during active turn.', {
                ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
                errorClass: classified.errorClass,
                error: classified.message,
            });
        } finally {
            runningEntry.workspaceStateCheckInFlight = false;
        }

        return runningEntry.hasObservedWorkspaceDiff;
    }

    private async maybeTriggerFirstRepoStepGuard(runningEntry: RunningEntry): Promise<void> {
        if (
            !this.shouldEvaluateFirstRepoStepGuard(runningEntry) ||
            !this.hasReachedFirstRepoStepGuardThreshold(runningEntry)
        ) {
            return;
        }

        const observedWorkspaceDiff = await this.refreshWorkspaceDiffObservation(runningEntry, true);
        if (observedWorkspaceDiff || runningEntry.firstRepoStepGuardTriggered || runningEntry.stopRequested) {
            return;
        }

        runningEntry.firstRepoStepGuardTriggered = true;
        const tokenUsage = summarizeTokenUsage(runningEntry.lastTokenUsage);
        const contextUtilizationPercent = tokenUsage?.contextUtilizationPercent ?? null;
        const message =
            'First-turn guard tripped before the first repo diff. Stop and retry with a narrower, concrete edit.';
        runningEntry.pendingGuardrailError = new OrchestratorGuardrailError('workspace_no_first_repo_step', message, {
            issueIdentifier: runningEntry.issue.identifier,
            workspacePath: runningEntry.workspacePath,
            runtimeMs: this.nowMs() - runningEntry.startedAtMs,
            contextUtilizationPercent,
            firstTurnItemLifecycleEvents: runningEntry.firstTurnItemLifecycleEvents,
        });

        this.logger.warn('Stopping issue because no repo diff was observed before first-turn guard threshold.', {
            ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
            workspacePath: runningEntry.workspacePath,
            contextUtilizationPercent,
            firstTurnItemLifecycleEvents: runningEntry.firstTurnItemLifecycleEvents,
        });
        this.recordTrace(
            runningEntry,
            'guard',
            'guard/first_repo_step',
            'Stopped issue because no repo diff was observed before the first-turn guard threshold.',
            {
                workspace_path: runningEntry.workspacePath,
                context_utilization_percent: contextUtilizationPercent,
                first_turn_item_lifecycle_events: runningEntry.firstTurnItemLifecycleEvents,
            },
        );

        await this.requestStopForRunningIssue(runningEntry, {
            suppressRetry: false,
            cleanupWorkspace: false,
            reason: 'first_repo_step_guard',
            currentState: runningEntry.issue.stateName,
        });
    }

    private async maybeTriggerFirstCommandDriftGuard(runningEntry: RunningEntry): Promise<void> {
        if (
            !this.shouldEvaluateFirstCommandDriftGuard(runningEntry) ||
            !this.hasReachedFirstCommandDriftThreshold(runningEntry)
        ) {
            return;
        }

        const observedWorkspaceDiff = await this.refreshWorkspaceDiffObservation(runningEntry, true);
        if (observedWorkspaceDiff || runningEntry.firstCommandDriftGuardTriggered || runningEntry.stopRequested) {
            return;
        }

        runningEntry.firstCommandDriftGuardTriggered = true;
        const message =
            'First turn drifted into pre-edit shell exploration before the first repo diff. Stop and retry with a direct file edit.';
        runningEntry.pendingGuardrailError = new OrchestratorGuardrailError(
            'workspace_first_repo_step_command_drift',
            message,
            {
                issueIdentifier: runningEntry.issue.identifier,
                workspacePath: runningEntry.workspacePath,
                runtimeMs: this.nowMs() - runningEntry.startedAtMs,
                firstRepoTargetPath: runningEntry.firstRepoTargetPath ?? null,
                firstTurnCommandExecutionCount: runningEntry.firstTurnCommandExecutionCount,
                firstTurnBroadCommandExecutionCount: runningEntry.firstTurnBroadCommandExecutionCount,
            },
        );

        this.logger.warn('Stopping issue because the first turn drifted into command exploration before a repo diff.', {
            ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
            workspacePath: runningEntry.workspacePath,
            firstRepoTargetPath: runningEntry.firstRepoTargetPath ?? null,
            firstTurnCommandExecutionCount: runningEntry.firstTurnCommandExecutionCount,
            firstTurnBroadCommandExecutionCount: runningEntry.firstTurnBroadCommandExecutionCount,
        });
        this.recordTrace(
            runningEntry,
            'guard',
            'guard/first_command_drift',
            'Stopped issue because the first turn drifted into command exploration before the first repo diff.',
            {
                workspace_path: runningEntry.workspacePath,
                first_repo_target_path: runningEntry.firstRepoTargetPath ?? null,
                first_turn_command_execution_count: runningEntry.firstTurnCommandExecutionCount,
                first_turn_broad_command_execution_count: runningEntry.firstTurnBroadCommandExecutionCount,
            },
        );

        await this.requestStopForRunningIssue(runningEntry, {
            suppressRetry: false,
            cleanupWorkspace: false,
            reason: 'first_command_drift_guard',
            currentState: runningEntry.issue.stateName,
        });
    }

    private async maybeTriggerFirstPlanOnlyGuard(runningEntry: RunningEntry): Promise<void> {
        if (!this.shouldEvaluateFirstPlanOnlyGuard(runningEntry)) {
            return;
        }

        const observedWorkspaceDiff = await this.refreshWorkspaceDiffObservation(runningEntry, true);
        if (observedWorkspaceDiff || runningEntry.firstPlanOnlyGuardTriggered || runningEntry.stopRequested) {
            return;
        }

        runningEntry.firstPlanOnlyGuardTriggered = true;
        const message =
            'First turn ended in a plan/status reply before the first repo diff. Stop and retry with a direct edit.';
        runningEntry.pendingGuardrailError = new OrchestratorGuardrailError(
            'workspace_first_repo_step_plan_only',
            message,
            {
                issueIdentifier: runningEntry.issue.identifier,
                workspacePath: runningEntry.workspacePath,
                runtimeMs: this.nowMs() - runningEntry.startedAtMs,
                firstRepoTargetPath: runningEntry.firstRepoTargetPath ?? null,
                firstTurnAgentMessage: runningEntry.firstTurnPlanOnlyCandidateMessage ?? null,
            },
        );

        this.logger.warn('Stopping issue because the first turn ended in a plan-only reply before a repo diff.', {
            ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
            workspacePath: runningEntry.workspacePath,
            firstRepoTargetPath: runningEntry.firstRepoTargetPath ?? null,
            firstTurnAgentMessage: runningEntry.firstTurnPlanOnlyCandidateMessage ?? null,
        });
        this.recordTrace(
            runningEntry,
            'guard',
            'guard/first_plan_only',
            'Stopped issue because the first turn ended in a plan/status reply before the first repo diff.',
            {
                workspace_path: runningEntry.workspacePath,
                first_repo_target_path: runningEntry.firstRepoTargetPath ?? null,
                first_turn_agent_message: runningEntry.firstTurnPlanOnlyCandidateMessage ?? null,
            },
        );

        await this.requestStopForRunningIssue(runningEntry, {
            suppressRetry: false,
            cleanupWorkspace: false,
            reason: 'first_plan_only_guard',
            currentState: runningEntry.issue.stateName,
        });
    }

    private async maybeTriggerPostDiffCheckpoint(runningEntry: RunningEntry): Promise<void> {
        if (
            !this.shouldEvaluatePostDiffCheckpoint(runningEntry) ||
            !this.hasReachedPostDiffCheckpointThreshold(runningEntry)
        ) {
            return;
        }

        runningEntry.postDiffCheckpointTriggered = true;
        const tokenUsage = summarizeTokenUsage(runningEntry.lastTokenUsage);
        const contextUtilizationPercent = tokenUsage?.contextUtilizationPercent ?? null;
        this.logger.info('Stopping issue after first repo diff to continue in a narrower follow-up turn.', {
            ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
            workspacePath: runningEntry.workspacePath,
            contextUtilizationPercent,
            firstRepoTargetPath: runningEntry.firstRepoTargetPath ?? null,
        });
        this.recordTrace(
            runningEntry,
            'turn',
            'turn/post_diff_checkpoint',
            'Stopping after the first repo diff so the next turn can focus on validation and commit work.',
            {
                workspace_path: runningEntry.workspacePath,
                context_utilization_percent: contextUtilizationPercent,
                first_repo_target_path: runningEntry.firstRepoTargetPath ?? null,
            },
        );

        await this.requestStopForRunningIssue(runningEntry, {
            suppressRetry: false,
            cleanupWorkspace: false,
            reason: 'post_diff_checkpoint',
            currentState: runningEntry.issue.stateName,
        });
    }

    private async maybeTriggerPublishStateCheckpoint(runningEntry: RunningEntry): Promise<void> {
        if (
            runningEntry.stopRequested ||
            !runningEntry.publishMode ||
            !runningEntry.trackerClient ||
            !runningEntry.reviewStateName ||
            !runningEntry.terminalStates ||
            runningEntry.publishStateCheckpointInFlight
        ) {
            return;
        }

        runningEntry.publishStateCheckpointInFlight = true;

        try {
            const refreshedStates = await runningEntry.trackerClient.fetchIssueStatesByIds([runningEntry.issue.id]);
            const currentState = refreshedStates.get(runningEntry.issue.id) ?? runningEntry.issue.stateName;
            const normalizedCurrentState = normalizeStateName(currentState);
            const normalizedReviewState = normalizeStateName(runningEntry.reviewStateName);
            const terminalStates = normalizeStateNames(runningEntry.terminalStates);

            if (normalizedCurrentState.length === 0) {
                return;
            }

            if (normalizedCurrentState !== normalizedReviewState && !terminalStates.has(normalizedCurrentState)) {
                return;
            }

            const workspaceIsSourceOfTruth = await this.confirmWorkspaceBranchSourceOfTruth(runningEntry);
            if (!workspaceIsSourceOfTruth) {
                this.recordTrace(
                    runningEntry,
                    'workspace',
                    'workspace/review_handoff_skipped_unconfirmed_source',
                    'Skipped publish handoff because the issue workspace is not yet the confirmed source of truth.',
                    {
                        workspace_path: runningEntry.workspacePath,
                        current_state: currentState,
                    },
                );
                return;
            }

            if (runningEntry.workspaceClient && runningEntry.workspacePath) {
                const workspaceState = await runningEntry.workspaceClient.captureWorkspaceState(
                    runningEntry.workspacePath,
                );
                if (workspaceState.statusText.trim().length > 0) {
                    this.recordTrace(
                        runningEntry,
                        'workspace',
                        'workspace/review_handoff_skipped_dirty',
                        'Kept issue active after publish because the workspace still has uncommitted changes.',
                        {
                            workspace_path: runningEntry.workspacePath,
                            status_text: workspaceState.statusText,
                        },
                    );
                    return;
                }
            }

            runningEntry.issue = {
                ...runningEntry.issue,
                stateName: currentState,
            };

            if (runningEntry.trackerClient.syncIssueWorkpadToState) {
                try {
                    runningEntry.issue = await runningEntry.trackerClient.syncIssueWorkpadToState(runningEntry.issue);
                } catch (error) {
                    const classified = this.classifyError(error);
                    this.logger.warn('Failed to synchronize issue workpad during publish-state checkpoint.', {
                        ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
                        errorClass: classified.errorClass,
                        error: classified.message,
                        currentState,
                    });
                }
            }

            if (
                normalizedCurrentState === normalizedReviewState &&
                isSuccessfulReviewHandoff(currentState, runningEntry.reviewStateName, runningEntry)
            ) {
                this.logger.info('Stopping publish turn immediately after issue entered review state.', {
                    ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
                    currentState,
                    helperRepoPaths: runningEntry.observedHelperRepoPaths,
                });
                this.recordTrace(
                    runningEntry,
                    'turn',
                    'turn/review_handoff_checkpoint',
                    `Stopping immediately after issue entered ${currentState}.`,
                    {
                        current_state: currentState,
                    },
                );
                await this.requestStopForRunningIssue(runningEntry, {
                    suppressRetry: true,
                    cleanupWorkspace: true,
                    reason: 'review_handoff',
                    currentState,
                });
                return;
            }

            this.logger.info('Stopping publish turn immediately after issue reached a terminal state.', {
                ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
                currentState,
                helperRepoPaths: runningEntry.observedHelperRepoPaths,
            });
            this.recordTrace(
                runningEntry,
                'turn',
                'turn/terminal_state_checkpoint',
                `Stopping immediately after issue reached terminal state ${currentState}.`,
                {
                    current_state: currentState,
                },
            );
            await this.requestStopForRunningIssue(runningEntry, {
                suppressRetry: true,
                cleanupWorkspace: true,
                reason: 'reconciliation_terminal',
                currentState,
            });
        } finally {
            runningEntry.publishStateCheckpointInFlight = false;
        }
    }

    private async confirmWorkspaceBranchSourceOfTruth(runningEntry: RunningEntry): Promise<boolean> {
        if (
            runningEntry.workspaceBranchSourceOfTruthConfirmed ||
            !runningEntry.workspaceClient ||
            !runningEntry.workspacePath ||
            !runningEntry.baselineWorkspaceState
        ) {
            return runningEntry.workspaceBranchSourceOfTruthConfirmed;
        }

        const expectedBranchName =
            runningEntry.issue.branchName?.trim() ||
            runningEntry.baselineWorkspaceState.branchName?.trim() ||
            undefined;

        if (!expectedBranchName) {
            return false;
        }

        try {
            const workspaceState = await runningEntry.workspaceClient.captureWorkspaceState(runningEntry.workspacePath);
            const branchMatches = workspaceState.branchName?.trim() === expectedBranchName;
            const publishProgressObserved =
                runningEntry.observedBranchPush ||
                runningEntry.observedPullRequestMutation ||
                runningEntry.observedOpenPullRequest ||
                runningEntry.hasObservedWorkspaceDiff ||
                didWorkspaceHeadAdvance(runningEntry.baselineWorkspaceState, workspaceState);

            if (!branchMatches || !publishProgressObserved) {
                return false;
            }

            runningEntry.workspaceBranchSourceOfTruthConfirmed = true;
            this.recordTrace(
                runningEntry,
                'workspace',
                'workspace/source_of_truth_confirmed',
                'Confirmed the issue workspace branch as the source of truth for publish handoff.',
                {
                    workspace_path: runningEntry.workspacePath,
                    branch_name: workspaceState.branchName,
                    head_sha: workspaceState.headSha,
                    status_text: workspaceState.statusText,
                },
            );
            return true;
        } catch (error) {
            const classified = this.classifyError(error);
            this.logger.warn('Failed to confirm issue workspace as publish source of truth.', {
                ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
                errorClass: classified.errorClass,
                error: classified.message,
            });
            return false;
        }
    }

    private async cleanupObservedHelperReposBestEffort(runningEntry: RunningEntry): Promise<void> {
        if (!runningEntry.workspacePath) {
            return;
        }

        const candidatePaths = new Set<string>(runningEntry.observedHelperRepoPaths);

        try {
            const directoryEntries = await readdir(runningEntry.workspacePath, {withFileTypes: true});
            for (const entry of directoryEntries) {
                if (!entry.name.startsWith('.git-codex-local-')) {
                    continue;
                }

                candidatePaths.add(path.join(runningEntry.workspacePath, entry.name));
            }
        } catch (error) {
            const filesystemError = error as NodeJS.ErrnoException;
            if (filesystemError.code === 'ENOENT') {
                return;
            }
            const classified = this.classifyError(error);
            this.logger.warn('Failed to enumerate helper repos for publish cleanup.', {
                ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
                errorClass: classified.errorClass,
                error: classified.message,
                workspacePath: runningEntry.workspacePath,
            });
        }

        for (const candidatePath of candidatePaths) {
            if (!isHelperRepoPath(candidatePath) || !isPathWithinWorkspace(candidatePath, runningEntry.workspacePath)) {
                continue;
            }

            try {
                await rm(candidatePath, {recursive: true, force: true});
                this.recordTrace(
                    runningEntry,
                    'workspace',
                    'workspace/helper_repo_cleaned',
                    'Removed a temporary helper repo after publish handoff.',
                    {
                        helper_repo_path: candidatePath,
                    },
                );
            } catch (error) {
                const classified = this.classifyError(error);
                this.logger.warn('Failed to remove temporary helper repo after publish handoff.', {
                    ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
                    errorClass: classified.errorClass,
                    error: classified.message,
                    helperRepoPath: candidatePath,
                });
            }
        }
    }

    private async maybeAutoMoveIssueToReviewState(args: {
        tracker: TrackerClient;
        runningEntry: RunningEntry;
        workspaceClient: WorkspaceClient;
        workspacePath: string;
        currentIssue: TrackedIssue;
        reviewStateName: string;
        mergeStateName: string;
    }): Promise<TrackedIssue | null> {
        const {tracker, runningEntry, workspaceClient, workspacePath, currentIssue, reviewStateName, mergeStateName} =
            args;
        if (!tracker.moveIssueToStateByName) {
            return null;
        }

        const normalizedState = normalizeStateName(currentIssue.stateName);
        if (
            normalizedState === normalizeStateName(reviewStateName) ||
            normalizedState === normalizeStateName(mergeStateName)
        ) {
            return null;
        }

        if (!runningEntry.observedOpenPullRequest) {
            return null;
        }

        if (!runningEntry.observedPullRequestMutation && !runningEntry.observedBranchPush) {
            return null;
        }

        if (!(await this.confirmWorkspaceBranchSourceOfTruth(runningEntry))) {
            this.recordTrace(
                runningEntry,
                'workspace',
                'workspace/review_handoff_skipped_unconfirmed_source',
                'Skipped auto-move to review because the issue workspace is not yet the confirmed source of truth.',
                {
                    workspace_path: workspacePath,
                },
            );
            return null;
        }

        const workspaceState = await workspaceClient.captureWorkspaceState(workspacePath);
        if (workspaceState.statusText.trim().length > 0) {
            this.recordTrace(
                runningEntry,
                'workspace',
                'workspace/review_handoff_skipped_dirty',
                'Kept issue active after publish because the workspace still has uncommitted changes.',
                {
                    workspace_path: workspacePath,
                    status_text: workspaceState.statusText,
                },
            );
            return null;
        }

        const nextIssue = await tracker.moveIssueToStateByName(currentIssue, reviewStateName);
        this.logger.info(`Moved issue to ${reviewStateName} after successful PR publish.`, {
            ...issueLogFields(nextIssue, runningEntry.sessionId),
            workspacePath,
        });
        this.recordTrace(
            runningEntry,
            'runtime',
            'issue/moved_to_review_state',
            `Moved issue to ${reviewStateName} after a successful publish turn.`,
            {
                workspace_path: workspacePath,
                previous_state: currentIssue.stateName,
                next_state: nextIssue.stateName,
            },
        );

        return await this.syncIssueWorkpadToStateBestEffort({
            tracker,
            issue: nextIssue,
            sessionId: runningEntry.sessionId,
            warningMessage: 'Failed to synchronize issue workpad after moving issue to review state.',
        });
    }

    private async syncIssueWorkpadToStateBestEffort(args: {
        tracker: TrackerClient;
        issue: TrackedIssue;
        sessionId?: string | null;
        warningMessage: string;
    }): Promise<TrackedIssue> {
        if (!args.tracker.syncIssueWorkpadToState) {
            return args.issue;
        }

        try {
            return await args.tracker.syncIssueWorkpadToState(args.issue);
        } catch (error) {
            const classified = this.classifyError(error);
            this.logger.warn(args.warningMessage, {
                ...issueLogFields(args.issue, args.sessionId ?? undefined),
                errorClass: classified.errorClass,
                error: classified.message,
            });
            return args.issue;
        }
    }

    private handleOrchestratorEvent(event: OrchestratorEvent, runningEntry: RunningEntry): void {
        runningEntry.lastEventAtMs = this.nowMs();
        runningEntry.lastEventType = event.type;

        if (event.type === 'trace') {
            runningEntry.lastEventType = event.eventType;
            runningEntry.lastEventMessage = event.message;
            this.recordTrace(runningEntry, event.category, event.eventType, event.message, event.details);
            if (
                event.eventType === 'tool/call/responded' &&
                event.category === 'tool' &&
                event.details?.tool === 'linear_graphql' &&
                event.details?.success === true
            ) {
                void this.maybeTriggerPublishStateCheckpoint(runningEntry);
            }
            return;
        }

        if (event.type === 'rate_limit') {
            this.lastRateLimits = {...event.payload};
            runningEntry.lastEventMessage = 'Rate limits updated.';
            return;
        }

        if (event.type === 'session') {
            runningEntry.threadId = event.threadId;
            runningEntry.sessionId = event.sessionId;
            runningEntry.lastEventMessage = 'Session started.';
            this.recordTrace(runningEntry, 'runtime', 'session/started', 'Session started.', {
                thread_id: event.threadId,
                turn_id: event.turnId,
                session_id: event.sessionId,
            });
            this.logger.info('Codex session created', {
                ...issueLogFields(runningEntry.issue, event.sessionId),
                threadId: event.threadId,
                turnId: event.turnId,
            });
            return;
        }

        if (event.type === 'diagnostic') {
            runningEntry.lastEventMessage = normalizeText(event.message);
            this.recordTrace(runningEntry, 'diagnostic', `diagnostic/${event.stream}`, normalizeText(event.message), {
                stream: event.stream,
            });
            this.logger.info('Codex diagnostic event', {
                ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
                stream: event.stream,
                message: event.message,
            });
            return;
        }

        if (event.type === 'token_usage') {
            runningEntry.lastTokenUsage = {...event.payload};
            runningEntry.lastEventMessage = describeTokenUsage(summarizeTokenUsage(runningEntry.lastTokenUsage));
            this.logger.info('Codex token usage event', {
                ...issueLogFields(runningEntry.issue, runningEntry.sessionId),
                usage: event.payload,
            });
            void this.maybeTriggerPostDiffCheckpoint(runningEntry);
            return;
        }

        if (event.type === 'raw_event') {
            const summary = summarizeRawEvent(event.payload);
            runningEntry.lastEventType = summary.eventType;
            runningEntry.lastEventMessage = summary.message;
            const normalizedEventType = summary.eventType.trim().toLowerCase();
            let traceDetails = summary.details;

            if (
                (normalizedEventType === 'item/agentmessage/delta' ||
                    normalizedEventType === 'codex/event/agent_message_delta' ||
                    normalizedEventType === 'codex/event/agent_message_content_delta') &&
                typeof summary.details?.delta === 'string'
            ) {
                runningEntry.currentAgentMessageBuffer = appendMergedCappedText(
                    runningEntry.currentAgentMessageBuffer,
                    summary.details.delta,
                    FIRST_AGENT_MESSAGE_MAX_CHARS,
                );
            }

            if (
                (normalizedEventType === 'item/started' || normalizedEventType === 'item/completed') &&
                isAgentMessageItemKind(summary.details?.item_kind)
            ) {
                if (normalizedEventType === 'item/started') {
                    runningEntry.currentAgentMessageBuffer = '';
                } else {
                    const completedAgentMessage = normalizeAgentMessageText(runningEntry.currentAgentMessageBuffer);
                    runningEntry.currentAgentMessageBuffer = '';

                    if (completedAgentMessage.length > 0) {
                        runningEntry.lastEventMessage = completedAgentMessage;
                        if (!runningEntry.firstTurnAgentMessageText) {
                            runningEntry.firstTurnAgentMessageText = completedAgentMessage;
                        }

                        this.recordTrace(
                            runningEntry,
                            'agent',
                            'agent/message_completed',
                            normalizeText(completedAgentMessage, 280),
                            {
                                text: completedAgentMessage,
                                char_count: completedAgentMessage.length,
                            },
                        );

                        if (
                            runningEntry.turnCount === 0 &&
                            !runningEntry.hasObservedWorkspaceDiff &&
                            runningEntry.firstTurnCommandExecutionCount === 0 &&
                            isPlanOnlyFirstTurnMessage(completedAgentMessage)
                        ) {
                            runningEntry.firstTurnPlanOnlyCandidateAtMs = this.nowMs();
                            runningEntry.firstTurnPlanOnlyCandidateMessage = completedAgentMessage;
                            this.recordTrace(
                                runningEntry,
                                'guard',
                                'guard/first_plan_only_candidate',
                                'Detected a first-turn plan/status reply before the first repo diff.',
                                {
                                    first_repo_target_path: runningEntry.firstRepoTargetPath ?? null,
                                    first_turn_agent_message: completedAgentMessage,
                                },
                            );
                        }
                    }
                }
            }

            if (
                normalizedEventType === 'codex/event/exec_command_begin' &&
                runningEntry.turnCount === 0 &&
                !runningEntry.hasObservedWorkspaceDiff
            ) {
                const command = typeof summary.details?.command === 'string' ? summary.details.command : '';
                const isBroadBeforeFirstDiff = isBroadCommandBeforeFirstDiff(command, runningEntry.firstRepoTargetPath);
                runningEntry.firstTurnCommandExecutionCount += 1;
                runningEntry.firstTurnPlanOnlyCandidateAtMs = undefined;
                runningEntry.firstTurnPlanOnlyCandidateMessage = undefined;
                if (isBroadBeforeFirstDiff) {
                    runningEntry.firstTurnBroadCommandExecutionCount += 1;
                }

                traceDetails = {
                    ...(summary.details ?? {}),
                    first_repo_target_path: runningEntry.firstRepoTargetPath ?? null,
                    targets_first_repo_path:
                        command.length > 0
                            ? commandMentionsTargetPath(command, runningEntry.firstRepoTargetPath)
                            : false,
                    is_broad_before_first_diff: isBroadBeforeFirstDiff,
                };
            }

            if (normalizedEventType === 'codex/event/exec_command_end') {
                const command = typeof summary.details?.command === 'string' ? summary.details.command : null;
                const cwd = typeof summary.details?.cwd === 'string' ? summary.details.cwd : null;
                const exitCode = typeof summary.details?.exit_code === 'number' ? summary.details.exit_code : null;
                runningEntry.observedPullRequestMutation =
                    runningEntry.observedPullRequestMutation || didCommandMutatePullRequest(command, exitCode);
                runningEntry.observedOpenPullRequest =
                    runningEntry.observedOpenPullRequest || didCommandObserveOpenPullRequest(command, exitCode);
                runningEntry.observedBranchPush =
                    runningEntry.observedBranchPush || didCommandPushBranch(command, exitCode);
                runningEntry.observedPullRequestMergeAttempt =
                    runningEntry.observedPullRequestMergeAttempt || didCommandAttemptPullRequestMerge(command);

                if (cwd && isHelperRepoPath(cwd)) {
                    addUniquePath(runningEntry.observedHelperRepoPaths, cwd);
                    this.recordTrace(
                        runningEntry,
                        'workspace',
                        'workspace/helper_repo_activity',
                        'Observed helper repo command activity while the issue workspace remained the source of truth.',
                        {
                            command,
                            cwd,
                            exit_code: exitCode,
                        },
                    );
                }

                if (
                    runningEntry.publishMode &&
                    (didCommandObserveOpenPullRequest(command, exitCode) ||
                        didCommandMutatePullRequest(command, exitCode) ||
                        didCommandPushBranch(command, exitCode))
                ) {
                    void this.maybeTriggerPublishStateCheckpoint(runningEntry);
                }
            }

            if (summary.category !== 'agent') {
                this.recordTrace(runningEntry, summary.category, summary.eventType, summary.message, traceDetails);
            }
            if (normalizedEventType === 'item/started' || normalizedEventType === 'item/completed') {
                runningEntry.firstTurnItemLifecycleEvents += 1;
            }

            if (normalizedEventType.includes('diff')) {
                runningEntry.firstTurnPlanOnlyCandidateAtMs = undefined;
                runningEntry.firstTurnPlanOnlyCandidateMessage = undefined;
                void this.refreshWorkspaceDiffObservation(runningEntry, true);
            }

            void this.maybeTriggerPostDiffCheckpoint(runningEntry);
        }
    }

    private recordTrace(
        runningEntry: RunningEntry,
        category: OrchestratorTraceCategory,
        eventType: string,
        message: string,
        details?: Record<string, unknown>,
    ): void {
        const entry: TraceEntry = {
            atIso: new Date(this.nowMs()).toISOString(),
            category,
            eventType,
            message,
            details,
        };

        runningEntry.traceEntries.push(entry);
        if (runningEntry.traceEntries.length > TRACE_MAX_ENTRIES) {
            runningEntry.traceEntries.splice(0, runningEntry.traceEntries.length - TRACE_MAX_ENTRIES);
        }

        if (runningEntry.turnCount === 0) {
            runningEntry.firstTurnTraceEntries.push(entry);
            if (runningEntry.firstTurnTraceEntries.length > FIRST_TURN_TRACE_MAX_ENTRIES) {
                runningEntry.firstTurnTraceEntries.splice(
                    0,
                    runningEntry.firstTurnTraceEntries.length - FIRST_TURN_TRACE_MAX_ENTRIES,
                );
            }
        }
    }

    private isRetryableError(error: unknown): boolean {
        if (error instanceof OrchestratorGuardrailError) {
            return true;
        }

        if (error instanceof AppServerClientError) {
            return error.errorClass !== 'turn_input_required' && error.errorClass !== 'approval_required';
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
