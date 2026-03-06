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
            command: 'codex --app-server',
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
        threadId: 'thread-123',
        turnId: 'turn-456',
    });

    await new Promise<void>((resolve) => setTimeout(resolve, 10));

    fakeProcess.stdout.write('{"type":"response.output_text.delta","delta":"Hel');
    fakeProcess.stdout.write('lo"}\n{"type":"response.output_text.delta","delta":" World"}\n');
    fakeProcess.stdout.write('{"type":"response.completed"}\n');

    const result = await turnPromise;
    assert.equal(result.status, 'completed');
    assert.equal(result.outputText, 'Hello World');
    assert.equal(result.sessionId, 'thread-123-turn-456');

    const sentMessages = stdinBuffer
        .trim()
        .split('\n')
        .map((line) => JSON.parse(line));
    assert.deepEqual(
        sentMessages.map((message) => message.type),
        ['initialize', 'initialized', 'thread/start', 'turn/start'],
    );

    const sessionEvent = events.find((event) => event.type === 'session') as Extract<
        OrchestratorEvent,
        {type: 'session'}
    >;
    assert.ok(sessionEvent);
    assert.equal(sessionEvent.sessionId, 'thread-123-turn-456');
});

test('turn.input_required resolves without hanging', async () => {
    const fakeProcess = new FakeAppServerProcess();
    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex --app-server',
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
    fakeProcess.stdout.write('{"type":"turn.input_required"}\n');

    const result = await turnPromise;
    assert.equal(result.status, 'input_required');
});

test('runTurn maps response timeout when no response arrives', async () => {
    const fakeProcess = new FakeAppServerProcess();
    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex --app-server',
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

test('runTurn maps turn timeout when turn exceeds limit', async () => {
    const fakeProcess = new FakeAppServerProcess();
    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex --app-server',
            workspacePath: '/tmp',
            responseTimeoutMs: 2000,
            turnTimeoutMs: 20,
        },
        spawnImpl: () => fakeProcess,
    });

    await assert.rejects(
        () =>
            client.runTurn({
                prompt: 'Turn timeout test',
                issueIdentifier: 'ROB-12',
                attempt: 1,
            }),
        (error) => error instanceof AppServerClientError && error.errorClass === 'turn_timeout',
    );
});

test('runTurn emits rate-limit, token-usage and diagnostics events', async () => {
    const fakeProcess = new FakeAppServerProcess();
    const events: OrchestratorEvent[] = [];

    const client = new CodexAppServerClient({
        logger: createLoggerStub([]),
        config: {
            command: 'codex --app-server',
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
    fakeProcess.stderr.write('diagnostic line\n');
    fakeProcess.stdout.write('{"type":"rate_limits.updated","rate_limits":{"remaining":17}}\n');
    fakeProcess.stdout.write('{"type":"session.updated","usage":{"input_tokens":12,"output_tokens":5}}\n');
    fakeProcess.stdout.write('{"type":"response.completed"}\n');

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
            command: 'codex --app-server',
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
    fakeProcess.stdout.write('{"type":"turn.failed","error":"boom"}\n');

    await assert.rejects(
        () => turnPromise,
        (error) => error instanceof AppServerClientError && error.errorClass === 'turn_failed',
    );
});
