<?php

namespace Tests\Unit\Helper;

use PHPUnit\Framework\TestCase;

require_once APPPATH . 'helpers/rate_limit_helper.php';

final class RateLimitHelperTest extends TestCase
{
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

    public function testConcurrentNonLoopbackRequestsDoNotLoseIncrements(): void
    {
        $repository = dirname(__DIR__, 3);
        $directory = sys_get_temp_dir() . '/rate-limit-race-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);

        $cachePath = $directory . '/cache';
        mkdir($cachePath, 0700, true);
        $statePath = $cachePath . '/cache.json';
        $cacheKey = 'rate_limit_key_203.0.113.10';
        $remainKey = 'rate_limit_tmp_203.0.113.10';
        file_put_contents(
            $statePath,
            json_encode(
                [
                    $cacheKey => 1,
                    $remainKey => '2099-01-01 00:00:00',
                ],
                JSON_THROW_ON_ERROR,
            ),
        );

        $worker = tempnam($directory, 'worker-');
        file_put_contents(
            $worker,
            str_replace(
                ['__APP_PATH__', '__STATE_PATH__', '__BARRIER_PATH__', '__CACHE_PATH__'],
                [
                    var_export($repository . '/application/', true),
                    var_export($statePath, true),
                    var_export($directory, true),
                    var_export($cachePath, true),
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
                    private int $keyReads = 0;

                    public function get(string $key): mixed
                    {
                        $state = json_decode(file_get_contents(__STATE_PATH__), true, 512, JSON_THROW_ON_ERROR);

                        if ($key === 'rate_limit_key_203.0.113.10') {
                            $this->keyReads++;
                            $phase = $this->keyReads === 1 ? 'initial' : 'requests';
                            $marker = __BARRIER_PATH__ . '/' . $phase . '-' . getmypid();
                            touch($marker);
                            $deadline = microtime(true) + 0.75;
                            do {
                                $markers = glob(__BARRIER_PATH__ . '/' . $phase . '-*') ?: [];
                                if (count($markers) >= 3) {
                                    break;
                                }
                                usleep(1000);
                            } while (microtime(true) < $deadline);
                        }

                        return $state[$key] ?? false;
                    }

                    public function save(string $key, mixed $value, int $ttl): bool
                    {
                        $state = json_decode(file_get_contents(__STATE_PATH__), true, 512, JSON_THROW_ON_ERROR);
                        $state[$key] = $value;
                        $temporaryPath = __STATE_PATH__ . '.' . getmypid() . '.tmp';
                        file_put_contents($temporaryPath, json_encode($state, JSON_THROW_ON_ERROR));
                        rename($temporaryPath, __STATE_PATH__);
                        return true;
                    }
                }

                final class RateLimitRaceConfig
                {
                    public function item(string $key): mixed
                    {
                        return $key === 'rate_limiting' ? true : __CACHE_PATH__;
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
                rate_limit('203.0.113.10', 2, 3600);
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

            $state = json_decode(file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
            $completedWorkers = count(glob($directory . '/completed-*') ?: []);
            $finalCount = (int) ($state[$cacheKey] ?? 0);

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
