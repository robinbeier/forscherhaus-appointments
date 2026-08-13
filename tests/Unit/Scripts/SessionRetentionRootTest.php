<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class SessionRetentionRootTest extends TestCase
{
    private const APP_ROOT = '/var/www/html/easyappointments';
    private const SESSION_ROOT = self::APP_ROOT . '/storage/sessions';
    private const STATE_ROOT = '/var/lib/fh-session-retention';
    private const ORCHESTRATOR_ROOT = '/var/lib/fh-deploy-orchestrator';
    private string $helper;
    private int $webUid;
    private int $webGid;

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY !== 'Linux' || posix_geteuid() !== 0) {
            $this->markTestSkipped('Linux root is required for the protected session-retention contract.');
        }
        if (!is_executable('/usr/bin/setpriv')) {
            self::fail('The Linux root gate requires /usr/bin/setpriv to enforce the production capability boundary.');
        }
        if (file_exists(self::APP_ROOT) || file_exists(self::STATE_ROOT) || file_exists(self::ORCHESTRATOR_ROOT)) {
            $this->markTestSkipped('Protected roots already exist; the root test will not mutate them.');
        }
        $web = posix_getpwnam('www-data');
        if (!is_array($web)) {
            $this->markTestSkipped('The production www-data account is required.');
        }
        $this->webUid = $web['uid'];
        $this->webGid = $web['gid'];
        $this->helper = dirname(__DIR__, 3) . '/scripts/ops/libexec/session_retention_v1.py';

        foreach (['/var', '/var/www', '/var/www/html'] as $path) {
            $metadata = lstat($path);
            if (
                !is_array($metadata) ||
                ($metadata['mode'] & 0170000) !== 0040000 ||
                $metadata['uid'] !== 0 ||
                ($metadata['mode'] & 0022) !== 0
            ) {
                $this->markTestSkipped(
                    'Existing web-root ancestors must already satisfy the production trust boundary.',
                );
            }
        }
        mkdir(self::SESSION_ROOT, 0755, true);
        chown(self::APP_ROOT, 0);
        chgrp(self::APP_ROOT, 0);
        chmod(self::APP_ROOT, 0755);
        foreach ([self::APP_ROOT . '/storage', self::SESSION_ROOT] as $path) {
            chown($path, $this->webUid);
            chgrp($path, $this->webGid);
            chmod($path, 0755);
        }
        mkdir(self::STATE_ROOT, 0700);
        chown(self::STATE_ROOT, 0);
        chgrp(self::STATE_ROOT, 0);
        chmod(self::STATE_ROOT, 0700);
        mkdir(self::ORCHESTRATOR_ROOT . '/locks', 0700, true);
        chmod(self::ORCHESTRATOR_ROOT, 0700);
        chmod(self::ORCHESTRATOR_ROOT . '/locks', 0700);
        $lock = self::ORCHESTRATOR_ROOT . '/locks/fh-production-change.lock';
        touch($lock);
        chmod($lock, 0600);
    }

    protected function tearDown(): void
    {
        if (isset($this->helper)) {
            $this->removeTree(self::APP_ROOT);
            $this->removeTree(self::STATE_ROOT);
            $this->removeTree(self::ORCHESTRATOR_ROOT);
        }
        parent::tearDown();
    }

    public function testDryRunIsAggregateOnlyAndDoesNotDelete(): void
    {
        $old = $this->session('a', 90_000, 'sensitive old payload');
        $new = $this->session('b', 100, 'sensitive new payload');
        file_put_contents(self::SESSION_ROOT . '/foreign-secret-name', 'foreign secret');

        $result = $this->runHelper('dry-run');

        self::assertSame(0, $result['exit'], $result['stderr']);
        $decoded = $this->decode($result);
        self::assertSame('dry-run', $decoded['mode']);
        self::assertSame(1, $decoded['eligible_count']);
        self::assertSame(1, $decoded['newer_count']);
        self::assertSame(1, $decoded['foreign_count']);
        self::assertSame(1, $decoded['would_delete_count']);
        self::assertFalse($decoded['deletion_performed']);
        self::assertFileExists($old);
        self::assertFileExists($new);
        self::assertStringNotContainsString(basename($old), $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('sensitive', $result['stdout'] . $result['stderr']);
        self::assertFileDoesNotExist(self::STATE_ROOT . '/last-success.json');
    }

    public function testExecuteDeletesOnlyExpiredUnlockedSessionsAndPublishesFreshMarker(): void
    {
        $old = $this->session('c', 90_000, 'old');
        $new = $this->session('d', 100, 'new');
        $locked = $this->session('e', 90_000, 'locked');
        $lockHandle = fopen($locked, 'rb');
        self::assertIsResource($lockHandle);
        self::assertTrue(flock($lockHandle, LOCK_EX | LOCK_NB));

        $partial = $this->runHelper('execute');
        self::assertSame(75, $partial['exit'], $partial['stdout'] . $partial['stderr']);
        self::assertSame('partial', $this->decode($partial)['status']);
        self::assertFileDoesNotExist($old);
        self::assertFileExists($new);
        self::assertFileExists($locked);
        self::assertFileDoesNotExist(self::STATE_ROOT . '/last-success.json');

        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        $success = $this->runHelper('execute');
        self::assertSame(0, $success['exit'], $success['stderr']);
        self::assertSame('pass', $this->decode($success)['status']);
        self::assertFileDoesNotExist($locked);
        self::assertFileExists($new);
        $marker = self::STATE_ROOT . '/last-success.json';
        self::assertFileExists($marker);
        $metadata = lstat($marker);
        self::assertIsArray($metadata);
        self::assertSame(0, $metadata['uid']);
        self::assertSame(0600, $metadata['mode'] & 0777);
        self::assertSame(1, $metadata['nlink']);
        self::assertSame('pass', $this->decode($this->runHelper('marker-status', '129600'))['status']);
    }

    public function testCutoffIsInclusiveAndRecheckedImmediatelyBeforeDelete(): void
    {
        $exactlyOld = $this->session('f', 86_400, 'boundary');
        $young = $this->session('1', 86_398, 'young');
        $result = $this->runHelper('execute');
        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertFileDoesNotExist($exactlyOld);
        self::assertFileExists($young);
    }

    public function testValidNameWithUnsafeTypeOwnershipModeOrHardlinkFailsClosed(): void
    {
        $path = $this->sessionPath('2');
        symlink('/dev/null', $path);
        self::assertSame(70, $this->runHelper('dry-run')['exit']);
        unlink($path);

        posix_mkfifo($path, 0600);
        chown($path, $this->webUid);
        chgrp($path, $this->webGid);
        self::assertSame(70, $this->runHelper('dry-run')['exit']);
        unlink($path);

        $this->writeSession($path, 90_000, 'mode');
        chmod($path, 0644);
        self::assertSame(70, $this->runHelper('dry-run')['exit']);
        chmod($path, 0600);
        chown($path, 0);
        self::assertSame(70, $this->runHelper('dry-run')['exit']);
        chown($path, $this->webUid);
        link($path, self::SESSION_ROOT . '/foreign-hardlink');
        self::assertSame(70, $this->runHelper('dry-run')['exit']);
    }

    public function testUnsafeSessionDirectoryAndBusyGlobalLockBlockWithoutDeletion(): void
    {
        $old = $this->session('3', 90_000, 'old');
        chmod(self::SESSION_ROOT, 0777);
        self::assertSame(70, $this->runHelper('execute')['exit']);
        self::assertFileExists($old);
        chmod(self::SESSION_ROOT, 0755);

        $global = fopen(self::ORCHESTRATOR_ROOT . '/locks/fh-production-change.lock', 'c+');
        self::assertIsResource($global);
        self::assertTrue(flock($global, LOCK_EX | LOCK_NB));
        $blocked = $this->runHelper('execute');
        self::assertSame(75, $blocked['exit']);
        self::assertSame('active_production_work', $this->decode($blocked)['reason']);
        self::assertFileExists($old);
        flock($global, LOCK_UN);
        fclose($global);
    }

    public function testDeletionCapIsExactAndRequiresAnotherPassBeforeSuccessMarker(): void
    {
        for ($index = 0; $index < 10_001; $index++) {
            $this->session(sprintf('%032x', $index + 100), 90_000, 'x');
        }

        $first = $this->runHelper('execute');
        $firstPayload = $this->decode($first);
        self::assertSame(75, $first['exit'], $first['stdout'] . $first['stderr']);
        self::assertSame(10_000, $firstPayload['deleted_count']);
        self::assertSame(1, $firstPayload['remaining_eligible_count']);
        self::assertTrue($firstPayload['cap_exceeded']);
        self::assertFileDoesNotExist(self::STATE_ROOT . '/last-success.json');

        $second = $this->runHelper('execute');
        self::assertSame(0, $second['exit'], $second['stderr']);
        self::assertSame(1, $this->decode($second)['deleted_count']);
        self::assertFileExists(self::STATE_ROOT . '/last-success.json');
    }

    public function testMarkerStatusClassifiesMissingInvalidStaleAndFuture(): void
    {
        self::assertSame('missing', $this->decode($this->runHelper('marker-status', '129600'))['status']);
        $marker = self::STATE_ROOT . '/last-success.json';
        file_put_contents($marker, "not-json\n");
        chmod($marker, 0600);
        self::assertSame('invalid', $this->decode($this->runHelper('marker-status', '129600'))['status']);

        $this->writeMarker('2020-01-01T00:00:00Z');
        self::assertSame('stale', $this->decode($this->runHelper('marker-status', '129600'))['status']);
        $this->writeMarker('2099-01-01T00:00:00Z');
        self::assertSame('invalid', $this->decode($this->runHelper('marker-status', '129600'))['status']);
    }

    private function session(string $suffix, int $ageSeconds, string $bytes): string
    {
        $path = $this->sessionPath($suffix);
        $this->writeSession($path, $ageSeconds, $bytes);
        return $path;
    }

    private function sessionPath(string $suffix): string
    {
        return self::SESSION_ROOT . '/ea_session' . str_pad($suffix, 32, '0', STR_PAD_LEFT);
    }

    private function writeSession(string $path, int $ageSeconds, string $bytes): void
    {
        file_put_contents($path, $bytes);
        chown($path, $this->webUid);
        chgrp($path, $this->webGid);
        chmod($path, 0600);
        touch($path, time() - $ageSeconds);
    }

    private function writeMarker(string $completedAt): void
    {
        $payload = [
            'completed_at_utc' => $completedAt,
            'cutoff_seconds' => 86_400,
            'deleted_count' => 0,
            'max_delete' => 10_000,
            'remaining_eligible_count' => 0,
            'schema' => 'prod_session_retention_marker.v1',
        ];
        ksort($payload);
        file_put_contents(
            self::STATE_ROOT . '/last-success.json',
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
        chmod(self::STATE_ROOT . '/last-success.json', 0600);
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private function runHelper(string ...$arguments): array
    {
        return $this->runCommand(
            array_merge(
                [
                    '/usr/bin/setpriv',
                    '--bounding-set=-all,+dac_override',
                    '--inh-caps=-all',
                    '--ambient-caps=-all',
                    '/usr/bin/python3',
                    '-I',
                    '-B',
                    $this->helper,
                ],
                $arguments,
            ),
        );
    }

    /** @param array{exit:int,stdout:string,stderr:string} $result */
    private function decode(array $result): array
    {
        $decoded = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    /** @param list<string> $command @return array{exit:int,stdout:string,stderr:string} */
    private function runCommand(array $command): array
    {
        $stdout = tmpfile();
        $stderr = tmpfile();
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);
        $process = proc_open($command, [['file', '/dev/null', 'r'], $stdout, $stderr], $pipes, dirname(__DIR__, 3));
        self::assertIsResource($process);
        $exit = proc_close($process);
        rewind($stdout);
        rewind($stderr);
        $stdoutBytes = stream_get_contents($stdout);
        $stderrBytes = stream_get_contents($stderr);
        fclose($stdout);
        fclose($stderr);
        return ['exit' => $exit, 'stdout' => $stdoutBytes ?: '', 'stderr' => $stderrBytes ?: ''];
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
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isLink() || $item->isFile()) {
                @unlink($item->getPathname());
            } else {
                @rmdir($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
