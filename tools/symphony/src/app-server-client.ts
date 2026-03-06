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
    [key: string]: unknown;
}

function messageType(message: AppServerMessage): string {
    return String(message.type ?? message.method ?? '');
}

function asRecord(value: unknown): Record<string, unknown> | undefined {
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        return undefined;
    }

    return value as Record<string, unknown>;
}

type JsonRpcRequestId = string | number;

function asRequestId(value: unknown): JsonRpcRequestId | undefined {
    if (typeof value === 'string' || typeof value === 'number') {
        return value;
    }

    return undefined;
}

function requestIdKey(id: JsonRpcRequestId): string {
    return `${typeof id}:${String(id)}`;
}

function candidateRequestIdKeys(id: JsonRpcRequestId): string[] {
    const keys = [requestIdKey(id)];

    if (typeof id === 'string') {
        const trimmed = id.trim();
        if (/^-?\d+$/.test(trimmed)) {
            const numericValue = Number(trimmed);
            if (Number.isSafeInteger(numericValue)) {
                const numericKey = requestIdKey(numericValue);
                if (!keys.includes(numericKey)) {
                    keys.push(numericKey);
                }
            }
        }
    } else if (typeof id === 'number' && Number.isSafeInteger(id)) {
        const stringKey = requestIdKey(String(id));
        if (!keys.includes(stringKey)) {
            keys.push(stringKey);
        }
    }

    return keys;
}

function approvalDecisionForMethod(method: string): Record<string, unknown> | undefined {
    if (method === 'item/commandExecution/requestApproval' || method === 'item/fileChange/requestApproval') {
        return {
            decision: 'acceptForSession',
        };
    }

    if (method === 'execCommandApproval' || method === 'applyPatchApproval') {
        return {
            decision: 'approved_for_session',
        };
    }

    return undefined;
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
        let threadId = request.threadId ?? randomUUID();
        let turnId = request.turnId ?? randomUUID();
        let sessionId = `${threadId}-${turnId}`;
        const responseTimeoutMs = request.responseTimeoutMs ?? this.config.responseTimeoutMs;
        const turnTimeoutMs = request.turnTimeoutMs ?? this.config.turnTimeoutMs;

        const processHandle = this.spawnAppServer();

        return await new Promise<RunTurnResult>((resolve, reject) => {
            let stdoutBuffer = '';
            let outputText = '';
            let finished = false;
            let responseTimer: NodeJS.Timeout | undefined;
            let turnTimer: NodeJS.Timeout | undefined;
            let responseTimeoutEnabled = true;
            let requestCounter = 1;

            type PendingRequest = {
                method: string;
                resolve: (result: Record<string, unknown>) => void;
                reject: (error: AppServerClientError) => void;
            };
            const pendingRequests = new Map<string, PendingRequest>();

            const updateSessionIds = (nextThreadId?: string, nextTurnId?: string): void => {
                if (nextThreadId) {
                    threadId = nextThreadId;
                }

                if (nextTurnId) {
                    turnId = nextTurnId;
                }

                sessionId = `${threadId}-${turnId}`;
            };

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

            const disableResponseTimeout = (): void => {
                responseTimeoutEnabled = false;
                if (responseTimer) {
                    clearTimeout(responseTimer);
                    responseTimer = undefined;
                }
            };

            const rejectPendingRequests = (error: AppServerClientError): void => {
                for (const pendingRequest of pendingRequests.values()) {
                    pendingRequest.reject(error);
                }

                pendingRequests.clear();
            };

            const finishWithError = (error: AppServerClientError): void => {
                if (finished) {
                    return;
                }

                finished = true;
                clearTimers();
                rejectPendingRequests(error);
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
                pendingRequests.clear();
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
                if (!responseTimeoutEnabled || responseTimeoutMs <= 0) {
                    return;
                }

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

            const sendJsonMessage = async (payload: Record<string, unknown>): Promise<void> => {
                await this.sendMessage(processHandle, payload);
            };

            const sendRequest = async (
                method: string,
                params?: Record<string, unknown>,
            ): Promise<Record<string, unknown>> => {
                const requestId: JsonRpcRequestId = requestCounter++;
                const payload: Record<string, unknown> = {
                    jsonrpc: '2.0',
                    id: requestId,
                    method,
                };

                if (params) {
                    payload.params = params;
                }

                await sendJsonMessage(payload);

                return await new Promise<Record<string, unknown>>((resolveRequest, rejectRequest) => {
                    pendingRequests.set(requestIdKey(requestId), {
                        method,
                        resolve: resolveRequest,
                        reject: rejectRequest,
                    });
                });
            };

            const sendNotification = async (method: string, params?: Record<string, unknown>): Promise<void> => {
                const payload: Record<string, unknown> = {
                    jsonrpc: '2.0',
                    method,
                };

                if (params) {
                    payload.params = params;
                }

                await sendJsonMessage(payload);
            };

            const sendServerRequestResponse = async (
                responseId: JsonRpcRequestId,
                result: Record<string, unknown>,
            ): Promise<void> => {
                await sendJsonMessage({
                    jsonrpc: '2.0',
                    id: responseId,
                    result,
                });
            };

            const resolveResponse = (message: AppServerMessage): boolean => {
                const responseId = asRequestId(message.id);
                if (!responseId) {
                    return false;
                }

                let matchedKey: string | undefined;
                let pendingRequest: PendingRequest | undefined;
                for (const candidateKey of candidateRequestIdKeys(responseId)) {
                    const candidateRequest = pendingRequests.get(candidateKey);
                    if (candidateRequest) {
                        matchedKey = candidateKey;
                        pendingRequest = candidateRequest;
                        break;
                    }
                }

                if (!pendingRequest || !matchedKey) {
                    return false;
                }

                pendingRequests.delete(matchedKey);

                const responseError = asRecord(message.error);
                if (responseError) {
                    const errorMessage = typeof responseError.message === 'string' ? responseError.message : 'Unknown';
                    const errorClass = pendingRequest.method === 'turn/start' ? 'turn_failed' : 'launch_failed';
                    pendingRequest.reject(
                        new AppServerClientError(
                            errorClass,
                            `App-server request ${pendingRequest.method} failed: ${errorMessage}`,
                            {
                                sessionId,
                                payload: message,
                            },
                        ),
                    );
                    return true;
                }

                if (pendingRequest.method === 'turn/start') {
                    disableResponseTimeout();
                }

                pendingRequest.resolve(asRecord(message.result) ?? {});
                return true;
            };

            const handleV2ServerRequest = (message: AppServerMessage): boolean => {
                const method = typeof message.method === 'string' ? message.method : '';
                const requestId = asRequestId(message.id);
                if (!method || !requestId) {
                    return false;
                }

                const autoApprovalDecision = approvalDecisionForMethod(method);
                if (autoApprovalDecision) {
                    const requestParams = asRecord(message.params) ?? {};
                    void sendServerRequestResponse(requestId, autoApprovalDecision)
                        .then(() => {
                            this.logger.info('Codex app-server approval request auto-accepted.', {
                                method,
                                sessionId,
                                itemId: typeof requestParams.itemId === 'string' ? requestParams.itemId : null,
                                turnId:
                                    typeof requestParams.turnId === 'string'
                                        ? requestParams.turnId
                                        : typeof requestParams.callId === 'string'
                                          ? requestParams.callId
                                          : null,
                            });
                        })
                        .catch((error) => {
                            finishWithError(
                                new AppServerClientError(
                                    'launch_failed',
                                    `Failed to send app-server approval response for ${method}.`,
                                    {
                                        reason: error instanceof Error ? error.message : String(error),
                                        sessionId,
                                    },
                                ),
                            );
                        });
                    return true;
                }

                if (
                    method === 'item/tool/requestUserInput' ||
                    method === 'item/tool/call' ||
                    method === 'mcpServer/elicitation/request'
                ) {
                    this.logger.info('Codex app-server requested interactive input/approval.', {
                        method,
                        sessionId,
                    });
                    finishWithResult('input_required');
                    return true;
                }

                return false;
            };

            const handleAppServerMessage = (message: AppServerMessage): void => {
                resetResponseTimer();
                this.emitEvent({
                    type: 'raw_event',
                    payload: message,
                });

                if (resolveResponse(message)) {
                    return;
                }

                if (handleV2ServerRequest(message)) {
                    return;
                }

                const type = messageType(message);
                if (type === 'response.output_text.delta' || type === 'response_text_delta') {
                    const delta = typeof message.delta === 'string' ? message.delta : String(message.delta ?? '');
                    outputText += delta;
                    return;
                }

                if (type === 'item/agentMessage/delta') {
                    const params = asRecord(message.params);
                    const delta = typeof params?.delta === 'string' ? params.delta : '';
                    outputText += delta;
                    return;
                }

                if (type === 'codex/event/agent_message_content_delta') {
                    const params = asRecord(message.params);
                    const codexEvent = asRecord(params?.msg);
                    const delta = typeof codexEvent?.delta === 'string' ? codexEvent.delta : '';
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

                if (type === 'account/rateLimits/updated') {
                    const params = asRecord(message.params);
                    this.emitEvent({
                        type: 'rate_limit',
                        payload: asRecord(params?.rateLimits) ?? params ?? message,
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

                if (type === 'thread/tokenUsage/updated') {
                    const params = asRecord(message.params);
                    this.emitEvent({
                        type: 'token_usage',
                        payload: asRecord(params?.tokenUsage) ?? params ?? message,
                    });
                    return;
                }

                if (type === 'codex/event/token_count') {
                    const params = asRecord(message.params);
                    const codexEvent = asRecord(params?.msg);
                    const usageInfo = asRecord(codexEvent?.info);
                    if (usageInfo) {
                        this.emitEvent({
                            type: 'token_usage',
                            payload: usageInfo,
                        });
                    }

                    const rateLimits = asRecord(codexEvent?.rate_limits);
                    if (rateLimits) {
                        this.emitEvent({
                            type: 'rate_limit',
                            payload: rateLimits,
                        });
                    }
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

                if (type === 'error') {
                    const params = asRecord(message.params);
                    const turnError = asRecord(params?.error);
                    finishWithError(
                        new AppServerClientError(
                            'turn_failed',
                            `App-server emitted error notification: ${
                                typeof turnError?.message === 'string' ? turnError.message : 'Unknown'
                            }`,
                            {
                                sessionId,
                                payload: message,
                            },
                        ),
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
                    return;
                }

                if (type === 'turn/completed') {
                    const params = asRecord(message.params);
                    const turn = asRecord(params?.turn);
                    const turnStatus = typeof turn?.status === 'string' ? turn.status : '';
                    const completedTurnId = typeof turn?.id === 'string' ? turn.id : undefined;

                    if (completedTurnId) {
                        updateSessionIds(undefined, completedTurnId);
                    }

                    if (turnStatus === 'completed') {
                        finishWithResult('completed');
                        return;
                    }

                    if (turnStatus === 'failed' || turnStatus === 'interrupted') {
                        finishWithError(
                            new AppServerClientError(
                                'turn_failed',
                                `App-server turn ended with status ${turnStatus}.`,
                                {
                                    sessionId,
                                    payload: message,
                                },
                            ),
                        );
                    }
                    return;
                }

                if (type === 'turn/started') {
                    const params = asRecord(message.params);
                    const turn = asRecord(params?.turn);
                    const startedTurnId = typeof turn?.id === 'string' ? turn.id : undefined;
                    if (startedTurnId) {
                        updateSessionIds(undefined, startedTurnId);
                    }
                    return;
                }

                if (type === 'codex/event/task_complete') {
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
                    await sendRequest('initialize', {
                        clientInfo: {
                            name: 'symphony-pilot',
                            version: '0.1.0',
                        },
                    });

                    await sendNotification('initialized');

                    if (!request.threadId) {
                        const threadStartResult = await sendRequest('thread/start', {
                            cwd: this.config.workspacePath,
                        });
                        const thread = asRecord(threadStartResult.thread);
                        const startedThreadId = typeof thread?.id === 'string' ? thread.id : undefined;
                        if (!startedThreadId) {
                            throw new AppServerClientError(
                                'launch_failed',
                                'App-server thread/start response missing thread id.',
                                {payload: threadStartResult},
                            );
                        }
                        updateSessionIds(startedThreadId, undefined);
                    }

                    const turnStartResult = await sendRequest('turn/start', {
                        threadId,
                        cwd: this.config.workspacePath,
                        input: [
                            {
                                type: 'text',
                                text: request.prompt,
                            },
                        ],
                    });
                    const startedTurn = asRecord(turnStartResult.turn);
                    const startedTurnId = typeof startedTurn?.id === 'string' ? startedTurn.id : undefined;
                    if (startedTurnId) {
                        updateSessionIds(undefined, startedTurnId);
                    }

                    this.emitEvent({
                        type: 'session',
                        threadId,
                        turnId,
                        sessionId,
                    });
                })
                .catch((error) => {
                    finishWithError(
                        error instanceof AppServerClientError
                            ? error
                            : new AppServerClientError('launch_failed', 'Failed to send app-server handshake.', {
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

        const spawnEnv: NodeJS.ProcessEnv = {
            ...(this.config.env ?? process.env),
        };

        // npm --prefix leaks this var into child processes and triggers noisy nvm warnings.
        delete spawnEnv.npm_config_prefix;

        return this.spawnImpl('bash', ['-lc', this.config.command], {
            cwd: this.config.workspacePath,
            env: spawnEnv,
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
