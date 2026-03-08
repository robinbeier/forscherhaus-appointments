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
const preCommitHookPath = path.join(repoRoot, 'scripts', 'hooks', 'pre-commit');
const dockerHelperPath = path.join(repoRoot, 'scripts', 'ci', 'docker_compose_helpers.sh');

async function copyExecutable(sourcePath: string, destinationPath: string): Promise<void> {
    const contents = await readFile(sourcePath, 'utf8');
    await writeFile(destinationPath, contents, 'utf8');
    await chmod(destinationPath, 0o755);
}

async function writeExecutable(filePath: string, contents: string): Promise<void> {
    await writeFile(filePath, contents, 'utf8');
    await chmod(filePath, 0o755);
}

test('managed pre-commit uses the deterministic docker bootstrap path for php-related commits', async () => {
    const temporaryDirectory = await mkdtemp(path.join(os.tmpdir(), 'managed-pre-commit-'));
    const fakeBin = path.join(temporaryDirectory, 'bin');
    const dockerLogPath = path.join(temporaryDirectory, 'docker.log');
    const npxLogPath = path.join(temporaryDirectory, 'npx.log');

    try {
        await mkdir(path.join(temporaryDirectory, 'scripts', 'hooks'), {recursive: true});
        await mkdir(path.join(temporaryDirectory, 'scripts', 'ci'), {recursive: true});
        await mkdir(path.join(temporaryDirectory, 'docker'), {recursive: true});
        await mkdir(path.join(temporaryDirectory, 'application'), {recursive: true});
        await mkdir(path.join(temporaryDirectory, 'vendor'), {recursive: true});
        await mkdir(path.join(temporaryDirectory, 'node_modules'), {recursive: true});
        await mkdir(fakeBin, {recursive: true});

        await copyExecutable(preCommitHookPath, path.join(temporaryDirectory, 'scripts', 'hooks', 'pre-commit'));
        await copyExecutable(
            dockerHelperPath,
            path.join(temporaryDirectory, 'scripts', 'ci', 'docker_compose_helpers.sh'),
        );
        await writeFile(path.join(temporaryDirectory, 'config-sample.php'), '<?php\nreturn [];\n', 'utf8');
        await writeFile(
            path.join(temporaryDirectory, 'docker-compose.yml'),
            'services:\n  php-fpm: {}\n  mysql: {}\n',
            'utf8',
        );
        await writeFile(
            path.join(temporaryDirectory, 'docker', 'compose.ci-local.yml'),
            'services:\n  php-fpm: {}\n  mysql: {}\n',
            'utf8',
        );
        await writeFile(
            path.join(temporaryDirectory, 'application', 'HookSmoke.php'),
            '<?php\n\nreturn true;\n',
            'utf8',
        );

        await execFile('git', ['init', '-b', 'main'], {cwd: temporaryDirectory});
        await execFile('git', ['config', 'user.name', 'Codex'], {cwd: temporaryDirectory});
        await execFile('git', ['config', 'user.email', 'codex@example.com'], {cwd: temporaryDirectory});
        await execFile('git', ['add', 'application/HookSmoke.php'], {cwd: temporaryDirectory});

        await writeExecutable(
            path.join(fakeBin, 'docker'),
            `#!/usr/bin/env bash
printf '%s\\n' "$*" >> "$FAKE_DOCKER_LOG"
printf 'EA_MYSQL_DATA_PATH=%s\\n' "\${EA_MYSQL_DATA_PATH:-}" >> "$FAKE_DOCKER_LOG"
if [[ "$*" == "compose version" ]]; then
    exit 0
fi
if [[ "$*" == *"ps --status running --services"* ]]; then
    exit 0
fi
if [[ "$*" == *"up -d php-fpm mysql"* ]]; then
    exit 0
fi
if [[ "$*" == *"exec -T -w /var/www/html php-fpm npx --yes prettier --check application/HookSmoke.php"* ]]; then
    exit 0
fi
if [[ "$*" == *"exec -T -w /var/www/html php-fpm php -l application/HookSmoke.php"* ]]; then
    exit 0
fi
if [[ "$*" == *"exec -T mysql mysqladmin ping -h localhost -uroot -psecret --silent"* ]]; then
    exit 0
fi
if [[ "$*" == *"exec -T mysql mysql -uuser -ppassword -e USE easyappointments; SELECT 1;"* ]]; then
    exit 0
fi
if [[ "$*" == *"run --rm php-fpm php index.php console install"* ]]; then
    exit 0
fi
if [[ "$*" == *"run --rm php-fpm composer test"* ]]; then
    exit 0
fi
if [[ "$*" == *"down -v --remove-orphans"* ]]; then
    exit 0
fi
exit 99
`,
        );
        await writeExecutable(
            path.join(fakeBin, 'npm'),
            `#!/usr/bin/env bash
exit 0
`,
        );
        await writeExecutable(
            path.join(fakeBin, 'npx'),
            `#!/usr/bin/env bash
printf '%s\\n' "$*" >> "$FAKE_NPX_LOG"
exit 0
`,
        );
        await writeExecutable(path.join(fakeBin, 'php'), '#!/usr/bin/env bash\nexit 99\n');

        const {stdout} = await execFile('bash', [path.join('scripts', 'hooks', 'pre-commit')], {
            cwd: temporaryDirectory,
            env: {
                ...process.env,
                PATH: `${fakeBin}:${process.env.PATH ?? ''}`,
                FAKE_DOCKER_LOG: dockerLogPath,
                FAKE_NPX_LOG: npxLogPath,
            },
        });

        const dockerLog = await readFile(dockerLogPath, 'utf8');
        const npxLog = await readFile(npxLogPath, 'utf8');
        assert.match(stdout, /Run PHPUnit suite/);
        assert.match(stdout, /Pre-commit checks passed/);
        assert.match(npxLog, /--yes prettier --check application\/HookSmoke\.php/);
        assert.match(dockerLog, /exec -T -w \/var\/www\/html php-fpm php -l application\/HookSmoke\.php/);
        assert.match(
            dockerLog,
            /compose -p managed-pre-commit-[a-z0-9-]+-precommit-[0-9]+ -f docker-compose\.yml -f docker\/compose\.ci-local\.yml up -d php-fpm mysql/,
        );
        assert.match(
            dockerLog,
            /EA_MYSQL_DATA_PATH=\.\/docker\/\.ci-mysql\/managed-pre-commit-[a-z0-9-]+-precommit-[0-9]+/,
        );
        assert.match(dockerLog, /run --rm php-fpm php index\.php console install/);
        assert.match(dockerLog, /run --rm php-fpm composer test/);
        assert.match(dockerLog, /down -v --remove-orphans/);
        assert.doesNotMatch(dockerLog, /exec -T -w \/var\/www\/html php-fpm composer test/);
    } finally {
        await rm(temporaryDirectory, {recursive: true, force: true});
    }
});

test('managed pre-commit derives unique compose project names for same-named clones', async () => {
    const temporaryDirectory = await mkdtemp(path.join(os.tmpdir(), 'managed-pre-commit-collision-'));
    const repoBasename = 'managed-pre-commit-repo';
    const fakeDockerScript = `#!/usr/bin/env bash
printf '%s\\n' "$*" >> "$FAKE_DOCKER_LOG"
printf 'EA_MYSQL_DATA_PATH=%s\\n' "\${EA_MYSQL_DATA_PATH:-}" >> "$FAKE_DOCKER_LOG"
if [[ "$*" == "compose version" ]]; then
    exit 0
fi
if [[ "$*" == *"up -d php-fpm mysql"* ]]; then
    exit 0
fi
if [[ "$*" == *"exec -T -w /var/www/html php-fpm php -l application/HookSmoke.php"* ]]; then
    exit 0
fi
if [[ "$*" == *"exec -T mysql mysqladmin ping -h localhost -uroot -psecret --silent"* ]]; then
    exit 0
fi
if [[ "$*" == *"exec -T mysql mysql -uuser -ppassword -e USE easyappointments; SELECT 1;"* ]]; then
    exit 0
fi
if [[ "$*" == *"run --rm php-fpm php index.php console install"* ]]; then
    exit 0
fi
if [[ "$*" == *"run --rm php-fpm composer test"* ]]; then
    exit 0
fi
if [[ "$*" == *"down -v --remove-orphans"* ]]; then
    exit 0
fi
exit 99
`;

    async function createRepo(parentName: string): Promise<{dockerLogPath: string; repoDirectory: string}> {
        const repoDirectory = path.join(temporaryDirectory, parentName, repoBasename);
        const fakeBin = path.join(repoDirectory, 'bin');
        const dockerLogPath = path.join(repoDirectory, 'docker.log');

        await mkdir(path.join(repoDirectory, 'scripts', 'hooks'), {recursive: true});
        await mkdir(path.join(repoDirectory, 'scripts', 'ci'), {recursive: true});
        await mkdir(path.join(repoDirectory, 'docker'), {recursive: true});
        await mkdir(path.join(repoDirectory, 'application'), {recursive: true});
        await mkdir(path.join(repoDirectory, 'vendor'), {recursive: true});
        await mkdir(path.join(repoDirectory, 'node_modules'), {recursive: true});
        await mkdir(fakeBin, {recursive: true});

        await copyExecutable(preCommitHookPath, path.join(repoDirectory, 'scripts', 'hooks', 'pre-commit'));
        await copyExecutable(dockerHelperPath, path.join(repoDirectory, 'scripts', 'ci', 'docker_compose_helpers.sh'));
        await writeFile(path.join(repoDirectory, 'config-sample.php'), '<?php\nreturn [];\n', 'utf8');
        await writeFile(
            path.join(repoDirectory, 'docker-compose.yml'),
            'services:\n  php-fpm: {}\n  mysql: {}\n',
            'utf8',
        );
        await writeFile(
            path.join(repoDirectory, 'docker', 'compose.ci-local.yml'),
            'services:\n  php-fpm: {}\n  mysql: {}\n',
            'utf8',
        );
        await writeFile(path.join(repoDirectory, 'application', 'HookSmoke.php'), '<?php\n\nreturn true;\n', 'utf8');

        await execFile('git', ['init', '-b', 'main'], {cwd: repoDirectory});
        await execFile('git', ['config', 'user.name', 'Codex'], {cwd: repoDirectory});
        await execFile('git', ['config', 'user.email', 'codex@example.com'], {cwd: repoDirectory});
        await execFile('git', ['add', 'application/HookSmoke.php'], {cwd: repoDirectory});

        await writeExecutable(path.join(fakeBin, 'docker'), fakeDockerScript);
        await writeExecutable(path.join(fakeBin, 'npm'), '#!/usr/bin/env bash\nexit 0\n');
        await writeExecutable(path.join(fakeBin, 'npx'), '#!/usr/bin/env bash\nexit 0\n');
        await writeExecutable(
            path.join(fakeBin, 'php'),
            `#!/usr/bin/env bash
if [[ "$1" == "-l" ]]; then
    exit 0
fi
exit 99
`,
        );

        await execFile('bash', [path.join('scripts', 'hooks', 'pre-commit')], {
            cwd: repoDirectory,
            env: {
                ...process.env,
                PATH: `${fakeBin}:${process.env.PATH ?? ''}`,
                FAKE_DOCKER_LOG: dockerLogPath,
            },
        });

        return {dockerLogPath, repoDirectory};
    }

    try {
        const firstRepo = await createRepo('clone-a');
        const secondRepo = await createRepo('clone-b');

        const firstDockerLog = await readFile(firstRepo.dockerLogPath, 'utf8');
        const secondDockerLog = await readFile(secondRepo.dockerLogPath, 'utf8');
        const firstProjectName = firstDockerLog.match(/compose -p ([^ ]+) -f docker-compose\.yml/)?.[1];
        const secondProjectName = secondDockerLog.match(/compose -p ([^ ]+) -f docker-compose\.yml/)?.[1];
        const firstMysqlPath = firstDockerLog.match(/EA_MYSQL_DATA_PATH=([^\n]+)/)?.[1];
        const secondMysqlPath = secondDockerLog.match(/EA_MYSQL_DATA_PATH=([^\n]+)/)?.[1];

        assert.ok(firstProjectName, 'first repo should emit a compose project name');
        assert.ok(secondProjectName, 'second repo should emit a compose project name');
        assert.ok(firstMysqlPath, 'first repo should emit a MySQL data path');
        assert.ok(secondMysqlPath, 'second repo should emit a MySQL data path');
        assert.notEqual(firstProjectName, secondProjectName);
        assert.notEqual(firstMysqlPath, secondMysqlPath);
    } finally {
        await rm(temporaryDirectory, {recursive: true, force: true});
    }
});

test('managed pre-commit does not start docker for docs-only prettier checks', async () => {
    const temporaryDirectory = await mkdtemp(path.join(os.tmpdir(), 'managed-pre-commit-docs-'));
    const fakeBin = path.join(temporaryDirectory, 'bin');
    const dockerLogPath = path.join(temporaryDirectory, 'docker.log');
    const npxLogPath = path.join(temporaryDirectory, 'npx.log');

    try {
        await mkdir(path.join(temporaryDirectory, 'scripts', 'hooks'), {recursive: true});
        await mkdir(path.join(temporaryDirectory, 'scripts', 'ci'), {recursive: true});
        await mkdir(path.join(temporaryDirectory, 'vendor'), {recursive: true});
        await mkdir(path.join(temporaryDirectory, 'node_modules'), {recursive: true});
        await mkdir(fakeBin, {recursive: true});

        await copyExecutable(preCommitHookPath, path.join(temporaryDirectory, 'scripts', 'hooks', 'pre-commit'));
        await copyExecutable(
            dockerHelperPath,
            path.join(temporaryDirectory, 'scripts', 'ci', 'docker_compose_helpers.sh'),
        );
        await writeFile(path.join(temporaryDirectory, 'README.md'), '# Hook smoke\n', 'utf8');

        await execFile('git', ['init', '-b', 'main'], {cwd: temporaryDirectory});
        await execFile('git', ['config', 'user.name', 'Codex'], {cwd: temporaryDirectory});
        await execFile('git', ['config', 'user.email', 'codex@example.com'], {cwd: temporaryDirectory});
        await execFile('git', ['add', 'README.md'], {cwd: temporaryDirectory});

        await writeExecutable(
            path.join(fakeBin, 'npx'),
            `#!/usr/bin/env bash
printf '%s\\n' "$*" >> "$FAKE_NPX_LOG"
exit 0
`,
        );
        await writeExecutable(
            path.join(fakeBin, 'npm'),
            `#!/usr/bin/env bash
exit 0
`,
        );
        await writeExecutable(
            path.join(fakeBin, 'php'),
            `#!/usr/bin/env bash
exit 0
`,
        );

        const {stdout} = await execFile('bash', [path.join('scripts', 'hooks', 'pre-commit')], {
            cwd: temporaryDirectory,
            env: {
                ...process.env,
                PATH: `${fakeBin}:${process.env.PATH ?? ''}`,
                FAKE_NPX_LOG: npxLogPath,
            },
        });

        const npxLog = await readFile(npxLogPath, 'utf8');
        assert.match(stdout, /Run prettier checks/);
        assert.match(stdout, /Pre-commit checks passed/);
        assert.match(npxLog, /--yes prettier --check README\.md/);
        await assert.rejects(() => readFile(dockerLogPath, 'utf8'));
    } finally {
        await rm(temporaryDirectory, {recursive: true, force: true});
    }
});

test('managed pre-commit keeps php validation enabled for delete-only php commits', async () => {
    const temporaryDirectory = await mkdtemp(path.join(os.tmpdir(), 'managed-pre-commit-delete-php-'));
    const fakeBin = path.join(temporaryDirectory, 'bin');
    const dockerLogPath = path.join(temporaryDirectory, 'docker.log');
    const npxLogPath = path.join(temporaryDirectory, 'npx.log');
    const phpLogPath = path.join(temporaryDirectory, 'php.log');

    try {
        await mkdir(path.join(temporaryDirectory, 'scripts', 'hooks'), {recursive: true});
        await mkdir(path.join(temporaryDirectory, 'scripts', 'ci'), {recursive: true});
        await mkdir(path.join(temporaryDirectory, 'docker'), {recursive: true});
        await mkdir(path.join(temporaryDirectory, 'application'), {recursive: true});
        await mkdir(path.join(temporaryDirectory, 'vendor'), {recursive: true});
        await mkdir(path.join(temporaryDirectory, 'node_modules'), {recursive: true});
        await mkdir(fakeBin, {recursive: true});

        await copyExecutable(preCommitHookPath, path.join(temporaryDirectory, 'scripts', 'hooks', 'pre-commit'));
        await copyExecutable(
            dockerHelperPath,
            path.join(temporaryDirectory, 'scripts', 'ci', 'docker_compose_helpers.sh'),
        );
        await writeFile(path.join(temporaryDirectory, 'config-sample.php'), '<?php\nreturn [];\n', 'utf8');
        await writeFile(
            path.join(temporaryDirectory, 'docker-compose.yml'),
            'services:\n  php-fpm: {}\n  mysql: {}\n',
            'utf8',
        );
        await writeFile(
            path.join(temporaryDirectory, 'docker', 'compose.ci-local.yml'),
            'services:\n  php-fpm: {}\n  mysql: {}\n',
            'utf8',
        );
        await writeFile(
            path.join(temporaryDirectory, 'application', 'DeleteMe.php'),
            '<?php\n\nreturn true;\n',
            'utf8',
        );

        await execFile('git', ['init', '-b', 'main'], {cwd: temporaryDirectory});
        await execFile('git', ['config', 'user.name', 'Codex'], {cwd: temporaryDirectory});
        await execFile('git', ['config', 'user.email', 'codex@example.com'], {cwd: temporaryDirectory});
        await execFile('git', ['add', 'application/DeleteMe.php'], {cwd: temporaryDirectory});
        await execFile('git', ['commit', '-m', 'Seed delete candidate'], {cwd: temporaryDirectory});
        await rm(path.join(temporaryDirectory, 'application', 'DeleteMe.php'));
        await execFile('git', ['add', '-u', 'application/DeleteMe.php'], {cwd: temporaryDirectory});

        await writeExecutable(
            path.join(fakeBin, 'docker'),
            `#!/usr/bin/env bash
printf '%s\\n' "$*" >> "$FAKE_DOCKER_LOG"
if [[ "$*" == "compose version" ]]; then
    exit 0
fi
if [[ "$*" == *"up -d php-fpm mysql"* ]]; then
    exit 0
fi
if [[ "$*" == *"exec -T mysql mysqladmin ping -h localhost -uroot -psecret --silent"* ]]; then
    exit 0
fi
if [[ "$*" == *"exec -T mysql mysql -uuser -ppassword -e USE easyappointments; SELECT 1;"* ]]; then
    exit 0
fi
if [[ "$*" == *"run --rm php-fpm php index.php console install"* ]]; then
    exit 0
fi
if [[ "$*" == *"run --rm php-fpm composer test"* ]]; then
    exit 0
fi
if [[ "$*" == *"down -v --remove-orphans"* ]]; then
    exit 0
fi
exit 99
`,
        );
        await writeExecutable(
            path.join(fakeBin, 'npx'),
            `#!/usr/bin/env bash
printf '%s\\n' "$*" >> "$FAKE_NPX_LOG"
exit 0
`,
        );
        await writeExecutable(
            path.join(fakeBin, 'php'),
            `#!/usr/bin/env bash
printf '%s\\n' "$*" >> "$FAKE_PHP_LOG"
if [[ "$1" == "-l" ]]; then
    exit 0
fi
exit 99
`,
        );
        await writeExecutable(path.join(fakeBin, 'npm'), '#!/usr/bin/env bash\nexit 0\n');

        const {stdout} = await execFile('bash', [path.join('scripts', 'hooks', 'pre-commit')], {
            cwd: temporaryDirectory,
            env: {
                ...process.env,
                PATH: `${fakeBin}:${process.env.PATH ?? ''}`,
                FAKE_DOCKER_LOG: dockerLogPath,
                FAKE_NPX_LOG: npxLogPath,
                FAKE_PHP_LOG: phpLogPath,
            },
        });

        const dockerLog = await readFile(dockerLogPath, 'utf8');
        assert.match(stdout, /Run PHPUnit suite/);
        assert.match(stdout, /Pre-commit checks passed/);
        assert.match(dockerLog, /run --rm php-fpm composer test/);
        await assert.rejects(() => readFile(npxLogPath, 'utf8'));
        await assert.rejects(() => readFile(phpLogPath, 'utf8'));
    } finally {
        await rm(temporaryDirectory, {recursive: true, force: true});
    }
});

test('managed pre-commit falls back to container php -l when host php is unavailable', async () => {
    const temporaryDirectory = await mkdtemp(path.join(os.tmpdir(), 'managed-pre-commit-container-php-l-'));
    const fakeBin = path.join(temporaryDirectory, 'bin');
    const dockerLogPath = path.join(temporaryDirectory, 'docker.log');
    const npxLogPath = path.join(temporaryDirectory, 'npx.log');

    try {
        await mkdir(path.join(temporaryDirectory, 'scripts', 'hooks'), {recursive: true});
        await mkdir(path.join(temporaryDirectory, 'scripts', 'ci'), {recursive: true});
        await mkdir(path.join(temporaryDirectory, 'docker'), {recursive: true});
        await mkdir(path.join(temporaryDirectory, 'application'), {recursive: true});
        await mkdir(path.join(temporaryDirectory, 'vendor'), {recursive: true});
        await mkdir(path.join(temporaryDirectory, 'node_modules'), {recursive: true});
        await mkdir(fakeBin, {recursive: true});

        await copyExecutable(preCommitHookPath, path.join(temporaryDirectory, 'scripts', 'hooks', 'pre-commit'));
        await copyExecutable(
            dockerHelperPath,
            path.join(temporaryDirectory, 'scripts', 'ci', 'docker_compose_helpers.sh'),
        );
        await writeFile(path.join(temporaryDirectory, 'config-sample.php'), '<?php\nreturn [];\n', 'utf8');
        await writeFile(
            path.join(temporaryDirectory, 'docker-compose.yml'),
            'services:\n  php-fpm: {}\n  mysql: {}\n',
            'utf8',
        );
        await writeFile(
            path.join(temporaryDirectory, 'docker', 'compose.ci-local.yml'),
            'services:\n  php-fpm: {}\n  mysql: {}\n',
            'utf8',
        );
        await writeFile(
            path.join(temporaryDirectory, 'application', 'HookSmoke.php'),
            '<?php\n\nreturn true;\n',
            'utf8',
        );

        await execFile('git', ['init', '-b', 'main'], {cwd: temporaryDirectory});
        await execFile('git', ['config', 'user.name', 'Codex'], {cwd: temporaryDirectory});
        await execFile('git', ['config', 'user.email', 'codex@example.com'], {cwd: temporaryDirectory});
        await execFile('git', ['add', 'application/HookSmoke.php'], {cwd: temporaryDirectory});

        await writeExecutable(
            path.join(fakeBin, 'docker'),
            `#!/usr/bin/env bash
printf '%s\\n' "$*" >> "$FAKE_DOCKER_LOG"
if [[ "$*" == "compose version" ]]; then
    exit 0
fi
if [[ "$*" == *"up -d php-fpm mysql"* ]]; then
    exit 0
fi
if [[ "$*" == *"exec -T -w /var/www/html php-fpm php -l application/HookSmoke.php"* ]]; then
    exit 0
fi
if [[ "$*" == *"exec -T mysql mysqladmin ping -h localhost -uroot -psecret --silent"* ]]; then
    exit 0
fi
if [[ "$*" == *"exec -T mysql mysql -uuser -ppassword -e USE easyappointments; SELECT 1;"* ]]; then
    exit 0
fi
if [[ "$*" == *"run --rm php-fpm php index.php console install"* ]]; then
    exit 0
fi
if [[ "$*" == *"run --rm php-fpm composer test"* ]]; then
    exit 0
fi
if [[ "$*" == *"down -v --remove-orphans"* ]]; then
    exit 0
fi
exit 99
`,
        );
        await writeExecutable(
            path.join(fakeBin, 'npx'),
            `#!/usr/bin/env bash
printf '%s\\n' "$*" >> "$FAKE_NPX_LOG"
exit 0
`,
        );
        await writeExecutable(path.join(fakeBin, 'npm'), '#!/usr/bin/env bash\nexit 0\n');

        const {stdout} = await execFile('bash', [path.join('scripts', 'hooks', 'pre-commit')], {
            cwd: temporaryDirectory,
            env: {
                ...process.env,
                PATH: `${fakeBin}:${process.env.PATH ?? ''}`,
                FAKE_DOCKER_LOG: dockerLogPath,
                FAKE_NPX_LOG: npxLogPath,
            },
        });

        const dockerLog = await readFile(dockerLogPath, 'utf8');
        const npxLog = await readFile(npxLogPath, 'utf8');
        assert.match(stdout, /Run php -l syntax checks/);
        assert.match(stdout, /Run PHPUnit suite/);
        assert.match(dockerLog, /exec -T -w \/var\/www\/html php-fpm php -l application\/HookSmoke\.php/);
        assert.match(dockerLog, /run --rm php-fpm composer test/);
        assert.match(npxLog, /--yes prettier --check application\/HookSmoke\.php/);
    } finally {
        await rm(temporaryDirectory, {recursive: true, force: true});
    }
});
