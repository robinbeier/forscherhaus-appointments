import assert from 'node:assert/strict';
import {EventEmitter} from 'node:events';
import {PassThrough} from 'node:stream';
import test from 'node:test';
import type {Logger} from './logger.js';
import {AppServerClientError, CodexAppServerClient, type OrchestratorEvent} from './app-server-client.js';

class FakeAppServerProcess extends EventEmitter {
    public readonly stdin = new PassThrough();
    public readonly stdout = new PassThrough();
    public readonly stderr = new PassThrough();
    public killed = false;

    public kill(signal?: NodeJS.Signals): boolean {
        this.killed = true;
        this.emit('exit', 0, signal ?? null);
        return true;
    }
}

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

async function emitHandshake(fakeProcess: FakeAppServerProcess, threadId: string, turnId: string): Promise<void> {
    fakeProcess.stdout.write('{"id":1,"result":{"userAgent":"codex-test"}}\n');
    await new Promise<void>((resolve) => setTimeout(resolve, 1));
    fakeProcess.stdout.write(`{"id":2,"result":{"thread":{"id":"${threadId}"}}}\n`);
    await new Promise<void>((resolve) => setTimeout(resolve, 1));
    fakeProcess.stdout.write(
        `{"id":3,"result":{"turn":{"id":"${turnId}","status":"inProgress","items":[],"error":null}}}\n`,
    );
}

test('runTurn sends handshake in required order and handles partial-line buffering', async () => {
    const fakeProcess = new FakeAppServerProcess();
    const events: OrchestratorEvent[] = [];
    let stdinBuffer = '';
    fakeProcess.stdin.on('data', (chunk) => {
        stdinBuffer += chunk.toString();
    });

    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex app-server',
            workspacePath: '/tmp',
            responseTimeoutMs: 300,
            turnTimeoutMs: 1000,
        },
        emitEvent: (event) => events.push(event),
        spawnImpl: () => fakeProcess,
    });

    const turnPromise = client.runTurn({
        prompt: 'Test prompt',
        issueIdentifier: 'ROB-12',
        attempt: 1,
    });

    await new Promise<void>((resolve) => setTimeout(resolve, 10));
    await emitHandshake(fakeProcess, 'thread-123', 'turn-456');
    fakeProcess.stdout.write(
        '{"method":"item/agentMessage/delta","params":{"threadId":"thread-123","turnId":"turn-456","itemId":"item-1","delta":"Hel',
    );
    fakeProcess.stdout.write('lo"}}\n');
    fakeProcess.stdout.write(
        '{"method":"item/agentMessage/delta","params":{"threadId":"thread-123","turnId":"turn-456","itemId":"item-1","delta":" World"}}\n',
    );
    fakeProcess.stdout.write(
        '{"method":"turn/completed","params":{"threadId":"thread-123","turn":{"id":"turn-456","status":"completed","items":[],"error":null}}}\n',
    );

    const result = await turnPromise;
    assert.equal(result.status, 'completed');
    assert.equal(result.outputText, 'Hello World');
    assert.equal(result.sessionId, 'thread-123-turn-456');

    const sentMessages = stdinBuffer
        .trim()
        .split('\n')
        .map((line) => JSON.parse(line));
    assert.deepEqual(
        sentMessages.map((message) => message.method),
        ['initialize', 'initialized', 'thread/start', 'turn/start'],
    );

    const sessionEvent = events.find((event) => event.type === 'session') as Extract<
        OrchestratorEvent,
        {type: 'session'}
    >;
    assert.ok(sessionEvent);
    assert.equal(sessionEvent.sessionId, 'thread-123-turn-456');
});

test('runTurn accumulates upstream wrapped agent message deltas', async () => {
    const fakeProcess = new FakeAppServerProcess();
    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex app-server',
            workspacePath: '/tmp',
            responseTimeoutMs: 300,
            turnTimeoutMs: 1000,
        },
        spawnImpl: () => fakeProcess,
    });

    const turnPromise = client.runTurn({
        prompt: 'Wrapped delta test',
        issueIdentifier: 'ROB-12',
        attempt: null,
    });

    await new Promise<void>((resolve) => setTimeout(resolve, 10));
    await emitHandshake(fakeProcess, 'thread-123', 'turn-456');
    fakeProcess.stdout.write(
        '{"method":"codex/event/agent_message_delta","params":{"msg":{"payload":{"delta":"Hello"}}}}\n',
    );
    fakeProcess.stdout.write(
        '{"method":"codex/event/agent_message_content_delta","params":{"msg":{"content":"Hello world"}}}\n',
    );
    fakeProcess.stdout.write(
        '{"method":"turn/completed","params":{"threadId":"thread-123","turn":{"id":"turn-456","status":"completed","items":[],"error":null}}}\n',
    );

    const result = await turnPromise;
    assert.equal(result.status, 'completed');
    assert.equal(result.outputText, 'Hello world');
});

test('runTurn applies safe default approval and sandbox settings when omitted', async () => {
    const fakeProcess = new FakeAppServerProcess();
    let stdinBuffer = '';
    fakeProcess.stdin.on('data', (chunk) => {
        stdinBuffer += chunk.toString();
    });

    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex app-server',
            workspacePath: '/tmp/symphony-workspaces/ROB-12',
            responseTimeoutMs: 300,
            turnTimeoutMs: 1000,
        },
        spawnImpl: () => fakeProcess,
    });

    const turnPromise = client.runTurn({
        prompt: 'Default policy test',
        issueIdentifier: 'ROB-12',
        attempt: null,
    });

    await new Promise<void>((resolve) => setTimeout(resolve, 10));
    await emitHandshake(fakeProcess, 'thread-123', 'turn-456');
    fakeProcess.stdout.write(
        '{"method":"turn/completed","params":{"threadId":"thread-123","turn":{"id":"turn-456","status":"completed","items":[],"error":null}}}\n',
    );

    const result = await turnPromise;
    assert.equal(result.status, 'completed');

    const sentMessages = stdinBuffer
        .trim()
        .split('\n')
        .map((line) => JSON.parse(line));

    assert.deepEqual(sentMessages[2].params.approvalPolicy, {
        reject: {
            sandbox_approval: true,
            rules: true,
            mcp_elicitations: true,
        },
    });
    assert.equal(sentMessages[2].params.sandbox, 'workspace-write');
    assert.deepEqual(sentMessages[3].params.approvalPolicy, {
        reject: {
            sandbox_approval: true,
            rules: true,
            mcp_elicitations: true,
        },
    });
    assert.deepEqual(sentMessages[3].params.sandboxPolicy, {
        type: 'workspaceWrite',
        writableRoots: ['/tmp/symphony-workspaces/ROB-12'],
        readOnlyAccess: {
            type: 'fullAccess',
        },
        networkAccess: false,
        excludeTmpdirEnvVar: false,
        excludeSlashTmp: false,
    });
});

test('runTurn fails with approval_required when command approval is requested under safe defaults', async () => {
    const fakeProcess = new FakeAppServerProcess();
    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex app-server',
            workspacePath: '/tmp/symphony-workspaces/ROB-12',
            responseTimeoutMs: 300,
            turnTimeoutMs: 1000,
        },
        spawnImpl: () => fakeProcess,
    });

    const turnPromise = client.runTurn({
        prompt: 'Approval test',
        issueIdentifier: 'ROB-12',
        attempt: null,
    });

    await new Promise<void>((resolve) => setTimeout(resolve, 10));
    await emitHandshake(fakeProcess, 'thread-approval', 'turn-approval');
    fakeProcess.stdout.write(
        '{"id":"approval-1","method":"item/commandExecution/requestApproval","params":{"command":"gh pr view","cwd":"/tmp","reason":"need approval"}}\n',
    );

    await assert.rejects(
        () => turnPromise,
        (error) =>
            error instanceof AppServerClientError &&
            error.errorClass === 'approval_required' &&
            error.details.payload !== undefined,
    );
});

test('runTurn auto-approves command execution approval requests only when approval policy is never', async () => {
    const fakeProcess = new FakeAppServerProcess();
    const events: OrchestratorEvent[] = [];
    let stdinBuffer = '';
    fakeProcess.stdin.on('data', (chunk) => {
        stdinBuffer += chunk.toString();
    });

    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex app-server',
            workspacePath: '/tmp',
            responseTimeoutMs: 300,
            turnTimeoutMs: 1000,
            approvalPolicy: 'never',
        },
        emitEvent: (event) => events.push(event),
        spawnImpl: () => fakeProcess,
    });

    const turnPromise = client.runTurn({
        prompt: 'Auto-approve',
        issueIdentifier: 'ROB-12',
        attempt: 1,
    });

    await new Promise<void>((resolve) => setTimeout(resolve, 10));
    await emitHandshake(fakeProcess, 'thread-123', 'turn-456');
    fakeProcess.stdout.write(
        '{"id":99,"method":"item/commandExecution/requestApproval","params":{"command":"gh pr view","cwd":"/tmp","reason":"need approval"}}\n',
    );
    fakeProcess.stdout.write(
        '{"method":"turn/completed","params":{"threadId":"thread-123","turn":{"id":"turn-456","status":"completed","items":[],"error":null}}}\n',
    );

    const result = await turnPromise;
    assert.equal(result.status, 'completed');

    const sentMessages = stdinBuffer
        .trim()
        .split('\n')
        .map((line) => JSON.parse(line));
    const approvalResponse = sentMessages.find((message) => message.id === 99);
    assert.deepEqual(approvalResponse?.result, {
        decision: 'acceptForSession',
    });
    const traceEvent = events.find(
        (event) => event.type === 'trace' && event.eventType === 'approval/auto_response',
    ) as Extract<OrchestratorEvent, {type: 'trace'}>;
    assert.ok(traceEvent);
    assert.equal(traceEvent.category, 'approval');
    assert.equal(traceEvent.details?.method, 'item/commandExecution/requestApproval');
});

test('runTurn auto-approves MCP tool approval prompts when approval policy is never', async () => {
    const fakeProcess = new FakeAppServerProcess();
    let stdinBuffer = '';
    fakeProcess.stdin.on('data', (chunk) => {
        stdinBuffer += chunk.toString();
    });

    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex app-server',
            workspacePath: '/tmp',
            responseTimeoutMs: 300,
            turnTimeoutMs: 1000,
            approvalPolicy: 'never',
        },
        spawnImpl: () => fakeProcess,
    });

    const turnPromise = client.runTurn({
        prompt: 'Tool approval',
        issueIdentifier: 'ROB-12',
        attempt: 1,
    });

    await new Promise<void>((resolve) => setTimeout(resolve, 10));
    await emitHandshake(fakeProcess, 'thread-123', 'turn-456');
    fakeProcess.stdout.write(
        '{"id":110,"method":"item/tool/requestUserInput","params":{"itemId":"call-717","questions":[{"header":"Approve app tool call?","id":"mcp_tool_call_approval_call-717","isOther":false,"isSecret":false,"options":[{"description":"Run the tool and continue.","label":"Approve Once"},{"description":"Run the tool and remember this choice for this session.","label":"Approve this Session"},{"description":"Decline this tool call and continue.","label":"Deny"},{"description":"Cancel this tool call","label":"Cancel"}],"question":"Allow this action?"}],"threadId":"thread-123","turnId":"turn-456"}}\n',
    );
    fakeProcess.stdout.write(
        '{"method":"turn/completed","params":{"threadId":"thread-123","turn":{"id":"turn-456","status":"completed","items":[],"error":null}}}\n',
    );

    const result = await turnPromise;
    assert.equal(result.status, 'completed');

    const sentMessages = stdinBuffer
        .trim()
        .split('\n')
        .map((line) => JSON.parse(line));
    const approvalResponse = sentMessages.find((message) => message.id === 110);
    assert.deepEqual(approvalResponse?.result, {
        answers: {
            'mcp_tool_call_approval_call-717': {
                answers: ['Approve this Session'],
            },
        },
    });
});

test('runTurn auto-denies MCP tool approval prompts for non-interactive runs when approval policy is not never', async () => {
    const fakeProcess = new FakeAppServerProcess();
    let stdinBuffer = '';
    fakeProcess.stdin.on('data', (chunk) => {
        stdinBuffer += chunk.toString();
    });

    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex app-server',
            workspacePath: '/tmp',
            responseTimeoutMs: 300,
            turnTimeoutMs: 1000,
            approvalPolicy: 'on-failure',
        },
        spawnImpl: () => fakeProcess,
    });

    const turnPromise = client.runTurn({
        prompt: 'Tool approval deny fallback',
        issueIdentifier: 'ROB-12',
        attempt: 1,
    });

    await new Promise<void>((resolve) => setTimeout(resolve, 10));
    await emitHandshake(fakeProcess, 'thread-123', 'turn-456');
    fakeProcess.stdout.write(
        '{"id":111,"method":"item/tool/requestUserInput","params":{"itemId":"call-718","questions":[{"header":"Approve app tool call?","id":"mcp_tool_call_approval_call-718","isOther":false,"isSecret":false,"options":[{"description":"Run the tool and continue.","label":"Approve Once"},{"description":"Decline this tool call and continue.","label":"Deny"},{"description":"Cancel this tool call","label":"Cancel"}],"question":"Allow this action?"}],"threadId":"thread-123","turnId":"turn-456"}}\n',
    );
    fakeProcess.stdout.write(
        '{"method":"turn/completed","params":{"threadId":"thread-123","turn":{"id":"turn-456","status":"completed","items":[],"error":null}}}\n',
    );

    const result = await turnPromise;
    assert.equal(result.status, 'completed');

    const sentMessages = stdinBuffer
        .trim()
        .split('\n')
        .map((line) => JSON.parse(line));
    const approvalResponse = sentMessages.find((message) => message.id === 111);
    assert.deepEqual(approvalResponse?.result, {
        answers: {
            'mcp_tool_call_approval_call-718': {
                answers: ['Deny'],
            },
        },
    });
});

test('runTurn handles zero-valued JSON-RPC request ids for tool approval prompts', async () => {
    const fakeProcess = new FakeAppServerProcess();
    let stdinBuffer = '';
    fakeProcess.stdin.on('data', (chunk) => {
        stdinBuffer += chunk.toString();
    });

    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex app-server',
            workspacePath: '/tmp',
            responseTimeoutMs: 300,
            turnTimeoutMs: 1000,
            approvalPolicy: 'on-failure',
        },
        spawnImpl: () => fakeProcess,
    });

    const turnPromise = client.runTurn({
        prompt: 'Tool approval zero request id',
        issueIdentifier: 'ROB-12',
        attempt: 1,
    });

    await new Promise<void>((resolve) => setTimeout(resolve, 10));
    await emitHandshake(fakeProcess, 'thread-123', 'turn-456');
    fakeProcess.stdout.write(
        '{"id":0,"method":"item/tool/requestUserInput","params":{"itemId":"call-000","questions":[{"header":"Approve app tool call?","id":"mcp_tool_call_approval_call-000","isOther":false,"isSecret":false,"options":[{"description":"Run the tool and continue.","label":"Approve Once"},{"description":"Decline this tool call and continue.","label":"Deny"},{"description":"Cancel this tool call","label":"Cancel"}],"question":"Allow this action?"}],"threadId":"thread-123","turnId":"turn-456"}}\n',
    );
    fakeProcess.stdout.write(
        '{"method":"turn/completed","params":{"threadId":"thread-123","turn":{"id":"turn-456","status":"completed","items":[],"error":null}}}\n',
    );

    const result = await turnPromise;
    assert.equal(result.status, 'completed');

    const sentMessages = stdinBuffer
        .trim()
        .split('\n')
        .map((line) => JSON.parse(line));
    const approvalResponse = sentMessages.find((message) => message.id === 0);
    assert.deepEqual(approvalResponse?.result, {
        answers: {
            'mcp_tool_call_approval_call-000': {
                answers: ['Deny'],
            },
        },
    });
});

test('runTurn auto-approves Linear save-comment prompts once for non-interactive runs', async () => {
    const fakeProcess = new FakeAppServerProcess();
    let stdinBuffer = '';
    fakeProcess.stdin.on('data', (chunk) => {
        stdinBuffer += chunk.toString();
    });

    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex app-server',
            workspacePath: '/tmp',
            responseTimeoutMs: 300,
            turnTimeoutMs: 1000,
            approvalPolicy: 'on-failure',
        },
        spawnImpl: () => fakeProcess,
    });

    const turnPromise = client.runTurn({
        prompt: 'Safe save-comment approval',
        issueIdentifier: 'ROB-12',
        attempt: 1,
    });

    await new Promise<void>((resolve) => setTimeout(resolve, 10));
    await emitHandshake(fakeProcess, 'thread-123', 'turn-456');
    fakeProcess.stdout.write(
        '{"id":0,"method":"item/tool/requestUserInput","params":{"itemId":"call-comment-1","questions":[{"header":"Approve app tool call?","id":"mcp_tool_call_approval_call-comment-1","isOther":false,"isSecret":false,"options":[{"description":"Run the tool and continue.","label":"Approve Once"},{"description":"Run the tool and remember this choice for this session.","label":"Approve this Session"},{"description":"Decline this tool call and continue.","label":"Deny"},{"description":"Cancel this tool call","label":"Cancel"}],"question":"The linear MCP server wants to run the tool \\"Save comment\\", which may modify or delete data. Allow this action?"}],"threadId":"thread-123","turnId":"turn-456"}}\n',
    );
    fakeProcess.stdout.write(
        '{"method":"turn/completed","params":{"threadId":"thread-123","turn":{"id":"turn-456","status":"completed","items":[],"error":null}}}\n',
    );

    const result = await turnPromise;
    assert.equal(result.status, 'completed');

    const sentMessages = stdinBuffer
        .trim()
        .split('\n')
        .map((line) => JSON.parse(line));
    const approvalResponse = sentMessages.find((message) => message.id === 0);
    assert.deepEqual(approvalResponse?.result, {
        answers: {
            'mcp_tool_call_approval_call-comment-1': {
                answers: ['Approve Once'],
            },
        },
    });
});

test('runTurn denies Linear save-comment prompts until tracker writes are allowed for the turn', async () => {
    const fakeProcess = new FakeAppServerProcess();
    let stdinBuffer = '';
    fakeProcess.stdin.on('data', (chunk) => {
        stdinBuffer += chunk.toString();
    });

    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex app-server',
            workspacePath: '/tmp',
            responseTimeoutMs: 300,
            turnTimeoutMs: 1000,
            approvalPolicy: 'on-failure',
        },
        spawnImpl: () => fakeProcess,
        allowTrackerWriteToolApprovals: () => false,
    });

    const turnPromise = client.runTurn({
        prompt: 'Blocked save-comment approval',
        issueIdentifier: 'ROB-12',
        attempt: 1,
    });

    await new Promise<void>((resolve) => setTimeout(resolve, 10));
    await emitHandshake(fakeProcess, 'thread-123', 'turn-456');
    fakeProcess.stdout.write(
        '{"id":0,"method":"item/tool/requestUserInput","params":{"itemId":"call-comment-2","questions":[{"header":"Approve app tool call?","id":"mcp_tool_call_approval_call-comment-2","isOther":false,"isSecret":false,"options":[{"description":"Run the tool and continue.","label":"Approve Once"},{"description":"Run the tool and remember this choice for this session.","label":"Approve this Session"},{"description":"Decline this tool call and continue.","label":"Deny"},{"description":"Cancel this tool call","label":"Cancel"}],"question":"The linear MCP server wants to run the tool \\"Save comment\\", which may modify or delete data. Allow this action?"}],"threadId":"thread-123","turnId":"turn-456"}}\n',
    );
    fakeProcess.stdout.write(
        '{"method":"turn/completed","params":{"threadId":"thread-123","turn":{"id":"turn-456","status":"completed","items":[],"error":null}}}\n',
    );

    const result = await turnPromise;
    assert.equal(result.status, 'completed');

    const sentMessages = stdinBuffer
        .trim()
        .split('\n')
        .map((line) => JSON.parse(line));
    const approvalResponse = sentMessages.find((message) => message.id === 0);
    assert.deepEqual(approvalResponse?.result, {
        answers: {
            'mcp_tool_call_approval_call-comment-2': {
                answers: ['Deny'],
            },
        },
    });
});

test('runTurn auto-answers freeform tool input requests for non-interactive runs', async () => {
    const fakeProcess = new FakeAppServerProcess();
    const events: OrchestratorEvent[] = [];
    let stdinBuffer = '';
    fakeProcess.stdin.on('data', (chunk) => {
        stdinBuffer += chunk.toString();
    });

    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex app-server',
            workspacePath: '/tmp',
            responseTimeoutMs: 300,
            turnTimeoutMs: 1000,
        },
        emitEvent: (event) => events.push(event),
        spawnImpl: () => fakeProcess,
    });

    const turnPromise = client.runTurn({
        prompt: 'Freeform tool input fallback',
        issueIdentifier: 'ROB-12',
        attempt: 1,
    });

    await new Promise<void>((resolve) => setTimeout(resolve, 10));
    await emitHandshake(fakeProcess, 'thread-123', 'turn-456');
    fakeProcess.stdout.write(
        '{"id":112,"method":"item/tool/requestUserInput","params":{"itemId":"call-719","questions":[{"header":"Provide context","id":"freeform-719","isOther":false,"isSecret":false,"options":null,"question":"What comment should I post back to the issue?"}],"threadId":"thread-123","turnId":"turn-456"}}\n',
    );
    fakeProcess.stdout.write(
        '{"method":"turn/completed","params":{"threadId":"thread-123","turn":{"id":"turn-456","status":"completed","items":[],"error":null}}}\n',
    );

    const result = await turnPromise;
    assert.equal(result.status, 'completed');

    const sentMessages = stdinBuffer
        .trim()
        .split('\n')
        .map((line) => JSON.parse(line));
    const response = sentMessages.find((message) => message.id === 112);
    assert.deepEqual(response?.result, {
        answers: {
            'freeform-719': {
                answers: [
                    'No interactive input is available. Continue autonomously using the issue brief, workpad, and workspace state.',
                ],
            },
        },
    });
    const traceEvent = events.find(
        (event) => event.type === 'trace' && event.eventType === 'tool/requestUserInput/auto_response',
    ) as Extract<OrchestratorEvent, {type: 'trace'}>;
    assert.ok(traceEvent);
    assert.equal(traceEvent.category, 'approval');
});

test('runTurn accepts string-typed JSON-RPC response ids', async () => {
    const fakeProcess = new FakeAppServerProcess();
    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex app-server',
            workspacePath: '/tmp',
            responseTimeoutMs: 300,
            turnTimeoutMs: 1000,
        },
        spawnImpl: () => fakeProcess,
    });

    const turnPromise = client.runTurn({
        prompt: 'String id response test',
        issueIdentifier: 'ROB-12',
        attempt: 1,
    });

    await new Promise<void>((resolve) => setTimeout(resolve, 10));
    fakeProcess.stdout.write('{"id":"1","result":{"userAgent":"codex-test"}}\n');
    await new Promise<void>((resolve) => setTimeout(resolve, 1));
    fakeProcess.stdout.write('{"id":"2","result":{"thread":{"id":"thread-123"}}}\n');
    await new Promise<void>((resolve) => setTimeout(resolve, 1));
    fakeProcess.stdout.write(
        '{"id":"3","result":{"turn":{"id":"turn-456","status":"inProgress","items":[],"error":null}}}\n',
    );
    fakeProcess.stdout.write(
        '{"method":"turn/completed","params":{"threadId":"thread-123","turn":{"id":"turn-456","status":"completed","items":[],"error":null}}}\n',
    );

    const result = await turnPromise;
    assert.equal(result.status, 'completed');
});

test('turn.input_required resolves without hanging', async () => {
    const fakeProcess = new FakeAppServerProcess();
    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex app-server',
            workspacePath: '/tmp',
            responseTimeoutMs: 300,
            turnTimeoutMs: 1000,
        },
        spawnImpl: () => fakeProcess,
    });

    const turnPromise = client.runTurn({
        prompt: 'Need input',
        issueIdentifier: 'ROB-12',
        attempt: 1,
    });

    await new Promise<void>((resolve) => setTimeout(resolve, 10));
    await emitHandshake(fakeProcess, 'thread-123', 'turn-456');
    fakeProcess.stdout.write('{"id":"server-1","method":"item/tool/requestUserInput","params":{}}\n');

    const result = await turnPromise;
    assert.equal(result.status, 'input_required');
    assert.equal(result.inputRequiredType, 'item/tool/requestUserInput');
    assert.equal(result.inputRequiredPayload?.method, 'item/tool/requestUserInput');
});

test('notification-style item/tool/requestUserInput resolves without hanging', async () => {
    const fakeProcess = new FakeAppServerProcess();
    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex app-server',
            workspacePath: '/tmp',
            responseTimeoutMs: 300,
            turnTimeoutMs: 1000,
        },
        spawnImpl: () => fakeProcess,
    });

    const turnPromise = client.runTurn({
        prompt: 'Need input via notification',
        issueIdentifier: 'ROB-12',
        attempt: 1,
    });

    await new Promise<void>((resolve) => setTimeout(resolve, 10));
    await emitHandshake(fakeProcess, 'thread-123', 'turn-456');
    fakeProcess.stdout.write('{"method":"item/tool/requestUserInput","params":{"question":"Should I continue?"}}\n');

    const result = await turnPromise;
    assert.equal(result.status, 'input_required');
    assert.equal(result.inputRequiredType, 'item/tool/requestUserInput');
    assert.equal(result.inputRequiredPayload?.method, 'item/tool/requestUserInput');
    assert.deepEqual(result.inputRequiredPayload?.params, {
        question: 'Should I continue?',
    });
});

test('unsupported dynamic tool calls return schema-correct tool failure and turn continues', async () => {
    const fakeProcess = new FakeAppServerProcess();
    let stdinBuffer = '';
    fakeProcess.stdin.on('data', (chunk) => {
        stdinBuffer += chunk.toString();
    });

    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex app-server',
            workspacePath: '/tmp',
            responseTimeoutMs: 300,
            turnTimeoutMs: 1000,
        },
        spawnImpl: () => fakeProcess,
    });

    const turnPromise = client.runTurn({
        prompt: 'Dynamic tool fallback',
        issueIdentifier: 'ROB-12',
        attempt: null,
    });

    await new Promise<void>((resolve) => setTimeout(resolve, 10));
    await emitHandshake(fakeProcess, 'thread-123', 'turn-456');
    fakeProcess.stdout.write(
        '{"id":"tool-1","method":"item/tool/call","params":{"tool":"unknown_tool","arguments":{"x":1},"callId":"call-1","threadId":"thread-123","turnId":"turn-456"}}\n',
    );
    fakeProcess.stdout.write(
        '{"method":"turn/completed","params":{"threadId":"thread-123","turn":{"id":"turn-456","status":"completed","items":[],"error":null}}}\n',
    );

    const result = await turnPromise;
    assert.equal(result.status, 'completed');

    const sentMessages = stdinBuffer
        .trim()
        .split('\n')
        .map((line) => JSON.parse(line));
    const toolResponse = sentMessages.find((message) => message.id === 'tool-1');
    assert.deepEqual(toolResponse?.result, {
        success: false,
        contentItems: [
            {
                type: 'inputText',
                text: JSON.stringify({
                    error: 'unsupported_tool_call',
                    tool: 'unknown_tool',
                }),
            },
        ],
    });
});

test('dynamic tool handler result is forwarded and turn continues', async () => {
    const fakeProcess = new FakeAppServerProcess();
    let stdinBuffer = '';
    fakeProcess.stdin.on('data', (chunk) => {
        stdinBuffer += chunk.toString();
    });

    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex app-server',
            workspacePath: '/tmp',
            responseTimeoutMs: 300,
            turnTimeoutMs: 1000,
        },
        spawnImpl: () => fakeProcess,
        dynamicToolCallHandler: async (request) => {
            assert.equal(request.tool, 'linear_graphql');
            return {
                success: true,
                contentItems: [
                    {
                        type: 'inputText',
                        text: JSON.stringify({data: {viewer: {id: 'viewer-1'}}}),
                    },
                ],
            };
        },
    });

    const turnPromise = client.runTurn({
        prompt: 'Dynamic tool success',
        issueIdentifier: 'ROB-12',
        attempt: null,
    });

    await new Promise<void>((resolve) => setTimeout(resolve, 10));
    await emitHandshake(fakeProcess, 'thread-123', 'turn-456');
    fakeProcess.stdout.write(
        '{"id":"tool-2","method":"item/tool/call","params":{"tool":"linear_graphql","arguments":{"query":"query Viewer { viewer { id } }"},"callId":"call-2","threadId":"thread-123","turnId":"turn-456"}}\n',
    );
    fakeProcess.stdout.write(
        '{"method":"turn/completed","params":{"threadId":"thread-123","turn":{"id":"turn-456","status":"completed","items":[],"error":null}}}\n',
    );

    const result = await turnPromise;
    assert.equal(result.status, 'completed');

    const sentMessages = stdinBuffer
        .trim()
        .split('\n')
        .map((line) => JSON.parse(line));
    const toolResponse = sentMessages.find((message) => message.id === 'tool-2');
    assert.deepEqual(toolResponse?.result, {
        success: true,
        contentItems: [
            {
                type: 'inputText',
                text: JSON.stringify({data: {viewer: {id: 'viewer-1'}}}),
            },
        ],
    });
});

test('dynamic tools are advertised during initialize and thread start', async () => {
    const fakeProcess = new FakeAppServerProcess();
    let stdinBuffer = '';
    fakeProcess.stdin.on('data', (chunk) => {
        stdinBuffer += chunk.toString();
    });

    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex app-server',
            workspacePath: '/tmp',
            responseTimeoutMs: 300,
            turnTimeoutMs: 1000,
        },
        spawnImpl: () => fakeProcess,
        dynamicTools: [
            {
                name: 'linear_graphql',
                description: 'Execute raw Linear GraphQL queries or mutations.',
                inputSchema: {
                    type: 'object',
                    additionalProperties: false,
                    required: ['query'],
                    properties: {
                        query: {
                            type: 'string',
                        },
                        variables: {
                            type: ['object', 'null'],
                            additionalProperties: true,
                        },
                    },
                },
            },
        ],
    });

    const turnPromise = client.runTurn({
        prompt: 'Dynamic tool advertisement',
        issueIdentifier: 'ROB-12',
        attempt: null,
    });

    await new Promise<void>((resolve) => setTimeout(resolve, 10));
    await emitHandshake(fakeProcess, 'thread-123', 'turn-456');
    fakeProcess.stdout.write(
        '{"method":"turn/completed","params":{"threadId":"thread-123","turn":{"id":"turn-456","status":"completed","items":[],"error":null}}}\n',
    );

    const result = await turnPromise;
    assert.equal(result.status, 'completed');

    const sentMessages = stdinBuffer
        .trim()
        .split('\n')
        .map((line) => JSON.parse(line));
    assert.deepEqual(sentMessages[0].params.capabilities, {experimentalApi: true});
    assert.equal(sentMessages[2].params.developerInstructions, undefined);
    assert.deepEqual(sentMessages[2].params.dynamicTools, [
        {
            name: 'linear_graphql',
            description: 'Execute raw Linear GraphQL queries or mutations.',
            inputSchema: {
                type: 'object',
                additionalProperties: false,
                required: ['query'],
                properties: {
                    query: {
                        type: 'string',
                    },
                    variables: {
                        type: ['object', 'null'],
                        additionalProperties: true,
                    },
                },
            },
        },
    ]);
});

test('thread start omits developer instructions when no dynamic tools are advertised', async () => {
    const fakeProcess = new FakeAppServerProcess();
    let stdinBuffer = '';
    fakeProcess.stdin.on('data', (chunk) => {
        stdinBuffer += chunk.toString();
    });

    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex app-server',
            workspacePath: '/tmp',
            responseTimeoutMs: 300,
            turnTimeoutMs: 1000,
        },
        spawnImpl: () => fakeProcess,
    });

    const turnPromise = client.runTurn({
        prompt: 'Turn discipline instructions',
        issueIdentifier: 'ROB-12',
        attempt: null,
    });

    await new Promise<void>((resolve) => setTimeout(resolve, 10));
    await emitHandshake(fakeProcess, 'thread-123', 'turn-456');
    fakeProcess.stdout.write(
        '{"method":"turn/completed","params":{"threadId":"thread-123","turn":{"id":"turn-456","status":"completed","items":[],"error":null}}}\n',
    );

    const result = await turnPromise;
    assert.equal(result.status, 'completed');

    const sentMessages = stdinBuffer
        .trim()
        .split('\n')
        .map((line) => JSON.parse(line));
    assert.deepEqual(sentMessages[0].params.capabilities, {experimentalApi: true});
    assert.equal(sentMessages[2].params.developerInstructions, undefined);
    assert.equal(sentMessages[2].params.dynamicTools, undefined);
});

test('runTurn maps response timeout when no response arrives', async () => {
    const fakeProcess = new FakeAppServerProcess();
    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex app-server',
            workspacePath: '/tmp',
            responseTimeoutMs: 20,
            turnTimeoutMs: 1000,
        },
        spawnImpl: () => fakeProcess,
    });

    await assert.rejects(
        () =>
            client.runTurn({
                prompt: 'Timeout test',
                issueIdentifier: 'ROB-12',
                attempt: 1,
            }),
        (error) => error instanceof AppServerClientError && error.errorClass === 'response_timeout',
    );
});

test('runTurn does not trigger response timeout after turn start handshake', async () => {
    const fakeProcess = new FakeAppServerProcess();
    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex app-server',
            workspacePath: '/tmp',
            responseTimeoutMs: 20,
            turnTimeoutMs: 150,
        },
        spawnImpl: () => fakeProcess,
    });

    const turnPromise = client.runTurn({
        prompt: 'Handshake timeout guard test',
        issueIdentifier: 'ROB-12',
        attempt: 1,
    });

    await new Promise<void>((resolve) => setTimeout(resolve, 10));
    await emitHandshake(fakeProcess, 'thread-123', 'turn-456');
    await new Promise<void>((resolve) => setTimeout(resolve, 60));
    fakeProcess.stdout.write(
        '{"method":"turn/completed","params":{"threadId":"thread-123","turn":{"id":"turn-456","status":"completed","items":[],"error":null}}}\n',
    );

    const result = await turnPromise;
    assert.equal(result.status, 'completed');
});

test('runTurn maps turn timeout when turn exceeds limit', async () => {
    const fakeProcess = new FakeAppServerProcess();
    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex app-server',
            workspacePath: '/tmp',
            responseTimeoutMs: 2000,
            turnTimeoutMs: 40,
        },
        spawnImpl: () => fakeProcess,
    });

    const turnPromise = client.runTurn({
        prompt: 'Turn timeout test',
        issueIdentifier: 'ROB-12',
        attempt: 1,
    });

    const rejection = assert.rejects(
        () => turnPromise,
        (error) => error instanceof AppServerClientError && error.errorClass === 'turn_timeout',
    );

    await new Promise<void>((resolve) => setTimeout(resolve, 10));
    await emitHandshake(fakeProcess, 'thread-123', 'turn-456');
    await rejection;
});

test('runTurn reuses the app-server process and thread across continuation turns', async () => {
    const fakeProcess = new FakeAppServerProcess();
    let stdinBuffer = '';
    fakeProcess.stdin.on('data', (chunk) => {
        stdinBuffer += chunk.toString();
    });

    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex app-server',
            workspacePath: '/tmp',
            responseTimeoutMs: 300,
            turnTimeoutMs: 1000,
        },
        spawnImpl: () => fakeProcess,
    });

    const firstTurnPromise = client.runTurn({
        prompt: 'First turn',
        issueIdentifier: 'ROB-12',
        issueTitle: 'Reuse session',
        attempt: 1,
    });

    await new Promise<void>((resolve) => setTimeout(resolve, 10));
    await emitHandshake(fakeProcess, 'thread-123', 'turn-1');
    fakeProcess.stdout.write(
        '{"method":"turn/completed","params":{"threadId":"thread-123","turn":{"id":"turn-1","status":"completed","items":[],"error":null}}}\n',
    );

    const firstResult = await firstTurnPromise;

    const secondTurnPromise = client.runTurn({
        prompt: 'Second turn',
        issueIdentifier: 'ROB-12',
        issueTitle: 'Reuse session',
        attempt: 2,
        threadId: firstResult.threadId,
    });

    await new Promise<void>((resolve) => setTimeout(resolve, 10));
    fakeProcess.stdout.write(
        '{"id":1,"result":{"turn":{"id":"turn-2","status":"inProgress","items":[],"error":null}}}\n',
    );
    fakeProcess.stdout.write(
        '{"method":"turn/completed","params":{"threadId":"thread-123","turn":{"id":"turn-2","status":"completed","items":[],"error":null}}}\n',
    );

    const secondResult = await secondTurnPromise;
    await client.stop();

    assert.equal(secondResult.threadId, 'thread-123');
    assert.equal(fakeProcess.killed, true);

    const sentMessages = stdinBuffer
        .trim()
        .split('\n')
        .map((line) => JSON.parse(line));
    assert.deepEqual(
        sentMessages.map((message) => message.method),
        ['initialize', 'initialized', 'thread/start', 'turn/start', 'turn/start'],
    );
    assert.equal(sentMessages[3].params.threadId, 'thread-123');
    assert.equal(sentMessages[4].params.threadId, 'thread-123');
});

test('stop cancels an in-flight turn with turn_cancelled', async () => {
    const fakeProcess = new FakeAppServerProcess();
    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex app-server',
            workspacePath: '/tmp',
            responseTimeoutMs: 300,
            turnTimeoutMs: 1000,
        },
        spawnImpl: () => fakeProcess,
    });

    const turnPromise = client.runTurn({
        prompt: 'Cancel me',
        issueIdentifier: 'ROB-12',
        attempt: 1,
    });
    const rejection = assert.rejects(
        () => turnPromise,
        (error) => error instanceof AppServerClientError && error.errorClass === 'turn_cancelled',
    );

    await new Promise<void>((resolve) => setTimeout(resolve, 10));
    await emitHandshake(fakeProcess, 'thread-123', 'turn-1');
    await client.stop();
    await rejection;
    assert.equal(fakeProcess.killed, true);
});

test('runTurn emits rate-limit, token-usage and diagnostics events', async () => {
    const fakeProcess = new FakeAppServerProcess();
    const events: OrchestratorEvent[] = [];

    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex app-server',
            workspacePath: '/tmp',
            responseTimeoutMs: 300,
            turnTimeoutMs: 1000,
        },
        spawnImpl: () => fakeProcess,
        emitEvent: (event) => events.push(event),
    });

    const turnPromise = client.runTurn({
        prompt: 'Event mapping test',
        issueIdentifier: 'ROB-12',
        attempt: 1,
    });

    await new Promise<void>((resolve) => setTimeout(resolve, 10));
    await emitHandshake(fakeProcess, 'thread-123', 'turn-456');
    fakeProcess.stderr.write('diagnostic line\n');
    fakeProcess.stdout.write('{"method":"account/rateLimits/updated","params":{"rateLimits":{"remaining":17}}}\n');
    fakeProcess.stdout.write(
        '{"method":"thread/tokenUsage/updated","params":{"threadId":"thread-123","turnId":"turn-456","tokenUsage":{"total":{"totalTokens":17}}}}\n',
    );
    fakeProcess.stdout.write(
        '{"method":"turn/completed","params":{"threadId":"thread-123","turn":{"id":"turn-456","status":"completed","items":[],"error":null}}}\n',
    );

    const result = await turnPromise;
    assert.equal(result.status, 'completed');

    assert.ok(events.some((event) => event.type === 'rate_limit'));
    assert.ok(events.some((event) => event.type === 'token_usage'));
    assert.ok(events.some((event) => event.type === 'diagnostic'));
});

test('turn.failed is mapped to turn_failed error', async () => {
    const fakeProcess = new FakeAppServerProcess();
    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex app-server',
            workspacePath: '/tmp',
            responseTimeoutMs: 300,
            turnTimeoutMs: 1000,
        },
        spawnImpl: () => fakeProcess,
    });

    const turnPromise = client.runTurn({
        prompt: 'Failure mapping',
        issueIdentifier: 'ROB-12',
        attempt: 1,
    });

    await new Promise<void>((resolve) => setTimeout(resolve, 10));
    await emitHandshake(fakeProcess, 'thread-123', 'turn-456');
    fakeProcess.stdout.write(
        '{"method":"error","params":{"threadId":"thread-123","turnId":"turn-456","willRetry":false,"error":{"message":"boom"}}}\n',
    );

    await assert.rejects(
        () => turnPromise,
        (error) => error instanceof AppServerClientError && error.errorClass === 'turn_failed',
    );
});

test('command and file approval requests are auto-accepted only when approval policy is never', async () => {
    const fakeProcess = new FakeAppServerProcess();
    let stdinBuffer = '';
    fakeProcess.stdin.on('data', (chunk) => {
        stdinBuffer += chunk.toString();
    });

    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex app-server',
            workspacePath: '/tmp',
            responseTimeoutMs: 300,
            turnTimeoutMs: 1000,
            approvalPolicy: 'never',
        },
        spawnImpl: () => fakeProcess,
    });

    const turnPromise = client.runTurn({
        prompt: 'Approval flow',
        issueIdentifier: 'ROB-12',
        attempt: 1,
    });

    await new Promise<void>((resolve) => setTimeout(resolve, 10));
    await emitHandshake(fakeProcess, 'thread-123', 'turn-456');
    fakeProcess.stdout.write(
        '{"id":"approve-1","method":"item/commandExecution/requestApproval","params":{"itemId":"item-1","threadId":"thread-123","turnId":"turn-456"}}\n',
    );
    fakeProcess.stdout.write(
        '{"id":"approve-2","method":"item/fileChange/requestApproval","params":{"itemId":"item-2","threadId":"thread-123","turnId":"turn-456"}}\n',
    );
    fakeProcess.stdout.write(
        '{"method":"turn/completed","params":{"threadId":"thread-123","turn":{"id":"turn-456","status":"completed","items":[],"error":null}}}\n',
    );

    const result = await turnPromise;
    assert.equal(result.status, 'completed');

    const sentMessages = stdinBuffer
        .trim()
        .split('\n')
        .map((line) => JSON.parse(line));
    const approvalResponses = sentMessages.filter(
        (message) => message.id === 'approve-1' || message.id === 'approve-2',
    );
    assert.equal(approvalResponses.length, 2);
    assert.deepEqual(
        approvalResponses.map((message) => message.result),
        [{decision: 'acceptForSession'}, {decision: 'acceptForSession'}],
    );
});
