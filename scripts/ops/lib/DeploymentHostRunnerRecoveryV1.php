<?php

declare(strict_types=1);

namespace Ops;

use RuntimeException;

require_once __DIR__ . '/DeploymentHostRunnerAdmissionV1.php';

final class HostRunnerRecoveryAdmission
{
    private readonly HostRunnerStartOrchestrator $start;
    private readonly HostRunnerBootReader $bootReader;
    private readonly HostRunnerClock $clock;

    public function __construct(
        private readonly HostRunnerStorage $storage,
        ?HostRunnerStartOrchestrator $start = null,
        private readonly HostRunnerDeployScriptReader $scriptReader = new HelperBackedHostRunnerDeployScriptReader(),
        private readonly HostRunnerLaunchNonceSource $nonceSource = new SystemHostRunnerLaunchNonceSource(),
        ?HostRunnerBootReader $bootReader = null,
        ?HostRunnerClock $clock = null,
    ) {
        $this->bootReader = $bootReader ?? new HelperBackedHostRunnerBootReader();
        $this->clock = $clock ?? new SystemHostRunnerClock();
        $this->start = $start ?? new HostRunnerStartOrchestrator(
            new HostRunnerReservationPersistence($storage, null, $this->clock),
            new DeploymentHostRunnerV1(new HelperBackedHostRunnerSystemAdapter()),
            $this->bootReader,
        );
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $input @return array<string,mixed> */
    public function admit(array $request, array $input): array
    {
        $runId = $request['run_id'] ?? '';
        $prefix = 'runs/' . $runId . '/';
        $originalBytes = $this->required($prefix . 'request.json', 16_384);
        $original = DeploymentHostRunnerContractV1::decodeDeployRequest($originalBytes);
        DeploymentHostRunnerContractV1::validateRecoveryExecutionBundle($request, $original, $input);
        $eventsBytes = $this->required($prefix . 'events.jsonl', 1_048_576);
        $stateBytes = $this->required($prefix . 'state.json', 4_096);
        $state = DeploymentHostRunnerContractV1::decodeState($stateBytes);
        if ($state['state'] === 'rollback_running') {
            $claimBytes = $this->required('active-run.json', 4_096);
            $this->start->resumeReserved($runId, $eventsBytes, $claimBytes, $stateBytes);
            return self::response($request, 'attach_observe_only', 'rollback_running');
        }
        if (
            $state['state'] !== 'post_gates_running' || $state['active_action'] !== 'none' ||
            $state['post_gates']['deploy_submission_count'] !== 1 ||
            $state['post_gates']['deploy_verdict'] !== 'failed'
        ) {
            throw new RuntimeException('recovery admission requires failed deploy post-gates');
        }
        $reportBytes = $this->required($prefix . 'deploy-post-gate-report.json', 16_384);
        if (!hash_equals($state['post_gates']['deploy_report_sha256'], hash('sha256', $reportBytes))) {
            throw new RuntimeException('recovery admission report authority is inconsistent');
        }
        $lines = explode("\n", substr($eventsBytes, 0, -1));
        if (DeploymentHostRunnerContractV1::recoveryAttachmentDisposition(
            $lines,
            $request,
            $state,
            null,
            $reportBytes,
        ) !== 'accepted') {
            throw new RuntimeException('recovery admission is not accepted by the frozen contract');
        }
        $script = $this->scriptReader->read();
        $launch = DeploymentHostRunnerContractV1::createSystemdLaunch(
            $input, $request, $original, $script, fn(): string => $this->nonceSource->bytes(),
        );
        $bootBytes = $this->bootReader->read();
        $binding = [
            'schema' => DeploymentHostRunnerContractV1::UNIT_BINDING_SCHEMA,
            'run_id' => $runId, 'intent_sha256' => $request['intent_sha256'], 'action' => 'rollback',
            'unit_name' => $launch['unit_name'],
            'unit_launch_sha256' => hash('sha256', DeploymentHostRunnerContractV1::encodeFile($launch)),
            'unit_manager_boot_id' => DeploymentHostRunnerContractV1::parseManagerBootId($bootBytes),
            'unit_invocation_id' => null, 'binding_state' => 'reserved',
        ];
        DeploymentHostRunnerContractV1::validateUnitBinding($binding);
        $recordedAtUtc = $this->clock->nowUtc();
        $lines[] = DeploymentContractV1::canonicalJson([
            'schema' => DeploymentContractV1::RUN_SCHEMA, 'record_type' => 'transition',
            'run_id' => $runId, 'sequence' => count($lines) + 1, 'recorded_at_utc' => $recordedAtUtc,
            'previous_state' => 'post_gates_running', 'state' => 'rollback_running',
            'deploy_invocation_count' => 1, 'intent_sha256' => $request['intent_sha256'],
            'exit_code' => 0, 'reason' => 'ok',
        ]);
        $candidateEvents = implode("\n", $lines) . "\n";
        $candidate = $state;
        $candidate['state'] = 'rollback_running';
        $candidate['sequence'] = count($lines);
        $candidate['events_sha256'] = hash('sha256', $candidateEvents);
        $candidate['active_action'] = 'rollback';
        $candidate['rollback'] = [
            'request_sha256' => hash('sha256', DeploymentHostRunnerContractV1::encodeFile($request)),
            'execution_input_sha256' => hash('sha256', DeploymentHostRunnerContractV1::encodeExecutionInput($input)),
            'invocation_count' => 1, 'unit_name' => $launch['unit_name'],
            'unit_launch_sha256' => hash('sha256', DeploymentHostRunnerContractV1::encodeFile($launch)),
            'unit_manager_boot_id' => $binding['unit_manager_boot_id'], 'unit_invocation_id' => null,
            'unit_missing_observed_boot_id' => null, 'unit_state' => 'starting',
            'observed_exit_code' => null, 'verdict' => 'unknown',
        ];
        $candidate['updated_at_utc'] = $recordedAtUtc;
        DeploymentHostRunnerContractV1::validateStateEvolution($state, $candidate);
        $claim = [
            'schema' => DeploymentHostRunnerContractV1::ACTIVE_RUN_SCHEMA,
            'run_id' => $runId, 'intent_sha256' => $request['intent_sha256'], 'state' => 'rollback_running',
            'sequence' => count($lines), 'events_sha256' => hash('sha256', $candidateEvents),
            'claimed_at_utc' => $recordedAtUtc,
        ];
        $disposition = $this->start->persistThenAdmit(
            $runId, $candidateEvents, DeploymentHostRunnerContractV1::encodeFile($claim),
            DeploymentHostRunnerContractV1::encodeFile($candidate), $launch, $binding,
            $input, $request, $original, $script, $reportBytes,
        );
        if (in_array($disposition, ['observe_only', 'observe_only_reconciliation_required'], true)) {
            return self::response($request, 'accepted', 'rollback_running');
        }
        if ($disposition === 'collision') {
            return self::response($request, 'rejected', null, 75, 'state_conflict');
        }
        return self::response($request, 'rejected', null, 70, 'contract_invalid');
    }

    private function required(string $relative, int $limit): string
    {
        return $this->storage->read($relative, $limit) ?? throw new RuntimeException('recovery admission authority is incomplete');
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private static function response(
        array $request, string $disposition, ?string $state,
        int $exitCode = 0, string $reason = 'ok',
    ): array {
        $response = [
            'schema' => DeploymentHostRunnerContractV1::RESPONSE_SCHEMA,
            'run_id' => $request['run_id'], 'intent_sha256' => $request['intent_sha256'],
            'action' => 'recovery', 'disposition' => $disposition, 'state' => $state,
            'result_exit_code' => $exitCode, 'result_reason' => $reason,
        ];
        DeploymentHostRunnerContractV1::validateResponse($response);
        return $response;
    }
}
