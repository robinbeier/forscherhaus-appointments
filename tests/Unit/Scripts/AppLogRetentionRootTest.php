<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use Tests\Support\RootHostTestPrerequisites;

final class AppLogRetentionRootTest extends TestCase
{
    private const APP_ROOT = '/var/www/html/easyappointments';
    private const LOG_ROOT = self::APP_ROOT . '/storage/logs';
    private const STATE_ROOT = '/var/lib/fh-app-log-retention';
    private const ORCHESTRATOR_ROOT = '/var/lib/fh-deploy-orchestrator';
    private string $helper;
    private int $webUid;
    private int $webGid;

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Linux root is required for the protected app-log retention contract.');
        }
        RootHostTestPrerequisites::enforce($this, RootHostTestPrerequisites::runtimeCheck());
        if (posix_geteuid() !== 0) {
            RootHostTestPrerequisites::enforce(
                $this,
                RootHostTestPrerequisites::classify(
                    false,
                    'root_required',
                    'Linux root is required for the protected app-log retention contract.',
                ),
            );
        }
        if (!is_executable('/usr/bin/setpriv')) {
            RootHostTestPrerequisites::enforce(
                $this,
                RootHostTestPrerequisites::classify(
                    false,
                    'setpriv_missing',
                    'The Linux root gate requires /usr/bin/setpriv.',
                ),
            );
        }
        if (file_exists(self::APP_ROOT) || file_exists(self::STATE_ROOT) || file_exists(self::ORCHESTRATOR_ROOT)) {
            RootHostTestPrerequisites::enforce(
                $this,
                RootHostTestPrerequisites::classify(
                    false,
                    'protected_roots_present',
                    'Protected roots already exist; the root test will not mutate them.',
                ),
            );
        }
        $web = posix_getpwnam('www-data');
        if (!is_array($web)) {
            RootHostTestPrerequisites::enforce(
                $this,
                RootHostTestPrerequisites::classify(
                    false,
                    'web_user_missing',
                    'The production www-data account is required.',
                ),
            );
        }
        $this->webUid = $web['uid'];
        $this->webGid = $web['gid'];
        $this->helper = dirname(__DIR__, 3) . '/scripts/ops/libexec/app_log_retention_v1.py';

        foreach (['/var', '/var/www', '/var/www/html'] as $path) {
            $metadata = lstat($path);
            if (
                !is_array($metadata) ||
                ($metadata['mode'] & 0170000) !== 0040000 ||
                $metadata['uid'] !== 0 ||
                ($metadata['mode'] & 0022) !== 0
            ) {
                RootHostTestPrerequisites::enforce(
                    $this,
                    RootHostTestPrerequisites::classify(
                        false,
                        'web_root_unsafe',
                        'Existing web-root ancestors do not match the production trust boundary.',
                        false,
                    ),
                );
            }
        }
        RootHostTestPrerequisites::enforce(
            $this,
            RootHostTestPrerequisites::ownershipCheck($this->webUid, $this->webGid, '/var/www/html'),
        );
        mkdir(self::LOG_ROOT, 0755, true);
        chown(self::APP_ROOT, 0);
        chgrp(self::APP_ROOT, 0);
        chmod(self::APP_ROOT, 0755);
        foreach ([self::APP_ROOT . '/storage', self::LOG_ROOT] as $path) {
            chown($path, $this->webUid);
            chgrp($path, $this->webGid);
            chmod($path, 0755);
        }
        mkdir(self::STATE_ROOT, 0700);
        chmod(self::STATE_ROOT, 0700);
        mkdir(self::ORCHESTRATOR_ROOT . '/locks', 0700, true);
        chmod(self::ORCHESTRATOR_ROOT, 0700);
        chmod(self::ORCHESTRATOR_ROOT . '/locks', 0700);
        touch(self::ORCHESTRATOR_ROOT . '/locks/fh-production-change.lock');
        chmod(self::ORCHESTRATOR_ROOT . '/locks/fh-production-change.lock', 0600);
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

    public function testDryRunIsAggregateOnlyAndProtectsEvidenceClasses(): void
    {
        $old = $this->logFile('2026-01-01', 61 * 86_400, 'sensitive-old-log-line');
        $current = $this->logFile('2026-08-13', 10, 'sensitive-current-log-line');
        $this->protectedFile('index.html', '<html>sensitive diagnostic</html>');
        $this->protectedFile('dashboard_principal_pdf_dump.html', 'sensitive pdf dump');
        mkdir(self::LOG_ROOT . '/release-gate', 0755);
        chown(self::LOG_ROOT . '/release-gate', $this->webUid);
        chgrp(self::LOG_ROOT . '/release-gate', $this->webGid);

        $result = $this->runHelper('dry-run');
        self::assertSame(0, $result['exit'], $result['stderr']);
        $payload = $this->decode($result);
        self::assertSame('dry-run', $payload['mode']);
        self::assertSame(1, $payload['eligible_count']);
        self::assertSame(1, $payload['current_count']);
        self::assertSame(3, $payload['protected_count']);
        self::assertSame(1, $payload['would_delete_count']);
        self::assertFalse($payload['deletion_performed']);
        self::assertFileExists($old);
        self::assertFileExists($current);
        self::assertStringNotContainsString(basename($old), $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('sensitive', $result['stdout'] . $result['stderr']);
    }

    public function testExecuteUsesFileLockRechecksAndPublishesMarkerOnlyWhenComplete(): void
    {
        $old = $this->logFile('2026-01-02', 61 * 86_400, 'old');
        $locked = $this->logFile('2026-01-03', 61 * 86_400, 'locked');
        $handle = fopen($locked, 'rb');
        self::assertIsResource($handle);
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));

        $partial = $this->runHelper('execute');
        self::assertSame(75, $partial['exit'], $partial['stdout'] . $partial['stderr']);
        self::assertSame('partial', $this->decode($partial)['status']);
        self::assertFileDoesNotExist($old);
        self::assertFileExists($locked);
        self::assertFileDoesNotExist(self::STATE_ROOT . '/last-success.json');

        flock($handle, LOCK_UN);
        fclose($handle);
        $complete = $this->runHelper('execute');
        self::assertSame(0, $complete['exit'], $complete['stdout'] . $complete['stderr']);
        self::assertFileDoesNotExist($locked);
        $marker = self::STATE_ROOT . '/last-success.json';
        self::assertFileExists($marker);
        $metadata = lstat($marker);
        self::assertIsArray($metadata);
        self::assertSame(0, $metadata['uid']);
        self::assertSame(0600, $metadata['mode'] & 0777);
        self::assertSame(1, $metadata['nlink']);
        self::assertSame('pass', $this->decode($this->runHelper('marker-status', '129600'))['status']);
    }

    public function testCutoffIsInclusiveAndCurrentFileIsRetained(): void
    {
        $expired = $this->logFile('2026-01-04', 5_184_002, 'expired');
        $retained = $this->logFile('2026-01-05', 5_183_995, 'retained');
        $result = $this->runHelper('execute');
        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertFileDoesNotExist($expired);
        self::assertFileExists($retained);
    }

    public function testUnclassifiedOrUnsafeEntryFailsClosedWithoutDeletion(): void
    {
        $old = $this->logFile('2026-01-06', 61 * 86_400, 'old');
        file_put_contents(self::LOG_ROOT . '/unexpected-secret.txt', 'secret');
        self::assertSame(70, $this->runHelper('execute')['exit']);
        self::assertFileExists($old);
        unlink(self::LOG_ROOT . '/unexpected-secret.txt');

        $unsafe = self::LOG_ROOT . '/log-2026-01-07.php';
        symlink('/dev/null', $unsafe);
        self::assertSame(70, $this->runHelper('dry-run')['exit']);
        unlink($unsafe);

        $unsafe = $this->logFile('2026-01-07', 61 * 86_400, 'mode');
        chmod($unsafe, 0600);
        self::assertSame(70, $this->runHelper('dry-run')['exit']);
        chmod($unsafe, 0644);
        chown($unsafe, 0);
        self::assertSame(70, $this->runHelper('dry-run')['exit']);
        chown($unsafe, $this->webUid);
        link($unsafe, self::LOG_ROOT . '/foreign-hardlink');
        self::assertSame(70, $this->runHelper('dry-run')['exit']);
    }

    public function testGlobalLockAndUnsafeDirectoryBlockWithoutDeletion(): void
    {
        $old = $this->logFile('2026-01-08', 61 * 86_400, 'old');
        chmod(self::LOG_ROOT, 0777);
        self::assertSame(70, $this->runHelper('execute')['exit']);
        self::assertFileExists($old);
        chmod(self::LOG_ROOT, 0755);

        $lock = fopen(self::ORCHESTRATOR_ROOT . '/locks/fh-production-change.lock', 'c+');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        $blocked = $this->runHelper('execute');
        self::assertSame(75, $blocked['exit']);
        self::assertSame('active_production_work', $this->decode($blocked)['reason']);
        self::assertFileExists($old);
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    public function testFileCountCapNeedsASecondPassBeforeMarker(): void
    {
        for ($index = 0; $index < 1001; $index++) {
            $day = sprintf(
                '%04d-%02d-%02d',
                2000 + intdiv($index, 336),
                intdiv($index % 336, 28) + 1,
                ($index % 28) + 1,
            );
            $this->logFile($day, 61 * 86_400, 'x');
        }
        $first = $this->runHelper('execute');
        $payload = $this->decode($first);
        self::assertSame(75, $first['exit'], $first['stdout'] . $first['stderr']);
        self::assertSame(1000, $payload['deleted_count']);
        self::assertSame(1, $payload['remaining_eligible_count']);
        self::assertTrue($payload['cap_exceeded']);
        self::assertFileDoesNotExist(self::STATE_ROOT . '/last-success.json');

        $second = $this->runHelper('execute');
        self::assertSame(0, $second['exit'], $second['stdout'] . $second['stderr']);
        self::assertSame(1, $this->decode($second)['deleted_count']);
        self::assertFileExists(self::STATE_ROOT . '/last-success.json');
    }

    public function testLogicalByteCapNeedsASecondPassBeforeMarker(): void
    {
        for ($day = 9; $day <= 13; $day++) {
            $path = $this->logFile(sprintf('2026-01-%02d', $day), 61 * 86_400, '');
            $handle = fopen($path, 'c+');
            self::assertIsResource($handle);
            self::assertTrue(ftruncate($handle, 128 * 1024 * 1024));
            fclose($handle);
            touch($path, time() - 61 * 86_400);
        }

        $first = $this->runHelper('execute');
        $payload = $this->decode($first);
        self::assertSame(75, $first['exit'], $first['stdout'] . $first['stderr']);
        self::assertSame(4, $payload['deleted_count']);
        self::assertSame(512 * 1024 * 1024, $payload['deleted_logical_bytes']);
        self::assertSame(1, $payload['remaining_eligible_count']);
        self::assertTrue($payload['cap_exceeded']);
        self::assertFileDoesNotExist(self::STATE_ROOT . '/last-success.json');

        $second = $this->runHelper('execute');
        self::assertSame(0, $second['exit'], $second['stdout'] . $second['stderr']);
        self::assertSame(1, $this->decode($second)['deleted_count']);
    }

    public function testMarkerStatusClassifiesMissingInvalidStaleAndFuture(): void
    {
        self::assertSame('missing', $this->decode($this->runHelper('marker-status', '129600'))['status']);
        file_put_contents(self::STATE_ROOT . '/last-success.json', "not-json\n");
        chmod(self::STATE_ROOT . '/last-success.json', 0600);
        self::assertSame('invalid', $this->decode($this->runHelper('marker-status', '129600'))['status']);

        $this->writeMarker('2020-01-01T00:00:00Z');
        self::assertSame('stale', $this->decode($this->runHelper('marker-status', '129600'))['status']);
        $this->writeMarker('2099-01-01T00:00:00Z');
        self::assertSame('invalid', $this->decode($this->runHelper('marker-status', '129600'))['status']);
    }

    private function logFile(string $date, int $ageSeconds, string $bytes): string
    {
        $path = self::LOG_ROOT . '/log-' . $date . '.php';
        file_put_contents($path, $bytes);
        chown($path, $this->webUid);
        chgrp($path, $this->webGid);
        chmod($path, 0644);
        touch($path, time() - $ageSeconds);
        return $path;
    }

    private function protectedFile(string $name, string $bytes): void
    {
        $path = self::LOG_ROOT . '/' . $name;
        file_put_contents($path, $bytes);
        chown($path, $this->webUid);
        chgrp($path, $this->webGid);
        chmod($path, 0644);
    }

    private function writeMarker(string $completedAt): void
    {
        $payload = [
            'completed_at_utc' => $completedAt,
            'deleted_count' => 0,
            'max_delete' => 1000,
            'max_delete_bytes' => 512 * 1024 * 1024,
            'remaining_eligible_count' => 0,
            'retention_seconds' => 60 * 86_400,
            'schema' => 'prod_app_log_retention_marker.v1',
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
            $item->isLink() || $item->isFile() ? @unlink($item->getPathname()) : @rmdir($item->getPathname());
        }
        @rmdir($path);
    }
}
