<?php

namespace Tests\Unit\Helper;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once APPPATH . 'helpers/rate_limit_helper.php';

final class RateLimitHelperTest extends TestCase
{
    /**
     * @return array{status: string, admitted: bool, calls: array<int, string>}
     */
    private function runFailClosedWorker(string $mode): array
    {
        $repository = dirname(__DIR__, 3);
        $directory = sys_get_temp_dir() . '/rate-limit-failure-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $cachePath = $mode === 'lock' ? $directory . '/missing/cache' : $directory . '/cache';
        if ($mode !== 'lock') {
            mkdir($cachePath, 0700, true);
        }
        if ($mode === 'corrupt-count') {
            file_put_contents($cachePath . '/rate_limit_key_203.0.113.10', 'corrupt');
        }

        $statusPath = $directory . '/status';
        $admittedPath = $directory . '/admitted';
        $callsPath = $directory . '/calls';
        $worker = tempnam($directory, 'worker-');
        file_put_contents(
            $worker,
            str_replace(
                [
                    '__APP_PATH__',
                    '__CACHE_PATH__',
                    '__MODE__',
                    '__STATUS_PATH__',
                    '__ADMITTED_PATH__',
                    '__CALLS_PATH__',
                ],
                [
                    var_export($repository . '/application/', true),
                    var_export($cachePath, true),
                    var_export($mode, true),
                    var_export($statusPath, true),
                    var_export($admittedPath, true),
                    var_export($callsPath, true),
                ],
                <<<'PHP'
                <?php
                define('BASEPATH', __DIR__ . '/');
                define('APPPATH', __APP_PATH__);
                $_SERVER['HTTP_HOST'] = 'example.test';

                function is_cli(): bool
                {
                    return false;
                }

                register_shutdown_function(static function (): void {
                    file_put_contents(__STATUS_PATH__, (string) http_response_code());
                });

                final class RateLimitFailureCache
                {
                    public function get_loaded_driver(): string { return __MODE__ === 'dummy' ? 'dummy' : 'file'; }

                    public function get(string $key): mixed
                    {
                        if (__MODE__ === 'save-count' || (__MODE__ === 'save-remain' && $key === 'rate_limit_key_203.0.113.10') || __MODE__ === 'corrupt-count') {
                            return false;
                        }
                        if ($key === 'rate_limit_tmp_203.0.113.10') {
                            return __MODE__ === 'missing-remain' ? false : (__MODE__ === 'invalid-remain' ? 'invalid' : (__MODE__ === 'save-reset' ? '2000-01-01 00:00:00' : '2099-01-01 00:00:00'));
                        }
                        return 1;
                    }

                    public function save(string $key, mixed $value, int $ttl): bool
                    {
                        file_put_contents(__CALLS_PATH__, $key . PHP_EOL, FILE_APPEND);
                        if (__MODE__ === 'save-count' || (__MODE__ === 'save-remain' && $key === 'rate_limit_tmp_203.0.113.10')) {
                            return false;
                        }
                        return !in_array(__MODE__, ['save-increment', 'save-reset'], true);
                    }
                }

                final class RateLimitFailureConfig
                {
                    public function item(string $key): mixed
                    {
                        return $key === 'rate_limiting' ? true : __CACHE_PATH__ . '/';
                    }
                }

                final class RateLimitFailureLoader
                {
                    public function driver(string $name, array $options): void
                    {
                    }
                }

                $GLOBALS['rateLimitCi'] = (object) [
                    'config' => new RateLimitFailureConfig(),
                    'load' => new RateLimitFailureLoader(),
                    'cache' => new RateLimitFailureCache(),
                ];

                function &get_instance(): object
                {
                    return $GLOBALS['rateLimitCi'];
                }

                require_once APPPATH . 'helpers/rate_limit_helper.php';
                rate_limit('203.0.113.10', 2, 3600);
                touch(__ADMITTED_PATH__);
                PHP
                ,
            ),
        );

        try {
            $process = proc_open([PHP_BINARY, $worker], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process));

            return [
                'status' => trim((string) file_get_contents($statusPath)),
                'admitted' => is_file($admittedPath),
                'calls' => is_file($callsPath) ? file($callsPath, FILE_IGNORE_NEW_LINES) : [],
            ];
        } finally {
            foreach (glob($cachePath . '/*') ?: [] as $path) {
                unlink($path);
            }
            if (is_dir($cachePath)) {
                rmdir($cachePath);
            }
            foreach (glob($directory . '/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    public function testUnavailableLockFailsClosed(): void
    {
        $result = $this->runFailClosedWorker('lock');
        $this->assertSame('503', $result['status']);
        $this->assertFalse($result['admitted']);
    }

    public function testUnsupportedDummyCacheFailsClosed(): void
    {
        $result = $this->runFailClosedWorker('dummy');
        $this->assertSame('503', $result['status']);
        $this->assertFalse($result['admitted']);
        $this->assertSame([], $result['calls']);
    }

    public function testExistingCorruptCounterFailsClosed(): void
    {
        $result = $this->runFailClosedWorker('corrupt-count');
        $this->assertSame('503', $result['status']);
        $this->assertFalse($result['admitted']);
        $this->assertSame([], $result['calls']);
    }

    public function testLockTimeoutFailsClosed(): void
    {
        $directory = sys_get_temp_dir() . '/rate-limit-timeout-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $cachePath = $directory . '/cache';
        mkdir($cachePath, 0700, true);
        $bucket = hexdec(substr(hash('sha256', 'rate_limit_key_203.0.113.10'), 0, 8)) % 64;
        $lockPath = dirname($cachePath) . '/rate_limit.lock.' . sprintf('%02x', $bucket);
        $readyPath = $directory . '/ready';
        $releasePath = $directory . '/release';
        $statusPath = $directory . '/status';
        $admittedPath = $directory . '/admitted';
        $repository = dirname(__DIR__, 3);
        $holder = tempnam($directory, 'holder-');
        file_put_contents(
            $holder,
            str_replace(
                ['__LOCK_PATH__', '__READY_PATH__', '__RELEASE_PATH__'],
                [var_export($lockPath, true), var_export($readyPath, true), var_export($releasePath, true)],
                <<<'PHP'
                <?php
                $lock = fopen(__LOCK_PATH__, 'c');
                flock($lock, LOCK_EX);
                touch(__READY_PATH__);
                $deadline = microtime(true) + 10;
                while (!is_file(__RELEASE_PATH__) && microtime(true) < $deadline) {
                    usleep(1000);
                }
                flock($lock, LOCK_UN);
                fclose($lock);
                PHP
                ,
            ),
        );
        $worker = tempnam($directory, 'worker-');
        file_put_contents(
            $worker,
            str_replace(
                ['__APP_PATH__', '__CACHE_PATH__', '__STATUS_PATH__', '__ADMITTED_PATH__'],
                [
                    var_export($repository . '/application/', true),
                    var_export($cachePath, true),
                    var_export($statusPath, true),
                    var_export($admittedPath, true),
                ],
                <<<'PHP'
                <?php
                define('BASEPATH', __DIR__ . '/');
                define('APPPATH', __APP_PATH__);
                $_SERVER['HTTP_HOST'] = 'example.test';
                function is_cli(): bool { return false; }
                register_shutdown_function(static function (): void {
                    file_put_contents(__STATUS_PATH__, (string) http_response_code());
                });
                final class RateLimitTimeoutCache
                {
                    public function get_loaded_driver(): string { return 'file'; }
                    public function get(string $key): mixed { return false; }
                    public function save(string $key, mixed $value, int $ttl): bool { return true; }
                }
                final class RateLimitTimeoutConfig
                {
                    public function item(string $key): mixed { return $key === 'rate_limiting' ? true : __CACHE_PATH__ . '/'; }
                }
                final class RateLimitTimeoutLoader
                {
                    public function driver(string $name, array $options): void {}
                }
                $GLOBALS['rateLimitCi'] = (object) ['config' => new RateLimitTimeoutConfig(), 'load' => new RateLimitTimeoutLoader(), 'cache' => new RateLimitTimeoutCache()];
                function &get_instance(): object { return $GLOBALS['rateLimitCi']; }
                require_once APPPATH . 'helpers/rate_limit_helper.php';
                rate_limit('203.0.113.10', 2, 3600);
                touch(__ADMITTED_PATH__);
                PHP
                ,
            ),
        );

        try {
            $holderProcess = proc_open([PHP_BINARY, $holder], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $holderPipes);
            $this->assertIsResource($holderProcess);
            fclose($holderPipes[1]);
            fclose($holderPipes[2]);
            $deadline = microtime(true) + 5;
            while (!is_file($readyPath) && microtime(true) < $deadline) {
                usleep(1000);
            }
            $this->assertFileExists($readyPath);
            $workerProcess = proc_open([PHP_BINARY, $worker], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $workerPipes);
            $this->assertIsResource($workerProcess);
            fclose($workerPipes[1]);
            fclose($workerPipes[2]);
            $this->assertSame(0, proc_close($workerProcess));
            $this->assertSame('503', trim((string) file_get_contents($statusPath)));
            $this->assertFileDoesNotExist($admittedPath);
            touch($releasePath);
            $this->assertSame(0, proc_close($holderProcess));
        } finally {
            touch($releasePath);
            if (isset($holderProcess) && is_resource($holderProcess)) {
                proc_terminate($holderProcess);
                proc_close($holderProcess);
            }
            foreach (glob($cachePath . '/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($cachePath);
            foreach (glob($directory . '/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    #[DataProvider('saveFailureModes')]
    public function testCacheSaveFailureFailsClosed(string $mode): void
    {
        $result = $this->runFailClosedWorker($mode);
        $this->assertSame('503', $result['status']);
        $this->assertFalse($result['admitted']);
        $this->assertSame(
            $mode === 'save-count'
                ? ['rate_limit_key_203.0.113.10']
                : ($mode === 'save-remain'
                    ? ['rate_limit_key_203.0.113.10', 'rate_limit_tmp_203.0.113.10']
                    : ['rate_limit_key_203.0.113.10']),
            $result['calls'],
        );
    }

    /** @return iterable<string, array{string}> */
    public static function saveFailureModes(): iterable
    {
        yield 'count save' => ['save-count'];
        yield 'remain save' => ['save-remain'];
        yield 'increment save' => ['save-increment'];
        yield 'reset save' => ['save-reset'];
    }

    #[DataProvider('invalidRemainModes')]
    public function testMissingOrInvalidRemainMetadataFailsClosed(string $mode): void
    {
        $result = $this->runFailClosedWorker($mode);
        $this->assertSame('503', $result['status']);
        $this->assertFalse($result['admitted']);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidRemainModes(): iterable
    {
        yield 'missing remain' => ['missing-remain'];
        yield 'invalid remain' => ['invalid-remain'];
    }

    public function testLoopbackBypassOnlyAppliesToExplicitLocalHosts(): void
    {
        $this->assertTrue(rate_limit_is_local_loopback_request('127.0.0.1', 'localhost'));
        $this->assertTrue(rate_limit_is_local_loopback_request('127.0.0.1', 'localhost:8080'));
        $this->assertTrue(rate_limit_is_local_loopback_request('127.0.0.1', '127.0.0.1:3000'));
        $this->assertTrue(rate_limit_is_local_loopback_request('::1', '::1'));
        $this->assertTrue(rate_limit_is_local_loopback_request('::1', '[::1]'));
        $this->assertTrue(rate_limit_is_local_loopback_request('::1', '[::1]:8080'));
        $this->assertFalse(rate_limit_is_local_loopback_request('127.0.0.1', 'dasforscherhaus-leg.de'));
        $this->assertFalse(rate_limit_is_local_loopback_request('203.0.113.10', 'localhost'));
    }

    /** @return iterable<string, array{array<int, string>}> */
    public static function concurrentAddresses(): iterable
    {
        yield 'IPv4' => [['203.0.113.10', '203.0.113.10', '203.0.113.10']];
        yield 'colliding IPv6 cache keys' => [['1:23::4', '12:3::4', '1:23::4']];
    }

    #[DataProvider('concurrentAddresses')]
    public function testConcurrentNonLoopbackRequestsDoNotLoseIncrements(array $ips): void
    {
        $repository = dirname(__DIR__, 3);
        $directory = sys_get_temp_dir() . '/rate-limit-race-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);

        $cachePath = $directory . '/cache';
        mkdir($cachePath, 0700, true);
        $ip = $ips[0];
        $cacheKey = str_replace(':', '', 'rate_limit_key_' . $ip);
        $remainKey = str_replace(':', '', 'rate_limit_tmp_' . $ip);
        file_put_contents($cachePath . '/' . $cacheKey, '1');
        file_put_contents($cachePath . '/' . $remainKey, '2099-01-01 00:00:00');

        $worker = tempnam($directory, 'worker-');
        file_put_contents(
            $worker,
            str_replace(
                ['__APP_PATH__', '__BARRIER_PATH__', '__CACHE_PATH__', '__IPS__', '__CACHE_KEY__'],
                [
                    var_export($repository . '/application/', true),
                    var_export($directory, true),
                    var_export($cachePath, true),
                    var_export($ips, true),
                    var_export($cacheKey, true),
                ],
                <<<'PHP'
                <?php
                define('BASEPATH', __DIR__ . '/');
                define('APPPATH', __APP_PATH__);
                $_SERVER['HTTP_HOST'] = 'example.test';

                function is_cli(): bool
                {
                    return false;
                }

                final class RateLimitRaceCache
                {
                    public function get_loaded_driver(): string { return 'file'; }

                    private int $keyReads = 0;

                    public function get(string $key): mixed
                    {
                        $path = __CACHE_PATH__ . '/' . $key;
                        $value = is_file($path) ? file_get_contents($path) : false;

                        if ($key === __CACHE_KEY__) {
                            $this->keyReads++;
                            $phase = $this->keyReads === 1 ? 'initial' : 'requests';
                            $marker = __BARRIER_PATH__ . '/' . $phase . '-' . getmypid();
                            touch($marker);
                            $deadline = microtime(true) + 0.25;
                            do {
                                $markers = glob(__BARRIER_PATH__ . '/' . $phase . '-*') ?: [];
                                if (count($markers) >= 3) {
                                    break;
                                }
                                usleep(1000);
                            } while (microtime(true) < $deadline);
                        }

                        return $key === __CACHE_KEY__ && $value !== false ? (int) $value : $value;
                    }

                    public function save(string $key, mixed $value, int $ttl): bool
                    {
                        $path = __CACHE_PATH__ . '/' . $key;
                        $temporaryPath = $path . '.' . getmypid() . '.tmp';
                        file_put_contents($temporaryPath, (string) $value);
                        rename($temporaryPath, $path);
                        return true;
                    }
                }

                final class RateLimitRaceConfig
                {
                    public function item(string $key): mixed
                    {
                        return $key === 'rate_limiting' ? true : __CACHE_PATH__ . '/';
                    }
                }

                final class RateLimitRaceLoader
                {
                    public function driver(string $name, array $options): void
                    {
                    }
                }

                $GLOBALS['rateLimitCi'] = (object) [
                    'config' => new RateLimitRaceConfig(),
                    'load' => new RateLimitRaceLoader(),
                    'cache' => new RateLimitRaceCache(),
                ];

                function &get_instance(): object
                {
                    return $GLOBALS['rateLimitCi'];
                }

                require_once APPPATH . 'helpers/rate_limit_helper.php';
                $marker = __BARRIER_PATH__ . '/ready-' . getmypid();
                touch($marker);
                $deadline = microtime(true) + 5;
                do {
                    $markers = glob(__BARRIER_PATH__ . '/ready-*') ?: [];
                    if (count($markers) >= 3) {
                        break;
                    }
                    usleep(1000);
                } while (microtime(true) < $deadline);
                $workerIps = __IPS__;
                rate_limit($workerIps[((int) ($argv[1] ?? 1) - 1) % count($workerIps)], 2, 3600);
                touch(__BARRIER_PATH__ . '/completed-' . getmypid());
                PHP
                ,
            ),
        );

        $processes = [];
        try {
            for ($workerId = 1; $workerId <= 3; $workerId++) {
                $processes[] = proc_open(
                    [PHP_BINARY, $worker, (string) $workerId],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                );
                $this->assertIsResource($processes[array_key_last($processes)]);
                fclose($pipes[1]);
                fclose($pipes[2]);
            }

            foreach ($processes as $process) {
                $this->assertSame(0, proc_close($process));
            }

            $completedWorkers = count(glob($directory . '/completed-*') ?: []);
            $finalCount = (int) file_get_contents($cachePath . '/' . $cacheKey);

            $this->assertSame(
                1,
                $completedWorkers,
                'Exactly one request should be admitted at max_requests=2 from an initial count of 1.',
            );
            $this->assertSame(4, $finalCount, 'Rejected attempts are counted while the lock prevents lost increments.');
        } finally {
            foreach (glob($cachePath . '/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($cachePath);
            foreach (glob($directory . '/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
