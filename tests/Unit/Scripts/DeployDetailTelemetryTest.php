<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class DeployDetailTelemetryTest extends TestCase
{
    public function testDeployScriptDoesNotSwallowStorageRsyncFailures(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/deploy_ea.sh');
        self::assertIsString($source);
        $legacySilentSkip = <<<'BASH'
        rsync -a '$APP/storage/' '$STAGE_ROOT/storage/' 2>/dev/null || true
        BASH;

        self::assertStringNotContainsString($legacySilentSkip, $source);
        self::assertStringContainsString('sync_live_storage_to_stage', $source);
        $requireRsync = strrpos($source, "\nrequire_command rsync\n");
        $storageSync = strrpos($source, "\nsync_live_storage_to_stage \\\n");
        $switch = strrpos($source, "\nperform_atomic_switch\n");
        self::assertIsInt($requireRsync);
        self::assertIsInt($storageSync);
        self::assertIsInt($switch);
        self::assertLessThan($storageSync, $requireRsync);
        self::assertLessThan($switch, $storageSync);
    }

    public function testStorageFingerprintIsClosedSecretFreeAndHandlesLargeSpecialCharacterTrees(): void
    {
        $workspace = sys_get_temp_dir() . '/rob456-fingerprint-customer-secret-' . bin2hex(random_bytes(6));
        $storage = $workspace . '/storage with spaces';
        self::assertTrue(mkdir($storage, 0700, true));

        try {
            for ($index = 0; $index < 2050; $index++) {
                $name = sprintf("entry-%04d-quote-'-%s.txt", $index, $index === 2049 ? 'SENSITIVE_MARKER' : 'safe');
                self::assertNotFalse(file_put_contents($storage . '/' . $name, 'x'));
            }
            self::assertNotFalse(file_put_contents($storage . "/line\nbreak-SENSITIVE_MARKER.txt", 'payload'));

            $script = <<<'BASH'
            set -Eeuo pipefail
            source "$1"
            DEPLOY_TIMING_RUN_ID="018f6f52-4c87-4d4e-8b19-6a66e6e1af25"
            DEPLOY_TIMING_START_MS="$(deploy_timing_now_ms)"
            deploy_detail_init 0
            deploy_detail_emit_storage_fingerprint source_before "$2"
            BASH;

            $result = $this->runCommand([
                'bash',
                '-c',
                $script,
                'bash',
                dirname(__DIR__, 3) . '/deploy_ea.sh',
                $storage,
            ]);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            $events = $this->detailEvents($result['stdout']);
            self::assertCount(1, $events);
            self::assertSame(
                [
                    'schema',
                    'run_id',
                    'sequence',
                    'event',
                    'phase',
                    'subphase',
                    'boundary',
                    'status',
                    'reason_code',
                    'file_count',
                    'logical_bytes',
                    'allocated_bytes',
                    'elapsed_ms',
                    'dry_run',
                ],
                array_keys($events[0]),
            );
            self::assertSame('deploy_detail.v1', $events[0]['schema']);
            self::assertSame('storage_fingerprint', $events[0]['event']);
            self::assertSame('permissions_stage', $events[0]['phase']);
            self::assertSame('storage_transfer', $events[0]['subphase']);
            self::assertSame('source_before', $events[0]['boundary']);
            self::assertSame('ok', $events[0]['status']);
            self::assertSame('none', $events[0]['reason_code']);
            self::assertSame(2051, $events[0]['file_count']);
            self::assertSame(2057, $events[0]['logical_bytes']);
            self::assertGreaterThanOrEqual(0, $events[0]['allocated_bytes']);
            self::assertGreaterThanOrEqual(0, $events[0]['elapsed_ms']);
            self::assertFalse($events[0]['dry_run']);
            self::assertStringNotContainsString($workspace, $result['stdout'] . $result['stderr']);
            self::assertStringNotContainsString('SENSITIVE_MARKER', $result['stdout'] . $result['stderr']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testSubphaseTelemetryUsesClosedSchemaAndMonotonicSequence(): void
    {
        $script = <<<'BASH'
        set -Eeuo pipefail
        source "$1"
        DEPLOY_TIMING_RUN_ID="018f6f52-4c87-4d4e-8b19-6a66e6e1af25"
        DEPLOY_TIMING_START_MS="$(deploy_timing_now_ms)"
        deploy_detail_init 0
        deploy_detail_run_subphase predeploy stage_permissions stage_permissions_failed true
        deploy_detail_run_subphase predeploy zero_surprise_replay zero_surprise_failed true
        deploy_detail_run_subphase permissions_stage renderer_dependencies renderer_dependencies_failed true
        deploy_detail_run_subphase permissions_stage final_permissions final_permissions_failed true
        deploy_detail_run_subphase permissions_stage runtime_config_contracts runtime_config_contract_failed true
        BASH;

        $result = $this->runCommand(['bash', '-c', $script, 'bash', dirname(__DIR__, 3) . '/deploy_ea.sh']);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        $events = $this->detailEvents($result['stdout']);
        self::assertSame([1, 2, 3, 4, 5], array_column($events, 'sequence'));
        self::assertSame(
            [
                'stage_permissions',
                'zero_surprise_replay',
                'renderer_dependencies',
                'final_permissions',
                'runtime_config_contracts',
            ],
            array_column($events, 'subphase'),
        );
        foreach ($events as $event) {
            self::assertSame(
                [
                    'schema',
                    'run_id',
                    'sequence',
                    'event',
                    'phase',
                    'subphase',
                    'status',
                    'reason_code',
                    'duration_ms',
                    'elapsed_ms',
                    'dry_run',
                ],
                array_keys($event),
            );
            self::assertSame('subphase', $event['event']);
            self::assertSame('ok', $event['status']);
            self::assertSame('none', $event['reason_code']);
            self::assertGreaterThanOrEqual(0, $event['duration_ms']);
            self::assertGreaterThanOrEqual(0, $event['elapsed_ms']);
        }
    }

    public function testStorageTransferCopiesSpecialNamesAndBindsBeforeAndAfterFingerprints(): void
    {
        if (trim((string) shell_exec('command -v rsync')) === '') {
            self::markTestSkipped('rsync is required for the storage-transfer success regression.');
        }

        $workspace = sys_get_temp_dir() . '/rob456-storage-success-' . bin2hex(random_bytes(6));
        $source = $workspace . '/source storage';
        $target = $workspace . '/target storage';
        self::assertTrue(mkdir($source, 0700, true));
        self::assertTrue(mkdir($target, 0700, true));
        self::assertNotFalse(file_put_contents($source . "/quote-' space.txt", 'abc'));
        self::assertNotFalse(file_put_contents($source . "/line\nbreak-SENSITIVE_MARKER.txt", 'payload'));

        $script = <<<'BASH'
        set -Eeuo pipefail
        source "$1"
        DEPLOY_TIMING_RUN_ID="018f6f52-4c87-4d4e-8b19-6a66e6e1af25"
        DEPLOY_TIMING_START_MS="$(deploy_timing_now_ms)"
        deploy_detail_init 0
        sync_storage_payload_with_detail "$2" "$3"
        BASH;

        try {
            $result = $this->runCommand([
                'bash',
                '-c',
                $script,
                'bash',
                dirname(__DIR__, 3) . '/deploy_ea.sh',
                $source,
                $target,
            ]);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertSame('abc', file_get_contents($target . "/quote-' space.txt"));
            self::assertSame('payload', file_get_contents($target . "/line\nbreak-SENSITIVE_MARKER.txt"));
            $events = $this->detailEvents($result['stdout']);
            self::assertSame([1, 2, 3, 4], array_column($events, 'sequence'));
            self::assertSame(
                ['source_before', 'target_before', 'target_after'],
                array_column(array_slice($events, 0, 3), 'boundary'),
            );
            self::assertSame([2, 0, 2], array_column(array_slice($events, 0, 3), 'file_count'));
            self::assertSame([10, 0, 10], array_column(array_slice($events, 0, 3), 'logical_bytes'));
            self::assertSame('subphase', $events[3]['event']);
            self::assertSame('ok', $events[3]['status']);
            self::assertStringNotContainsString($workspace, $result['stdout'] . $result['stderr']);
            self::assertStringNotContainsString('SENSITIVE_MARKER', $result['stdout'] . $result['stderr']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testDryRunStorageTransferSkipsCopyAndEmitsSkippedTelemetry(): void
    {
        $workspace = sys_get_temp_dir() . '/rob456-storage-dry-run-' . bin2hex(random_bytes(6));
        $source = $workspace . '/source storage';
        $target = $workspace . '/target storage';
        self::assertTrue(mkdir($source, 0700, true));
        self::assertTrue(mkdir($target, 0700, true));
        self::assertNotFalse(file_put_contents($source . '/dry-run-source.txt', 'payload'));

        $script = <<<'BASH'
        set -Eeuo pipefail
        source "$1"
        DEPLOY_TIMING_RUN_ID="018f6f52-4c87-4d4e-8b19-6a66e6e1af25"
        DEPLOY_TIMING_START_MS="$(deploy_timing_now_ms)"
        DRYRUN=1
        deploy_detail_init 1
        sync_storage_payload_with_detail "$2" "$3"
        BASH;

        try {
            $result = $this->runCommand([
                'bash',
                '-c',
                $script,
                'bash',
                dirname(__DIR__, 3) . '/deploy_ea.sh',
                $source,
                $target,
            ]);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertFileDoesNotExist($target . '/dry-run-source.txt');
            $events = $this->detailEvents($result['stdout']);
            self::assertSame([1, 2, 3, 4], array_column($events, 'sequence'));
            self::assertSame(
                ['source_before', 'target_before', 'target_after'],
                array_column(array_slice($events, 0, 3), 'boundary'),
            );
            foreach (array_slice($events, 0, 3) as $event) {
                self::assertSame('storage_fingerprint', $event['event']);
                self::assertSame('skipped', $event['status']);
                self::assertSame('dry_run', $event['reason_code']);
                self::assertTrue($event['dry_run']);
                self::assertSame(0, $event['file_count']);
                self::assertSame(0, $event['logical_bytes']);
                self::assertSame(0, $event['allocated_bytes']);
            }
            self::assertSame('subphase', $events[3]['event']);
            self::assertSame('skipped', $events[3]['status']);
            self::assertSame('dry_run', $events[3]['reason_code']);
            self::assertTrue($events[3]['dry_run']);
            self::assertStringNotContainsString(
                $workspace,
                implode(
                    "\n",
                    array_map(static fn(array $event): string => json_encode($event, JSON_THROW_ON_ERROR), $events),
                ),
            );
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testMalformedStorageFingerprintFallsBackWithoutLeakingOrFailingTransfer(): void
    {
        if (trim((string) shell_exec('command -v rsync')) === '') {
            self::markTestSkipped('rsync is required for the storage-fingerprint fallback regression.');
        }

        $workspace = sys_get_temp_dir() . '/rob456-storage-fingerprint-fallback-' . bin2hex(random_bytes(6));
        $source = $workspace . '/source storage';
        $target = $workspace . '/target storage';
        self::assertTrue(mkdir($source, 0700, true));
        self::assertTrue(mkdir($target, 0700, true));
        self::assertNotFalse(file_put_contents($source . '/safe.txt', 'payload'));

        $script = <<<'BASH'
        set -Eeuo pipefail
        source "$1"
        DEPLOY_TIMING_RUN_ID="018f6f52-4c87-4d4e-8b19-6a66e6e1af25"
        DEPLOY_TIMING_START_MS="$(deploy_timing_now_ms)"
        deploy_detail_init 0
        deploy_detail_storage_totals() { printf 'MALFORMED SENSITIVE_MARKER\n'; }
        sync_storage_payload_with_detail "$2" "$3"
        BASH;

        try {
            $result = $this->runCommand([
                'bash',
                '-c',
                $script,
                'bash',
                dirname(__DIR__, 3) . '/deploy_ea.sh',
                $source,
                $target,
            ]);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertSame('payload', file_get_contents($target . '/safe.txt'));
            $events = $this->detailEvents($result['stdout']);
            self::assertCount(4, $events);
            foreach (array_slice($events, 0, 3) as $event) {
                self::assertSame('storage_fingerprint', $event['event']);
                self::assertSame('failed', $event['status']);
                self::assertSame('storage_fingerprint_failed', $event['reason_code']);
                self::assertSame(0, $event['file_count']);
                self::assertSame(0, $event['logical_bytes']);
                self::assertSame(0, $event['allocated_bytes']);
            }
            self::assertSame('subphase', $events[3]['event']);
            self::assertSame('ok', $events[3]['status']);
            self::assertSame('none', $events[3]['reason_code']);
            self::assertStringNotContainsString('SENSITIVE_MARKER', $result['stdout'] . $result['stderr']);
            self::assertStringNotContainsString($workspace, $result['stdout'] . $result['stderr']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testSubphaseFailurePreservesExitAndEmitsOnlyAllowlistedReason(): void
    {
        $script = <<<'BASH'
        set -Eeuo pipefail
        source "$1"
        DEPLOY_TIMING_RUN_ID="018f6f52-4c87-4d4e-8b19-6a66e6e1af25"
        DEPLOY_TIMING_START_MS="$(deploy_timing_now_ms)"
        deploy_detail_init 0
        failing_gate() { printf 'SENSITIVE_MARKER\n' >&2; return 23; }
        set +e
        deploy_detail_run_subphase permissions_stage final_permissions final_permissions_failed failing_gate
        result="$?"
        set -e
        printf 'RESULT=%s\n' "$result"
        exit 0
        BASH;

        $result = $this->runCommand(['bash', '-c', $script, 'bash', dirname(__DIR__, 3) . '/deploy_ea.sh']);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString('RESULT=23', $result['stdout']);
        $events = $this->detailEvents($result['stdout']);
        self::assertCount(1, $events);
        self::assertSame('failed', $events[0]['status']);
        self::assertSame('final_permissions_failed', $events[0]['reason_code']);
        self::assertStringNotContainsString(
            'SENSITIVE_MARKER',
            implode(
                "\n",
                array_map(static fn(array $event): string => json_encode($event, JSON_THROW_ON_ERROR), $events),
            ),
        );
    }

    public function testPredeploySubphaseFailuresAbortBeforeSwitchWithAllowlistedReasons(): void
    {
        $cases = [
            ['stage_permissions', 'stage_permissions_failed'],
            ['zero_surprise_replay', 'zero_surprise_failed'],
        ];

        foreach ($cases as [$subphase, $reasonCode]) {
            $script = <<<'BASH'
            set -Eeuo pipefail
            source "$1"
            DEPLOY_TIMING_RUN_ID="018f6f52-4c87-4d4e-8b19-6a66e6e1af25"
            DEPLOY_TIMING_START_MS="$(deploy_timing_now_ms)"
            deploy_detail_init 0
            failing_predeploy_gate() { printf 'SENSITIVE_MARKER /secret/path\n' >&2; return 23; }
            deploy_detail_run_subphase predeploy "$2" "$3" failing_predeploy_gate
            printf 'SWITCH_REACHED\n'
            BASH;

            $result = $this->runCommand([
                'bash',
                '-c',
                $script,
                'bash',
                dirname(__DIR__, 3) . '/deploy_ea.sh',
                $subphase,
                $reasonCode,
            ]);

            self::assertSame(23, $result['exit_code']);
            self::assertStringNotContainsString('SWITCH_REACHED', $result['stdout']);
            $events = $this->detailEvents($result['stdout']);
            self::assertCount(1, $events);
            self::assertSame('predeploy', $events[0]['phase']);
            self::assertSame($subphase, $events[0]['subphase']);
            self::assertSame('failed', $events[0]['status']);
            self::assertSame($reasonCode, $events[0]['reason_code']);
            self::assertStringNotContainsString('SENSITIVE_MARKER', $result['stdout']);
            self::assertStringNotContainsString('/secret/path', $result['stdout']);
        }
    }

    public function testInstrumentedRendererPreparationCannotMaskAnEarlyCommandFailure(): void
    {
        $script = <<<'BASH'
        set -Eeuo pipefail
        source "$1"
        DEPLOY_TIMING_RUN_ID="018f6f52-4c87-4d4e-8b19-6a66e6e1af25"
        DEPLOY_TIMING_START_MS="$(deploy_timing_now_ms)"
        deploy_detail_init 0
        DRYRUN=1
        RENDERER_DEPLOY_MODE=host
        STAGE_ROOT=/stage
        prepare_renderer_state_dir() { return 23; }
        install_renderer_dependencies() { printf 'MASKED_FAILURE\n'; return 0; }
        set +e
        deploy_detail_run_subphase permissions_stage renderer_dependencies renderer_dependencies_failed \
            prepare_stage_renderer_dependencies
        result="$?"
        set -e
        printf 'RESULT=%s\n' "$result"
        BASH;

        $result = $this->runCommand(['bash', '-c', $script, 'bash', dirname(__DIR__, 3) . '/deploy_ea.sh']);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString('RESULT=23', $result['stdout']);
        self::assertStringNotContainsString('MASKED_FAILURE', $result['stdout']);
        $events = $this->detailEvents($result['stdout']);
        self::assertCount(1, $events);
        self::assertSame('failed', $events[0]['status']);
        self::assertSame('renderer_dependencies_failed', $events[0]['reason_code']);
    }

    public function testInvalidTelemetryLabelsCannotBlockOrLeakFromWrappedOperation(): void
    {
        $script = <<<'BASH'
        set -Eeuo pipefail
        source "$1"
        DEPLOY_TIMING_RUN_ID="018f6f52-4c87-4d4e-8b19-6a66e6e1af25"
        DEPLOY_TIMING_START_MS="$(deploy_timing_now_ms)"
        deploy_detail_init 0
        wrapped_operation() { printf 'OPERATION_RAN\n'; return 23; }
        set +e
        deploy_detail_run_subphase 'predeploy/SENSITIVE_MARKER' '../unsafe' '/secret/path' wrapped_operation
        result="$?"
        set -e
        printf 'RESULT=%s\n' "$result"
        BASH;

        $result = $this->runCommand(['bash', '-c', $script, 'bash', dirname(__DIR__, 3) . '/deploy_ea.sh']);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString('OPERATION_RAN', $result['stdout']);
        self::assertStringContainsString('RESULT=23', $result['stdout']);
        self::assertSame([], $this->detailEvents($result['stdout']));
        self::assertStringNotContainsString('SENSITIVE_MARKER', $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('/secret/path', $result['stdout'] . $result['stderr']);
    }

    public function testStorageTransferDryRunSkipsCopyAndEmitsOnlySkippedDryRunDetail(): void
    {
        $workspace = sys_get_temp_dir() . '/rob456-storage-dry-run-' . bin2hex(random_bytes(6));
        $source = $workspace . '/source';
        $target = $workspace . '/target';
        self::assertTrue(mkdir($source, 0700, true));
        self::assertTrue(mkdir($target, 0700, true));
        self::assertNotFalse(file_put_contents($source . '/SENSITIVE_MARKER.txt', 'payload'));

        $script = <<<'BASH'
        set -Eeuo pipefail
        source "$1"
        DEPLOY_TIMING_RUN_ID="018f6f52-4c87-4d4e-8b19-6a66e6e1af25"
        DEPLOY_TIMING_START_MS="$(deploy_timing_now_ms)"
        deploy_detail_init 1
        DRYRUN=1
        sync_storage_payload_with_detail "$2" "$3"
        BASH;

        try {
            $result = $this->runCommand([
                'bash',
                '-c',
                $script,
                'bash',
                dirname(__DIR__, 3) . '/deploy_ea.sh',
                $source,
                $target,
            ]);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertFileDoesNotExist($target . '/SENSITIVE_MARKER.txt');
            $events = $this->detailEvents($result['stdout']);
            self::assertCount(4, $events);
            self::assertSame([1, 2, 3, 4], array_column($events, 'sequence'));
            self::assertSame(
                ['source_before', 'target_before', 'target_after'],
                array_column(array_slice($events, 0, 3), 'boundary'),
            );
            foreach ($events as $event) {
                self::assertSame('skipped', $event['status']);
                self::assertSame('dry_run', $event['reason_code']);
                self::assertTrue($event['dry_run']);
                $encoded = json_encode($event, JSON_THROW_ON_ERROR);
                self::assertStringNotContainsString($workspace, $encoded);
                self::assertStringNotContainsString('SENSITIVE_MARKER', $encoded);
            }
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testFingerprintFailureAndMalformedTotalsRemainSanitizedAndFailOpen(): void
    {
        $script = <<<'BASH'
        set -Eeuo pipefail
        source "$1"
        DEPLOY_TIMING_RUN_ID="018f6f52-4c87-4d4e-8b19-6a66e6e1af25"
        DEPLOY_TIMING_START_MS="$(deploy_timing_now_ms)"
        deploy_detail_init 0
        deploy_detail_storage_totals() { printf 'SENSITIVE_MARKER /secret/path\n' >&2; return 23; }
        deploy_detail_emit_storage_fingerprint source_before /unreadable-secret-root
        deploy_detail_storage_totals() { printf 'malformed SENSITIVE_MARKER /secret/path\n'; return 0; }
        deploy_detail_emit_storage_fingerprint target_before /malformed-secret-root
        printf 'CONTINUED\n'
        BASH;

        $result = $this->runCommand(['bash', '-c', $script, 'bash', dirname(__DIR__, 3) . '/deploy_ea.sh']);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString('CONTINUED', $result['stdout']);
        $events = $this->detailEvents($result['stdout']);
        self::assertCount(2, $events);
        foreach ($events as $event) {
            self::assertSame('failed', $event['status']);
            self::assertSame('storage_fingerprint_failed', $event['reason_code']);
            self::assertSame(0, $event['file_count']);
            self::assertSame(0, $event['logical_bytes']);
            self::assertSame(0, $event['allocated_bytes']);
        }
        self::assertStringNotContainsString('SENSITIVE_MARKER', $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('/secret/path', $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('unreadable-secret-root', $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('malformed-secret-root', $result['stdout'] . $result['stderr']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function detailEvents(string $output): array
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
