import {parse} from 'graphql';
import {
    GitHubPrAutoResumePolicyClient,
    type PullRequestAutoResumeDecision,
    type PullRequestAutoResumePolicy,
} from './github-pr-auto-resume.js';

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
    description?: string | null;
    branchName?: string | null;
    url?: string | null;
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
        state?: {
            name?: string | null;
        } | null;
    } | null;
    relatedIssue?: {
        id?: string | null;
        identifier?: string | null;
        state?: {
            name?: string | null;
        } | null;
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

interface GraphQlCommentNode {
    id: string;
    body?: string | null;
    url?: string | null;
    updatedAt: string;
}

interface GraphQlTeamStateNode {
    id: string;
    name: string;
    type?: string | null;
}

interface GraphQlIssueRunContext {
    issue:
        | (GraphQlIssueNode & {
              comments?: {
                  nodes?: GraphQlCommentNode[];
              } | null;
              team?: {
                  states?: {
                      nodes?: GraphQlTeamStateNode[];
                  } | null;
              } | null;
          })
        | null;
}

interface GraphQlIssueStateUpdateResult {
    issueUpdate?: {
        success?: boolean | null;
        issue?: {
            id?: string | null;
            updatedAt?: string | null;
            state?: {
                id?: string | null;
                name?: string | null;
            } | null;
        } | null;
    } | null;
}

interface GraphQlCommentCreateResult {
    commentCreate?: {
        success?: boolean | null;
        comment?: {
            id?: string | null;
            url?: string | null;
            body?: string | null;
        } | null;
    } | null;
}

interface GraphQlCommentUpdateResult {
    commentUpdate?: {
        success?: boolean | null;
        comment?: {
            id?: string | null;
            url?: string | null;
            body?: string | null;
        } | null;
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
    terminalStates?: string[];
    reviewStateName?: string;
    mergeStateName?: string;
    apiUrl?: string;
    timeoutMs?: number;
    pageSize?: number;
}

export interface TrackedIssue {
    id: string;
    identifier: string;
    title: string;
    description: string | null;
    stateName: string;
    stateType: string;
    priority: number | null;
    branchName: string | null;
    url: string | null;
    labels: string[];
    blockedBy: Array<{
        id: string | null;
        identifier: string | null;
        state: string | null;
    }>;
    blockedByIdentifiers: string[];
    createdAt: string;
    updatedAt: string;
    projectSlug: string;
    workpadCommentId: string | null;
    workpadCommentBody: string | null;
    workpadCommentUrl: string | null;
}

type FetchLike = (url: string, init?: RequestInit) => Promise<Response>;

interface LinearTrackerAdapterArgs {
    config: LinearTrackerConfig;
    fetchImpl?: FetchLike;
    pullRequestPolicyClient?: PullRequestAutoResumePolicy;
}

const LINEAR_API_URL = 'https://api.linear.app/graphql';
const DEFAULT_TIMEOUT_MS = 30000;
const DEFAULT_PAGE_SIZE = 50;
const DEFAULT_TERMINAL_STATES = ['Done', 'Closed', 'Cancelled', 'Canceled', 'Duplicate'];

function asOptionalTrimmedString(value: unknown): string | null {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmedValue = value.trim();
    return trimmedValue.length > 0 ? trimmedValue : null;
}

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

function normalizeBlockedBy(node: GraphQlIssueNode): {
    blockedBy: TrackedIssue['blockedBy'];
    blockedByIdentifiers: string[];
} {
    const relationNodes: GraphQlIssueRelationNode[] = [
        ...(node.relations?.nodes ?? []),
        ...(node.inverseRelations?.nodes ?? []),
    ];
    const blockedBy: TrackedIssue['blockedBy'] = [];
    const blockedByIdentifiers = new Set<string>();
    const seenBlockers = new Set<string>();

    for (const relationNode of relationNodes) {
        if ((relationNode.type ?? '').trim().toLowerCase() !== 'blocks') {
            continue;
        }

        const blockerIssueId = asOptionalTrimmedString(relationNode.issue?.id);
        const blockerIssueIdentifier = asOptionalTrimmedString(relationNode.issue?.identifier);
        const blockerIssueState = asOptionalTrimmedString(relationNode.issue?.state?.name);
        const blockedIssueId = asOptionalTrimmedString(relationNode.relatedIssue?.id);
        const blockedIssueIdentifier = asOptionalTrimmedString(relationNode.relatedIssue?.identifier);

        const isCurrentIssueBlocked =
            (blockedIssueId !== null && blockedIssueId === node.id) ||
            (blockedIssueIdentifier !== null && blockedIssueIdentifier === node.identifier);

        if (!isCurrentIssueBlocked) {
            continue;
        }

        if (blockerIssueId !== null && blockerIssueId === node.id) {
            continue;
        }

        if (blockerIssueId === null && blockerIssueIdentifier === null) {
            continue;
        }

        const blockerKey = `${blockerIssueId ?? ''}:${blockerIssueIdentifier ?? ''}`;
        if (seenBlockers.has(blockerKey)) {
            continue;
        }

        seenBlockers.add(blockerKey);
        blockedBy.push({
            id: blockerIssueId,
            identifier: blockerIssueIdentifier,
            state: blockerIssueState,
        });

        if (blockerIssueIdentifier !== null) {
            blockedByIdentifiers.add(blockerIssueIdentifier);
        }
    }

    return {
        blockedBy,
        blockedByIdentifiers: Array.from(blockedByIdentifiers),
    };
}

function normalizePriority(value: number | null): number | null {
    if (typeof value === 'number' && Number.isInteger(value)) {
        return value;
    }

    return null;
}

function normalizeIssue(node: GraphQlIssueNode, fallbackProjectSlug: string): TrackedIssue {
    const projectSlug = node.project?.slugId ?? fallbackProjectSlug;
    if (projectSlug.trim() === '') {
        throw new LinearTrackerError('linear_invalid_response', 'Missing project slug in Linear issue payload.');
    }

    const blockers = normalizeBlockedBy(node);

    return {
        id: node.id,
        identifier: node.identifier,
        title: node.title,
        description: asOptionalTrimmedString(node.description),
        stateName: node.state?.name ?? '',
        stateType: node.state?.type ?? '',
        priority: normalizePriority(node.priority),
        branchName: asOptionalTrimmedString(node.branchName),
        url: asOptionalTrimmedString(node.url),
        labels: normalizeLabels(node),
        blockedBy: blockers.blockedBy,
        blockedByIdentifiers: blockers.blockedByIdentifiers,
        createdAt: asIsoTimestamp(node.createdAt, 'createdAt'),
        updatedAt: asIsoTimestamp(node.updatedAt, 'updatedAt'),
        projectSlug,
        workpadCommentId: null,
        workpadCommentBody: null,
        workpadCommentUrl: null,
    };
}

function normalizeCommentBody(value: unknown): string | null {
    if (typeof value !== 'string') {
        return null;
    }

    return value.replace(/\r\n/g, '\n').trim();
}

function isWorkpadComment(comment: GraphQlCommentNode): boolean {
    const body = normalizeCommentBody(comment.body);
    return body !== null && body.startsWith('## Codex Workpad');
}

function compareCommentsByUpdatedAtDescending(left: GraphQlCommentNode, right: GraphQlCommentNode): number {
    return Date.parse(right.updatedAt) - Date.parse(left.updatedAt);
}

function normalizeHeadingName(value: string): string {
    return value.trim().toLowerCase().replace(/\s+/g, ' ');
}

function normalizeTerminalStates(values: string[]): Set<string> {
    return new Set(values.map((value) => normalizeHeadingName(value)).filter((value) => value.length > 0));
}

function hasOutstandingBlockers(
    issue: Pick<TrackedIssue, 'blockedBy'>,
    terminalStates: Set<string>,
): Array<{id: string | null; identifier: string | null; state: string | null}> {
    return issue.blockedBy.filter((blocker) => {
        const hasIdentity =
            (typeof blocker.id === 'string' && blocker.id.trim().length > 0) ||
            (typeof blocker.identifier === 'string' && blocker.identifier.trim().length > 0);

        if (!hasIdentity) {
            return false;
        }

        const normalizedBlockerState = normalizeHeadingName(blocker.state ?? '');
        if (normalizedBlockerState.length === 0) {
            return true;
        }

        return !terminalStates.has(normalizedBlockerState);
    });
}

function parseDescriptionSections(description: string | null): Map<string, string[]> {
    const sections = new Map<string, string[]>();
    let currentSection = '__root__';
    sections.set(currentSection, []);

    if (!description) {
        return sections;
    }

    for (const line of description.replace(/\r\n/g, '\n').split('\n')) {
        const headingMatch = line.match(/^#{1,6}\s+(.+?)\s*$/);
        if (headingMatch) {
            currentSection = normalizeHeadingName(headingMatch[1]);
            if (!sections.has(currentSection)) {
                sections.set(currentSection, []);
            }
            continue;
        }

        sections.get(currentSection)?.push(line);
    }

    return sections;
}

function extractChecklistItems(lines: string[]): string[] {
    const items: string[] = [];

    for (const rawLine of lines) {
        const line = rawLine.trim();
        if (line.length === 0 || line.startsWith('```')) {
            continue;
        }

        const bulletMatch = line.match(/^[-*+]\s+(.*)$/);
        if (bulletMatch) {
            items.push(bulletMatch[1].trim());
            continue;
        }

        const numberedMatch = line.match(/^\d+[.)]\s+(.*)$/);
        if (numberedMatch) {
            items.push(numberedMatch[1].trim());
        }
    }

    return items;
}

function extractParagraphLines(lines: string[]): string[] {
    const items: string[] = [];

    for (const rawLine of lines) {
        const line = rawLine.trim();
        if (line.length === 0 || line.startsWith('```') || /^[-*+]\s+/.test(line) || /^\d+[.)]\s+/.test(line)) {
            continue;
        }

        items.push(line);
    }

    return items;
}

function collectSectionContent(
    sections: Map<string, string[]>,
    sectionNames: string[],
    extractor: (lines: string[]) => string[],
): string[] {
    const collected: string[] = [];

    for (const sectionName of sectionNames) {
        const key = sectionName === '__root__' ? '__root__' : normalizeHeadingName(sectionName);
        collected.push(...extractor(sections.get(key) ?? []));
    }

    return collected;
}

function dedupeAndLimit(items: string[], limit: number): string[] {
    const seen = new Set<string>();
    const result: string[] = [];

    for (const rawItem of items) {
        const item = rawItem.trim().replace(/\s+/g, ' ');
        if (item.length === 0) {
            continue;
        }

        const key = item.toLowerCase();
        if (seen.has(key)) {
            continue;
        }

        seen.add(key);
        result.push(item);

        if (result.length >= limit) {
            break;
        }
    }

    return result;
}

function asChecklistLines(items: string[]): string[] {
    return items.map((item) => `- [ ] ${item}`);
}

function shouldRefreshBootstrapWorkpad(body: string | null): boolean {
    if (body === null) {
        return false;
    }

    return (
        body.startsWith('## Codex Workpad') &&
        body.includes('Summary: Starting Symphony run for') &&
        body.includes('Reconcile the issue scope against the current workspace state.') &&
        !body.includes('### Acceptance Criteria')
    );
}

function buildBootstrapWorkpad(issue: TrackedIssue): string {
    const sections = parseDescriptionSections(issue.description);
    const goalSummary = dedupeAndLimit(
        collectSectionContent(sections, ['Goal', 'Problem', 'Context'], extractParagraphLines),
        1,
    )[0];
    const scopeItems = dedupeAndLimit(
        collectSectionContent(sections, ['Scope', 'Implementation Scope'], extractChecklistItems),
        4,
    );
    const acceptanceItems = dedupeAndLimit(
        collectSectionContent(sections, ['Definition of Done', 'Acceptance Criteria'], extractChecklistItems),
        5,
    );
    const validationItems = dedupeAndLimit(
        collectSectionContent(sections, ['Validation', 'Test Plan', 'Testing'], extractChecklistItems),
        4,
    );
    const noteItems = dedupeAndLimit(
        [
            ...collectSectionContent(sections, ['Goal', 'Problem', 'Context'], extractParagraphLines),
            ...collectSectionContent(sections, ['__root__'], extractParagraphLines),
        ],
        2,
    );
    const planItems =
        scopeItems.length > 0
            ? scopeItems
            : [
                  `Deliver the scoped change for ${issue.identifier}.`,
                  'Verify the result with the narrowest relevant check.',
              ];
    const criteriaItems =
        acceptanceItems.length > 0
            ? acceptanceItems
            : scopeItems.length > 0
              ? scopeItems
              : ['Keep the change scoped to the ticket and avoid unrelated edits.'];
    const workpadValidationItems =
        validationItems.length > 0 ? validationItems : ['Run the narrowest relevant check for the current diff.'];
    const nextAction = planItems[0] ?? `Deliver the scoped change for ${issue.identifier}.`;
    const summary = goalSummary ?? `Starting Symphony run for ${issue.identifier}: ${issue.title}.`;

    return [
        '## Codex Workpad',
        '',
        '### Status',
        `- Summary: ${summary}`,
        `- Next: ${nextAction}`,
        '',
        '### Plan',
        ...asChecklistLines(planItems),
        '',
        '### Acceptance Criteria',
        ...asChecklistLines(criteriaItems),
        '',
        '### Validation',
        ...asChecklistLines(workpadValidationItems),
        '',
        '### Notes',
        ...(noteItems.length > 0 ? noteItems.map((item) => `- ${item}`) : ['- No extra notes yet.']),
        '',
        '### Blockers',
        '- None.',
    ].join('\n');
}

function buildStateSynchronizedWorkpad(issue: TrackedIssue, terminalStateNames: string[]): string {
    const sections = parseDescriptionSections(issue.description);
    const acceptanceItems = dedupeAndLimit(
        collectSectionContent(sections, ['Definition of Done', 'Acceptance Criteria'], extractChecklistItems),
        5,
    );
    const noteItems = dedupeAndLimit(
        [
            ...collectSectionContent(sections, ['Goal', 'Problem', 'Context'], extractParagraphLines),
            ...collectSectionContent(sections, ['__root__'], extractParagraphLines),
        ],
        2,
    );

    const normalizedState = normalizeHeadingName(issue.stateName);
    const isReviewState = normalizedState === 'in review';
    const isReadyToMergeState = normalizedState === 'ready to merge';
    const normalizedTerminalStates = new Set(terminalStateNames.map((state) => normalizeHeadingName(state)));
    const isTerminalState = normalizedTerminalStates.has(normalizedState);

    if (!isReviewState && !isReadyToMergeState && !isTerminalState) {
        return buildBootstrapWorkpad(issue);
    }

    const criteriaItems =
        acceptanceItems.length > 0
            ? acceptanceItems
            : ['Keep the change scoped to the ticket and avoid unrelated edits.'];
    const statusSummary = isReviewState
        ? `The PR is published and ${issue.identifier} is waiting in \`${issue.stateName}\`.`
        : isReadyToMergeState
          ? `The PR is published and ${issue.identifier} is cleared for \`${issue.stateName}\`.`
          : `${issue.identifier} is complete in \`${issue.stateName}\`.`;
    const nextLine = isReviewState
        ? 'Symphony may move the issue to `Ready to Merge` automatically when the published PR is open, green, mergeable, and free of fresh trusted review feedback; otherwise wait for explicit human steering.'
        : isReadyToMergeState
          ? 'Resume the land flow now and merge once the final PR watch stays green.'
          : 'None.';
    const planItems = isReviewState
        ? [
              'Keep the PR parked while CI and review signals settle.',
              'Resume the land flow when policy signals or explicit human steering move the issue to `Ready to Merge`.',
          ]
        : isReadyToMergeState
          ? ['Re-enter the land flow from the current PR state.', 'Merge the PR and move the issue to `Done`.']
          : ['No further action.'];
    const validationItems = isReviewState
        ? [
              `Issue state is now \`${issue.stateName}\`.`,
              'PR publish and review handoff completed.',
              'Automatic resume requires a merge-clean PR watcher snapshot.',
          ]
        : isReadyToMergeState
          ? [
                `Issue state is now \`${issue.stateName}\`.`,
                'PR is already published and waiting for the land flow.',
                'PR watcher signals are green enough to resume landing.',
            ]
          : [`Issue state is now \`${issue.stateName}\`.`, 'Merge/closure handoff completed.'];

    return [
        '## Codex Workpad',
        '',
        '### Status',
        `- Summary: ${statusSummary}`,
        `- Next: ${nextLine}`,
        '',
        '### Plan',
        ...asChecklistLines(planItems),
        '',
        '### Acceptance Criteria',
        ...asChecklistLines(criteriaItems),
        '',
        '### Validation',
        ...validationItems.map((item) => `- Done: ${item}`),
        '',
        '### Notes',
        ...(noteItems.length > 0 ? noteItems.map((item) => `- ${item}`) : ['- No extra notes yet.']),
        '',
        '### Blockers',
        '- None.',
    ].join('\n');
}

function validateGraphQlDocument(document: string):
    | {
          ok: true;
      }
    | {
          ok: false;
          message: string;
      } {
    try {
        parse(document);

        return {
            ok: true,
        };
    } catch (error) {
        return {
            ok: false,
            message: `linear_graphql query is not valid GraphQL: ${
                error instanceof Error ? error.message : String(error)
            }`,
        };
    }
}

function normalizeLinearGraphQlToolInput(rawArguments: unknown):
    | {
          ok: true;
          payload: {
              query: string;
              variables: Record<string, unknown>;
          };
      }
    | {
          ok: false;
          payload: Record<string, unknown>;
      } {
    if (typeof rawArguments === 'string') {
        const query = rawArguments.trim();
        if (query.length === 0) {
            return {
                ok: false,
                payload: {
                    error: 'invalid_tool_input',
                    message: 'linear_graphql query must be a non-empty string.',
                },
            };
        }

        const validation = validateGraphQlDocument(query);
        if (!validation.ok) {
            return {
                ok: false,
                payload: {
                    error: 'invalid_tool_input',
                    message: validation.message,
                },
            };
        }

        return {
            ok: true,
            payload: {
                query,
                variables: {},
            },
        };
    }

    if (!rawArguments || typeof rawArguments !== 'object' || Array.isArray(rawArguments)) {
        return {
            ok: false,
            payload: {
                error: 'invalid_tool_input',
                message: 'linear_graphql arguments must be a query string or an object with query/variables.',
            },
        };
    }

    const record = rawArguments as Record<string, unknown>;
    const query = typeof record.query === 'string' ? record.query.trim() : '';
    if (query.length === 0) {
        return {
            ok: false,
            payload: {
                error: 'invalid_tool_input',
                message: 'linear_graphql.query must be a non-empty string.',
            },
        };
    }

    const validation = validateGraphQlDocument(query);
    if (!validation.ok) {
        return {
            ok: false,
            payload: {
                error: 'invalid_tool_input',
                message: validation.message,
            },
        };
    }

    const rawVariables = record.variables;
    if (
        rawVariables !== undefined &&
        (rawVariables === null || typeof rawVariables !== 'object' || Array.isArray(rawVariables))
    ) {
        return {
            ok: false,
            payload: {
                error: 'invalid_tool_input',
                message: 'linear_graphql.variables must be an object when provided.',
            },
        };
    }

    return {
        ok: true,
        payload: {
            query,
            variables: (rawVariables as Record<string, unknown> | undefined) ?? {},
        },
    };
}

export class LinearTrackerAdapter {
    private readonly config: Required<LinearTrackerConfig>;
    private readonly fetchImpl: FetchLike;
    private readonly pullRequestPolicyClient: PullRequestAutoResumePolicy;

    public constructor(args: LinearTrackerAdapterArgs) {
        this.config = {
            apiUrl: args.config.apiUrl ?? LINEAR_API_URL,
            timeoutMs: args.config.timeoutMs ?? DEFAULT_TIMEOUT_MS,
            pageSize: args.config.pageSize ?? DEFAULT_PAGE_SIZE,
            apiKey: args.config.apiKey,
            projectSlug: args.config.projectSlug,
            activeStates: args.config.activeStates,
            terminalStates: args.config.terminalStates ?? DEFAULT_TERMINAL_STATES,
            reviewStateName: args.config.reviewStateName ?? 'In Review',
            mergeStateName: args.config.mergeStateName ?? 'Ready to Merge',
        };
        this.fetchImpl = args.fetchImpl ?? fetch;
        this.pullRequestPolicyClient = args.pullRequestPolicyClient ?? new GitHubPrAutoResumePolicyClient();
    }

    public async fetchCandidateIssues(): Promise<TrackedIssue[]> {
        const activeIssues = await this.fetchIssuesByStates(this.config.activeStates, this.config.projectSlug);
        const promotedIssues = await this.autoPromoteReviewReadyIssues();
        const issuesById = new Map<string, TrackedIssue>();

        for (const issue of [...activeIssues, ...promotedIssues]) {
            issuesById.set(issue.id, issue);
        }

        return Array.from(issuesById.values());
    }

    public async prepareIssueForRun(issue: TrackedIssue): Promise<TrackedIssue> {
        const runContext = await this.fetchIssueRunContext(issue.id);
        const fetchedIssue = runContext.issue;
        if (!fetchedIssue) {
            throw new LinearTrackerError('linear_invalid_response', `Linear issue payload missing issue ${issue.id}.`);
        }

        const normalizedIssue = normalizeIssue(fetchedIssue, this.config.projectSlug);
        let preparedIssue = normalizedIssue;

        if ((normalizedIssue.stateName ?? '').trim().toLowerCase() === 'todo') {
            preparedIssue = await this.updateIssueStateByName(normalizedIssue, runContext, 'In Progress');
        }

        const existingWorkpad = this.selectWorkpadComment(fetchedIssue.comments?.nodes ?? []);
        if (existingWorkpad) {
            const existingBody = normalizeCommentBody(existingWorkpad.body);
            if (shouldRefreshBootstrapWorkpad(existingBody)) {
                const refreshedWorkpad = await this.updateIssueComment(
                    existingWorkpad.id,
                    buildBootstrapWorkpad(preparedIssue),
                );

                return {
                    ...preparedIssue,
                    workpadCommentId: refreshedWorkpad.id,
                    workpadCommentBody: refreshedWorkpad.body,
                    workpadCommentUrl: refreshedWorkpad.url,
                };
            }

            return {
                ...preparedIssue,
                workpadCommentId: existingWorkpad.id,
                workpadCommentBody: existingBody,
                workpadCommentUrl: asOptionalTrimmedString(existingWorkpad.url),
            };
        }

        const createdWorkpad = await this.createIssueComment(preparedIssue.id, buildBootstrapWorkpad(preparedIssue));
        return {
            ...preparedIssue,
            workpadCommentId: createdWorkpad.id,
            workpadCommentBody: createdWorkpad.body,
            workpadCommentUrl: createdWorkpad.url,
        };
    }

    public async moveIssueToStateByName(issue: TrackedIssue, stateName: string): Promise<TrackedIssue> {
        const runContext = await this.fetchIssueRunContext(issue.id);
        return await this.updateIssueStateByName(issue, runContext, stateName);
    }

    public async syncIssueWorkpadToState(issue: TrackedIssue): Promise<TrackedIssue> {
        const runContext = await this.fetchIssueRunContext(issue.id);
        const fetchedIssue = runContext.issue;
        if (!fetchedIssue) {
            throw new LinearTrackerError('linear_invalid_response', `Linear issue payload missing issue ${issue.id}.`);
        }

        const normalizedIssue = normalizeIssue(fetchedIssue, this.config.projectSlug);
        const currentIssue = {
            ...normalizedIssue,
            stateName: issue.stateName,
        };
        const workpadBody = buildStateSynchronizedWorkpad(currentIssue, this.config.terminalStates);
        const existingWorkpad = this.selectWorkpadComment(fetchedIssue.comments?.nodes ?? []);

        if (existingWorkpad) {
            const updatedWorkpad = await this.updateIssueComment(existingWorkpad.id, workpadBody);
            return {
                ...currentIssue,
                workpadCommentId: updatedWorkpad.id,
                workpadCommentBody: updatedWorkpad.body,
                workpadCommentUrl: updatedWorkpad.url,
            };
        }

        const createdWorkpad = await this.createIssueComment(currentIssue.id, workpadBody);
        return {
            ...currentIssue,
            workpadCommentId: createdWorkpad.id,
            workpadCommentBody: createdWorkpad.body,
            workpadCommentUrl: createdWorkpad.url,
        };
    }

    public async executeLinearGraphQlToolCall(rawArguments: unknown): Promise<{
        success: boolean;
        payload: Record<string, unknown>;
    }> {
        const normalizedInput = normalizeLinearGraphQlToolInput(rawArguments);
        if (!normalizedInput.ok) {
            return {
                success: false,
                payload: normalizedInput.payload,
            };
        }

        const {query, variables} = normalizedInput.payload;
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
                return {
                    success: false,
                    payload: {
                        error: 'linear_timeout',
                        message: `Linear GraphQL request timed out after ${this.config.timeoutMs}ms.`,
                        timeoutMs: this.config.timeoutMs,
                    },
                };
            }

            return {
                success: false,
                payload: {
                    error: 'linear_network',
                    message: 'Linear GraphQL request failed.',
                    reason: error instanceof Error ? error.message : String(error),
                },
            };
        }

        clearTimeout(timeoutHandle);

        if (!response.ok) {
            const details = await this.extractApiStatusErrorDetails(response);
            return {
                success: false,
                payload: {
                    error: 'linear_api_status',
                    ...details,
                },
            };
        }

        let payload: GraphQlResponse<unknown>;
        try {
            payload = (await response.json()) as GraphQlResponse<unknown>;
        } catch {
            return {
                success: false,
                payload: {
                    error: 'linear_invalid_response',
                    message: 'Linear GraphQL response is not valid JSON.',
                },
            };
        }

        if (payload.errors && payload.errors.length > 0) {
            return {
                success: false,
                payload: payload as Record<string, unknown>,
            };
        }

        if (!('data' in payload)) {
            return {
                success: false,
                payload: {
                    error: 'linear_invalid_response',
                    message: 'Linear GraphQL response missing data payload.',
                },
            };
        }

        return {
            success: true,
            payload: payload as Record<string, unknown>,
        };
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
                            description
                            branchName
                            url
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
                                        state {
                                            name
                                        }
                                    }
                                    relatedIssue {
                                        id
                                        identifier
                                        state {
                                            name
                                        }
                                    }
                                }
                            }
                            inverseRelations {
                                nodes {
                                    type
                                    issue {
                                        id
                                        identifier
                                        state {
                                            name
                                        }
                                    }
                                    relatedIssue {
                                        id
                                        identifier
                                        state {
                                            name
                                        }
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
                        description
                        branchName
                        url
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
                                    state {
                                        name
                                    }
                                }
                                relatedIssue {
                                    id
                                    identifier
                                    state {
                                        name
                                    }
                                }
                            }
                        }
                        inverseRelations {
                            nodes {
                                type
                                issue {
                                    id
                                    identifier
                                    state {
                                        name
                                    }
                                }
                                relatedIssue {
                                    id
                                    identifier
                                    state {
                                        name
                                    }
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

    private async autoPromoteReviewReadyIssues(): Promise<TrackedIssue[]> {
        const normalizedReviewState = this.config.reviewStateName.trim().toLowerCase();
        const normalizedMergeState = this.config.mergeStateName.trim().toLowerCase();
        if (
            normalizedReviewState.length === 0 ||
            normalizedMergeState.length === 0 ||
            normalizedReviewState === normalizedMergeState
        ) {
            return [];
        }

        const reviewIssues = await this.fetchIssuesByStates([this.config.reviewStateName], this.config.projectSlug);
        const promotedIssues: TrackedIssue[] = [];
        const terminalStates = normalizeTerminalStates(this.config.terminalStates);

        for (const reviewIssue of reviewIssues) {
            if (hasOutstandingBlockers(reviewIssue, terminalStates).length > 0) {
                continue;
            }

            let decision: PullRequestAutoResumeDecision;
            try {
                decision = await this.pullRequestPolicyClient.evaluateAutoResume({
                    identifier: reviewIssue.identifier,
                    branchName: reviewIssue.branchName,
                });
            } catch {
                continue;
            }

            if (!decision.shouldPromote) {
                continue;
            }

            const promotedIssue = await this.moveIssueToStateByName(reviewIssue, this.config.mergeStateName);
            promotedIssues.push(await this.syncIssueWorkpadToState(promotedIssue));
        }

        return promotedIssues;
    }

    private async fetchIssueRunContext(issueId: string): Promise<GraphQlIssueRunContext> {
        const payload = await this.executeGraphQl<GraphQlIssueRunContext>(
            `
            query FetchIssueRunContext($id: String!) {
                issue(id: $id) {
                    id
                    identifier
                    title
                    description
                    branchName
                    url
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
                                state {
                                    name
                                }
                            }
                            relatedIssue {
                                id
                                identifier
                                state {
                                    name
                                }
                            }
                        }
                    }
                    inverseRelations {
                        nodes {
                            type
                            issue {
                                id
                                identifier
                                state {
                                    name
                                }
                            }
                            relatedIssue {
                                id
                                identifier
                                state {
                                    name
                                }
                            }
                        }
                    }
                    project {
                        slugId
                    }
                    comments(first: 50) {
                        nodes {
                            id
                            body
                            url
                            updatedAt
                        }
                    }
                    team {
                        states {
                            nodes {
                                id
                                name
                                type
                            }
                        }
                    }
                }
            }
            `,
            {
                id: issueId,
            },
        );

        if (!payload.issue) {
            throw new LinearTrackerError('linear_invalid_response', `Linear issue payload missing issue ${issueId}.`);
        }

        return payload;
    }

    private selectWorkpadComment(comments: GraphQlCommentNode[]): GraphQlCommentNode | undefined {
        return comments.filter((comment) => isWorkpadComment(comment)).sort(compareCommentsByUpdatedAtDescending)[0];
    }

    private async createIssueComment(
        issueId: string,
        body: string,
    ): Promise<{
        id: string;
        body: string;
        url: string | null;
    }> {
        const payload = await this.executeGraphQl<GraphQlCommentCreateResult>(
            `
            mutation CreateComment($issueId: String!, $body: String!) {
                commentCreate(input: { issueId: $issueId, body: $body }) {
                    success
                    comment {
                        id
                        body
                        url
                    }
                }
            }
            `,
            {
                issueId,
                body,
            },
        );

        const createdComment = payload.commentCreate?.comment;
        if (payload.commentCreate?.success !== true || !createdComment?.id) {
            throw new LinearTrackerError(
                'linear_invalid_response',
                `Failed to create workpad comment for issue ${issueId}.`,
            );
        }

        return {
            id: createdComment.id,
            body: normalizeCommentBody(createdComment.body) ?? body,
            url: asOptionalTrimmedString(createdComment.url),
        };
    }

    private async updateIssueComment(
        commentId: string,
        body: string,
    ): Promise<{
        id: string;
        body: string;
        url: string | null;
    }> {
        const payload = await this.executeGraphQl<GraphQlCommentUpdateResult>(
            `
            mutation UpdateComment($id: String!, $body: String!) {
                commentUpdate(id: $id, input: { body: $body }) {
                    success
                    comment {
                        id
                        body
                        url
                    }
                }
            }
            `,
            {
                id: commentId,
                body,
            },
        );

        const updatedComment = payload.commentUpdate?.comment;
        if (payload.commentUpdate?.success !== true || !updatedComment?.id) {
            throw new LinearTrackerError('linear_invalid_response', `Failed to update workpad comment ${commentId}.`);
        }

        return {
            id: updatedComment.id,
            body: normalizeCommentBody(updatedComment.body) ?? body,
            url: asOptionalTrimmedString(updatedComment.url),
        };
    }

    private async updateIssueStateByName(
        issue: TrackedIssue,
        context: GraphQlIssueRunContext,
        stateName: string,
    ): Promise<TrackedIssue> {
        const availableStates = context.issue?.team?.states?.nodes ?? [];
        const targetState = availableStates.find(
            (candidate) => candidate.name.trim().toLowerCase() === stateName.trim().toLowerCase(),
        );

        if (!targetState?.id) {
            throw new LinearTrackerError(
                'linear_invalid_response',
                `Linear issue ${issue.identifier} is missing target state ${stateName}.`,
            );
        }

        const payload = await this.executeGraphQl<GraphQlIssueStateUpdateResult>(
            `
            mutation UpdateIssueState($id: String!, $stateId: String!) {
                issueUpdate(id: $id, input: { stateId: $stateId }) {
                    success
                    issue {
                        id
                        updatedAt
                        state {
                            id
                            name
                        }
                    }
                }
            }
            `,
            {
                id: issue.id,
                stateId: targetState.id,
            },
        );

        if (payload.issueUpdate?.success !== true) {
            throw new LinearTrackerError(
                'linear_invalid_response',
                `Failed to move issue ${issue.identifier} to ${stateName}.`,
            );
        }

        const nextUpdatedAt = asOptionalTrimmedString(payload.issueUpdate.issue?.updatedAt) ?? issue.updatedAt;
        return {
            ...issue,
            stateName: asOptionalTrimmedString(payload.issueUpdate.issue?.state?.name) ?? stateName,
            updatedAt: nextUpdatedAt,
        };
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
