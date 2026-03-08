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
printf 'PROJECT=%s\n' "$CI_DOCKER_COMPOSE_PROJECT_NAME"
printf 'MYSQL=%s\n' "$EA_MYSQL_DATA_PATH"
printf 'MYSQL_DIR=%s\n' "$(test -d "$EA_MYSQL_DATA_PATH" && printf yes || printf no)"
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

        assert.match(stdout, /^PROJECT=docker-helper-unset-project-[a-z0-9-]*-local-ci-\d+$/m);
        const projectName = stdout.match(/^PROJECT=(.+)$/m)?.[1];
        assert.ok(projectName, 'helper should derive a compose project name');
        assert.match(stdout, new RegExp(`^MYSQL=\\./docker/\\.ci-mysql/${projectName}$`, 'm'));
        assert.match(stdout, /^MYSQL_DIR=yes$/m);
        assert.match(
            stdout,
            new RegExp(
                `docker\\ncompose\\n-p\\n${projectName}\\n-f\\ndocker-compose\\.yml\\n-f\\ndocker/compose\\.ci-local\\.yml$`,
                'm',
            ),
        );
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
printf 'MYSQL=%s\n' "$EA_MYSQL_DATA_PATH"
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

        assert.match(stdout, /^MYSQL=\.\/docker\/\.ci-mysql\/fh-helper-test$/m);
        assert.equal(
            stdout.trim().split('\n').slice(1).join('\n'),
            'docker\ncompose\n-p\nfh-helper-test\n-f\ndocker-compose.yml\n-f\ndocker/compose.ci-local.yml',
        );
    } finally {
        await rm(temporaryDirectory, {recursive: true, force: true});
    }
});

test('ci_docker_cleanup_stack removes the helper-managed MySQL data path', async () => {
    const temporaryDirectory = await mkdtemp(path.join(os.tmpdir(), 'docker-helper-cleanup-'));
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
if [[ "$*" == *"down -v --remove-orphans"* ]]; then
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
touch "$EA_MYSQL_DATA_PATH/sentinel"
ci_docker_cleanup_stack
printf 'REMOVED=%s\n' "$(test ! -e "$EA_MYSQL_DATA_PATH" && printf yes || printf no)"`,
            ],
            {
                cwd: temporaryDirectory,
                env: {
                    ...process.env,
                    PATH: `${fakeBin}:${process.env.PATH ?? ''}`,
                },
            },
        );

        assert.match(stdout, /^REMOVED=yes$/m);
    } finally {
        await rm(temporaryDirectory, {recursive: true, force: true});
    }
});

test('ci_docker_init_compose sanitizes helper-managed MySQL paths for custom project names', async () => {
    const temporaryDirectory = await mkdtemp(path.join(os.tmpdir(), 'docker-helper-path-sanitize-'));
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
export CI_DOCKER_COMPOSE_PROJECT_NAME='fh/helper test'
source "${dockerHelperPath}"
ci_docker_init_compose test-helper
printf 'MYSQL=%s\n' "$EA_MYSQL_DATA_PATH"`,
            ],
            {
                cwd: temporaryDirectory,
                env: {
                    ...process.env,
                    PATH: `${fakeBin}:${process.env.PATH ?? ''}`,
                },
            },
        );

        assert.match(stdout, /^MYSQL=\.\/docker\/\.ci-mysql\/fh-helper-test$/m);
    } finally {
        await rm(temporaryDirectory, {recursive: true, force: true});
    }
});

test('local CI compose files keep MySQL data-path overrides and remove host ports', async () => {
    const baseCompose = await readFile(path.join(repoRoot, 'docker-compose.yml'), 'utf8');
    const localOverride = await readFile(path.join(repoRoot, 'docker', 'compose.ci-local.yml'), 'utf8');

    assert.match(baseCompose, /\$\{EA_MYSQL_DATA_PATH:-\.\/docker\/mysql\}:\/var\/lib\/mysql/);
    assert.match(localOverride, /nginx:\s+ports: !reset \[\]/s);
    assert.match(localOverride, /mysql:\s+ports: !reset \[\]/s);
});
