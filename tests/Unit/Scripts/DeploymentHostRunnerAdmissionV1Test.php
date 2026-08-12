<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Ops\DeploymentContractV1;
use Ops\DeploymentEvidenceAuthorityV1;
use Ops\DeploymentHostRunnerContractV1;
use Ops\DeploymentHostRunnerV1;
use Ops\HostRunnerBootReader;
use Ops\HostRunnerClock;
use Ops\HostRunnerDeployAdmission;
use Ops\HostRunnerDeployScriptReader;
use Ops\HostRunnerLaunchNonceSource;
use Ops\HostRunnerProcessResult;
use Ops\HostRunnerReservationPersistence;
use Ops\HostRunnerStartOrchestrator;
use Ops\HostRunnerStorage;
use Ops\HostRunnerSystemAdapter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/ops/lib/DeploymentHostRunnerAdmissionV1.php';

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
            $input, $request, null, $script, static fn(): string => $nonce,
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
            $storage, $start, new AdmissionScriptReaderFake($script),
            new AdmissionNonceSourceFake($nonce), $boot, $clock,
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
        self::assertEquals($launch, DeploymentHostRunnerContractV1::decodeSystemdLaunch(
            $storage->files[$prefix . 'deploy-systemd-launch.json'],
        ));
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $input */
    private function seedArtifactVerified(AdmissionStorageFake $storage, array $request, array $input, string $scriptSha): void
    {
        $lines = [DeploymentContractV1::canonicalJson(DeploymentContractV1::createIntentRecord(
            $request['run_id'], '2026-08-12T12:00:00Z', $request['expected_commit'],
            $request['release_id'], $request['traffic_mode'],
        ))];
        $previous = 'planned';
        foreach (['built', 'uploaded', 'accepted', 'lock_acquired', 'expected_commit_verified', 'traffic_gate_passed', 'dump_verified', 'capacity_passed', 'artifact_verified'] as $state) {
            $lines[] = DeploymentContractV1::canonicalJson([
                'schema' => DeploymentContractV1::RUN_SCHEMA, 'record_type' => 'transition',
                'run_id' => $request['run_id'], 'sequence' => count($lines) + 1,
                'recorded_at_utc' => '2026-08-12T12:00:00Z', 'previous_state' => $previous,
                'state' => $state, 'deploy_invocation_count' => 0,
                'intent_sha256' => $request['intent_sha256'], 'exit_code' => 0, 'reason' => 'ok',
            ]);
            $previous = $state;
        }
        $events = implode("\n", $lines) . "\n";
        $state = [
            'schema' => DeploymentHostRunnerContractV1::STATE_SCHEMA,
            'run_id' => $request['run_id'], 'intent_sha256' => $request['intent_sha256'],
            'state' => 'artifact_verified', 'sequence' => count($lines), 'events_sha256' => hash('sha256', $events),
            'active_action' => 'none',
            'deploy' => [
                'request_sha256' => hash('sha256', DeploymentHostRunnerContractV1::encodeFile($request)),
                'execution_input_sha256' => null, 'invocation_count' => 0, 'unit_name' => null,
                'unit_launch_sha256' => null, 'unit_manager_boot_id' => null, 'unit_invocation_id' => null,
                'unit_missing_observed_boot_id' => null, 'unit_state' => 'not_created',
                'observed_exit_code' => null, 'receipt_sha256' => null,
            ],
            'post_gates' => [
                'deploy_report_sha256' => null, 'deploy_submission_count' => 0, 'deploy_verdict' => 'not_submitted',
                'rollback_report_sha256' => null, 'rollback_submission_count' => 0, 'rollback_verdict' => 'not_submitted',
            ],
            'rollback' => [
                'request_sha256' => null, 'execution_input_sha256' => null, 'invocation_count' => 0,
                'unit_name' => null, 'unit_launch_sha256' => null, 'unit_manager_boot_id' => null,
                'unit_invocation_id' => null, 'unit_missing_observed_boot_id' => null,
                'unit_state' => 'not_created', 'observed_exit_code' => null, 'verdict' => 'not_invoked',
            ],
            'evidence_sha256' => null, 'terminal' => ['state' => null, 'exit_code' => null, 'reason' => null],
            'updated_at_utc' => '2026-08-12T12:00:00Z',
        ];
        DeploymentHostRunnerContractV1::validateState($state);
        $assembly = [
            'schema' => DeploymentEvidenceAuthorityV1::PREDEPLOY_ASSEMBLY_SCHEMA,
            'run_id' => $request['run_id'], 'intent_sha256' => $request['intent_sha256'],
            'status' => 'passed', 'exit_code' => 0, 'reason' => 'ok',
            'sections' => ['artifact' => ['host_script_sha256' => $scriptSha]],
        ];
        $prefix = 'runs/' . $request['run_id'] . '/';
        $storage->files[$prefix . 'events.jsonl'] = $events;
        $storage->files[$prefix . 'state.json'] = DeploymentHostRunnerContractV1::encodeFile($state);
        $storage->files[$prefix . 'predeploy-evidence.json'] = DeploymentEvidenceAuthorityV1::encodeFile($assembly);
        $storage->files[$prefix . 'request.json'] = DeploymentHostRunnerContractV1::encodeFile($request);
        $storage->files[$prefix . 'execution-input.json'] = DeploymentHostRunnerContractV1::encodeExecutionInput($input);
    }

    /** @param array<string,mixed> $launch */
    private function notFoundShow(array $launch): string
    {
        $properties = DeploymentHostRunnerContractV1::observedUnitProperties(
            'deploy', hash('sha256', DeploymentHostRunnerContractV1::encodeFile($launch)),
        );
        return implode("\n", [
            'Id=' . $launch['unit_name'], 'LoadState=not-found', 'ActiveState=inactive', 'SubState=dead',
            'Result=success', 'ExecMainCode=0', 'ExecMainStatus=0', 'InvocationID=',
            'Description=' . $properties['Description'], 'Transient=no', 'Type=' . $properties['Type'],
            'RemainAfterExit=' . $properties['RemainAfterExit'], 'UMask=' . $properties['UMask'],
            'KillMode=' . $properties['KillMode'], 'Restart=' . $properties['Restart'],
            'RuntimeMaxUSec=' . $properties['RuntimeMaxUSec'], 'TimeoutStopUSec=' . $properties['TimeoutStopUSec'],
            'StandardInput=' . $properties['StandardInput'], 'StandardOutput=' . $properties['StandardOutput'],
            'StandardError=' . $properties['StandardError'],
        ]) . "\n";
    }
}

final class AdmissionStorageFake implements HostRunnerStorage
{
    public array $files = [];
    public function prepareRun(string $runId): void {}
    public function pinReference(string $runId, string $field, string $sourcePath, string $sha256): void {}
    public function read(string $relative, int $maxBytes): ?string { return $this->files[$relative] ?? null; }
    public function pin(string $relative, string $bytes, int $maxBytes): string
    { if (isset($this->files[$relative]) && $this->files[$relative] !== $bytes) throw new RuntimeException('conflict'); return $this->files[$relative] = $bytes; }
    public function cow(string $relative, string $bytes, int $maxBytes): void { $this->files[$relative] = $bytes; }
    public function refreshBinding(string $relative, string $currentBytes, string $candidateBytes): void { $this->files[$relative] = $candidateBytes; }
    public function refreshActiveClaim(string $currentBytes, string $candidateBytes): void { $this->files['active-run.json'] = $candidateBytes; }
    public function clearActiveClaim(string $expectedBytes): void { unset($this->files['active-run.json']); }
    public function reservedCandidates(): iterable { return []; }
}

final class AdmissionSystemAdapterFake implements HostRunnerSystemAdapter
{
    public array $calls = [];
    public function __construct(private array $results) {}
    public function run(array $argv, array $environment, int $timeoutSeconds): HostRunnerProcessResult
    { $this->calls[] = $argv; return array_shift($this->results); }
}

final class AdmissionBootReaderFake implements HostRunnerBootReader
{
    public function __construct(private readonly string $bytes) {}
    public function read(): string { return $this->bytes; }
}

final class AdmissionClockFake implements HostRunnerClock
{
    public function __construct(private readonly string $now) {}
    public function nowUtc(): string { return $this->now; }
}

final class AdmissionScriptReaderFake implements HostRunnerDeployScriptReader
{
    public function __construct(private readonly string $bytes) {}
    public function read(): string { return $this->bytes; }
}

final class AdmissionNonceSourceFake implements HostRunnerLaunchNonceSource
{
    public function __construct(private readonly string $value) {}
    public function bytes(): string { return $this->value; }
}
