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
        $this->assertStringContainsString('--runtime-config-permissions harden|verify', $result['stdout']);
        $this->assertStringContainsString('--runtime-config-rollback --active PATH', $result['stdout']);
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
                "find '{$expectedStageRoot}' -type d -exec chmod 755 {} +",
                $result['stdout'],
            );
            $this->assertStringContainsString(
                "find '{$expectedStageRoot}' -type f -exec chmod 644 {} +",
                $result['stdout'],
            );
            $this->assertStringNotContainsString(
                "find '{$expectedStageRoot}' -type f -exec chmod 644 {} \\;",
                $result['stdout'],
            );
            $this->assertStringContainsString(
                "would generate zero-surprise stage config from '{$expectedStageRoot}/config-sample.php' -> '{$expectedStageRoot}/config.php'",
                $result['stdout'],
            );
            $this->assertStringContainsString(
                "restore executable bits for '{$expectedStageRoot}/scripts/ops' shell scripts when present",
                $result['stdout'],
            );

            $genericPermissionPass = "find '{$expectedStageRoot}' -type f -exec chmod 644 {} +";
            $stageHarden = "bash '{$repoRoot}/deploy_ea.sh' --runtime-config-permissions 'harden' --app-root '{$expectedStageRoot}' --runtime-user 'www-data'";
            $liveHarden = "bash '{$repoRoot}/deploy_ea.sh' --runtime-config-permissions 'harden' --app-root '{$appPath}' --runtime-user 'www-data'";
            $atomicMove = "mv '{$appPath}' '{$appPath}_prev_ea_20260320_1200'";
            $activeVerify = "bash '{$repoRoot}/deploy_ea.sh' --runtime-config-permissions 'verify' --app-root '{$appPath}' --runtime-user 'www-data'";
            $previousVerify = "bash '{$repoRoot}/deploy_ea.sh' --runtime-config-permissions 'verify' --app-root '{$appPath}_prev_ea_20260320_1200' --runtime-user 'www-data'";

            $firstGenericPassPosition = strpos($result['stdout'], $genericPermissionPass);
            $this->assertIsInt($firstGenericPassPosition);
            $firstStageHardenPosition = strpos($result['stdout'], $stageHarden, $firstGenericPassPosition + 1);
            $this->assertIsInt($firstStageHardenPosition);
            $secondGenericPassPosition = strpos(
                $result['stdout'],
                $genericPermissionPass,
                $firstStageHardenPosition + 1,
            );
            $this->assertIsInt($secondGenericPassPosition);
            $secondStageHardenPosition = strpos($result['stdout'], $stageHarden, $secondGenericPassPosition + 1);
            $this->assertIsInt($secondStageHardenPosition);
            $liveHardenPosition = strpos($result['stdout'], $liveHarden, $secondStageHardenPosition + 1);
            $this->assertIsInt($liveHardenPosition);
            $atomicMovePosition = strpos($result['stdout'], $atomicMove, $liveHardenPosition + 1);
            $this->assertIsInt($atomicMovePosition);
            $activeVerifyPosition = strpos($result['stdout'], $activeVerify, $atomicMovePosition + 1);
            $this->assertIsInt($activeVerifyPosition);
            $previousVerifyPosition = strpos($result['stdout'], $previousVerify, $activeVerifyPosition + 1);
            $this->assertIsInt($previousVerifyPosition);

            $this->assertLessThan($firstStageHardenPosition, $firstGenericPassPosition);
            $this->assertLessThan($secondStageHardenPosition, $secondGenericPassPosition);
            $this->assertLessThan($liveHardenPosition, $secondStageHardenPosition);
            $this->assertLessThan($atomicMovePosition, $liveHardenPosition);
            $this->assertLessThan($activeVerifyPosition, $atomicMovePosition);
            $this->assertLessThan($previousVerifyPosition, $activeVerifyPosition);

            $manualFailedPath = $appPath . '_failed_ea_20260320_1200';
            $this->assertStringContainsString(
                "bash '{$repoRoot}/deploy_ea.sh' --runtime-config-rollback --active '{$appPath}' --previous '{$appPath}_prev_ea_20260320_1200' --failed '{$manualFailedPath}' --runtime-user 'www-data'",
                $result['stdout'],
            );

            $timingEvents = $this->deployTimingEvents($result['stdout']);
            $phaseEvents = array_values(
                array_filter(
                    $timingEvents,
                    static fn(array $event): bool => $event['event'] === 'phase' && $event['mode'] === 'deploy',
                ),
            );
            $this->assertSame(
                ['preparation_artifact', 'predeploy', 'permissions_stage', 'switch', 'postdeploy_validation'],
                array_column($phaseEvents, 'phase'),
            );
            $this->assertSame(['ok', 'ok', 'ok', 'ok', 'ok'], array_column($phaseEvents, 'status'));
            $this->assertSame([true, true, true, true, true], array_column($phaseEvents, 'dry_run'));

            $elapsed = array_column($phaseEvents, 'elapsed_ms');
            $sortedElapsed = $elapsed;
            sort($sortedElapsed);
            $this->assertSame($sortedElapsed, $elapsed);

            $summaryEvents = array_values(
                array_filter(
                    $timingEvents,
                    static fn(array $event): bool => $event['event'] === 'summary' && $event['mode'] === 'deploy',
                ),
            );
            $this->assertCount(1, $summaryEvents);
            $this->assertSame('succeeded', $summaryEvents[0]['outcome']);
            $this->assertSame(0, $summaryEvents[0]['exit_code']);
            $this->assertTrue($summaryEvents[0]['dry_run']);

            foreach ($this->deployTimingLines($result['stdout']) as $timingLine) {
                $this->assertStringNotContainsString($workspace, $timingLine);
                $this->assertStringNotContainsString('ea_20260320_1200', $timingLine);
                $this->assertStringNotContainsString('dump.sql.gz', $timingLine);
                $this->assertStringNotContainsString('predeploy.ini', $timingLine);
                $this->assertStringNotContainsString('canary.ini', $timingLine);
            }
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testDeployTimingMarksPreSwitchFailureWithoutLeakingContext(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $script = <<<'BASH'
        source "$1"
        SENSITIVE_FIXTURE_PATH="/fixtures/customer-alpha/config.php"
        deploy_timing_init deploy 0 preparation_artifact
        deploy_timing_transition predeploy
        exit 23
        BASH;

        $result = $this->runCommand(['bash', '-c', $script, 'bash', $repoRoot . '/deploy_ea.sh']);

        $this->assertSame(23, $result['exit_code']);
        $events = $this->deployTimingEvents($result['stdout']);
        $this->assertSame('preparation_artifact', $events[0]['phase']);
        $this->assertSame('ok', $events[0]['status']);
        $this->assertSame('predeploy', $events[1]['phase']);
        $this->assertSame('failed', $events[1]['status']);
        $this->assertSame('failed_pre_switch', $events[2]['outcome']);
        $this->assertSame(23, $events[2]['exit_code']);

        foreach ($this->deployTimingLines($result['stdout']) as $timingLine) {
            $this->assertStringNotContainsString('customer-alpha', $timingLine);
            $this->assertStringNotContainsString('config.php', $timingLine);
        }
    }

    public function testDeployTimingWriteFailureBeforeSwitchDoesNotStopGateExecution(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $script = <<<'BASH'
        set -Eeuo pipefail
        source "$1"
        SENSITIVE_FIXTURE_PATH="/fixtures/customer-alpha/config.php"
        deploy_timing_init deploy 0 preparation_artifact
        TIMING_WRITE_FAILURES=1
        printf() {
          if [[ "${1:-}" == DEPLOY_TIMING\ * && "$TIMING_WRITE_FAILURES" -gt 0 ]]; then
            TIMING_WRITE_FAILURES="$((TIMING_WRITE_FAILURES - 1))"
            return 74
          fi
          builtin printf "$@"
        }
        deploy_timing_transition predeploy
        builtin printf 'PRE_SWITCH_GATE_REACHED\n'
        deploy_timing_transition permissions_stage
        deploy_timing_finish ok succeeded 0
        BASH;

        $result = $this->runCommand(['bash', '-c', $script, 'bash', $repoRoot . '/deploy_ea.sh']);

        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $this->assertStringContainsString('PRE_SWITCH_GATE_REACHED', $result['stdout']);
        $events = $this->deployTimingEvents($result['stdout']);
        $this->assertSame('predeploy', $events[0]['phase']);
        $this->assertSame('permissions_stage', $events[1]['phase']);
        $this->assertSame('succeeded', $events[2]['outcome']);

        foreach ($this->deployTimingLines($result['stdout']) as $timingLine) {
            $this->assertStringNotContainsString('customer-alpha', $timingLine);
            $this->assertStringNotContainsString('config.php', $timingLine);
        }
    }

    public function testDeployTimingMarksSecondAtomicMoveFailureAsRecoveryRequired(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $workspace = sys_get_temp_dir() . '/deploy-ea-switch-customer-alpha-' . bin2hex(random_bytes(4));
        $appPath = $workspace . '/app';
        $previousPath = $workspace . '/previous';
        $missingStagePath = $workspace . '/missing-stage';
        mkdir($appPath, 0777, true);
        file_put_contents($appPath . '/SENSITIVE_CUSTOMER_MARKER', 'fixture');

        $script = <<<'BASH'
        source "$1"
        APP="$2"
        PREV="$3"
        STAGE_ROOT="$4"
        DRYRUN=0
        deploy_timing_init deploy 0 preparation_artifact
        deploy_timing_transition predeploy
        deploy_timing_transition permissions_stage
        deploy_timing_transition switch
        perform_atomic_switch
        exit 99
        BASH;

        try {
            $result = $this->runCommand([
                'bash',
                '-c',
                $script,
                'bash',
                $repoRoot . '/deploy_ea.sh',
                $appPath,
                $previousPath,
                $missingStagePath,
            ]);

            $this->assertNotSame(0, $result['exit_code']);
            $this->assertDirectoryDoesNotExist($appPath);
            $this->assertDirectoryExists($previousPath);
            $this->assertFileExists($previousPath . '/SENSITIVE_CUSTOMER_MARKER');

            $events = $this->deployTimingEvents($result['stdout']);
            $phaseEvents = array_values(
                array_filter($events, static fn(array $event): bool => $event['event'] === 'phase'),
            );
            $this->assertSame(
                ['preparation_artifact', 'predeploy', 'permissions_stage', 'switch'],
                array_column($phaseEvents, 'phase'),
            );
            $this->assertSame(['ok', 'ok', 'ok', 'failed'], array_column($phaseEvents, 'status'));
            $this->assertSame('failed_switch_recovery_required', $events[array_key_last($events)]['outcome']);
            $this->assertSame($result['exit_code'], $events[array_key_last($events)]['exit_code']);

            foreach ($this->deployTimingLines($result['stdout']) as $timingLine) {
                $this->assertStringNotContainsString($workspace, $timingLine);
                $this->assertStringNotContainsString('customer-alpha', $timingLine);
                $this->assertStringNotContainsString('SENSITIVE_CUSTOMER_MARKER', $timingLine);
            }
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testDeployTopLevelInvokesProductionPostSwitchConfigVerificationAfterAtomicSwitch(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/deploy_ea.sh');
        $this->assertIsString($source);

        $atomicSwitch = strrpos($source, "\nperform_atomic_switch\n");
        $this->assertIsInt($atomicSwitch);
        $postSwitchVerification = strpos($source, "\nverify_post_switch_runtime_config_contracts\n", $atomicSwitch + 1);
        $this->assertIsInt($postSwitchVerification);
        $rendererRestart = strpos($source, "\nif ! restart_renderer_service; then", $postSwitchVerification + 1);
        $this->assertIsInt($rendererRestart);

        $this->assertLessThan($postSwitchVerification, $atomicSwitch);
        $this->assertLessThan($rendererRestart, $postSwitchVerification);
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

    /**
     * @return list<string>
     */
    private function deployTimingLines(string $output): array
    {
        return array_values(
            array_filter(
                preg_split('/\R/', $output) ?: [],
                static fn(string $line): bool => str_starts_with($line, 'DEPLOY_TIMING '),
            ),
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function deployTimingEvents(string $output): array
    {
        $events = [];
        foreach ($this->deployTimingLines($output) as $line) {
            $payload = json_decode(substr($line, strlen('DEPLOY_TIMING ')), true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($payload);
            $this->assertSame('deploy_timing.v1', $payload['schema'] ?? null);
            $this->assertContains($payload['event'] ?? null, ['phase', 'summary']);

            if (($payload['event'] ?? null) === 'phase') {
                $this->assertSame(
                    ['schema', 'event', 'mode', 'phase', 'status', 'duration_ms', 'elapsed_ms', 'dry_run'],
                    array_keys($payload),
                );
                $this->assertIsInt($payload['duration_ms']);
                $this->assertGreaterThanOrEqual(0, $payload['duration_ms']);
                $this->assertIsInt($payload['elapsed_ms']);
                $this->assertGreaterThanOrEqual(0, $payload['elapsed_ms']);
            } else {
                $this->assertSame(
                    ['schema', 'event', 'mode', 'outcome', 'exit_code', 'total_ms', 'dry_run'],
                    array_keys($payload),
                );
                $this->assertIsInt($payload['exit_code']);
                $this->assertIsInt($payload['total_ms']);
                $this->assertGreaterThanOrEqual(0, $payload['total_ms']);
            }

            $events[] = $payload;
        }

        $this->assertNotSame([], $events);

        return $events;
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
