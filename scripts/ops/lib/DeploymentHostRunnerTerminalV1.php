<?php

declare(strict_types=1);

namespace Ops;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/DeployTimingSampleValidator.php';
require_once __DIR__ . '/DeploymentEvidenceAuthorityV1.php';
require_once __DIR__ . '/DeploymentHostRunnerPredeployV1.php';

interface HostRunnerTimingPin
{
    /** @return array{status:string,bytes:string,sha256:?string} */
    public function pin(string $timingRunId, string $runId): array;
}

final class HelperBackedHostRunnerTimingPin implements HostRunnerTimingPin
{
    private const COMMAND_PREFIX = [
        '/usr/bin/env',
        '-i',
        'LANG=C',
        'LC_ALL=C',
        'PATH=/usr/sbin:/usr/bin:/sbin:/bin',
        '/usr/bin/python3',
        '-I',
        '-B',
        __DIR__ . '/../libexec/pin_deploy_timing_v1.py',
    ];

    public function pin(string $timingRunId, string $runId): array
    {
        $pipes = [];
        $process = proc_open(
            [...self::COMMAND_PREFIX, $timingRunId, $runId],
            [
                ['file', '/dev/null', 'r'],
                ['pipe', 'w'],
                ['file', '/dev/null', 'w'],
                198 => ['file', '/dev/null', 'r'],
                199 => ['file', '/dev/null', 'r'],
            ],
            $pipes,
            null,
            [],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('deploy timing pin helper is unavailable');
        }
        stream_set_blocking($pipes[1], false);
        $stdout = '';
        $deadline = microtime(true) + 10.0;
        $status = proc_get_status($process);
        while ($status['running'] && microtime(true) < $deadline) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            if (strlen($stdout) > 1_500_000) {
                proc_terminate($process, 9);
                break;
            }
            usleep(10_000);
            $status = proc_get_status($process);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        if ($status['running']) {
            proc_terminate($process, 9);
        }
        fclose($pipes[1]);
        $exit = proc_close($process);
        if ($exit === -1) {
            $exit = $status['exitcode'];
        }
        if ($exit !== 0 || strlen($stdout) > 1_500_000) {
            throw new RuntimeException('deploy timing pin helper rejected the source');
        }
        $decoded = json_decode($stdout, true, 16, JSON_THROW_ON_ERROR);
        if (
            !is_array($decoded) ||
            array_keys($decoded) !== ['bytes_base64', 'sha256', 'status'] ||
            !in_array($decoded['status'], ['not_observed', 'pinned', 'attached'], true)
        ) {
            throw new RuntimeException('deploy timing pin response is invalid');
        }
        if ($decoded['status'] === 'not_observed') {
            if ($decoded['bytes_base64'] !== null || $decoded['sha256'] !== null) {
                throw new RuntimeException('missing deploy timing pin invented authority');
            }
            return ['status' => 'not_observed', 'bytes' => '', 'sha256' => null];
        }
        if (!is_string($decoded['bytes_base64']) || !is_string($decoded['sha256'])) {
            throw new RuntimeException('deploy timing pin response lacks bytes');
        }
        $bytes = base64_decode($decoded['bytes_base64'], true);
        if (!is_string($bytes) || !hash_equals($decoded['sha256'], hash('sha256', $bytes))) {
            throw new RuntimeException('deploy timing pin response contradicts exact bytes');
        }
        return ['status' => $decoded['status'], 'bytes' => $bytes, 'sha256' => $decoded['sha256']];
    }
}

interface HostRunnerTerminalizer
{
    /** @return array<string,mixed> */
    public function resumeTerminal(string $runId, string $action = 'deploy'): array;

    /** @return array<string,mixed> */
    public function terminalizeDeploy(string $runId): array;

    /** @return array<string,mixed> */
    public function terminalizeUnverifiableDeploy(string $runId): array;

    /** @return array<string,mixed> */
    public function terminalizeRollback(string $runId): array;
}

final class HostRunnerTerminalPersistence implements HostRunnerTerminalizer
{
    private const ORCHESTRATOR_FINISH_SCHEMA = 'deployment_orchestrator_finish.v1';

    public function __construct(
        private readonly HostRunnerStorage $storage,
        private readonly HostRunnerOrchestratorClock $clock = new SystemHostRunnerOrchestratorClock(),
        private readonly HostRunnerTimingPin $timingPin = new HelperBackedHostRunnerTimingPin(),
    ) {}

    /**
     * Return an already durable terminal result only after revalidating the
     * exact journal, evidence, reports, and every reserved unit bundle.
     *
     * @return array<string,mixed> canonical runner response
     */
    public function resumeTerminal(string $runId, string $action = 'deploy'): array
    {
        $prefix = 'runs/' . $runId . '/';
        $state = DeploymentHostRunnerContractV1::decodeState($this->required($prefix . 'state.json', 4_096));
        if (
            $state['run_id'] !== $runId ||
            !in_array($state['state'], ['succeeded', ...DeploymentContractV1::TERMINAL_FAILURE_STATES], true)
        ) {
            throw new RuntimeException('durable terminal replay state is inconsistent');
        }

        $reports = [];
        foreach (['deploy', 'rollback'] as $subject) {
            $reports[$subject] =
                $state['post_gates'][$subject . '_submission_count'] === 0
                    ? null
                    : $this->required($prefix . $subject . '-post-gate-report.json', 16_384);
        }
        $units = [];
        foreach (['deploy', 'rollback'] as $unitAction) {
            if ($state[$unitAction]['invocation_count'] === 0) {
                continue;
            }
            $units[$unitAction] = [
                'launch' => $this->required($prefix . $unitAction . '-systemd-launch.json', 16_384),
                'binding' => $this->required($prefix . $unitAction . '-unit-binding.json', 16_384),
                'observation' => $this->required($prefix . $unitAction . '-unit-observation.json', 65_536),
            ];
        }
        if (
            DeploymentHostRunnerContractV1::terminalStateCacheDisposition(
                $state,
                $this->required($prefix . 'events.jsonl', 1_048_576),
                $this->required($prefix . 'evidence.json', 1_048_576),
                $reports['deploy'],
                $reports['rollback'],
                $units,
            ) !== 'current'
        ) {
            throw new RuntimeException('durable terminal replay bundle is not current');
        }
        $this->clearTerminalClaimIfPresent($runId, $state['intent_sha256']);
        return $this->terminalResponse($state, $action);
    }

    /**
     * Terminalize either a known non-success deploy receipt or a successful
     * deploy with a passed exact post-gate report.
     *
     * @return array<string,mixed> canonical runner response
     */
    public function terminalizeDeploy(string $runId): array
    {
        $prefix = 'runs/' . $runId . '/';
        $eventsBytes = $this->required($prefix . 'events.jsonl', 1_048_576);
        $stateBytes = $this->required($prefix . 'state.json', 4_096);
        $predeployBytes = $this->required($prefix . 'predeploy-evidence.json', 65_536);
        $receiptBytes = $this->required($prefix . 'deploy-result.json', 4_096);
        $launchBytes = $this->required($prefix . 'deploy-systemd-launch.json', 16_384);
        $bindingBytes = $this->required($prefix . 'deploy-unit-binding.json', 16_384);
        $observationBytes = $this->required($prefix . 'deploy-unit-observation.json', 65_536);
        $startBytes = $this->required($prefix . 'orchestrator-start.json', 4_096);
        $state = DeploymentHostRunnerContractV1::decodeState($stateBytes);
        $receipt = DeployResultV1::decode($receiptBytes);
        $launch = DeploymentHostRunnerContractV1::decodeSystemdLaunch($launchBytes);
        $binding = DeploymentHostRunnerContractV1::decodeUnitBinding($bindingBytes);
        $observation = DeploymentHostRunnerContractV1::decodeUnitLoadedObservation($observationBytes, $launch);
        DeploymentHostRunnerContractV1::validateUnitReconciliationBundle($launch, $binding, $state, $observation);
        if (
            $state['run_id'] !== $runId ||
            !hash_equals($state['intent_sha256'], $launch['intent_sha256']) ||
            !in_array($state['deploy']['unit_state'], ['exited', 'failed'], true) ||
            $state['deploy']['observed_exit_code'] !== $receipt['exit_code'] ||
            $state['deploy']['unit_invocation_id'] === null ||
            $launch['timing_run_id'] === null
        ) {
            throw new RuntimeException('terminal deploy authority is inconsistent');
        }

        $predeploy = json_decode($predeployBytes, true, 64, JSON_THROW_ON_ERROR);
        if (
            !is_array($predeploy) ||
            array_is_list($predeploy) ||
            DeploymentEvidenceAuthorityV1::encodeFile($predeploy) !== $predeployBytes ||
            ($predeploy['schema'] ?? null) !== DeploymentEvidenceAuthorityV1::PREDEPLOY_ASSEMBLY_SCHEMA ||
            ($predeploy['status'] ?? null) !== 'passed' ||
            ($predeploy['exit_code'] ?? null) !== 0 ||
            ($predeploy['reason'] ?? null) !== 'ok' ||
            !isset($predeploy['sections']) ||
            !is_array($predeploy['sections'])
        ) {
            throw new RuntimeException('terminal deploy lacks passed predeploy evidence');
        }
        DeploymentContractV1::validatePredeploySections($predeploy['sections']);

        if (in_array($state['state'], ['succeeded', ...DeploymentContractV1::TERMINAL_FAILURE_STATES], true)) {
            return $this->resumeTerminalDeploy(
                $runId,
                $state,
                $eventsBytes,
                $this->required($prefix . 'evidence.json', 1_048_576),
                $launchBytes,
                $bindingBytes,
                $observationBytes,
                $prefix,
            );
        }

        [$terminalStateName, $terminalExit, $terminalReason, $postGates] = $this->terminalResult(
            $state,
            $receipt,
            $prefix,
        );
        $existingEvidenceBytes = $this->storage->read($prefix . 'evidence.json', 1_048_576);

        $timing = $this->timingPin->pin($launch['timing_run_id'], $runId);
        $timingSection = $this->timingSection($timing, $launch['timing_run_id']);
        $finish = $this->loadOrPinFinish($prefix, $runId);
        $observedAtUtc = $finish['finished_at_utc'];
        $childObservation = [
            'schema' => DeploymentEvidenceAuthorityV1::CHILD_OBSERVATION_SCHEMA,
            'run_id' => $runId,
            'intent_sha256' => $state['intent_sha256'],
            'timing' => $timingSection,
            'receipt_sha256' => hash('sha256', $receiptBytes),
            'artifact_sha256' => $predeploy['sections']['artifact']['remote_sha256'],
            'unit_launch_sha256' => hash('sha256', $launchBytes),
            'manager_boot_id' => $state['deploy']['unit_manager_boot_id'],
            'unit_invocation_id' => $state['deploy']['unit_invocation_id'],
            'exit_code' => $receipt['exit_code'],
            'observed_at_utc' => $observedAtUtc,
        ];
        $childObservationBytes = DeploymentEvidenceAuthorityV1::encodeFile($childObservation);
        DeploymentEvidenceAuthorityV1::decodeChildObservation(
            $childObservationBytes,
            $runId,
            $state['intent_sha256'],
            $launch['timing_run_id'],
            $receiptBytes,
            $timing['bytes'],
            $predeploy['sections']['artifact']['remote_sha256'],
            hash('sha256', $launchBytes),
            $state['deploy']['unit_manager_boot_id'],
            $state['deploy']['unit_invocation_id'],
            $receipt['exit_code'],
            $observedAtUtc,
        );
        $this->storage->pin($prefix . 'deploy-child-observation.json', $childObservationBytes, 65_536);

        $start = json_decode($startBytes, true, 16, JSON_THROW_ON_ERROR);
        if (
            !is_array($start) ||
            array_is_list($start) ||
            DeploymentHostRunnerContractV1::encodeFile($start) !== $startBytes
        ) {
            throw new RuntimeException('terminal orchestrator start is invalid');
        }
        $orchestratorTiming = DeploymentEvidenceAuthorityV1::finishOrchestratorTiming(
            $start,
            $observedAtUtc,
            $finish['boot_id'],
            $finish['monotonic_ns'],
            $terminalStateName === 'succeeded',
        );
        $lines = explode("\n", substr($eventsBytes, 0, -1));
        $run = DeploymentContractV1::validateRunLines($lines);
        $cacheDisposition = DeploymentHostRunnerContractV1::stateCacheDisposition($state, $eventsBytes);
        if ($run['state'] === $state['state'] && $cacheDisposition === 'current') {
            $lines[] = DeploymentContractV1::canonicalJson([
                'schema' => DeploymentContractV1::RUN_SCHEMA,
                'record_type' => 'transition',
                'run_id' => $runId,
                'sequence' => count($lines) + 1,
                'recorded_at_utc' => $observedAtUtc,
                'previous_state' => $state['state'],
                'state' => $terminalStateName,
                'deploy_invocation_count' => 1,
                'intent_sha256' => $state['intent_sha256'],
                'exit_code' => $terminalExit,
                'reason' => $terminalReason,
            ]);
        } elseif ($run['state'] === $terminalStateName && $cacheDisposition === 'stale_recoverable') {
            $last = json_decode($lines[array_key_last($lines)], true, 32, JSON_THROW_ON_ERROR);
            if (
                !is_array($last) ||
                ($last['previous_state'] ?? null) !== $state['state'] ||
                ($last['exit_code'] ?? null) !== $terminalExit ||
                ($last['reason'] ?? null) !== $terminalReason
            ) {
                throw new RuntimeException('terminal deploy journal-ahead prefix is inconsistent');
            }
        } else {
            throw new RuntimeException('terminal deploy state is not current');
        }
        $candidateEventsBytes = implode("\n", $lines) . "\n";
        $evidence = [
            'schema' => DeploymentContractV1::EVIDENCE_SCHEMA,
            'run_id' => $runId,
            'intent_sha256' => $state['intent_sha256'],
            'captured_at_utc' => $observedAtUtc,
            ...$predeploy['sections'],
            'deploy' => DeployResultV1::deployEvidence($receipt['outcome']),
            'rollback' => [
                'status' => 'not_invoked',
                'invocation_count' => 0,
                'mode' => 'not_applicable',
                'verified' => null,
            ],
            'post_gates' => $postGates,
            'deploy_timing' => $timingSection,
            'orchestrator_timing' => $orchestratorTiming,
            'result' => ['state' => $terminalStateName, 'exit_code' => $terminalExit, 'reason' => $terminalReason],
        ];
        DeploymentContractV1::validateBundle($lines, $evidence);
        $evidenceBytes = DeploymentHostRunnerContractV1::encodeFile($evidence);
        if ($existingEvidenceBytes !== null && !hash_equals($existingEvidenceBytes, $evidenceBytes)) {
            throw new RuntimeException('terminal evidence conflicts with durable candidate bytes');
        }
        $candidateState = $state;
        $candidateState['state'] = $terminalStateName;
        $candidateState['sequence'] = count($lines);
        $candidateState['events_sha256'] = hash('sha256', $candidateEventsBytes);
        $candidateState['active_action'] = 'none';
        $candidateState['deploy']['receipt_sha256'] = hash('sha256', $receiptBytes);
        $candidateState['evidence_sha256'] = hash('sha256', $evidenceBytes);
        $candidateState['terminal'] = [
            'state' => $terminalStateName,
            'exit_code' => $terminalExit,
            'reason' => $terminalReason,
        ];
        $candidateState['updated_at_utc'] = $observedAtUtc;
        DeploymentHostRunnerContractV1::validateStateEvolution($state, $candidateState);

        $this->storage->cow($prefix . 'evidence.json', $evidenceBytes, 1_048_576);
        $this->storage->cow($prefix . 'events.jsonl', $candidateEventsBytes, 1_048_576);
        $this->storage->cow($prefix . 'evidence.json', $evidenceBytes, 1_048_576);
        $this->storage->cow($prefix . 'state.json', DeploymentHostRunnerContractV1::encodeFile($candidateState), 4_096);
        if (
            DeploymentHostRunnerContractV1::terminalStateCacheDisposition(
                $candidateState,
                $candidateEventsBytes,
                $evidenceBytes,
                $postGates['status'] === 'not_observed'
                    ? null
                    : $this->required($prefix . 'deploy-post-gate-report.json', 16_384),
                null,
                [
                    'deploy' => [
                        'launch' => $launchBytes,
                        'binding' => $bindingBytes,
                        'observation' => $observationBytes,
                    ],
                ],
            ) !== 'current'
        ) {
            throw new RuntimeException('terminal deploy persistence did not produce a current bundle');
        }
        $this->clearTerminalClaimIfPresent($runId, $state['intent_sha256']);
        return $this->terminalResponse($candidateState);
    }

    /**
     * Freeze a reserved deploy whose stopped/missing unit cannot be bound to a
     * valid receipt. The active claim intentionally remains: unknown execution
     * authority must never become permission for another deployment.
     *
     * @return array<string,mixed> canonical runner response
     */
    public function terminalizeUnverifiableDeploy(string $runId): array
    {
        $prefix = 'runs/' . $runId . '/';
        $eventsBytes = $this->required($prefix . 'events.jsonl', 1_048_576);
        $state = DeploymentHostRunnerContractV1::decodeState($this->required($prefix . 'state.json', 4_096));
        $claimBytes = $this->required('active-run.json', 4_096);
        $claim = DeploymentHostRunnerContractV1::decodeActiveRun($claimBytes);
        $predeployBytes = $this->required($prefix . 'predeploy-evidence.json', 65_536);
        $launchBytes = $this->required($prefix . 'deploy-systemd-launch.json', 16_384);
        $bindingBytes = $this->required($prefix . 'deploy-unit-binding.json', 16_384);
        $observationBytes = $this->required($prefix . 'deploy-unit-observation.json', 65_536);
        $startBytes = $this->required($prefix . 'orchestrator-start.json', 4_096);
        if (in_array($state['state'], DeploymentContractV1::TERMINAL_FAILURE_STATES, true)) {
            return $this->resumeTerminalDeploy(
                $runId,
                $state,
                $eventsBytes,
                $this->required($prefix . 'evidence.json', 1_048_576),
                $launchBytes,
                $bindingBytes,
                $observationBytes,
                $prefix,
                $claimBytes,
                $claim,
            );
        }
        if (
            $state['state'] !== 'deploy_running' ||
            $state['active_action'] !== 'deploy' ||
            !in_array($state['deploy']['unit_state'], ['exited', 'failed', 'killed', 'missing', 'unknown'], true) ||
            $claim['run_id'] !== $runId ||
            $state['run_id'] !== $runId ||
            !hash_equals($claim['intent_sha256'], $state['intent_sha256']) ||
            $claim['state'] !== $state['state'] ||
            $claim['sequence'] !== $state['sequence'] ||
            !hash_equals($claim['events_sha256'], $state['events_sha256'])
        ) {
            throw new RuntimeException('unverifiable terminal deploy requires a stopped or absent reservation');
        }
        $predeploy = json_decode($predeployBytes, true, 64, JSON_THROW_ON_ERROR);
        if (
            !is_array($predeploy) ||
            array_is_list($predeploy) ||
            DeploymentEvidenceAuthorityV1::encodeFile($predeploy) !== $predeployBytes ||
            ($predeploy['schema'] ?? null) !== DeploymentEvidenceAuthorityV1::PREDEPLOY_ASSEMBLY_SCHEMA ||
            ($predeploy['status'] ?? null) !== 'passed' ||
            !is_array($predeploy['sections'] ?? null)
        ) {
            throw new RuntimeException('unverifiable deploy lacks passed predeploy evidence');
        }
        DeploymentContractV1::validatePredeploySections($predeploy['sections']);
        $start = json_decode($startBytes, true, 16, JSON_THROW_ON_ERROR);
        if (
            !is_array($start) ||
            array_is_list($start) ||
            DeploymentHostRunnerContractV1::encodeFile($start) !== $startBytes
        ) {
            throw new RuntimeException('unverifiable deploy orchestrator start is invalid');
        }
        $finish = $this->loadOrPinFinish($prefix, $runId);
        $recordedAtUtc = $finish['finished_at_utc'];
        $orchestratorTiming = DeploymentEvidenceAuthorityV1::finishOrchestratorTiming(
            $start,
            $recordedAtUtc,
            $finish['boot_id'],
            $finish['monotonic_ns'],
            false,
        );
        $interrupted = $state['deploy']['unit_state'] === 'killed';
        $exitCode = $interrupted ? 143 : 70;
        $reason = $interrupted ? 'interrupted' : 'contract_invalid';
        $lines = explode("\n", substr($eventsBytes, 0, -1));
        $run = DeploymentContractV1::validateRunLines($lines);
        if (
            $run['state'] !== 'deploy_running' ||
            DeploymentHostRunnerContractV1::stateCacheDisposition($state, $eventsBytes) !== 'current'
        ) {
            throw new RuntimeException('unverifiable deploy state is not current');
        }
        $lines[] = DeploymentContractV1::canonicalJson([
            'schema' => DeploymentContractV1::RUN_SCHEMA,
            'record_type' => 'transition',
            'run_id' => $runId,
            'sequence' => count($lines) + 1,
            'recorded_at_utc' => $recordedAtUtc,
            'previous_state' => 'deploy_running',
            'state' => 'manual_recovery_required',
            'deploy_invocation_count' => 1,
            'intent_sha256' => $state['intent_sha256'],
            'exit_code' => $exitCode,
            'reason' => $reason,
        ]);
        $candidateEvents = implode("\n", $lines) . "\n";
        $evidence = [
            'schema' => DeploymentContractV1::EVIDENCE_SCHEMA,
            'run_id' => $runId,
            'intent_sha256' => $state['intent_sha256'],
            'captured_at_utc' => $recordedAtUtc,
            ...$predeploy['sections'],
            'deploy' => [
                'status' => 'unknown',
                'invocation_count' => 1,
                'exit_code' => null,
                'rollback_outcome' => 'not_observed',
            ],
            'rollback' => [
                'status' => 'not_invoked',
                'invocation_count' => 0,
                'mode' => 'not_applicable',
                'verified' => null,
            ],
            'post_gates' => $this->notObservedPostGates(),
            'deploy_timing' => [
                'status' => 'not_observed',
                'authoritative_sha256' => null,
                'run_id' => null,
                'total_ms' => null,
            ],
            'orchestrator_timing' => $orchestratorTiming,
            'result' => ['state' => 'manual_recovery_required', 'exit_code' => $exitCode, 'reason' => $reason],
        ];
        DeploymentContractV1::validateBundle($lines, $evidence);
        $evidenceBytes = DeploymentHostRunnerContractV1::encodeFile($evidence);
        $candidate = $state;
        $candidate['state'] = 'manual_recovery_required';
        $candidate['sequence'] = count($lines);
        $candidate['events_sha256'] = hash('sha256', $candidateEvents);
        $candidate['active_action'] = 'none';
        $candidate['evidence_sha256'] = hash('sha256', $evidenceBytes);
        $candidate['terminal'] = ['state' => 'manual_recovery_required', 'exit_code' => $exitCode, 'reason' => $reason];
        $candidate['updated_at_utc'] = $recordedAtUtc;
        DeploymentHostRunnerContractV1::validateStateEvolution($state, $candidate);
        $this->storage->cow($prefix . 'evidence.json', $evidenceBytes, 1_048_576);
        $this->storage->cow($prefix . 'events.jsonl', $candidateEvents, 1_048_576);
        $this->storage->cow($prefix . 'evidence.json', $evidenceBytes, 1_048_576);
        $this->storage->cow($prefix . 'state.json', DeploymentHostRunnerContractV1::encodeFile($candidate), 4_096);
        if (
            DeploymentHostRunnerContractV1::terminalStateCacheDisposition(
                $candidate,
                $candidateEvents,
                $evidenceBytes,
                null,
                null,
                [
                    'deploy' => [
                        'launch' => $launchBytes,
                        'binding' => $bindingBytes,
                        'observation' => $observationBytes,
                    ],
                ],
            ) !== 'current'
        ) {
            throw new RuntimeException('unverifiable terminal deploy persistence is not current');
        }
        $terminalClaim = [
            'schema' => DeploymentHostRunnerContractV1::ACTIVE_RUN_SCHEMA,
            'run_id' => $runId,
            'intent_sha256' => $candidate['intent_sha256'],
            'state' => $candidate['state'],
            'sequence' => $candidate['sequence'],
            'events_sha256' => $candidate['events_sha256'],
            'claimed_at_utc' => $candidate['updated_at_utc'],
        ];
        $this->storage->refreshActiveClaim($claimBytes, DeploymentHostRunnerContractV1::encodeFile($terminalClaim));
        return $this->terminalResponse($candidate);
    }

    /** @return array<string,mixed> canonical runner response */
    public function terminalizeRollback(string $runId): array
    {
        $prefix = 'runs/' . $runId . '/';
        $eventsBytes = $this->required($prefix . 'events.jsonl', 1_048_576);
        $state = DeploymentHostRunnerContractV1::decodeState($this->required($prefix . 'state.json', 4_096));
        $predeployBytes = $this->required($prefix . 'predeploy-evidence.json', 65_536);
        $receiptBytes = $this->required($prefix . 'deploy-result.json', 4_096);
        $deployLaunchBytes = $this->required($prefix . 'deploy-systemd-launch.json', 16_384);
        $deployBindingBytes = $this->required($prefix . 'deploy-unit-binding.json', 16_384);
        $deployObservationBytes = $this->required($prefix . 'deploy-unit-observation.json', 65_536);
        $rollbackLaunchBytes = $this->required($prefix . 'rollback-systemd-launch.json', 16_384);
        $rollbackBindingBytes = $this->required($prefix . 'rollback-unit-binding.json', 16_384);
        $rollbackObservationBytes = $this->required($prefix . 'rollback-unit-observation.json', 65_536);
        $deployReportBytes = $this->required($prefix . 'deploy-post-gate-report.json', 16_384);
        $startBytes = $this->required($prefix . 'orchestrator-start.json', 4_096);
        $receipt = DeployResultV1::decode($receiptBytes);
        $deployLaunch = DeploymentHostRunnerContractV1::decodeSystemdLaunch($deployLaunchBytes);
        $deployBinding = DeploymentHostRunnerContractV1::decodeUnitBinding($deployBindingBytes);
        $deployObservation = DeploymentHostRunnerContractV1::decodeUnitLoadedObservation(
            $deployObservationBytes,
            $deployLaunch,
        );
        $rollbackLaunch = DeploymentHostRunnerContractV1::decodeSystemdLaunch($rollbackLaunchBytes);
        $rollbackBinding = DeploymentHostRunnerContractV1::decodeUnitBinding($rollbackBindingBytes);
        $rollbackObservation = DeploymentHostRunnerContractV1::decodeUnitLoadedObservation(
            $rollbackObservationBytes,
            $rollbackLaunch,
        );
        DeploymentHostRunnerContractV1::validateUnitReconciliationBundle(
            $deployLaunch,
            $deployBinding,
            $state,
            $deployObservation,
        );
        DeploymentHostRunnerContractV1::validateUnitReconciliationBundle(
            $rollbackLaunch,
            $rollbackBinding,
            $state,
            $rollbackObservation,
        );
        $deployReport = DeploymentHostRunnerContractV1::decodePostGateReport($deployReportBytes);
        $rollbackReportBytes =
            $state['post_gates']['rollback_submission_count'] === 0
                ? null
                : $this->required($prefix . 'rollback-post-gate-report.json', 16_384);
        if (in_array($state['state'], DeploymentContractV1::TERMINAL_FAILURE_STATES, true)) {
            if (
                DeploymentHostRunnerContractV1::terminalStateCacheDisposition(
                    $state,
                    $eventsBytes,
                    $this->required($prefix . 'evidence.json', 1_048_576),
                    $deployReportBytes,
                    $rollbackReportBytes,
                    [
                        'deploy' => [
                            'launch' => $deployLaunchBytes,
                            'binding' => $deployBindingBytes,
                            'observation' => $deployObservationBytes,
                        ],
                        'rollback' => [
                            'launch' => $rollbackLaunchBytes,
                            'binding' => $rollbackBindingBytes,
                            'observation' => $rollbackObservationBytes,
                        ],
                    ],
                ) !== 'current'
            ) {
                throw new RuntimeException('durable terminal rollback bundle is not current');
            }
            $this->clearTerminalClaimIfPresent($runId, $state['intent_sha256']);
            return $this->terminalResponse($state, 'recovery');
        }
        if (
            $state['run_id'] !== $runId ||
            $state['state'] !== 'rollback_running' ||
            !in_array(
                DeploymentHostRunnerContractV1::stateCacheDisposition($state, $eventsBytes),
                ['current', 'stale_recoverable'],
                true,
            ) ||
            $receipt['outcome'] !== 'succeeded' ||
            $receipt['exit_code'] !== 0 ||
            !hash_equals($state['deploy']['receipt_sha256'], hash('sha256', $receiptBytes)) ||
            $deployReport['subject'] !== 'deploy' ||
            $deployReport['post_gates']['status'] !== 'failed' ||
            !hash_equals($state['post_gates']['deploy_report_sha256'], hash('sha256', $deployReportBytes)) ||
            $state['post_gates']['deploy_verdict'] !== 'failed' ||
            !in_array($state['rollback']['verdict'], ['succeeded', 'failed'], true) ||
            $deployLaunch['timing_run_id'] === null
        ) {
            throw new RuntimeException('terminal rollback authority is inconsistent');
        }
        $rollbackPassed = $state['rollback']['verdict'] === 'succeeded';
        if ($state['rollback']['observed_exit_code'] === 0) {
            if ($rollbackReportBytes === null) {
                throw new RuntimeException('zero-exit rollback requires an exact verification report');
            }
            $rollbackReport = DeploymentHostRunnerContractV1::decodePostGateReport($rollbackReportBytes);
            if (
                $rollbackReport['subject'] !== 'rollback' ||
                ($rollbackReport['post_gates']['status'] === 'passed') !== $rollbackPassed ||
                !hash_equals($state['post_gates']['rollback_report_sha256'], hash('sha256', $rollbackReportBytes))
            ) {
                throw new RuntimeException('terminal rollback report contradicts its verdict');
            }
        } elseif ($rollbackReportBytes !== null || $rollbackPassed) {
            throw new RuntimeException('nonzero rollback exit cannot consume a verification report');
        }

        $predeploy = json_decode($predeployBytes, true, 64, JSON_THROW_ON_ERROR);
        if (
            !is_array($predeploy) ||
            array_is_list($predeploy) ||
            DeploymentEvidenceAuthorityV1::encodeFile($predeploy) !== $predeployBytes ||
            ($predeploy['schema'] ?? null) !== DeploymentEvidenceAuthorityV1::PREDEPLOY_ASSEMBLY_SCHEMA ||
            ($predeploy['status'] ?? null) !== 'passed' ||
            !is_array($predeploy['sections'] ?? null)
        ) {
            throw new RuntimeException('terminal rollback lacks passed predeploy evidence');
        }
        DeploymentContractV1::validatePredeploySections($predeploy['sections']);
        $timing = $this->timingPin->pin($deployLaunch['timing_run_id'], $runId);
        $timingSection = $this->timingSection($timing, $deployLaunch['timing_run_id']);
        $finish = $this->loadOrPinFinish($prefix, $runId);
        $observedAtUtc = $finish['finished_at_utc'];
        $childObservation = [
            'schema' => DeploymentEvidenceAuthorityV1::CHILD_OBSERVATION_SCHEMA,
            'run_id' => $runId,
            'intent_sha256' => $state['intent_sha256'],
            'timing' => $timingSection,
            'receipt_sha256' => hash('sha256', $receiptBytes),
            'artifact_sha256' => $predeploy['sections']['artifact']['remote_sha256'],
            'unit_launch_sha256' => hash('sha256', $deployLaunchBytes),
            'manager_boot_id' => $state['deploy']['unit_manager_boot_id'],
            'unit_invocation_id' => $state['deploy']['unit_invocation_id'],
            'exit_code' => 0,
            'observed_at_utc' => $observedAtUtc,
        ];
        $childObservationBytes = DeploymentEvidenceAuthorityV1::encodeFile($childObservation);
        DeploymentEvidenceAuthorityV1::decodeChildObservation(
            $childObservationBytes,
            $runId,
            $state['intent_sha256'],
            $deployLaunch['timing_run_id'],
            $receiptBytes,
            $timing['bytes'],
            $predeploy['sections']['artifact']['remote_sha256'],
            hash('sha256', $deployLaunchBytes),
            $state['deploy']['unit_manager_boot_id'],
            $state['deploy']['unit_invocation_id'],
            0,
            $observedAtUtc,
        );
        $this->storage->pin($prefix . 'deploy-child-observation.json', $childObservationBytes, 65_536);
        $start = json_decode($startBytes, true, 16, JSON_THROW_ON_ERROR);
        if (
            !is_array($start) ||
            array_is_list($start) ||
            DeploymentHostRunnerContractV1::encodeFile($start) !== $startBytes
        ) {
            throw new RuntimeException('terminal rollback orchestrator start is invalid');
        }
        $orchestratorTiming = DeploymentEvidenceAuthorityV1::finishOrchestratorTiming(
            $start,
            $observedAtUtc,
            $finish['boot_id'],
            $finish['monotonic_ns'],
            false,
        );
        $lines = explode("\n", substr($eventsBytes, 0, -1));
        $terminalStateName = $rollbackPassed
            ? 'failed_post_switch_rollback_succeeded'
            : 'failed_post_switch_rollback_failed';
        $terminalExit = $rollbackPassed ? 30 : 31;
        $terminalReason = $rollbackPassed ? 'deploy_failed' : 'rollback_failed';
        $run = DeploymentContractV1::validateRunLines($lines);
        $cacheDisposition = DeploymentHostRunnerContractV1::stateCacheDisposition($state, $eventsBytes);
        if ($run['state'] === 'rollback_running' && $cacheDisposition === 'current') {
            $lines[] = DeploymentContractV1::canonicalJson([
                'schema' => DeploymentContractV1::RUN_SCHEMA,
                'record_type' => 'transition',
                'run_id' => $runId,
                'sequence' => count($lines) + 1,
                'recorded_at_utc' => $observedAtUtc,
                'previous_state' => 'rollback_running',
                'state' => $terminalStateName,
                'deploy_invocation_count' => 1,
                'intent_sha256' => $state['intent_sha256'],
                'exit_code' => $terminalExit,
                'reason' => $terminalReason,
            ]);
        } elseif ($run['state'] === $terminalStateName && $cacheDisposition === 'stale_recoverable') {
            $last = json_decode($lines[array_key_last($lines)], true, 32, JSON_THROW_ON_ERROR);
            if (
                !is_array($last) ||
                ($last['previous_state'] ?? null) !== 'rollback_running' ||
                ($last['exit_code'] ?? null) !== $terminalExit ||
                ($last['reason'] ?? null) !== $terminalReason
            ) {
                throw new RuntimeException('terminal rollback journal-ahead prefix is inconsistent');
            }
        } else {
            throw new RuntimeException('terminal rollback journal is inconsistent');
        }
        $candidateEventsBytes = implode("\n", $lines) . "\n";
        $evidence = [
            'schema' => DeploymentContractV1::EVIDENCE_SCHEMA,
            'run_id' => $runId,
            'intent_sha256' => $state['intent_sha256'],
            'captured_at_utc' => $observedAtUtc,
            ...$predeploy['sections'],
            'deploy' => DeployResultV1::deployEvidence('succeeded'),
            'rollback' => [
                'status' => $rollbackPassed ? 'succeeded' : 'failed',
                'invocation_count' => 1,
                'mode' => 'dedicated_post_gate_recovery',
                'verified' => $rollbackPassed,
            ],
            'post_gates' => $deployReport['post_gates'],
            'deploy_timing' => $timingSection,
            'orchestrator_timing' => $orchestratorTiming,
            'result' => ['state' => $terminalStateName, 'exit_code' => $terminalExit, 'reason' => $terminalReason],
        ];
        DeploymentContractV1::validateBundle($lines, $evidence);
        $evidenceBytes = DeploymentHostRunnerContractV1::encodeFile($evidence);
        $existingEvidenceBytes = $this->storage->read($prefix . 'evidence.json', 1_048_576);
        if ($existingEvidenceBytes !== null && !hash_equals($existingEvidenceBytes, $evidenceBytes)) {
            throw new RuntimeException('terminal rollback evidence conflicts with durable candidate bytes');
        }
        $candidateState = $state;
        $candidateState['state'] = $terminalStateName;
        $candidateState['sequence'] = count($lines);
        $candidateState['events_sha256'] = hash('sha256', $candidateEventsBytes);
        $candidateState['active_action'] = 'none';
        $candidateState['evidence_sha256'] = hash('sha256', $evidenceBytes);
        $candidateState['terminal'] = [
            'state' => $terminalStateName,
            'exit_code' => $terminalExit,
            'reason' => $terminalReason,
        ];
        $candidateState['updated_at_utc'] = $observedAtUtc;
        DeploymentHostRunnerContractV1::validateStateEvolution($state, $candidateState);
        $this->storage->cow($prefix . 'evidence.json', $evidenceBytes, 1_048_576);
        $this->storage->cow($prefix . 'events.jsonl', $candidateEventsBytes, 1_048_576);
        $this->storage->cow($prefix . 'evidence.json', $evidenceBytes, 1_048_576);
        $this->storage->cow($prefix . 'state.json', DeploymentHostRunnerContractV1::encodeFile($candidateState), 4_096);
        if (
            DeploymentHostRunnerContractV1::terminalStateCacheDisposition(
                $candidateState,
                $candidateEventsBytes,
                $evidenceBytes,
                $deployReportBytes,
                $rollbackReportBytes,
                [
                    'deploy' => [
                        'launch' => $deployLaunchBytes,
                        'binding' => $deployBindingBytes,
                        'observation' => $deployObservationBytes,
                    ],
                    'rollback' => [
                        'launch' => $rollbackLaunchBytes,
                        'binding' => $rollbackBindingBytes,
                        'observation' => $rollbackObservationBytes,
                    ],
                ],
            ) !== 'current'
        ) {
            throw new RuntimeException('terminal rollback persistence did not produce a current bundle');
        }
        $this->clearTerminalClaimIfPresent($runId, $state['intent_sha256']);
        return $this->terminalResponse($candidateState, 'recovery');
    }

    /** @param array<string,mixed> $state @param array{schema:string,outcome:string,exit_code:int} $receipt @return array{string,int,string,array<string,mixed>} */
    private function terminalResult(array $state, array $receipt, string $prefix): array
    {
        if ($receipt['outcome'] === 'succeeded') {
            if ($state['state'] !== 'post_gates_running' || $state['deploy']['receipt_sha256'] === null) {
                throw new RuntimeException('successful deploy terminal requires post-gate state');
            }
            $reportBytes = $this->required($prefix . 'deploy-post-gate-report.json', 16_384);
            $report = DeploymentHostRunnerContractV1::decodePostGateReport($reportBytes);
            if (
                $report['post_gates']['status'] !== 'passed' ||
                $state['post_gates']['deploy_verdict'] !== 'passed' ||
                !hash_equals($state['post_gates']['deploy_report_sha256'], hash('sha256', $reportBytes))
            ) {
                throw new RuntimeException('successful deploy terminal requires a passed exact report');
            }
            return ['succeeded', 0, 'ok', $report['post_gates']];
        }
        if ($state['state'] !== 'deploy_running' || $state['deploy']['receipt_sha256'] !== null) {
            throw new RuntimeException('non-success deploy receipt requires the deploy-running state');
        }
        [$terminalState, $reason] = match ($receipt['outcome']) {
            'failed_pre_switch' => ['failed_pre_switch', 'deploy_failed'],
            'internal_rollback_succeeded' => ['failed_post_switch_rollback_succeeded', 'deploy_failed'],
            'rollback_failed_or_unverifiable' => ['failed_post_switch_rollback_failed', 'rollback_failed'],
            'switch_recovery_required' => ['failed_switch_recovery_required', 'switch_recovery_required'],
            'interrupted_pre_switch' => ['failed_pre_switch', 'interrupted'],
            default => throw new RuntimeException('deploy receipt outcome cannot be terminalized'),
        };
        return [$terminalState, $receipt['exit_code'], $reason, $this->notObservedPostGates()];
    }

    /** @param array{status:string,bytes:string,sha256:?string} $pin @return array<string,mixed> */
    private function timingSection(array $pin, string $timingRunId): array
    {
        if ($pin['status'] === 'not_observed') {
            return ['status' => 'not_observed', 'authoritative_sha256' => null, 'run_id' => null, 'total_ms' => null];
        }
        try {
            $timing = DeployTimingSampleValidator::validateBytes($pin['bytes']);
            if ($timing['run_id'] !== $timingRunId) {
                throw new RuntimeException('deploy timing run identity is invalid');
            }
            return [
                'status' => 'valid',
                'authoritative_sha256' => $pin['sha256'],
                'run_id' => $timing['run_id'],
                'total_ms' => $timing['total_ms'],
            ];
        } catch (Throwable) {
            return [
                'status' => 'invalid',
                'authoritative_sha256' => $pin['sha256'],
                'run_id' => null,
                'total_ms' => null,
            ];
        }
    }

    /** @return array<string,mixed> */
    private function notObservedPostGates(): array
    {
        $value = array_fill_keys(
            [
                'status',
                'kuma_healthy_count',
                'kuma_total_count',
                'runtime_config_passed',
                'services_passed',
                'endpoints_passed',
                'logs_passed',
                'scanner_passed',
                'dormant_clean_passed',
                'passed',
            ],
            null,
        );
        $value['status'] = 'not_observed';
        return $value;
    }

    /** @return array<string,mixed> */
    private function loadOrPinFinish(string $prefix, string $runId): array
    {
        $relative = $prefix . 'orchestrator-finish.json';
        $bytes = $this->storage->read($relative, 4_096);
        if ($bytes === null) {
            $finish = [
                'schema' => self::ORCHESTRATOR_FINISH_SCHEMA,
                'run_id' => $runId,
                'finished_at_utc' => $this->clock->nowUtc(),
                'boot_id' => $this->clock->bootId(),
                'monotonic_ns' => $this->clock->monotonicNs(),
            ];
            $bytes = DeploymentHostRunnerContractV1::encodeFile($finish);
            $this->storage->pin($relative, $bytes, 4_096);
            return $finish;
        }
        $finish = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        if (
            !is_array($finish) ||
            array_is_list($finish) ||
            array_keys($finish) !== ['boot_id', 'finished_at_utc', 'monotonic_ns', 'run_id', 'schema'] ||
            DeploymentHostRunnerContractV1::encodeFile($finish) !== $bytes ||
            ($finish['schema'] ?? null) !== self::ORCHESTRATOR_FINISH_SCHEMA ||
            ($finish['run_id'] ?? null) !== $runId ||
            !is_string($finish['finished_at_utc'] ?? null) ||
            !is_string($finish['boot_id'] ?? null) ||
            !is_int($finish['monotonic_ns'] ?? null) ||
            $finish['monotonic_ns'] < 0
        ) {
            throw new RuntimeException('durable orchestrator finish is invalid');
        }
        return $finish;
    }

    /** @return array<string,mixed> */
    private function resumeTerminalDeploy(
        string $runId,
        array $state,
        string $eventsBytes,
        string $evidenceBytes,
        string $launchBytes,
        string $bindingBytes,
        string $observationBytes,
        string $prefix,
        ?string $claimBytes = null,
        ?array $claim = null,
    ): array {
        $deployReportBytes =
            $state['post_gates']['deploy_submission_count'] === 0
                ? null
                : $this->required($prefix . 'deploy-post-gate-report.json', 16_384);
        if (
            DeploymentHostRunnerContractV1::terminalStateCacheDisposition(
                $state,
                $eventsBytes,
                $evidenceBytes,
                $deployReportBytes,
                null,
                [
                    'deploy' => [
                        'launch' => $launchBytes,
                        'binding' => $bindingBytes,
                        'observation' => $observationBytes,
                    ],
                ],
            ) !== 'current'
        ) {
            throw new RuntimeException('durable terminal deploy bundle is not current');
        }
        if ($state['state'] === 'manual_recovery_required') {
            if ($claimBytes === null || $claim === null) {
                throw new RuntimeException('manual recovery terminal claim is missing');
            }
            $disposition = DeploymentHostRunnerContractV1::activeRunDisposition(
                $claim,
                $state,
                $eventsBytes,
                $evidenceBytes,
                $runId,
                $state['intent_sha256'],
                $deployReportBytes,
                null,
                [
                    'deploy' => [
                        'launch' => $launchBytes,
                        'binding' => $bindingBytes,
                        'observation' => $observationBytes,
                    ],
                ],
            );
            if ($disposition === 'refresh_terminal_claim') {
                $terminalClaim = [
                    'schema' => DeploymentHostRunnerContractV1::ACTIVE_RUN_SCHEMA,
                    'run_id' => $runId,
                    'intent_sha256' => $state['intent_sha256'],
                    'state' => $state['state'],
                    'sequence' => $state['sequence'],
                    'events_sha256' => $state['events_sha256'],
                    'claimed_at_utc' => $state['updated_at_utc'],
                ];
                $this->storage->refreshActiveClaim(
                    $claimBytes,
                    DeploymentHostRunnerContractV1::encodeFile($terminalClaim),
                );
            } elseif ($disposition !== 'clear_terminal') {
                throw new RuntimeException('manual recovery terminal claim is inconsistent');
            }
        } else {
            $this->clearTerminalClaimIfPresent($runId, $state['intent_sha256']);
        }
        return $this->terminalResponse($state);
    }

    private function clearTerminalClaimIfPresent(string $runId, string $intentSha256): void
    {
        $claim = $this->storage->read('active-run.json', 4_096);
        if ($claim === null) {
            return;
        }
        $reconciler = new HostRunnerReconciliationPersistence($this->storage);
        $disposition = $reconciler->reconcileStored($runId, $intentSha256);
        if ($disposition === 'refresh_terminal_claim') {
            $disposition = $reconciler->reconcileStored($runId, $intentSha256);
        }
        if ($disposition !== 'clear_terminal') {
            throw new RuntimeException('terminal deploy claim cannot be cleared');
        }
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function terminalResponse(array $state, string $action = 'deploy'): array
    {
        $response = [
            'schema' => DeploymentHostRunnerContractV1::RESPONSE_SCHEMA,
            'run_id' => $state['run_id'],
            'intent_sha256' => $state['intent_sha256'],
            'action' => $action,
            'disposition' => 'terminal',
            'state' => $state['state'],
            'result_exit_code' => $state['terminal']['exit_code'],
            'result_reason' => $state['terminal']['reason'],
        ];
        DeploymentHostRunnerContractV1::validateResponse($response);
        return $response;
    }

    private function required(string $relative, int $maxBytes): string
    {
        $bytes = $this->storage->read($relative, $maxBytes);
        if ($bytes === null) {
            throw new RuntimeException('terminal deploy authority is incomplete');
        }
        return $bytes;
    }
}
