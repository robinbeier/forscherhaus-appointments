<?php

declare(strict_types=1);

namespace Tests\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Early_rate_limit;

require_once APPPATH . 'core/Early_rate_limit.php';

final class EarlyRateLimitTest extends TestCase
{
    private string $root;
    private string $directory;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/early-rate-limit-' . bin2hex(random_bytes(8));
        $this->directory = $this->root . '/cache';
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/cache/*') ?: [] as $path) {
            is_dir($path) && !is_link($path) ? rmdir($path) : unlink($path);
        }
        rmdir($this->directory);
        foreach (glob($this->root . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->root);
    }

    public function testCounterUsesCodeIgniterFormatAndCountsExactlyOnce(): void
    {
        Early_rate_limit::enforce('203.0.113.10', 2, 60, $this->directory);
        Early_rate_limit::enforce('203.0.113.10', 2, 60, $this->directory);
        self::assertSame(2, $this->entry('rate_limit_key_')['data']);
        self::assertSame(60, $this->entry('rate_limit_key_')['ttl']);
        self::assertIsInt($this->entry('rate_limit_tmp_')['data']);
        self::assertSame(120, $this->entry('rate_limit_tmp_')['ttl']);
    }

    public function testThirdRequestIsRejectedAtLimitTwo(): void
    {
        self::assertSame(429, $this->worker(3, 2));
        self::assertSame(3, $this->entry('rate_limit_key_')['data']);
    }

    public function testExpiredWindowResetsWithoutWaitingForSlidingCacheTtl(): void
    {
        $this->seed('rate_limit_key_', 30);
        $this->seed('rate_limit_tmp_', time() - 1, 120);
        self::assertSame(200, $this->worker());
        self::assertSame(1, $this->entry('rate_limit_key_')['data']);
    }

    public function testLegacyDstDeadlineMigratesConservatively(): void
    {
        $this->seed('rate_limit_key_', 2);
        $this->seed('rate_limit_tmp_', '2026-10-25 02:30:00', 120);
        self::assertSame(429, $this->worker(1, 2));
        self::assertSame(3, $this->entry('rate_limit_key_')['data']);
        self::assertGreaterThan(time(), $this->entry('rate_limit_tmp_')['data']);
    }

    public function testMalformedNegativeAndMissingDeadlineFailClosed(): void
    {
        foreach ([-1, 'count', null] as $value) {
            $this->seed('rate_limit_key_', $value);
            self::assertSame(503, $this->worker());
        }
        $this->seed('rate_limit_key_', 1);
        self::assertSame(503, $this->worker());
        file_put_contents($this->directory . '/rate_limit_key_203.0.113.10', 'broken');
        self::assertSame(503, $this->worker());
    }

    public function testSymlinkAndDirectoryEntriesFailClosed(): void
    {
        $path = $this->directory . '/rate_limit_key_203.0.113.10';
        mkdir($path);
        self::assertSame(503, $this->worker());
        rmdir($path);
        symlink($this->root . '/absent', $path);
        self::assertSame(503, $this->worker());
    }

    public function testNewAddressesReplaceOldestEntryWithoutExceedingBucketCapacity(): void
    {
        $addresses = [];
        $bucket = null;
        for ($first = 0; $first < 256 && count($addresses) < 65; $first++) {
            for ($second = 1; $second < 256 && count($addresses) < 65; $second++) {
                $ip = '198.51.' . $first . '.' . $second;
                $candidate = hexdec(substr(hash('sha256', 'rate_limit_key_' . $ip), 0, 8)) % 64;
                if ($bucket === null) {
                    $bucket = $candidate;
                }
                if ($candidate === $bucket) {
                    $addresses[] = $ip;
                }
            }
        }
        self::assertCount(65, $addresses);

        foreach (array_slice($addresses, 0, 64) as $ip) {
            self::assertSame(200, $this->workerForIp($ip));
        }
        self::assertSame(200, $this->workerForIp($addresses[64]));
        self::assertCount(1, glob($this->directory . '/rate_limit.ledger.*') ?: []);
        self::assertCount(64, unserialize(file_get_contents($this->ledgerPath($addresses[0])), ['allowed_classes' => false])['data']);
    }

    public function testDistinctLowVolumeIdentitiesCanChurnBeyondLedgerCapacity(): void
    {
        $addresses = [];
        $bucket = null;
        for ($first = 0; $first < 256 && count($addresses) < 129; $first++) {
            for ($second = 1; $second < 256 && count($addresses) < 129; $second++) {
                $ip = '198.18.' . $first . '.' . $second;
                $candidate = hexdec(substr(hash('sha256', 'rate_limit_key_' . $ip), 0, 8)) % 64;
                $bucket ??= $candidate;
                if ($candidate === $bucket) {
                    $addresses[] = $ip;
                }
            }
        }
        self::assertCount(129, $addresses);
        foreach ($addresses as $ip) {
            self::assertSame(200, $this->workerForIp($ip));
        }
    }

    public function testBucketRequestBudgetStopsHighVolumeBeforeEviction(): void
    {
        $ip = '203.0.113.10';
        file_put_contents(
            $this->bucketPath($ip),
            serialize(['time' => time(), 'ttl' => 120, 'data' => ['window' => time() + 60, 'requests' => 255]]),
        );
        $bucket = hexdec(substr(hash('sha256', 'rate_limit_key_' . $ip), 0, 8)) % 64;
        $otherIp = null;
        for ($last = 1; $last < 255; $last++) {
            $candidate = '203.0.113.' . $last;
            if ($candidate !== $ip && hexdec(substr(hash('sha256', 'rate_limit_key_' . $candidate), 0, 8)) % 64 === $bucket) {
                $otherIp = $candidate;
                break;
            }
        }
        self::assertNotNull($otherIp);
        self::assertSame(200, $this->workerForIp($ip, 1, 1));
        self::assertSame(429, $this->workerForIp($otherIp, 1, 1));
    }

    public function testOverQuotaUntrackedLegacyIdentityDoesNotGrowFullLedger(): void
    {
        $ip = '203.0.113.10';
        $bucket = hexdec(substr(hash('sha256', 'rate_limit_key_' . $ip), 0, 8)) % 64;
        $entries = [];
        for ($first = 0; count($entries) < 64 && $first < 256; $first++) {
            for ($second = 1; count($entries) < 64 && $second < 256; $second++) {
                $candidate = '198.19.' . $first . '.' . $second;
                if (hexdec(substr(hash('sha256', 'rate_limit_key_' . $candidate), 0, 8)) % 64 === $bucket) {
                    $entries['rate_limit_key_' . $candidate] = time() + 120;
                }
            }
        }
        self::assertCount(64, $entries);
        file_put_contents(
            $this->ledgerPath($ip),
            serialize(['time' => time(), 'ttl' => 120, 'data' => $entries]),
        );
        $this->seed('rate_limit_key_', 1);
        $this->seed('rate_limit_tmp_', time() + 60, 120);
        self::assertSame(429, $this->workerForIp($ip, 1, 1));
        $ledger = unserialize(file_get_contents($this->ledgerPath($ip)), ['allowed_classes' => false]);
        self::assertCount(64, $ledger['data']);
        self::assertArrayNotHasKey('rate_limit_key_' . $ip, $ledger['data']);
    }

    public function testMalformedBucketAdmissionStateFailsClosed(): void
    {
        file_put_contents(
            $this->bucketPath('203.0.113.10'),
            serialize(['time' => time(), 'ttl' => 120, 'data' => ['unexpected' => true]]),
        );
        self::assertSame(503, $this->worker());
    }

    public function testExpiredLedgerEntriesArePrunedUnderBucketLock(): void
    {
        $ip = '203.0.113.10';
        $currentIp = '203.0.113.24';
        $ledgerPath = $this->ledgerPath($ip);
        file_put_contents(
            $ledgerPath,
            serialize([
                'time' => time(),
                'ttl' => 120,
                'data' => ['rate_limit_key_' . $ip => time() - 1],
            ]),
        );
        file_put_contents(
            $this->directory . '/rate_limit_key_' . $ip,
            serialize(['time' => time() - 10, 'ttl' => 1, 'data' => 1]),
        );
        file_put_contents(
            $this->directory . '/rate_limit_tmp_' . $ip,
            serialize(['time' => time() - 10, 'ttl' => 1, 'data' => time() - 1]),
        );
        self::assertSame(200, $this->workerForIp($currentIp));
        self::assertFileDoesNotExist($this->directory . '/rate_limit_key_' . $ip);
        self::assertFileDoesNotExist($this->directory . '/rate_limit_tmp_' . $ip);
        $ledger = unserialize(file_get_contents($ledgerPath), ['allowed_classes' => false]);
        self::assertArrayNotHasKey('rate_limit_key_' . $ip, $ledger['data']);
        self::assertGreaterThan(time(), $ledger['data']['rate_limit_key_' . $currentIp]);
    }

    public function testActiveKeyRefreshesLedgerExpiry(): void
    {
        $ip = '203.0.113.10';
        $ledgerPath = $this->ledgerPath($ip);
        $oldExpiry = time() + 3;
        file_put_contents(
            $ledgerPath,
            serialize(['time' => time(), 'ttl' => 120, 'data' => ['rate_limit_key_' . $ip => $oldExpiry]]),
        );
        self::assertSame(200, $this->workerForIp($ip));
        $ledger = unserialize(file_get_contents($ledgerPath), ['allowed_classes' => false]);
        self::assertGreaterThan($oldExpiry, $ledger['data']['rate_limit_key_' . $ip]);
    }

    public function testFullLedgerReplacesOldestLegacyEntry(): void
    {
        $ip = '203.0.113.10';
        $ledgerPath = $this->ledgerPath($ip);
        $entries = [];
        $bucket = hexdec(substr(hash('sha256', 'rate_limit_key_' . $ip), 0, 8)) % 64;
        for ($first = 0; count($entries) < 64 && $first < 256; $first++) {
            for ($second = 1; count($entries) < 64 && $second < 256; $second++) {
                $candidate = '198.51.' . $first . '.' . $second;
                if (hexdec(substr(hash('sha256', 'rate_limit_key_' . $candidate), 0, 8)) % 64 === $bucket) {
                    $entries['rate_limit_key_' . $candidate] = time() + 120;
                }
            }
        }
        self::assertCount(64, $entries);
        file_put_contents($ledgerPath, serialize(['time' => time(), 'ttl' => 120, 'data' => $entries]));
        file_put_contents(
            $this->directory . '/rate_limit_key_' . $ip,
            serialize(['time' => time(), 'ttl' => 60, 'data' => 1]),
        );
        file_put_contents(
            $this->directory . '/rate_limit_tmp_' . $ip,
            serialize(['time' => time(), 'ttl' => 120, 'data' => time() + 60]),
        );
        self::assertSame(200, $this->workerForIp($ip));
    }

    public function testMalformedLedgerFailsClosed(): void
    {
        $ip = '203.0.113.10';
        $ledgerPath = $this->ledgerPath($ip);
        file_put_contents(
            $ledgerPath,
            serialize([
                'time' => time(),
                'ttl' => 120,
                'data' => ['unexpected' => 'not-an-expiry'],
            ]),
        );
        self::assertSame(503, $this->workerForIp($ip));
    }

    public function testLedgerPathTraversalKeyFailsClosedWithoutDeletingOutsideFile(): void
    {
        $ip = '203.0.113.10';
        $ledgerPath = $this->ledgerPath($ip);
        $outside = $this->root . '/outside-sentinel';
        file_put_contents($outside, 'keep');
        file_put_contents(
            $ledgerPath,
            serialize([
                'time' => time(),
                'ttl' => 120,
                'data' => ['rate_limit_key_../../outside-sentinel' => time() - 1],
            ]),
        );

        self::assertSame(503, $this->workerForIp($ip));
        self::assertFileExists($outside);
        self::assertSame('keep', file_get_contents($outside));
    }

    public function testExpiredLedgerForAnotherPrefixDoesNotBlockCurrentPrefix(): void
    {
        $ip = '203.0.113.10';
        $globalLedger = $this->ledgerPath($ip);
        file_put_contents(
            $globalLedger,
            serialize([
                'time' => time(),
                'ttl' => 120,
                'data' => ['rate_limit_key_' . $ip => time() - 1],
            ]),
        );

        self::assertSame(200, $this->workerForIpWithPrefixes($ip, 'booking_creation_key_', 'booking_creation_tmp_'));
        self::assertFileExists($globalLedger);
        self::assertCount(2, glob($this->directory . '/rate_limit.ledger.*') ?: []);
    }

    public function testCreationWindowsDoNotShareOrRefundTheHourlyBudget(): void
    {
        Early_rate_limit::enforce(
            '203.0.113.10',
            8,
            60,
            $this->directory,
            'booking_creation_key_',
            'booking_creation_tmp_',
        );
        Early_rate_limit::enforce(
            '203.0.113.10',
            40,
            3600,
            $this->directory,
            'booking_creation_hour_key_',
            'booking_creation_hour_tmp_',
        );
        self::assertSame(1, $this->entry('booking_creation_key_')['data']);
        self::assertSame(1, $this->entry('booking_creation_hour_key_')['data']);
        self::assertSame(3600, $this->entry('booking_creation_hour_key_')['ttl']);
        self::assertCount(2, glob($this->directory . '/rate_limit.ledger.*') ?: []);
    }

    public function testConcurrentRequestsShareOneAtomicBudget(): void
    {
        $workers = [];
        for ($i = 0; $i < 8; $i++) {
            $workers[] = $this->startWorker(1, 4);
        }
        $statuses = [];
        foreach ($workers as $worker) {
            $statuses[] = $this->finishWorker($worker);
        }
        self::assertSame(4, count(array_filter($statuses, static fn($code) => $code === 200)));
        self::assertSame(4, count(array_filter($statuses, static fn($code) => $code === 429)));
        self::assertSame(8, $this->entry('rate_limit_key_')['data']);
    }

    private function seed(string $prefix, mixed $data, int $ttl = 60): void
    {
        file_put_contents(
            $this->directory . '/' . $prefix . '203.0.113.10',
            serialize(['time' => time(), 'ttl' => $ttl, 'data' => $data]),
        );
    }

    private function entry(string $prefix): array
    {
        return unserialize(file_get_contents($this->directory . '/' . $prefix . '203.0.113.10'), [
            'allowed_classes' => false,
        ]);
    }

    private function ledgerPath(
        string $ip,
        string $keyPrefix = 'rate_limit_key_',
        string $remainPrefix = 'rate_limit_tmp_',
    ): string {
        $bucket = hexdec(substr(hash('sha256', $keyPrefix . $ip), 0, 8)) % 64;
        $scope = substr(hash('sha256', $keyPrefix . "\0" . $remainPrefix), 0, 8);

        return $this->directory . '/rate_limit.ledger.' . $scope . '.' . sprintf('%02x', $bucket);
    }

    private function bucketPath(string $ip): string
    {
        $bucket = hexdec(substr(hash('sha256', 'rate_limit_key_' . $ip), 0, 8)) % 64;
        $scope = substr(hash('sha256', "rate_limit_key_\0rate_limit_tmp_"), 0, 8);

        return $this->directory . '/rate_limit.bucket.' . $scope . '.' . sprintf('%02x', $bucket);
    }

    private function worker(int $calls = 1, int $limit = 2): int
    {
        return $this->workerForIp('203.0.113.10', $calls, $limit);
    }

    private function workerForIp(string $ip, int $calls = 1, int $limit = 2): int
    {
        return $this->finishWorker($this->startWorker($calls, $limit, $ip));
    }

    private function workerForIpWithPrefixes(string $ip, string $keyPrefix, string $remainPrefix): int
    {
        $id = bin2hex(random_bytes(5));
        $script = $this->root . '/worker-' . $id . '.php';
        $status = $this->root . '/status-' . $id;
        file_put_contents(
            $script,
            str_replace(
                ['__STATUS__', '__HELPER__', '__IP__', '__CACHE__', '__KEY_PREFIX__', '__REMAIN_PREFIX__'],
                [
                    var_export($status, true),
                    var_export(APPPATH . 'core/Early_rate_limit.php', true),
                    var_export($ip, true),
                    var_export($this->directory, true),
                    var_export($keyPrefix, true),
                    var_export($remainPrefix, true),
                ],
                <<<'PHP'
                <?php
                define('BASEPATH', __DIR__ . '/');
                register_shutdown_function(static function (): void { file_put_contents(__STATUS__, (string) (http_response_code() ?: 200)); });
                require __HELPER__;
                Early_rate_limit::enforce(__IP__, 2, 60, __CACHE__, __KEY_PREFIX__, __REMAIN_PREFIX__);
                PHP
                ,
            ),
        );
        $process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        return $this->finishWorker([$process, $pipes, $status]);
    }

    private function startWorker(int $calls, int $limit, string $ip = '203.0.113.10'): array
    {
        $id = bin2hex(random_bytes(5));
        $script = $this->root . '/worker-' . $id . '.php';
        $status = $this->root . '/status-' . $id;
        file_put_contents(
            $script,
            str_replace(
                ['__STATUS__', '__HELPER__', '__CALLS__', '__LIMIT__', '__CACHE__', '__IP__'],
                [
                    var_export($status, true),
                    var_export(APPPATH . 'core/Early_rate_limit.php', true),
                    (string) $calls,
                    (string) $limit,
                    var_export($this->directory, true),
                    var_export($ip, true),
                ],
                <<<'PHP'
                <?php
                // Deliberately no CI controller, loader, database, or session exists here.
                define('BASEPATH', __DIR__ . '/');
                register_shutdown_function(static function (): void { file_put_contents(__STATUS__, (string) (http_response_code() ?: 200)); });
                require __HELPER__;
                for ($i = 0; $i < __CALLS__; $i++) { Early_rate_limit::enforce(__IP__, __LIMIT__, 60, __CACHE__); }
                PHP
                ,
            ),
        );
        $process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        return [$process, $pipes, $status];
    }

    private function finishWorker(array $worker): int
    {
        [$process, $pipes, $status] = $worker;
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $out . $err);
        return (int) file_get_contents($status);
    }
}
