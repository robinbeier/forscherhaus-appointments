<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class PreCommitHookTest extends TestCase
{
    /** @var list<string> */
    private array $repositories = [];

    protected function tearDown(): void
    {
        foreach ($this->repositories as $repository) {
            $this->removeTree($repository);
        }
    }

    public function testInstalledHookPassesWithoutExecutingCheckoutCode(): void
    {
        $repository = $this->repository();
        file_put_contents($repository . '/scripts/ci/docker_compose_helpers.sh', "touch sentinel\n");
        file_put_contents($repository . '/scripts/hooks/pre-commit', "touch sentinel\n");
        file_put_contents($repository . '/application/config/test.php', "<?php\n");
        file_put_contents($repository . '/assets/icon.js', "console.log('fixture');\n");
        $this->stage($repository, 'scripts/ci/docker_compose_helpers.sh');
        $this->stage($repository, 'scripts/hooks/pre-commit');
        $this->stage($repository, 'application/config/test.php');
        $this->stage($repository, 'assets/icon.js');

        $result = $this->runCommit($repository);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString('Pre-commit checks passed.', $result['stdout'] . $result['stderr']);
        self::assertFileDoesNotExist($repository . '/sentinel');
    }

    public function testForbiddenStorageLogsFileFails(): void
    {
        $repository = $this->repository();
        mkdir($repository . '/storage/logs', 0777, true);
        file_put_contents($repository . '/storage/logs/app.log', "secret\n");
        $this->stage($repository, 'storage/logs/app.log');

        $result = $this->runCommit($repository);

        self::assertSame(1, $result['exit_code']);
        self::assertStringContainsString('storage/logs', $result['stderr']);
    }

    public function testWhitespaceErrorFails(): void
    {
        $repository = $this->repository();
        file_put_contents($repository . '/scripts/check.sh', "#!/usr/bin/env bash  \n");
        $this->stage($repository, 'scripts/check.sh');

        $result = $this->runCommit($repository);

        self::assertSame(1, $result['exit_code']);
        self::assertStringContainsString('Whitespace errors', $result['stderr']);
    }

    private function repository(): string
    {
        $repository = sys_get_temp_dir() . '/pre-commit-hook-' . bin2hex(random_bytes(8));
        mkdir($repository . '/scripts/hooks', 0777, true);
        mkdir($repository . '/scripts/ci', 0777, true);
        mkdir($repository . '/application/config', 0777, true);
        mkdir($repository . '/assets', 0777, true);
        copy(dirname(__DIR__, 3) . '/scripts/hooks/pre-commit', $repository . '/scripts/hooks/pre-commit');
        file_put_contents(
            $repository . '/scripts/ci/docker_compose_helpers.sh',
            "touch \"\$(git rev-parse --show-toplevel)/sentinel\"\n",
        );
        file_put_contents(
            $repository . '/package.json',
            '{"scripts":{"build":"touch sentinel"}}' . PHP_EOL,
        );
        file_put_contents(
            $repository . '/docker-compose.yml',
            "services:\n  fixture:\n    image: alpine\n    command: ['sh', '-c', 'touch sentinel']\n",
        );
        chmod($repository . '/scripts/hooks/pre-commit', 0755);
        $this->execute($repository, ['git', 'init', '-q']);
        $this->execute($repository, ['git', 'config', 'user.email', 'test@example.test']);
        $this->execute($repository, ['git', 'config', 'user.name', 'Test']);
        $this->execute($repository, ['git', 'add', 'scripts', 'package.json', 'docker-compose.yml']);
        $this->execute($repository, ['git', 'commit', '-qm', 'initial']);
        copy($repository . '/scripts/hooks/pre-commit', $repository . '/.git/hooks/pre-commit');
        chmod($repository . '/.git/hooks/pre-commit', 0755);
        $this->repositories[] = $repository;

        return $repository;
    }

    private function stage(string $repository, string $path): void
    {
        $this->execute($repository, ['git', 'add', $path]);
    }

    /** @return array{exit_code: int, stdout: string, stderr: string} */
    private function runCommit(string $repository): array
    {
        return $this->execute($repository, ['git', 'commit', '-m', 'fixture change']);
    }

    /** @param list<string> $arguments */
    /** @return array{exit_code: int, stdout: string, stderr: string} */
    private function execute(string $repository, array $arguments): array
    {
        $pipes = [];
        $process = proc_open(
            $arguments,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $repository,
            getenv(),
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit_code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
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
