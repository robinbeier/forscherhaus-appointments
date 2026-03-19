<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

class CleanupLocalArtifactsScriptTest extends TestCase
{
    public function testDryRunReportsTargetsWithoutDeletingAnything(): void
    {
        $repo = $this->createFixtureRepo();

        try {
            $result = $this->runCommand(
                ['bash', 'scripts/cleanup_local_artifacts.sh', '--dry-run', '--with-deps'],
                $repo,
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertMatchesRegularExpression('~would remove contents of .*/storage/logs~', $result['stdout']);
            self::assertMatchesRegularExpression('~would remove .*/vendor~', $result['stdout']);
            self::assertMatchesRegularExpression('~would remove .*/node_modules~', $result['stdout']);
            self::assertMatchesRegularExpression('~preserved .*/docker/mysql~', $result['stdout']);

            self::assertFileExists($repo . '/storage/logs/debug.log');
            self::assertFileExists($repo . '/storage/logs/nested/evidence.json');
            self::assertFileExists($repo . '/build/output.txt');
            self::assertFileExists($repo . '/.phpunit.cache/result');
            self::assertFileExists($repo . '/easyappointments-0.0.0.zip');
            self::assertFileExists($repo . '/vendor/autoload.php');
            self::assertFileExists($repo . '/node_modules/pkg/index.js');
        } finally {
            $this->removeDirectory($repo);
        }
    }

    public function testCleanupRemovesRuntimeArtifactsAndPreservesPlaceholdersByDefault(): void
    {
        $repo = $this->createFixtureRepo();

        try {
            $result = $this->runCommand(['bash', 'scripts/cleanup_local_artifacts.sh'], $repo);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertFileExists($repo . '/storage/logs/.htaccess');
            self::assertFileExists($repo . '/storage/logs/index.html');
            self::assertFileDoesNotExist($repo . '/storage/logs/debug.log');
            self::assertFileDoesNotExist($repo . '/storage/logs/nested/evidence.json');
            self::assertDirectoryDoesNotExist($repo . '/build');
            self::assertDirectoryDoesNotExist($repo . '/.phpunit.cache');
            self::assertFileDoesNotExist($repo . '/easyappointments-0.0.0.zip');
            self::assertFileExists($repo . '/vendor/autoload.php');
            self::assertFileExists($repo . '/node_modules/pkg/index.js');
            self::assertFileExists($repo . '/docker/mysql/ibdata1');
        } finally {
            $this->removeDirectory($repo);
        }
    }

    public function testCleanupWithDepsAlsoRemovesDependencyDirectories(): void
    {
        $repo = $this->createFixtureRepo();

        try {
            $result = $this->runCommand(['bash', 'scripts/cleanup_local_artifacts.sh', '--with-deps'], $repo);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertDirectoryDoesNotExist($repo . '/vendor');
            self::assertDirectoryDoesNotExist($repo . '/node_modules');
            self::assertFileExists($repo . '/docker/mysql/ibdata1');
        } finally {
            $this->removeDirectory($repo);
        }
    }

    public function testCleanupHandlesMissingOptionalPathsGracefully(): void
    {
        $repo = $this->createMinimalRepo();

        try {
            $result = $this->runCommand(['bash', 'scripts/cleanup_local_artifacts.sh', '--with-deps'], $repo);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertMatchesRegularExpression('~skip .*/storage/logs \\(missing\\)~', $result['stdout']);
            self::assertMatchesRegularExpression('~skip .*/build \\(missing\\)~', $result['stdout']);
            self::assertMatchesRegularExpression('~skip .*/\\.phpunit\\.cache \\(missing\\)~', $result['stdout']);
            self::assertMatchesRegularExpression(
                '~skip .*/easyappointments-0\\.0\\.0\\.zip \\(missing\\)~',
                $result['stdout'],
            );
            self::assertMatchesRegularExpression('~skip .*/vendor \\(missing\\)~', $result['stdout']);
            self::assertMatchesRegularExpression('~skip .*/node_modules \\(missing\\)~', $result['stdout']);
            self::assertFileExists($repo . '/docker/mysql/ibdata1');
        } finally {
            $this->removeDirectory($repo);
        }
    }

    public function testCleanupStillRunsWhenRepoSizeProbeCannotReadPreservedMysqlData(): void
    {
        $repo = $this->createFixtureRepo();
        $duStubDir = $repo . '/bin';
        mkdir($duStubDir, 0777, true);
        file_put_contents($duStubDir . '/du', "#!/usr/bin/env bash\nexit 1\n");
        chmod($duStubDir . '/du', 0755);

        try {
            $result = $this->runCommand(['bash', 'scripts/cleanup_local_artifacts.sh'], $repo, [
                'PATH' => $duStubDir . PATH_SEPARATOR . (getenv('PATH') ?: ''),
            ]);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertMatchesRegularExpression('~repo size before: unknown~', $result['stdout']);
            self::assertFileExists($repo . '/docker/mysql/ibdata1');
            self::assertFileDoesNotExist($repo . '/build/output.txt');
            self::assertFileDoesNotExist($repo . '/storage/logs/debug.log');
        } finally {
            $this->removeDirectory($repo);
        }
    }

    public function testCleanupStillRemovesAccessibleArtifactsWhenStorageLogsContainsUnreadableEntries(): void
    {
        $repo = $this->createFixtureRepo();
        $stubDir = $repo . '/bin';
        mkdir($stubDir, 0777, true);
        file_put_contents(
            $stubDir . '/find',
            "#!/usr/bin/env bash\nlogs_dir=\"\$1\"\nrm -rf \"\$logs_dir/debug.log\" \"\$logs_dir/nested\"\nexit 1\n",
        );
        chmod($stubDir . '/find', 0755);

        try {
            $result = $this->runCommand(['bash', 'scripts/cleanup_local_artifacts.sh'], $repo, [
                'PATH' => $stubDir . PATH_SEPARATOR . (getenv('PATH') ?: ''),
            ]);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertMatchesRegularExpression(
                '~removed accessible contents of .*/storage/logs .*manual cleanup~',
                $result['stdout'],
            );
            self::assertFileExists($repo . '/storage/logs/.htaccess');
            self::assertFileExists($repo . '/storage/logs/index.html');
            self::assertFileDoesNotExist($repo . '/storage/logs/debug.log');
            self::assertDirectoryDoesNotExist($repo . '/storage/logs/nested');
            self::assertFileDoesNotExist($repo . '/build/output.txt');
            self::assertFileDoesNotExist($repo . '/easyappointments-0.0.0.zip');
        } finally {
            $this->removeDirectory($repo);
        }
    }

    /**
     * @param list<string> $command
     * @param array<string,string> $env
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runCommand(array $command, string $cwd, array $env = []): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $cwd, array_merge($_ENV, $env));
        self::assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exit_code' => $exitCode,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function createFixtureRepo(): string
    {
        $repo = sys_get_temp_dir() . '/cleanup-local-artifacts-' . bin2hex(random_bytes(8));

        mkdir($repo . '/scripts', 0777, true);
        mkdir($repo . '/storage/logs/nested', 0777, true);
        mkdir($repo . '/build', 0777, true);
        mkdir($repo . '/.phpunit.cache', 0777, true);
        mkdir($repo . '/vendor', 0777, true);
        mkdir($repo . '/node_modules/pkg', 0777, true);
        mkdir($repo . '/docker/mysql', 0777, true);

        $scriptSource = file_get_contents(dirname(__DIR__, 3) . '/scripts/cleanup_local_artifacts.sh');
        self::assertIsString($scriptSource);

        file_put_contents($repo . '/scripts/cleanup_local_artifacts.sh', $scriptSource);
        chmod($repo . '/scripts/cleanup_local_artifacts.sh', 0755);

        file_put_contents($repo . '/storage/logs/.htaccess', "deny from all\n");
        file_put_contents($repo . '/storage/logs/index.html', "<html></html>\n");
        file_put_contents($repo . '/storage/logs/debug.log', "debug\n");
        file_put_contents($repo . '/storage/logs/nested/evidence.json', "{}\n");
        file_put_contents($repo . '/build/output.txt', "build\n");
        file_put_contents($repo . '/.phpunit.cache/result', "cache\n");
        file_put_contents($repo . '/easyappointments-0.0.0.zip', "zip\n");
        file_put_contents($repo . '/vendor/autoload.php', "<?php\n");
        file_put_contents($repo . '/node_modules/pkg/index.js', "export {};\n");
        file_put_contents($repo . '/docker/mysql/ibdata1', "mysql\n");

        return $repo;
    }

    private function createMinimalRepo(): string
    {
        $repo = sys_get_temp_dir() . '/cleanup-local-artifacts-minimal-' . bin2hex(random_bytes(8));

        mkdir($repo . '/scripts', 0777, true);
        mkdir($repo . '/docker/mysql', 0777, true);

        $scriptSource = file_get_contents(dirname(__DIR__, 3) . '/scripts/cleanup_local_artifacts.sh');
        self::assertIsString($scriptSource);

        file_put_contents($repo . '/scripts/cleanup_local_artifacts.sh', $scriptSource);
        chmod($repo . '/scripts/cleanup_local_artifacts.sh', 0755);
        file_put_contents($repo . '/docker/mysql/ibdata1', "mysql\n");

        return $repo;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($path);
    }
}
