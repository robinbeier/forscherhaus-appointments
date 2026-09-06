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

    public function testShellOnlyChangePassesWithoutComposerOrNodeDependencies(): void
    {
        $repository = $this->repository();
        file_put_contents($repository . '/scripts/check.sh', "#!/usr/bin/env bash\necho ok\n");
        $this->stage($repository, 'scripts/check.sh');

        $result = $this->runHook($repository);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString('Pre-commit checks passed.', $result['stdout']);
    }

    public function testPhpChangeStillRequiresNodeDependencies(): void
    {
        $repository = $this->repository();
        file_put_contents($repository . '/application/config/test.php', "<?php\n");
        $this->stage($repository, 'application/config/test.php');

        $result = $this->runHook($repository);

        self::assertSame(1, $result['exit_code']);
        self::assertStringContainsString('Node dependencies missing', $result['stderr']);
    }

    public function testFrontendChangeStillRequiresNodeDependencies(): void
    {
        $repository = $this->repository();
        file_put_contents($repository . '/assets/icon.svg', "<svg></svg>\n");
        $this->stage($repository, 'assets/icon.svg');

        $result = $this->runHook($repository);

        self::assertSame(1, $result['exit_code']);
        self::assertStringContainsString('Node dependencies missing', $result['stderr']);
    }

    private function repository(): string
    {
        $repository = sys_get_temp_dir() . '/pre-commit-hook-' . bin2hex(random_bytes(8));
        mkdir($repository . '/scripts/hooks', 0777, true);
        mkdir($repository . '/scripts/ci', 0777, true);
        mkdir($repository . '/application/config', 0777, true);
        mkdir($repository . '/assets', 0777, true);
        copy(dirname(__DIR__, 3) . '/scripts/hooks/pre-commit', $repository . '/scripts/hooks/pre-commit');
        copy(
            dirname(__DIR__, 3) . '/scripts/ci/docker_compose_helpers.sh',
            $repository . '/scripts/ci/docker_compose_helpers.sh',
        );
        chmod($repository . '/scripts/hooks/pre-commit', 0755);
        $this->execute($repository, ['git', 'init', '-q']);
        $this->execute($repository, ['git', 'config', 'user.email', 'test@example.test']);
        $this->execute($repository, ['git', 'config', 'user.name', 'Test']);
        $this->execute($repository, ['git', 'add', 'scripts']);
        $this->execute($repository, ['git', 'commit', '-qm', 'initial']);
        $this->repositories[] = $repository;

        return $repository;
    }

    private function stage(string $repository, string $path): void
    {
        $this->execute($repository, ['git', 'add', $path]);
    }

    /** @return array{exit_code: int, stdout: string, stderr: string} */
    private function runHook(string $repository): array
    {
        return $this->execute($repository, ['bash', 'scripts/hooks/pre-commit']);
    }

    /** @param list<string> $arguments */
    /** @return array{exit_code: int, stdout: string, stderr: string} */
    private function execute(string $repository, array $arguments): array
    {
        $pipes = [];
        $process = proc_open($arguments, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $repository);
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
