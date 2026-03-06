<?php

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateCliSupport;

require_once __DIR__ . '/../../../scripts/release-gate/lib/GateCliSupport.php';

class GateCliSupportTest extends TestCase
{
    public function testZeroSurpriseReplayHelpIncludesProfileAndCredentialsOptions(): void
    {
        $result = $this->runCommand(['php', 'scripts/release-gate/zero_surprise_replay.php', '--help']);

        $this->assertSame(0, $result['exit_code']);
        $this->assertStringContainsString('--profile=NAME', $result['stdout']);
        $this->assertStringContainsString('--credentials-file=PATH', $result['stdout']);
    }

    public function testZeroSurpriseLiveCanaryHelpIncludesProfileOption(): void
    {
        $result = $this->runCommand(['php', 'scripts/release-gate/zero_surprise_live_canary.php', '--help']);

        $this->assertSame(0, $result['exit_code']);
        $this->assertStringContainsString('--profile', $result['stdout']);
        $this->assertStringContainsString('--credentials-file', $result['stdout']);
    }

    public function testZeroSurpriseIncidentNotifyHelpIncludesWebhookAndEventOptions(): void
    {
        $result = $this->runCommand(['php', 'scripts/release-gate/zero_surprise_incident_notify.php', '--help']);

        $this->assertSame(0, $result['exit_code']);
        $this->assertStringContainsString('--webhook-file=PATH', $result['stdout']);
        $this->assertStringContainsString('--event=VALUE', $result['stdout']);
        $this->assertStringContainsString('--severity=VALUE', $result['stdout']);
    }

    public function testDeployHelpIncludesPhaseFourZeroSurpriseFlags(): void
    {
        $result = $this->runCommand(['bash', 'deploy_ea.sh', '--help']);

        $this->assertSame(0, $result['exit_code']);
        $this->assertStringContainsString('--zero-surprise-dump-file PATH', $result['stdout']);
        $this->assertStringContainsString('--zero-surprise-breakglass-file PATH', $result['stdout']);
        $this->assertStringContainsString('--zero-surprise-incident-webhook-file PATH', $result['stdout']);
    }

    public function testClassifyAssertionExitCodeReturnsRuntimeForPreflightChecks(): void
    {
        $checks = [
            [
                'name' => 'auth_login_validate',
                'status' => 'fail',
                'error' => 'Credentials rejected',
            ],
        ];

        $actual = GateCliSupport::classifyAssertionExitCode($checks);

        $this->assertSame(GateCliSupport::EXIT_RUNTIME_ERROR, $actual);
    }

    public function testClassifyAssertionExitCodeReturnsRuntimeForHttpStatusMismatch(): void
    {
        $checks = [
            [
                'name' => 'dashboard_metrics',
                'status' => 'fail',
                'error' => 'GET metrics expected HTTP 200, got 500.',
            ],
        ];

        $actual = GateCliSupport::classifyAssertionExitCode($checks);

        $this->assertSame(GateCliSupport::EXIT_RUNTIME_ERROR, $actual);
    }

    public function testClassifyAssertionExitCodeReturnsAssertionForNonRuntimeChecks(): void
    {
        $checks = [
            [
                'name' => 'dashboard_heatmap',
                'status' => 'fail',
                'error' => 'weekday must be in range 1..5',
            ],
        ];

        $actual = GateCliSupport::classifyAssertionExitCode($checks);

        $this->assertSame(GateCliSupport::EXIT_ASSERTION_FAILURE, $actual);
    }

    public function testClassifyAssertionExitCodeReturnsAssertionForMalformedInput(): void
    {
        $actual = GateCliSupport::classifyAssertionExitCode([]);

        $this->assertSame(GateCliSupport::EXIT_ASSERTION_FAILURE, $actual);
    }

    public function testResolveCsrfNamesFromConfigReturnsDefaultsForMissingFile(): void
    {
        $actual = GateCliSupport::resolveCsrfNamesFromConfig('/tmp/does-not-exist-' . uniqid('', true) . '.php');

        $this->assertSame(
            [
                'csrf_token_name' => 'csrf_token',
                'csrf_cookie_name' => 'csrf_cookie',
            ],
            $actual,
        );
    }

    public function testResolveCsrfNamesFromConfigAppliesCookiePrefix(): void
    {
        $configPath = tempnam(sys_get_temp_dir(), 'gate-config-');
        $this->assertIsString($configPath);

        try {
            $configContent = <<<'PHP'
            <?php
            $config['cookie_prefix'] = 'fh_';
            $config['csrf_token_name'] = 'my_csrf_token';
            $config['csrf_cookie_name'] = 'my_csrf_cookie';
            PHP;

            file_put_contents($configPath, $configContent);

            $actual = GateCliSupport::resolveCsrfNamesFromConfig($configPath);

            $this->assertSame(
                [
                    'csrf_token_name' => 'my_csrf_token',
                    'csrf_cookie_name' => 'fh_my_csrf_cookie',
                ],
                $actual,
            );
        } finally {
            if (is_file($configPath)) {
                unlink($configPath);
            }
        }
    }

    /**
     * @param list<string> $command
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runCommand(array $command): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, dirname(__DIR__, 3));
        $this->assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exit_code' => $exitCode,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }
}
