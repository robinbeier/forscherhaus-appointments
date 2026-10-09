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

    public function __construct(
        int $expiration = 7200,
        bool $disableSessionCacheLimiter = false,
        bool $recordRequests = false,
        bool $failCalendarWrites = false,
    ) {
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
            $router = '<?php ';
            if ($recordRequests) {
                $router .=
                    '$request_ledger = ' .
                    var_export($this->directory . '/request-ledger.jsonl', true) .
                    '; file_put_contents($request_ledger, json_encode([\'method\' => $_SERVER[\'REQUEST_METHOD\'] ?? null, \'uri\' => $_SERVER[\'REQUEST_URI\'] ?? null], JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX); ';
            }
            if ($failCalendarWrites) {
                $router .=
                    'if (($_SERVER[\'REQUEST_METHOD\'] ?? null) === \'POST\' && str_contains($_SERVER[\'REQUEST_URI\'] ?? \'\', \'/calendar/\')) { http_response_code(502); header(\'Content-Type: application/json\'); echo \'{"success":false}\'; exit; } ';
            }
            $router .=
                '$assign_to_config = ' .
                var_export($config, true) .
                '; require ' .
                var_export($root . '/index.php', true) .
                ';';
            file_put_contents($this->directory . '/router.php', $router);
            $phpArguments = [PHP_BINARY, '-d', 'session.gc_probability=0', '-d', 'sendmail_path=/bin/false'];
            if ($disableSessionCacheLimiter) {
                $phpArguments[] = '-d';
                $phpArguments[] = 'session.cache_limiter=';
            }
            $phpArguments[] = '-S';
            $phpArguments[] = $address;
            $phpArguments[] = $this->directory . '/router.php';
            $this->process = proc_open(
                $phpArguments,
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
            // Preserve the readiness budget while avoiding a 100 ms stall for every server start.
            for ($attempt = 0; $attempt < 250; $attempt++) {
                $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.02);
                if ($connection) {
                    fclose($connection);
                    return;
                }
                if (!proc_get_status($this->process)['running']) {
                    throw new RuntimeException('Isolated HTTP server exited during startup.');
                }
                usleep(20000);
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

    /** @return array<int,array{method:string,uri:string}> */
    public function requestLedger(): array
    {
        $ledger = [];
        foreach (
            file($this->directory . '/request-ledger.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []
            as $line
        ) {
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($entry) || !is_string($entry['method'] ?? null) || !is_string($entry['uri'] ?? null)) {
                throw new RuntimeException('Request ledger entry is invalid.');
            }
            $ledger[] = ['method' => $entry['method'], 'uri' => $entry['uri']];
        }
        return $ledger;
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
        foreach (['router.php', 'server.log', 'request-ledger.jsonl'] as $name) {
            if (is_file($this->directory . '/' . $name)) {
                unlink($this->directory . '/' . $name);
            }
        }
        if (is_dir($this->directory) && !rmdir($this->directory)) {
            throw new RuntimeException('HTTP resource cleanup incomplete.');
        }
    }
}
