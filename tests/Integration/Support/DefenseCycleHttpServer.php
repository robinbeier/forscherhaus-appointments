<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

use ReleaseGate\GateHttpClient;
use RuntimeException;

require_once dirname(__DIR__, 3) . '/scripts/release-gate/lib/GateHttpClient.php';

/** Loopback-only real application lifecycle; no replacement controllers or authentication. */
final class DefenseCycleHttpServer
{
    public readonly string $directory;
    public readonly string $baseUrl;
    private mixed $process = null;

    public function __construct(int $expiration = 7200)
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || !is_file('/.dockerenv')) {
            throw new RuntimeException('Only available in the owned isolated Docker run.');
        }
        $this->directory = sys_get_temp_dir() . '/fh-defense-' . bin2hex(random_bytes(8));
        if (!mkdir($this->directory, 0700) || !mkdir($this->directory . '/sessions', 0700)) {
            throw new RuntimeException('Could not create isolated HTTP resources.');
        }
        try {
            $socket = stream_socket_server('tcp://127.0.0.1:0');
            if (!$socket) {
                throw new RuntimeException('Could not reserve a loopback port.');
            }
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            $this->baseUrl = 'http://' . $address;
            $root = dirname(__DIR__, 3);
            $config = [
                'base_url' => $this->baseUrl,
                'sess_save_path' => $this->directory . '/sessions',
                'sess_expiration' => $expiration,
                'sess_time_to_update' => 300,
            ];
            $router =
                '<?php $assign_to_config = ' .
                var_export($config, true) .
                '; require ' .
                var_export($root . '/index.php', true) .
                ';';
            file_put_contents($this->directory . '/router.php', $router);
            $this->process = proc_open(
                [
                    PHP_BINARY,
                    '-d',
                    'session.gc_probability=0',
                    '-d',
                    'sendmail_path=/bin/false',
                    '-S',
                    $address,
                    $this->directory . '/router.php',
                ],
                [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['file', $this->directory . '/server.log', 'a'],
                    2 => ['file', $this->directory . '/server.log', 'a'],
                ],
                $pipes,
                $root,
                array_merge(getenv(), ['APP_ENV' => 'testing']),
            );
            if (!is_resource($this->process)) {
                throw new RuntimeException('Could not start the isolated HTTP server.');
            }
            for ($attempt = 0; $attempt < 50; $attempt++) {
                $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
                if ($connection) {
                    fclose($connection);
                    return;
                }
                if (!proc_get_status($this->process)['running']) {
                    throw new RuntimeException('Isolated HTTP server exited during startup.');
                }
                usleep(100000);
            }
            throw new RuntimeException('Isolated HTTP server startup timed out.');
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    public function client(): GateHttpClient
    {
        return new GateHttpClient($this->baseUrl);
    }

    public function close(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
        foreach (glob($this->directory . '/sessions/*') ?: [] as $file) {
            if (!is_file($file) || is_link($file) || !unlink($file)) {
                throw new RuntimeException('Could not clean the isolated session directory.');
            }
        }
        if (is_dir($this->directory . '/sessions')) {
            rmdir($this->directory . '/sessions');
        }
        foreach (['router.php', 'server.log'] as $name) {
            if (is_file($this->directory . '/' . $name)) {
                unlink($this->directory . '/' . $name);
            }
        }
        if (is_dir($this->directory) && !rmdir($this->directory)) {
            throw new RuntimeException('HTTP resource cleanup incomplete.');
        }
    }
}
