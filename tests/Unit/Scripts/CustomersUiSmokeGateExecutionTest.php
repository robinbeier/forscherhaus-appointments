<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReleaseGate\CustomersUiSmokeContract;

require_once __DIR__ . '/../../../scripts/release-gate/lib/CustomersUiSmokeContract.php';

final class CustomersUiSmokeGateExecutionTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/fh-customers-ui-smoke-test-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->workspace, 0700));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    public function testFailureReportSeparatesStorageStateFromOverallArtifactCleanup(): void
    {
        foreach ([false, true] as $leaveUnrelatedArtifact) {
            $report = $this->runFailingBrowserGate($leaveUnrelatedArtifact);

            self::assertSame('fail', $report['status']);
            self::assertSame($leaveUnrelatedArtifact ? 'cleanup_failed' : 'runtime_error', $report['failure_code']);
            self::assertSame($leaveUnrelatedArtifact ? 'fail' : 'pass', $report['cleanup']['status']);
            self::assertTrue($report['cleanup']['storage_state_removed']);
            self::assertSame(!$leaveUnrelatedArtifact, $report['cleanup']['temporary_artifacts_removed']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runFailingBrowserGate(bool $leaveUnrelatedArtifact): array
    {
        $suffix = $leaveUnrelatedArtifact ? 'cleanup-failure' : 'cleanup-success';
        $routerPath = $this->workspace . '/router-' . $suffix . '.php';
        $pwcliPath = $this->workspace . '/pwcli-' . $suffix . '.sh';
        $recordPath = $this->workspace . '/output-dir-' . $suffix . '.txt';
        $reportPath = $this->workspace . '/report-' . $suffix . '.json';
        self::assertNotFalse(file_put_contents($routerPath, $this->routerSource()));
        self::assertNotFalse(file_put_contents($pwcliPath, $this->pwcliSource($recordPath, $leaveUnrelatedArtifact)));
        self::assertTrue(chmod($pwcliPath, 0700));

        [$server, $baseUrl] = $this->startServer($routerPath);

        try {
            $result = $this->runProcess(
                [
                    PHP_BINARY,
                    __DIR__ . '/../../../scripts/release-gate/customers_ui_smoke.php',
                    '--base-url=' . $baseUrl,
                    '--credentials-file=-',
                    '--pwcli-path=' . $pwcliPath,
                    '--browser=firefox',
                    '--bootstrap-timeout=5',
                    '--http-timeout=5',
                    '--open-timeout=1',
                    '--output-json=' . $reportPath,
                ],
                $this->credentialsIni(),
            );
        } finally {
            proc_terminate($server);
            proc_close($server);
        }

        self::assertSame(2, $result['exit_code'], $result['stderr']);
        self::assertFileExists($reportPath);
        $report = json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($report);

        $gateTempDirectory = is_file($recordPath) ? trim((string) file_get_contents($recordPath)) : '';

        if ($leaveUnrelatedArtifact) {
            self::assertNotSame('', $gateTempDirectory);
            self::assertDirectoryExists($gateTempDirectory . '/unrelated-artifact');
            $this->removeTree($gateTempDirectory);
        } else {
            self::assertTrue($gateTempDirectory === '' || !file_exists($gateTempDirectory));
        }

        return $report;
    }

    /**
     * @return array{0: resource, 1: string}
     */
    private function startServer(string $routerPath): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);

        $server = proc_open(
            [PHP_BINARY, '-S', $address, $routerPath],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $this->workspace . '/server.out', 'a'],
                2 => ['file', $this->workspace . '/server.err', 'a'],
            ],
            $pipes,
            $this->workspace,
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
        self::fail('Synthetic Customers HTTP server did not start.');
    }

    /**
     * @param list<string> $command
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runProcess(array $command, string $stdin): array
    {
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
        );
        self::assertIsResource($process);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit_code' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function credentialsIni(): string
    {
        $lines = ['CUSTOMERS_UI_SMOKE_PASSWORD=' . str_repeat('a', 64)];

        foreach (CustomersUiSmokeContract::USERNAMES_BY_ROLE as $role => $username) {
            $lines[] = 'CUSTOMERS_UI_SMOKE_' . strtoupper($role) . '_USERNAME=' . $username;
        }

        return implode("\n", $lines) . "\n";
    }

    private function pwcliSource(string $recordPath, bool $leaveUnrelatedArtifact): string
    {
        $script = <<<'BASH'
        #!/usr/bin/env bash
        set -eu
        record_path=__RECORD_PATH__
        leave_artifact=__LEAVE_ARTIFACT__
        command_name=''
        for argument in "$@"; do
            case "$argument" in
                install-browser|open|state-load|run-code|close) command_name="$argument" ;;
            esac
        done
        if [[ "$command_name" == 'run-code' ]]; then
            printf '%s' "${PLAYWRIGHT_MCP_OUTPUT_DIR:-}" > "$record_path"
            if [[ "$leave_artifact" == '1' ]]; then
                mkdir -p "${PLAYWRIGHT_MCP_OUTPUT_DIR}/unrelated-artifact"
            fi
            exit 1
        fi
        exit 0
        BASH;

        return str_replace(
            ['__RECORD_PATH__', '__LEAVE_ARTIFACT__'],
            [escapeshellarg($recordPath), $leaveUnrelatedArtifact ? '1' : '0'],
            $script,
        );
    }

    private function routerSource(): string
    {
        return <<<'PHP'
        <?php
        declare(strict_types=1);

        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $roleByUsername = [
            '__ea_customers_ui_smoke_admin_v1' => 'admin',
            '__ea_customers_ui_smoke_provider_v1' => 'provider',
            '__ea_customers_ui_smoke_secretary_v1' => 'secretary',
            '__ea_customers_ui_smoke_customer_v1' => 'customer',
        ];

        if ($path === '/index.php/login' && $method === 'GET') {
            setcookie('csrf_cookie', 'csrf-token', ['path' => '/']);
            echo 'synthetic login';
            return;
        }

        if ($path === '/index.php/login/validate' && $method === 'POST') {
            $role = $roleByUsername[$_POST['username'] ?? ''] ?? '';
            if ($role === '') {
                http_response_code(403);
                echo '{"success":false}';
                return;
            }
            setcookie('role', $role, ['path' => '/']);
            setcookie('session', 'synthetic-' . $role, ['path' => '/', 'httponly' => true]);
            header('Content-Type: application/json');
            echo '{"success":true}';
            return;
        }

        $role = $_COOKIE['role'] ?? '';
        if ($path === '/index.php/customers' && $method === 'GET') {
            if ($role === 'customer' || !in_array($role, ['admin', 'provider', 'secretary'], true)) {
                http_response_code(403);
                echo 'forbidden';
                return;
            }
            header('Content-Type: text/html');
            echo '<!doctype html><script>const vars = {"csrf_token":"csrf-token"};</script><main id="customers">synthetic empty</main>';
            return;
        }

        if ($path === '/index.php/dashboard' && $method === 'GET') {
            http_response_code(403);
            echo 'forbidden';
            return;
        }

        if ($path === '/index.php/customers/search' && $method === 'POST') {
            if ($role === 'customer' || !in_array($role, ['admin', 'provider', 'secretary'], true)) {
                http_response_code(403);
                echo 'forbidden';
                return;
            }
            if (($_POST['keyword'] ?? '') !== '__EA_CUSTOMERS_UI_SMOKE_V1_EMPTY_SEARCH__') {
                http_response_code(403);
                echo 'forbidden';
                return;
            }
            header('Content-Type: application/json');
            echo '[]';
            return;
        }

        http_response_code(404);
        echo 'not found';
        PHP;
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

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
        }

        @rmdir($path);
    }
}
