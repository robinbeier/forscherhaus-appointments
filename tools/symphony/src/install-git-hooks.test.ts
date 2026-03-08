import assert from 'node:assert/strict';
import {execFile as execFileCallback} from 'node:child_process';
import {chmod, mkdtemp, mkdir, readFile, rm, writeFile} from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import {fileURLToPath} from 'node:url';
import {promisify} from 'node:util';

const execFile = promisify(execFileCallback);

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..', '..');
const installScriptPath = path.join(repoRoot, 'scripts', 'install-git-hooks.sh');
const preCommitHookPath = path.join(repoRoot, 'scripts', 'hooks', 'pre-commit');
const prePushHookPath = path.join(repoRoot, 'scripts', 'hooks', 'pre-push');

async function copyRepoScript(sourcePath: string, destinationPath: string): Promise<void> {
    const contents = await readFile(sourcePath, 'utf8');
    await writeFile(destinationPath, contents, 'utf8');
    await chmod(destinationPath, 0o755);
}

async function initializeTemporaryRepo(): Promise<string> {
    const temporaryDirectory = await mkdtemp(path.join(os.tmpdir(), 'install-git-hooks-'));
    await mkdir(path.join(temporaryDirectory, 'scripts', 'hooks'), {recursive: true});

    await copyRepoScript(installScriptPath, path.join(temporaryDirectory, 'scripts', 'install-git-hooks.sh'));
    await copyRepoScript(preCommitHookPath, path.join(temporaryDirectory, 'scripts', 'hooks', 'pre-commit'));
    await copyRepoScript(prePushHookPath, path.join(temporaryDirectory, 'scripts', 'hooks', 'pre-push'));

    await execFile('git', ['init', '-b', 'main'], {cwd: temporaryDirectory});

    return temporaryDirectory;
}

test('install-git-hooks installs both managed hooks in a fresh repo', async () => {
    const temporaryDirectory = await initializeTemporaryRepo();

    try {
        const {stdout} = await execFile('bash', [path.join('scripts', 'install-git-hooks.sh')], {
            cwd: temporaryDirectory,
        });

        const installedPreCommit = await readFile(path.join(temporaryDirectory, '.git', 'hooks', 'pre-commit'), 'utf8');
        const installedPrePush = await readFile(path.join(temporaryDirectory, '.git', 'hooks', 'pre-push'), 'utf8');

        assert.match(stdout, /Installed managed pre-commit hook/);
        assert.match(stdout, /Installed managed pre-push hook/);
        assert.match(installedPreCommit, /managed-by-forscherhaus-precommit/);
        assert.match(installedPrePush, /managed-by-forscherhaus-prepush/);
    } finally {
        await rm(temporaryDirectory, {recursive: true, force: true});
    }
});

test('install-git-hooks preserves a custom pre-commit hook without force while still installing pre-push', async () => {
    const temporaryDirectory = await initializeTemporaryRepo();
    const customHookBody = '#!/usr/bin/env bash\necho custom-pre-commit\n';

    try {
        await writeFile(path.join(temporaryDirectory, '.git', 'hooks', 'pre-commit'), customHookBody, 'utf8');
        await chmod(path.join(temporaryDirectory, '.git', 'hooks', 'pre-commit'), 0o755);

        const {stdout} = await execFile('bash', [path.join('scripts', 'install-git-hooks.sh')], {
            cwd: temporaryDirectory,
        });

        const installedPreCommit = await readFile(path.join(temporaryDirectory, '.git', 'hooks', 'pre-commit'), 'utf8');
        const installedPrePush = await readFile(path.join(temporaryDirectory, '.git', 'hooks', 'pre-push'), 'utf8');

        assert.match(stdout, /Existing custom pre-commit hook detected; leaving it untouched/);
        assert.match(stdout, /Installed managed pre-push hook/);
        assert.equal(installedPreCommit, customHookBody);
        assert.match(installedPrePush, /managed-by-forscherhaus-prepush/);
    } finally {
        await rm(temporaryDirectory, {recursive: true, force: true});
    }
});

test('install-git-hooks overwrites custom hooks when FORCE_HOOK_INSTALL=1', async () => {
    const temporaryDirectory = await initializeTemporaryRepo();

    try {
        await writeFile(
            path.join(temporaryDirectory, '.git', 'hooks', 'pre-commit'),
            '#!/usr/bin/env bash\necho old\n',
            'utf8',
        );
        await writeFile(
            path.join(temporaryDirectory, '.git', 'hooks', 'pre-push'),
            '#!/usr/bin/env bash\necho old\n',
            'utf8',
        );
        await chmod(path.join(temporaryDirectory, '.git', 'hooks', 'pre-commit'), 0o755);
        await chmod(path.join(temporaryDirectory, '.git', 'hooks', 'pre-push'), 0o755);

        await execFile('bash', [path.join('scripts', 'install-git-hooks.sh')], {
            cwd: temporaryDirectory,
            env: {...process.env, FORCE_HOOK_INSTALL: '1'},
        });

        const installedPreCommit = await readFile(path.join(temporaryDirectory, '.git', 'hooks', 'pre-commit'), 'utf8');
        const installedPrePush = await readFile(path.join(temporaryDirectory, '.git', 'hooks', 'pre-push'), 'utf8');

        assert.match(installedPreCommit, /managed-by-forscherhaus-precommit/);
        assert.match(installedPrePush, /managed-by-forscherhaus-prepush/);
    } finally {
        await rm(temporaryDirectory, {recursive: true, force: true});
    }
});
