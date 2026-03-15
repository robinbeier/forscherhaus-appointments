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

    public function testDeployDryRunNormalizesRelativeZeroSurprisePathsBeforeStageReplay(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $workspace = sys_get_temp_dir() . '/deploy-ea-dryrun-' . bin2hex(random_bytes(4));
        $appPath = $workspace . '/app';
        $srcPath = $workspace . '/src';
        $archiveRoot = $workspace . '/archive-root';
        $cwd = $workspace . '/cwd';
        $archivePath = $srcPath . '/ea_20260320_1200.tar.gz';

        mkdir($appPath, 0777, true);
        mkdir($srcPath, 0777, true);
        mkdir($archiveRoot . '/application/config', 0777, true);
        mkdir($cwd, 0777, true);

        file_put_contents($appPath . '/config.php', "<?php\n");
        file_put_contents($archiveRoot . '/application/config/config.php', "<?php\n");

        try {
            $tarResult = $this->runCommand(['tar', '-czf', $archivePath, '-C', $archiveRoot, '.']);
            $this->assertSame(0, $tarResult['exit_code'], $tarResult['stderr']);
            $resolvedCwd = realpath($cwd);
            $this->assertIsString($resolvedCwd);

            $result = $this->runCommand(
                [
                    'bash',
                    $repoRoot . '/deploy_ea.sh',
                    '--dry-run',
                    '--rel',
                    'ea_20260320_1200',
                    '--app',
                    $appPath,
                    '--src',
                    $srcPath,
                    '--zero-surprise-dump-file',
                    'fixtures/dump.sql.gz',
                    '--zero-surprise-predeploy-credentials-file',
                    'fixtures/predeploy.ini',
                    '--zero-surprise-canary-credentials-file',
                    'fixtures/canary.ini',
                    '--zero-surprise-report',
                    'reports/predeploy.json',
                ],
                $cwd,
            );

            $this->assertSame(0, $result['exit_code'], $result['stderr']);
            $this->assertStringContainsString("dump '{$resolvedCwd}/fixtures/dump.sql.gz'", $result['stdout']);
            $this->assertStringContainsString("credentials '{$resolvedCwd}/fixtures/predeploy.ini'", $result['stdout']);
            $this->assertStringContainsString("report '{$resolvedCwd}/reports/predeploy.json'", $result['stdout']);
            $expectedStageRoot = $appPath . '_ea_20260320_1200_stage';
            $this->assertStringContainsString(
                "would generate zero-surprise stage config from '{$expectedStageRoot}/config-sample.php' -> '{$expectedStageRoot}/config.php'",
                $result['stdout'],
            );
            $this->assertStringContainsString(
                "restore executable bits for '{$expectedStageRoot}/scripts/ops' shell scripts when present",
                $result['stdout'],
            );
        } finally {
            $this->removeDirectory($workspace);
        }
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
    private function runCommand(array $command, ?string $cwd = null): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $cwd ?? dirname(__DIR__, 3));
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

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($path);
    }
}
