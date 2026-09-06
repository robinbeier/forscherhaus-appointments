<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class JsLintChangedTest extends TestCase
{
    /** @var list<string> */
    private array $repositories = [];

    protected function tearDown(): void
    {
        foreach ($this->repositories as $repository) {
            $this->removeTree($repository);
        }
    }

    public function testCheckOnlyReportsFalseForNonJsChangesWithoutRunningEslint(): void
    {
        $repository = $this->repository();
        file_put_contents($repository . '/README.md', "initial\n");
        $this->commit($repository, 'initial');
        file_put_contents($repository . '/README.md', "changed\n");
        $this->commit($repository, 'docs');

        $result = $this->runLint($repository, ['--check-only']);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame("has_changes=false\n", file_get_contents($result['output_file']));
        self::assertFileDoesNotExist($result['eslint_log']);
    }

    public function testCheckOnlyReportsTrueForChangedJsWithoutRunningEslint(): void
    {
        $repository = $this->repository();
        file_put_contents($repository . '/assets/js/app.js', "const initial = true;\n");
        $this->commit($repository, 'initial');
        file_put_contents($repository . '/assets/js/app.js', "const changed = true;\n");
        $this->commit($repository, 'javascript');

        $result = $this->runLint($repository, ['--check-only']);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame("has_changes=true\n", file_get_contents($result['output_file']));
        self::assertFileDoesNotExist($result['eslint_log']);
    }

    public function testNormalModePassesChangedJsSubdirectoryAndRenameToEslint(): void
    {
        $repository = $this->repository();
        file_put_contents($repository . '/assets/js/app.js', "const initial = true;\n");
        mkdir($repository . '/assets/js/sub', 0777, true);
        file_put_contents($repository . '/assets/js/sub/old.js', "const oldName = true;\n");
        $this->commit($repository, 'initial');
        file_put_contents($repository . '/assets/js/app.js', "const changed = true;\n");
        $this->execute($repository, ['git', 'mv', 'assets/js/sub/old.js', 'assets/js/sub/renamed.js']);
        $this->commit($repository, 'javascript rename');

        $result = $this->runLint($repository);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame(
            ['--max-warnings=0', 'assets/js/app.js', 'assets/js/sub/renamed.js'],
            file($result['eslint_log'], FILE_IGNORE_NEW_LINES),
        );
    }

    public function testDeletedAndMinifiedChangesAreExcluded(): void
    {
        $repository = $this->repository();
        file_put_contents($repository . '/assets/js/removed.js', "const removed = true;\n");
        file_put_contents($repository . '/assets/js/app.min.js', "const minified = true;\n");
        $this->commit($repository, 'initial');
        unlink($repository . '/assets/js/removed.js');
        file_put_contents($repository . '/assets/js/app.min.js', "const minified = false;\n");
        $this->commit($repository, 'excluded javascript');

        $result = $this->runLint($repository, ['--check-only']);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame("has_changes=false\n", file_get_contents($result['output_file']));
        self::assertFileDoesNotExist($result['eslint_log']);
    }

    public function testInvalidRevisionFailsClosed(): void
    {
        $repository = $this->repository();
        file_put_contents($repository . '/assets/js/app.js', "const initial = true;\n");
        $this->commit($repository, 'initial');
        file_put_contents($repository . '/assets/js/app.js', "const changed = true;\n");
        $this->commit($repository, 'javascript');

        $result = $this->runLint(
            $repository,
            [],
            ['GITHUB_EVENT_NAME' => 'push', 'GITHUB_EVENT_BEFORE' => str_repeat('d', 40)],
        );

        self::assertNotSame(0, $result['exit_code']);
        self::assertStringContainsString('Unable to determine changed files', $result['stderr']);
        self::assertFileDoesNotExist($result['eslint_log']);
    }

    private function repository(): string
    {
        $repository = sys_get_temp_dir() . '/js-lint-changed-' . bin2hex(random_bytes(8));
        mkdir($repository . '/scripts/ci', 0777, true);
        mkdir($repository . '/assets/js', 0777, true);
        mkdir($repository . '/node_modules/.bin', 0777, true);
        copy(dirname(__DIR__, 3) . '/scripts/ci/js-lint-changed.sh', $repository . '/scripts/ci/js-lint-changed.sh');
        copy(dirname(__DIR__, 3) . '/scripts/ci/git_helpers.sh', $repository . '/scripts/ci/git_helpers.sh');
        file_put_contents(
            $repository . '/node_modules/.bin/eslint',
            "#!/usr/bin/env bash\nprintf '%s\\n' \"\$@\" > \"\${ESLINT_LOG}\"\n",
        );
        chmod($repository . '/node_modules/.bin/eslint', 0755);
        $this->execute($repository, ['git', 'init', '-q']);
        $this->execute($repository, ['git', 'branch', '-M', 'main']);
        $this->execute($repository, ['git', 'config', 'user.email', 'test@example.test']);
        $this->execute($repository, ['git', 'config', 'user.name', 'Test']);
        $this->repositories[] = $repository;

        return $repository;
    }

    private function commit(string $repository, string $message): void
    {
        $this->execute($repository, ['git', 'add', '-A']);
        $this->execute($repository, ['git', 'commit', '-qm', $message]);
    }

    /** @param list<string> $arguments */
    /** @param array<string, string> $environment */
    private function execute(string $repository, array $arguments, array $environment = []): array
    {
        $pipes = [];
        $process = proc_open(
            $arguments,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $repository,
            array_merge(getenv(), $environment),
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit_code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @param list<string> $arguments */
    /** @param array<string, string> $extraEnvironment */
    private function runLint(string $repository, array $arguments = [], array $extraEnvironment = []): array
    {
        $outputFile = $repository . '/github-output';
        $eslintLog = $repository . '/eslint-log';
        $environment = array_merge(
            [
                'GITHUB_EVENT_NAME' => 'local',
                'GITHUB_OUTPUT' => $outputFile,
                'ESLINT_LOG' => $eslintLog,
            ],
            $extraEnvironment,
        );
        $result = $this->execute(
            $repository,
            array_merge(['bash', 'scripts/ci/js-lint-changed.sh'], $arguments),
            $environment,
        );
        $result['output_file'] = $outputFile;
        $result['eslint_log'] = $eslintLog;

        return $result;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;
            is_dir($child) && !is_link($child) ? $this->removeTree($child) : unlink($child);
        }
        rmdir($path);
    }
}
