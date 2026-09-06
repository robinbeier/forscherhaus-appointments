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

    public function testPhpChangeUsesUniqueOneShotProjectWithoutVendorBootstrap(): void
    {
        $repository = $this->repository(true);
        file_put_contents($repository . '/application/config/valid.php', "<?php\n");
        $this->stage($repository, 'application/config/valid.php');

        $result = $this->runHook($repository);
        $log = (string) file_get_contents($repository . '/compose.log');

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString('run --rm --no-deps -T', $log);
        self::assertStringContainsString('-lint-', $log);
        self::assertStringNotContainsString(' up ', $log);
        self::assertStringNotContainsString(' exec ', $log);
        self::assertStringNotContainsString(' -v', $log);
        self::assertFileDoesNotExist($repository . '/vendor');
    }

    public function testOneShotProjectReportsAnInvalidSecondPhpFile(): void
    {
        $repository = $this->repository(true);
        file_put_contents($repository . '/application/config/valid.php', "<?php\n");
        file_put_contents($repository . '/application/config/zz-invalid.php', "<?php function broken( {\n");
        $this->stage($repository, 'application/config/valid.php');
        $this->stage($repository, 'application/config/zz-invalid.php');

        $result = $this->runHook($repository);

        self::assertSame(1, $result['exit_code']);
        self::assertStringContainsString('zz-invalid.php', $result['stdout']);
        self::assertStringContainsString('PHP syntax errors detected', $result['stderr']);
    }

    public function testOneShotFailureCleansUpItsOwnComposeProject(): void
    {
        $repository = $this->repository(true, true);
        file_put_contents($repository . '/application/config/valid.php', "<?php\n");
        $this->stage($repository, 'application/config/valid.php');

        $result = $this->runHook($repository);
        $log = (string) file_get_contents($repository . '/compose.log');

        self::assertSame(1, $result['exit_code']);
        self::assertStringContainsString('run --rm --no-deps -T', $log);
        self::assertStringContainsString('down --remove-orphans', $log);
        self::assertStringNotContainsString(' -v', $log);
    }

    private function repository(bool $withNode = false, bool $composeFailure = false): string
    {
        $repository = sys_get_temp_dir() . '/pre-commit-hook-' . bin2hex(random_bytes(8));
        mkdir($repository . '/scripts/hooks', 0777, true);
        mkdir($repository . '/scripts/ci', 0777, true);
        mkdir($repository . '/application/config', 0777, true);
        mkdir($repository . '/assets', 0777, true);
        copy(dirname(__DIR__, 3) . '/scripts/hooks/pre-commit', $repository . '/scripts/hooks/pre-commit');
        $hook = file_get_contents($repository . '/scripts/hooks/pre-commit');
        self::assertIsString($hook);
        file_put_contents(
            $repository . '/scripts/hooks/pre-commit',
            str_replace('/.dockerenv', '/.precommit-test-dockerenv', $hook),
        );
        copy(
            dirname(__DIR__, 3) . '/scripts/ci/docker_compose_helpers.sh',
            $repository . '/scripts/ci/docker_compose_helpers.sh',
        );
        if ($withNode) {
            mkdir($repository . '/node_modules', 0777, true);
            mkdir($repository . '/bin', 0777, true);
            file_put_contents($repository . '/bin/npx', "#!/usr/bin/env bash\nexit 0\n");
            file_put_contents($repository . '/bin/docker', "#!/usr/bin/env bash\nexit 0\n");
            chmod($repository . '/bin/npx', 0755);
            chmod($repository . '/bin/docker', 0755);
            file_put_contents($repository . '/compose.log', '');
            if ($composeFailure) {
                file_put_contents($repository . '/force-compose-failure', '1');
            }
            file_put_contents(
                $repository . '/scripts/ci/docker_compose_helpers.sh',
                <<<'BASH'
                #!/usr/bin/env bash
                ci_docker_compose() {
                    printf 'project=%s %s\n' "${CI_DOCKER_COMPOSE_PROJECT_NAME:-}" "$*" >> "${COMPOSE_LOG}"
                    if [[ "$1" == down ]]; then return 0; fi
                    if [[ "${FORCE_COMPOSE_FAILURE:-0}" == 1 ]]; then return 23; fi
                    while [[ "$1" != php-fpm ]]; do shift; done
                    shift
                    "$@"
                }
                BASH
                ,
            );
        }
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
        $environment = [];
        if (is_file($repository . '/bin/npx')) {
            $environment['PATH'] = $repository . '/bin:' . (getenv('PATH') ?: '');
            $environment['COMPOSE_LOG'] = $repository . '/compose.log';
            if (is_file($repository . '/force-compose-failure')) {
                $environment['FORCE_COMPOSE_FAILURE'] = '1';
            }
        }
        return $this->execute($repository, ['bash', 'scripts/hooks/pre-commit'], $environment);
    }

    /** @param list<string> $arguments */
    /** @return array{exit_code: int, stdout: string, stderr: string} */
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
