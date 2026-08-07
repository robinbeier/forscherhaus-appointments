<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class RuntimeConfigRollbackScriptTest extends TestCase
{
    private string $workspace;
    private string $activePath;
    private string $previousPath;
    private string $failedPath;
    private string $rollbackScript;
    private int $runtimeGroupId;

    protected function setUp(): void
    {
        parent::setUp();

        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('The production rollback helper targets Linux metadata semantics.');
        }

        $uid = $this->runCommand(['id', '-u']);
        if ($uid['exit_code'] !== 0 || trim($uid['stdout']) !== '0') {
            $this->markTestSkipped('Root is required to exercise rollback ownership semantics.');
        }

        $runtimeGroup = $this->runCommand(['id', '-g', 'www-data']);
        if ($runtimeGroup['exit_code'] !== 0) {
            $this->markTestSkipped('The www-data runtime user is unavailable.');
        }

        $this->runtimeGroupId = (int) trim($runtimeGroup['stdout']);
        $this->workspace = sys_get_temp_dir() . '/runtime-config-rollback-' . bin2hex(random_bytes(6));
        $this->activePath = $this->workspace . '/app';
        $this->previousPath = $this->workspace . '/app_prev_release';
        $this->failedPath = $this->workspace . '/app_failed_release';

        $repoRoot = dirname(__DIR__, 3);
        $this->createReleaseTree($this->activePath, $repoRoot, 'ACTIVE_RELEASE_MARKER');
        $this->createReleaseTree($this->previousPath, $repoRoot, 'PREVIOUS_RELEASE_MARKER');
        $this->rollbackScript = $this->activePath . '/scripts/ops/runtime_config_rollback.sh';
    }

    protected function tearDown(): void
    {
        if (isset($this->workspace)) {
            $this->removeDirectory($this->workspace);
        }

        parent::tearDown();
    }

    public function testRollbackSwitchesTreesAndHardensBothConfigsThroughTheProductionScript(): void
    {
        $result = $this->runRollback();

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertDirectoryExists($this->activePath);
        self::assertDirectoryDoesNotExist($this->previousPath);
        self::assertDirectoryExists($this->failedPath);
        self::assertFileExists($this->activePath . '/PREVIOUS_RELEASE_MARKER');
        self::assertFileExists($this->failedPath . '/ACTIVE_RELEASE_MARKER');
        $this->assertPermissionContract($this->activePath);
        $this->assertPermissionContract($this->failedPath);
        self::assertStringContainsString('rollback switch and permission contracts verified', $result['stdout']);
        self::assertStringNotContainsString('SENSITIVE_TEST_MARKER', $result['stdout'] . $result['stderr']);
    }

    public function testRollbackFailsBeforeMovingAnythingWhenFailedTargetAlreadyExists(): void
    {
        mkdir($this->failedPath, 0777, true);

        $result = $this->runRollback();

        self::assertNotSame(0, $result['exit_code']);
        self::assertStringContainsString('failed release target already exists', $result['stderr']);
        self::assertDirectoryExists($this->activePath);
        self::assertDirectoryExists($this->previousPath);
        self::assertFileExists($this->activePath . '/ACTIVE_RELEASE_MARKER');
        self::assertFileExists($this->previousPath . '/PREVIOUS_RELEASE_MARKER');
    }

    public function testRollbackFailsClosedWhenRestoredConfigCannotBeHardenedAfterSwitch(): void
    {
        link($this->previousPath . '/config.php', $this->workspace . '/previous-config-hardlink.php');

        $result = $this->runRollback();

        self::assertNotSame(0, $result['exit_code']);
        self::assertStringContainsString(
            'Restored release runtime config permissions are unverifiable',
            $result['stderr'],
        );
        self::assertStringNotContainsString('rollback switch and permission contracts verified', $result['stdout']);
        self::assertDirectoryExists($this->activePath);
        self::assertDirectoryDoesNotExist($this->previousPath);
        self::assertDirectoryExists($this->failedPath);
        self::assertFileExists($this->activePath . '/PREVIOUS_RELEASE_MARKER');
        self::assertFileExists($this->failedPath . '/ACTIVE_RELEASE_MARKER');
        $restoredConfigMetadata = stat($this->activePath . '/config.php');
        self::assertIsArray($restoredConfigMetadata);
        self::assertSame(2, $restoredConfigMetadata['nlink']);
        $this->assertPermissionContract($this->failedPath);
        self::assertStringNotContainsString('SENSITIVE_TEST_MARKER', $result['stdout'] . $result['stderr']);
    }

    private function createReleaseTree(string $path, string $repoRoot, string $marker): void
    {
        mkdir($path . '/scripts/ops', 0777, true);
        file_put_contents($path . '/config.php', "SENSITIVE_TEST_MARKER\n");
        file_put_contents($path . '/' . $marker, '');
        copy(
            $repoRoot . '/scripts/ops/runtime_config_permissions.sh',
            $path . '/scripts/ops/runtime_config_permissions.sh',
        );
        copy($repoRoot . '/scripts/ops/runtime_config_rollback.sh', $path . '/scripts/ops/runtime_config_rollback.sh');
        chmod($path, 0777);
        chmod($path . '/config.php', 0666);
        chmod($path . '/scripts/ops/runtime_config_permissions.sh', 0755);
        chmod($path . '/scripts/ops/runtime_config_rollback.sh', 0755);
    }

    private function assertPermissionContract(string $path): void
    {
        clearstatcache(true, $path);
        clearstatcache(true, $path . '/config.php');
        self::assertSame(0, fileowner($path));
        self::assertSame(0, filegroup($path));
        self::assertSame(0755, fileperms($path) & 0777);
        self::assertSame(0, fileowner($path . '/config.php'));
        self::assertSame($this->runtimeGroupId, filegroup($path . '/config.php'));
        self::assertSame(0440, fileperms($path . '/config.php') & 0777);
    }

    /**
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runRollback(): array
    {
        return $this->runCommand([
            'bash',
            $this->rollbackScript,
            '--active',
            $this->activePath,
            '--previous',
            $this->previousPath,
            '--failed',
            $this->failedPath,
            '--runtime-user',
            'www-data',
        ]);
    }

    /**
     * @param list<string> $command
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runCommand(array $command): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, dirname(__DIR__, 3));
        self::assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit_code' => proc_close($process),
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
            if ($item->isLink() || $item->isFile()) {
                unlink($item->getPathname());
                continue;
            }

            rmdir($item->getPathname());
        }

        rmdir($path);
    }
}
