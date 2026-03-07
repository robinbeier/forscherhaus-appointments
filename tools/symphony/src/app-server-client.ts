import {spawn as spawnProcess} from 'node:child_process';
import {randomUUID} from 'node:crypto';
import type {Readable, Writable} from 'node:stream';
import type {Logger} from './logger.js';

export type AppServerErrorClass =
    | 'launch_failed'
    | 'protocol_parse_error'
    | 'response_timeout'
    | 'turn_timeout'
    | 'turn_cancelled'
    | 'approval_required'
    | 'turn_input_required'
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
      }
    | {
          type: 'trace';
          category: OrchestratorTraceCategory;
          eventType: string;
          message: string;
          details?: Record<string, unknown>;
      };

export type OrchestratorTraceCategory =
    | 'runtime'
    | 'turn'
    | 'command'
    | 'tool'
    | 'approval'
    | 'workspace'
    | 'guard'
    | 'diagnostic'
    | 'agent';

export interface AppServerClientConfig {
    command: string;
    workspacePath: string;
    readTimeoutMs?: number;
    responseTimeoutMs?: number;
    turnTimeoutMs: number;
    approvalPolicy?: unknown;
    threadSandbox?: unknown;
    turnSandboxPolicy?: unknown;
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
    dynamicToolCallHandler?: DynamicToolCallHandler;
    dynamicTools?: DynamicToolDefinition[];
    allowTrackerWriteToolApprovals?: () => boolean;
}

export interface RunTurnRequest {
    prompt: string;
    issueIdentifier: string;
    issueTitle?: string;
    attempt: number | null;
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
    inputRequiredType?: string;
    inputRequiredPayload?: Record<string, unknown>;
}

interface AppServerMessage {
    [key: string]: unknown;
}

export interface DynamicToolCallRequest {
    tool: string;
    arguments: unknown;
    callId?: string;
    threadId?: string;
    turnId?: string;
}

export interface DynamicToolCallResult {
    success: boolean;
    contentItems: Array<
        | {
              type: 'inputText';
              text: string;
          }
        | {
              type: 'inputImage';
              imageUrl: string;
          }
    >;
}

type DynamicToolCallHandler = (request: DynamicToolCallRequest) => Promise<DynamicToolCallResult | undefined>;

export interface DynamicToolDefinition {
    name: string;
    description: string;
    inputSchema?: Record<string, unknown>;
}

function createDefaultApprovalPolicy(): Record<string, unknown> {
    return {
        reject: {
            sandbox_approval: true,
            rules: true,
            mcp_elicitations: true,
        },
    };
}

function createDefaultTurnSandboxPolicy(workspacePath: string): Record<string, unknown> {
    return {
        type: 'workspaceWrite',
        writableRoots: [workspacePath],
        readOnlyAccess: {
            type: 'fullAccess',
        },
        networkAccess: false,
        excludeTmpdirEnvVar: false,
        excludeSlashTmp: false,
    };
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

function readPath(value: unknown, path: string[]): unknown {
    let current = value;
    for (const segment of path) {
        const record = asRecord(current);
        if (!record) {
            return undefined;
        }

        current = record[segment];
    }

    return current;
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

function extractStreamingText(message: AppServerMessage): string {
    const value = firstDefinedValue(message, [
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

    return typeof value === 'string' ? value : '';
}

function appendStreamingText(existing: string, delta: string): string {
    if (delta.length === 0) {
        return existing;
    }

    if (existing.length === 0) {
        return delta;
    }

    if (delta.startsWith(existing)) {
        return delta;
    }

    if (existing.startsWith(delta)) {
        return existing;
    }

    const maxOverlap = Math.min(existing.length, delta.length);
    for (let length = maxOverlap; length > 0; length -= 1) {
        if (existing.slice(-length) === delta.slice(0, length)) {
            return `${existing}${delta.slice(length)}`;
        }
    }

    return `${existing}${delta}`;
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

function isApprovalRequestMethod(method: string): boolean {
    return (
        method === 'item/commandExecution/requestApproval' ||
        method === 'item/fileChange/requestApproval' ||
        method === 'execCommandApproval' ||
        method === 'applyPatchApproval'
    );
}

function allowsSessionWideAutoApproval(approvalPolicy: unknown): boolean {
    return typeof approvalPolicy === 'string' && approvalPolicy.trim() === 'never';
}

function createToolUserInputApprovalResponse(params: Record<string, unknown>): Record<string, unknown> | undefined {
    const rawQuestions = params.questions;
    if (!Array.isArray(rawQuestions) || rawQuestions.length === 0) {
        return undefined;
    }

    const answers: Record<string, {answers: string[]}> = {};
    for (const rawQuestion of rawQuestions) {
        if (!rawQuestion || typeof rawQuestion !== 'object' || Array.isArray(rawQuestion)) {
            return undefined;
        }

        const question = rawQuestion as Record<string, unknown>;
        const questionId = typeof question.id === 'string' ? question.id.trim() : '';
        const options = Array.isArray(question.options) ? question.options : undefined;
        if (questionId.length === 0 || !options || options.length === 0) {
            return undefined;
        }

        const labels = options
            .map((option) =>
                option && typeof option === 'object' && !Array.isArray(option) && typeof option.label === 'string'
                    ? option.label.trim()
                    : '',
            )
            .filter((label) => label.length > 0);

        if (!questionId.startsWith('mcp_tool_call_approval_')) {
            return undefined;
        }

        const selectedAnswer =
            labels.find((label) => label === 'Approve this Session') ??
            labels.find((label) => label === 'Approve Once');
        if (!selectedAnswer) {
            return undefined;
        }

        answers[questionId] = {
            answers: [selectedAnswer],
        };
    }

    return {
        answers,
    };
}

function isSafeLinearCommentApproval(questionText: string): boolean {
    return /linear mcp server wants to run the tool "(save|create|update) comment"/i.test(questionText);
}

function selectSafeToolUserInputOption(
    questionId: string,
    labels: string[],
    approvalPolicy: unknown,
    questionText: string,
    allowTrackerWriteToolApprovals: boolean,
): string | undefined {
    if (questionId.startsWith('mcp_tool_call_approval_')) {
        if (allowsSessionWideAutoApproval(approvalPolicy)) {
            return (
                labels.find((label) => label === 'Approve this Session') ??
                labels.find((label) => label === 'Approve Once')
            );
        }

        if (isSafeLinearCommentApproval(questionText)) {
            if (allowTrackerWriteToolApprovals) {
                return (
                    labels.find((label) => label === 'Approve Once') ??
                    labels.find((label) => label === 'Approve this Session')
                );
            }

            return labels.find((label) => label === 'Deny') ?? labels.find((label) => label === 'Cancel');
        }

        return labels.find((label) => label === 'Deny') ?? labels.find((label) => label === 'Cancel');
    }

    return (
        labels.find((label) => label === 'Use default') ??
        labels.find((label) => label === 'Skip') ??
        labels.find((label) => label === 'Deny') ??
        labels.find((label) => label === 'Cancel')
    );
}

function createToolUserInputAutonomyResponse(
    params: Record<string, unknown>,
    approvalPolicy: unknown,
    allowTrackerWriteToolApprovals: boolean,
): Record<string, unknown> | undefined {
    const rawQuestions = params.questions;
    if (!Array.isArray(rawQuestions) || rawQuestions.length === 0) {
        return undefined;
    }

    const answers: Record<string, {answers: string[]}> = {};
    for (const rawQuestion of rawQuestions) {
        if (!rawQuestion || typeof rawQuestion !== 'object' || Array.isArray(rawQuestion)) {
            return undefined;
        }

        const question = rawQuestion as Record<string, unknown>;
        const questionId = typeof question.id === 'string' ? question.id.trim() : '';
        if (questionId.length === 0) {
            return undefined;
        }
        const questionText = typeof question.question === 'string' ? question.question.trim() : '';

        const options = Array.isArray(question.options) ? question.options : undefined;
        if (options && options.length > 0) {
            const labels = options
                .map((option) =>
                    option && typeof option === 'object' && !Array.isArray(option) && typeof option.label === 'string'
                        ? option.label.trim()
                        : '',
                )
                .filter((label) => label.length > 0);

            const selectedAnswer = selectSafeToolUserInputOption(
                questionId,
                labels,
                approvalPolicy,
                questionText,
                allowTrackerWriteToolApprovals,
            );
            if (!selectedAnswer) {
                return undefined;
            }

            answers[questionId] = {
                answers: [selectedAnswer],
            };
            continue;
        }

        answers[questionId] = {
            answers: [
                'No interactive input is available. Continue autonomously using the issue brief, workpad, and workspace state.',
            ],
        };
    }

    return {
        answers,
    };
}

function approvalDecisionForMethod(
    method: string,
    approvalPolicy: unknown,
    params: Record<string, unknown>,
): Record<string, unknown> | undefined {
    if (!allowsSessionWideAutoApproval(approvalPolicy)) {
        return undefined;
    }

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

    if (method === 'item/tool/requestUserInput') {
        return createToolUserInputApprovalResponse(params);
    }

    return undefined;
}

function createTextToolResponse(success: boolean, payload: unknown): DynamicToolCallResult {
    return {
        success,
        contentItems: [
            {
                type: 'inputText',
                text: JSON.stringify(payload) ?? 'null',
            },
        ],
    };
}

export class CodexAppServerClient {
    private readonly config: AppServerClientConfig;
    private readonly logger: Logger;
    private readonly emitEvent: (event: OrchestratorEvent) => void;
    private readonly spawnImpl: SpawnLike;
    private readonly dynamicToolCallHandler?: DynamicToolCallHandler;
    private readonly dynamicTools: DynamicToolDefinition[];
    private readonly allowTrackerWriteToolApprovals: () => boolean;
    private processHandle?: SpawnedAppServerProcess;
    private initialized = false;
    private stopRequested = false;

    public constructor(args: AppServerClientArgs) {
        this.config = {
            ...args.config,
            approvalPolicy: args.config.approvalPolicy ?? createDefaultApprovalPolicy(),
            threadSandbox: args.config.threadSandbox ?? 'workspace-write',
            turnSandboxPolicy:
                args.config.turnSandboxPolicy ?? createDefaultTurnSandboxPolicy(args.config.workspacePath),
        };
        this.logger = args.logger;
        this.emitEvent = args.emitEvent ?? (() => {});
        this.spawnImpl =
            args.spawnImpl ?? ((command, commandArgs, options) => spawnProcess(command, commandArgs, options));
        this.dynamicToolCallHandler = args.dynamicToolCallHandler;
        this.dynamicTools = [...(args.dynamicTools ?? [])];
        this.allowTrackerWriteToolApprovals = args.allowTrackerWriteToolApprovals ?? (() => true);
    }

    public async stop(): Promise<void> {
        if (!this.processHandle) {
            return;
        }

        this.stopRequested = true;
        this.disposeProcess(this.processHandle, true);
    }

    public async runTurn(request: RunTurnRequest): Promise<RunTurnResult> {
        let threadId = request.threadId ?? randomUUID();
        let turnId = request.turnId ?? randomUUID();
        let sessionId = `${threadId}-${turnId}`;
        const responseTimeoutMs =
            request.responseTimeoutMs ?? this.config.readTimeoutMs ?? this.config.responseTimeoutMs ?? 5000;
        const turnTimeoutMs = request.turnTimeoutMs ?? this.config.turnTimeoutMs;

        const processHandle = this.ensureAppServerProcess();
        this.stopRequested = false;

        return await new Promise<RunTurnResult>((resolve, reject) => {
            let stdoutBuffer = '';
            let outputText = '';
            let finished = false;
            let inputRequiredType: string | undefined;
            let inputRequiredPayload: Record<string, unknown> | undefined;
            let responseTimer: NodeJS.Timeout | undefined;
            let turnTimer: NodeJS.Timeout | undefined;
            let responseTimeoutEnabled = true;
            let requestCounter = 1;

            const emitTrace = (
                category: OrchestratorTraceCategory,
                eventType: string,
                message: string,
                details?: Record<string, unknown>,
            ): void => {
                this.emitEvent({
                    type: 'trace',
                    category,
                    eventType,
                    message,
                    details,
                });
            };

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
                this.disposeProcess(processHandle, true);
                reject(error);
            };

            const finishWithResult = (
                status: RunTurnResult['status'],
                inputRequired?: {
                    type?: string;
                    payload?: Record<string, unknown>;
                },
            ): void => {
                if (finished) {
                    return;
                }

                finished = true;
                clearTimers();
                pendingRequests.clear();
                detachListeners();
                this.stopRequested = false;
                if (status === 'input_required') {
                    inputRequiredType = inputRequired?.type;
                    inputRequiredPayload = inputRequired?.payload;
                }
                resolve({
                    status,
                    outputText,
                    threadId,
                    turnId,
                    sessionId,
                    inputRequiredType,
                    inputRequiredPayload,
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
                result: Record<string, unknown> | DynamicToolCallResult,
            ): Promise<void> => {
                await sendJsonMessage({
                    jsonrpc: '2.0',
                    id: responseId,
                    result,
                });
            };

            const resolveResponse = (message: AppServerMessage): boolean => {
                const responseId = asRequestId(message.id);
                if (responseId === undefined) {
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
                if (!method || requestId === undefined) {
                    return false;
                }

                const requestParams = asRecord(message.params) ?? {};
                const autoApprovalDecision = approvalDecisionForMethod(
                    method,
                    this.config.approvalPolicy,
                    requestParams,
                );
                if (autoApprovalDecision) {
                    emitTrace('approval', 'approval/auto_response', `Auto-responded to ${method}.`, {
                        method,
                        itemId: typeof requestParams.itemId === 'string' ? requestParams.itemId : null,
                        callId: typeof requestParams.callId === 'string' ? requestParams.callId : null,
                    });
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

                if (method === 'item/tool/requestUserInput') {
                    const nonInteractiveResponse = createToolUserInputAutonomyResponse(
                        requestParams,
                        this.config.approvalPolicy,
                        this.allowTrackerWriteToolApprovals(),
                    );
                    if (nonInteractiveResponse) {
                        emitTrace(
                            'approval',
                            'tool/requestUserInput/auto_response',
                            'Auto-answered tool input request for non-interactive run.',
                            {
                                method,
                                itemId: typeof requestParams.itemId === 'string' ? requestParams.itemId : null,
                                answers:
                                    nonInteractiveResponse.answers &&
                                    typeof nonInteractiveResponse.answers === 'object' &&
                                    !Array.isArray(nonInteractiveResponse.answers)
                                        ? nonInteractiveResponse.answers
                                        : null,
                            },
                        );
                        void sendServerRequestResponse(requestId, nonInteractiveResponse)
                            .then(() => {
                                this.logger.info(
                                    'Codex app-server tool input request auto-answered for non-interactive run.',
                                    {
                                        method,
                                        sessionId,
                                        itemId: typeof requestParams.itemId === 'string' ? requestParams.itemId : null,
                                    },
                                );
                            })
                            .catch((error) => {
                                finishWithError(
                                    new AppServerClientError(
                                        'launch_failed',
                                        `Failed to send app-server tool input response for ${method}.`,
                                        {
                                            reason: error instanceof Error ? error.message : String(error),
                                            sessionId,
                                        },
                                    ),
                                );
                            });
                        return true;
                    }
                }

                if (isApprovalRequestMethod(method)) {
                    finishWithError(
                        new AppServerClientError(
                            'approval_required',
                            'Codex app-server requested approval but the current approval policy does not allow auto-approval.',
                            {
                                sessionId,
                                payload: message,
                            },
                        ),
                    );
                    return true;
                }

                if (method === 'item/tool/requestUserInput' || method === 'mcpServer/elicitation/request') {
                    this.logger.info('Codex app-server requested interactive input/approval.', {
                        method,
                        sessionId,
                    });
                    finishWithResult('input_required', {
                        type: method,
                        payload: message,
                    });
                    return true;
                }

                if (method === 'item/tool/call') {
                    const requestParams = asRecord(message.params) ?? {};
                    const toolName = typeof requestParams.tool === 'string' ? requestParams.tool : 'unknown';
                    const dynamicToolRequest: DynamicToolCallRequest = {
                        tool: toolName,
                        arguments: requestParams.arguments,
                        callId: typeof requestParams.callId === 'string' ? requestParams.callId : undefined,
                        threadId: typeof requestParams.threadId === 'string' ? requestParams.threadId : undefined,
                        turnId: typeof requestParams.turnId === 'string' ? requestParams.turnId : undefined,
                    };

                    void Promise.resolve(this.dynamicToolCallHandler?.(dynamicToolRequest))
                        .catch((error) => {
                            this.logger.warn('Dynamic tool call handler failed. Returning tool failure.', {
                                method,
                                sessionId,
                                tool: toolName,
                                callId: dynamicToolRequest.callId ?? null,
                                error: error instanceof Error ? error.message : String(error),
                            });

                            return createTextToolResponse(false, {
                                error: 'dynamic_tool_call_failed',
                                tool: toolName,
                                message: error instanceof Error ? error.message : String(error),
                            });
                        })
                        .then((toolResult) => {
                            const effectiveToolResult =
                                toolResult ??
                                createTextToolResponse(false, {
                                    error: 'unsupported_tool_call',
                                    tool: toolName,
                                });
                            emitTrace('tool', 'tool/call/responded', `Dynamic tool response sent for ${toolName}.`, {
                                tool: toolName,
                                callId: dynamicToolRequest.callId ?? null,
                                success: effectiveToolResult.success,
                            });

                            return sendServerRequestResponse(requestId, effectiveToolResult);
                        })
                        .then(() => {
                            this.logger.info('Dynamic tool call responded with tool result.', {
                                method,
                                sessionId,
                                tool: toolName,
                                callId: dynamicToolRequest.callId ?? null,
                            });
                        })
                        .catch((error) => {
                            finishWithError(
                                new AppServerClientError(
                                    'launch_failed',
                                    `Failed to send tool response for ${toolName}.`,
                                    {
                                        reason: error instanceof Error ? error.message : String(error),
                                        sessionId,
                                    },
                                ),
                            );
                        });
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

                if (
                    type === 'item/agentMessage/delta' ||
                    type === 'codex/event/agent_message_delta' ||
                    type === 'codex/event/agent_message_content_delta'
                ) {
                    const delta = extractStreamingText(message);
                    outputText = appendStreamingText(outputText, delta);
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
                    finishWithResult('input_required', {
                        type,
                        payload: message,
                    });
                    return;
                }

                if (
                    type === 'item/tool/requestUserInput' ||
                    type === 'tool/requestUserInput' ||
                    type === 'mcpServer/elicitation/request'
                ) {
                    finishWithResult('input_required', {
                        type,
                        payload: message,
                    });
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

                const wasStopRequested = this.stopRequested;
                this.clearProcessHandle(processHandle);

                if (wasStopRequested) {
                    finishWithError(
                        new AppServerClientError('turn_cancelled', 'App-server session was cancelled.', {
                            code,
                            signal,
                            sessionId,
                        }),
                    );
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
                    if (!this.initialized) {
                        await sendRequest('initialize', {
                            clientInfo: {
                                name: 'symphony-orchestrator',
                                version: '0.1.0',
                            },
                            capabilities: {
                                experimentalApi: true,
                            },
                        });

                        await sendNotification('initialized');
                        this.initialized = true;
                    }

                    if (!request.threadId) {
                        const threadStartParams: Record<string, unknown> = {
                            cwd: this.config.workspacePath,
                        };
                        if (this.dynamicTools.length > 0) {
                            threadStartParams.dynamicTools = this.dynamicTools.map((tool) => ({
                                name: tool.name,
                                description: tool.description,
                                inputSchema: tool.inputSchema ?? {
                                    type: 'object',
                                    additionalProperties: true,
                                },
                            }));
                        }
                        if (this.config.approvalPolicy !== undefined) {
                            threadStartParams.approvalPolicy = this.config.approvalPolicy;
                        }
                        if (this.config.threadSandbox !== undefined) {
                            threadStartParams.sandbox = this.config.threadSandbox;
                        }

                        const threadStartResult = await sendRequest('thread/start', threadStartParams);
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

                    const turnStartParams: Record<string, unknown> = {
                        threadId,
                        cwd: this.config.workspacePath,
                        title: `${request.issueIdentifier}: ${request.issueTitle ?? request.issueIdentifier}`,
                        input: [
                            {
                                type: 'text',
                                text: request.prompt,
                            },
                        ],
                    };
                    if (this.config.approvalPolicy !== undefined) {
                        turnStartParams.approvalPolicy = this.config.approvalPolicy;
                    }
                    if (this.config.turnSandboxPolicy !== undefined) {
                        turnStartParams.sandboxPolicy = this.config.turnSandboxPolicy;
                    }

                    const turnStartResult = await sendRequest('turn/start', turnStartParams);
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

    private ensureAppServerProcess(): SpawnedAppServerProcess {
        if (this.processHandle) {
            return this.processHandle;
        }

        this.processHandle = this.spawnAppServer();
        this.initialized = false;
        return this.processHandle;
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

    private clearProcessHandle(processHandle: SpawnedAppServerProcess): void {
        if (this.processHandle !== processHandle) {
            return;
        }

        this.processHandle = undefined;
        this.initialized = false;
    }

    private disposeProcess(processHandle: SpawnedAppServerProcess, killProcess: boolean): void {
        if (killProcess) {
            try {
                processHandle.kill('SIGTERM');
            } catch {
                // best effort shutdown
            }
        }

        this.clearProcessHandle(processHandle);
    }
}
