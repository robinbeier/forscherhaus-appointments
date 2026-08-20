<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use Tests\Support\RootHostTestPrerequisites;

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
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Linux root is required for the protected session-retention contract.');
        }
        RootHostTestPrerequisites::enforce($this, RootHostTestPrerequisites::processRuntimeCheck());
        RootHostTestPrerequisites::enforce($this, RootHostTestPrerequisites::pythonRuntimeCheck());
        if (posix_geteuid() !== 0) {
            RootHostTestPrerequisites::enforce(
                $this,
                RootHostTestPrerequisites::classify(
                    false,
                    'root_required',
                    'Linux root is required for the protected session-retention contract.',
                ),
            );
        }
        if (!is_executable('/usr/bin/setpriv')) {
            RootHostTestPrerequisites::enforce(
                $this,
                RootHostTestPrerequisites::classify(
                    false,
                    'setpriv_missing',
                    'The Linux root gate requires /usr/bin/setpriv to enforce the production capability boundary.',
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
        $this->helper = dirname(__DIR__, 3) . '/scripts/ops/libexec/session_retention_v1.py';

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
                        'Existing web-root ancestors must already satisfy the production trust boundary.',
                        false,
                    ),
                );
            }
        }
        RootHostTestPrerequisites::enforce(
            $this,
            RootHostTestPrerequisites::ownershipCheck($this->webUid, $this->webGid, '/var/www/html'),
        );
        RootHostTestPrerequisites::enforce(
            $this,
            RootHostTestPrerequisites::capabilitySemantics($this->webUid, $this->webGid),
        );
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

    public function testLegacyModeNormalizationPreservesFileAndDoesNotTouchRetentionMarker(): void
    {
        $legacy = $this->session('4', 100, 'sensitive legacy payload');
        chmod($legacy, 0644);
        $secure = $this->session('9', 100, 'already secure payload');
        $before = lstat($legacy);
        $secureBefore = lstat($secure);
        self::assertIsArray($before);
        self::assertIsArray($secureBefore);
        $sha = hash_file('sha256', $legacy);
        $this->writeMarker('2026-08-13T08:00:00Z');
        $marker = self::STATE_ROOT . '/last-success.json';
        $markerBytes = (string) file_get_contents($marker);
        $markerBefore = lstat($marker);

        $blockedRetention = $this->runHelper('dry-run');
        self::assertSame(70, $blockedRetention['exit']);
        self::assertSame(0644, fileperms($legacy) & 0777);

        $dryRun = $this->runHelper('normalize-modes', 'dry-run');
        self::assertSame(0, $dryRun['exit'], $dryRun['stderr']);
        $dryPayload = $this->decode($dryRun);
        self::assertSame('required', $dryPayload['status']);
        self::assertSame(1, $dryPayload['legacy_before_count']);
        self::assertSame(1, $dryPayload['already_secure_count']);
        self::assertSame(1, $dryPayload['would_normalize_count']);
        self::assertFalse($dryPayload['mutation_performed']);
        self::assertSame(0644, fileperms($legacy) & 0777);

        $execute = $this->runHelperWithCaps('-all,+dac_override,+fowner', 'normalize-modes', 'execute');
        self::assertSame(0, $execute['exit'], $execute['stdout'] . $execute['stderr']);
        $payload = $this->decode($execute);
        self::assertSame('pass', $payload['status']);
        self::assertSame(1, $payload['normalized_count']);
        self::assertSame(1, $payload['already_secure_count']);
        self::assertSame(0, $payload['remaining_legacy_count']);
        self::assertTrue($payload['mutation_performed']);

        clearstatcache(true, $legacy);
        $after = lstat($legacy);
        self::assertIsArray($after);
        self::assertSame(0600, $after['mode'] & 0777);
        foreach (['dev', 'ino', 'uid', 'gid', 'nlink', 'size', 'mtime'] as $field) {
            self::assertSame($before[$field], $after[$field], $field);
        }
        self::assertSame($sha, hash_file('sha256', $legacy));
        self::assertSame('sensitive legacy payload', file_get_contents($legacy));
        self::assertSame($secureBefore, lstat($secure));
        self::assertSame('already secure payload', file_get_contents($secure));
        self::assertSame($markerBytes, file_get_contents($marker));
        $markerAfter = lstat($marker);
        self::assertIsArray($markerAfter);
        foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
            self::assertSame($markerBefore[$field], $markerAfter[$field], 'marker.' . $field);
        }
        self::assertStringNotContainsString(basename($legacy), $execute['stdout'] . $execute['stderr']);
        self::assertStringNotContainsString('sensitive', $execute['stdout'] . $execute['stderr']);

        self::assertSame(0, $this->runHelper('dry-run')['exit']);
        $replay = $this->runHelper('normalize-modes', 'execute');
        self::assertSame(0, $replay['exit']);
        self::assertSame(0, $this->decode($replay)['normalized_count']);
    }

    public function testNormalizationFailsClosedBeforeMutationForUnsafeValidEntry(): void
    {
        $legacy = $this->session('5', 100, 'legacy');
        chmod($legacy, 0644);
        $unsafe = $this->session('6', 100, 'unsafe');
        chmod($unsafe, 0660);

        $result = $this->runHelperWithCaps('-all,+dac_override,+fowner', 'normalize-modes', 'execute');

        self::assertSame(70, $result['exit']);
        self::assertSame('unsafe_session_entry', $this->decode($result)['reason']);
        self::assertSame('prod_session_mode_normalization.v1', $this->decode($result)['schema']);
        self::assertSame(0644, fileperms($legacy) & 0777);
        self::assertSame(0660, fileperms($unsafe) & 0777);
        self::assertFileDoesNotExist(self::STATE_ROOT . '/last-success.json');
    }

    public function testNormalizationUsageFailureUsesItsOwnSchema(): void
    {
        $result = $this->runHelper('normalize-modes');

        self::assertSame(64, $result['exit']);
        $payload = $this->decode($result);
        self::assertSame('usage', $payload['reason']);
        self::assertSame('prod_session_mode_normalization.v1', $payload['schema']);
        self::assertSame('blocked', $payload['status']);
    }

    public function testLockedLegacySessionIsPartialAndRetryConverges(): void
    {
        $legacy = $this->session('7', 100, 'locked');
        chmod($legacy, 0644);
        $handle = fopen($legacy, 'rb');
        self::assertIsResource($handle);
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));

        $partial = $this->runHelper('normalize-modes', 'execute');
        self::assertSame(75, $partial['exit']);
        self::assertSame('partial', $this->decode($partial)['status']);
        self::assertSame(0644, fileperms($legacy) & 0777);

        flock($handle, LOCK_UN);
        fclose($handle);
        $success = $this->runHelperWithCaps('-all,+dac_override,+fowner', 'normalize-modes', 'execute');
        self::assertSame(0, $success['exit'], $success['stderr']);
        self::assertSame(0600, fileperms($legacy) & 0777);
    }

    public function testNormalizationRequiresFownerInAdditionToDacOverride(): void
    {
        $legacy = $this->session('8', 100, 'capability');
        chmod($legacy, 0644);

        $withoutFowner = $this->runHelperWithCaps('-all,+dac_override', 'normalize-modes', 'execute');
        self::assertSame(70, $withoutFowner['exit']);
        self::assertSame(0644, fileperms($legacy) & 0777);

        $withFowner = $this->runHelperWithCaps('-all,+dac_override,+fowner', 'normalize-modes', 'execute');
        self::assertSame(0, $withFowner['exit'], $withFowner['stdout'] . $withFowner['stderr']);
        self::assertSame(0600, fileperms($legacy) & 0777);
    }

    public function testNormalizationCapRequiresASecondPass(): void
    {
        for ($index = 0; $index < 10_001; $index++) {
            $path = $this->session(sprintf('%032x', $index + 20_000), 100, 'x');
            chmod($path, 0644);
        }

        $first = $this->runHelperWithCaps('-all,+dac_override,+fowner', 'normalize-modes', 'execute');
        $firstPayload = $this->decode($first);
        self::assertSame(75, $first['exit'], $first['stdout'] . $first['stderr']);
        self::assertSame('partial', $firstPayload['status']);
        self::assertSame(10_000, $firstPayload['normalized_count']);
        self::assertSame(1, $firstPayload['remaining_legacy_count']);
        self::assertTrue($firstPayload['cap_exceeded']);

        $second = $this->runHelperWithCaps('-all,+dac_override,+fowner', 'normalize-modes', 'execute');
        $secondPayload = $this->decode($second);
        self::assertSame(0, $second['exit'], $second['stderr']);
        self::assertSame(1, $secondPayload['normalized_count']);
        self::assertSame(0, $secondPayload['remaining_legacy_count']);
    }

    public function testBlockedGlobalLockCannotCreateMissingPrivateState(): void
    {
        $legacy = $this->session('a0', 100, 'global lock');
        chmod($legacy, 0644);
        rmdir(self::STATE_ROOT);
        $global = fopen(self::ORCHESTRATOR_ROOT . '/locks/fh-production-change.lock', 'c+');
        self::assertIsResource($global);
        self::assertTrue(flock($global, LOCK_EX | LOCK_NB));

        $blocked = $this->runHelperWithCaps('-all,+dac_override,+fowner', 'normalize-modes', 'execute');

        self::assertSame(75, $blocked['exit']);
        self::assertSame('active_production_work', $this->decode($blocked)['reason']);
        self::assertDirectoryDoesNotExist(self::STATE_ROOT);
        self::assertSame(0644, fileperms($legacy) & 0777);
        flock($global, LOCK_UN);
        fclose($global);
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
        return $this->runHelperWithCaps('-all,+dac_override', ...$arguments);
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private function runHelperWithCaps(string $boundingSet, string ...$arguments): array
    {
        return $this->runCommand(
            array_merge(
                [
                    '/usr/bin/setpriv',
                    '--bounding-set=' . $boundingSet,
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
