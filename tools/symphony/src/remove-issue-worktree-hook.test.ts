import assert from 'node:assert/strict';
import {execFile as execFileCallback} from 'node:child_process';
import {chmod, mkdtemp, mkdir, readFile, realpath, rm, writeFile} from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import {promisify} from 'node:util';

const execFile = promisify(execFileCallback);
const hookPath = '/Users/robinbeier/Developers/forscherhaus-appointments/scripts/symphony/remove_issue_worktree.sh';

async function writeExecutable(filePath: string, contents: string): Promise<void> {
    await writeFile(filePath, contents, 'utf8');
    await chmod(filePath, 0o755);
}

test('remove_issue_worktree closes open PRs best-effort before removing the worktree', async () => {
    const temporaryDirectory = await mkdtemp(path.join(os.tmpdir(), 'symphony-remove-hook-'));
    const repoRoot = path.join(temporaryDirectory, 'repo');
    const workspacePath = path.join(temporaryDirectory, 'workspace');
    const fakeBin = path.join(temporaryDirectory, 'bin');
    const gitLogPath = path.join(temporaryDirectory, 'git.log');
    const ghLogPath = path.join(temporaryDirectory, 'gh.log');

    await mkdir(path.join(repoRoot, '.git'), {recursive: true});
    await mkdir(workspacePath, {recursive: true});
    await mkdir(fakeBin, {recursive: true});
    const workspaceRealPath = await realpath(workspacePath);

    await writeExecutable(
        path.join(fakeBin, 'git'),
        `#!/usr/bin/env bash
printf '%s\n' "$*" >> "$FAKE_GIT_LOG"
if [[ "$*" == *"worktree list --porcelain"* ]]; then
    printf 'worktree %s\n' "$WORKSPACE_PATH"
    exit 0
fi
if [[ "$*" == *"symbolic-ref --quiet --short HEAD"* ]]; then
    printf 'codex/symphony-rob-42\n'
    exit 0
fi
if [[ "$*" == *"worktree remove --force"* ]]; then
    exit 0
fi
if [[ "$*" == *"worktree prune"* ]]; then
    exit 0
fi
exit 99
`,
    );

    await writeExecutable(
        path.join(fakeBin, 'gh'),
        `#!/usr/bin/env bash
printf '%s\n' "$*" >> "$FAKE_GH_LOG"
if [[ "$1" == "auth" && "$2" == "status" ]]; then
    exit 0
fi
if [[ "$1" == "pr" && "$2" == "list" ]]; then
    printf '101\n102\n'
    exit 0
fi
if [[ "$1" == "pr" && "$2" == "close" && "$3" == "101" ]]; then
    exit 0
fi
if [[ "$1" == "pr" && "$2" == "close" && "$3" == "102" ]]; then
    printf 'boom\n' >&2
    exit 17
fi
exit 99
`,
    );

    try {
        const {stdout, stderr} = await execFile('bash', [hookPath], {
            cwd: workspaceRealPath,
            env: {
                ...process.env,
                PATH: `${fakeBin}:${process.env.PATH ?? ''}`,
                SYMPHONY_REPO_ROOT: repoRoot,
                WORKSPACE_PATH: workspaceRealPath,
                FAKE_GIT_LOG: gitLogPath,
                FAKE_GH_LOG: ghLogPath,
            },
        });

        assert.match(stdout, /Closed PR #101 for branch codex\/symphony-rob-42/);
        assert.match(stdout, /Removed worktree registration/);
        assert.match(stderr, /Failed to close PR #102 for branch codex\/symphony-rob-42: boom/);

        const gitLog = await readFile(gitLogPath, 'utf8');
        assert.match(gitLog, /worktree list --porcelain/);
        assert.match(gitLog, /symbolic-ref --quiet --short HEAD/);
        assert.match(gitLog, /worktree remove --force/);
        assert.match(gitLog, /worktree prune/);

        const ghLog = await readFile(ghLogPath, 'utf8');
        assert.match(ghLog, /auth status/);
        assert.match(ghLog, /pr list --head codex\/symphony-rob-42 --state open --json number --jq \.\[\]\.number/);
        assert.match(ghLog, /pr close 101 --delete-branch=false/);
        assert.match(ghLog, /pr close 102 --delete-branch=false/);
    } finally {
        await rm(temporaryDirectory, {recursive: true, force: true});
    }
});

test('remove_issue_worktree still removes the worktree when gh is unavailable', async () => {
    const temporaryDirectory = await mkdtemp(path.join(os.tmpdir(), 'symphony-remove-hook-no-gh-'));
    const repoRoot = path.join(temporaryDirectory, 'repo');
    const workspacePath = path.join(temporaryDirectory, 'workspace');
    const fakeBin = path.join(temporaryDirectory, 'bin');
    const gitLogPath = path.join(temporaryDirectory, 'git.log');

    await mkdir(path.join(repoRoot, '.git'), {recursive: true});
    await mkdir(workspacePath, {recursive: true});
    await mkdir(fakeBin, {recursive: true});
    const workspaceRealPath = await realpath(workspacePath);

    await writeExecutable(
        path.join(fakeBin, 'git'),
        `#!/usr/bin/env bash
printf '%s\n' "$*" >> "$FAKE_GIT_LOG"
if [[ "$*" == *"worktree list --porcelain"* ]]; then
    printf 'worktree %s\n' "$WORKSPACE_PATH"
    exit 0
fi
if [[ "$*" == *"symbolic-ref --quiet --short HEAD"* ]]; then
    printf 'codex/symphony-rob-43\n'
    exit 0
fi
if [[ "$*" == *"worktree remove --force"* || "$*" == *"worktree prune"* ]]; then
    exit 0
fi
exit 99
`,
    );

    try {
        const {stdout, stderr} = await execFile('bash', [hookPath], {
            cwd: workspaceRealPath,
            env: {
                ...process.env,
                PATH: `${fakeBin}:${process.env.PATH ?? ''}`,
                SYMPHONY_REPO_ROOT: repoRoot,
                WORKSPACE_PATH: workspaceRealPath,
                FAKE_GIT_LOG: gitLogPath,
            },
        });

        assert.equal(stderr, '');
        assert.match(stdout, /Removed worktree registration/);

        const gitLog = await readFile(gitLogPath, 'utf8');
        assert.match(gitLog, /worktree remove --force/);
        assert.match(gitLog, /worktree prune/);
    } finally {
        await rm(temporaryDirectory, {recursive: true, force: true});
    }
});
