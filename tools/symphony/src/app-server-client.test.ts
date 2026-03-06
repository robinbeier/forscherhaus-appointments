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
            turnTimeoutMs: 20,
        },
        spawnImpl: () => fakeProcess,
    });

    const turnPromise = client.runTurn({
        prompt: 'Turn timeout test',
        issueIdentifier: 'ROB-12',
        attempt: 1,
    });

    await new Promise<void>((resolve) => setTimeout(resolve, 10));
    await emitHandshake(fakeProcess, 'thread-123', 'turn-456');

    await assert.rejects(
        () => turnPromise,
        (error) => error instanceof AppServerClientError && error.errorClass === 'turn_timeout',
    );
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

test('approval requests are auto-accepted and turn continues', async () => {
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
