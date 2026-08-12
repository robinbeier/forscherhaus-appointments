<?php

declare(strict_types=1);

namespace Ops;

use JsonException;
use RuntimeException;

require_once __DIR__ . '/DeploymentContractV1.php';
require_once __DIR__ . '/DeploymentHostRunnerAdmissionV1.php';
require_once __DIR__ . '/DeploymentHostRunnerContractV1.php';
require_once __DIR__ . '/DeploymentHostRunnerProtectedSourceV1.php';
require_once __DIR__ . '/DeploymentHostRunnerRecoveryV1.php';
require_once __DIR__ . '/DeploymentHostRunnerTerminalV1.php';

/**
 * Decodes the bounded stdin envelope produced by the privileged supervisor.
 * File paths never cross this boundary: validation and execution consume the
 * exact same protected bytes selected before either lock is acquired.
 */
final class DeploymentHostRunnerCliEnvelopeV1
{
    private const KEYS = [
        'action',
        'execution_input_bytes_base64',
        'intent_sha256',
        'report_bytes_base64',
        'request_bytes_base64',
        'run_id',
    ];

    /**
     * @return array{
     *   action:string,
     *   run_id:string,
     *   intent_sha256:string,
     *   request_bytes:?string,
     *   execution_input_bytes:?string,
     *   report_bytes:?string,
     *   request:?array<string,mixed>,
     *   execution_input:?array<string,mixed>,
     *   report:?array<string,mixed>
     * }
     */
    public static function decode(string $encoded): array
    {
        if ($encoded === '' || strlen($encoded) > 65_536 || !str_ends_with($encoded, "\n")) {
            throw new RuntimeException('host-runner CLI envelope is invalid');
        }
        try {
            $value = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('host-runner CLI envelope is invalid');
        }
        if (!is_array($value) || array_is_list($value) || array_keys($value) !== self::KEYS) {
            throw new RuntimeException('host-runner CLI envelope shape is invalid');
        }
        if (
            !is_string($value['action']) ||
            !is_string($value['run_id']) ||
            !is_string($value['intent_sha256']) ||
            !in_array($value['action'], DeploymentHostRunnerContractV1::CLI_ACTIONS, true)
        ) {
            throw new RuntimeException('host-runner CLI envelope identity is invalid');
        }

        $requestBytes = self::decodeNullableBytes($value['request_bytes_base64'], 16_384, 'request');
        $inputBytes = self::decodeNullableBytes($value['execution_input_bytes_base64'], 16_384, 'execution input');
        $reportBytes = self::decodeNullableBytes($value['report_bytes_base64'], 16_384, 'report');
        $request = null;
        $input = null;
        $report = null;

        if ($value['action'] === 'deploy') {
            if ($requestBytes === null || $inputBytes === null || $reportBytes !== null) {
                throw new RuntimeException('deploy CLI envelope authority is invalid');
            }
            $request = DeploymentHostRunnerContractV1::decodeDeployRequest($requestBytes);
            $input = DeploymentHostRunnerContractV1::decodeExecutionInput($inputBytes);
            DeploymentHostRunnerContractV1::validateDeployExecutionBundle($request, $input);
        } elseif ($value['action'] === 'recovery') {
            if ($requestBytes === null || $inputBytes === null || $reportBytes !== null) {
                throw new RuntimeException('recovery CLI envelope authority is invalid');
            }
            $request = DeploymentHostRunnerContractV1::decodeRecoveryRequest($requestBytes);
            $input = DeploymentHostRunnerContractV1::decodeExecutionInput($inputBytes);
            if ($input['action'] !== 'rollback') {
                throw new RuntimeException('recovery CLI envelope action is invalid');
            }
        } elseif ($value['action'] === 'post-gates') {
            if ($requestBytes === null || $inputBytes !== null || $reportBytes === null) {
                throw new RuntimeException('post-gates CLI envelope authority is invalid');
            }
            $decodedRequest = json_decode($requestBytes, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($decodedRequest) || array_is_list($decodedRequest)) {
                throw new RuntimeException('post-gates CLI request is invalid');
            }
            $request = ($decodedRequest['schema'] ?? null) === DeploymentHostRunnerContractV1::DEPLOY_REQUEST_SCHEMA
                ? DeploymentHostRunnerContractV1::decodeDeployRequest($requestBytes)
                : DeploymentHostRunnerContractV1::decodeRecoveryRequest($requestBytes);
            $report = DeploymentHostRunnerContractV1::decodePostGateReport($reportBytes);
            if (
                ($report['subject'] === 'deploy') !==
                ($request['schema'] === DeploymentHostRunnerContractV1::DEPLOY_REQUEST_SCHEMA)
            ) {
                throw new RuntimeException('post-gates CLI request does not bind the report subject');
            }
        } else {
            if ($requestBytes !== null || $inputBytes !== null || $reportBytes !== null) {
                throw new RuntimeException('reconcile CLI envelope cannot carry file authority');
            }
        }

        foreach ([$request, $input, $report] as $authority) {
            if ($authority === null) {
                continue;
            }
            if (
                ($authority['run_id'] ?? null) !== $value['run_id'] ||
                !is_string($authority['intent_sha256'] ?? null) ||
                !hash_equals($authority['intent_sha256'], $value['intent_sha256'])
            ) {
                throw new RuntimeException('host-runner CLI envelope substitutes authority identity');
            }
        }

        return [
            'action' => $value['action'],
            'run_id' => $value['run_id'],
            'intent_sha256' => $value['intent_sha256'],
            'request_bytes' => $requestBytes,
            'execution_input_bytes' => $inputBytes,
            'report_bytes' => $reportBytes,
            'request' => $request,
            'execution_input' => $input,
            'report' => $report,
        ];
    }

    private static function decodeNullableBytes(mixed $encoded, int $limit, string $name): ?string
    {
        if ($encoded === null) {
            return null;
        }
        if (!is_string($encoded) || $encoded === '') {
            throw new RuntimeException('host-runner CLI ' . $name . ' bytes are invalid');
        }
        $bytes = base64_decode($encoded, true);
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > $limit || str_contains($bytes, "\0")) {
            throw new RuntimeException('host-runner CLI ' . $name . ' bytes are invalid');
        }
        return $bytes;
    }
}

interface HostRunnerReservationReconstructor
{
    public function reconstruct(): string;
}

interface HostRunnerStoredReconciler
{
    public function reconcile(string $runId, string $intentSha256): string;
}

interface HostRunnerDeployWorkflow
{
    /** @param array<string,mixed> $request @param array<string,mixed> $input @return array<string,mixed> */
    public function start(array $request, array $input): array;

    /** @param array<string,mixed> $request @param array<string,mixed> $input @return array<string,mixed> */
    public function resume(array $request, array $input): array;
}

interface HostRunnerPostGateWorkflow
{
    /** @param array<string,mixed> $request @param array<string,mixed> $report @return array<string,mixed> */
    public function submit(array $request, array $report, string $reportBytes): array;
}

interface HostRunnerRecoveryWorkflow
{
    /** @param array<string,mixed> $request @param array<string,mixed> $input @return array<string,mixed> */
    public function admit(array $request, array $input): array;
}

final readonly class SystemHostRunnerReservationReconstructor implements HostRunnerReservationReconstructor
{
    public function __construct(private HostRunnerReservationPersistence $persistence) {}
    public function reconstruct(): string { return $this->persistence->reconstructSoleReservedClaim(); }
}

final readonly class SystemHostRunnerStoredReconciler implements HostRunnerStoredReconciler
{
    public function __construct(private HostRunnerReconciliationPersistence $persistence) {}
    public function reconcile(string $runId, string $intentSha256): string
    {
        return $this->persistence->reconcileStored($runId, $intentSha256);
    }
}

final readonly class SystemHostRunnerDeployWorkflow implements HostRunnerDeployWorkflow
{
    public function __construct(private HostRunnerStorage $storage) {}

    public function start(array $request, array $input): array
    {
        $source = new SystemHostRunnerProtectedObservationSource($request['expected_commit'], $this->storage);
        $provider = new ProtectedHostPredeployObservationProvider($source, $request, $input);
        $response = (new HostRunnerPredeployOrchestrator($this->storage))->collect($request, $input, $provider);
        if ($response['disposition'] === 'terminal') {
            return $response;
        }
        if ($response['disposition'] !== 'attach_pre_deploy' || $response['state'] !== 'artifact_verified') {
            throw new RuntimeException('predeploy workflow returned an invalid admission handoff');
        }
        return (new HostRunnerDeployAdmission($this->storage))->admit($request, $input);
    }

    public function resume(array $request, array $input): array
    {
        $response = (new HostRunnerDeployAdmission($this->storage))->admit($request, $input);
        $prefix = 'runs/' . $request['run_id'] . '/';
        $stateBytes = $this->storage->read($prefix . 'state.json', 4_096);
        if ($stateBytes === null) {
            throw new RuntimeException('resumed deploy has no durable state');
        }
        $state = DeploymentHostRunnerContractV1::decodeState($stateBytes);
        if (!in_array($state['deploy']['unit_state'], ['exited', 'failed'], true)) {
            return $response;
        }
        $completion = new HostRunnerActionCompletion($this->storage);
        $receipt = $completion->requireDeployReceiptForStoppedUnit($request['run_id']);
        if ($receipt['receipt']['outcome'] === 'succeeded') {
            return $completion->acceptSucceededDeployReceipt($request['run_id']);
        }
        return (new HostRunnerTerminalPersistence($this->storage))->terminalizeDeploy($request['run_id']);
    }
}

final readonly class SystemHostRunnerPostGateWorkflow implements HostRunnerPostGateWorkflow
{
    public function __construct(private HostRunnerStorage $storage) {}

    public function submit(array $request, array $report, string $reportBytes): array
    {
        $runId = $request['run_id'];
        $completion = new HostRunnerActionCompletion($this->storage);
        $terminal = new HostRunnerTerminalPersistence($this->storage);
        if ($report['subject'] === 'deploy') {
            $completion->acceptSucceededDeployReceipt($runId);
            $accepted = $completion->acceptDeployPostGateReport($runId, $reportBytes);
            if ($report['post_gates']['status'] === 'passed') {
                return self::withAction($terminal->terminalizeDeploy($runId), 'post-gates');
            }
            return self::nonterminal($accepted['state'], 'post-gates');
        }
        $accepted = $completion->acceptRollbackPostGateReport($runId, $reportBytes);
        unset($accepted);
        return self::withAction($terminal->terminalizeRollback($runId), 'post-gates');
    }

    /** @param array<string,mixed> $response @return array<string,mixed> */
    private static function withAction(array $response, string $action): array
    {
        $response['action'] = $action;
        DeploymentHostRunnerContractV1::validateResponse($response);
        return $response;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private static function nonterminal(array $state, string $action): array
    {
        $response = [
            'schema' => DeploymentHostRunnerContractV1::RESPONSE_SCHEMA,
            'run_id' => $state['run_id'], 'intent_sha256' => $state['intent_sha256'],
            'action' => $action, 'disposition' => 'attach_observe_only', 'state' => $state['state'],
            'result_exit_code' => 0, 'result_reason' => 'ok',
        ];
        DeploymentHostRunnerContractV1::validateResponse($response);
        return $response;
    }
}

final readonly class SystemHostRunnerRecoveryWorkflow implements HostRunnerRecoveryWorkflow
{
    public function __construct(private HostRunnerStorage $storage) {}
    public function admit(array $request, array $input): array
    {
        return (new HostRunnerRecoveryAdmission($this->storage))->admit($request, $input);
    }
}

/** Locked lifecycle router. The supervisor remains responsible for both FDs. */
final class DeploymentHostRunnerCliApplicationV1
{
    private readonly HostRunnerReservationReconstructor $reconstructor;
    private readonly HostRunnerStoredReconciler $reconciler;
    private readonly HostRunnerDeployWorkflow $deployWorkflow;
    private readonly HostRunnerPostGateWorkflow $postGateWorkflow;
    private readonly HostRunnerRecoveryWorkflow $recoveryWorkflow;

    public function __construct(
        private readonly HostRunnerStorage $storage,
        ?HostRunnerReservationReconstructor $reconstructor = null,
        ?HostRunnerStoredReconciler $reconciler = null,
        ?HostRunnerDeployWorkflow $deployWorkflow = null,
        ?HostRunnerPostGateWorkflow $postGateWorkflow = null,
        ?HostRunnerRecoveryWorkflow $recoveryWorkflow = null,
    ) {
        $this->reconstructor = $reconstructor ?? new SystemHostRunnerReservationReconstructor(
            new HostRunnerReservationPersistence($storage),
        );
        $this->reconciler = $reconciler ?? new SystemHostRunnerStoredReconciler(
            new HostRunnerReconciliationPersistence($storage),
        );
        $this->deployWorkflow = $deployWorkflow ?? new SystemHostRunnerDeployWorkflow($storage);
        $this->postGateWorkflow = $postGateWorkflow ?? new SystemHostRunnerPostGateWorkflow($storage);
        $this->recoveryWorkflow = $recoveryWorkflow ?? new SystemHostRunnerRecoveryWorkflow($storage);
    }

    /** @param array<string,mixed> $envelope @return array<string,mixed> */
    public function deploy(array $envelope): array
    {
        if (
            ($envelope['action'] ?? null) !== 'deploy' ||
            !is_array($envelope['request'] ?? null) ||
            !is_array($envelope['execution_input'] ?? null)
        ) {
            throw new RuntimeException('deploy CLI application received invalid authority');
        }
        $request = $envelope['request'];
        $input = $envelope['execution_input'];
        DeploymentHostRunnerContractV1::validateDeployExecutionBundle($request, $input);

        // Always scan first: this also repairs the claim-present/state-missing
        // crash prefix because reconstructed claims are deterministic.
        $this->reconstructor->reconstruct();
        $claimBytes = $this->storage->read('active-run.json', 4_096);
        if ($claimBytes === null) {
            return $this->validated($this->deployWorkflow->start($request, $input));
        }
        $claim = DeploymentHostRunnerContractV1::decodeActiveRun($claimBytes);
        if (
            $claim['run_id'] !== $request['run_id'] ||
            !hash_equals($claim['intent_sha256'], $request['intent_sha256'])
        ) {
            return self::response('deploy', $request, 'rejected', null, 75, 'state_conflict');
        }

        $this->reconciler->reconcile($request['run_id'], $request['intent_sha256']);
        $stateBytes = $this->storage->read('runs/' . $request['run_id'] . '/state.json', 4_096);
        if ($stateBytes === null) {
            throw new RuntimeException('reconciled deploy has no durable state');
        }
        $state = DeploymentHostRunnerContractV1::decodeState($stateBytes);
        if (in_array($state['state'], ['succeeded', ...DeploymentContractV1::TERMINAL_FAILURE_STATES], true)) {
            return self::response(
                'deploy',
                $request,
                'terminal',
                $state['state'],
                $state['terminal']['exit_code'],
                $state['terminal']['reason'],
            );
        }
        if ($state['state'] === 'deploy_running') {
            return $this->validated($this->deployWorkflow->resume($request, $input));
        }
        if (!in_array($state['state'], ['post_gates_running', 'rollback_running'], true)) {
            throw new RuntimeException('active deploy claim has an unsupported durable state');
        }
        return self::response('deploy', $request, 'attach_observe_only', $state['state'], 0, 'ok');
    }

    /** @param array<string,mixed> $envelope @return array<string,mixed> */
    public function postGates(array $envelope): array
    {
        if (
            ($envelope['action'] ?? null) !== 'post-gates' ||
            !is_array($envelope['request'] ?? null) ||
            !is_array($envelope['report'] ?? null) ||
            !is_string($envelope['report_bytes'] ?? null)
        ) {
            throw new RuntimeException('post-gates CLI application received invalid authority');
        }
        $request = $envelope['request'];
        $this->reconstructor->reconstruct();
        $claimBytes = $this->storage->read('active-run.json', 4_096);
        if ($claimBytes === null) {
            return self::response('post-gates', $request, 'rejected', null, 75, 'state_conflict');
        }
        $claim = DeploymentHostRunnerContractV1::decodeActiveRun($claimBytes);
        if (
            $claim['run_id'] !== $request['run_id'] ||
            !hash_equals($claim['intent_sha256'], $request['intent_sha256'])
        ) {
            return self::response('post-gates', $request, 'rejected', null, 75, 'state_conflict');
        }
        $this->reconciler->reconcile($request['run_id'], $request['intent_sha256']);
        return $this->validated($this->postGateWorkflow->submit(
            $request,
            $envelope['report'],
            $envelope['report_bytes'],
        ));
    }

    /** @param array<string,mixed> $envelope @return array<string,mixed> */
    public function reconcile(array $envelope): array
    {
        if (($envelope['action'] ?? null) !== 'reconcile') {
            throw new RuntimeException('reconcile CLI application received invalid authority');
        }
        $identity = ['run_id' => $envelope['run_id'], 'intent_sha256' => $envelope['intent_sha256']];
        $this->reconstructor->reconstruct();
        $claimBytes = $this->storage->read('active-run.json', 4_096);
        if ($claimBytes === null) {
            return self::response('reconcile', $identity, 'rejected', null, 75, 'state_conflict');
        }
        $claim = DeploymentHostRunnerContractV1::decodeActiveRun($claimBytes);
        if (
            $claim['run_id'] !== $envelope['run_id'] ||
            !hash_equals($claim['intent_sha256'], $envelope['intent_sha256'])
        ) {
            return self::response('reconcile', $identity, 'rejected', null, 75, 'state_conflict');
        }
        $this->reconciler->reconcile($envelope['run_id'], $envelope['intent_sha256']);
        $stateBytes = $this->storage->read('runs/' . $envelope['run_id'] . '/state.json', 4_096);
        if ($stateBytes === null) {
            throw new RuntimeException('reconciled run has no durable state');
        }
        $state = DeploymentHostRunnerContractV1::decodeState($stateBytes);
        if (in_array($state['state'], ['succeeded', ...DeploymentContractV1::TERMINAL_FAILURE_STATES], true)) {
            return self::response(
                'reconcile', $identity, 'terminal', $state['state'],
                $state['terminal']['exit_code'], $state['terminal']['reason'],
            );
        }
        return self::response('reconcile', $identity, 'attach_observe_only', $state['state'], 0, 'ok');
    }

    /** @param array<string,mixed> $envelope @return array<string,mixed> */
    public function recovery(array $envelope): array
    {
        if (
            ($envelope['action'] ?? null) !== 'recovery' ||
            !is_array($envelope['request'] ?? null) ||
            !is_array($envelope['execution_input'] ?? null)
        ) {
            throw new RuntimeException('recovery CLI application received invalid authority');
        }
        $request = $envelope['request'];
        $this->reconstructor->reconstruct();
        $claimBytes = $this->storage->read('active-run.json', 4_096);
        if ($claimBytes === null) {
            return self::response('recovery', $request, 'rejected', null, 75, 'state_conflict');
        }
        $claim = DeploymentHostRunnerContractV1::decodeActiveRun($claimBytes);
        if (
            $claim['run_id'] !== $request['run_id'] ||
            !hash_equals($claim['intent_sha256'], $request['intent_sha256'])
        ) {
            return self::response('recovery', $request, 'rejected', null, 75, 'state_conflict');
        }
        $this->reconciler->reconcile($request['run_id'], $request['intent_sha256']);
        return $this->validated($this->recoveryWorkflow->admit($request, $envelope['execution_input']));
    }

    /** @param array<string,mixed> $response @return array<string,mixed> */
    private function validated(array $response): array
    {
        DeploymentHostRunnerContractV1::validateResponse($response);
        return $response;
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private static function response(
        string $action,
        array $request,
        string $disposition,
        ?string $state,
        ?int $exitCode,
        ?string $reason,
    ): array {
        $response = [
            'schema' => DeploymentHostRunnerContractV1::RESPONSE_SCHEMA,
            'run_id' => $request['run_id'],
            'intent_sha256' => $request['intent_sha256'],
            'action' => $action,
            'disposition' => $disposition,
            'state' => $state,
            'result_exit_code' => $exitCode,
            'result_reason' => $reason,
        ];
        DeploymentHostRunnerContractV1::validateResponse($response);
        return $response;
    }
}
