import {spawn as spawnProcess} from 'node:child_process';
import {randomUUID} from 'node:crypto';
import type {Readable, Writable} from 'node:stream';
import type {Logger} from './logger.js';

export type AppServerErrorClass =
    | 'launch_failed'
    | 'protocol_parse_error'
    | 'response_timeout'
    | 'turn_timeout'
    | 'turn_failed';

export class AppServerClientError extends Error {
    public readonly errorClass: AppServerErrorClass;
    public readonly details: Record<string, unknown>;

    public constructor(errorClass: AppServerErrorClass, message: string, details: Record<string, unknown> = {}) {
        super(message);
        this.name = 'AppServerClientError';
        this.errorClass = errorClass;
        this.details = details;
    }
}

export type OrchestratorEvent =
    | {
          type: 'session';
          threadId: string;
          turnId: string;
          sessionId: string;
      }
    | {
          type: 'rate_limit';
          payload: Record<string, unknown>;
      }
    | {
          type: 'token_usage';
          payload: Record<string, unknown>;
      }
    | {
          type: 'diagnostic';
          stream: 'stderr' | 'stdout';
          message: string;
      }
    | {
          type: 'raw_event';
          payload: Record<string, unknown>;
      };

export interface AppServerClientConfig {
    command: string;
    workspacePath: string;
    responseTimeoutMs: number;
    turnTimeoutMs: number;
    env?: NodeJS.ProcessEnv;
}

interface SpawnedAppServerProcess {
    stdin: Writable;
    stdout: Readable;
    stderr: Readable;
    kill(signal?: NodeJS.Signals): boolean;
    on(event: 'error', listener: (error: Error) => void): this;
    on(event: 'exit', listener: (code: number | null, signal: NodeJS.Signals | null) => void): this;
    off(event: 'error', listener: (error: Error) => void): this;
    off(event: 'exit', listener: (code: number | null, signal: NodeJS.Signals | null) => void): this;
}

type SpawnLike = (command: string, args: string[], options: Record<string, unknown>) => SpawnedAppServerProcess;

interface AppServerClientArgs {
    config: AppServerClientConfig;
    logger: Logger;
    emitEvent?: (event: OrchestratorEvent) => void;
    spawnImpl?: SpawnLike;
}

export interface RunTurnRequest {
    prompt: string;
    issueIdentifier: string;
    attempt: number;
    threadId?: string;
    turnId?: string;
    responseTimeoutMs?: number;
    turnTimeoutMs?: number;
}

export interface RunTurnResult {
    status: 'completed' | 'input_required';
    outputText: string;
    threadId: string;
    turnId: string;
    sessionId: string;
}

interface AppServerMessage {
    type: string;
    [key: string]: unknown;
}

function messageType(message: AppServerMessage): string {
    return String(message.type ?? '');
}

export class CodexAppServerClient {
    private readonly config: AppServerClientConfig;
    private readonly logger: Logger;
    private readonly emitEvent: (event: OrchestratorEvent) => void;
    private readonly spawnImpl: SpawnLike;

    public constructor(args: AppServerClientArgs) {
        this.config = args.config;
        this.logger = args.logger;
        this.emitEvent = args.emitEvent ?? (() => {});
        this.spawnImpl =
            args.spawnImpl ?? ((command, commandArgs, options) => spawnProcess(command, commandArgs, options));
    }

    public async runTurn(request: RunTurnRequest): Promise<RunTurnResult> {
        const threadId = request.threadId ?? randomUUID();
        const turnId = request.turnId ?? randomUUID();
        const sessionId = `${threadId}-${turnId}`;
        const responseTimeoutMs = request.responseTimeoutMs ?? this.config.responseTimeoutMs;
        const turnTimeoutMs = request.turnTimeoutMs ?? this.config.turnTimeoutMs;

        const processHandle = this.spawnAppServer();

        this.emitEvent({
            type: 'session',
            threadId,
            turnId,
            sessionId,
        });

        return await new Promise<RunTurnResult>((resolve, reject) => {
            let stdoutBuffer = '';
            let outputText = '';
            let finished = false;
            let responseTimer: NodeJS.Timeout | undefined;
            let turnTimer: NodeJS.Timeout | undefined;

            const clearTimers = (): void => {
                if (responseTimer) {
                    clearTimeout(responseTimer);
                    responseTimer = undefined;
                }

                if (turnTimer) {
                    clearTimeout(turnTimer);
                    turnTimer = undefined;
                }
            };

            const finishWithError = (error: AppServerClientError): void => {
                if (finished) {
                    return;
                }

                finished = true;
                clearTimers();
                detachListeners();
                this.terminateAppServer(processHandle);
                reject(error);
            };

            const finishWithResult = (status: RunTurnResult['status']): void => {
                if (finished) {
                    return;
                }

                finished = true;
                clearTimers();
                detachListeners();
                this.terminateAppServer(processHandle);
                resolve({
                    status,
                    outputText,
                    threadId,
                    turnId,
                    sessionId,
                });
            };

            const resetResponseTimer = (): void => {
                if (responseTimer) {
                    clearTimeout(responseTimer);
                }

                responseTimer = setTimeout(() => {
                    finishWithError(
                        new AppServerClientError(
                            'response_timeout',
                            'No app-server response within response timeout.',
                            {
                                responseTimeoutMs,
                                sessionId,
                            },
                        ),
                    );
                }, responseTimeoutMs);
            };

            const handleAppServerMessage = (message: AppServerMessage): void => {
                resetResponseTimer();
                this.emitEvent({
                    type: 'raw_event',
                    payload: message,
                });

                const type = messageType(message);

                if (type === 'response.output_text.delta' || type === 'response_text_delta') {
                    const delta = typeof message.delta === 'string' ? message.delta : String(message.delta ?? '');
                    outputText += delta;
                    return;
                }

                if (type === 'rate_limits.updated' || type === 'rate_limits') {
                    this.emitEvent({
                        type: 'rate_limit',
                        payload: (message.rate_limits as Record<string, unknown>) ?? message,
                    });
                    return;
                }

                if (type === 'session.updated' || type === 'tokens.used') {
                    this.emitEvent({
                        type: 'token_usage',
                        payload:
                            (message.usage as Record<string, unknown>) ??
                            (message.tokens as Record<string, unknown>) ??
                            message,
                    });
                    return;
                }

                if (type === 'turn.input_required' || type === 'turn_input_required') {
                    finishWithResult('input_required');
                    return;
                }

                if (type === 'turn.failed' || type === 'turn_failed') {
                    finishWithError(
                        new AppServerClientError('turn_failed', 'App-server reported failed turn.', {
                            sessionId,
                            payload: message,
                        }),
                    );
                    return;
                }

                if (
                    type === 'turn.completed' ||
                    type === 'turn_completed' ||
                    type === 'response.completed' ||
                    type === 'response_completed'
                ) {
                    finishWithResult('completed');
                }
            };

            const onStdoutData = (chunk: Buffer | string): void => {
                stdoutBuffer += chunk.toString();

                while (true) {
                    const newlineIndex = stdoutBuffer.indexOf('\n');
                    if (newlineIndex < 0) {
                        break;
                    }

                    const line = stdoutBuffer.slice(0, newlineIndex).trim();
                    stdoutBuffer = stdoutBuffer.slice(newlineIndex + 1);

                    if (line.length === 0) {
                        continue;
                    }

                    let parsedMessage: AppServerMessage;
                    try {
                        parsedMessage = JSON.parse(line) as AppServerMessage;
                    } catch {
                        this.emitEvent({
                            type: 'diagnostic',
                            stream: 'stdout',
                            message: line,
                        });
                        continue;
                    }

                    handleAppServerMessage(parsedMessage);
                }
            };

            const onStderrData = (chunk: Buffer | string): void => {
                const message = chunk.toString().trim();
                if (message.length === 0) {
                    return;
                }

                this.emitEvent({
                    type: 'diagnostic',
                    stream: 'stderr',
                    message,
                });
            };

            const onProcessError = (error: Error): void => {
                finishWithError(
                    new AppServerClientError('launch_failed', 'Failed to launch app-server process.', {
                        reason: error.message,
                    }),
                );
            };

            const onProcessExit = (code: number | null, signal: NodeJS.Signals | null): void => {
                if (finished) {
                    return;
                }

                finishWithError(
                    new AppServerClientError('turn_failed', 'App-server process exited before turn completion.', {
                        code,
                        signal,
                        sessionId,
                    }),
                );
            };

            const detachListeners = (): void => {
                processHandle.stdout.off('data', onStdoutData);
                processHandle.stderr.off('data', onStderrData);
                processHandle.off('error', onProcessError);
                processHandle.off('exit', onProcessExit);
            };

            processHandle.stdout.on('data', onStdoutData);
            processHandle.stderr.on('data', onStderrData);
            processHandle.on('error', onProcessError);
            processHandle.on('exit', onProcessExit);

            turnTimer = setTimeout(() => {
                finishWithError(
                    new AppServerClientError('turn_timeout', 'App-server turn exceeded turn timeout.', {
                        turnTimeoutMs,
                        sessionId,
                    }),
                );
            }, turnTimeoutMs);

            resetResponseTimer();

            Promise.resolve()
                .then(async () => {
                    await this.sendMessage(processHandle, {
                        type: 'initialize',
                        issue_identifier: request.issueIdentifier,
                        attempt: request.attempt,
                    });
                    await this.sendMessage(processHandle, {
                        type: 'initialized',
                    });
                    await this.sendMessage(processHandle, {
                        type: 'thread/start',
                        thread_id: threadId,
                    });
                    await this.sendMessage(processHandle, {
                        type: 'turn/start',
                        thread_id: threadId,
                        turn_id: turnId,
                        input: {
                            prompt: request.prompt,
                        },
                    });
                })
                .catch((error) => {
                    finishWithError(
                        new AppServerClientError('launch_failed', 'Failed to send app-server handshake.', {
                            reason: error instanceof Error ? error.message : String(error),
                        }),
                    );
                });
        });
    }

    private spawnAppServer(): SpawnedAppServerProcess {
        this.logger.info('Launching Codex app-server process', {
            command: this.config.command,
            workspacePath: this.config.workspacePath,
        });

        return this.spawnImpl('bash', ['-lc', this.config.command], {
            cwd: this.config.workspacePath,
            env: this.config.env ?? process.env,
            stdio: 'pipe',
            windowsHide: true,
        });
    }

    private async sendMessage(processHandle: SpawnedAppServerProcess, payload: Record<string, unknown>): Promise<void> {
        const line = `${JSON.stringify(payload)}\n`;

        await new Promise<void>((resolve, reject) => {
            const writable = processHandle.stdin;
            const accepted = writable.write(line, (error) => {
                if (error) {
                    reject(error);
                    return;
                }

                resolve();
            });

            if (!accepted) {
                writable.once('drain', () => resolve());
            }
        });
    }

    private terminateAppServer(processHandle: SpawnedAppServerProcess): void {
        try {
            processHandle.kill('SIGTERM');
        } catch {
            // best effort shutdown
        }
    }
}
