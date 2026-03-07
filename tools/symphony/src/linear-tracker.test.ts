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
                                description: '  Normalized issue description. ',
                                branchName: 'feature/rob-10',
                                url: 'https://linear.app/forscherhaus/issue/ROB-10',
                                createdAt: '2026-03-06T08:00:00.000Z',
                                updatedAt: '2026-03-06T08:05:00.000Z',
                                priority: null,
                                state: {id: 'state-id-1', name: 'In Progress', type: 'started'},
                                labels: {nodes: [{name: 'Feature'}, {name: 'CI'}]},
                                relations: {
                                    nodes: [
                                        {
                                            type: 'blocks',
                                            issue: {
                                                id: 'issue-id-1',
                                                identifier: 'ROB-10',
                                                state: {name: 'In Progress'},
                                            },
                                            relatedIssue: {
                                                id: 'issue-id-2',
                                                identifier: 'ROB-11',
                                                state: {name: 'Todo'},
                                            },
                                        },
                                    ],
                                },
                                inverseRelations: {
                                    nodes: [
                                        {
                                            type: 'blocks',
                                            issue: {
                                                id: 'issue-id-9',
                                                identifier: 'ROB-9',
                                                state: {name: 'Todo'},
                                            },
                                            relatedIssue: {
                                                id: 'issue-id-1',
                                                identifier: 'ROB-10',
                                                state: {name: 'In Progress'},
                                            },
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
    assert.equal(issues[0].description, 'Normalized issue description.');
    assert.equal(issues[0].branchName, 'feature/rob-10');
    assert.equal(issues[0].url, 'https://linear.app/forscherhaus/issue/ROB-10');
    assert.deepEqual(issues[0].labels, ['feature', 'ci']);
    assert.deepEqual(issues[0].blockedBy, [{id: 'issue-id-9', identifier: 'ROB-9', state: 'Todo'}]);
    assert.deepEqual(issues[0].blockedByIdentifiers, ['ROB-9']);
    assert.equal(issues[0].priority, null);

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

test('prepareIssueForRun moves Todo issues to In Progress and reuses the newest existing workpad comment', async () => {
    const calls: MockFetchCall[] = [];

    const adapter = new LinearTrackerAdapter({
        config: {
            apiKey: 'linear-token',
            projectSlug: 'forscherhaus',
            activeStates: ['Todo', 'In Progress'],
        },
        fetchImpl: async (url, init) => {
            calls.push({url, init});

            if (calls.length === 1) {
                return jsonResponse({
                    data: {
                        issue: {
                            id: 'issue-id-1',
                            identifier: 'ROB-10',
                            title: 'Linear adapter',
                            description: 'Scope',
                            branchName: 'feature/rob-10',
                            url: 'https://linear.app/forscherhaus/issue/ROB-10',
                            createdAt: '2026-03-06T08:00:00.000Z',
                            updatedAt: '2026-03-06T08:05:00.000Z',
                            priority: 1,
                            state: {id: 'state-todo', name: 'Todo', type: 'unstarted'},
                            labels: {nodes: []},
                            relations: {nodes: []},
                            inverseRelations: {nodes: []},
                            project: {slugId: 'forscherhaus'},
                            comments: {
                                nodes: [
                                    {
                                        id: 'comment-old',
                                        body: '## Codex Workpad\n\nOlder',
                                        url: 'https://linear.app/comment-old',
                                        updatedAt: '2026-03-06T08:04:00.000Z',
                                    },
                                    {
                                        id: 'comment-new',
                                        body: '## Codex Workpad\n\nNewer',
                                        url: 'https://linear.app/comment-new',
                                        updatedAt: '2026-03-06T08:06:00.000Z',
                                    },
                                    {
                                        id: 'comment-other',
                                        body: 'Regular comment',
                                        url: 'https://linear.app/comment-other',
                                        updatedAt: '2026-03-06T08:07:00.000Z',
                                    },
                                ],
                            },
                            team: {
                                states: {
                                    nodes: [
                                        {id: 'state-todo', name: 'Todo', type: 'unstarted'},
                                        {id: 'state-progress', name: 'In Progress', type: 'started'},
                                    ],
                                },
                            },
                        },
                    },
                });
            }

            return jsonResponse({
                data: {
                    issueUpdate: {
                        success: true,
                        issue: {
                            id: 'issue-id-1',
                            updatedAt: '2026-03-06T08:07:00.000Z',
                            state: {
                                id: 'state-progress',
                                name: 'In Progress',
                            },
                        },
                    },
                },
            });
        },
    });

    const preparedIssue = await adapter.prepareIssueForRun({
        id: 'issue-id-1',
        identifier: 'ROB-10',
        title: 'Linear adapter',
        description: null,
        stateName: 'Todo',
        stateType: 'unstarted',
        priority: 1,
        branchName: null,
        url: null,
        labels: [],
        blockedBy: [],
        blockedByIdentifiers: [],
        createdAt: '2026-03-06T08:00:00.000Z',
        updatedAt: '2026-03-06T08:05:00.000Z',
        projectSlug: 'forscherhaus',
        workpadCommentId: null,
        workpadCommentBody: null,
        workpadCommentUrl: null,
    });

    assert.equal(preparedIssue.stateName, 'In Progress');
    assert.equal(preparedIssue.updatedAt, '2026-03-06T08:07:00.000Z');
    assert.equal(preparedIssue.workpadCommentId, 'comment-new');
    assert.equal(preparedIssue.workpadCommentBody, '## Codex Workpad\n\nNewer');
    assert.equal(preparedIssue.workpadCommentUrl, 'https://linear.app/comment-new');
    assert.equal(calls.length, 2);

    const updateBody = JSON.parse(String(calls[1].init?.body));
    assert.equal(updateBody.variables.id, 'issue-id-1');
    assert.equal(updateBody.variables.stateId, 'state-progress');
});

test('prepareIssueForRun refreshes legacy bootstrap workpads in place', async () => {
    const calls: MockFetchCall[] = [];

    const adapter = new LinearTrackerAdapter({
        config: {
            apiKey: 'linear-token',
            projectSlug: 'forscherhaus',
            activeStates: ['In Progress'],
        },
        fetchImpl: async (url, init) => {
            calls.push({url, init});

            if (calls.length === 1) {
                return jsonResponse({
                    data: {
                        issue: {
                            id: 'issue-id-legacy',
                            identifier: 'ROB-12',
                            title: 'Refresh bootstrap workpad',
                            description: [
                                '## Scope',
                                '',
                                '- Update `docs/symphony/STAGING_PILOT_RUNBOOK.md`.',
                                '',
                                '## Definition of Done',
                                '',
                                '- The runbook contains the new note.',
                            ].join('\n'),
                            branchName: 'feature/rob-12',
                            url: 'https://linear.app/forscherhaus/issue/ROB-12',
                            createdAt: '2026-03-06T08:00:00.000Z',
                            updatedAt: '2026-03-06T08:05:00.000Z',
                            priority: 2,
                            state: {id: 'state-progress', name: 'In Progress', type: 'started'},
                            labels: {nodes: []},
                            relations: {nodes: []},
                            inverseRelations: {nodes: []},
                            project: {slugId: 'forscherhaus'},
                            comments: {
                                nodes: [
                                    {
                                        id: 'comment-legacy',
                                        body: [
                                            '## Codex Workpad',
                                            '',
                                            '### Status',
                                            '- Summary: Starting Symphony run for ROB-12.',
                                            '- Next: Reconcile the current scope, then complete the next concrete milestone.',
                                            '',
                                            '### Plan',
                                            '- Reconcile the issue scope against the current workspace state.',
                                            '- Complete the next concrete milestone for this issue.',
                                            '',
                                            '### Validation',
                                            '- Done: None yet.',
                                            '- Pending: Determine and run the narrowest relevant check for the current diff.',
                                            '',
                                            '### Blockers',
                                            '- None.',
                                        ].join('\n'),
                                        url: 'https://linear.app/comment-legacy',
                                        updatedAt: '2026-03-06T08:06:00.000Z',
                                    },
                                ],
                            },
                            team: {
                                states: {
                                    nodes: [{id: 'state-progress', name: 'In Progress', type: 'started'}],
                                },
                            },
                        },
                    },
                });
            }

            return jsonResponse({
                data: {
                    commentUpdate: {
                        success: true,
                        comment: {
                            id: 'comment-legacy',
                            body: [
                                '## Codex Workpad',
                                '',
                                '### Status',
                                '- Summary: Starting Symphony run for ROB-12: Refresh bootstrap workpad.',
                                '- Next: Update `docs/symphony/STAGING_PILOT_RUNBOOK.md`.',
                                '',
                                '### Plan',
                                '- [ ] Update `docs/symphony/STAGING_PILOT_RUNBOOK.md`.',
                                '',
                                '### Acceptance Criteria',
                                '- [ ] The runbook contains the new note.',
                                '',
                                '### Validation',
                                '- [ ] Run the narrowest relevant check for the current diff.',
                                '',
                                '### Notes',
                                '- No extra notes yet.',
                                '',
                                '### Blockers',
                                '- None.',
                            ].join('\n'),
                            url: 'https://linear.app/comment-legacy',
                        },
                    },
                },
            });
        },
    });

    const preparedIssue = await adapter.prepareIssueForRun({
        id: 'issue-id-legacy',
        identifier: 'ROB-12',
        title: 'Refresh bootstrap workpad',
        description: null,
        stateName: 'In Progress',
        stateType: 'started',
        priority: 2,
        branchName: null,
        url: null,
        labels: [],
        blockedBy: [],
        blockedByIdentifiers: [],
        createdAt: '2026-03-06T08:00:00.000Z',
        updatedAt: '2026-03-06T08:05:00.000Z',
        projectSlug: 'forscherhaus',
        workpadCommentId: null,
        workpadCommentBody: null,
        workpadCommentUrl: null,
    });

    assert.equal(preparedIssue.workpadCommentId, 'comment-legacy');
    assert.match(preparedIssue.workpadCommentBody ?? '', /### Acceptance Criteria/);
    assert.equal(calls.length, 2);

    const updateBody = JSON.parse(String(calls[1].init?.body));
    assert.equal(updateBody.variables.id, 'comment-legacy');
    assert.match(updateBody.variables.body, /Update `docs\/symphony\/STAGING_PILOT_RUNBOOK\.md`\./);
});

test('prepareIssueForRun creates a bootstrap workpad comment when none exists', async () => {
    const calls: MockFetchCall[] = [];

    const adapter = new LinearTrackerAdapter({
        config: {
            apiKey: 'linear-token',
            projectSlug: 'forscherhaus',
            activeStates: ['In Progress'],
        },
        fetchImpl: async (url, init) => {
            calls.push({url, init});

            if (calls.length === 1) {
                return jsonResponse({
                    data: {
                        issue: {
                            id: 'issue-id-2',
                            identifier: 'ROB-11',
                            title: 'Bootstrap workpad',
                            description: [
                                '## Goal',
                                '',
                                'Add a focused documentation note.',
                                '',
                                '## Scope',
                                '',
                                '- Update `docs/symphony/STAGING_PILOT_RUNBOOK.md`.',
                                '- Keep the change limited to docs only.',
                                '',
                                '## Definition of Done',
                                '',
                                '- The runbook contains the new note.',
                                '- No unrelated file changes.',
                                '',
                                '## Validation',
                                '',
                                '- Confirm markdown formatting stays clean.',
                            ].join('\n'),
                            branchName: 'feature/rob-11',
                            url: 'https://linear.app/forscherhaus/issue/ROB-11',
                            createdAt: '2026-03-06T08:00:00.000Z',
                            updatedAt: '2026-03-06T08:05:00.000Z',
                            priority: 2,
                            state: {id: 'state-progress', name: 'In Progress', type: 'started'},
                            labels: {nodes: []},
                            relations: {nodes: []},
                            inverseRelations: {nodes: []},
                            project: {slugId: 'forscherhaus'},
                            comments: {
                                nodes: [
                                    {
                                        id: 'comment-other',
                                        body: 'Regular comment',
                                        url: 'https://linear.app/comment-other',
                                        updatedAt: '2026-03-06T08:07:00.000Z',
                                    },
                                ],
                            },
                            team: {
                                states: {
                                    nodes: [{id: 'state-progress', name: 'In Progress', type: 'started'}],
                                },
                            },
                        },
                    },
                });
            }

            return jsonResponse({
                data: {
                    commentCreate: {
                        success: true,
                        comment: {
                            id: 'comment-created',
                            body: '## Codex Workpad\n\nCreated',
                            url: 'https://linear.app/comment-created',
                        },
                    },
                },
            });
        },
    });

    const preparedIssue = await adapter.prepareIssueForRun({
        id: 'issue-id-2',
        identifier: 'ROB-11',
        title: 'Bootstrap workpad',
        description: null,
        stateName: 'In Progress',
        stateType: 'started',
        priority: 2,
        branchName: null,
        url: null,
        labels: [],
        blockedBy: [],
        blockedByIdentifiers: [],
        createdAt: '2026-03-06T08:00:00.000Z',
        updatedAt: '2026-03-06T08:05:00.000Z',
        projectSlug: 'forscherhaus',
        workpadCommentId: null,
        workpadCommentBody: null,
        workpadCommentUrl: null,
    });

    assert.equal(preparedIssue.stateName, 'In Progress');
    assert.equal(preparedIssue.workpadCommentId, 'comment-created');
    assert.equal(preparedIssue.workpadCommentBody, '## Codex Workpad\n\nCreated');
    assert.equal(preparedIssue.workpadCommentUrl, 'https://linear.app/comment-created');
    assert.equal(calls.length, 2);

    const createBody = JSON.parse(String(calls[1].init?.body));
    assert.equal(createBody.variables.issueId, 'issue-id-2');
    assert.match(createBody.variables.body, /^## Codex Workpad/);
    assert.match(createBody.variables.body, /Update `docs\/symphony\/STAGING_PILOT_RUNBOOK\.md`\./);
    assert.match(createBody.variables.body, /### Acceptance Criteria/);
    assert.match(createBody.variables.body, /The runbook contains the new note\./);
    assert.match(createBody.variables.body, /Confirm markdown formatting stays clean\./);
});

test('executeLinearGraphQlToolCall preserves GraphQL errors as tool payload', async () => {
    const adapter = new LinearTrackerAdapter({
        config: {
            apiKey: 'linear-token',
            projectSlug: 'forscherhaus',
            activeStates: ['In Progress'],
        },
        fetchImpl: async () =>
            jsonResponse({
                data: {
                    issue: null,
                },
                errors: [{message: 'Issue not found'}],
            }),
    });

    const result = await adapter.executeLinearGraphQlToolCall({
        query: 'query FetchIssue { issue(id: "issue-id-1") { id } }',
    });

    assert.equal(result.success, false);
    assert.deepEqual(result.payload, {
        data: {
            issue: null,
        },
        errors: [{message: 'Issue not found'}],
    });
});

test('executeLinearGraphQlToolCall passes multi-operation documents through unchanged', async () => {
    const adapter = new LinearTrackerAdapter({
        config: {
            apiKey: 'linear-token',
            projectSlug: 'forscherhaus',
            activeStates: ['In Progress'],
        },
        fetchImpl: async (_url, init) => {
            assert.ok(init?.body);
            const body = JSON.parse(String(init.body));
            assert.equal(body.query, 'query First { viewer { id } } query Second { issues { nodes { id } } }');
            return jsonResponse({
                errors: [{message: 'Must provide operation name if query contains multiple operations.'}],
            });
        },
    });

    const result = await adapter.executeLinearGraphQlToolCall({
        query: 'query First { viewer { id } } query Second { issues { nodes { id } } }',
    });

    assert.equal(result.success, false);
    assert.deepEqual(result.payload, {
        errors: [{message: 'Must provide operation name if query contains multiple operations.'}],
    });
});

test('executeLinearGraphQlToolCall accepts one operation with query keywords inside string literals', async () => {
    let invocationCount = 0;

    const adapter = new LinearTrackerAdapter({
        config: {
            apiKey: 'linear-token',
            projectSlug: 'forscherhaus',
            activeStates: ['In Progress'],
        },
        fetchImpl: async () => {
            invocationCount += 1;
            return jsonResponse({
                data: {
                    searchIssues: {
                        nodes: [],
                    },
                },
            });
        },
    });

    const result = await adapter.executeLinearGraphQlToolCall({
        query: 'query SearchIssues { searchIssues(query: "query mutation subscription") { nodes { id } } }',
    });

    assert.equal(result.success, true);
    assert.equal(invocationCount, 1);
});

test('executeLinearGraphQlToolCall rejects invalid GraphQL syntax before transport', async () => {
    let invocationCount = 0;

    const adapter = new LinearTrackerAdapter({
        config: {
            apiKey: 'linear-token',
            projectSlug: 'forscherhaus',
            activeStates: ['In Progress'],
        },
        fetchImpl: async () => {
            invocationCount += 1;
            return jsonResponse({});
        },
    });

    const result = await adapter.executeLinearGraphQlToolCall({
        query: 'query Broken { viewer { id }',
    });

    assert.equal(result.success, false);
    assert.equal(invocationCount, 0);
    assert.equal((result.payload as {error?: string}).error, 'invalid_tool_input');
});

test('moveIssueToStateByName updates the Linear issue state by name', async () => {
    const calls: MockFetchCall[] = [];

    const adapter = new LinearTrackerAdapter({
        config: {
            apiKey: 'linear-token',
            projectSlug: 'forscherhaus',
            activeStates: ['In Progress'],
        },
        fetchImpl: async (url, init) => {
            calls.push({url, init});
            const body = JSON.parse(String(init?.body));
            const query = String(body.query ?? '');

            if (query.includes('query FetchIssueRunContext')) {
                return jsonResponse({
                    data: {
                        issue: {
                            id: 'issue-id-13',
                            identifier: 'ROB-13',
                            title: 'Publish handoff',
                            description: null,
                            branchName: 'beierrobin/rob-13',
                            url: 'https://linear.app/forscherhaus/issue/ROB-13',
                            createdAt: '2026-03-06T08:00:00.000Z',
                            updatedAt: '2026-03-06T08:05:00.000Z',
                            priority: null,
                            state: {id: 'state-in-progress', name: 'In Progress', type: 'started'},
                            labels: {nodes: []},
                            relations: {nodes: []},
                            inverseRelations: {nodes: []},
                            project: {slugId: 'forscherhaus'},
                            comments: {nodes: []},
                            team: {
                                states: {
                                    nodes: [
                                        {id: 'state-in-progress', name: 'In Progress', type: 'started'},
                                        {id: 'state-in-review', name: 'In Review', type: 'unstarted'},
                                    ],
                                },
                            },
                        },
                    },
                });
            }

            if (query.includes('mutation UpdateIssueState')) {
                assert.equal(body.variables.stateId, 'state-in-review');
                return jsonResponse({
                    data: {
                        issueUpdate: {
                            success: true,
                            issue: {
                                id: 'issue-id-13',
                                updatedAt: '2026-03-06T08:06:00.000Z',
                                state: {
                                    id: 'state-in-review',
                                    name: 'In Review',
                                },
                            },
                        },
                    },
                });
            }

            throw new Error(`Unexpected query: ${query}`);
        },
    });

    const movedIssue = await adapter.moveIssueToStateByName(
        {
            id: 'issue-id-13',
            identifier: 'ROB-13',
            title: 'Publish handoff',
            description: null,
            stateName: 'In Progress',
            stateType: 'started',
            priority: null,
            branchName: 'beierrobin/rob-13',
            url: 'https://linear.app/forscherhaus/issue/ROB-13',
            labels: [],
            blockedBy: [],
            blockedByIdentifiers: [],
            createdAt: '2026-03-06T08:00:00.000Z',
            updatedAt: '2026-03-06T08:05:00.000Z',
            projectSlug: 'forscherhaus',
            workpadCommentId: 'comment-1',
            workpadCommentBody: '## Codex Workpad',
            workpadCommentUrl: 'https://linear.app/comment-1',
        },
        'In Review',
    );

    assert.equal(movedIssue.stateName, 'In Review');
    assert.equal(movedIssue.updatedAt, '2026-03-06T08:06:00.000Z');
    assert.equal(calls.length, 2);
});
