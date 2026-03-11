import {execFile as execFileCallback} from 'node:child_process';
import {mkdtemp, rm} from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {promisify} from 'node:util';

const execFile = promisify(execFileCallback);
const DEFAULT_WATCHER_SCRIPT_PATH = fileURLToPath(
    new URL('../../../.codex/skills/babysit-pr/scripts/gh_pr_watch.py', import.meta.url),
);

interface ExecFileResult {
    stdout: string;
    stderr: string;
}

export type ExecFileLike = (
    file: string,
    args: string[],
    options?: {
        cwd?: string;
    },
) => Promise<ExecFileResult>;

export interface PullRequestAutoResumeIssueRef {
    identifier: string;
    branchName: string | null;
}

interface PullRequestListItem {
    number: number;
    url: string;
    headRefName: string;
    title: string;
    state: string;
}

export interface PullRequestWatchSnapshot {
    pr: {
        number: number;
        url: string;
        state: string;
        merged: boolean;
        closed: boolean;
        mergeable: string;
        merge_state_status: string;
        review_decision: string;
    };
    checks: {
        pending_count: number;
        failed_count: number;
        passed_count: number;
        all_terminal: boolean;
    };
    failed_runs: Array<Record<string, unknown>>;
    new_review_items: Array<Record<string, unknown>>;
    actions: string[];
}

export interface PullRequestAutoResumeDecision {
    shouldPromote: boolean;
    reason:
        | 'ready_to_merge'
        | 'pull_request_not_found'
        | 'review_feedback_pending'
        | 'checks_failed'
        | 'checks_pending'
        | 'not_mergeable'
        | 'review_gate_blocked'
        | 'not_ready'
        | 'signal_lookup_failed';
    prNumber: number | null;
    prUrl: string | null;
    snapshot: PullRequestWatchSnapshot | null;
}

export interface PullRequestAutoResumePolicy {
    evaluateAutoResume(issue: PullRequestAutoResumeIssueRef): Promise<PullRequestAutoResumeDecision>;
}

interface GitHubPrAutoResumePolicyArgs {
    cwd?: string;
    execFileImpl?: ExecFileLike;
    watcherScriptPath?: string;
}

function defaultExecFile(file: string, args: string[], options?: {cwd?: string}): Promise<ExecFileResult> {
    return execFile(file, args, options).then((result) => ({
        stdout: String(result.stdout),
        stderr: String(result.stderr),
    }));
}

function normalizeIdentifierForMatching(identifier: string): string {
    return identifier.trim().toLowerCase();
}

function normalizeIdentifierForBranchMatching(identifier: string): string {
    return normalizeIdentifierForMatching(identifier).replace(/[^a-z0-9]+/g, '-');
}

function safeString(value: unknown): string {
    return typeof value === 'string' ? value : '';
}

function asPullRequestListItem(value: unknown): PullRequestListItem | null {
    if (!value || typeof value !== 'object') {
        return null;
    }

    const record = value as Record<string, unknown>;
    const number = record.number;
    if (typeof number !== 'number' || !Number.isInteger(number)) {
        return null;
    }

    return {
        number,
        url: safeString(record.url),
        headRefName: safeString(record.headRefName),
        title: safeString(record.title),
        state: safeString(record.state),
    };
}

function extractExecFailureMessage(error: unknown): string {
    if (!error || typeof error !== 'object') {
        return String(error);
    }

    const maybeError = error as {
        message?: unknown;
        stdout?: unknown;
        stderr?: unknown;
    };
    const parts = [typeof maybeError.message === 'string' ? maybeError.message : String(error)];

    if (typeof maybeError.stdout === 'string' && maybeError.stdout.trim().length > 0) {
        parts.push(maybeError.stdout.trim());
    }

    if (typeof maybeError.stderr === 'string' && maybeError.stderr.trim().length > 0) {
        parts.push(maybeError.stderr.trim());
    }

    return parts.join('\n');
}

function selectBestPullRequestCandidate(
    issue: PullRequestAutoResumeIssueRef,
    candidates: PullRequestListItem[],
): PullRequestListItem | null {
    if (candidates.length === 0) {
        return null;
    }

    const normalizedIdentifier = normalizeIdentifierForMatching(issue.identifier);
    const normalizedIdentifierSlug = normalizeIdentifierForBranchMatching(issue.identifier);
    const normalizedBranchName = issue.branchName?.trim().toLowerCase() ?? null;

    const scoredCandidates = candidates
        .map((candidate) => {
            const normalizedTitle = candidate.title.trim().toLowerCase();
            const normalizedHeadRef = candidate.headRefName.trim().toLowerCase();
            let score = 0;

            if (normalizedBranchName && normalizedHeadRef === normalizedBranchName) {
                score += 1000;
            }

            if (
                normalizedTitle.startsWith(`${normalizedIdentifier}:`) ||
                normalizedTitle.startsWith(`${normalizedIdentifier} `)
            ) {
                score += 500;
            } else if (normalizedTitle.includes(normalizedIdentifier)) {
                score += 200;
            }

            if (normalizedHeadRef.includes(normalizedIdentifierSlug)) {
                score += 300;
            }

            return {
                candidate,
                score,
            };
        })
        .sort((left, right) => right.score - left.score || left.candidate.number - right.candidate.number);

    return scoredCandidates[0].score > 0 ? scoredCandidates[0].candidate : null;
}

function classifyDecision(snapshot: PullRequestWatchSnapshot): PullRequestAutoResumeDecision['reason'] {
    const actions = new Set(snapshot.actions);
    if (actions.has('stop_ready_to_merge')) {
        return 'ready_to_merge';
    }

    if (actions.has('process_review_comment')) {
        return 'review_feedback_pending';
    }

    if (snapshot.checks.failed_count > 0) {
        return 'checks_failed';
    }

    if (!snapshot.checks.all_terminal || snapshot.checks.pending_count > 0) {
        return 'checks_pending';
    }

    if (snapshot.pr.review_decision === 'REVIEW_REQUIRED' || snapshot.pr.review_decision === 'CHANGES_REQUESTED') {
        return 'review_gate_blocked';
    }

    if (snapshot.pr.mergeable !== 'MERGEABLE') {
        return 'not_mergeable';
    }

    if (['BLOCKED', 'DIRTY', 'DRAFT', 'UNKNOWN'].includes(snapshot.pr.merge_state_status)) {
        return 'not_mergeable';
    }

    return 'not_ready';
}

export class GitHubPrAutoResumePolicyClient implements PullRequestAutoResumePolicy {
    private readonly cwd: string;
    private readonly execFileImpl: ExecFileLike;
    private readonly watcherScriptPath: string;

    public constructor(args: GitHubPrAutoResumePolicyArgs = {}) {
        this.cwd = args.cwd ?? process.cwd();
        this.execFileImpl = args.execFileImpl ?? defaultExecFile;
        this.watcherScriptPath = args.watcherScriptPath ?? DEFAULT_WATCHER_SCRIPT_PATH;
    }

    public async evaluateAutoResume(issue: PullRequestAutoResumeIssueRef): Promise<PullRequestAutoResumeDecision> {
        try {
            const pullRequest = await this.resolvePullRequest(issue);
            if (!pullRequest) {
                return {
                    shouldPromote: false,
                    reason: 'pull_request_not_found',
                    prNumber: null,
                    prUrl: null,
                    snapshot: null,
                };
            }

            const snapshot = await this.collectWatcherSnapshot(pullRequest.number);
            const reason = classifyDecision(snapshot);

            return {
                shouldPromote: reason === 'ready_to_merge',
                reason,
                prNumber: pullRequest.number,
                prUrl: pullRequest.url,
                snapshot,
            };
        } catch {
            return {
                shouldPromote: false,
                reason: 'signal_lookup_failed',
                prNumber: null,
                prUrl: null,
                snapshot: null,
            };
        }
    }

    private async resolvePullRequest(issue: PullRequestAutoResumeIssueRef): Promise<PullRequestListItem | null> {
        const branchName = issue.branchName?.trim();
        if (branchName) {
            const branchMatch = await this.tryResolvePullRequestByBranch(branchName);
            if (branchMatch) {
                return branchMatch;
            }
        }

        const searchResults = await this.runJsonCommand<unknown[]>('gh', [
            'pr',
            'list',
            '--state',
            'open',
            '--limit',
            '10',
            '--search',
            `${issue.identifier} is:open`,
            '--json',
            'number,url,headRefName,title,state',
        ]);
        if (!Array.isArray(searchResults)) {
            return null;
        }

        return selectBestPullRequestCandidate(
            issue,
            searchResults
                .map((entry) => asPullRequestListItem(entry))
                .filter((entry): entry is PullRequestListItem => entry !== null),
        );
    }

    private async tryResolvePullRequestByBranch(branchName: string): Promise<PullRequestListItem | null> {
        try {
            const pullRequest = await this.runJsonCommand<unknown>('gh', [
                'pr',
                'view',
                branchName,
                '--json',
                'number,url,headRefName,title,state',
            ]);

            const normalized = asPullRequestListItem(pullRequest);
            if (!normalized || normalized.state.toUpperCase() !== 'OPEN') {
                return null;
            }

            return normalized;
        } catch (error) {
            const message = extractExecFailureMessage(error).toLowerCase();
            if (
                message.includes('no pull requests found') ||
                message.includes('could not resolve to a pull request') ||
                message.includes('no open pull requests')
            ) {
                return null;
            }

            throw error;
        }
    }

    private async collectWatcherSnapshot(prNumber: number): Promise<PullRequestWatchSnapshot> {
        const temporaryDirectory = await mkdtemp(path.join(os.tmpdir(), 'symphony-pr-auto-resume-'));
        const stateFilePath = path.join(temporaryDirectory, 'state.json');

        try {
            return await this.runJsonCommand<PullRequestWatchSnapshot>('python3', [
                this.watcherScriptPath,
                '--once',
                '--json',
                '--pr',
                String(prNumber),
                '--state-file',
                stateFilePath,
            ]);
        } finally {
            await rm(temporaryDirectory, {recursive: true, force: true});
        }
    }

    private async runJsonCommand<PayloadType>(file: string, args: string[]): Promise<PayloadType> {
        const result = await this.execFileImpl(file, args, {cwd: this.cwd});
        const payload = result.stdout.trim();
        if (payload.length === 0) {
            throw new Error(`Command produced no JSON output: ${file} ${args.join(' ')}`);
        }

        return JSON.parse(payload) as PayloadType;
    }
}
