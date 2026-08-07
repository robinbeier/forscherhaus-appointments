<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class RuntimeConfigPermissionsScriptTest extends TestCase
{
    private string $workspace;
    private string $appRoot;
    private string $script;
    private int $runtimeGroupId;

    protected function setUp(): void
    {
        parent::setUp();

        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('The production permission helper targets Linux metadata semantics.');
        }

        $uid = $this->runCommand(['id', '-u']);
        if ($uid['exit_code'] !== 0 || trim($uid['stdout']) !== '0') {
            $this->markTestSkipped('Root is required to exercise chown and runuser semantics.');
        }

        $runtimeGroup = $this->runCommand(['id', '-g', 'www-data']);
        if ($runtimeGroup['exit_code'] !== 0) {
            $this->markTestSkipped('The www-data runtime user is unavailable.');
        }

        $this->runtimeGroupId = (int) trim($runtimeGroup['stdout']);
        $this->workspace = sys_get_temp_dir() . '/runtime-config-permissions-' . bin2hex(random_bytes(6));
        $this->appRoot = $this->workspace . '/app';
        $this->script = dirname(__DIR__, 3) . '/scripts/ops/runtime_config_permissions.sh';

        mkdir($this->appRoot, 0777, true);
        file_put_contents($this->appRoot . '/config.php', "SENSITIVE_TEST_MARKER\n");
        chmod($this->appRoot, 0777);
        chmod($this->appRoot . '/config.php', 0666);
    }

    protected function tearDown(): void
    {
        if (isset($this->workspace)) {
            $this->removeDirectory($this->workspace);
        }

        parent::tearDown();
    }

    public function testHardenEstablishesAndVerifiesLeastPrivilegeContractWithoutPrintingContents(): void
    {
        $harden = $this->runHelper('harden');

        self::assertSame(0, $harden['exit_code'], $harden['stderr']);
        self::assertStringContainsString('config_mode=440', $harden['stdout']);
        self::assertStringContainsString('readable=yes writable=no replaceable=no', $harden['stdout']);
        self::assertStringNotContainsString('SENSITIVE_TEST_MARKER', $harden['stdout'] . $harden['stderr']);
        self::assertSame(0755, fileperms($this->appRoot) & 0777);
        self::assertSame(0440, fileperms($this->appRoot . '/config.php') & 0777);
        self::assertSame(0, fileowner($this->appRoot));
        self::assertSame(0, filegroup($this->appRoot));
        self::assertSame(0, fileowner($this->appRoot . '/config.php'));
        self::assertSame($this->runtimeGroupId, filegroup($this->appRoot . '/config.php'));

        $metadataBeforeVerify = $this->permissionMetadata();

        $verify = $this->runHelper('verify');
        self::assertSame(0, $verify['exit_code'], $verify['stderr']);
        self::assertStringNotContainsString('SENSITIVE_TEST_MARKER', $verify['stdout'] . $verify['stderr']);
        self::assertSame($metadataBeforeVerify, $this->permissionMetadata());
    }

    public function testVerifyFailsClosedWhenGenericPassMakesConfigWorldReadable(): void
    {
        self::assertSame(0, $this->runHelper('harden')['exit_code']);
        chmod($this->appRoot . '/config.php', 0644);

        $verify = $this->runHelper('verify');

        self::assertNotSame(0, $verify['exit_code']);
        self::assertStringContainsString('config.php mode must be 440', $verify['stderr']);
        self::assertStringNotContainsString('SENSITIVE_TEST_MARKER', $verify['stdout'] . $verify['stderr']);
    }

    public function testHardenRejectsSymlinkConfigWithoutTouchingTarget(): void
    {
        $target = $this->workspace . '/outside-config.php';
        file_put_contents($target, "SENSITIVE_TEST_MARKER\n");
        chmod($target, 0666);
        unlink($this->appRoot . '/config.php');
        symlink($target, $this->appRoot . '/config.php');

        $harden = $this->runHelper('harden');

        self::assertNotSame(0, $harden['exit_code']);
        self::assertStringContainsString('config.php must not be a symlink', $harden['stderr']);
        self::assertSame(0666, fileperms($target) & 0777);
        self::assertStringNotContainsString('SENSITIVE_TEST_MARKER', $harden['stdout'] . $harden['stderr']);
    }

    public function testHardenRejectsConfigWithMultipleHardlinks(): void
    {
        link($this->appRoot . '/config.php', $this->workspace . '/config-hardlink.php');

        $harden = $this->runHelper('harden');

        self::assertNotSame(0, $harden['exit_code']);
        self::assertStringContainsString('config.php must have exactly one hardlink', $harden['stderr']);
        self::assertStringNotContainsString('SENSITIVE_TEST_MARKER', $harden['stdout'] . $harden['stderr']);
    }

    public function testHardenRestoresPriorMetadataWhenRuntimeVerificationFailsAfterMutation(): void
    {
        chmod($this->workspace, 0700);
        $originalAppOwner = fileowner($this->appRoot);
        $originalAppGroup = filegroup($this->appRoot);
        $originalConfigOwner = fileowner($this->appRoot . '/config.php');
        $originalConfigGroup = filegroup($this->appRoot . '/config.php');

        $harden = $this->runHelper('harden');

        self::assertNotSame(0, $harden['exit_code']);
        self::assertStringContainsString('config.php is not readable by runtime user', $harden['stderr']);
        self::assertStringContainsString('Restored prior runtime config permission metadata', $harden['stderr']);
        self::assertSame(0777, fileperms($this->appRoot) & 0777);
        self::assertSame(0666, fileperms($this->appRoot . '/config.php') & 0777);
        self::assertSame($originalAppOwner, fileowner($this->appRoot));
        self::assertSame($originalAppGroup, filegroup($this->appRoot));
        self::assertSame($originalConfigOwner, fileowner($this->appRoot . '/config.php'));
        self::assertSame($originalConfigGroup, filegroup($this->appRoot . '/config.php'));
        self::assertStringNotContainsString('SENSITIVE_TEST_MARKER', $harden['stdout'] . $harden['stderr']);
    }

    public function testHardenFailsClosedForNonRootCaller(): void
    {
        $result = $this->runCommand([
            'runuser',
            '-u',
            'www-data',
            '--',
            'bash',
            $this->script,
            'harden',
            '--app-root',
            $this->appRoot,
            '--runtime-user',
            'www-data',
        ]);

        self::assertNotSame(0, $result['exit_code']);
        self::assertStringContainsString('harden requires root privileges', $result['stderr']);
        self::assertStringNotContainsString('SENSITIVE_TEST_MARKER', $result['stdout'] . $result['stderr']);
    }

    /**
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runHelper(string $action): array
    {
        return $this->runCommand([
            'bash',
            $this->script,
            $action,
            '--app-root',
            $this->appRoot,
            '--runtime-user',
            'www-data',
        ]);
    }

    /**
     * @return array{app_owner:int,app_group:int,app_mode:int,config_owner:int,config_group:int,config_mode:int}
     */
    private function permissionMetadata(): array
    {
        clearstatcache(true, $this->appRoot);
        clearstatcache(true, $this->appRoot . '/config.php');

        return [
            'app_owner' => (int) fileowner($this->appRoot),
            'app_group' => (int) filegroup($this->appRoot),
            'app_mode' => fileperms($this->appRoot) & 0777,
            'config_owner' => (int) fileowner($this->appRoot . '/config.php'),
            'config_group' => (int) filegroup($this->appRoot . '/config.php'),
            'config_mode' => fileperms($this->appRoot . '/config.php') & 0777,
        ];
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
