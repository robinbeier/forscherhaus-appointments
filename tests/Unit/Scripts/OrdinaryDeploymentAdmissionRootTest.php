<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('root-deployment')]
final class OrdinaryDeploymentAdmissionRootTest extends TestCase
{
    private const ADMISSION_ROOT = '/var/lib/fh-maintenance-admission';
    private const LOCK_ROOT = '/var/lib/fh-deploy-orchestrator';
    private const LOCK = self::LOCK_ROOT . '/locks/fh-production-change.lock';
    private const CORE = '/usr/local/libexec/fh/maintenance_pending_v1.py';

    private string $fixture;
    private bool $createdAdmissionRoot = false;
    private bool $createdLockRoot = false;
    private bool $createdLockDirectory = false;
    private bool $createdLock = false;
    private bool $stagedCore = false;
    private ?array $coreIdentity = null;
    private bool $createdCoreDirectory = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Linux root is required for the deployment admission boundary test.');
        }
        if (posix_getpwnam('www-data') === false) {
            self::markTestSkipped('The production runtime user is required for the real deploy entry.');
        }
        if (file_exists(self::ADMISSION_ROOT) || is_link(self::ADMISSION_ROOT)) {
            self::markTestSkipped('The fixed admission state root already exists; the test will not mutate it.');
        }
        if (file_exists(self::LOCK) || is_link(self::LOCK)) {
            self::markTestSkipped('The production lock already exists; the root fixture will not replace it.');
        }
        if (file_exists(self::CORE) || is_link(self::CORE)) {
            self::markTestSkipped('The admission core already exists; the root fixture will not replace it.');
        }

        // The copied trusted deploy script must live below a root-controlled
        // ancestor.  /tmp is intentionally world-writable and therefore
        // rejected by validate_trusted_deploy_script before admission.
        $this->fixture = '/root/ordinary-admission-' . bin2hex(random_bytes(8));
        mkdir($this->fixture, 0700, true);
        $this->createAdmissionState();
        $this->createLock();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->removeTree($this->fixture);
        }
        if ($this->stagedCore && is_array($this->coreIdentity)) {
            $current = @lstat(self::CORE);
            if ($this->sameIdentity($current, $this->coreIdentity)) {
                @unlink(self::CORE);
            }
        }
        if ($this->createdCoreDirectory) {
            @rmdir(dirname(self::CORE));
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
        if ($this->createdAdmissionRoot) {
            $this->removeTree(self::ADMISSION_ROOT);
        }
        parent::tearDown();
    }

    public function testRealDeployEntryRefusesMissingAdmissionCoreBeforeReceiptOrDeploymentMutation(): void
    {
        if (file_exists(self::CORE) || is_link(self::CORE)) {
            self::markTestSkipped('The admission core is installed; the missing-core fixture is unsafe.');
        }
        $result = $this->runDeployEntry();

        self::assertSame(30, $result['exit_code'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString(
            'Maintenance admission core is not installed',
            $result['stdout'] . $result['stderr'],
        );
        self::assertStringNotContainsString(
            'zero-surprise-canary-credentials-file is required',
            $result['stdout'] . $result['stderr'],
        );
        self::assertFileDoesNotExist($this->fixture . '/receipt.json');
    }

    public function testRealDeployEntryRefusesDurablePendingStateBeforeReceiptOrDeploymentMutation(): void
    {
        if (!file_exists(self::CORE) && !is_link(self::CORE)) {
            $this->stageAdmissionCore();
        } else {
            self::assertFalse(is_link(self::CORE), 'The installed admission core must not be a symlink.');
            self::assertSame(
                '78da04cc6c66c7e140dcc6f4a924acf92062ea5860c8bb424115129b922c6bb5',
                hash_file('sha256', self::CORE),
            );
        }
        $record = [
            'boot_id' => '11111111-1111-1111-1111-111111111111',
            'epoch' => 'maintenance-admission.v1',
            'operation' => 'deploy',
            'registered_at_utc' => '2026-10-10T00:00:00Z',
            'resource_identity' => ['id' => 'pending-run', 'kind' => 'synthetic'],
            'run_id' => 'pendingrun1',
            'schema' => 'maintenance_pending.v1',
        ];
        file_put_contents(
            self::ADMISSION_ROOT . '/pending.json',
            json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
        chmod(self::ADMISSION_ROOT . '/pending.json', 0600);

        $result = $this->runDeployEntry();

        self::assertSame(30, $result['exit_code'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('pending_present', $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString(
            'zero-surprise-canary-credentials-file is required',
            $result['stdout'] . $result['stderr'],
        );
        self::assertFileDoesNotExist($this->fixture . '/receipt.json');
        self::assertFileExists(self::ADMISSION_ROOT . '/pending.json');
    }

    public function testRealDeployEntryRejectsInvalidAdmissionCoreBytes(): void
    {
        $this->stageAdmissionCoreBytes("print('unreviewed')\n");
        $result = $this->runDeployEntry();
        self::assertSame(30, $result['exit_code'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('admission_core_hash_mismatch', $result['stdout'] . $result['stderr']);
        self::assertFileDoesNotExist($this->fixture . '/receipt.json');
    }

    public function testRealDeployEntryRejectsOversizedAdmissionCoreBytes(): void
    {
        $this->stageAdmissionCoreBytes(str_repeat('x', 1024 * 1024 + 1));
        $result = $this->runDeployEntry();
        self::assertSame(30, $result['exit_code'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('admission_core_oversize', $result['stdout'] . $result['stderr']);
        self::assertFileDoesNotExist($this->fixture . '/receipt.json');
    }

    private function createAdmissionState(): void
    {
        mkdir(self::ADMISSION_ROOT, 0700, true);
        $this->createdAdmissionRoot = true;
        file_put_contents(self::ADMISSION_ROOT . '/epoch', "maintenance-admission.v1\n");
        chmod(self::ADMISSION_ROOT . '/epoch', 0600);
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

    private function stageAdmissionCore(): void
    {
        $source = dirname(__DIR__, 3) . '/scripts/ops/libexec/maintenance_pending_v1.py';
        self::assertSame(
            '78da04cc6c66c7e140dcc6f4a924acf92062ea5860c8bb424115129b922c6bb5',
            hash_file('sha256', $source),
        );
        $this->stageAdmissionCoreBytes((string) file_get_contents($source));
    }

    private function stageAdmissionCoreBytes(string $bytes): void
    {
        $parent = dirname(self::CORE);
        if (!is_dir($parent)) {
            mkdir($parent, 0755, true);
            $this->createdCoreDirectory = true;
        }
        if ($this->stagedCore || file_exists(self::CORE) || is_link(self::CORE)) {
            self::markTestSkipped('The admission core fixture must be created exactly once without clobbering.');
        }
        $stream = @fopen(self::CORE, 'x+b');
        if (!is_resource($stream)) {
            self::markTestSkipped('The admission core fixture could not be created without clobbering.');
        }
        chmod(self::CORE, 0644);
        $this->stagedCore = true;
        fwrite($stream, $bytes);
        fflush($stream);
        if (function_exists('fsync')) {
            fsync($stream);
        }
        fclose($stream);
        $identity = lstat(self::CORE);
        self::assertIsArray($identity);
        $this->coreIdentity ??= $identity;
        self::assertTrue($this->sameIdentity($identity, $this->coreIdentity));
    }

    /** @param array<string,mixed>|false $current @param array<string,mixed> $expected */
    private function sameIdentity(array|false $current, array $expected): bool
    {
        if (!is_array($current)) {
            return false;
        }
        foreach (['dev', 'ino', 'uid', 'gid', 'mode', 'nlink'] as $field) {
            if (($current[$field] ?? null) !== ($expected[$field] ?? null)) {
                return false;
            }
        }
        return true;
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
        $result = $this->runCommand([
            '/bin/bash',
            $script,
            '--rel',
            'ea_admission_fixture',
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
        return $result;
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
