import {readFile, stat} from 'node:fs/promises';
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
    workspace: MutableRecord;
    hooks: MutableRecord;
    agent: MutableRecord;
    codex: MutableRecord;
}

export type WorkflowErrorClass =
    | 'missing_workflow'
    | 'invalid_workflow'
    | 'invalid_reload_keeping_last_known_good'
    | 'preflight_missing_tracker_api_key'
    | 'preflight_missing_tracker_project_slug'
    | 'preflight_missing_codex_command'
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
    provider: string;
    apiKey: string;
    projectSlug: string;
    activeStates: string[];
}

export interface PollingConfig {
    intervalMs: number;
    maxCandidates: number;
}

export interface WorkspaceConfig {
    root: string;
    keepTerminalWorkspaces: boolean;
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
}

export interface CodexConfig {
    command: string;
    responseTimeoutMs: number;
    turnTimeoutMs: number;
}

export interface WorkflowConfig {
    workflowPath: string;
    promptTemplate: string;
    tracker: TrackerConfig;
    polling: PollingConfig;
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

function asStringArray(value: unknown): string[] | undefined {
    if (!Array.isArray(value)) {
        return undefined;
    }

    const strings = value.filter((entry): entry is string => typeof entry === 'string').map((entry) => entry.trim());
    return strings;
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
        const value = asStringArray(config[key]);
        if (value !== undefined) {
            return value;
        }
    }

    return fallback;
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
        throw new WorkflowConfigError('invalid_workflow', `Invalid WORKFLOW.md front matter: ${message}`);
    }

    const record = asRecord(parsedFrontMatter);
    const promptTemplate = match[2].trim();

    return {
        frontMatter: {
            tracker: asRecord(record.tracker),
            polling: asRecord(record.polling),
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

    return path.resolve(args.moduleDir, '../../..', 'WORKFLOW.md');
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
        throw new WorkflowConfigError('missing_workflow', `Workflow file not found: ${workflowPath}`);
    }

    if (!fileStats.isFile()) {
        throw new WorkflowConfigError('missing_workflow', `Workflow path is not a file: ${workflowPath}`);
    }

    const contents = await readFile(workflowPath, 'utf8');

    if (contents.trim().length === 0) {
        throw new WorkflowConfigError('invalid_workflow', `Workflow file is empty: ${workflowPath}`);
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

    if (splitDocument.promptTemplate.length === 0) {
        throw new WorkflowConfigError('invalid_workflow', 'WORKFLOW.md prompt body must not be empty.');
    }

    validateStrictTemplate(splitDocument.promptTemplate);

    const workspaceRootRaw = pickString(
        resolvedFrontMatter.workspace,
        ['root'],
        path.join(args.homeDir, '.symphony', 'workspaces'),
    );

    const workspaceRoot = path.isAbsolute(workspaceRootRaw)
        ? workspaceRootRaw
        : path.resolve(path.dirname(args.workflowPath), workspaceRootRaw);

    const config: LoadedWorkflowConfig = {
        workflowPath: args.workflowPath,
        loadedAtIso: new Date().toISOString(),
        promptTemplate: splitDocument.promptTemplate,
        tracker: {
            provider: pickString(resolvedFrontMatter.tracker, ['provider'], 'linear'),
            apiKey: pickString(
                resolvedFrontMatter.tracker,
                ['apiKey', 'api_key'],
                args.env.SYMPHONY_LINEAR_API_KEY ?? '',
            ),
            projectSlug: pickString(
                resolvedFrontMatter.tracker,
                ['projectSlug', 'project_slug'],
                args.env.SYMPHONY_LINEAR_PROJECT_SLUG ?? '',
            ),
            activeStates: pickStringArray(
                resolvedFrontMatter.tracker,
                ['activeStates', 'active_states'],
                ['In Progress'],
            ),
        },
        polling: {
            intervalMs: pickNumber(resolvedFrontMatter.polling, ['intervalMs', 'interval_ms'], 60000),
            maxCandidates: pickNumber(resolvedFrontMatter.polling, ['maxCandidates', 'max_candidates'], 20),
        },
        workspace: {
            root: workspaceRoot,
            keepTerminalWorkspaces: pickBoolean(
                resolvedFrontMatter.workspace,
                ['keepTerminalWorkspaces', 'keep_terminal_workspaces', 'keepTerminal', 'keep_terminal'],
                false,
            ),
        },
        hooks: {
            timeoutMs: pickNumber(resolvedFrontMatter.hooks, ['timeoutMs', 'timeout_ms'], 30000),
            afterCreate: pickStringArray(resolvedFrontMatter.hooks, ['afterCreate', 'after_create'], []),
            beforeRun: pickStringArray(resolvedFrontMatter.hooks, ['beforeRun', 'before_run'], []),
            afterRun: pickStringArray(resolvedFrontMatter.hooks, ['afterRun', 'after_run'], []),
            beforeRemove: pickStringArray(resolvedFrontMatter.hooks, ['beforeRemove', 'before_remove'], []),
        },
        agent: {
            maxConcurrent: pickNumber(resolvedFrontMatter.agent, ['maxConcurrent', 'max_concurrent'], 1),
            maxAttempts: pickNumber(resolvedFrontMatter.agent, ['maxAttempts', 'max_attempts'], 2),
        },
        codex: {
            command: pickString(resolvedFrontMatter.codex, ['command'], args.env.SYMPHONY_CODEX_COMMAND ?? ''),
            responseTimeoutMs: pickNumber(
                resolvedFrontMatter.codex,
                ['responseTimeoutMs', 'response_timeout_ms'],
                120000,
            ),
            turnTimeoutMs: pickNumber(resolvedFrontMatter.codex, ['turnTimeoutMs', 'turn_timeout_ms'], 900000),
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
            throw new WorkflowConfigError('missing_workflow', 'Workflow config has not been initialized.');
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
            const normalizedError = normalizeWorkflowError(error, 'invalid_reload_keeping_last_known_good');

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
            const normalizedError = normalizeWorkflowError(error, 'invalid_reload_keeping_last_known_good');

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
            throw new WorkflowConfigError('missing_workflow', `Workflow file not found: ${this.workflowPath}`);
        }
    }
}
