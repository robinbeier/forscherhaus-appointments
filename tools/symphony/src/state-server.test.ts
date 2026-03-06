import assert from 'node:assert/strict';
import test from 'node:test';
import type {Logger} from './logger.js';
import {SymphonyStateServer} from './state-server.js';

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

test('state server exposes GET /api/v1/state snapshot payload', async () => {
    const logRecords: Array<Record<string, unknown>> = [];
    const server = new SymphonyStateServer({
        enabled: true,
        logger: createLoggerStub(logRecords),
        host: '127.0.0.1',
        port: 0,
        getSnapshot: () => ({
            lastTickAtIso: '2026-03-06T11:00:00.000Z',
            running: [],
            retrying: [],
            codex_totals: {
                completed: 1,
                inputRequired: 0,
                failed: 0,
                responseTimeouts: 0,
                turnTimeouts: 0,
                launchFailures: 0,
            },
            rate_limits: {
                remaining: 12,
            },
        }),
        refresh: async () => undefined,
    });

    await server.start();
    const port = server.getListeningPort();
    assert.ok(port);

    const response = await fetch(`http://127.0.0.1:${port}/api/v1/state`);
    assert.equal(response.status, 200);

    const payload = (await response.json()) as Record<string, unknown>;
    assert.equal(payload.status, 'ok');
    assert.ok(payload.snapshot);

    await server.stop();
});

test('state server handles POST /api/v1/refresh asynchronously', async () => {
    let refreshCallCount = 0;
    const server = new SymphonyStateServer({
        enabled: true,
        logger: createLoggerStub([]),
        host: '127.0.0.1',
        port: 0,
        getSnapshot: () => ({
            running: [],
            retrying: [],
            codex_totals: {
                completed: 0,
                inputRequired: 0,
                failed: 0,
                responseTimeouts: 0,
                turnTimeouts: 0,
                launchFailures: 0,
            },
            rate_limits: {},
        }),
        refresh: async () => {
            refreshCallCount += 1;
        },
    });

    await server.start();
    const port = server.getListeningPort();
    assert.ok(port);

    const response = await fetch(`http://127.0.0.1:${port}/api/v1/refresh`, {
        method: 'POST',
    });

    assert.equal(response.status, 202);
    await new Promise((resolve) => setTimeout(resolve, 20));
    assert.equal(refreshCallCount, 1);

    await server.stop();
});

test('state server returns 404 for unknown routes and no-op when disabled', async () => {
    const server = new SymphonyStateServer({
        enabled: true,
        logger: createLoggerStub([]),
        host: '127.0.0.1',
        port: 0,
        getSnapshot: () => ({
            running: [],
            retrying: [],
            codex_totals: {
                completed: 0,
                inputRequired: 0,
                failed: 0,
                responseTimeouts: 0,
                turnTimeouts: 0,
                launchFailures: 0,
            },
            rate_limits: {},
        }),
        refresh: async () => undefined,
    });

    await server.start();
    const port = server.getListeningPort();
    assert.ok(port);

    const response = await fetch(`http://127.0.0.1:${port}/unknown`);
    assert.equal(response.status, 404);
    await server.stop();

    const disabledServer = new SymphonyStateServer({
        enabled: false,
        logger: createLoggerStub([]),
        host: '127.0.0.1',
        port: 8787,
        getSnapshot: () => ({
            running: [],
            retrying: [],
            codex_totals: {
                completed: 0,
                inputRequired: 0,
                failed: 0,
                responseTimeouts: 0,
                turnTimeouts: 0,
                launchFailures: 0,
            },
            rate_limits: {},
        }),
        refresh: async () => undefined,
    });

    await disabledServer.start();
    assert.equal(disabledServer.getListeningPort(), undefined);
    await disabledServer.stop();
});
