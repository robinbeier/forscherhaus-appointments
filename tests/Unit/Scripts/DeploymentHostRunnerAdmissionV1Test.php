<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Ops\DeploymentContractV1;
use Ops\DeploymentEvidenceAuthorityV1;
use Ops\DeploymentHostRunnerContractV1;
use Ops\DeploymentHostRunnerV1;
use Ops\HostRunnerBootReader;
use Ops\HostRunnerClock;
use Ops\HostRunnerActionCompletion;
use Ops\HostRunnerDeployAdmission;
use Ops\HostRunnerDeployScriptReader;
use Ops\HostRunnerLaunchNonceSource;
use Ops\HostRunnerProcessResult;
use Ops\HostRunnerRecoveryAdmission;
use Ops\HostRunnerReservationPersistence;
use Ops\HostRunnerStartOrchestrator;
use Ops\HostRunnerStorage;
use Ops\HostRunnerSystemAdapter;
use Ops\HostRunnerTerminalizer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/ops/lib/DeploymentHostRunnerAdmissionV1.php';
require_once __DIR__ . '/../../../scripts/ops/lib/DeploymentHostRunnerRecoveryV1.php';

final class DeploymentHostRunnerAdmissionV1Test extends TestCase
{
    private const BOOT = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    public function testArtifactVerifiedRunIsReservedBeforeExactlyOneSystemdAdmission(): void
    {
        $request = DeploymentHostRunnerContractV1::decodeDeployRequest(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/deploy-request.json'),
        );
        $input = DeploymentHostRunnerContractV1::decodeExecutionInput(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/execution-input.json'),
        );
        $script = "#!/bin/bash\nexit 0\n";
        $nonce = str_repeat("\x11", 32);
        $launch = DeploymentHostRunnerContractV1::createSystemdLaunch(
            $input,
            $request,
            null,
            $script,
            static fn(): string => $nonce,
        );
        $storage = new AdmissionStorageFake();
        $this->seedArtifactVerified($storage, $request, $input, hash('sha256', $script));
        $adapter = new AdmissionSystemAdapterFake([
            new HostRunnerProcessResult(0, $this->notFoundShow($launch), ''),
            new HostRunnerProcessResult(0, '', ''),
        ]);
        $boot = new AdmissionBootReaderFake(self::BOOT . "\n");
        $clock = new AdmissionClockFake('2026-08-12T12:01:00Z');
        $start = new HostRunnerStartOrchestrator(
            new HostRunnerReservationPersistence($storage, null, $clock),
            new DeploymentHostRunnerV1($adapter),
            $boot,
        );
        $admission = new HostRunnerDeployAdmission(
            $storage,
            $start,
            new AdmissionScriptReaderFake($script),
            new AdmissionNonceSourceFake($nonce),
            $boot,
            $clock,
        );

        $response = $admission->admit($request, $input);

        self::assertSame('accepted', $response['disposition']);
        self::assertSame('deploy_running', $response['state']);
        self::assertCount(2, $adapter->calls);
        self::assertSame('/bin/systemctl', $adapter->calls[0][5]);
        self::assertSame('/usr/bin/systemd-run', $adapter->calls[1][5]);
        $prefix = 'runs/' . $request['run_id'] . '/';
        $state = DeploymentHostRunnerContractV1::decodeState($storage->files[$prefix . 'state.json']);
        $claim = DeploymentHostRunnerContractV1::decodeActiveRun($storage->files['active-run.json']);
        self::assertSame('deploy_running', $state['state']);
        self::assertSame($state['events_sha256'], $claim['events_sha256']);
        self::assertEquals(
            $launch,
            DeploymentHostRunnerContractV1::decodeSystemdLaunch(
                $storage->files[$prefix . 'deploy-systemd-launch.json'],
            ),
        );
    }

    public function testFailedPostGatesReserveExactlyOneRollbackAndReplayOnlyObserves(): void
    {
        $request = DeploymentHostRunnerContractV1::decodeDeployRequest(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/deploy-request.json'),
        );
        $input = DeploymentHostRunnerContractV1::decodeExecutionInput(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/execution-input.json'),
        );
        $script = "#!/bin/bash\nexit 0\n";
        $storage = new AdmissionStorageFake();
        $this->seedArtifactVerified($storage, $request, $input, hash('sha256', $script));
        $deployNonce = str_repeat("\x11", 32);
        $deployLaunch = DeploymentHostRunnerContractV1::createSystemdLaunch(
            $input,
            $request,
            null,
            $script,
            static fn(): string => $deployNonce,
        );
        $boot = new AdmissionBootReaderFake(self::BOOT . "\n");
        $clock = new AdmissionClockFake('2026-08-12T12:01:00Z');
        $deployAdapter = new AdmissionSystemAdapterFake([
            new HostRunnerProcessResult(0, $this->notFoundShow($deployLaunch), ''),
            new HostRunnerProcessResult(0, '', ''),
        ]);
        $deployStart = new HostRunnerStartOrchestrator(
            new HostRunnerReservationPersistence($storage, null, $clock),
            new DeploymentHostRunnerV1($deployAdapter),
            $boot,
        );
        (new HostRunnerDeployAdmission(
            $storage,
            $deployStart,
            new AdmissionScriptReaderFake($script),
            new AdmissionNonceSourceFake($deployNonce),
            $boot,
            $clock,
        ))->admit($request, $input);

        $prefix = 'runs/' . $request['run_id'] . '/';
        $deployObservation = $this->loadedShow($deployLaunch, 'deploy', 'active', 'exited', 'success', 1, 0);
        self::assertEquals(
            $deployLaunch,
            DeploymentHostRunnerContractV1::decodeSystemdLaunch(
                $storage->files[$prefix . 'deploy-systemd-launch.json'],
            ),
        );
        self::assertSame(
            'exited',
            DeploymentHostRunnerContractV1::classifySystemdObservation(
                $deployLaunch,
                DeploymentHostRunnerContractV1::parseSystemctlShow($deployObservation, $deployLaunch),
            )['unit_state'],
        );
        $observe = new HostRunnerStartOrchestrator(
            new HostRunnerReservationPersistence($storage, null, $clock),
            new DeploymentHostRunnerV1(
                new AdmissionSystemAdapterFake([new HostRunnerProcessResult(0, $deployObservation, '')]),
            ),
            $boot,
        );
        $observe->resumeReserved(
            $request['run_id'],
            $storage->files[$prefix . 'events.jsonl'],
            $storage->files['active-run.json'],
            $storage->files[$prefix . 'state.json'],
        );
        $observedState = DeploymentHostRunnerContractV1::decodeState($storage->files[$prefix . 'state.json']);
        self::assertSame('exited', $observedState['deploy']['unit_state']);
        self::assertSame(0, $observedState['deploy']['observed_exit_code']);
        $receiptBytes = \Ops\DeployResultV1::canonicalJson(\Ops\DeployResultV1::create('succeeded', 0));
        $storage->files[$prefix . 'deploy-result.json'] = $receiptBytes;
        $completion = new HostRunnerActionCompletion($storage, $clock);
        $completion->acceptSucceededDeployReceipt($request['run_id']);
        $postGateState = DeploymentHostRunnerContractV1::decodeState($storage->files[$prefix . 'state.json']);
        $reportBytes = DeploymentHostRunnerContractV1::encodePostGateReport([
            'schema' => DeploymentHostRunnerContractV1::POST_GATE_REPORT_SCHEMA,
            'run_id' => $request['run_id'],
            'intent_sha256' => $request['intent_sha256'],
            'captured_at_utc' => '2026-08-12T12:02:00Z',
            'subject' => 'deploy',
            'deploy_receipt_sha256' => hash('sha256', $receiptBytes),
            'post_gates' => [
                'status' => 'failed',
                'kuma_healthy_count' => 12,
                'kuma_total_count' => 13,
                'runtime_config_passed' => true,
                'services_passed' => true,
                'endpoints_passed' => true,
                'logs_passed' => false,
                'scanner_passed' => true,
                'dormant_clean_passed' => true,
                'passed' => false,
            ],
        ]);
        $completion->acceptDeployPostGateReport($request['run_id'], $reportBytes);
        self::assertSame('post_gates_running', $postGateState['state']);
        self::assertSame(
            'refresh_active_claim',
            (new \Ops\HostRunnerReconciliationPersistence($storage))->reconcileStored(
                $request['run_id'],
                $request['intent_sha256'],
            ),
        );

        $recoveryRequest = DeploymentHostRunnerContractV1::decodeRecoveryRequest(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/recovery-request.json'),
        );
        $recoveryInput = $input;
        $recoveryInput['action'] = 'rollback';
        $recoveryInput['parameters'] = ['release_id' => $input['parameters']['release_id']];
        $rollbackNonce = str_repeat("\x22", 32);
        $rollbackLaunch = DeploymentHostRunnerContractV1::createSystemdLaunch(
            $recoveryInput,
            $recoveryRequest,
            $request,
            $script,
            static fn(): string => $rollbackNonce,
        );
        $rollbackAdapter = new AdmissionSystemAdapterFake([
            new HostRunnerProcessResult(0, $this->notFoundShow($rollbackLaunch), ''),
            new HostRunnerProcessResult(0, '', ''),
            new HostRunnerProcessResult(
                0,
                $this->loadedShow($rollbackLaunch, 'rollback', 'active', 'running', 'success', 0, 0),
                '',
            ),
        ]);
        $recoveryClock = new AdmissionClockFake('2026-08-12T12:03:00Z');
        $rollbackStart = new HostRunnerStartOrchestrator(
            new HostRunnerReservationPersistence($storage, null, $recoveryClock),
            new DeploymentHostRunnerV1($rollbackAdapter),
            $boot,
        );
        $recovery = new HostRunnerRecoveryAdmission(
            $storage,
            $rollbackStart,
            new AdmissionScriptReaderFake($script),
            new AdmissionNonceSourceFake($rollbackNonce),
            $boot,
            $recoveryClock,
        );

        $first = $recovery->admit($recoveryRequest, $recoveryInput);
        $second = $recovery->admit($recoveryRequest, $recoveryInput);

        self::assertSame('accepted', $first['disposition']);
        self::assertSame('attach_observe_only', $second['disposition']);
        self::assertSame('rollback_running', $second['state']);
        self::assertCount(3, $rollbackAdapter->calls);
        self::assertSame('/bin/systemctl', $rollbackAdapter->calls[0][5]);
        self::assertSame('/usr/bin/systemd-run', $rollbackAdapter->calls[1][5]);
        self::assertSame('/bin/systemctl', $rollbackAdapter->calls[2][5]);
        self::assertSame(
            'rollback_running',
            DeploymentHostRunnerContractV1::decodeState($storage->files[$prefix . 'state.json'])['state'],
        );
        self::assertSame($reportBytes, $storage->files[$prefix . 'deploy-post-gate-report.json']);
        $unknownStorage = clone $storage;

        $failedAdapter = new AdmissionSystemAdapterFake([
            new HostRunnerProcessResult(
                0,
                $this->loadedShow($rollbackLaunch, 'rollback', 'failed', 'failed', 'exit-code', 1, 31),
                '',
            ),
        ]);
        $failedStart = new HostRunnerStartOrchestrator(
            new HostRunnerReservationPersistence($storage, null, $recoveryClock),
            new DeploymentHostRunnerV1($failedAdapter),
            $boot,
        );
        $terminal = new AdmissionTerminalizerFake([
            'schema' => DeploymentHostRunnerContractV1::RESPONSE_SCHEMA,
            'run_id' => $request['run_id'],
            'intent_sha256' => $request['intent_sha256'],
            'action' => 'recovery',
            'disposition' => 'terminal',
            'state' => 'failed_post_switch_rollback_failed',
            'result_exit_code' => 31,
            'result_reason' => 'rollback_failed',
        ]);
        $failedRecovery = new HostRunnerRecoveryAdmission(
            $storage,
            $failedStart,
            new AdmissionScriptReaderFake($script),
            new AdmissionNonceSourceFake($rollbackNonce),
            $boot,
            $recoveryClock,
            $terminal,
        );

        $third = $failedRecovery->admit($recoveryRequest, $recoveryInput);

        self::assertSame('terminal', $third['disposition']);
        self::assertSame('failed_post_switch_rollback_failed', $third['state']);
        self::assertSame(1, $terminal->rollbackCalls);
        self::assertCount(1, $failedAdapter->calls);
        $failedState = DeploymentHostRunnerContractV1::decodeState($storage->files[$prefix . 'state.json']);
        self::assertSame('failed', $failedState['rollback']['unit_state']);
        self::assertSame(31, $failedState['rollback']['observed_exit_code']);
        self::assertSame('failed', $failedState['rollback']['verdict']);

        $unknownAdapter = new AdmissionSystemAdapterFake([new HostRunnerProcessResult(1, '', 'private transport')]);
        $unknownStart = new HostRunnerStartOrchestrator(
            new HostRunnerReservationPersistence($unknownStorage, null, $recoveryClock),
            new DeploymentHostRunnerV1($unknownAdapter),
            $boot,
        );
        $unknownTerminal = new AdmissionTerminalizerFake([
            'schema' => DeploymentHostRunnerContractV1::RESPONSE_SCHEMA,
            'run_id' => $request['run_id'],
            'intent_sha256' => $request['intent_sha256'],
            'action' => 'recovery',
            'disposition' => 'terminal',
            'state' => 'manual_recovery_required',
            'result_exit_code' => 143,
            'result_reason' => 'interrupted',
        ]);
        $unknownRecovery = new HostRunnerRecoveryAdmission(
            $unknownStorage,
            $unknownStart,
            new AdmissionScriptReaderFake($script),
            new AdmissionNonceSourceFake($rollbackNonce),
            $boot,
            $recoveryClock,
            $unknownTerminal,
        );

        $unknown = $unknownRecovery->admit($recoveryRequest, $recoveryInput);

        self::assertSame('terminal', $unknown['disposition']);
        self::assertSame('manual_recovery_required', $unknown['state']);
        self::assertSame(1, $unknownTerminal->rollbackCalls);
        self::assertCount(1, $unknownAdapter->calls);
        $unknownState = DeploymentHostRunnerContractV1::decodeState($unknownStorage->files[$prefix . 'state.json']);
        self::assertSame('unknown', $unknownState['rollback']['unit_state']);
        self::assertNull($unknownState['rollback']['observed_exit_code']);
        self::assertSame('unknown', $unknownState['rollback']['verdict']);
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $input */
    private function seedArtifactVerified(
        AdmissionStorageFake $storage,
        array $request,
        array $input,
        string $scriptSha,
    ): void {
        $lines = [
            DeploymentContractV1::canonicalJson(
                DeploymentContractV1::createIntentRecord(
                    $request['run_id'],
                    '2026-08-12T12:00:00Z',
                    $request['expected_commit'],
                    $request['release_id'],
                    $request['traffic_mode'],
                ),
            ),
        ];
        $previous = 'planned';
        foreach (
            [
                'built',
                'uploaded',
                'accepted',
                'lock_acquired',
                'expected_commit_verified',
                'traffic_gate_passed',
                'dump_verified',
                'capacity_passed',
                'artifact_verified',
            ]
            as $state
        ) {
            $lines[] = DeploymentContractV1::canonicalJson([
                'schema' => DeploymentContractV1::RUN_SCHEMA,
                'record_type' => 'transition',
                'run_id' => $request['run_id'],
                'sequence' => count($lines) + 1,
                'recorded_at_utc' => '2026-08-12T12:00:00Z',
                'previous_state' => $previous,
                'state' => $state,
                'deploy_invocation_count' => 0,
                'intent_sha256' => $request['intent_sha256'],
                'exit_code' => 0,
                'reason' => 'ok',
            ]);
            $previous = $state;
        }
        $events = implode("\n", $lines) . "\n";
        $state = [
            'schema' => DeploymentHostRunnerContractV1::STATE_SCHEMA,
            'run_id' => $request['run_id'],
            'intent_sha256' => $request['intent_sha256'],
            'state' => 'artifact_verified',
            'sequence' => count($lines),
            'events_sha256' => hash('sha256', $events),
            'active_action' => 'none',
            'deploy' => [
                'request_sha256' => hash('sha256', DeploymentHostRunnerContractV1::encodeFile($request)),
                'execution_input_sha256' => null,
                'invocation_count' => 0,
                'unit_name' => null,
                'unit_launch_sha256' => null,
                'unit_manager_boot_id' => null,
                'unit_invocation_id' => null,
                'unit_missing_observed_boot_id' => null,
                'unit_state' => 'not_created',
                'observed_exit_code' => null,
                'receipt_sha256' => null,
            ],
            'post_gates' => [
                'deploy_report_sha256' => null,
                'deploy_submission_count' => 0,
                'deploy_verdict' => 'not_submitted',
                'rollback_report_sha256' => null,
                'rollback_submission_count' => 0,
                'rollback_verdict' => 'not_submitted',
            ],
            'rollback' => [
                'request_sha256' => null,
                'execution_input_sha256' => null,
                'invocation_count' => 0,
                'unit_name' => null,
                'unit_launch_sha256' => null,
                'unit_manager_boot_id' => null,
                'unit_invocation_id' => null,
                'unit_missing_observed_boot_id' => null,
                'unit_state' => 'not_created',
                'observed_exit_code' => null,
                'verdict' => 'not_invoked',
            ],
            'evidence_sha256' => null,
            'terminal' => ['state' => null, 'exit_code' => null, 'reason' => null],
            'updated_at_utc' => '2026-08-12T12:00:00Z',
        ];
        DeploymentHostRunnerContractV1::validateState($state);
        $assembly = [
            'schema' => DeploymentEvidenceAuthorityV1::PREDEPLOY_ASSEMBLY_SCHEMA,
            'run_id' => $request['run_id'],
            'intent_sha256' => $request['intent_sha256'],
            'status' => 'passed',
            'exit_code' => 0,
            'reason' => 'ok',
            'sections' => ['artifact' => ['host_script_sha256' => $scriptSha]],
        ];
        $prefix = 'runs/' . $request['run_id'] . '/';
        $storage->files[$prefix . 'events.jsonl'] = $events;
        $storage->files[$prefix . 'state.json'] = DeploymentHostRunnerContractV1::encodeFile($state);
        $storage->files[$prefix . 'predeploy-evidence.json'] = DeploymentEvidenceAuthorityV1::encodeFile($assembly);
        $storage->files[$prefix . 'request.json'] = DeploymentHostRunnerContractV1::encodeFile($request);
        $storage->files[$prefix . 'execution-input.json'] = DeploymentHostRunnerContractV1::encodeExecutionInput(
            $input,
        );
    }

    /** @param array<string,mixed> $launch */
    private function notFoundShow(array $launch): string
    {
        return $this->show($launch, $launch['action'], 'not-found', 'inactive', 'dead', 'success', 0, 0, '', 'no');
    }

    /** @param array<string,mixed> $launch */
    private function loadedShow(
        array $launch,
        string $action,
        string $active,
        string $sub,
        string $result,
        int $code,
        int $status,
    ): string {
        return $this->show(
            $launch,
            $action,
            'loaded',
            $active,
            $sub,
            $result,
            $code,
            $status,
            str_repeat('d', 32),
            'yes',
        );
    }

    /** @param array<string,mixed> $launch */
    private function show(
        array $launch,
        string $action,
        string $load,
        string $active,
        string $sub,
        string $result,
        int $code,
        int $status,
        string $invocation,
        string $transient,
    ): string {
        $properties = DeploymentHostRunnerContractV1::observedUnitProperties(
            $action,
            hash('sha256', DeploymentHostRunnerContractV1::encodeFile($launch)),
        );
        return implode("\n", [
            'Id=' . $launch['unit_name'],
            'LoadState=' . $load,
            'ActiveState=' . $active,
            'SubState=' . $sub,
            'Result=' . $result,
            'ExecMainCode=' . $code,
            'ExecMainStatus=' . $status,
            'InvocationID=' . $invocation,
            'Description=' . $properties['Description'],
            'Transient=' . $transient,
            'Type=' . $properties['Type'],
            'RemainAfterExit=' . $properties['RemainAfterExit'],
            'UMask=' . $properties['UMask'],
            'KillMode=' . $properties['KillMode'],
            'Restart=' . $properties['Restart'],
            'RuntimeMaxUSec=' . $properties['RuntimeMaxUSec'],
            'TimeoutStopUSec=' . $properties['TimeoutStopUSec'],
            'StandardInput=' . $properties['StandardInput'],
            'StandardOutput=' . $properties['StandardOutput'],
            'StandardError=' . $properties['StandardError'],
        ]) . "\n";
    }
}

final class AdmissionStorageFake implements HostRunnerStorage
{
    public array $files = [];
    public function prepareRun(string $runId): void {}
    public function pinReference(string $runId, string $field, string $sourcePath, string $sha256): void {}
    public function read(string $relative, int $maxBytes): ?string
    {
        return $this->files[$relative] ?? null;
    }
    public function pin(string $relative, string $bytes, int $maxBytes): string
    {
        if (isset($this->files[$relative]) && $this->files[$relative] !== $bytes) {
            throw new RuntimeException('conflict');
        }
        return $this->files[$relative] = $bytes;
    }
    public function cow(string $relative, string $bytes, int $maxBytes): void
    {
        $this->files[$relative] = $bytes;
    }
    public function refreshBinding(string $relative, string $currentBytes, string $candidateBytes): void
    {
        $this->files[$relative] = $candidateBytes;
    }
    public function refreshActiveClaim(string $currentBytes, string $candidateBytes): void
    {
        $this->files['active-run.json'] = $candidateBytes;
    }
    public function clearActiveClaim(string $expectedBytes): void
    {
        unset($this->files['active-run.json']);
    }
    public function reservedCandidates(): iterable
    {
        return [];
    }
}

final class AdmissionSystemAdapterFake implements HostRunnerSystemAdapter
{
    public array $calls = [];
    public function __construct(private array $results) {}
    public function run(array $argv, array $environment, int $timeoutSeconds): HostRunnerProcessResult
    {
        $this->calls[] = $argv;
        return array_shift($this->results);
    }
}

final class AdmissionBootReaderFake implements HostRunnerBootReader
{
    public function __construct(private readonly string $bytes) {}
    public function read(): string
    {
        return $this->bytes;
    }
}

final class AdmissionTerminalizerFake implements HostRunnerTerminalizer
{
    public int $rollbackCalls = 0;
    /** @param array<string,mixed> $response */
    public function __construct(private readonly array $response) {}
    public function resumeTerminal(string $runId, string $action = 'deploy'): array
    {
        throw new RuntimeException('not expected');
    }
    public function terminalizeDeploy(string $runId): array
    {
        throw new RuntimeException('not expected');
    }
    public function terminalizeUnverifiableDeploy(string $runId): array
    {
        throw new RuntimeException('not expected');
    }
    public function terminalizeRollback(string $runId): array
    {
        $this->rollbackCalls++;
        return $this->response;
    }
}

final class AdmissionClockFake implements HostRunnerClock
{
    public function __construct(private readonly string $now) {}
    public function nowUtc(): string
    {
        return $this->now;
    }
}

final class AdmissionScriptReaderFake implements HostRunnerDeployScriptReader
{
    public function __construct(private readonly string $bytes) {}
    public function read(): string
    {
        return $this->bytes;
    }
}

final class AdmissionNonceSourceFake implements HostRunnerLaunchNonceSource
{
    public function __construct(private readonly string $value) {}
    public function bytes(): string
    {
        return $this->value;
    }
}
