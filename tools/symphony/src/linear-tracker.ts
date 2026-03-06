export type LinearTrackerErrorClass =
    | 'linear_api_status'
    | 'linear_graphql_errors'
    | 'linear_timeout'
    | 'linear_network'
    | 'linear_invalid_response'
    | 'linear_pagination_cursor_stuck';

export class LinearTrackerError extends Error {
    public readonly errorClass: LinearTrackerErrorClass;
    public readonly details: Record<string, unknown>;

    public constructor(errorClass: LinearTrackerErrorClass, message: string, details: Record<string, unknown> = {}) {
        super(message);
        this.name = 'LinearTrackerError';
        this.errorClass = errorClass;
        this.details = details;
    }
}

interface GraphQlPageInfo {
    hasNextPage: boolean;
    endCursor: string | null;
}

interface GraphQlConnection<NodeType> {
    nodes: NodeType[];
    pageInfo: GraphQlPageInfo;
}

interface GraphQlIssueNode {
    id: string;
    identifier: string;
    title: string;
    createdAt: string;
    updatedAt: string;
    priority: number | null;
    state?: {
        id: string;
        name: string;
        type?: string | null;
    } | null;
    labels?: {
        nodes?: Array<{
            name: string;
        }>;
    } | null;
    relations?: {
        nodes?: GraphQlIssueRelationNode[];
    } | null;
    inverseRelations?: {
        nodes?: GraphQlIssueRelationNode[];
    } | null;
    project?: {
        slugId?: string | null;
    } | null;
}

interface GraphQlIssueRelationNode {
    type?: string | null;
    issue?: {
        id?: string | null;
        identifier?: string | null;
    } | null;
    relatedIssue?: {
        id?: string | null;
        identifier?: string | null;
    } | null;
}

interface GraphQlIssueStateNode {
    id: string;
    identifier: string;
    updatedAt: string;
    state?: {
        id: string;
        name: string;
        type?: string | null;
    } | null;
}

interface GraphQlIssuesPage {
    issues: GraphQlConnection<GraphQlIssueNode>;
}

interface GraphQlIssueStatesPage {
    issues: GraphQlConnection<GraphQlIssueNode>;
}

interface GraphQlIssueStatesByIdsPage {
    issues: GraphQlConnection<GraphQlIssueStateNode>;
}

interface GraphQlErrorEntry {
    message?: string;
}

interface GraphQlResponse<DataType> {
    data?: DataType;
    errors?: GraphQlErrorEntry[];
}

export interface LinearTrackerConfig {
    apiKey: string;
    projectSlug: string;
    activeStates: string[];
    apiUrl?: string;
    timeoutMs?: number;
    pageSize?: number;
}

export interface TrackedIssue {
    id: string;
    identifier: string;
    title: string;
    stateName: string;
    stateType: string;
    priority: number;
    labels: string[];
    blockedByIdentifiers: string[];
    createdAt: string;
    updatedAt: string;
    projectSlug: string;
}

type FetchLike = (url: string, init?: RequestInit) => Promise<Response>;

interface LinearTrackerAdapterArgs {
    config: LinearTrackerConfig;
    fetchImpl?: FetchLike;
}

const LINEAR_API_URL = 'https://api.linear.app/graphql';
const DEFAULT_TIMEOUT_MS = 15000;
const DEFAULT_PAGE_SIZE = 50;

function asIsoTimestamp(timestamp: string, fieldName: string): string {
    if (!Number.isNaN(Date.parse(timestamp))) {
        return timestamp;
    }

    throw new LinearTrackerError('linear_invalid_response', `Invalid timestamp in Linear response for ${fieldName}.`);
}

function normalizeLabels(node: GraphQlIssueNode): string[] {
    const labels = node.labels?.nodes ?? [];
    return labels.map((label) => label.name.trim().toLowerCase()).filter((label) => label.length > 0);
}

function normalizeBlockedByIdentifiers(node: GraphQlIssueNode): string[] {
    const relationNodes: GraphQlIssueRelationNode[] = [
        ...(node.relations?.nodes ?? []),
        ...(node.inverseRelations?.nodes ?? []),
    ];
    const blockedByIdentifiers = new Set<string>();

    for (const relationNode of relationNodes) {
        if ((relationNode.type ?? '').trim().toLowerCase() !== 'blocks') {
            continue;
        }

        const blockerIssueId = relationNode.issue?.id?.trim() ?? '';
        const blockerIssueIdentifier = relationNode.issue?.identifier?.trim() ?? '';
        const blockedIssueId = relationNode.relatedIssue?.id?.trim() ?? '';
        const blockedIssueIdentifier = relationNode.relatedIssue?.identifier?.trim() ?? '';

        const isCurrentIssueBlocked =
            (blockedIssueId !== '' && blockedIssueId === node.id) ||
            (blockedIssueIdentifier !== '' && blockedIssueIdentifier === node.identifier);

        if (!isCurrentIssueBlocked || blockerIssueIdentifier === '') {
            continue;
        }

        if (blockerIssueId !== '' && blockerIssueId === node.id) {
            continue;
        }

        blockedByIdentifiers.add(blockerIssueIdentifier);
    }

    return Array.from(blockedByIdentifiers);
}

function normalizePriority(value: number | null): number {
    if (typeof value === 'number' && Number.isFinite(value)) {
        return value;
    }

    return 0;
}

function normalizeIssue(node: GraphQlIssueNode, fallbackProjectSlug: string): TrackedIssue {
    const projectSlug = node.project?.slugId ?? fallbackProjectSlug;
    if (projectSlug.trim() === '') {
        throw new LinearTrackerError('linear_invalid_response', 'Missing project slug in Linear issue payload.');
    }

    return {
        id: node.id,
        identifier: node.identifier,
        title: node.title,
        stateName: node.state?.name ?? '',
        stateType: node.state?.type ?? '',
        priority: normalizePriority(node.priority),
        labels: normalizeLabels(node),
        blockedByIdentifiers: normalizeBlockedByIdentifiers(node),
        createdAt: asIsoTimestamp(node.createdAt, 'createdAt'),
        updatedAt: asIsoTimestamp(node.updatedAt, 'updatedAt'),
        projectSlug,
    };
}

export class LinearTrackerAdapter {
    private readonly config: Required<LinearTrackerConfig>;
    private readonly fetchImpl: FetchLike;

    public constructor(args: LinearTrackerAdapterArgs) {
        this.config = {
            apiUrl: args.config.apiUrl ?? LINEAR_API_URL,
            timeoutMs: args.config.timeoutMs ?? DEFAULT_TIMEOUT_MS,
            pageSize: args.config.pageSize ?? DEFAULT_PAGE_SIZE,
            apiKey: args.config.apiKey,
            projectSlug: args.config.projectSlug,
            activeStates: args.config.activeStates,
        };
        this.fetchImpl = args.fetchImpl ?? fetch;
    }

    public async fetchCandidateIssues(): Promise<TrackedIssue[]> {
        return this.fetchIssuesByStates(this.config.activeStates, this.config.projectSlug);
    }

    public async fetchIssuesByStates(states: string[], projectSlug = this.config.projectSlug): Promise<TrackedIssue[]> {
        const trimmedStates = states.map((stateName) => stateName.trim()).filter((stateName) => stateName.length > 0);
        if (trimmedStates.length === 0) {
            return [];
        }

        const allIssues: TrackedIssue[] = [];
        const seenCursors = new Set<string>();
        let afterCursor: string | null = null;

        while (true) {
            const page: GraphQlIssuesPage = await this.executeGraphQl<GraphQlIssuesPage>(
                `
                query FetchIssuesByState($projectSlugId: String!, $stateNames: [String!], $first: Int!, $after: String) {
                    issues(
                        filter: {
                            project: { slugId: { eq: $projectSlugId } }
                            state: { name: { in: $stateNames } }
                        }
                        first: $first
                        after: $after
                    ) {
                        nodes {
                            id
                            identifier
                            title
                            createdAt
                            updatedAt
                            priority
                            state {
                                id
                                name
                                type
                            }
                            labels {
                                nodes {
                                    name
                                }
                            }
                            relations {
                                nodes {
                                    type
                                    issue {
                                        id
                                        identifier
                                    }
                                    relatedIssue {
                                        id
                                        identifier
                                    }
                                }
                            }
                            inverseRelations {
                                nodes {
                                    type
                                    issue {
                                        id
                                        identifier
                                    }
                                    relatedIssue {
                                        id
                                        identifier
                                    }
                                }
                            }
                            project {
                                slugId
                            }
                        }
                        pageInfo {
                            hasNextPage
                            endCursor
                        }
                    }
                }
                `,
                {
                    projectSlugId: projectSlug,
                    stateNames: trimmedStates,
                    first: this.config.pageSize,
                    after: afterCursor,
                },
            );

            const connection: GraphQlConnection<GraphQlIssueNode> = page.issues;
            const normalizedIssues: TrackedIssue[] = connection.nodes.map((node: GraphQlIssueNode) =>
                normalizeIssue(node, projectSlug),
            );
            allIssues.push(...normalizedIssues);

            if (!connection.pageInfo.hasNextPage) {
                break;
            }

            const endCursor: string | null = connection.pageInfo.endCursor;
            if (!endCursor) {
                throw new LinearTrackerError(
                    'linear_invalid_response',
                    'Linear pagination has next page but no endCursor.',
                );
            }

            if (seenCursors.has(endCursor)) {
                throw new LinearTrackerError(
                    'linear_pagination_cursor_stuck',
                    `Linear pagination cursor repeated: ${endCursor}`,
                    {
                        cursor: endCursor,
                        projectSlug,
                    },
                );
            }

            seenCursors.add(endCursor);
            afterCursor = endCursor;
        }

        return allIssues;
    }

    public async fetchIssueStatesByIds(issueIds: string[]): Promise<Map<string, string>> {
        const trimmedIds = issueIds.map((issueId) => issueId.trim()).filter((issueId) => issueId.length > 0);
        if (trimmedIds.length === 0) {
            return new Map();
        }

        const page = await this.executeGraphQl<GraphQlIssueStatesByIdsPage>(
            `
            query FetchIssueStatesByIds($ids: [ID!], $first: Int!) {
                issues(filter: { id: { in: $ids } }, first: $first) {
                    nodes {
                        id
                        identifier
                        updatedAt
                        state {
                            id
                            name
                            type
                        }
                    }
                    pageInfo {
                        hasNextPage
                        endCursor
                    }
                }
            }
            `,
            {
                ids: trimmedIds,
                first: Math.max(trimmedIds.length, this.config.pageSize),
            },
        );

        const statesById = new Map<string, string>();
        for (const node of page.issues.nodes) {
            if (node.id.trim() === '') {
                throw new LinearTrackerError(
                    'linear_invalid_response',
                    'Linear issue state payload contains empty id.',
                );
            }

            statesById.set(node.id, node.state?.name ?? '');
        }

        return statesById;
    }

    public async fetchIssueStatesByStateNames(stateNames: string[]): Promise<TrackedIssue[]> {
        const states = stateNames.map((stateName) => stateName.trim()).filter((stateName) => stateName.length > 0);
        if (states.length === 0) {
            return [];
        }

        const page = await this.executeGraphQl<GraphQlIssueStatesPage>(
            `
            query FetchIssueStatesByStateNames($projectSlugId: String!, $stateNames: [String!], $first: Int!) {
                issues(
                    filter: {
                        project: { slugId: { eq: $projectSlugId } }
                        state: { name: { in: $stateNames } }
                    }
                    first: $first
                ) {
                    nodes {
                        id
                        identifier
                        title
                        createdAt
                        updatedAt
                        priority
                        state {
                            id
                            name
                            type
                        }
                        labels {
                            nodes {
                                name
                            }
                        }
                        relations {
                            nodes {
                                type
                                issue {
                                    id
                                    identifier
                                }
                                relatedIssue {
                                    id
                                    identifier
                                }
                            }
                        }
                        inverseRelations {
                            nodes {
                                type
                                issue {
                                    id
                                    identifier
                                }
                                relatedIssue {
                                    id
                                    identifier
                                }
                            }
                        }
                        project {
                            slugId
                        }
                    }
                    pageInfo {
                        hasNextPage
                        endCursor
                    }
                }
            }
            `,
            {
                projectSlugId: this.config.projectSlug,
                stateNames: states,
                first: this.config.pageSize,
            },
        );

        return page.issues.nodes.map((node) => normalizeIssue(node, this.config.projectSlug));
    }

    private async executeGraphQl<ResponseType>(
        query: string,
        variables: Record<string, unknown>,
    ): Promise<ResponseType> {
        const controller = new AbortController();
        const timeoutHandle = setTimeout(() => {
            controller.abort();
        }, this.config.timeoutMs);

        let response: Response;

        try {
            response = await this.fetchImpl(this.config.apiUrl, {
                method: 'POST',
                headers: {
                    Authorization: this.config.apiKey,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({query, variables}),
                signal: controller.signal,
            });
        } catch (error) {
            clearTimeout(timeoutHandle);

            if (error instanceof Error && error.name === 'AbortError') {
                throw new LinearTrackerError(
                    'linear_timeout',
                    `Linear GraphQL request timed out after ${this.config.timeoutMs}ms.`,
                    {
                        timeoutMs: this.config.timeoutMs,
                    },
                );
            }

            throw new LinearTrackerError('linear_network', 'Linear GraphQL request failed.', {
                reason: error instanceof Error ? error.message : String(error),
            });
        }

        clearTimeout(timeoutHandle);

        if (!response.ok) {
            const details = await this.extractApiStatusErrorDetails(response);
            const summary = this.summarizeApiStatusError(details);
            const message =
                summary.length > 0
                    ? `Linear API returned status ${response.status}: ${summary}`
                    : `Linear API returned status ${response.status}.`;

            throw new LinearTrackerError('linear_api_status', message, details);
        }

        let payload: GraphQlResponse<ResponseType>;
        try {
            payload = (await response.json()) as GraphQlResponse<ResponseType>;
        } catch {
            throw new LinearTrackerError('linear_invalid_response', 'Linear GraphQL response is not valid JSON.');
        }

        if (payload.errors && payload.errors.length > 0) {
            throw new LinearTrackerError('linear_graphql_errors', 'Linear GraphQL responded with errors.', {
                errors: payload.errors.map((entry) => entry.message ?? 'unknown GraphQL error'),
            });
        }

        if (!payload.data) {
            throw new LinearTrackerError('linear_invalid_response', 'Linear GraphQL response missing data payload.');
        }

        return payload.data;
    }

    private async extractApiStatusErrorDetails(response: Response): Promise<Record<string, unknown>> {
        const details: Record<string, unknown> = {
            status: response.status,
            statusText: response.statusText,
        };

        const requestId = response.headers.get('x-request-id');
        if (requestId) {
            details.requestId = requestId;
        }

        let rawBody = '';
        try {
            rawBody = await response.text();
        } catch {
            return details;
        }

        if (rawBody.trim() === '') {
            return details;
        }

        details.responseBody = rawBody.length > 2000 ? `${rawBody.slice(0, 2000)}...` : rawBody;

        try {
            const parsedPayload = JSON.parse(rawBody) as GraphQlResponse<unknown>;
            if (parsedPayload.errors && parsedPayload.errors.length > 0) {
                details.errors = parsedPayload.errors.map((entry) => entry.message ?? 'unknown GraphQL error');
            }
        } catch {
            // Keep raw body details only when response is not JSON.
        }

        return details;
    }

    private summarizeApiStatusError(details: Record<string, unknown>): string {
        const errorMessages = Array.isArray(details.errors)
            ? details.errors.filter((entry): entry is string => typeof entry === 'string' && entry.trim().length > 0)
            : [];

        if (errorMessages.length > 0) {
            return errorMessages.join(' | ');
        }

        if (typeof details.responseBody === 'string' && details.responseBody.trim().length > 0) {
            return details.responseBody;
        }

        return '';
    }
}
