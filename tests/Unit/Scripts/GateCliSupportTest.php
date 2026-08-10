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
        $this->assertStringContainsString('32  Switch is partial and requires recovery', $result['stdout']);
    }

    public function testElevatedRegressionStepExecutesTheProductionTrafficGateWrapper(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('This contract runs in the existing root-elevated CI regression step.');
        }
        if (!is_readable('/proc/net/tcp') || !is_readable('/proc/net/tcp6')) {
            self::fail('The fixed production active-request signal must be available in the root CI step.');
        }

        $repository = dirname(__DIR__, 3);
        $workspace = sys_get_temp_dir() . '/traffic-gate-root-cli-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($workspace, 0700));
        $monitorSourcesPath = $workspace . '/monitor-sources.json';
        $outputPath = $workspace . '/report.json';
        $timestamp = (new \DateTimeImmutable('-5 minutes'))->format('d/M/Y:H:i:s O');
        self::assertNotFalse(
            file_put_contents(
                $monitorSourcesPath,
                json_encode(
                    [
                        'schema' => 'traffic_gate_monitor_sources.v1',
                        'version' => '2026-08-09.1',
                        'exact_cidrs' => ['198.51.100.23/32'],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );
        self::assertTrue(chmod($monitorSourcesPath, 0600));
        self::assertNotFalse(
            file_put_contents(
                $workspace . '/app-access.log',
                sprintf('127.0.0.1 - - [%s] "GET /health HTTP/1.1" 200 123 "-" "fixture-agent"', $timestamp) . "\n",
            ),
        );

        try {
            $result = $this->runCommand([
                'env',
                'TRAFFIC_GATE_LOG_DIR=' . $workspace,
                'TRAFFIC_GATE_CATALOG_PATH=' . $repository . '/scripts/ops/config/traffic_gate_catalog.v1.json',
                'TRAFFIC_GATE_MONITOR_SOURCES_PATH=' . $monitorSourcesPath,
                'bash',
                'scripts/ops/prod_traffic_gate.sh',
                '--purpose',
                'deploy',
                '--mode',
                'normal',
                '--window-seconds',
                '1',
                '--output-json',
                $outputPath,
            ]);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            $report = json_decode((string) file_get_contents($outputPath), true, 32, JSON_THROW_ON_ERROR);
            self::assertIsArray($report);
            self::assertSame('allow', $report['decision']);
            self::assertSame(0, $report['exit_code']);
            self::assertSame(0600, fileperms($outputPath) & 0777);
        } finally {
            $this->removeDirectory($workspace);
        }
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
        deploy_result_trap_install
        deploy_timing_init deploy 0 preparation_artifact
        deploy_timing_transition predeploy
        exit 23
        BASH;

        $result = $this->runCommand(['bash', '-c', $script, 'bash', $repoRoot . '/deploy_ea.sh']);

        $this->assertSame(30, $result['exit_code']);
        $events = $this->deployTimingEvents($result['stdout']);
        $this->assertSame('preparation_artifact', $events[0]['phase']);
        $this->assertSame('ok', $events[0]['status']);
        $this->assertSame('predeploy', $events[1]['phase']);
        $this->assertSame('failed', $events[1]['status']);
        $this->assertSame('failed_pre_switch', $events[2]['outcome']);
        $this->assertSame(30, $events[2]['exit_code']);

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

    public function testDeployTimingClockFailurePreservesSignalExitStatus(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $script = <<<'BASH'
        set -Eeuo pipefail
        source "$1"
        SENSITIVE_FIXTURE_PATH="/fixtures/customer-alpha/config.php"
        deploy_timing_init deploy 0 preparation_artifact
        deploy_timing_transition switch
        DEPLOY_TIMING_SWITCH_STATE="complete"
        deploy_timing_now_ms() { return 74; }
        trap 'exit 143' TERM
        kill -TERM "$$"
        builtin printf 'SIGNAL_HANDLER_RETURNED\n'
        BASH;

        $result = $this->runCommand(['bash', '-c', $script, 'bash', $repoRoot . '/deploy_ea.sh']);

        $this->assertSame(143, $result['exit_code'], $result['stderr']);
        $this->assertStringNotContainsString('SIGNAL_HANDLER_RETURNED', $result['stdout']);
        $this->assertStringNotContainsString('"exit_code":74', $result['stdout']);
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
        deploy_result_trap_install
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

            $this->assertSame(32, $result['exit_code']);
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

    public function testElevatedRealRsyncFailureStopsBeforeAtomicSwitchAndEmitsSafeReason(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || trim((string) shell_exec('id -u')) !== '0') {
            self::markTestSkipped('Root on Linux is required for the production storage-transfer regression.');
        }
        if (trim((string) shell_exec('command -v rsync')) === '') {
            self::markTestSkipped('rsync is required for the root storage-transfer regression.');
        }
        if (!is_dir('/sys')) {
            self::markTestSkipped(
                'A read-only Linux system filesystem is required to force a real rsync receiver failure.',
            );
        }

        $workspace = '/rob456-rsync-customer-secret-' . bin2hex(random_bytes(6));
        $source = $workspace . '/storage';
        $switchSentinel = $workspace . '/switch-reached';
        self::assertTrue(mkdir($source, 0700, true));
        self::assertNotFalse(file_put_contents($source . '/SENSITIVE_MARKER.txt', 'secret payload'));

        $script = <<<'BASH'
        set -Eeuo pipefail
        source "$1"
        DEPLOY_TIMING_RUN_ID="018f6f52-4c87-4d4e-8b19-6a66e6e1af25"
        DEPLOY_TIMING_START_MS="$(deploy_timing_now_ms)"
        deploy_detail_init 0
        switch_sentinel="$4"
        perform_atomic_switch() { : > "$switch_sentinel"; }
        sync_storage_payload_with_detail "$2" "$3"
        perform_atomic_switch
        BASH;

        try {
            $result = $this->runCommand([
                'bash',
                '-c',
                $script,
                'bash',
                dirname(__DIR__, 3) . '/deploy_ea.sh',
                $source,
                '/sys',
                $switchSentinel,
            ]);

            self::assertNotSame(0, $result['exit_code']);
            self::assertFileDoesNotExist($switchSentinel);
            $events = $this->deployDetailEvents($result['stdout']);
            self::assertCount(3, $events);
            self::assertSame([1, 2, 3], array_column($events, 'sequence'));
            self::assertSame(['source_before', 'target_before'], array_column(array_slice($events, 0, 2), 'boundary'));
            self::assertSame(
                ['storage_fingerprint', 'storage_fingerprint', 'subphase'],
                array_column($events, 'event'),
            );
            self::assertNotContains('target_after', array_column($events, 'boundary'));
            $failure = $events[2];
            self::assertSame('subphase', $failure['event']);
            self::assertSame('storage_transfer', $failure['subphase']);
            self::assertSame('failed', $failure['status']);
            self::assertSame('rsync_failed', $failure['reason_code']);
            self::assertStringNotContainsString($workspace, $result['stdout'] . $result['stderr']);
            self::assertStringNotContainsString('SENSITIVE_MARKER', $result['stdout'] . $result['stderr']);
        } finally {
            $this->removeDirectory($workspace);
        }
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
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $payload['run_id'] ?? '',
            );
            $this->assertIsInt($payload['sequence'] ?? null);
            $this->assertGreaterThan(0, $payload['sequence']);

            if (($payload['event'] ?? null) === 'phase') {
                $this->assertSame(
                    [
                        'schema',
                        'run_id',
                        'sequence',
                        'event',
                        'mode',
                        'phase',
                        'status',
                        'duration_ms',
                        'elapsed_ms',
                        'dry_run',
                    ],
                    array_keys($payload),
                );
                $this->assertIsInt($payload['duration_ms']);
                $this->assertGreaterThanOrEqual(0, $payload['duration_ms']);
                $this->assertIsInt($payload['elapsed_ms']);
                $this->assertGreaterThanOrEqual(0, $payload['elapsed_ms']);
            } else {
                $this->assertSame(
                    ['schema', 'run_id', 'sequence', 'event', 'mode', 'outcome', 'exit_code', 'total_ms', 'dry_run'],
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

    /**
     * @return list<array<string,mixed>>
     */
    private function deployDetailEvents(string $output): array
    {
        $events = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (!str_starts_with($line, 'DEPLOY_DETAIL ')) {
                continue;
            }
            $event = json_decode(substr($line, strlen('DEPLOY_DETAIL ')), true, 64, JSON_THROW_ON_ERROR);
            self::assertIsArray($event);
            $events[] = $event;
        }

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
