<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class GateCliHttpBoundaryTest extends TestCase
{
    public function testCliGateEntrypointsReturnEmpty404OverHttpWithoutDependenciesOrSideEffects(): void
    {
        $fixture = sys_get_temp_dir() . '/gate-http-boundary-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($fixture, 0700, true));

        $scripts = [
            'dashboard_release_gate.php' => __DIR__ . '/../../../scripts/release-gate/dashboard_release_gate.php',
            'booking_write_contract_smoke.php' => __DIR__ . '/../../../scripts/ci/booking_write_contract_smoke.php',
        ];

        foreach ($scripts as $name => $source) {
            self::assertTrue(copy($source, $fixture . '/' . $name));
        }

        [$server, $baseUrl] = $this->startServer($fixture);

        try {
            foreach (array_keys($scripts) as $name) {
                [$status, $body] = $this->request($baseUrl . '/' . $name);

                self::assertSame(404, $status, $name . ' body=' . $body);
                self::assertSame('', $body, $name);
            }

            $entries = array_values(array_diff(scandir($fixture) ?: [], ['.', '..']));
            self::assertEqualsCanonicalizing(array_keys($scripts), $entries);
        } finally {
            proc_terminate($server);
            proc_close($server);
            foreach (array_keys($scripts) as $name) {
                @unlink($fixture . '/' . $name);
            }
            @rmdir($fixture);
        }
    }

    /**
     * @return array{0: resource, 1: string}
     */
    private function startServer(string $documentRoot): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);

        $server = proc_open(
            [PHP_BINARY, '-S', $address, '-t', $documentRoot],
            [
                0 => ['pipe', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ],
            $pipes,
            $documentRoot,
        );
        self::assertIsResource($server);
        fclose($pipes[0]);

        $deadline = microtime(true) + 3;
        do {
            $probe = @stream_socket_client('tcp://' . $address, $probeErrorCode, $probeErrorMessage, 0.1);

            if (is_resource($probe)) {
                fclose($probe);

                return [$server, 'http://' . $address];
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        proc_terminate($server);
        proc_close($server);
        self::fail('Synthetic PHP HTTP server did not start.');
    }

    /** @return array{0: int, 1: string} */
    private function request(string $url): array
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 3]]);
        $body = file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';

        preg_match('/\s(\d{3})\s/', $statusLine, $matches);

        return [(int) ($matches[1] ?? 0), is_string($body) ? $body : ''];
    }
}
