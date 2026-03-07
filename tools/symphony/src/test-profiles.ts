import {AppServerClientError, type AppServerErrorClass} from './app-server-client.js';
import type {TrackedIssue} from './linear-tracker.js';
import type {AppServerClient, TrackerClient} from './orchestrator.js';

export interface FakeLinearProfileScenario {
    candidates: TrackedIssue[];
    issueStatesById?: Record<string, string>;
    todoIssues?: TrackedIssue[];
}

function cloneIssue(issue: TrackedIssue): TrackedIssue {
    return {
        ...issue,
        labels: [...issue.labels],
        blockedBy: issue.blockedBy.map((blocker) => ({...blocker})),
        blockedByIdentifiers: [...issue.blockedByIdentifiers],
        workpadCommentId: issue.workpadCommentId,
        workpadCommentBody: issue.workpadCommentBody,
        workpadCommentUrl: issue.workpadCommentUrl,
    };
}

export class FakeLinearProfile implements TrackerClient {
    private candidates: TrackedIssue[];
    private todoIssues: TrackedIssue[];
    private issueStatesById: Map<string, string>;

    public constructor(scenario: FakeLinearProfileScenario) {
        this.candidates = scenario.candidates.map((issue) => cloneIssue(issue));
        this.todoIssues = (scenario.todoIssues ?? []).map((issue) => cloneIssue(issue));
        this.issueStatesById = new Map<string, string>();

        for (const issue of this.candidates) {
            this.issueStatesById.set(issue.id, issue.stateName);
        }

        for (const [issueId, stateName] of Object.entries(scenario.issueStatesById ?? {})) {
            this.issueStatesById.set(issueId, stateName);
        }
    }

    public setCandidates(candidates: TrackedIssue[]): void {
        this.candidates = candidates.map((issue) => cloneIssue(issue));
        for (const issue of this.candidates) {
            this.issueStatesById.set(issue.id, issue.stateName);
        }
    }

    public setIssueState(issueId: string, stateName: string): void {
        this.issueStatesById.set(issueId, stateName);
    }

    public async fetchCandidateIssues(): Promise<TrackedIssue[]> {
        return this.candidates.map((issue) => cloneIssue(issue));
    }

    public async fetchIssueStatesByIds(issueIds: string[]): Promise<Map<string, string>> {
        const mapped = new Map<string, string>();

        for (const issueId of issueIds) {
            mapped.set(issueId, this.issueStatesById.get(issueId) ?? '');
        }

        return mapped;
    }

    public async fetchIssueStatesByStateNames(stateNames: string[]): Promise<TrackedIssue[]> {
        const normalizedStateNames = new Set(stateNames.map((stateName) => stateName.trim().toLowerCase()));
        const combinedIssues = [...this.candidates, ...this.todoIssues];

        return combinedIssues
            .filter((issue) => {
                const currentState = (this.issueStatesById.get(issue.id) ?? issue.stateName).trim().toLowerCase();
                return normalizedStateNames.has(currentState);
            })
            .map((issue) => {
                const cloned = cloneIssue(issue);
                cloned.stateName = this.issueStatesById.get(issue.id) ?? issue.stateName;
                return cloned;
            });
    }

    public async prepareIssueForRun(issue: TrackedIssue): Promise<TrackedIssue> {
        const preparedIssue = cloneIssue(issue);
        if (preparedIssue.stateName.trim().toLowerCase() === 'todo') {
            preparedIssue.stateName = 'In Progress';
            this.issueStatesById.set(preparedIssue.id, preparedIssue.stateName);
        }

        if (!preparedIssue.workpadCommentId) {
            preparedIssue.workpadCommentId = `workpad-${preparedIssue.id}`;
            preparedIssue.workpadCommentBody = '## Codex Workpad';
            preparedIssue.workpadCommentUrl = null;
        }

        return preparedIssue;
    }
}

export type FakeCodexOutcome =
    | {
          type: 'completed';
          outputText?: string;
      }
    | {
          type: 'input_required';
          outputText?: string;
      }
    | {
          type: 'failed';
          errorClass?: AppServerErrorClass;
          message?: string;
      };

export class FakeCodexProfile implements AppServerClient {
    private readonly outcomes: FakeCodexOutcome[];
    public readonly seenRequests: Array<{
        issueIdentifier: string;
        attempt: number | null;
        prompt: string;
        threadId?: string;
    }> = [];

    public constructor(outcomes: FakeCodexOutcome[]) {
        this.outcomes = [...outcomes];
    }

    public async runTurn(request: {
        prompt: string;
        issueIdentifier: string;
        issueTitle?: string;
        attempt: number | null;
        threadId?: string;
        responseTimeoutMs?: number;
        turnTimeoutMs?: number;
    }): Promise<{
        status: 'completed' | 'input_required';
        outputText: string;
        threadId: string;
        turnId: string;
        sessionId: string;
    }> {
        this.seenRequests.push({
            issueIdentifier: request.issueIdentifier,
            attempt: request.attempt,
            prompt: request.prompt,
            threadId: request.threadId,
        });

        const outcome = this.outcomes.shift() ?? {type: 'completed', outputText: ''};

        if (outcome.type === 'failed') {
            throw new AppServerClientError(
                outcome.errorClass ?? 'turn_failed',
                outcome.message ?? 'Fake codex scripted failure.',
                {
                    issueIdentifier: request.issueIdentifier,
                    attempt: request.attempt,
                },
            );
        }

        const threadId = `thread-${request.issueIdentifier}`;
        const turnId = `turn-${request.attempt ?? 'initial'}`;

        return {
            status: outcome.type,
            outputText: outcome.outputText ?? '',
            threadId,
            turnId,
            sessionId: `${threadId}-${turnId}`,
        };
    }

    public async stop(): Promise<void> {
        return;
    }
}
