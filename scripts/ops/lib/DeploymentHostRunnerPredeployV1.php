<?php

declare(strict_types=1);

namespace Ops;

use RuntimeException;

require_once __DIR__ . '/DeploymentContractV1.php';
require_once __DIR__ . '/DeploymentEvidenceAuthorityV1.php';
require_once __DIR__ . '/DeploymentHostRunnerContractV1.php';
require_once __DIR__ . '/DeploymentHostRunnerV1.php';
require_once __DIR__ . '/ProtectedPredeployObservationProvider.php';

interface HostRunnerOrchestratorClock extends HostRunnerClock
{
    public function bootId(): string;
    public function monotonicNs(): int;
}

final class SystemHostRunnerOrchestratorClock implements HostRunnerOrchestratorClock
{
    public function __construct(private readonly HostRunnerBootReader $bootReader = new HelperBackedHostRunnerBootReader()) {}

    public function nowUtc(): string
    {
        return gmdate('Y-m-d\\TH:i:s\\Z');
    }

    public function bootId(): string
    {
        return rtrim($this->bootReader->read(), "\n");
    }

    public function monotonicNs(): int
    {
        $value = hrtime(true);
        if (!is_int($value) || $value < 0) {
            throw new RuntimeException('host-runner monotonic clock is unavailable');
        }
        return $value;
    }
}

/**
 * Persist the authority-backed pre-deploy lifecycle before a deploy unit may
 * be reserved. The provider remains the only source of pre-deploy evidence.
 */
final class HostRunnerPredeployOrchestrator
{
    public function __construct(
        private readonly HostRunnerStorage $storage,
        private readonly HostRunnerOrchestratorClock $clock = new SystemHostRunnerOrchestratorClock(),
    ) {}

    /**
     * @param array<string,mixed> $request
     * @param array<string,mixed> $input
     * @return array<string,mixed> canonical deployment_host_runner_response.v1
     */
    public function collect(
        array $request,
        array $input,
        ProtectedPredeployObservationProvider $provider,
    ): array {
        DeploymentHostRunnerContractV1::validateDeployExecutionBundle($request, $input);
        $runId = $request['run_id'];
        $intentSha256 = $request['intent_sha256'];
        $prefix = 'runs/' . $runId . '/';
        $this->storage->prepareRun($runId);

        $start = $this->loadOrPinStart($runId, $prefix);
        $intent = DeploymentContractV1::createIntentRecord(
            $runId,
            $start['started_at_utc'],
            $request['expected_commit'],
            $request['release_id'],
            $request['traffic_mode'],
        );
        if (!hash_equals($intent['intent_sha256'], $intentSha256)) {
            throw new RuntimeException('deploy request does not bind the canonical intent');
        }

        $this->storage->pin($prefix . 'intent.json', DeploymentHostRunnerContractV1::encodeFile($intent), 4_096);
        $this->storage->pin($prefix . 'request.json', DeploymentHostRunnerContractV1::encodeFile($request), 16_384);
        $this->storage->pin(
            $prefix . 'execution-input.json',
            DeploymentHostRunnerContractV1::encodeExecutionInput($input),
            16_384,
        );

        $assembly = DeploymentEvidenceAuthorityV1::collectPredeployEvidence(
            $provider,
            $runId,
            $intentSha256,
            $request['release_id'],
            $request['expected_commit'],
            $request['traffic_mode'],
        );
        $assemblyBytes = DeploymentEvidenceAuthorityV1::encodeFile($assembly);
        $this->storage->pin($prefix . 'predeploy-evidence.json', $assemblyBytes, 65_536);

        $baseStates = ['built', 'uploaded', 'accepted', 'lock_acquired'];
        $verifiedStates = [
            'expected_commit_verified',
            'traffic_gate_passed',
            'dump_verified',
            'capacity_passed',
            'artifact_verified',
        ];
        $lastVerified = $assembly['status'] === 'passed'
            ? 'artifact_verified'
            : match ($assembly['reason']) {
                'expected_commit_mismatch' => 'lock_acquired',
                'traffic_hard_stop', 'traffic_evidence_invalid' => 'expected_commit_verified',
                'dump_verification_failed' => 'traffic_gate_passed',
                'capacity_gate_failed' => 'dump_verified',
                'artifact_verification_failed' => 'capacity_passed',
                default => throw new RuntimeException('predeploy authority returned an unsupported result'),
            };
        $states = $baseStates;
        foreach ($verifiedStates as $state) {
            if ($states[array_key_last($states)] === $lastVerified) {
                break;
            }
            $states[] = $state;
        }

        $lines = [DeploymentContractV1::canonicalJson($intent)];
        $previous = 'planned';
        foreach ($states as $state) {
            $lines[] = $this->transition($request, count($lines) + 1, $previous, $state, $start['started_at_utc']);
            $previous = $state;
        }

        if ($assembly['status'] === 'passed') {
            $eventsBytes = implode("\n", $lines) . "\n";
            $state = $this->stateForJournal($request, $eventsBytes, $previous, $start['started_at_utc']);
            $this->persistJournalAndState($prefix, $eventsBytes, $state);
            return $this->response($request, 'attach_pre_deploy', $previous, 0, 'ok');
        }

        $existingEvidenceBytes = $this->storage->read($prefix . 'evidence.json', 1_048_576);
        if ($existingEvidenceBytes === null) {
            $finishedAtUtc = $this->clock->nowUtc();
            $orchestratorTiming = DeploymentEvidenceAuthorityV1::finishOrchestratorTiming(
                $start,
                $finishedAtUtc,
                $this->clock->bootId(),
                $this->clock->monotonicNs(),
                false,
            );
            $terminalRecordedAtUtc = $finishedAtUtc;
            $capturedAtUtc = $finishedAtUtc;
            $evidence = $this->failedBeforeWriteEvidence(
                $request,
                $assembly,
                $capturedAtUtc,
                $orchestratorTiming,
            );
            $evidenceBytes = DeploymentHostRunnerContractV1::encodeFile($evidence);
        } else {
            $evidence = json_decode($existingEvidenceBytes, true, 64, JSON_THROW_ON_ERROR);
            if (
                !is_array($evidence) ||
                array_is_list($evidence) ||
                !hash_equals(DeploymentHostRunnerContractV1::encodeFile($evidence), $existingEvidenceBytes)
            ) {
                throw new RuntimeException('durable predeploy terminal evidence is not canonical');
            }
            DeploymentContractV1::validateEvidence($evidence);
            if (
                $evidence['run_id'] !== $runId ||
                !hash_equals($evidence['intent_sha256'], $intentSha256) ||
                $evidence['result']['state'] !== 'failed_before_write' ||
                $evidence['result']['exit_code'] !== $assembly['exit_code'] ||
                $evidence['result']['reason'] !== $assembly['reason'] ||
                $evidence['expected_commit'] != $assembly['sections']['expected_commit'] ||
                $evidence['traffic_gate'] != $assembly['sections']['traffic_gate'] ||
                $evidence['dump'] != $assembly['sections']['dump'] ||
                $evidence['capacity'] != $assembly['sections']['capacity'] ||
                $evidence['artifact'] != $assembly['sections']['artifact']
            ) {
                throw new RuntimeException('durable predeploy terminal evidence conflicts with current authority');
            }
            $evidenceBytes = $existingEvidenceBytes;
            $terminalRecordedAtUtc = $evidence['orchestrator_timing']['finished_at_utc'];
        }

        $lines[] = $this->transition(
            $request,
            count($lines) + 1,
            $previous,
            'failed_before_write',
            $terminalRecordedAtUtc,
            $assembly['exit_code'],
            $assembly['reason'],
        );
        DeploymentContractV1::validateBundle($lines, $evidence);
        $eventsBytes = implode("\n", $lines) . "\n";
        $terminalState = $this->stateForJournal(
            $request,
            $eventsBytes,
            'failed_before_write',
            $terminalRecordedAtUtc,
            hash('sha256', $evidenceBytes),
            $assembly['exit_code'],
            $assembly['reason'],
        );

        // Candidate evidence is durable before the authoritative terminal event.
        $this->storage->cow($prefix . 'evidence.json', $evidenceBytes, 1_048_576);
        $this->storage->cow($prefix . 'events.jsonl', $eventsBytes, 1_048_576);
        $this->storage->cow($prefix . 'evidence.json', $evidenceBytes, 1_048_576);
        $this->storage->cow(
            $prefix . 'state.json',
            DeploymentHostRunnerContractV1::encodeFile($terminalState),
            4_096,
        );
        return $this->response(
            $request,
            'terminal',
            'failed_before_write',
            $assembly['exit_code'],
            $assembly['reason'],
        );
    }

    /** @return array<string,mixed> */
    private function loadOrPinStart(string $runId, string $prefix): array
    {
        $existing = $this->storage->read($prefix . 'orchestrator-start.json', 4_096);
        if ($existing === null) {
            $start = [
                'schema' => DeploymentEvidenceAuthorityV1::ORCHESTRATOR_START_SCHEMA,
                'run_id' => $runId,
                'started_at_utc' => $this->clock->nowUtc(),
                'boot_id' => $this->clock->bootId(),
                'monotonic_ns' => $this->clock->monotonicNs(),
            ];
            // The authority validator supplies the closed start-record checks.
            DeploymentEvidenceAuthorityV1::finishOrchestratorTiming(
                $start,
                $start['started_at_utc'],
                $start['boot_id'],
                $start['monotonic_ns'],
                false,
            );
            $this->storage->pin(
                $prefix . 'orchestrator-start.json',
                DeploymentHostRunnerContractV1::encodeFile($start),
                4_096,
            );
            return $start;
        }
        $decoded = json_decode($existing, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded) || DeploymentHostRunnerContractV1::encodeFile($decoded) !== $existing) {
            throw new RuntimeException('durable orchestrator start is invalid');
        }
        DeploymentEvidenceAuthorityV1::finishOrchestratorTiming(
            $decoded,
            $decoded['started_at_utc'] ?? '',
            $decoded['boot_id'] ?? '',
            $decoded['monotonic_ns'] ?? -1,
            false,
        );
        if (($decoded['run_id'] ?? null) !== $runId) {
            throw new RuntimeException('durable orchestrator start has the wrong run identity');
        }
        return $decoded;
    }

    /** @param array<string,mixed> $request */
    private function transition(
        array $request,
        int $sequence,
        string $previous,
        string $state,
        string $recordedAtUtc,
        int $exitCode = 0,
        string $reason = 'ok',
    ): string {
        return DeploymentContractV1::canonicalJson([
            'schema' => DeploymentContractV1::RUN_SCHEMA,
            'record_type' => 'transition',
            'run_id' => $request['run_id'],
            'sequence' => $sequence,
            'recorded_at_utc' => $recordedAtUtc,
            'previous_state' => $previous,
            'state' => $state,
            'deploy_invocation_count' => 0,
            'intent_sha256' => $request['intent_sha256'],
            'exit_code' => $exitCode,
            'reason' => $reason,
        ]);
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function stateForJournal(
        array $request,
        string $eventsBytes,
        string $state,
        string $updatedAtUtc,
        ?string $evidenceSha256 = null,
        ?int $terminalExitCode = null,
        ?string $terminalReason = null,
    ): array {
        $run = DeploymentContractV1::validateRunLines(explode("\n", substr($eventsBytes, 0, -1)));
        $terminal = $state === 'failed_before_write';
        $value = [
            'schema' => DeploymentHostRunnerContractV1::STATE_SCHEMA,
            'run_id' => $request['run_id'],
            'intent_sha256' => $request['intent_sha256'],
            'state' => $state,
            'sequence' => $run['records'],
            'events_sha256' => hash('sha256', $eventsBytes),
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
            'evidence_sha256' => $evidenceSha256,
            'terminal' => $terminal
                ? ['state' => $state, 'exit_code' => $terminalExitCode, 'reason' => $terminalReason]
                : ['state' => null, 'exit_code' => null, 'reason' => null],
            'updated_at_utc' => $updatedAtUtc,
        ];
        DeploymentHostRunnerContractV1::validateState($value);
        if (!$terminal && DeploymentHostRunnerContractV1::stateCacheDisposition($value, $eventsBytes) !== 'current') {
            throw new RuntimeException('predeploy state does not bind its authoritative journal');
        }
        return $value;
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $assembly @param array<string,mixed> $timing @return array<string,mixed> */
    private function failedBeforeWriteEvidence(
        array $request,
        array $assembly,
        string $capturedAtUtc,
        array $timing,
    ): array {
        $postGates = array_fill_keys([
            'status', 'kuma_healthy_count', 'kuma_total_count', 'runtime_config_passed',
            'services_passed', 'endpoints_passed', 'logs_passed', 'scanner_passed',
            'dormant_clean_passed', 'passed',
        ], null);
        $postGates['status'] = 'not_observed';
        $value = [
            'schema' => DeploymentContractV1::EVIDENCE_SCHEMA,
            'run_id' => $request['run_id'],
            'intent_sha256' => $request['intent_sha256'],
            'captured_at_utc' => $capturedAtUtc,
            ...$assembly['sections'],
            'deploy' => ['status' => 'not_invoked', 'invocation_count' => 0, 'exit_code' => null, 'rollback_outcome' => 'not_applicable'],
            'rollback' => ['status' => 'not_invoked', 'invocation_count' => 0, 'mode' => 'not_applicable', 'verified' => null],
            'post_gates' => $postGates,
            'deploy_timing' => ['status' => 'not_observed', 'authoritative_sha256' => null, 'run_id' => null, 'total_ms' => null],
            'orchestrator_timing' => $timing,
            'result' => ['state' => 'failed_before_write', 'exit_code' => $assembly['exit_code'], 'reason' => $assembly['reason']],
        ];
        DeploymentContractV1::validateEvidence($value);
        return $value;
    }

    /** @param array<string,mixed> $state */
    private function persistJournalAndState(string $prefix, string $eventsBytes, array $state): void
    {
        $existingEvents = $this->storage->read($prefix . 'events.jsonl', 1_048_576);
        if ($existingEvents !== null && !hash_equals($existingEvents, $eventsBytes)) {
            throw new RuntimeException('predeploy journal conflicts with durable bytes');
        }
        $existingState = $this->storage->read($prefix . 'state.json', 4_096);
        if ($existingState !== null) {
            $decoded = DeploymentHostRunnerContractV1::decodeState($existingState);
            if ($decoded['sequence'] > $state['sequence']) {
                throw new RuntimeException('predeploy state cannot replace a later durable lifecycle');
            }
            if ($decoded['sequence'] === $state['sequence'] && !hash_equals($existingState, DeploymentHostRunnerContractV1::encodeFile($state))) {
                throw new RuntimeException('predeploy state conflicts with durable bytes');
            }
        }
        $this->storage->cow($prefix . 'events.jsonl', $eventsBytes, 1_048_576);
        $this->storage->cow($prefix . 'state.json', DeploymentHostRunnerContractV1::encodeFile($state), 4_096);
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function response(
        array $request,
        string $disposition,
        string $state,
        int $exitCode,
        string $reason,
    ): array {
        $response = [
            'schema' => DeploymentHostRunnerContractV1::RESPONSE_SCHEMA,
            'run_id' => $request['run_id'],
            'intent_sha256' => $request['intent_sha256'],
            'action' => 'deploy',
            'disposition' => $disposition,
            'state' => $state,
            'result_exit_code' => $exitCode,
            'result_reason' => $reason,
        ];
        DeploymentHostRunnerContractV1::validateResponse($response);
        return $response;
    }
}
