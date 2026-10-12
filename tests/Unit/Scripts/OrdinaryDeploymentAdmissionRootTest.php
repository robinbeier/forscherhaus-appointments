<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('root-deployment')]
final class OrdinaryDeploymentAdmissionRootTest extends TestCase
{
    private const LOCK_ROOT = '/var/lib/fh-deploy-orchestrator';
    private const LOCK = self::LOCK_ROOT . '/locks/fh-production-change.lock';
    private const CANARY_ROOT = '/var/lib/fh-zero-surprise-canary';
    private const CANARY_STATE = self::CANARY_ROOT . '/active.json';

    private string $fixture;
    private bool $createdLockRoot = false;
    private bool $createdLockDirectory = false;
    private bool $createdLock = false;
    private bool $createdCanaryRoot = false;
    private bool $createdCanaryState = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Linux root is required for the deployment boundary test.');
        }
        if (posix_getpwnam('www-data') === false) {
            self::markTestSkipped('The production runtime user is required for the real deploy entry.');
        }
        if (file_exists(self::LOCK) || is_link(self::LOCK)) {
            self::markTestSkipped('The production lock already exists; the root fixture will not replace it.');
        }
        if (
            is_link(self::CANARY_ROOT) ||
            (file_exists(self::CANARY_ROOT) && !is_dir(self::CANARY_ROOT)) ||
            file_exists(self::CANARY_STATE) ||
            is_link(self::CANARY_STATE)
        ) {
            self::markTestSkipped('The canary state already exists; the root fixture will not replace it.');
        }

        $this->fixture = '/root/ordinary-deploy-' . bin2hex(random_bytes(8));
        mkdir($this->fixture, 0700, true);
        $this->createLock();
        if (!is_dir(self::CANARY_ROOT)) {
            mkdir(self::CANARY_ROOT, 0700, true);
            $this->createdCanaryRoot = true;
        }
        file_put_contents(self::CANARY_STATE, "{}\n");
        chmod(self::CANARY_STATE, 0600);
        $this->createdCanaryState = true;
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->removeTree($this->fixture);
        }
        if ($this->createdCanaryState) {
            @unlink(self::CANARY_STATE);
        }
        if ($this->createdCanaryRoot) {
            @rmdir(self::CANARY_ROOT);
        }
        if ($this->createdLock) {
            @unlink(self::LOCK);
        }
        if ($this->createdLockDirectory) {
            @rmdir(dirname(self::LOCK));
        }
        if ($this->createdLockRoot) {
            @rmdir(self::LOCK_ROOT);
        }
        parent::tearDown();
    }

    public function testRealDeployEntryReachesCanonicalRecoveryAndCanaryGatesWithoutGlobalCore(): void
    {
        $result = $this->runDeployEntry();
        $output = $result['stdout'] . $result['stderr'];

        self::assertStringNotContainsString('maintenance_pending_v1.py', $output);
        self::assertStringNotContainsString('Maintenance admission core', $output);
        self::assertStringContainsString(
            'active or unresolved zero-surprise canary blocks deployment',
            strtolower($output),
        );
        self::assertFileDoesNotExist($this->fixture . '/receipt.json');
    }

    private function createLock(): void
    {
        if (!is_dir(self::LOCK_ROOT)) {
            mkdir(self::LOCK_ROOT, 0700, true);
            $this->createdLockRoot = true;
        }
        if (!is_dir(dirname(self::LOCK))) {
            mkdir(dirname(self::LOCK), 0700, true);
            $this->createdLockDirectory = true;
        }
        file_put_contents(self::LOCK, '');
        chmod(self::LOCK, 0600);
        $this->createdLock = true;
    }

    /** @return array{exit_code:int,stdout:string,stderr:string} */
    private function runDeployEntry(): array
    {
        $script = $this->fixture . '/deploy_ea.sh';
        copy(dirname(__DIR__, 3) . '/deploy_ea.sh', $script);
        chmod($script, 0700);
        $archiveRoot = $this->fixture . '/src';
        mkdir($archiveRoot, 0700);
        $app = $this->fixture . '/live';
        mkdir($app, 0700);
        return $this->runCommand([
            '/bin/bash',
            $script,
            '--rel',
            'ea_deploy_fixture',
            '--src',
            $archiveRoot,
            '--app',
            $app,
            '--result-file',
            $this->fixture . '/receipt.json',
            '--zero-surprise-dump-file',
            $this->fixture . '/synthetic-dump.sql',
            '--zero-surprise-predeploy-credentials-file',
            $this->fixture . '/synthetic-predeploy.ini',
            '--zero-surprise-canary-credentials-file',
            $this->fixture . '/synthetic-canary.ini',
        ]);
    }

    /** @param list<string> $command @return array{exit_code:int,stdout:string,stderr:string} */
    private function runCommand(array $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        self::assertIsResource($process);
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

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $leaf) {
            if ($leaf !== '.' && $leaf !== '..') {
                $this->removeTree($path . '/' . $leaf);
            }
        }
        @rmdir($path);
    }
}
