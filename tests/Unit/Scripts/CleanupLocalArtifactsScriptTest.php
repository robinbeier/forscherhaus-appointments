<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class CleanupLocalArtifactsScriptTest extends TestCase
{
    public function testCleanupPreservesPlaceholdersAndDependenciesByDefault(): void
    {
        $workspace = $this->createWorkspace();

        try {
            $this->seedArtifacts($workspace);

            $result = $this->runCommand(['bash', 'scripts/cleanup_local_artifacts.sh'], $workspace);

            $this->assertSame(0, $result['exit_code'], $result['stderr']);
            $this->assertFileExists($workspace . '/storage/logs/.htaccess');
            $this->assertFileExists($workspace . '/storage/logs/index.html');
            $this->assertDirectoryDoesNotExist($workspace . '/storage/logs/release-gate');
            $this->assertFileDoesNotExist($workspace . '/storage/logs/debug.log');
            $this->assertDirectoryDoesNotExist($workspace . '/build');
            $this->assertDirectoryDoesNotExist($workspace . '/.phpunit.cache');
            $this->assertFileDoesNotExist($workspace . '/easyappointments-0.0.0.zip');
            $this->assertDirectoryExists($workspace . '/vendor');
            $this->assertDirectoryExists($workspace . '/node_modules');
            $this->assertDirectoryExists($workspace . '/docker/mysql');
            $this->assertFileExists($workspace . '/docker/mysql/keep.txt');
            $this->assertStringContainsString(
                'preserved ' . $workspace . '/vendor and ' . $workspace . '/node_modules',
                $result['stdout'],
            );
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testCleanupWithDepsRemovesDependencies(): void
    {
        $workspace = $this->createWorkspace();

        try {
            $this->seedArtifacts($workspace);

            $result = $this->runCommand(['bash', 'scripts/cleanup_local_artifacts.sh', '--with-deps'], $workspace);

            $this->assertSame(0, $result['exit_code'], $result['stderr']);
            $this->assertDirectoryDoesNotExist($workspace . '/vendor');
            $this->assertDirectoryDoesNotExist($workspace . '/node_modules');
            $this->assertDirectoryExists($workspace . '/docker/mysql');
            $this->assertFileExists($workspace . '/docker/mysql/keep.txt');
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    private function createWorkspace(): string
    {
        $repoRoot = dirname(__DIR__, 3);
        $workspace = sys_get_temp_dir() . '/cleanup-local-artifacts-' . bin2hex(random_bytes(4));

        mkdir($workspace . '/scripts', 0777, true);
        copy($repoRoot . '/scripts/cleanup_local_artifacts.sh', $workspace . '/scripts/cleanup_local_artifacts.sh');

        return $workspace;
    }

    private function seedArtifacts(string $workspace): void
    {
        mkdir($workspace . '/storage/logs/release-gate', 0777, true);
        mkdir($workspace . '/build', 0777, true);
        mkdir($workspace . '/.phpunit.cache', 0777, true);
        mkdir($workspace . '/vendor', 0777, true);
        mkdir($workspace . '/node_modules', 0777, true);
        mkdir($workspace . '/docker/mysql', 0777, true);

        file_put_contents($workspace . '/storage/logs/.htaccess', 'placeholder');
        file_put_contents($workspace . '/storage/logs/index.html', 'placeholder');
        file_put_contents($workspace . '/storage/logs/debug.log', 'log');
        file_put_contents($workspace . '/storage/logs/release-gate/report.json', '{}');
        file_put_contents($workspace . '/build/output.txt', 'build');
        file_put_contents($workspace . '/.phpunit.cache/state', 'cache');
        file_put_contents($workspace . '/easyappointments-0.0.0.zip', 'zip');
        file_put_contents($workspace . '/vendor/autoload.php', '<?php');
        file_put_contents($workspace . '/node_modules/module.txt', 'module');
        file_put_contents($workspace . '/docker/mysql/keep.txt', 'keep');
    }

    /**
     * @param list<string> $command
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runCommand(array $command, string $cwd): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $cwd);
        $this->assertIsResource($process);

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
