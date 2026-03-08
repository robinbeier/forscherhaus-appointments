import assert from 'node:assert/strict';
import {execFile as execFileCallback} from 'node:child_process';
import {chmod, mkdtemp, mkdir, rm, writeFile} from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import {fileURLToPath} from 'node:url';
import {promisify} from 'node:util';

const execFile = promisify(execFileCallback);

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..', '..');
const dockerHelperPath = path.join(repoRoot, 'scripts', 'ci', 'docker_compose_helpers.sh');

async function writeExecutable(filePath: string, contents: string): Promise<void> {
    await writeFile(filePath, contents, 'utf8');
    await chmod(filePath, 0o755);
}

test('ci_docker_init_compose tolerates an unset compose project name', async () => {
    const temporaryDirectory = await mkdtemp(path.join(os.tmpdir(), 'docker-helper-unset-project-'));
    const fakeBin = path.join(temporaryDirectory, 'bin');

    try {
        await mkdir(path.join(temporaryDirectory, 'docker'), {recursive: true});
        await mkdir(fakeBin, {recursive: true});
        await writeFile(path.join(temporaryDirectory, 'docker-compose.yml'), 'services:\n  php-fpm: {}\n', 'utf8');
        await writeFile(
            path.join(temporaryDirectory, 'docker', 'compose.ci-local.yml'),
            'services:\n  php-fpm: {}\n',
            'utf8',
        );
        await writeExecutable(
            path.join(fakeBin, 'docker'),
            `#!/usr/bin/env bash
if [[ "$*" == "compose version" ]]; then
    exit 0
fi
exit 99
`,
        );

        const {stdout} = await execFile(
            'bash',
            [
                '-lc',
                `set -euo pipefail
source "${dockerHelperPath}"
ci_docker_init_compose test-helper
printf '%s\n' "\${CI_DOCKER_COMPOSE_CMD[@]}"`,
            ],
            {
                cwd: temporaryDirectory,
                env: {
                    ...process.env,
                    PATH: `${fakeBin}:${process.env.PATH ?? ''}`,
                },
            },
        );

        assert.equal(stdout.trim(), 'docker\ncompose\n-f\ndocker-compose.yml\n-f\ndocker/compose.ci-local.yml');
    } finally {
        await rm(temporaryDirectory, {recursive: true, force: true});
    }
});

test('ci_docker_init_compose includes the compose project name when provided', async () => {
    const temporaryDirectory = await mkdtemp(path.join(os.tmpdir(), 'docker-helper-set-project-'));
    const fakeBin = path.join(temporaryDirectory, 'bin');

    try {
        await mkdir(path.join(temporaryDirectory, 'docker'), {recursive: true});
        await mkdir(fakeBin, {recursive: true});
        await writeFile(path.join(temporaryDirectory, 'docker-compose.yml'), 'services:\n  php-fpm: {}\n', 'utf8');
        await writeFile(
            path.join(temporaryDirectory, 'docker', 'compose.ci-local.yml'),
            'services:\n  php-fpm: {}\n',
            'utf8',
        );
        await writeExecutable(
            path.join(fakeBin, 'docker'),
            `#!/usr/bin/env bash
if [[ "$*" == "compose version" ]]; then
    exit 0
fi
exit 99
`,
        );

        const {stdout} = await execFile(
            'bash',
            [
                '-lc',
                `set -euo pipefail
export CI_DOCKER_COMPOSE_PROJECT_NAME=fh-helper-test
source "${dockerHelperPath}"
ci_docker_init_compose test-helper
printf '%s\n' "\${CI_DOCKER_COMPOSE_CMD[@]}"`,
            ],
            {
                cwd: temporaryDirectory,
                env: {
                    ...process.env,
                    PATH: `${fakeBin}:${process.env.PATH ?? ''}`,
                },
            },
        );

        assert.equal(
            stdout.trim(),
            'docker\ncompose\n-p\nfh-helper-test\n-f\ndocker-compose.yml\n-f\ndocker/compose.ci-local.yml',
        );
    } finally {
        await rm(temporaryDirectory, {recursive: true, force: true});
    }
});
