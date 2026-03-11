import assert from 'node:assert/strict';
import test from 'node:test';
import {
    GitHubPrAutoResumePolicyClient,
    type ExecFileLike,
    type PullRequestWatchSnapshot,
} from './github-pr-auto-resume.js';

function snapshotWithActions(actions: string[]): PullRequestWatchSnapshot {
    return {
        pr: {
            number: 118,
            url: 'https://github.com/robinbeier/forscherhaus-appointments/pull/118',
            state: 'OPEN',
            merged: false,
            closed: false,
            mergeable: 'MERGEABLE',
            merge_state_status: 'CLEAN',
            review_decision: '',
        },
        checks: {
            pending_count: 0,
            failed_count: 0,
            passed_count: 7,
            all_terminal: true,
        },
        failed_runs: [],
        new_review_items: [],
        actions,
    };
}

test('evaluateAutoResume promotes a merge-clean PR resolved directly by branch', async () => {
    const commands: Array<{file: string; args: string[]}> = [];
    const execFileImpl: ExecFileLike = async (file, args) => {
        commands.push({file, args});

        if (file === 'gh' && args[0] === 'pr' && args[1] === 'view') {
            return {
                stdout: JSON.stringify({
                    number: 118,
                    url: 'https://github.com/robinbeier/forscherhaus-appointments/pull/118',
                    headRefName: 'codex/rob-118-auto-resume-policy',
                    title: 'ROB-118: Add policy-driven auto-resume',
                    state: 'OPEN',
                }),
                stderr: '',
            };
        }

        if (file === 'python3') {
            return {
                stdout: JSON.stringify(snapshotWithActions(['stop_ready_to_merge'])),
                stderr: '',
            };
        }

        throw new Error(`Unexpected command: ${file} ${args.join(' ')}`);
    };

    const policy = new GitHubPrAutoResumePolicyClient({
        cwd: '/tmp/repo',
        execFileImpl,
        watcherScriptPath: '/tmp/watch.py',
    });

    const decision = await policy.evaluateAutoResume({
        identifier: 'ROB-118',
        branchName: 'codex/rob-118-auto-resume-policy',
    });

    assert.equal(decision.shouldPromote, true);
    assert.equal(decision.reason, 'ready_to_merge');
    assert.equal(decision.prNumber, 118);
    assert.equal(commands.length, 2);
});

test('evaluateAutoResume falls back to identifier search when the branch lookup misses', async () => {
    const execFileImpl: ExecFileLike = async (file, args) => {
        if (file === 'gh' && args[0] === 'pr' && args[1] === 'view') {
            throw Object.assign(new Error('branch not found'), {
                stderr: 'no pull requests found for branch "beierrobin/rob-118-legacy"',
            });
        }

        if (file === 'gh' && args[0] === 'pr' && args[1] === 'list') {
            return {
                stdout: JSON.stringify([
                    {
                        number: 118,
                        url: 'https://github.com/robinbeier/forscherhaus-appointments/pull/118',
                        headRefName: 'codex/rob-118-auto-resume-policy',
                        title: 'ROB-118: Add policy-driven auto-resume',
                        state: 'OPEN',
                    },
                ]),
                stderr: '',
            };
        }

        if (file === 'python3') {
            return {
                stdout: JSON.stringify(snapshotWithActions(['stop_ready_to_merge'])),
                stderr: '',
            };
        }

        throw new Error(`Unexpected command: ${file} ${args.join(' ')}`);
    };

    const policy = new GitHubPrAutoResumePolicyClient({
        cwd: '/tmp/repo',
        execFileImpl,
        watcherScriptPath: '/tmp/watch.py',
    });

    const decision = await policy.evaluateAutoResume({
        identifier: 'ROB-118',
        branchName: 'beierrobin/rob-118-legacy',
    });

    assert.equal(decision.shouldPromote, true);
    assert.equal(decision.prNumber, 118);
    assert.equal(decision.reason, 'ready_to_merge');
});

test('evaluateAutoResume blocks promotion when fresh review feedback is still open', async () => {
    const execFileImpl: ExecFileLike = async (file, args) => {
        if (file === 'gh') {
            return {
                stdout: JSON.stringify({
                    number: 118,
                    url: 'https://github.com/robinbeier/forscherhaus-appointments/pull/118',
                    headRefName: 'codex/rob-118-auto-resume-policy',
                    title: 'ROB-118: Add policy-driven auto-resume',
                    state: 'OPEN',
                }),
                stderr: '',
            };
        }

        if (file === 'python3') {
            return {
                stdout: JSON.stringify({
                    ...snapshotWithActions(['process_review_comment']),
                    new_review_items: [{id: 'review-1', kind: 'review_comment'}],
                }),
                stderr: '',
            };
        }

        throw new Error(`Unexpected command: ${file} ${args.join(' ')}`);
    };

    const policy = new GitHubPrAutoResumePolicyClient({
        cwd: '/tmp/repo',
        execFileImpl,
        watcherScriptPath: '/tmp/watch.py',
    });

    const decision = await policy.evaluateAutoResume({
        identifier: 'ROB-118',
        branchName: 'codex/rob-118-auto-resume-policy',
    });

    assert.equal(decision.shouldPromote, false);
    assert.equal(decision.reason, 'review_feedback_pending');
    assert.equal(decision.prNumber, 118);
});

test('evaluateAutoResume blocks promotion when the PR is not mergeable yet', async () => {
    const execFileImpl: ExecFileLike = async (file, args) => {
        if (file === 'gh') {
            return {
                stdout: JSON.stringify({
                    number: 118,
                    url: 'https://github.com/robinbeier/forscherhaus-appointments/pull/118',
                    headRefName: 'codex/rob-118-auto-resume-policy',
                    title: 'ROB-118: Add policy-driven auto-resume',
                    state: 'OPEN',
                }),
                stderr: '',
            };
        }

        if (file === 'python3') {
            return {
                stdout: JSON.stringify({
                    ...snapshotWithActions(['idle']),
                    pr: {
                        ...snapshotWithActions(['idle']).pr,
                        mergeable: 'CONFLICTING',
                        merge_state_status: 'DIRTY',
                    },
                }),
                stderr: '',
            };
        }

        throw new Error(`Unexpected command: ${file} ${args.join(' ')}`);
    };

    const policy = new GitHubPrAutoResumePolicyClient({
        cwd: '/tmp/repo',
        execFileImpl,
        watcherScriptPath: '/tmp/watch.py',
    });

    const decision = await policy.evaluateAutoResume({
        identifier: 'ROB-118',
        branchName: 'codex/rob-118-auto-resume-policy',
    });

    assert.equal(decision.shouldPromote, false);
    assert.equal(decision.reason, 'not_mergeable');
    assert.equal(decision.prNumber, 118);
});

test('evaluateAutoResume returns pull_request_not_found when no open PR can be resolved', async () => {
    const execFileImpl: ExecFileLike = async (file, args) => {
        if (file === 'gh' && args[0] === 'pr' && args[1] === 'view') {
            throw Object.assign(new Error('missing branch pr'), {
                stderr: 'no pull requests found for branch "beierrobin/rob-118"',
            });
        }

        if (file === 'gh' && args[0] === 'pr' && args[1] === 'list') {
            return {
                stdout: JSON.stringify([]),
                stderr: '',
            };
        }

        throw new Error(`Unexpected command: ${file} ${args.join(' ')}`);
    };

    const policy = new GitHubPrAutoResumePolicyClient({
        cwd: '/tmp/repo',
        execFileImpl,
        watcherScriptPath: '/tmp/watch.py',
    });

    const decision = await policy.evaluateAutoResume({
        identifier: 'ROB-118',
        branchName: 'beierrobin/rob-118',
    });

    assert.equal(decision.shouldPromote, false);
    assert.equal(decision.reason, 'pull_request_not_found');
    assert.equal(decision.prNumber, null);
});
