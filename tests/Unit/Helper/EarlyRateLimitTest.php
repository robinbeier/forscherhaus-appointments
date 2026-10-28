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

    private function worker(int $calls = 1, int $limit = 2): int
    {
        return $this->finishWorker($this->startWorker($calls, $limit));
    }

    private function startWorker(int $calls, int $limit): array
    {
        $id = bin2hex(random_bytes(5));
        $script = $this->root . '/worker-' . $id . '.php';
        $status = $this->root . '/status-' . $id;
        file_put_contents(
            $script,
            str_replace(
                ['__STATUS__', '__HELPER__', '__CALLS__', '__LIMIT__', '__CACHE__'],
                [
                    var_export($status, true),
                    var_export(APPPATH . 'core/Early_rate_limit.php', true),
                    (string) $calls,
                    (string) $limit,
                    var_export($this->directory, true),
                ],
                <<<'PHP'
                <?php
                // Deliberately no CI controller, loader, database, or session exists here.
                define('BASEPATH', __DIR__ . '/');
                register_shutdown_function(static function (): void { file_put_contents(__STATUS__, (string) (http_response_code() ?: 200)); });
                require __HELPER__;
                for ($i = 0; $i < __CALLS__; $i++) { Early_rate_limit::enforce('203.0.113.10', __LIMIT__, 60, __CACHE__); }
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
