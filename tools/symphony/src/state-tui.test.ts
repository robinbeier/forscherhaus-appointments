import assert from 'node:assert/strict';
import test from 'node:test';
import type {OrchestratorSnapshot} from './orchestrator.js';
import {renderTerminalStatusView, SymphonyStateTui} from './state-tui.js';

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

test('renderTerminalStatusView renders header, running table and backoff queue', () => {
    const output = renderTerminalStatusView({
        snapshot: createSnapshot({
            counts: {
                running: 1,
                retrying: 1,
                completed: 0,
                input_required: 0,
                failed: 0,
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
                    trace_tail: [],
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
            rate_limits: {
                remaining: 12,
            },
        }),
        options: {
            projectSlug: 'school-appointments',
            dashboardUrl: 'http://127.0.0.1:8787/',
            nextRefreshSeconds: 5,
        },
    });

    assert.match(output, /SYMPHONY STATUS/);
    assert.match(output, /Throughput: 4,606\.38 tps/);
    assert.match(output, /Tokens: in 121,000 \| out 72,468 \| total 193,468/);
    assert.match(output, /Project: school-appointments/);
    assert.match(output, /Dashboard: http:\/\/127\.0\.0\.1:8787\//);
    assert.match(output, /RUNNING/);
    assert.match(output, /ROB-42/);
    assert.match(output, /BACKOFF QUEUE/);
    assert.match(output, /ROB-43/);
});

test('renderTerminalStatusView renders deterministic empty states', () => {
    const output = renderTerminalStatusView({
        snapshot: createSnapshot(),
    });

    assert.match(output, /Rate Limits: unavailable/);
    assert.match(output, /No running issues\./);
    assert.match(output, /No queued retries\./);
});

test('SymphonyStateTui emits terminal reset and supports stop lifecycle', () => {
    const writes: string[] = [];
    const tui = new SymphonyStateTui({
        write: (chunk) => {
            writes.push(chunk);
        },
    });

    tui.start(createSnapshot());
    tui.render(createSnapshot());
    tui.stop();

    assert.ok(writes[0]?.startsWith('\u001b[2J\u001b[H'));
    assert.ok(writes[1]?.startsWith('\u001b[2J\u001b[H'));
    assert.equal(writes[writes.length - 1], '\n');
});
