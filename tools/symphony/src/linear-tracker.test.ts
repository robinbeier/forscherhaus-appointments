import assert from 'node:assert/strict';
import test from 'node:test';
import {LinearTrackerAdapter, LinearTrackerError} from './linear-tracker.js';

type MockFetchCall = {
    url: string;
    init?: RequestInit;
};

function jsonResponse(payload: unknown, status = 200): Response {
    return new Response(JSON.stringify(payload), {
        status,
        headers: {
            'Content-Type': 'application/json',
        },
    });
}

test('fetchCandidateIssues uses configured project/state filters and normalizes data', async () => {
    const calls: MockFetchCall[] = [];

    const adapter = new LinearTrackerAdapter({
        config: {
            apiKey: 'linear-token',
            projectSlug: 'forscherhaus',
            activeStates: ['In Progress', 'Todo'],
        },
        fetchImpl: async (url, init) => {
            calls.push({url, init});
            return jsonResponse({
                data: {
                    issues: {
                        nodes: [
                            {
                                id: 'issue-id-1',
                                identifier: 'ROB-10',
                                title: 'Linear adapter',
                                createdAt: '2026-03-06T08:00:00.000Z',
                                updatedAt: '2026-03-06T08:05:00.000Z',
                                priority: null,
                                state: {id: 'state-id-1', name: 'In Progress', type: 'started'},
                                labels: {nodes: [{name: 'Feature'}, {name: 'CI'}]},
                                relations: {
                                    nodes: [
                                        {
                                            type: 'blocks',
                                            issue: {id: 'issue-id-1', identifier: 'ROB-10'},
                                            relatedIssue: {id: 'issue-id-2', identifier: 'ROB-11'},
                                        },
                                    ],
                                },
                                inverseRelations: {
                                    nodes: [
                                        {
                                            type: 'blocks',
                                            issue: {id: 'issue-id-9', identifier: 'ROB-9'},
                                            relatedIssue: {id: 'issue-id-1', identifier: 'ROB-10'},
                                        },
                                    ],
                                },
                                project: {slugId: 'forscherhaus'},
                            },
                        ],
                        pageInfo: {
                            hasNextPage: false,
                            endCursor: null,
                        },
                    },
                },
            });
        },
    });

    const issues = await adapter.fetchCandidateIssues();

    assert.equal(issues.length, 1);
    assert.equal(issues[0].identifier, 'ROB-10');
    assert.deepEqual(issues[0].labels, ['feature', 'ci']);
    assert.deepEqual(issues[0].blockedByIdentifiers, ['ROB-9']);
    assert.equal(issues[0].priority, 0);

    const body = JSON.parse(String(calls[0].init?.body));
    assert.equal(body.variables.projectSlugId, 'forscherhaus');
    assert.deepEqual(body.variables.stateNames, ['In Progress', 'Todo']);
});

test('fetchIssueStatesByIds returns id -> state map', async () => {
    const adapter = new LinearTrackerAdapter({
        config: {
            apiKey: 'linear-token',
            projectSlug: 'forscherhaus',
            activeStates: ['In Progress'],
        },
        fetchImpl: async () =>
            jsonResponse({
                data: {
                    issues: {
                        nodes: [
                            {
                                id: 'issue-id-1',
                                identifier: 'ROB-10',
                                updatedAt: '2026-03-06T08:05:00.000Z',
                                state: {id: 'state-id-1', name: 'In Progress', type: 'started'},
                            },
                            {
                                id: 'issue-id-2',
                                identifier: 'ROB-11',
                                updatedAt: '2026-03-06T08:06:00.000Z',
                                state: {id: 'state-id-2', name: 'Done', type: 'completed'},
                            },
                        ],
                        pageInfo: {
                            hasNextPage: false,
                            endCursor: null,
                        },
                    },
                },
            }),
    });

    const states = await adapter.fetchIssueStatesByIds(['issue-id-1', 'issue-id-2']);
    assert.equal(states.get('issue-id-1'), 'In Progress');
    assert.equal(states.get('issue-id-2'), 'Done');
});

test('fetchIssuesByStates short-circuits for empty state lists', async () => {
    let called = false;

    const adapter = new LinearTrackerAdapter({
        config: {
            apiKey: 'linear-token',
            projectSlug: 'forscherhaus',
            activeStates: ['In Progress'],
        },
        fetchImpl: async () => {
            called = true;
            return jsonResponse({data: {issues: {nodes: [], pageInfo: {hasNextPage: false, endCursor: null}}}});
        },
    });

    const issues = await adapter.fetchIssuesByStates([]);
    assert.deepEqual(issues, []);
    assert.equal(called, false);
});

test('GraphQL errors are mapped to linear_graphql_errors', async () => {
    const adapter = new LinearTrackerAdapter({
        config: {
            apiKey: 'linear-token',
            projectSlug: 'forscherhaus',
            activeStates: ['In Progress'],
        },
        fetchImpl: async () =>
            jsonResponse({
                errors: [{message: 'Invalid query'}],
            }),
    });

    await assert.rejects(
        () => adapter.fetchCandidateIssues(),
        (error) =>
            error instanceof LinearTrackerError &&
            error.errorClass === 'linear_graphql_errors' &&
            Array.isArray(error.details.errors),
    );
});

test('HTTP status errors are mapped to linear_api_status', async () => {
    const adapter = new LinearTrackerAdapter({
        config: {
            apiKey: 'linear-token',
            projectSlug: 'forscherhaus',
            activeStates: ['In Progress'],
        },
        fetchImpl: async () =>
            new Response(
                JSON.stringify({
                    errors: [{message: 'Field "slug" is not defined by type "NullableProjectFilter".'}],
                }),
                {
                    status: 400,
                    headers: {
                        'Content-Type': 'application/json',
                        'x-request-id': 'req-123',
                    },
                },
            ),
    });

    await assert.rejects(
        () => adapter.fetchCandidateIssues(),
        (error) =>
            error instanceof LinearTrackerError &&
            error.errorClass === 'linear_api_status' &&
            error.message.includes('Field "slug" is not defined') &&
            error.details.requestId === 'req-123' &&
            Array.isArray(error.details.errors),
    );
});

test('Pagination protects against repeated endCursor loops', async () => {
    let invocationCount = 0;

    const adapter = new LinearTrackerAdapter({
        config: {
            apiKey: 'linear-token',
            projectSlug: 'forscherhaus',
            activeStates: ['In Progress'],
            pageSize: 1,
        },
        fetchImpl: async () => {
            invocationCount += 1;

            if (invocationCount === 1) {
                return jsonResponse({
                    data: {
                        issues: {
                            nodes: [],
                            pageInfo: {
                                hasNextPage: true,
                                endCursor: 'cursor-1',
                            },
                        },
                    },
                });
            }

            return jsonResponse({
                data: {
                    issues: {
                        nodes: [],
                        pageInfo: {
                            hasNextPage: true,
                            endCursor: 'cursor-1',
                        },
                    },
                },
            });
        },
    });

    await assert.rejects(
        () => adapter.fetchCandidateIssues(),
        (error) => error instanceof LinearTrackerError && error.errorClass === 'linear_pagination_cursor_stuck',
    );
});

test('Timeouts are mapped to linear_timeout', async () => {
    const adapter = new LinearTrackerAdapter({
        config: {
            apiKey: 'linear-token',
            projectSlug: 'forscherhaus',
            activeStates: ['In Progress'],
            timeoutMs: 20,
        },
        fetchImpl: async (_url, init) =>
            new Promise<Response>((_resolve, reject) => {
                init?.signal?.addEventListener('abort', () => {
                    reject(new DOMException('Aborted', 'AbortError'));
                });
            }),
    });

    await assert.rejects(
        () => adapter.fetchCandidateIssues(),
        (error) => error instanceof LinearTrackerError && error.errorClass === 'linear_timeout',
    );
});
