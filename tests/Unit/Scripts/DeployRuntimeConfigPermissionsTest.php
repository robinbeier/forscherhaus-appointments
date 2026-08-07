<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class DeployRuntimeConfigPermissionsTest extends TestCase
{
    private string $workspace;
    private string $appRoot;
    private string $trustedDeployScript;
    private int $runtimeUserId;
    private int $runtimeGroupId;

    protected function setUp(): void
    {
        parent::setUp();

        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('The production permission contract targets Linux metadata semantics.');
        }

        $uid = $this->runCommand(['id', '-u']);
        if ($uid['exit_code'] !== 0 || trim($uid['stdout']) !== '0') {
            $this->markTestSkipped('Root is required to exercise chown and runuser semantics.');
        }

        $runtimeUser = $this->runCommand(['id', '-u', 'www-data']);
        $runtimeGroup = $this->runCommand(['id', '-g', 'www-data']);
        if ($runtimeUser['exit_code'] !== 0 || $runtimeGroup['exit_code'] !== 0) {
            $this->markTestSkipped('The www-data runtime user is unavailable.');
        }

        $this->runtimeUserId = (int) trim($runtimeUser['stdout']);
        $this->runtimeGroupId = (int) trim($runtimeGroup['stdout']);
        $this->workspace = '/rob442-runtime-config-' . bin2hex(random_bytes(6));
        $this->appRoot = $this->workspace . '/app';
        $trustedRoot = $this->workspace . '/trusted';
        $this->trustedDeployScript = $trustedRoot . '/deploy_ea.sh';

        mkdir($trustedRoot, 0755, true);
        copy(dirname(__DIR__, 3) . '/deploy_ea.sh', $this->trustedDeployScript);
        chmod($this->trustedDeployScript, 0755);
        mkdir($this->appRoot, 0777, true);
        file_put_contents($this->appRoot . '/config.php', "SENSITIVE_TEST_MARKER\n");
        $this->setRuntimeOwned($this->appRoot, 0777);
        $this->setRuntimeOwned($this->appRoot . '/config.php', 0666);
    }

    protected function tearDown(): void
    {
        if (isset($this->workspace)) {
            $this->removeDirectory($this->workspace);
        }

        parent::tearDown();
    }

    public function testTrustedHostDeployScriptHardensAndVerifiesWithoutPrintingContents(): void
    {
        $harden = $this->runPermissionMode('harden');

        self::assertSame(0, $harden['exit_code'], $harden['stderr']);
        self::assertStringContainsString('config_mode=440', $harden['stdout']);
        self::assertStringContainsString('readable=yes writable=no replaceable=no', $harden['stdout']);
        self::assertStringNotContainsString('SENSITIVE_TEST_MARKER', $harden['stdout'] . $harden['stderr']);
        $this->assertPermissionContract($this->appRoot);

        $metadataBeforeVerify = $this->permissionMetadata($this->appRoot);
        $verify = $this->runPermissionMode('verify');

        self::assertSame(0, $verify['exit_code'], $verify['stderr']);
        self::assertSame($metadataBeforeVerify, $this->permissionMetadata($this->appRoot));
        self::assertStringNotContainsString('SENSITIVE_TEST_MARKER', $verify['stdout'] . $verify['stderr']);
    }

    public function testVerifyFailsClosedWhenGenericPassMakesConfigWorldReadable(): void
    {
        self::assertSame(0, $this->runPermissionMode('harden')['exit_code']);
        chmod($this->appRoot . '/config.php', 0644);

        $verify = $this->runPermissionMode('verify');

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

        $harden = $this->runPermissionMode('harden');

        self::assertNotSame(0, $harden['exit_code']);
        self::assertStringContainsString('config.php must be a regular non-symlink file', $harden['stderr']);
        self::assertSame(0666, fileperms($target) & 0777);
        self::assertSame($this->runtimeUserId, fileowner($this->appRoot));
        self::assertSame(0777, fileperms($this->appRoot) & 0777);
        self::assertStringNotContainsString('SENSITIVE_TEST_MARKER', $harden['stdout'] . $harden['stderr']);
    }

    public function testHardenRejectsConfigWithMultipleHardlinks(): void
    {
        link($this->appRoot . '/config.php', $this->workspace . '/config-hardlink.php');

        $harden = $this->runPermissionMode('harden');

        self::assertNotSame(0, $harden['exit_code']);
        self::assertStringContainsString('config.php must have exactly one hardlink', $harden['stderr']);
        self::assertSame($this->runtimeUserId, fileowner($this->appRoot));
        self::assertSame(0777, fileperms($this->appRoot) & 0777);
        self::assertStringNotContainsString('SENSITIVE_TEST_MARKER', $harden['stdout'] . $harden['stderr']);
    }

    public function testHardenRestoresPinnedMetadataWhenLateRuntimeReadabilityCheckFails(): void
    {
        chmod($this->workspace, 0700);
        $metadataBefore = $this->permissionMetadata($this->appRoot);

        $harden = $this->runPermissionMode('harden');

        self::assertNotSame(0, $harden['exit_code']);
        self::assertStringContainsString('config.php is not readable by runtime user', $harden['stderr']);
        self::assertStringContainsString('Restored prior runtime config permission metadata', $harden['stderr']);
        self::assertSame($metadataBefore, $this->permissionMetadata($this->appRoot));
        self::assertStringNotContainsString('SENSITIVE_TEST_MARKER', $harden['stdout'] . $harden['stderr']);
    }

    public function testPermissionModeFailsClosedForNonRootCaller(): void
    {
        $result = $this->runCommand([
            'runuser',
            '-u',
            'www-data',
            '--',
            'bash',
            $this->trustedDeployScript,
            '--runtime-config-permissions',
            'harden',
            '--app-root',
            $this->appRoot,
            '--runtime-user',
            'www-data',
        ]);

        self::assertNotSame(0, $result['exit_code']);
        self::assertStringContainsString('requires root privileges', $result['stderr']);
        self::assertStringNotContainsString('SENSITIVE_TEST_MARKER', $result['stdout'] . $result['stderr']);
    }

    public function testRootRefusesDeployScriptControlledByRuntimeReleaseTree(): void
    {
        $untrustedScript = $this->appRoot . '/deploy_ea.sh';
        copy($this->trustedDeployScript, $untrustedScript);
        $this->setRuntimeOwned($untrustedScript, 0755);
        $metadataBefore = $this->permissionMetadata($this->appRoot);

        $result = $this->runCommand([
            'bash',
            $untrustedScript,
            '--runtime-config-permissions',
            'harden',
            '--app-root',
            $this->appRoot,
            '--runtime-user',
            'www-data',
        ]);

        self::assertNotSame(0, $result['exit_code']);
        self::assertStringContainsString('Trusted deploy script must be root-owned', $result['stderr']);
        self::assertSame($metadataBefore, $this->permissionMetadata($this->appRoot));
        self::assertStringNotContainsString('SENSITIVE_TEST_MARKER', $result['stdout'] . $result['stderr']);
    }

    public function testHardenRejectsSymlinkedAppRootWithoutMutatingTarget(): void
    {
        $symlinkPath = $this->workspace . '/app-link';
        symlink($this->appRoot, $symlinkPath);
        $metadataBefore = $this->permissionMetadata($this->appRoot);

        $result = $this->runPermissionMode('harden', $symlinkPath);

        self::assertNotSame(0, $result['exit_code']);
        self::assertStringContainsString('app root must be a non-symlink directory', $result['stderr']);
        self::assertSame($metadataBefore, $this->permissionMetadata($this->appRoot));
    }

    public function testHardenRejectsRuntimeControlledAncestorBeforeMutation(): void
    {
        $runtimeParent = $this->workspace . '/runtime-parent';
        $appRoot = $runtimeParent . '/app';
        mkdir($appRoot, 0777, true);
        file_put_contents($appRoot . '/config.php', "SENSITIVE_TEST_MARKER\n");
        $this->setRuntimeOwned($runtimeParent, 0755);
        $this->setRuntimeOwned($appRoot, 0777);
        $this->setRuntimeOwned($appRoot . '/config.php', 0666);
        $metadataBefore = $this->permissionMetadata($appRoot);

        $result = $this->runPermissionMode('harden', $appRoot);

        self::assertNotSame(0, $result['exit_code']);
        self::assertStringContainsString('ancestor must be root-owned', $result['stderr']);
        self::assertSame($metadataBefore, $this->permissionMetadata($appRoot));
        self::assertStringNotContainsString('SENSITIVE_TEST_MARKER', $result['stdout'] . $result['stderr']);
    }

    public function testRuntimeCannotExchangeConfigAfterAppRootLockAndPinnedInodeIsPreserved(): void
    {
        $replacement = $this->appRoot . '/replacement.php';
        file_put_contents($replacement, "ATTACKER_REPLACEMENT_MARKER\n");
        $this->setRuntimeOwned($replacement, 0666);
        $originalMetadata = stat($this->appRoot . '/config.php');
        self::assertIsArray($originalMetadata);

        $attacker = $this->startPostLockExchangeAttempt($replacement);
        $ready = fgets($attacker['pipes'][1]);
        self::assertSame("READY\n", $ready);

        $harden = $this->runPermissionMode('harden');
        if ($harden['exit_code'] !== 0) {
            proc_terminate($attacker['process']);
        }
        $attackerStdout = stream_get_contents($attacker['pipes'][1]);
        $attackerStderr = stream_get_contents($attacker['pipes'][2]);
        fclose($attacker['pipes'][1]);
        fclose($attacker['pipes'][2]);
        $attackerExit = proc_close($attacker['process']);

        self::assertSame(0, $harden['exit_code'], $harden['stderr']);
        self::assertNotSame(0, $attackerExit, (string) $attackerStdout);
        $finalMetadata = stat($this->appRoot . '/config.php');
        self::assertIsArray($finalMetadata);
        self::assertSame($originalMetadata['dev'], $finalMetadata['dev']);
        self::assertSame($originalMetadata['ino'], $finalMetadata['ino']);
        $this->assertPermissionContract($this->appRoot);
        self::assertStringNotContainsString(
            'SENSITIVE_TEST_MARKER',
            $harden['stdout'] . $harden['stderr'] . $attackerStderr,
        );
        self::assertStringNotContainsString(
            'ATTACKER_REPLACEMENT_MARKER',
            $harden['stdout'] . $harden['stderr'] . $attackerStderr,
        );
    }

    /**
     * @return array{process:resource,pipes:array<int,resource>}
     */
    private function startPostLockExchangeAttempt(string $replacement): array
    {
        $script = <<<'BASH'
        echo READY
        for ((attempt = 0; attempt < 1000; attempt++)); do
          owner="$(stat -c '%u' -- "$1")" || exit 20
          if [[ "$owner" == "0" ]]; then
            mv -f -- "$2" "$1/config.php"
            exit $?
          fi
          sleep 0.005
        done
        exit 21
        BASH;
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            ['runuser', '-u', 'www-data', '--', 'bash', '-c', $script, 'bash', $this->appRoot, $replacement],
            $descriptorSpec,
            $pipes,
            dirname(__DIR__, 3),
        );
        self::assertIsResource($process);
        fclose($pipes[0]);

        return ['process' => $process, 'pipes' => $pipes];
    }

    private function setRuntimeOwned(string $path, int $mode): void
    {
        chown($path, $this->runtimeUserId);
        chgrp($path, $this->runtimeGroupId);
        chmod($path, $mode);
    }

    private function assertPermissionContract(string $appRoot): void
    {
        $metadata = $this->permissionMetadata($appRoot);
        self::assertSame(0, $metadata['app_owner']);
        self::assertSame(0, $metadata['app_group']);
        self::assertSame(0755, $metadata['app_mode']);
        self::assertSame(0, $metadata['config_owner']);
        self::assertSame($this->runtimeGroupId, $metadata['config_group']);
        self::assertSame(0440, $metadata['config_mode']);
    }

    /**
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runPermissionMode(string $action, ?string $appRoot = null): array
    {
        return $this->runCommand([
            'bash',
            $this->trustedDeployScript,
            '--runtime-config-permissions',
            $action,
            '--app-root',
            $appRoot ?? $this->appRoot,
            '--runtime-user',
            'www-data',
        ]);
    }

    /**
     * @return array{app_owner:int,app_group:int,app_mode:int,config_owner:int,config_group:int,config_mode:int}
     */
    private function permissionMetadata(string $appRoot): array
    {
        clearstatcache(true, $appRoot);
        clearstatcache(true, $appRoot . '/config.php');

        return [
            'app_owner' => (int) fileowner($appRoot),
            'app_group' => (int) filegroup($appRoot),
            'app_mode' => fileperms($appRoot) & 0777,
            'config_owner' => (int) fileowner($appRoot . '/config.php'),
            'config_group' => (int) filegroup($appRoot . '/config.php'),
            'config_mode' => fileperms($appRoot . '/config.php') & 0777,
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
