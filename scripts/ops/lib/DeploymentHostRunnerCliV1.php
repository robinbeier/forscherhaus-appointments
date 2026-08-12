<?php

declare(strict_types=1);

namespace Ops;

use JsonException;
use RuntimeException;

require_once __DIR__ . '/DeploymentContractV1.php';
require_once __DIR__ . '/DeploymentHostRunnerContractV1.php';

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
