import assert from 'node:assert/strict';
import test from 'node:test';
import type {OrchestratorSnapshot} from './orchestrator.js';
import {presentStateSnapshot} from './state-presenter.js';

function createSnapshot(overrides: Partial<OrchestratorSnapshot> = {}): OrchestratorSnapshot {
    return {
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
        health: {
            overall: 'ok',
            indicators: [
                {
                    status: 'ok',
                    code: 'healthy',
                    message: 'No active health warnings or errors.',
                },
            ],
        },
        recent_events: [],
        rate_limits: {},
        ...overrides,
    };
}

test('presentStateSnapshot builds formatted dashboard-facing fields from snapshot data', () => {
    const presented = presentStateSnapshot(
        createSnapshot({
            generated_at: '2026-03-06T11:00:42.000Z',
            last_tick_at: '2026-03-06T11:00:00.000Z',
            counts: {
                running: 1,
                retrying: 2,
                completed: 3,
                input_required: 4,
                failed: 5,
                response_timeouts: 0,
                turn_timeouts: 0,
                launch_failures: 0,
            },
            totals: {
                runtime_seconds: 42,
                input_tokens: 121000,
                output_tokens: 72468,
                total_tokens: 193468,
            },
            running: [
                {
                    issue_id: 'issue-1',
                    issue_identifier: 'ROB-42',
                    attempt: null,
                    source: 'candidate',
                    started_at: '2026-03-06T11:00:00.000Z',
                    runtime_seconds: 42,
                    last_activity_at: '2026-03-06T11:00:30.000Z',
                    idle_seconds: 12,
                    suppress_retry: false,
                    session_id: 'thread-1-turn-1',
                    thread_id: 'thread-1',
                    turn_count: 1,
                    last_event: 'item/agentMessage/delta',
                    last_activity: 'Codex is streaming a response.',
                    total_tokens: 193468,
                    last_turn_tokens: 1440,
                    context_window_tokens: 258400,
                    context_headroom_tokens: 64932,
                    context_utilization_percent: 74.9,
                    trace_tail: [
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
                    attempt: 2,
                    reason: 'dispatch_failed',
                    available_at: '2026-03-06T11:01:00.000Z',
                    error_class: 'approval_required',
                },
            ],
            recent_events: [
                {
                    issue_id: 'issue-2',
                    issue_identifier: 'ROB-43',
                    atIso: '2026-03-06T11:00:31.000Z',
                    category: 'runtime',
                    eventType: 'dispatch/failed',
                    message: 'Approval is required.',
                },
            ],
            rate_limits: {
                remaining: 12,
                burst_fraction: 0.125,
                limited: true,
            },
        }),
    );

    assert.equal(presented.generatedAt, '2026-03-06T11:00:42Z');
    assert.equal(presented.lastTickAt, '2026-03-06T11:00:00Z');
    assert.deepEqual(presented.counts, {
        running: '1',
        retrying: '2',
        completed: '3',
        inputRequired: '4',
        failed: '5',
        runningEntries: '1',
        retryingEntries: '1',
        recentEvents: '1',
    });
    assert.equal(presented.totals.runtimeSeconds, '42s');
    assert.equal(presented.totals.inputTokens, '121,000');
    assert.equal(presented.totals.outputTokens, '72,468');
    assert.equal(presented.totals.totalTokens, '193,468');
    assert.equal(presented.totals.throughputTokensPerSecond, '4,606.38');
    assert.equal(presented.metricCards.length, 4);
    assert.equal(presented.metricCards[0]?.label, 'Runtime total');

    assert.equal(presented.running[0]?.issueIdentifier, 'ROB-42');
    assert.equal(presented.running[0]?.runtime, '42s');
    assert.equal(presented.running[0]?.idle, '12s');
    assert.equal(presented.running[0]?.totalTokens, '193,468');
    assert.equal(presented.running[0]?.lastTurnTokens, '1,440');
    assert.equal(presented.running[0]?.contextHeadroom, '64,932');
    assert.equal(presented.running[0]?.utilization, '74.9%');
    assert.equal(presented.running[0]?.lastActivityAt, '2026-03-06T11:00:30Z');
    assert.equal(presented.running[0]?.session, 'thread-1-turn-1');
    assert.equal(presented.running[0]?.traceTail[0]?.eventType, 'session/started');

    assert.equal(presented.retrying[0]?.issueIdentifier, 'ROB-43');
    assert.equal(presented.retrying[0]?.attempt, '2');
    assert.equal(presented.retrying[0]?.retryAt, '2026-03-06T11:01:00Z');
    assert.equal(presented.retrying[0]?.errorClass, 'approval_required');

    assert.equal(presented.recentEvents[0]?.issueIdentifier, 'ROB-43');
    assert.equal(presented.recentEvents[0]?.at, '2026-03-06T11:00:31Z');

    assert.deepEqual(presented.rateLimits, [
        {key: 'remaining', value: '12'},
        {key: 'burst_fraction', value: '0.13'},
        {key: 'limited', value: 'true'},
    ]);
});

test('presentStateSnapshot applies stable fallbacks for missing or non-finite values', () => {
    const presented = presentStateSnapshot(
        createSnapshot({
            generated_at: 'not-a-date',
            last_tick_at: undefined,
            totals: {
                runtime_seconds: 0,
                input_tokens: Number.NaN,
                output_tokens: Number.POSITIVE_INFINITY,
                total_tokens: 15,
            },
            running: [
                {
                    issue_id: 'issue-9',
                    issue_identifier: 'ROB-99',
                    attempt: 1,
                    source: 'retry',
                    started_at: '2026-03-06T11:00:00.000Z',
                    runtime_seconds: Number.NaN,
                    last_activity_at: '',
                    idle_seconds: Number.NaN,
                    suppress_retry: false,
                    session_id: null,
                    thread_id: null,
                    turn_count: 0,
                    last_event: null,
                    last_activity: null,
                    total_tokens: null,
                    last_turn_tokens: null,
                    context_window_tokens: null,
                    context_headroom_tokens: null,
                    context_utilization_percent: null,
                    trace_tail: [],
                },
            ],
            retrying: [
                {
                    issue_id: 'issue-10',
                    issue_identifier: 'ROB-100',
                    attempt: 1,
                    reason: 'continuation',
                    available_at: 'not-a-date',
                },
            ],
            recent_events: [],
            rate_limits: {
                remaining: undefined,
                details: {window: '60s'},
                non_serializable: () => 'noop',
            },
        }),
    );

    assert.equal(presented.generatedAt, 'not-a-date');
    assert.equal(presented.lastTickAt, 'Never');
    assert.equal(presented.totals.runtimeSeconds, '0s');
    assert.equal(presented.totals.inputTokens, 'n/a');
    assert.equal(presented.totals.outputTokens, 'n/a');
    assert.equal(presented.totals.totalTokens, '15');
    assert.equal(presented.totals.throughputTokensPerSecond, 'n/a');

    assert.equal(presented.running[0]?.lastActivityMessage, 'No recent activity message.');
    assert.equal(presented.running[0]?.runtime, 'n/a');
    assert.equal(presented.running[0]?.idle, 'n/a');
    assert.equal(presented.running[0]?.totalTokens, 'n/a');
    assert.equal(presented.running[0]?.lastTurnTokens, 'n/a');
    assert.equal(presented.running[0]?.contextHeadroom, 'n/a');
    assert.equal(presented.running[0]?.utilization, 'n/a');
    assert.equal(presented.running[0]?.lastActivityAt, 'Never');
    assert.equal(presented.running[0]?.session, 'n/a');

    assert.equal(presented.retrying[0]?.retryAt, 'not-a-date');
    assert.equal(presented.retrying[0]?.errorClass, 'n/a');

    assert.deepEqual(presented.rateLimits, [
        {key: 'remaining', value: 'n/a'},
        {key: 'details', value: '{"window":"60s"}'},
        {key: 'non_serializable', value: 'n/a'},
    ]);
});
