<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\DataProvider;
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
            [$report, $result] = $this->runBrowserGate(null, $leaveUnrelatedArtifact);

            self::assertSame(2, $result['exit_code'], $result['stderr']);
            self::assertSame('fail', $report['status']);
            self::assertSame($leaveUnrelatedArtifact ? 'cleanup_failed' : 'runtime_error', $report['failure_code']);
            self::assertSame($leaveUnrelatedArtifact ? 'fail' : 'pass', $report['cleanup']['status']);
            self::assertTrue($report['cleanup']['storage_state_removed']);
            self::assertSame(!$leaveUnrelatedArtifact, $report['cleanup']['temporary_artifacts_removed']);
        }
    }

    /**
     * @param array<string, bool|int> $payload
     */
    #[DataProvider('browserFailureStagesProvider')]
    public function testFunctionalBrowserFailurePreservesOnlyValidatedStageDetails(
        array $payload,
        string $expectedFailedStage,
    ): void {
        [$report, $result] = $this->runBrowserGate($payload, false);

        self::assertSame(1, $result['exit_code'], $result['stderr']);
        self::assertSame('assertion_failed', $report['failure_code']);
        $browserCheck = $this->checkByName($report, 'browser_role_admin');
        self::assertSame('fail', $browserCheck['status']);
        self::assertTrue($browserCheck['details']['assertion_failure']);
        self::assertFalse($browserCheck['details'][$expectedFailedStage]);
        self::assertSame($payload, array_diff_key($browserCheck['details'], ['assertion_failure' => true]));
        self::assertSame('pass', $report['cleanup']['status']);
        self::assertTrue($report['cleanup']['storage_state_removed']);
        self::assertTrue($report['cleanup']['temporary_artifacts_removed']);
    }

    /**
     * @return iterable<string, array{0: array<string, bool|int>, 1: string}>
     */
    public static function browserFailureStagesProvider(): iterable
    {
        $navigationFailure = self::validBrowserPayload();
        $navigationFailure['ok'] = false;
        $navigationFailure['page_loaded'] = false;
        $navigationFailure['initial_search_empty'] = false;
        $navigationFailure['synthetic_search_empty'] = false;
        $navigationFailure['empty_state_visible'] = false;
        $navigationFailure['script_vars_safe'] = false;
        $navigationFailure['dom_safe'] = false;
        $navigationFailure['search_response_count'] = 0;
        $navigationFailure['flow_error_count'] = 1;
        yield 'navigation stage' => [$navigationFailure, 'page_loaded'];

        $syntheticSearchFailure = self::validBrowserPayload();
        $syntheticSearchFailure['ok'] = false;
        $syntheticSearchFailure['synthetic_search_empty'] = false;
        $syntheticSearchFailure['empty_state_visible'] = false;
        $syntheticSearchFailure['search_response_count'] = 1;
        $syntheticSearchFailure['flow_error_count'] = 1;
        yield 'synthetic search stage' => [$syntheticSearchFailure, 'synthetic_search_empty'];
    }

    public function testInvalidBrowserPayloadFailsClosedWithoutExportingUnknownOrRawValues(): void
    {
        $payload = self::validBrowserPayload();
        $payload['ok'] = false;
        $payload['username'] = 'real-person@example.test';
        [$report, $result] = $this->runBrowserGate($payload, false);

        self::assertSame(1, $result['exit_code'], $result['stderr']);
        self::assertSame('assertion_failed', $report['failure_code']);
        $browserCheck = $this->checkByName($report, 'browser_role_admin');
        self::assertSame(['assertion_failure' => true], $browserCheck['details']);
        $encodedReport = json_encode($report, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('username', $encodedReport);
        self::assertStringNotContainsString('real-person', $encodedReport);
        self::assertStringNotContainsString('__CUSTOMERS_UI_SMOKE_GATE__', $encodedReport);
    }

    public function testCleanupFailureStillOverridesFunctionalBrowserFailure(): void
    {
        $payload = self::validBrowserPayload();
        $payload['ok'] = false;
        $payload['flow_error_count'] = 1;
        [$report, $result] = $this->runBrowserGate($payload, true);

        self::assertSame(2, $result['exit_code'], $result['stderr']);
        self::assertSame('cleanup_failed', $report['failure_code']);
        self::assertSame('fail', $report['cleanup']['status']);
        self::assertTrue($report['cleanup']['storage_state_removed']);
        self::assertFalse($report['cleanup']['temporary_artifacts_removed']);
    }

    public function testSuccessReportKeepsExistingAggregatedBrowserDetails(): void
    {
        [$report, $result] = $this->runBrowserGate(self::validBrowserPayload(), false);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame('pass', $report['status']);
        self::assertNull($report['failure_code']);
        self::assertSame(
            [
                'page_loaded' => true,
                'initial_search_empty' => true,
                'synthetic_search_empty' => true,
                'containment_ok' => true,
                'browser_closed' => true,
                'blocked_request_count' => 0,
                'storage_state_removed' => true,
            ],
            $this->checkByName($report, 'browser_role_admin')['details'],
        );
        self::assertSame('pass', $report['cleanup']['status']);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{0: array<string, mixed>, 1: array{exit_code: int, stdout: string, stderr: string}}
     */
    private function runBrowserGate(?array $payload, bool $leaveUnrelatedArtifact): array
    {
        $suffix = bin2hex(random_bytes(4));
        $routerPath = $this->workspace . '/router-' . $suffix . '.php';
        $pwcliPath = $this->workspace . '/pwcli-' . $suffix . '.sh';
        $recordPath = $this->workspace . '/output-dir-' . $suffix . '.txt';
        $reportPath = $this->workspace . '/report-' . $suffix . '.json';
        self::assertNotFalse(file_put_contents($routerPath, $this->routerSource()));
        self::assertNotFalse(
            file_put_contents($pwcliPath, $this->pwcliSource($recordPath, $leaveUnrelatedArtifact, $payload)),
        );
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

        return [$report, $result];
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

    /**
     * @param array<string, mixed>|null $payload
     */
    private function pwcliSource(string $recordPath, bool $leaveUnrelatedArtifact, ?array $payload): string
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
            __RUN_CODE_RESULT__
        fi
        exit 0
        BASH;

        return str_replace(
            ['__RECORD_PATH__', '__LEAVE_ARTIFACT__', '__RUN_CODE_RESULT__'],
            [
                escapeshellarg($recordPath),
                $leaveUnrelatedArtifact ? '1' : '0',
                $payload === null
                    ? 'exit 1'
                    : 'printf \'%s\\n\' ' .
                        escapeshellarg('__CUSTOMERS_UI_SMOKE_GATE__' . json_encode($payload, JSON_THROW_ON_ERROR)),
            ],
            $script,
        );
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function checkByName(array $report, string $name): array
    {
        foreach ($report['checks'] ?? [] as $check) {
            if (is_array($check) && ($check['name'] ?? null) === $name) {
                return $check;
            }
        }

        self::fail('Expected Customers UI smoke check was not found: ' . $name);
    }

    /**
     * @return array<string, bool|int>
     */
    private static function validBrowserPayload(): array
    {
        return [
            'ok' => true,
            'network_policy_installed' => true,
            'page_loaded' => true,
            'initial_search_empty' => true,
            'synthetic_search_empty' => true,
            'empty_state_visible' => true,
            'script_vars_safe' => true,
            'dom_safe' => true,
            'response_bodies_safe' => true,
            'search_response_count' => 2,
            'blocked_request_count' => 0,
            'page_error_count' => 0,
            'console_error_count' => 0,
            'flow_error_count' => 0,
        ];
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
