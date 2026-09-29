<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class ProviderUiSmokeGateExecutionTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/fh-provider-ui-smoke-test-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->workspace, 0700));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    public function testBrowserProcessFailuresProduceSafeStageClassAndCleanupEvidence(): void
    {
        $cases = [
            'browser_open_exit' => [
                2,
                'runtime_error',
                'browser_session_prepare',
                'browser_open_process_exit',
                'browser_open',
            ],
            'state_load_exit' => [
                2,
                'runtime_error',
                'browser_session_prepare',
                'state_load_process_exit',
                'state_load',
            ],
            'process_exit' => [
                2,
                'runtime_error',
                'provider_dashboard_browser_flow',
                'run_code_process_exit',
                'run_code',
            ],
            'structured_assertion' => [
                1,
                'assertion_failed',
                'provider_dashboard_browser_flow',
                'structured_result_assertion',
                'structured_result',
            ],
        ];

        foreach ($cases as $mode => [$expectedExit, $expectedError, $expectedCheck, $expectedClass, $expectedStage]) {
            [$report, $result] = $this->runGate($mode);

            self::assertSame($expectedExit, $result['exit_code'], $result['stderr']);
            self::assertSame($expectedError, $report['failure']['error_code']);
            self::assertSame($expectedCheck, $report['failure']['check']);
            self::assertSame($expectedClass, $report['failure']['diagnostic_class'] ?? null, json_encode($report));
            self::assertSame($expectedStage, $report['failure']['stage']);

            $failedChecks = array_values(
                array_filter($report['checks'], static fn(array $check): bool => $check['status'] === 'fail'),
            );
            self::assertCount(1, $failedChecks);
            self::assertSame($expectedCheck, $failedChecks[0]['name']);
            self::assertSame($expectedError, $failedChecks[0]['error_code']);
            self::assertSame($expectedClass, $failedChecks[0]['diagnostic_class']);
            self::assertSame($expectedStage, $failedChecks[0]['stage']);

            if ($mode === 'structured_assertion') {
                self::assertFalse($failedChecks[0]['details']['ok']);
                self::assertSame(1, $failedChecks[0]['details']['flow_error_count']);
            }

            self::assertSame('pass', $report['cleanup']['status']);
            self::assertTrue($report['cleanup']['temporary_artifacts_removed']);
            $encoded = json_encode($report, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('secret', $encoded);
            self::assertStringNotContainsString('stdout', $encoded);
            self::assertStringNotContainsString('stderr', $encoded);
            self::assertStringNotContainsString('run-code', $encoded);
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: array{exit_code: int, stdout: string, stderr: string}}
     */
    private function runGate(string $mode): array
    {
        $routerPath = $this->workspace . '/router.php';
        $pwcliPath = $this->workspace . '/pwcli.sh';
        $reportPath = $this->workspace . '/report.json';
        $binPath = $this->workspace . '/bin';
        if (!is_dir($binPath)) {
            self::assertTrue(mkdir($binPath, 0700));
        }
        $bashEnvPath = $this->workspace . '/bash-env.sh';
        self::assertNotFalse(
            file_put_contents(
                $bashEnvPath,
                'export PATH=' . escapeshellarg($binPath . PATH_SEPARATOR . (getenv('PATH') ?: '')) . "\n",
            ),
        );
        foreach (['npx', 'pdfinfo', 'pdftotext'] as $binary) {
            self::assertNotFalse(file_put_contents($binPath . '/' . $binary, "#!/usr/bin/env bash\nexit 0\n"));
            self::assertTrue(chmod($binPath . '/' . $binary, 0700));
        }
        self::assertNotFalse(file_put_contents($routerPath, $this->routerSource()));
        self::assertNotFalse(file_put_contents($pwcliPath, $this->pwcliSource($mode)));
        self::assertTrue(chmod($pwcliPath, 0700));
        [$server, $baseUrl] = $this->startServer($routerPath);

        try {
            $result = $this->runProcess(
                [
                    PHP_BINARY,
                    __DIR__ . '/../../../scripts/release-gate/provider_ui_smoke.php',
                    '--base-url=' . $baseUrl,
                    '--credentials-file=-',
                    '--deployed-view-sha256=' .
                    hash_file('sha256', __DIR__ . '/../../../application/views/exports/provider_preparation_pdf.php'),
                    '--pwcli-path=' . $pwcliPath,
                    '--browser=firefox',
                    '--bootstrap-timeout=5',
                    '--http-timeout=5',
                    '--open-timeout=1',
                    '--output-json=' . $reportPath,
                ],
                "PROVIDER_UI_SMOKE_USERNAME=__ea_provider_ui_smoke_v1\n" .
                    'PROVIDER_UI_SMOKE_PASSWORD=' .
                    str_repeat('a', 64) .
                    "\n",
                $binPath . PATH_SEPARATOR . (getenv('PATH') ?: ''),
                $bashEnvPath,
            );
        } finally {
            proc_terminate($server);
            proc_close($server);
        }

        self::assertFileExists($reportPath);
        $report = json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($report);

        return [$report, $result];
    }

    private function routerSource(): string
    {
        return <<<'PHP'
        <?php
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($path === '/index.php/login' && $method === 'GET') {
            setcookie('csrf_cookie', 'synthetic-csrf', ['path' => '/']);
            echo 'login';
            return;
        }
        if ($path === '/index.php/login/validate' && $method === 'POST') {
            setcookie('session', 'synthetic-session', ['path' => '/']);
            header('Content-Type: application/json');
            echo '{"success":true}';
            return;
        }
        if ($path === '/index.php/customers' && $method === 'GET') {
            http_response_code(403);
            echo 'forbidden';
            return;
        }
        if ($path === '/index.php/dashboard' && $method === 'GET') {
            header('Content-Type: text/html');
            echo '<script>const vars = {};</script><main>dashboard</main>';
            return;
        }
        http_response_code(404);
        echo 'not found';
        PHP;
    }

    private function pwcliSource(string $mode): string
    {
        $payload = json_encode(
            [
                'ok' => false,
                'network_policy_installed' => true,
                'dashboard_loaded' => true,
                'buttons_present' => true,
                'script_vars_safe' => true,
                'primary_metrics_status_ok' => true,
                'primary_row_matches' => true,
                'preparation_downloaded' => true,
                'parent_downloaded' => true,
                'empty_metrics_status_ok' => true,
                'empty_state_visible' => true,
                'empty_preparation_downloaded' => true,
                'restore_metrics_status_ok' => true,
                'primary_row_count' => 0,
                'empty_row_count' => 0,
                'blocked_request_count' => 0,
                'page_error_count' => 0,
                'console_error_count' => 0,
                'flow_error_count' => 1,
            ],
            JSON_THROW_ON_ERROR,
        );
        $action =
            $mode === 'structured_assertion'
                ? 'printf %s ' . escapeshellarg('__PROVIDER_UI_SMOKE_GATE__' . $payload)
                : 'printf %s ' . escapeshellarg('secret browser output') . '; exit 23';
        $failureCommand = match ($mode) {
            'browser_open_exit' => 'open',
            'state_load_exit' => 'state-load',
            default => 'run-code',
        };

        return "#!/usr/bin/env bash\n" .
            "set -eu\n" .
            "command_name=''\n" .
            "for argument in \"\$@\"; do case \"\$argument\" in install-browser|open|state-load|run-code|close) command_name=\"\$argument\" ;; esac; done\n" .
            "if [[ \"\$command_name\" == {$failureCommand} ]]; then {$action}; fi\n" .
            "exit 0\n";
    }

    /** @return array{0: resource, 1: string} */
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
        self::fail('Synthetic HTTP server did not start.');
    }

    /** @param list<string> $command */
    private function runProcess(array $command, string $stdin, string $path, string $bashEnvPath): array
    {
        $environment = $_ENV;
        $environment['PATH'] = $path;
        $environment['BASH_ENV'] = $bashEnvPath;
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
            $environment,
        );
        self::assertIsResource($process);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit_code' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
