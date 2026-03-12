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

test('state server exposes human-readable dashboard at GET / and JSON snapshot at GET /api/v1/state', async () => {
    const logRecords: Array<Record<string, unknown>> = [];
    const server = new SymphonyStateServer({
        enabled: true,
        logger: createLoggerStub(logRecords),
        host: '127.0.0.1',
        port: 0,
        getSnapshot: () => ({
            generated_at: '2026-03-06T11:00:42.000Z',
            last_tick_at: '2026-03-06T11:00:00.000Z',
            lastTickAtIso: '2026-03-06T11:00:00.000Z',
            counts: {
                running: 1,
                retrying: 0,
                completed: 1,
                input_required: 0,
                failed: 0,
                response_timeouts: 0,
                turn_timeouts: 0,
                launch_failures: 0,
            },
            running: [
                {
                    issue_id: 'issue-1',
                    issue_identifier: 'ROB-42',
                    issueId: 'issue-1',
                    issueIdentifier: 'ROB-42',
                    attempt: null,
                    source: 'candidate',
                    started_at: '2026-03-06T11:00:00.000Z',
                    startedAtIso: '2026-03-06T11:00:00.000Z',
                    runtime_seconds: 42,
                    runtimeSeconds: 42,
                    last_activity_at: '2026-03-06T11:00:30.000Z',
                    lastActivityAtIso: '2026-03-06T11:00:30.000Z',
                    idle_seconds: 12,
                    idleSeconds: 12,
                    suppress_retry: false,
                    suppressRetry: false,
                    session_id: 'thread-1-turn-1',
                    sessionId: 'thread-1-turn-1',
                    thread_id: 'thread-1',
                    threadId: 'thread-1',
                    turn_count: 0,
                    turnCount: 0,
                    last_event: 'item/agentMessage/delta',
                    lastEvent: 'item/agentMessage/delta',
                    last_activity: 'Codex is streaming a response. <script>alert(1)</script>',
                    lastActivity: 'Codex is streaming a response. <script>alert(1)</script>',
                    total_tokens: 193468,
                    totalTokens: 193468,
                    last_turn_tokens: 1440,
                    lastTurnTokens: 1440,
                    context_window_tokens: 258400,
                    contextWindowTokens: 258400,
                    context_headroom_tokens: 64932,
                    contextHeadroomTokens: 64932,
                    context_utilization_percent: 74.9,
                    contextUtilizationPercent: 74.9,
                    trace_tail: [
                        {
                            atIso: '2026-03-06T11:00:20.000Z',
                            category: 'runtime',
                            eventType: 'session/started',
                            message: 'Session started.',
                        },
                    ],
                    traceTail: [
                        {
                            atIso: '2026-03-06T11:00:20.000Z',
                            category: 'runtime',
                            eventType: 'session/started',
                            message: 'Session started.',
                        },
                    ],
                },
            ],
            retrying: [
                {
                    issue_id: 'issue-2',
                    issue_identifier: 'ROB-43',
                    issueId: 'issue-2',
                    issueIdentifier: 'ROB-43',
                    attempt: 2,
                    reason: 'dispatch_failed',
                    available_at: '2026-03-06T11:01:00.000Z',
                    availableAtIso: '2026-03-06T11:01:00.000Z',
                    error_class: 'approval_required',
                    errorClass: 'approval_required',
                },
            ],
            totals: {
                runtime_seconds: 42,
                input_tokens: 121000,
                output_tokens: 72468,
                total_tokens: 193468,
            },
            codex_totals: {
                input_tokens: 121000,
                output_tokens: 72468,
                total_tokens: 193468,
                seconds_running: 42,
            },
            rate_limits: {
                remaining: 12,
            },
        }),
        getIssueDetails: () => undefined,
        refresh: async () => undefined,
    });

    await server.start();
    const port = server.getListeningPort();
    assert.ok(port);

    const dashboardResponse = await fetch(`http://127.0.0.1:${port}/`);
    assert.equal(dashboardResponse.status, 200);
    assert.match(dashboardResponse.headers.get('content-type') ?? '', /text\/html/);
    const dashboardHtml = await dashboardResponse.text();
    assert.match(dashboardHtml, /Symphony Dashboard/);
    assert.match(dashboardHtml, /ROB-42/);
    assert.match(dashboardHtml, /ROB-43/);
    assert.match(dashboardHtml, /193,468/);
    assert.match(dashboardHtml, /42s/);
    assert.match(dashboardHtml, /approval_required/);
    assert.match(dashboardHtml, /&lt;script&gt;alert\(1\)&lt;\/script&gt;/);
    assert.doesNotMatch(dashboardHtml, /<script>alert\(1\)<\/script>/);

    const response = await fetch(`http://127.0.0.1:${port}/api/v1/state`);
    assert.equal(response.status, 200);

    const payload = (await response.json()) as Record<string, unknown>;
    assert.equal(payload.status, 'ok');
    assert.ok(payload.snapshot);
    const snapshot = payload.snapshot as {
        generated_at: string;
        last_tick_at: string;
        counts: {
            completed: number;
        };
        totals: {
            total_tokens: number;
            runtime_seconds: number;
        };
        codex_totals: {
            total_tokens: number;
        };
        running: Array<{
            issue_identifier: string;
            last_activity: string;
            context_headroom_tokens: number;
            trace_tail: Array<{
                eventType: string;
            }>;
            issueIdentifier: string;
            lastActivity: string;
            contextHeadroomTokens: number;
            traceTail: Array<{
                eventType: string;
            }>;
        }>;
    };
    assert.equal(snapshot.generated_at, '2026-03-06T11:00:42.000Z');
    assert.equal(snapshot.last_tick_at, '2026-03-06T11:00:00.000Z');
    assert.equal(snapshot.counts.completed, 1);
    assert.equal(snapshot.totals.total_tokens, 193468);
    assert.equal(snapshot.totals.runtime_seconds, 42);
    assert.equal(snapshot.codex_totals.total_tokens, 193468);
    assert.equal(snapshot.running[0]?.issue_identifier, 'ROB-42');
    assert.equal(snapshot.running[0]?.last_activity, 'Codex is streaming a response. <script>alert(1)</script>');
    assert.equal(snapshot.running[0]?.context_headroom_tokens, 64932);
    assert.equal(snapshot.running[0]?.trace_tail[0]?.eventType, 'session/started');
    assert.equal(snapshot.running[0]?.issueIdentifier, 'ROB-42');
    assert.equal(snapshot.running[0]?.lastActivity, 'Codex is streaming a response. <script>alert(1)</script>');
    assert.equal(snapshot.running[0]?.contextHeadroomTokens, 64932);
    assert.equal(snapshot.running[0]?.traceTail[0]?.eventType, 'session/started');

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
            generated_at: '2026-03-06T11:00:00.000Z',
            last_tick_at: '2026-03-06T11:00:00.000Z',
            counts: {
                running: 0,
                retrying: 0,
                completed: 0,
                input_required: 0,
                failed: 0,
                response_timeouts: 0,
                turn_timeouts: 0,
                launch_failures: 0,
            },
            running: [],
            retrying: [],
            totals: {
                runtime_seconds: 0,
                input_tokens: 0,
                output_tokens: 0,
                total_tokens: 0,
            },
            codex_totals: {
                input_tokens: 0,
                output_tokens: 0,
                total_tokens: 0,
                seconds_running: 0,
            },
            rate_limits: {},
        }),
        getIssueDetails: () => undefined,
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
            generated_at: '2026-03-06T11:00:00.000Z',
            last_tick_at: '2026-03-06T11:00:00.000Z',
            counts: {
                running: 0,
                retrying: 0,
                completed: 0,
                input_required: 0,
                failed: 0,
                response_timeouts: 0,
                turn_timeouts: 0,
                launch_failures: 0,
            },
            running: [],
            retrying: [],
            totals: {
                runtime_seconds: 0,
                input_tokens: 0,
                output_tokens: 0,
                total_tokens: 0,
            },
            codex_totals: {
                input_tokens: 0,
                output_tokens: 0,
                total_tokens: 0,
                seconds_running: 0,
            },
            rate_limits: {},
        }),
        getIssueDetails: () => undefined,
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
            generated_at: '2026-03-06T11:00:00.000Z',
            last_tick_at: '2026-03-06T11:00:00.000Z',
            counts: {
                running: 0,
                retrying: 0,
                completed: 0,
                input_required: 0,
                failed: 0,
                response_timeouts: 0,
                turn_timeouts: 0,
                launch_failures: 0,
            },
            running: [],
            retrying: [],
            totals: {
                runtime_seconds: 0,
                input_tokens: 0,
                output_tokens: 0,
                total_tokens: 0,
            },
            codex_totals: {
                input_tokens: 0,
                output_tokens: 0,
                total_tokens: 0,
                seconds_running: 0,
            },
            rate_limits: {},
        }),
        getIssueDetails: () => undefined,
        refresh: async () => undefined,
    });

    await disabledServer.start();
    assert.equal(disabledServer.getListeningPort(), undefined);
    await disabledServer.stop();
});

test('state server exposes GET /api/v1/<issue_identifier> issue debug payload and 405 for wrong method', async () => {
    const server = new SymphonyStateServer({
        enabled: true,
        logger: createLoggerStub([]),
        host: '127.0.0.1',
        port: 0,
        getSnapshot: () => ({
            generated_at: '2026-03-06T11:00:00.000Z',
            last_tick_at: '2026-03-06T11:00:00.000Z',
            counts: {
                running: 0,
                retrying: 0,
                completed: 0,
                input_required: 0,
                failed: 0,
                response_timeouts: 0,
                turn_timeouts: 0,
                launch_failures: 0,
            },
            running: [],
            retrying: [],
            totals: {
                runtime_seconds: 0,
                input_tokens: 0,
                output_tokens: 0,
                total_tokens: 0,
            },
            codex_totals: {
                input_tokens: 0,
                output_tokens: 0,
                total_tokens: 0,
                seconds_running: 0,
            },
            rate_limits: {},
        }),
        getIssueDetails: (issueIdentifier) =>
            issueIdentifier === 'ROB-42'
                ? {
                      issue_identifier: 'ROB-42',
                      status: 'running',
                  }
                : undefined,
        refresh: async () => undefined,
    });

    await server.start();
    const port = server.getListeningPort();
    assert.ok(port);

    const issueResponse = await fetch(`http://127.0.0.1:${port}/api/v1/ROB-42`);
    assert.equal(issueResponse.status, 200);
    const issuePayload = (await issueResponse.json()) as Record<string, unknown>;
    assert.equal(issuePayload.issue_identifier, 'ROB-42');

    const missingResponse = await fetch(`http://127.0.0.1:${port}/api/v1/ROB-404`);
    assert.equal(missingResponse.status, 404);

    const methodResponse = await fetch(`http://127.0.0.1:${port}/api/v1/state`, {
        method: 'POST',
    });
    assert.equal(methodResponse.status, 405);

    await server.stop();
});
