<?php

declare(strict_types=1);

const CSP_RUNTIME_VALIDATOR_SCHEMA = 'csp_report_only_runtime_readiness.v1';
const CSP_RUNTIME_VALIDATOR_CLASSES = [
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
    'client_precondition_failed',
    'http_unavailable',
    'response_invalid',
    'response_contradictory',
];

$input = stream_get_contents(STDIN, 4097);
if (!is_string($input) || $input === '' || strlen($input) > 4096) {
    exit(1);
}
try {
    $receipt = json_decode($input, true, 8, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    exit(1);
}
if (!is_array($receipt)) {
    exit(1);
}
$keys = array_keys($receipt);
sort($keys);
if (
    $keys !== ['result_class', 'schema', 'status'] ||
    ($receipt['schema'] ?? null) !== CSP_RUNTIME_VALIDATOR_SCHEMA ||
    !in_array($receipt['status'] ?? null, ['passed', 'failed'], true) ||
    !in_array($receipt['result_class'] ?? null, CSP_RUNTIME_VALIDATOR_CLASSES, true) ||
    ($receipt['status'] === 'passed') !== ($receipt['result_class'] === 'write_ready')
) {
    exit(1);
}

echo json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($receipt['status'] === 'passed' ? 0 : 3);
