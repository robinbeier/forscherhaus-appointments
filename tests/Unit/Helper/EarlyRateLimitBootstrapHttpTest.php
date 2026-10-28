<?php

declare(strict_types=1);

namespace Tests\Unit\Helper;

use PHPUnit\Framework\TestCase;

/** Actual CI HTTP bootstrap with a deliberately unreachable synthetic database. */
final class EarlyRateLimitBootstrapHttpTest extends TestCase
{
    public function testExhaustedQuotaRejectsBeforeDatabaseAndSessionAutoloadOnBothRoutes(): void
    {
        $repository = dirname(__DIR__, 3);
        $directory = sys_get_temp_dir() . '/early-bootstrap-' . bin2hex(random_bytes(8));
        mkdir($directory . '/cache', 0700, true);
        mkdir($directory . '/sessions', 0700, true);
        mkdir($directory . '/logs', 0700, true);
        $process = null;
        try {
            foreach (['application', 'system', 'vendor'] as $name) {
                self::assertTrue(symlink($repository . '/' . $name, $directory . '/' . $name));
            }
            copy($repository . '/index.php', $directory . '/index.php');
            $config = file_get_contents($repository . '/config-sample.php');
            $config = str_replace("const DB_HOST = 'mysql';", "const DB_HOST = '127.0.0.1:1';", $config);
            file_put_contents($directory . '/config.php', $config);
            $socket = stream_socket_server('tcp://127.0.0.1:0');
            self::assertIsResource($socket);
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            $override = [
                'base_url' => 'http://' . $address,
                'cache_path' => $directory . '/cache/',
                'sess_save_path' => $directory . '/sessions',
                'log_path' => $directory . '/logs/',
                'rate_limiting' => true,
            ];
            file_put_contents(
                $directory . '/router.php',
                '<?php $assign_to_config = ' . var_export($override, true) . '; require __DIR__ . "/index.php";',
            );
            $process = proc_open(
                [PHP_BINARY, '-d', 'session.gc_probability=0', '-S', $address, $directory . '/router.php'],
                [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['file', $directory . '/server.log', 'a'],
                    2 => ['file', $directory . '/server.log', 'a'],
                ],
                $pipes,
                $directory,
            );
            self::assertIsResource($process);
            $ready = false;
            for ($i = 0; $i < 100; $i++) {
                $connection = @stream_socket_client('tcp://' . $address, $error, $message, 0.05);
                if ($connection !== false) {
                    fclose($connection);
                    $ready = true;
                    break;
                }
                usleep(20000);
            }
            self::assertTrue($ready);
            $countPath = $directory . '/cache/rate_limit_key_127.0.0.1';
            $remainPath = $directory . '/cache/rate_limit_tmp_127.0.0.1';
            file_put_contents($countPath, serialize(['time' => time(), 'ttl' => 120, 'data' => 100]));
            file_put_contents($remainPath, serialize(['time' => time(), 'ttl' => 240, 'data' => time() + 120]));
            foreach (['booking', 'index.php/booking'] as $route) {
                [$status, $headers, $body] = $this->request($address, $route);
                self::assertSame(429, $status, $body . file_get_contents($directory . '/server.log'));
                self::assertMatchesRegularExpression('/Retry-After: [1-9][0-9]*/i', $headers);
                self::assertStringNotContainsString('ea_session=', $headers);
                self::assertSame([], glob($directory . '/sessions/*'));
                self::assertSame('', $body);
            }
            self::assertSame(102, unserialize(file_get_contents($countPath), ['allowed_classes' => false])['data']);
            unlink($countPath);
            unlink($remainPath);
            [$status, , $body] = $this->request($address, 'booking');
            self::assertSame(
                500,
                $status,
                'The admitted control must reach the deliberately unavailable database: ' . $body,
            );
            self::assertSame(1, unserialize(file_get_contents($countPath), ['allowed_classes' => false])['data']);
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            $remove = static function (string $path) use (&$remove): void {
                if (is_dir($path) && !is_link($path)) {
                    foreach (scandir($path) as $name) {
                        if ($name !== '.' && $name !== '..') {
                            $remove($path . '/' . $name);
                        }
                    }
                    rmdir($path);
                } else {
                    unlink($path);
                }
            };
            $remove($directory);
        }
    }

    private function request(string $address, string $route): array
    {
        $curl = curl_init('http://' . $address . '/' . $route);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Host: public-booking.example.invalid'],
        ]);
        $response = curl_exec($curl);
        self::assertIsString($response, curl_error($curl));
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        unset($curl);
        return [$status, substr($response, 0, $headerSize), substr($response, $headerSize)];
    }
}
