import {readFile, stat} from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import {parse as parseYaml} from 'yaml';
import type {Logger} from './logger.js';
import {renderStrictTemplate, type PromptTemplateContext, validateStrictTemplate} from './template.js';

interface ResolveWorkflowPathArgs {
    cliWorkflowPath?: string;
    cwd: string;
    moduleDir: string;
}

interface MutableRecord {
    [key: string]: unknown;
}

interface WorkflowFrontMatter {
    tracker: MutableRecord;
    polling: MutableRecord;
    server: MutableRecord;
    workspace: MutableRecord;
    hooks: MutableRecord;
    agent: MutableRecord;
    codex: MutableRecord;
}

export type WorkflowErrorClass =
    | 'missing_workflow_file'
    | 'workflow_parse_error'
    | 'workflow_front_matter_not_a_map'
    | 'preflight_missing_tracker_api_key'
    | 'preflight_missing_tracker_project_slug'
    | 'preflight_missing_codex_command'
    | 'invalid_codex_approval_policy'
    | 'invalid_codex_thread_sandbox'
    | 'invalid_codex_turn_sandbox_policy'
    | 'template_parse_error'
    | 'template_render_error';

export class WorkflowConfigError extends Error {
    public readonly errorClass: WorkflowErrorClass;

    public constructor(errorClass: WorkflowErrorClass, message: string) {
        super(message);
        this.name = 'WorkflowConfigError';
        this.errorClass = errorClass;
    }
}

interface FileSnapshot {
    readonly mtimeMs: number;
    readonly size: number;
}

interface LoadedWorkflowDocument {
    readonly snapshot: FileSnapshot;
    readonly contents: string;
}

export interface TrackerConfig {
    kind: string;
    provider: string;
    endpoint: string;
    apiKey: string;
    projectSlug: string;
    activeStates: string[];
    terminalStates: string[];
    reviewStateName: string;
    mergeStateName: string;
}

export interface PollingConfig {
    intervalMs: number;
    maxCandidates: number;
}

export interface WorkspaceConfig {
    root: string;
    keepTerminalWorkspaces: boolean;
}

export interface ServerConfig {
    host?: string;
    port?: number;
}

export interface HookConfig {
    timeoutMs: number;
    afterCreate: string[];
    beforeRun: string[];
    afterRun: string[];
    beforeRemove: string[];
}

export interface AgentConfig {
    maxConcurrent: number;
    maxAttempts: number;
    maxTurns: number;
    maxRetryBackoffMs: number;
    maxConcurrentByState: Record<string, number>;
    commitRequiredStates: string[];
}

export interface CodexConfig {
    command: string;
    readTimeoutMs: number;
    responseTimeoutMs: number;
    turnTimeoutMs: number;
    stallTimeoutMs: number;
    approvalPolicy?: unknown;
    publishApprovalPolicy?: unknown;
    publishNetworkAccess: boolean;
    threadSandbox?: unknown;
    turnSandboxPolicy?: unknown;
}

export interface WorkflowConfig {
    workflowPath: string;
    promptTemplate: string;
    tracker: TrackerConfig;
    polling: PollingConfig;
    server: ServerConfig;
    workspace: WorkspaceConfig;
    hooks: HookConfig;
    agent: AgentConfig;
    codex: CodexConfig;
}

export interface LoadedWorkflowConfig extends WorkflowConfig {
    loadedAtIso: string;
}

interface ParseWorkflowConfigArgs {
    workflowPath: string;
    contents: string;
    env: NodeJS.ProcessEnv;
    homeDir: string;
}

interface WorkflowConfigStoreArgs {
    workflowPath: string;
    logger: Logger;
    env?: NodeJS.ProcessEnv;
    homeDir?: string;
}

export interface DispatchConfigResult {
    readonly config: LoadedWorkflowConfig;
    readonly prompt: string;
}

const FRONT_MATTER_PATTERN = /^---\s*\n([\s\S]*?)\n---\s*\n?([\s\S]*)$/;
const ENV_VARIABLE_PATTERN = /\$([A-Z0-9_]+)/g;
const DEFAULT_LINEAR_ENDPOINT = 'https://api.linear.app/graphql';
const DEFAULT_WORKSPACE_ROOT = path.join(os.tmpdir(), 'symphony_workspaces');
const DEFAULT_MINIMAL_PROMPT = 'You are working on an issue from Linear.';

function asRecord(value: unknown): MutableRecord {
    if (value === null || typeof value !== 'object' || Array.isArray(value)) {
        return {};
    }

    return value as MutableRecord;
}

function asString(value: unknown): string | undefined {
    if (typeof value !== 'string') {
        return undefined;
    }

    return value;
}

function isRecord(value: unknown): value is MutableRecord {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function asNumber(value: unknown): number | undefined {
    if (typeof value === 'number' && Number.isFinite(value)) {
        return value;
    }

    if (typeof value === 'string' && value.trim() !== '') {
        const parsed = Number(value);
        if (Number.isFinite(parsed)) {
            return parsed;
        }
    }

    return undefined;
}

function asBoolean(value: unknown): boolean | undefined {
    if (typeof value === 'boolean') {
        return value;
    }

    if (typeof value === 'string') {
        if (value === 'true') {
            return true;
        }

        if (value === 'false') {
            return false;
        }
    }

    return undefined;
}

function asStringList(value: unknown): string[] | undefined {
    if (typeof value === 'string') {
        return value
            .split(',')
            .map((entry) => entry.trim())
            .filter((entry) => entry.length > 0);
    }

    if (Array.isArray(value)) {
        return value
            .filter((entry): entry is string => typeof entry === 'string')
            .map((entry) => entry.trim())
            .filter((entry) => entry.length > 0);
    }

    return undefined;
}

function asCommandList(value: unknown): string[] | undefined {
    if (typeof value === 'string') {
        const trimmedValue = value.trim();
        return trimmedValue.length > 0 ? [trimmedValue] : [];
    }

    if (Array.isArray(value)) {
        return value
            .filter((entry): entry is string => typeof entry === 'string')
            .map((entry) => entry.trim())
            .filter((entry) => entry.length > 0);
    }

    return undefined;
}

function asPositiveIntegerMap(value: unknown): Record<string, number> | undefined {
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        return undefined;
    }

    const normalized: Record<string, number> = {};
    for (const [rawKey, rawValue] of Object.entries(value)) {
        const parsedValue = asNumber(rawValue);
        if (parsedValue === undefined || !Number.isFinite(parsedValue) || parsedValue <= 0) {
            continue;
        }

        const normalizedKey = rawKey.trim().toLowerCase();
        if (normalizedKey.length === 0) {
            continue;
        }

        normalized[normalizedKey] = Math.floor(parsedValue);
    }

    return normalized;
}

function pickString(config: MutableRecord, keys: string[], fallback = ''): string {
    for (const key of keys) {
        const value = asString(config[key]);
        if (value !== undefined) {
            return value.trim();
        }
    }

    return fallback;
}

function pickNumber(config: MutableRecord, keys: string[], fallback: number): number {
    for (const key of keys) {
        const value = asNumber(config[key]);
        if (value !== undefined) {
            return value;
        }
    }

    return fallback;
}

function pickOptionalNumber(config: MutableRecord, keys: string[]): number | undefined {
    for (const key of keys) {
        const value = asNumber(config[key]);
        if (value !== undefined) {
            return value;
        }
    }

    return undefined;
}

function pickBoolean(config: MutableRecord, keys: string[], fallback: boolean): boolean {
    for (const key of keys) {
        const value = asBoolean(config[key]);
        if (value !== undefined) {
            return value;
        }
    }

    return fallback;
}

function pickStringArray(config: MutableRecord, keys: string[], fallback: string[]): string[] {
    for (const key of keys) {
        const value = asStringList(config[key]);
        if (value !== undefined) {
            return value;
        }
    }

    return fallback;
}

function pickCommandArray(config: MutableRecord, keys: string[], fallback: string[]): string[] {
    for (const key of keys) {
        const value = asCommandList(config[key]);
        if (value !== undefined) {
            return value;
        }
    }

    return fallback;
}

function pickPositiveIntegerMap(config: MutableRecord, keys: string[]): Record<string, number> {
    for (const key of keys) {
        const value = asPositiveIntegerMap(config[key]);
        if (value !== undefined) {
            return value;
        }
    }

    return {};
}

function sanitizePositiveInteger(value: number, fallback: number): number {
    if (!Number.isFinite(value) || value <= 0) {
        return fallback;
    }

    return Math.floor(value);
}

function sanitizePort(value: number | undefined): number | undefined {
    if (value === undefined || !Number.isFinite(value)) {
        return undefined;
    }

    const port = Math.floor(value);
    if (port < 1 || port > 65535) {
        return undefined;
    }

    return port;
}

function pickValue(config: MutableRecord, keys: string[]): unknown {
    for (const key of keys) {
        if (key in config) {
            return config[key];
        }
    }

    return undefined;
}

function normalizeCodexApprovalPolicy(value: unknown): unknown {
    if (value === undefined || value === null) {
        return undefined;
    }

    if (typeof value === 'string') {
        const trimmedValue = value.trim();
        if (trimmedValue.length === 0) {
            throw new WorkflowConfigError(
                'invalid_codex_approval_policy',
                'codex.approval_policy must be a non-empty string or object when provided.',
            );
        }

        return trimmedValue;
    }

    if (isRecord(value)) {
        return value;
    }

    throw new WorkflowConfigError(
        'invalid_codex_approval_policy',
        'codex.approval_policy must be a non-empty string or object when provided.',
    );
}

function normalizeCodexThreadSandbox(value: unknown): unknown {
    if (value === undefined || value === null) {
        return undefined;
    }

    if (typeof value !== 'string') {
        throw new WorkflowConfigError(
            'invalid_codex_thread_sandbox',
            'codex.thread_sandbox must be a non-empty string when provided.',
        );
    }

    const trimmedValue = value.trim();
    if (trimmedValue.length === 0) {
        throw new WorkflowConfigError(
            'invalid_codex_thread_sandbox',
            'codex.thread_sandbox must be a non-empty string when provided.',
        );
    }

    return trimmedValue;
}

function normalizeCodexTurnSandboxPolicy(value: unknown): unknown {
    if (value === undefined || value === null) {
        return undefined;
    }

    if (!isRecord(value)) {
        throw new WorkflowConfigError(
            'invalid_codex_turn_sandbox_policy',
            'codex.turn_sandbox_policy must be an object when provided.',
        );
    }

    return value;
}

function expandHomePath(candidatePath: string, homeDir: string): string {
    if (candidatePath === '~') {
        return homeDir;
    }

    if (candidatePath.startsWith('~/')) {
        return path.join(homeDir, candidatePath.slice(2));
    }

    return candidatePath;
}

function resolveEnvVariables(value: string, env: NodeJS.ProcessEnv): string {
    return value.replace(ENV_VARIABLE_PATTERN, (_token, variableName) => env[String(variableName)] ?? '');
}

function resolveValue(value: unknown, env: NodeJS.ProcessEnv, homeDir: string): unknown {
    if (typeof value === 'string') {
        return expandHomePath(resolveEnvVariables(value, env), homeDir);
    }

    if (Array.isArray(value)) {
        return value.map((entry) => resolveValue(entry, env, homeDir));
    }

    if (value !== null && typeof value === 'object') {
        const resolvedObject: MutableRecord = {};
        for (const [key, entry] of Object.entries(value)) {
            resolvedObject[key] = resolveValue(entry, env, homeDir);
        }

        return resolvedObject;
    }

    return value;
}

function splitFrontMatter(contents: string): {frontMatter: WorkflowFrontMatter; promptTemplate: string} {
    const trimmedContents = contents.trim();
    const match = trimmedContents.match(FRONT_MATTER_PATTERN);

    if (!match) {
        return {
            frontMatter: {
                tracker: {},
                polling: {},
                server: {},
                workspace: {},
                hooks: {},
                agent: {},
                codex: {},
            },
            promptTemplate: trimmedContents,
        };
    }

    let parsedFrontMatter: unknown;

    try {
        parsedFrontMatter = parseYaml(match[1]);
    } catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        throw new WorkflowConfigError('workflow_parse_error', `Invalid WORKFLOW.md front matter: ${message}`);
    }

    if (parsedFrontMatter !== null && (typeof parsedFrontMatter !== 'object' || Array.isArray(parsedFrontMatter))) {
        throw new WorkflowConfigError(
            'workflow_front_matter_not_a_map',
            'WORKFLOW.md front matter must be a YAML map/object.',
        );
    }

    const record = asRecord(parsedFrontMatter);
    const promptTemplate = match[2].trim();

    return {
        frontMatter: {
            tracker: asRecord(record.tracker),
            polling: asRecord(record.polling),
            server: asRecord(record.server),
            workspace: asRecord(record.workspace),
            hooks: asRecord(record.hooks),
            agent: asRecord(record.agent),
            codex: asRecord(record.codex),
        },
        promptTemplate,
    };
}

function createErrorClassFromPreflightCheck(config: WorkflowConfig): WorkflowConfigError | undefined {
    if (config.tracker.apiKey.trim() === '') {
        return new WorkflowConfigError(
            'preflight_missing_tracker_api_key',
            'Missing tracker API key. Set tracker.apiKey/api_key in WORKFLOW.md.',
        );
    }

    if (config.tracker.projectSlug.trim() === '') {
        return new WorkflowConfigError(
            'preflight_missing_tracker_project_slug',
            'Missing tracker project slug. Set tracker.projectSlug/project_slug in WORKFLOW.md.',
        );
    }

    if (config.codex.command.trim() === '') {
        return new WorkflowConfigError(
            'preflight_missing_codex_command',
            'Missing codex command. Set codex.command in WORKFLOW.md.',
        );
    }

    return undefined;
}

export function resolveWorkflowPath(args: ResolveWorkflowPathArgs): string {
    if (args.cliWorkflowPath) {
        return path.resolve(args.cwd, args.cliWorkflowPath);
    }

    return path.resolve(args.cwd, 'WORKFLOW.md');
}

export async function readWorkflowFile(workflowPath: string): Promise<string> {
    const workflowDocument = await readWorkflowDocument(workflowPath);
    return workflowDocument.contents;
}

async function readWorkflowDocument(workflowPath: string): Promise<LoadedWorkflowDocument> {
    let fileStats;

    try {
        fileStats = await stat(workflowPath);
    } catch {
        throw new WorkflowConfigError('missing_workflow_file', `Workflow file not found: ${workflowPath}`);
    }

    if (!fileStats.isFile()) {
        throw new WorkflowConfigError('missing_workflow_file', `Workflow path is not a file: ${workflowPath}`);
    }

    const contents = await readFile(workflowPath, 'utf8');

    if (contents.trim().length === 0) {
        throw new WorkflowConfigError('workflow_parse_error', `Workflow file is empty: ${workflowPath}`);
    }

    return {
        snapshot: {
            mtimeMs: fileStats.mtimeMs,
            size: fileStats.size,
        },
        contents,
    };
}

function normalizeWorkflowError(error: unknown, fallback: WorkflowErrorClass): WorkflowConfigError {
    if (error instanceof WorkflowConfigError) {
        return error;
    }

    const message = error instanceof Error ? error.message : String(error);
    return new WorkflowConfigError(fallback, message);
}

export function parseWorkflowConfig(args: ParseWorkflowConfigArgs): LoadedWorkflowConfig {
    const splitDocument = splitFrontMatter(args.contents);
    const resolvedFrontMatter = resolveValue(splitDocument.frontMatter, args.env, args.homeDir) as WorkflowFrontMatter;

    const promptTemplate =
        splitDocument.promptTemplate.length > 0 ? splitDocument.promptTemplate : DEFAULT_MINIMAL_PROMPT;

    try {
        validateStrictTemplate(promptTemplate);
    } catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        throw new WorkflowConfigError('template_parse_error', message);
    }

    const workspaceRoot = pickString(resolvedFrontMatter.workspace, ['root'], DEFAULT_WORKSPACE_ROOT);
    const trackerKind = pickString(resolvedFrontMatter.tracker, ['kind', 'provider'], 'linear');
    const trackerEndpoint = pickString(resolvedFrontMatter.tracker, ['endpoint'], DEFAULT_LINEAR_ENDPOINT);
    const hookTimeoutMs = sanitizePositiveInteger(
        pickNumber(resolvedFrontMatter.hooks, ['timeoutMs', 'timeout_ms'], 60000),
        60000,
    );
    const maxConcurrentAgents = sanitizePositiveInteger(
        pickNumber(
            resolvedFrontMatter.agent,
            ['maxConcurrentAgents', 'max_concurrent_agents', 'maxConcurrent', 'max_concurrent'],
            10,
        ),
        10,
    );
    const maxRetryBackoffMs = sanitizePositiveInteger(
        pickNumber(resolvedFrontMatter.agent, ['maxRetryBackoffMs', 'max_retry_backoff_ms'], 300000),
        300000,
    );
    const maxTurns = sanitizePositiveInteger(pickNumber(resolvedFrontMatter.agent, ['maxTurns', 'max_turns'], 20), 20);
    const readTimeoutMs = sanitizePositiveInteger(
        pickNumber(
            resolvedFrontMatter.codex,
            ['readTimeoutMs', 'read_timeout_ms', 'responseTimeoutMs', 'response_timeout_ms'],
            5000,
        ),
        5000,
    );
    const turnTimeoutMs = sanitizePositiveInteger(
        pickNumber(resolvedFrontMatter.codex, ['turnTimeoutMs', 'turn_timeout_ms'], 3600000),
        3600000,
    );
    const stallTimeoutMs = pickNumber(resolvedFrontMatter.codex, ['stallTimeoutMs', 'stall_timeout_ms'], 300000);
    const serverHost = pickString(resolvedFrontMatter.server, ['host'], '');
    const serverPort = sanitizePort(pickOptionalNumber(resolvedFrontMatter.server, ['port']));

    const config: LoadedWorkflowConfig = {
        workflowPath: args.workflowPath,
        loadedAtIso: new Date().toISOString(),
        promptTemplate,
        tracker: {
            kind: trackerKind,
            provider: trackerKind,
            endpoint: trackerEndpoint,
            apiKey: pickString(
                resolvedFrontMatter.tracker,
                ['apiKey', 'api_key'],
                args.env.LINEAR_API_KEY ?? args.env.SYMPHONY_LINEAR_API_KEY ?? '',
            ),
            projectSlug: pickString(
                resolvedFrontMatter.tracker,
                ['projectSlug', 'project_slug'],
                args.env.SYMPHONY_LINEAR_PROJECT_SLUG ?? '',
            ),
            activeStates: pickStringArray(
                resolvedFrontMatter.tracker,
                ['activeStates', 'active_states'],
                ['Todo', 'In Progress'],
            ),
            terminalStates: pickStringArray(
                resolvedFrontMatter.tracker,
                ['terminalStates', 'terminal_states'],
                ['Done', 'Closed', 'Cancelled', 'Canceled', 'Duplicate'],
            ),
            reviewStateName: pickString(
                resolvedFrontMatter.tracker,
                ['reviewStateName', 'review_state_name', 'reviewState', 'review_state'],
                'Human Review',
            ),
            mergeStateName: pickString(
                resolvedFrontMatter.tracker,
                ['mergeStateName', 'merge_state_name', 'mergeState', 'merge_state'],
                'Merging',
            ),
        },
        polling: {
            intervalMs: pickNumber(resolvedFrontMatter.polling, ['intervalMs', 'interval_ms'], 30000),
            maxCandidates: pickNumber(resolvedFrontMatter.polling, ['maxCandidates', 'max_candidates'], 20),
        },
        server: {
            host: serverHost.length > 0 ? serverHost : undefined,
            port: serverPort,
        },
        workspace: {
            root: path.isAbsolute(workspaceRoot) ? workspaceRoot : workspaceRoot,
            keepTerminalWorkspaces: pickBoolean(
                resolvedFrontMatter.workspace,
                ['keepTerminalWorkspaces', 'keep_terminal_workspaces', 'keepTerminal', 'keep_terminal'],
                false,
            ),
        },
        hooks: {
            timeoutMs: hookTimeoutMs,
            afterCreate: pickCommandArray(resolvedFrontMatter.hooks, ['afterCreate', 'after_create'], []),
            beforeRun: pickCommandArray(resolvedFrontMatter.hooks, ['beforeRun', 'before_run'], []),
            afterRun: pickCommandArray(resolvedFrontMatter.hooks, ['afterRun', 'after_run'], []),
            beforeRemove: pickCommandArray(resolvedFrontMatter.hooks, ['beforeRemove', 'before_remove'], []),
        },
        agent: {
            maxConcurrent: maxConcurrentAgents,
            maxAttempts: pickNumber(resolvedFrontMatter.agent, ['maxAttempts', 'max_attempts'], 2),
            maxTurns,
            maxRetryBackoffMs,
            maxConcurrentByState: pickPositiveIntegerMap(resolvedFrontMatter.agent, [
                'maxConcurrentAgentsByState',
                'max_concurrent_agents_by_state',
            ]),
            commitRequiredStates: pickStringArray(
                resolvedFrontMatter.agent,
                ['commitRequiredStates', 'commit_required_states'],
                ['Todo', 'In Progress', 'Rework'],
            ),
        },
        codex: {
            command: pickString(resolvedFrontMatter.codex, ['command'], args.env.SYMPHONY_CODEX_COMMAND ?? ''),
            readTimeoutMs,
            responseTimeoutMs: readTimeoutMs,
            turnTimeoutMs,
            stallTimeoutMs,
            approvalPolicy: normalizeCodexApprovalPolicy(
                pickValue(resolvedFrontMatter.codex, ['approvalPolicy', 'approval_policy']),
            ),
            publishApprovalPolicy: normalizeCodexApprovalPolicy(
                pickValue(resolvedFrontMatter.codex, ['publishApprovalPolicy', 'publish_approval_policy']),
            ),
            publishNetworkAccess: pickBoolean(
                resolvedFrontMatter.codex,
                ['publishNetworkAccess', 'publish_network_access'],
                false,
            ),
            threadSandbox: normalizeCodexThreadSandbox(
                pickValue(resolvedFrontMatter.codex, ['threadSandbox', 'thread_sandbox']),
            ),
            turnSandboxPolicy: normalizeCodexTurnSandboxPolicy(
                pickValue(resolvedFrontMatter.codex, ['turnSandboxPolicy', 'turn_sandbox_policy']),
            ),
        },
    };

    return config;
}

export function validateDispatchPreflight(config: WorkflowConfig): void {
    const preflightError = createErrorClassFromPreflightCheck(config);
    if (preflightError) {
        throw preflightError;
    }
}

export class WorkflowConfigStore {
    private readonly workflowPath: string;
    private readonly logger: Logger;
    private readonly env: NodeJS.ProcessEnv;
    private readonly homeDir: string;
    private currentConfig?: LoadedWorkflowConfig;
    private lastKnownSnapshot?: FileSnapshot;

    public constructor(args: WorkflowConfigStoreArgs) {
        this.workflowPath = args.workflowPath;
        this.logger = args.logger;
        this.env = args.env ?? process.env;
        this.homeDir = args.homeDir ?? process.env.HOME ?? '';
    }

    public async initialize(): Promise<LoadedWorkflowConfig> {
        const loadedDocument = await readWorkflowDocument(this.workflowPath);
        const config = parseWorkflowConfig({
            workflowPath: this.workflowPath,
            contents: loadedDocument.contents,
            env: this.env,
            homeDir: this.homeDir,
        });

        this.currentConfig = config;
        this.lastKnownSnapshot = loadedDocument.snapshot;

        return config;
    }

    public getCurrentConfig(): LoadedWorkflowConfig {
        if (!this.currentConfig) {
            throw new WorkflowConfigError('missing_workflow_file', 'Workflow config has not been initialized.');
        }

        return this.currentConfig;
    }

    public validateCurrentPreflight(): void {
        validateDispatchPreflight(this.getCurrentConfig());
    }

    public async reloadIfChanged(): Promise<boolean> {
        let currentSnapshot: FileSnapshot;

        try {
            currentSnapshot = await this.statWorkflowPath();
        } catch (error) {
            const normalizedError = normalizeWorkflowError(error, 'workflow_parse_error');

            if (!this.currentConfig) {
                throw normalizedError;
            }

            this.logger.error('Workflow reload failed. Keeping last known good config.', {
                workflowPath: this.workflowPath,
                errorClass: normalizedError.errorClass,
                error: normalizedError.message,
            });

            return false;
        }

        if (
            this.lastKnownSnapshot &&
            this.lastKnownSnapshot.mtimeMs === currentSnapshot.mtimeMs &&
            this.lastKnownSnapshot.size === currentSnapshot.size
        ) {
            return false;
        }

        try {
            const loadedDocument = await readWorkflowDocument(this.workflowPath);
            const reloadedConfig = parseWorkflowConfig({
                workflowPath: this.workflowPath,
                contents: loadedDocument.contents,
                env: this.env,
                homeDir: this.homeDir,
            });

            this.currentConfig = reloadedConfig;
            this.lastKnownSnapshot = loadedDocument.snapshot;

            this.logger.info('Workflow reload applied', {
                workflowPath: this.workflowPath,
                loadedAtIso: reloadedConfig.loadedAtIso,
            });

            return true;
        } catch (error) {
            const normalizedError = normalizeWorkflowError(error, 'workflow_parse_error');

            if (!this.currentConfig) {
                throw normalizedError;
            }

            this.logger.error('Workflow reload failed. Keeping last known good config.', {
                workflowPath: this.workflowPath,
                errorClass: normalizedError.errorClass,
                error: normalizedError.message,
            });

            return false;
        }
    }

    public async buildDispatchPrompt(context: PromptTemplateContext): Promise<DispatchConfigResult> {
        await this.reloadIfChanged();

        const config = this.getCurrentConfig();
        validateDispatchPreflight(config);

        let prompt: string;

        try {
            prompt = renderStrictTemplate(config.promptTemplate, context);
        } catch (error) {
            const normalizedError = normalizeWorkflowError(error, 'template_render_error');
            throw normalizedError;
        }

        return {
            config,
            prompt,
        };
    }

    private async statWorkflowPath(): Promise<FileSnapshot> {
        try {
            const snapshot = await stat(this.workflowPath);
            return {
                mtimeMs: snapshot.mtimeMs,
                size: snapshot.size,
            };
        } catch {
            throw new WorkflowConfigError('missing_workflow_file', `Workflow file not found: ${this.workflowPath}`);
        }
    }
}
