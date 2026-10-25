<?php

declare(strict_types=1);

namespace Tests\Unit\Helper;

use PHPUnit\Framework\TestCase;

final class RateLimitWindowTest extends TestCase
{
    /**
     * Execute the helper in a fresh process with a deterministic Unix clock.
     * The cache double expires entries using the same integer TTL contract as
     * Cache_file, while the helper still uses a real filesystem flock.
     *
     * @param list<int> $clocks
     * @return array{status: int, admitted: int, saved: list<array{key: string, value: mixed, ttl: int}>}
     */
    private function runWorker(array $clocks, int $maxRequests = 100, int $duration = 120): array
    {
        $directory = sys_get_temp_dir() . '/rate-limit-window-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $cachePath = $directory . '/cache';
        mkdir($cachePath, 0700, true);
        $helper = file_get_contents(dirname(__DIR__, 3) . '/application/helpers/rate_limit_helper.php');
        self::assertIsString($helper);
        $helper = str_replace(
            ['get_instance()', 'is_cli()', 'time()', 'date(', 'strtotime('],
            [
                '\\TestRateLimitClock\\get_instance()',
                '\\TestRateLimitClock\\is_cli()',
                '\\TestRateLimitClock\\time()',
                '\\TestRateLimitClock\\date(',
                '\\TestRateLimitClock\\strtotime(',
            ],
            $helper,
        );
        $worker = tempnam($directory, 'worker-');
        self::assertIsString($worker);
        $source = str_replace(
            ['__CACHE_PATH__', '__CLOCKS__', '__MAX__', '__DURATION__', '__HELPER__', '__OUTPUT__'],
            [
                var_export($cachePath, true),
                var_export($clocks, true),
                (string) $maxRequests,
                (string) $duration,
                var_export($helper, true),
                var_export($directory . '/result', true),
            ],
            <<<'PHP'
            <?php
            namespace TestRateLimitClock;

            define('BASEPATH', __DIR__ . '/');
            \date_default_timezone_set('Europe/Berlin');
            $GLOBALS['clock'] = __CLOCKS__;
            $GLOBALS['clockIndex'] = 0;
            $GLOBALS['saved'] = [];
            $GLOBALS['admitted'] = 0;

            function time(): int
            {
                return $GLOBALS['clock'][$GLOBALS['clockIndex']];
            }

            function date(string $format, ?int $timestamp = null): string
            {
                return \date($format, $timestamp ?? time());
            }

            function strtotime(string $datetime, ?int $baseTimestamp = null): int|false
            {
                return \strtotime($datetime, $baseTimestamp ?? time());
            }

            function is_cli(): bool { return false; }

            function &get_instance(): object
            {
                return $GLOBALS['ci'];
            }

            final class Cache
            {
                public function get_loaded_driver(): string { return 'file'; }

                public function get(string $key): mixed
                {
                    $entry = $GLOBALS['entries'][$key] ?? null;
                    if ($entry === null) {
                        return false;
                    }
                    if (time() > $entry['expires']) {
                        unset($GLOBALS['entries'][$key]);
                        @unlink(__CACHE_PATH__ . '/' . $key);
                        return false;
                    }
                    return $entry['value'];
                }

                public function save(string $key, mixed $value, int $ttl): bool
                {
                    $GLOBALS['entries'][$key] = ['value' => $value, 'expires' => time() + $ttl];
                    $GLOBALS['saved'][] = ['key' => $key, 'value' => $value, 'ttl' => $ttl];
                    file_put_contents(__CACHE_PATH__ . '/' . $key, serialize($value));
                    return true;
                }
            }

            final class Config
            {
                public function item(string $key): mixed
                {
                    return $key === 'rate_limiting' ? true : __CACHE_PATH__ . '/';
                }
            }

            final class Loader
            {
                public function driver(string $name, array $options): void {}
            }

            $GLOBALS['entries'] = [];
            $GLOBALS['ci'] = (object) ['config' => new Config(), 'load' => new Loader(), 'cache' => new Cache()];
            register_shutdown_function(static function (): void {
                file_put_contents(__OUTPUT__, json_encode([
                    'status' => http_response_code() ?: 200,
                    'admitted' => $GLOBALS['admitted'],
                    'saved' => $GLOBALS['saved'],
                ], JSON_THROW_ON_ERROR));
            });

            eval(substr(__HELPER__, 5));
            foreach ($GLOBALS['clock'] as $index => $_clock) {
                $GLOBALS['clockIndex'] = $index;
                rate_limit('203.0.113.10', __MAX__, __DURATION__);
                $GLOBALS['admitted']++;
            }
            PHP
            ,
        );
        file_put_contents($worker, $source);

        try {
            $process = proc_open([PHP_BINARY, $worker], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), (string) $stdout . (string) $stderr);
            $result = json_decode((string) file_get_contents($directory . '/result'), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($result);
            return $result;
        } finally {
            foreach (glob($cachePath . '/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($cachePath);
            unlink($worker);
            unlink($directory . '/result');
            $bucket = hexdec(substr(hash('sha256', 'rate_limit_key_203.0.113.10'), 0, 8)) % 64;
            unlink($directory . '/rate_limit.lock.' . sprintf('%02x', $bucket));
            rmdir($directory);
        }
    }

    public function testFallbackWindowUsesUnixDeadlinesAcrossRepeatedLocalHour(): void
    {
        $start = strtotime('2026-10-25 00:52:08 UTC');
        $clocks = range($start, $start + 17 * 60, 30);
        $result = $this->runWorker($clocks);

        $this->assertSame(200, $result['status']);
        $this->assertCount(
            count($clocks),
            array_filter(
                $result['saved'],
                static fn(array $entry): bool => $entry['key'] === 'rate_limit_key_203.0.113.10',
            ),
        );
        $this->assertSame(count($clocks), $result['admitted']);
        $deadlines = array_values(
            array_filter(
                $result['saved'],
                static fn(array $entry): bool => $entry['key'] === 'rate_limit_tmp_203.0.113.10',
            ),
        );
        foreach ($deadlines as $index => $entry) {
            $this->assertSame($start + $index * 120 + 120, $entry['value']);
            $this->assertSame(240, $entry['ttl']);
        }
        $counts = array_values(
            array_filter(
                $result['saved'],
                static fn(array $entry): bool => $entry['key'] === 'rate_limit_key_203.0.113.10',
            ),
        );
        foreach ($counts as $index => $entry) {
            $this->assertSame(($index % 4) + 1, $entry['value']);
        }
    }

    public function testForwardTransitionAndNormalDayRemainStable(): void
    {
        foreach (['2026-03-29 00:52:08 UTC', '2026-02-15 00:52:08 UTC'] as $date) {
            $start = strtotime($date);
            $result = $this->runWorker(range($start, $start + 17 * 60, 30));
            $this->assertSame(200, $result['status']);
            $this->assertSame(35, $result['admitted']);
        }
    }

    public function testExactDeadlineResetsAndThirdRequestIsRejectedAtLimitTwo(): void
    {
        $start = strtotime('2026-02-15 00:00:00 UTC');
        $reset = $this->runWorker([$start, $start + 120], 100, 120);
        $this->assertSame(200, $reset['status']);
        $this->assertSame(2, $reset['admitted']);
        $counts = array_values(
            array_filter(
                $reset['saved'],
                static fn(array $entry): bool => $entry['key'] === 'rate_limit_key_203.0.113.10',
            ),
        );
        $this->assertSame([1, 1], array_column($counts, 'value'));

        $result = $this->runWorker([$start, $start + 30, $start + 60], 2, 120);

        $this->assertSame(429, $result['status']);
        $this->assertSame(2, $result['admitted']);
    }
}
