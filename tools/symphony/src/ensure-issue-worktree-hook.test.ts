import assert from 'node:assert/strict';
import {execFile as execFileCallback} from 'node:child_process';
import {chmod, mkdtemp, mkdir, readFile, realpath, rm, writeFile} from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import {promisify} from 'node:util';

const execFile = promisify(execFileCallback);
const hookPath = '/Users/robinbeier/Developers/forscherhaus-appointments/scripts/symphony/ensure_issue_worktree.sh';

async function writeExecutable(filePath: string, contents: string): Promise<void> {
    await writeFile(filePath, contents, 'utf8');
    await chmod(filePath, 0o755);
}

test('ensure_issue_worktree accepts a git worktree repo root with a .git file', async () => {
    const temporaryDirectory = await mkdtemp(path.join(os.tmpdir(), 'symphony-ensure-hook-'));
    const repoRoot = path.join(temporaryDirectory, 'repo');
    const workspacePath = path.join(temporaryDirectory, 'ROB-44');
    const fakeBin = path.join(temporaryDirectory, 'bin');
    const gitLogPath = path.join(temporaryDirectory, 'git.log');
    const fakeGitDir = path.join(temporaryDirectory, 'fake-git-dir');
    const excludePath = path.join(temporaryDirectory, 'exclude');

    await mkdir(repoRoot, {recursive: true});
    await writeFile(path.join(repoRoot, '.git'), 'gitdir: /tmp/fake-ensure-hook-gitdir\n', 'utf8');
    await mkdir(workspacePath, {recursive: true});
    await mkdir(fakeBin, {recursive: true});
    await mkdir(path.join(fakeGitDir, 'info'), {recursive: true});
    await mkdir(path.join(repoRoot, '.codex', 'skills', 'land'), {recursive: true});
    await mkdir(path.join(repoRoot, '.claude'), {recursive: true});
    await writeFile(
        path.join(repoRoot, '.codex', 'skills', 'land', 'SKILL.md'),
        '---\nname: land\ndescription: ok\n---\n',
    );
    await writeFile(path.join(repoRoot, '.claude', 'napkin.md'), '# Napkin\n');
    const workspaceRealPath = await realpath(workspacePath);

    await writeExecutable(
        path.join(fakeBin, 'git'),
        `#!/usr/bin/env bash
printf '%s\n' "$*" >> "$FAKE_GIT_LOG"
if [[ "$*" == *"rev-parse --git-path info/exclude"* ]]; then
    printf '%s\n' "$FAKE_EXCLUDE_PATH"
    exit 0
fi
if [[ "$*" == *"rev-parse --git-dir"* ]]; then
    printf '%s\n' "$FAKE_GIT_DIR"
    exit 0
fi
if [[ "$*" == *"remote get-url origin"* ]]; then
    exit 1
fi
if [[ "$*" == *"show-ref --verify --quiet refs/remotes/origin/main"* ]]; then
    exit 1
fi
if [[ "$*" == *"symbolic-ref --quiet --short refs/remotes/origin/HEAD"* ]]; then
    exit 1
fi
if [[ "$*" == *"worktree list --porcelain"* ]]; then
    exit 0
fi
if [[ "$*" == *"status --porcelain=v1 --untracked-files=all"* ]]; then
    exit 0
fi
if [[ "$*" == *"symbolic-ref --quiet --short HEAD"* ]]; then
    printf 'codex/symphony-rob-44\n'
    exit 0
fi
if [[ "$*" == *"show-ref --verify --quiet refs/remotes/origin/feature/rob-44"* ]]; then
    exit 0
fi
if [[ "$*" == *"worktree prune"* ]]; then
    exit 0
fi
if [[ "$*" == *"show-ref --verify --quiet refs/heads/codex/symphony-rob-44"* ]]; then
    exit 1
fi
if [[ "$*" == *"checkout -B feature/rob-44 origin/feature/rob-44"* ]]; then
    exit 0
fi
if [[ "$*" == *"ls-files .codex/skills .claude/napkin.md"* ]]; then
    printf '.codex/skills/land/SKILL.md\n'
    exit 0
fi
if [[ "$*" == *"update-index --assume-unchanged -- .codex/skills/land/SKILL.md"* ]]; then
    exit 0
fi
if [[ "$*" == *"worktree add -b codex/symphony-rob-44"* ]]; then
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
                SYMPHONY_ISSUE_BRANCH_NAME: 'feature/rob-44',
                FAKE_GIT_LOG: gitLogPath,
                FAKE_GIT_DIR: fakeGitDir,
                FAKE_EXCLUDE_PATH: excludePath,
            },
        });

        assert.match(stderr, /Aligned workspace branch to origin\/feature\/rob-44/);
        assert.match(stdout, /Prepared worktree .* codex\/symphony-rob-44 \(base: HEAD\)/);

        const gitLog = await readFile(gitLogPath, 'utf8');
        assert.match(gitLog, /rev-parse --git-dir/);
        assert.match(gitLog, /checkout -B feature\/rob-44 origin\/feature\/rob-44/);
        assert.match(gitLog, /update-index --assume-unchanged -- \.codex\/skills\/land\/SKILL\.md/);
        assert.match(gitLog, /worktree add -b codex\/symphony-rob-44 .* HEAD/);

        const syncedSkill = await readFile(path.join(workspacePath, '.codex', 'skills', 'land', 'SKILL.md'), 'utf8');
        const syncedNapkin = await readFile(path.join(workspacePath, '.claude', 'napkin.md'), 'utf8');
        const excludeContents = await readFile(excludePath, 'utf8');
        assert.match(syncedSkill, /name: land/);
        assert.match(syncedNapkin, /# Napkin/);
        assert.match(excludeContents, /\/\.codex\/skills\/\*/);
        assert.match(excludeContents, /\/\.codex\/skills\/\*\*/);
        assert.match(excludeContents, /\/\.claude\/napkin\.md/);
    } finally {
        await rm(temporaryDirectory, {recursive: true, force: true});
    }
});
