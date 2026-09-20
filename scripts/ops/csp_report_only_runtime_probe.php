<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/CspReportOnlyProductionContext.php';

const CSP_RUNTIME_SCHEMA = 'csp_report_only_runtime_readiness.v1';
const CSP_RUNTIME_TOKEN_PATH = '/etc/fh/healthz.token';
const CSP_RUNTIME_URL = 'http://127.0.0.1/index.php/healthz/csp-report-only-write-readiness';
const CSP_RUNTIME_MAX_RESPONSE_BYTES = 4096;
const CSP_RUNTIME_RESULT_CLASSES = [
    'write_ready',
    'directory_unavailable',
    'directory_identity_failed',
    'probe_create_failed',
    'probe_identity_failed',
    'probe_write_failed',
    'probe_cleanup_failed',
    'parent_sync_failed',
    'method_not_allowed',
    'unauthorized',
    'non_loopback',
    'internal_error',
];

/** @return array{schema:string,status:string,result_class:string} */
function runtimeFailure(string $resultClass): array
{
    return ['schema' => CSP_RUNTIME_SCHEMA, 'status' => 'failed', 'result_class' => $resultClass];
}

/** @param array<mixed> $payload */
function validRuntimePayload(array $payload): bool
{
    $keys = array_keys($payload);
    sort($keys);
    if ($keys !== ['result_class', 'schema', 'status']) {
        return false;
    }
    if (
        ($payload['schema'] ?? null) !== CSP_RUNTIME_SCHEMA ||
        !in_array($payload['status'] ?? null, ['passed', 'failed'], true) ||
        !in_array($payload['result_class'] ?? null, CSP_RUNTIME_RESULT_CLASSES, true)
    ) {
        return false;
    }

    return ($payload['status'] === 'passed') === ($payload['result_class'] === 'write_ready');
}

function readHealthToken(): ?string
{
    return CspReportOnlyProductionContext::readHealthToken();
}

/** @return array<int,mixed> */
function runtimeCurlOptions(string $token): array
{
    return [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_HTTPHEADER => ['X-Health-Token: ' . $token, 'Content-Length: 0'],
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
    ];
}

/** @param array<string,mixed> $receipt */
function emitRuntimeReceipt(array $receipt, int $exitCode): never
{
    echo json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit($exitCode);
}

function runRuntimeProbe(): never
{
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0 || !function_exists('curl_init')) {
        emitRuntimeReceipt(runtimeFailure('client_precondition_failed'), 2);
    }
    $token = readHealthToken();
    if ($token === null) {
        emitRuntimeReceipt(runtimeFailure('client_precondition_failed'), 2);
    }

    $curl = curl_init(CSP_RUNTIME_URL);
    if ($curl === false) {
        emitRuntimeReceipt(runtimeFailure('http_unavailable'), 2);
    }
    curl_setopt_array($curl, runtimeCurlOptions($token));
    $body = curl_exec($curl);
    $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $primaryIp = (string) curl_getinfo($curl, CURLINFO_PRIMARY_IP);
    curl_close($curl);
    unset($token);

    if (
        !in_array($primaryIp, ['127.0.0.1', '::1'], true) ||
        !is_string($body) ||
        strlen($body) > CSP_RUNTIME_MAX_RESPONSE_BYTES
    ) {
        emitRuntimeReceipt(runtimeFailure('http_unavailable'), 2);
    }
    try {
        $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        emitRuntimeReceipt(runtimeFailure('response_invalid'), 2);
    }
    if (!is_array($payload) || !validRuntimePayload($payload)) {
        emitRuntimeReceipt(runtimeFailure('response_invalid'), 2);
    }
    if (($statusCode === 200) !== ($payload['status'] === 'passed')) {
        emitRuntimeReceipt(runtimeFailure('response_contradictory'), 2);
    }

    emitRuntimeReceipt($payload, $payload['status'] === 'passed' ? 0 : 1);
}

if (!defined('CSP_RUNTIME_PROBE_LOAD_ONLY')) {
    runRuntimeProbe();
}
