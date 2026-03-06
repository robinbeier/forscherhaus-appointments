import {exec as execCallback} from 'node:child_process';
import {access, mkdir, rm} from 'node:fs/promises';
import path from 'node:path';
import {promisify} from 'node:util';
import type {Logger} from './logger.js';

const exec = promisify(execCallback);

export type WorkspaceErrorClass = 'workspace_path_escape' | 'workspace_hook_timeout' | 'workspace_hook_failed';

export class WorkspaceManagerError extends Error {
    public readonly errorClass: WorkspaceErrorClass;
    public readonly details: Record<string, unknown>;

    public constructor(errorClass: WorkspaceErrorClass, message: string, details: Record<string, unknown> = {}) {
        super(message);
        this.name = 'WorkspaceManagerError';
        this.errorClass = errorClass;
        this.details = details;
    }
}

export type WorkspaceHookPhase = 'after_create' | 'before_run' | 'after_run' | 'before_remove';

export interface WorkspaceHooksConfig {
    timeoutMs: number;
    afterCreate: string[];
    beforeRun: string[];
    afterRun: string[];
    beforeRemove: string[];
}

export interface WorkspaceManagerConfig {
    root: string;
    hooks: WorkspaceHooksConfig;
    shellPath?: string;
    env?: NodeJS.ProcessEnv;
}

export interface WorkspaceHandle {
    key: string;
    path: string;
    created: boolean;
}

interface WorkspaceManagerArgs {
    config: WorkspaceManagerConfig;
    logger: Logger;
}

function mapPhaseToCommands(config: WorkspaceHooksConfig, phase: WorkspaceHookPhase): string[] {
    switch (phase) {
        case 'after_create':
            return config.afterCreate;
        case 'before_run':
            return config.beforeRun;
        case 'after_run':
            return config.afterRun;
        case 'before_remove':
            return config.beforeRemove;
        default:
            return [];
    }
}

function isFatalPhase(phase: WorkspaceHookPhase): boolean {
    return phase === 'before_run' || phase === 'before_remove';
}

export function sanitizeWorkspaceKey(rawKey: string): string {
    const normalized = rawKey.trim().replace(/[^A-Za-z0-9._-]/g, '_');
    if (normalized.length === 0) {
        return 'workspace';
    }

    return normalized;
}

export function ensurePathWithinRoot(root: string, candidatePath: string): string {
    const resolvedRoot = path.resolve(root);
    const resolvedCandidatePath = path.resolve(candidatePath);
    const relativePath = path.relative(resolvedRoot, resolvedCandidatePath);

    if (relativePath.startsWith('..') || path.isAbsolute(relativePath)) {
        throw new WorkspaceManagerError(
            'workspace_path_escape',
            `Resolved workspace path escapes root: ${resolvedCandidatePath}`,
            {
                root: resolvedRoot,
                candidatePath: resolvedCandidatePath,
            },
        );
    }

    return resolvedCandidatePath;
}

export class WorkspaceManager {
    private readonly logger: Logger;
    private readonly config: WorkspaceManagerConfig;
    private readonly rootPath: string;
    private readonly shellPath: string;
    private readonly env: NodeJS.ProcessEnv;

    public constructor(args: WorkspaceManagerArgs) {
        this.logger = args.logger;
        this.config = args.config;
        this.rootPath = path.resolve(args.config.root);
        this.shellPath = args.config.shellPath ?? '/bin/bash';
        this.env = args.config.env ?? process.env;
    }

    public getRootPath(): string {
        return this.rootPath;
    }

    public async prepareWorkspace(rawKey: string): Promise<WorkspaceHandle> {
        const workspaceKey = sanitizeWorkspaceKey(rawKey);
        const workspacePath = this.resolveWorkspacePath(workspaceKey);
        let created = false;

        try {
            await access(workspacePath);
        } catch {
            created = true;
            await mkdir(workspacePath, {recursive: true});
        }

        if (created) {
            await this.runHooks('after_create', workspacePath);
        }

        return {
            key: workspaceKey,
            path: workspacePath,
            created,
        };
    }

    public async runBeforeRunHooks(workspacePath: string): Promise<void> {
        await this.runHooks('before_run', ensurePathWithinRoot(this.rootPath, workspacePath));
    }

    public async runAfterRunHooks(workspacePath: string): Promise<void> {
        await this.runHooks('after_run', ensurePathWithinRoot(this.rootPath, workspacePath));
    }

    public async cleanupTerminalWorkspace(workspacePath: string): Promise<void> {
        const safeWorkspacePath = ensurePathWithinRoot(this.rootPath, workspacePath);
        await this.runHooks('before_remove', safeWorkspacePath);
        await rm(safeWorkspacePath, {recursive: true, force: true});
    }

    public resolveWorkspacePath(workspaceKey: string): string {
        const candidatePath = path.resolve(this.rootPath, workspaceKey);
        return ensurePathWithinRoot(this.rootPath, candidatePath);
    }

    private async runHooks(phase: WorkspaceHookPhase, workspacePath: string): Promise<void> {
        const commands = mapPhaseToCommands(this.config.hooks, phase);
        for (const command of commands) {
            try {
                await this.executeHookCommand(phase, command, workspacePath);
            } catch (error) {
                if (!(error instanceof WorkspaceManagerError)) {
                    throw error;
                }

                this.logger.error('Workspace hook failed', {
                    phase,
                    command,
                    workspacePath,
                    errorClass: error.errorClass,
                    error: error.message,
                });

                if (isFatalPhase(phase)) {
                    throw error;
                }
            }
        }
    }

    private async executeHookCommand(phase: WorkspaceHookPhase, command: string, workspacePath: string): Promise<void> {
        try {
            await exec(command, {
                cwd: workspacePath,
                env: this.env,
                shell: this.shellPath,
                timeout: this.config.hooks.timeoutMs,
                windowsHide: true,
                maxBuffer: 1024 * 1024,
            });

            this.logger.info('Workspace hook completed', {
                phase,
                command,
                workspacePath,
            });
        } catch (error) {
            const executionError = error as {
                code?: number;
                killed?: boolean;
                signal?: string;
                message?: string;
                stdout?: string;
                stderr?: string;
            };

            if (executionError.killed || executionError.signal === 'SIGTERM') {
                throw new WorkspaceManagerError(
                    'workspace_hook_timeout',
                    `Workspace hook timed out after ${this.config.hooks.timeoutMs}ms.`,
                    {
                        phase,
                        command,
                        timeoutMs: this.config.hooks.timeoutMs,
                    },
                );
            }

            throw new WorkspaceManagerError(
                'workspace_hook_failed',
                `Workspace hook command failed: ${executionError.message ?? 'unknown error'}`,
                {
                    phase,
                    command,
                    exitCode: executionError.code ?? null,
                    stderr: executionError.stderr ?? '',
                },
            );
        }
    }
}
