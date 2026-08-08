<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class DeployRuntimeConfigRollbackTest extends TestCase
{
    private string $workspace;
    private string $activePath;
    private string $previousPath;
    private string $failedPath;
    private string $trustedDeployScript;
    private string $maliciousSentinel;
    private int $runtimeUserId;
    private int $runtimeGroupId;

    protected function setUp(): void
    {
        parent::setUp();

        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('The production rollback contract targets Linux metadata semantics.');
        }

        $uid = $this->runCommand(['id', '-u']);
        if ($uid['exit_code'] !== 0 || trim($uid['stdout']) !== '0') {
            $this->markTestSkipped('Root is required to exercise rollback ownership semantics.');
        }

        $runtimeUser = $this->runCommand(['id', '-u', 'www-data']);
        $runtimeGroup = $this->runCommand(['id', '-g', 'www-data']);
        if ($runtimeUser['exit_code'] !== 0 || $runtimeGroup['exit_code'] !== 0) {
            $this->markTestSkipped('The www-data runtime user is unavailable.');
        }

        $this->runtimeUserId = (int) trim($runtimeUser['stdout']);
        $this->runtimeGroupId = (int) trim($runtimeGroup['stdout']);
        $this->workspace = '/rob442-runtime-rollback-' . bin2hex(random_bytes(6));
        $this->activePath = $this->workspace . '/app';
        $this->previousPath = $this->workspace . '/app_prev_release';
        $this->failedPath = $this->workspace . '/app_failed_release';
        $this->maliciousSentinel = $this->workspace . '/release-helper-executed';
        $trustedRoot = $this->workspace . '/trusted';
        $this->trustedDeployScript = $trustedRoot . '/deploy_ea.sh';

        mkdir($trustedRoot, 0755, true);
        copy(dirname(__DIR__, 3) . '/deploy_ea.sh', $this->trustedDeployScript);
        chmod($this->trustedDeployScript, 0755);
        $this->createReleaseTree($this->activePath, 'ACTIVE_RELEASE_MARKER');
        $this->createReleaseTree($this->previousPath, 'PREVIOUS_RELEASE_MARKER');
        self::assertSame(0, $this->runPermissionMode('harden', $this->activePath)['exit_code']);
        self::assertSame(0, $this->runPermissionMode('harden', $this->previousPath)['exit_code']);
    }

    protected function tearDown(): void
    {
        if (isset($this->workspace)) {
            $this->removeDirectory($this->workspace);
        }

        parent::tearDown();
    }

    public function testAutomaticPostSwitchPermissionFailureRunsRollbackAndStopsDeployment(): void
    {
        chmod($this->activePath . '/config.php', 0644);
        $releaseId = 'ea_process_test';
        $this->failedPath = $this->activePath . '_failed_' . $releaseId;

        $script = <<<'BASH'
        set -Eeuo pipefail
        source "$1"
        APP="$2"
        PREV="$3"
        REL="$4"
        WEBUSER="www-data"
        DRYRUN=0
        ZERO_SURPRISE_CANARY_REPORT=""
        restart_renderer_service() { return 0; }
        probe_renderer_health() { return 0; }
        reload_services() { return 0; }
        probe_deep_health_contract() { return 0; }
        emit_zero_surprise_incident() { return 0; }

        deploy_timing_init deploy 0 preparation_artifact
        deploy_timing_transition predeploy
        deploy_timing_transition permissions_stage
        deploy_timing_transition switch
        DEPLOY_TIMING_SWITCH_STATE="complete"
        deploy_timing_transition postdeploy_validation
        verify_post_switch_runtime_config_contracts

        exit 99
        BASH;
        $result = $this->runCommand([
            'bash',
            '-c',
            $script,
            'bash',
            $this->trustedDeployScript,
            $this->activePath,
            $this->previousPath,
            $releaseId,
        ]);

        self::assertSame(30, $result['exit_code'], $result['stderr']);
        self::assertDirectoryExists($this->activePath);
        self::assertDirectoryDoesNotExist($this->previousPath);
        self::assertDirectoryExists($this->failedPath);
        self::assertFileExists($this->activePath . '/PREVIOUS_RELEASE_MARKER');
        self::assertFileExists($this->failedPath . '/ACTIVE_RELEASE_MARKER');
        $this->assertPermissionContract($this->activePath);
        $this->assertPermissionContract($this->failedPath);
        self::assertStringContainsString('Starting automatic rollback', $result['stdout']);
        self::assertStringContainsString('Rollback succeeded, deployment remains failed', $result['stdout']);
        self::assertStringNotContainsString('Deployment completed', $result['stdout']);
        self::assertFileDoesNotExist($this->maliciousSentinel);
        self::assertStringNotContainsString('SENSITIVE_TEST_MARKER', $result['stdout'] . $result['stderr']);

        $deployTiming = array_values(
            array_filter(
                $this->deployTimingEvents($result['stdout']),
                static fn(array $event): bool => $event['mode'] === 'deploy',
            ),
        );
        $phaseEvents = array_values(
            array_filter($deployTiming, static fn(array $event): bool => $event['event'] === 'phase'),
        );
        self::assertSame(
            ['preparation_artifact', 'predeploy', 'permissions_stage', 'switch', 'postdeploy_validation', 'rollback'],
            array_column($phaseEvents, 'phase'),
        );
        self::assertSame(['ok', 'ok', 'ok', 'ok', 'failed', 'ok'], array_column($phaseEvents, 'status'));
        self::assertSame('rollback_succeeded', $deployTiming[array_key_last($deployTiming)]['outcome']);
        self::assertSame(30, $deployTiming[array_key_last($deployTiming)]['exit_code']);
    }

    public function testTimingWriteFailuresAfterSwitchDoNotStopGateOrAutomaticRollback(): void
    {
        chmod($this->activePath . '/config.php', 0644);
        $releaseId = 'ea_timing_write_failure';
        $this->failedPath = $this->activePath . '_failed_' . $releaseId;

        $script = <<<'BASH'
        set -Eeuo pipefail
        source "$1"
        APP="$2"
        PREV="$3"
        REL="$4"
        WEBUSER="www-data"
        DRYRUN=0
        ZERO_SURPRISE_CANARY_REPORT=""
        restart_renderer_service() { return 0; }
        probe_renderer_health() { return 0; }
        reload_services() { return 0; }
        probe_deep_health_contract() { return 0; }
        emit_zero_surprise_incident() { return 0; }

        deploy_timing_init deploy 0 preparation_artifact
        deploy_timing_transition predeploy
        deploy_timing_transition permissions_stage
        deploy_timing_transition switch
        DEPLOY_TIMING_SWITCH_STATE="complete"
        TIMING_WRITE_FAILURES=4
        printf() {
          if [[ "${1:-}" == DEPLOY_TIMING\ * && "$TIMING_WRITE_FAILURES" -gt 0 ]]; then
            TIMING_WRITE_FAILURES="$((TIMING_WRITE_FAILURES - 1))"
            return 74
          fi
          builtin printf "$@"
        }
        deploy_timing_transition postdeploy_validation
        builtin printf 'POST_SWITCH_GATE_REACHED\n'
        verify_post_switch_runtime_config_contracts

        exit 99
        BASH;
        $result = $this->runCommand([
            'bash',
            '-c',
            $script,
            'bash',
            $this->trustedDeployScript,
            $this->activePath,
            $this->previousPath,
            $releaseId,
        ]);

        self::assertSame(30, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString('POST_SWITCH_GATE_REACHED', $result['stdout']);
        self::assertStringContainsString('Starting automatic rollback', $result['stdout']);
        self::assertStringContainsString('Rollback succeeded, deployment remains failed', $result['stdout']);
        self::assertDirectoryExists($this->activePath);
        self::assertDirectoryDoesNotExist($this->previousPath);
        self::assertDirectoryExists($this->failedPath);
        self::assertFileExists($this->activePath . '/PREVIOUS_RELEASE_MARKER');
        self::assertFileExists($this->failedPath . '/ACTIVE_RELEASE_MARKER');
        $this->assertPermissionContract($this->activePath);
        $this->assertPermissionContract($this->failedPath);
        self::assertStringNotContainsString('SENSITIVE_TEST_MARKER', $result['stdout'] . $result['stderr']);

        $events = $this->deployTimingEvents($result['stdout']);
        self::assertContains('deploy', array_column($events, 'mode'));
        self::assertContains('manual_rollback', array_column($events, 'mode'));
        foreach ($this->deployTimingLines($result['stdout']) as $timingLine) {
            self::assertStringNotContainsString($this->workspace, $timingLine);
            self::assertStringNotContainsString('SENSITIVE_TEST_MARKER', $timingLine);
        }
    }

    public function testTimingClockFailureAfterSwitchDoesNotStopGateOrAutomaticRollback(): void
    {
        $result = $this->runAutomaticRollbackWithTimingFault('clock_failure');

        $this->assertTimingFaultReachedGateAndPreservedExit($result, 30);
        self::assertStringContainsString('Rollback succeeded, deployment remains failed', $result['stdout']);
        self::assertDirectoryExists($this->activePath);
        self::assertDirectoryDoesNotExist($this->previousPath);
        self::assertDirectoryExists($this->failedPath);
        self::assertFileExists($this->activePath . '/PREVIOUS_RELEASE_MARKER');
        self::assertFileExists($this->failedPath . '/ACTIVE_RELEASE_MARKER');
        $this->assertPermissionContract($this->activePath);
        $this->assertPermissionContract($this->failedPath);
    }

    public function testInvalidTimingStateAfterSwitchDoesNotStopGateOrAutomaticRollback(): void
    {
        $result = $this->runAutomaticRollbackWithTimingFault('invalid_state');

        $this->assertTimingFaultReachedGateAndPreservedExit($result, 30);
        self::assertStringContainsString('Rollback succeeded, deployment remains failed', $result['stdout']);
        self::assertStringNotContainsString('unbound variable', $result['stderr']);
        self::assertDirectoryExists($this->activePath);
        self::assertDirectoryDoesNotExist($this->previousPath);
        self::assertDirectoryExists($this->failedPath);
    }

    public function testTimingClockFailurePreservesAutomaticRollbackFailureExit(): void
    {
        link($this->previousPath . '/config.php', $this->workspace . '/previous-config-hardlink.php');

        $result = $this->runAutomaticRollbackWithTimingFault('clock_failure');

        $this->assertTimingFaultReachedGateAndPreservedExit($result, 31);
        self::assertStringContainsString('Rollback failed or unverifiable', $result['stdout']);
        self::assertDirectoryExists($this->activePath);
        self::assertDirectoryDoesNotExist($this->previousPath);
        self::assertDirectoryExists($this->failedPath);
        self::assertFileExists($this->activePath . '/PREVIOUS_RELEASE_MARKER');
        self::assertFileExists($this->failedPath . '/ACTIVE_RELEASE_MARKER');
    }

    public function testManualRollbackEmitsSuccessfulSecretFreeTimingSummary(): void
    {
        $result = $this->runRollbackMode();

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertDirectoryExists($this->activePath);
        self::assertDirectoryDoesNotExist($this->previousPath);
        self::assertDirectoryExists($this->failedPath);
        self::assertFileExists($this->activePath . '/PREVIOUS_RELEASE_MARKER');
        self::assertFileExists($this->failedPath . '/ACTIVE_RELEASE_MARKER');

        $events = $this->deployTimingEvents($result['stdout']);
        self::assertCount(2, $events);
        self::assertSame('manual_rollback', $events[0]['mode']);
        self::assertSame('rollback', $events[0]['phase']);
        self::assertSame('ok', $events[0]['status']);
        self::assertSame('manual_rollback_succeeded', $events[1]['outcome']);
        self::assertSame(0, $events[1]['exit_code']);
        self::assertStringNotContainsString(
            $this->workspace,
            implode("\n", $this->deployTimingLines($result['stdout'])),
        );
        self::assertStringNotContainsString('SENSITIVE_TEST_MARKER', $result['stdout'] . $result['stderr']);
    }

    public function testRollbackFailsBeforeMovingAnythingWhenFailedTargetAlreadyExists(): void
    {
        mkdir($this->failedPath, 0755, true);

        $result = $this->runRollbackMode();

        self::assertNotSame(0, $result['exit_code']);
        self::assertStringContainsString('failed release target already exists', $result['stderr']);
        self::assertDirectoryExists($this->activePath);
        self::assertDirectoryExists($this->previousPath);
        self::assertFileExists($this->activePath . '/ACTIVE_RELEASE_MARKER');
        self::assertFileExists($this->previousPath . '/PREVIOUS_RELEASE_MARKER');

        $events = $this->deployTimingEvents($result['stdout']);
        self::assertSame('rollback', $events[0]['phase']);
        self::assertSame('failed', $events[0]['status']);
        self::assertSame('manual_rollback_failed', $events[1]['outcome']);
        self::assertSame($result['exit_code'], $events[1]['exit_code']);
    }

    public function testRollbackFailsClosedWhenRestoredConfigCannotBeHardenedAfterSwitch(): void
    {
        link($this->previousPath . '/config.php', $this->workspace . '/previous-config-hardlink.php');

        $result = $this->runRollbackMode();

        self::assertNotSame(0, $result['exit_code']);
        self::assertStringContainsString(
            'Restored release runtime config permissions are unverifiable',
            $result['stderr'],
        );
        self::assertStringNotContainsString('rollback switch and permission contracts verified', $result['stdout']);
        self::assertDirectoryExists($this->activePath);
        self::assertDirectoryDoesNotExist($this->previousPath);
        self::assertDirectoryExists($this->failedPath);
        self::assertFileExists($this->activePath . '/PREVIOUS_RELEASE_MARKER');
        self::assertFileExists($this->failedPath . '/ACTIVE_RELEASE_MARKER');
        $restoredConfigMetadata = stat($this->activePath . '/config.php');
        self::assertIsArray($restoredConfigMetadata);
        self::assertSame(2, $restoredConfigMetadata['nlink']);
        $this->assertPermissionContract($this->failedPath);
        self::assertFileDoesNotExist($this->maliciousSentinel);
        self::assertStringNotContainsString('SENSITIVE_TEST_MARKER', $result['stdout'] . $result['stderr']);
    }

    private function createReleaseTree(string $path, string $marker): void
    {
        mkdir($path . '/scripts/ops', 0777, true);
        file_put_contents($path . '/config.php', "SENSITIVE_TEST_MARKER\n");
        file_put_contents($path . '/' . $marker, '');
        file_put_contents(
            $path . '/scripts/ops/runtime_config_permissions.sh',
            "#!/usr/bin/env bash\ntouch '" . $this->maliciousSentinel . "'\n",
        );
        $this->setRuntimeOwned($path, 0777);
        $this->setRuntimeOwned($path . '/config.php', 0666);
        $this->setRuntimeOwned($path . '/scripts', 0777);
        $this->setRuntimeOwned($path . '/scripts/ops', 0777);
        $this->setRuntimeOwned($path . '/scripts/ops/runtime_config_permissions.sh', 0755);
    }

    private function setRuntimeOwned(string $path, int $mode): void
    {
        chown($path, $this->runtimeUserId);
        chgrp($path, $this->runtimeGroupId);
        chmod($path, $mode);
    }

    private function assertPermissionContract(string $path): void
    {
        clearstatcache(true, $path);
        clearstatcache(true, $path . '/config.php');
        self::assertSame(0, fileowner($path));
        self::assertSame(0, filegroup($path));
        self::assertSame(0755, fileperms($path) & 0777);
        self::assertSame(0, fileowner($path . '/config.php'));
        self::assertSame($this->runtimeGroupId, filegroup($path . '/config.php'));
        self::assertSame(0440, fileperms($path . '/config.php') & 0777);
    }

    /**
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runPermissionMode(string $action, string $appRoot): array
    {
        return $this->runCommand([
            'bash',
            $this->trustedDeployScript,
            '--runtime-config-permissions',
            $action,
            '--app-root',
            $appRoot,
            '--runtime-user',
            'www-data',
        ]);
    }

    /**
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runRollbackMode(): array
    {
        return $this->runCommand([
            'bash',
            $this->trustedDeployScript,
            '--runtime-config-rollback',
            '--active',
            $this->activePath,
            '--previous',
            $this->previousPath,
            '--failed',
            $this->failedPath,
            '--runtime-user',
            'www-data',
        ]);
    }

    /**
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runAutomaticRollbackWithTimingFault(string $fault): array
    {
        chmod($this->activePath . '/config.php', 0644);
        $releaseId = 'ea_timing_' . $fault;
        $this->failedPath = $this->activePath . '_failed_' . $releaseId;

        $script = <<<'BASH'
        set -Eeuo pipefail
        source "$1"
        APP="$2"
        PREV="$3"
        REL="$4"
        TIMING_FAULT="$5"
        WEBUSER="www-data"
        DRYRUN=0
        ZERO_SURPRISE_CANARY_REPORT=""
        restart_renderer_service() { return 0; }
        probe_renderer_health() { return 0; }
        reload_services() { return 0; }
        probe_deep_health_contract() { return 0; }
        emit_zero_surprise_incident() { return 0; }

        deploy_timing_init deploy 0 preparation_artifact
        deploy_timing_transition predeploy
        deploy_timing_transition permissions_stage
        deploy_timing_transition switch
        DEPLOY_TIMING_SWITCH_STATE="complete"
        case "$TIMING_FAULT" in
          clock_failure)
            deploy_timing_now_ms() { return 74; }
            ;;
          invalid_state)
            DEPLOY_TIMING_PHASE_START_MS="not_numeric"
            ;;
          *)
            exit 98
            ;;
        esac
        deploy_timing_transition postdeploy_validation
        builtin printf 'POST_SWITCH_GATE_REACHED\n'
        verify_post_switch_runtime_config_contracts

        exit 99
        BASH;

        return $this->runCommand([
            'bash',
            '-c',
            $script,
            'bash',
            $this->trustedDeployScript,
            $this->activePath,
            $this->previousPath,
            $releaseId,
            $fault,
        ]);
    }

    /**
     * @param array{exit_code:int,stdout:string,stderr:string} $result
     */
    private function assertTimingFaultReachedGateAndPreservedExit(array $result, int $expectedExit): void
    {
        self::assertSame($expectedExit, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString('POST_SWITCH_GATE_REACHED', $result['stdout']);
        self::assertStringContainsString('Starting automatic rollback', $result['stdout']);
        self::assertStringNotContainsString('"exit_code":74', $result['stdout']);
        self::assertStringNotContainsString('SENSITIVE_TEST_MARKER', $result['stdout'] . $result['stderr']);
        self::assertFileDoesNotExist($this->maliciousSentinel);

        foreach ($this->deployTimingLines($result['stdout']) as $timingLine) {
            self::assertStringNotContainsString($this->workspace, $timingLine);
            self::assertStringNotContainsString('SENSITIVE_TEST_MARKER', $timingLine);
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
        self::assertIsResource($process);

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
            self::assertIsArray($payload);
            self::assertSame('deploy_timing.v1', $payload['schema'] ?? null);
            $events[] = $payload;
        }

        self::assertNotSame([], $events);

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
            if ($item->isLink() || $item->isFile()) {
                unlink($item->getPathname());
                continue;
            }

            rmdir($item->getPathname());
        }

        rmdir($path);
    }
}
