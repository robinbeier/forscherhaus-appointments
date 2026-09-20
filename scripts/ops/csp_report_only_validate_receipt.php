<?php

declare(strict_types=1);

const CSP_RECEIPT_SCHEMA = 'csp_report_only_status.v1';
const CSP_AGGREGATE_SCHEMA = 'csp_report_only_aggregate.v1';
const CSP_RECEIPT_MAX_BYTES = 131072;
const CSP_RECEIPT_MAX_COUNTER = 1_000_000_000;
const CSP_RECEIPT_DIRECTIVES = [
    'default-src',
    'script-src',
    'style-src',
    'img-src',
    'font-src',
    'connect-src',
    'frame-src',
    'object-src',
    'base-uri',
    'form-action',
    'frame-ancestors',
    'media-src',
    'worker-src',
    'manifest-src',
    'child-src',
    'plugin-types',
    'sandbox',
    'script-src-elem',
    'script-src-attr',
    'style-src-elem',
    'style-src-attr',
    'report-uri',
    'other',
];
const CSP_RECEIPT_ORIGIN_CLASSES = [
    'self',
    'inline',
    'eval',
    'data',
    'blob',
    'google-analytics',
    'google-tag-manager',
    'matomo',
    'unknown-external',
    'none',
];

/** @param array<mixed> $value */
function hasExactKeys(array $value, array $keys): bool
{
    $actual = array_keys($value);
    sort($actual);
    sort($keys);
    return $actual === $keys;
}

function isCounter(mixed $value): bool
{
    return is_int($value) && $value >= 0 && $value <= CSP_RECEIPT_MAX_COUNTER;
}

/** @param array<mixed> $counts */
function validateCounterMap(array $counts, array $allowedKeys, bool $requireAll = false): ?int
{
    if ($requireAll && !hasExactKeys($counts, $allowedKeys)) {
        return null;
    }

    $total = 0;
    foreach ($counts as $key => $value) {
        if (!is_string($key) || !in_array($key, $allowedKeys, true) || !isCounter($value)) {
            return null;
        }
        if ($value > CSP_RECEIPT_MAX_COUNTER - $total) {
            return null;
        }
        $total += $value;
    }
    return $total;
}

/** @param array<mixed> $summary */
function validateAggregateSummary(array $summary): bool
{
    if (
        !hasExactKeys($summary, [
            'schema',
            'status',
            'updated_at_utc',
            'age_seconds',
            'bucket_count',
            'accepted',
            'dropped',
            'classes',
        ])
    ) {
        return false;
    }
    if (
        ($summary['schema'] ?? null) !== CSP_AGGREGATE_SCHEMA ||
        ($summary['status'] ?? null) !== 'ok' ||
        !is_string($summary['updated_at_utc'] ?? null) ||
        preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\z/', $summary['updated_at_utc']) !== 1 ||
        !isCounter($summary['age_seconds'] ?? null) ||
        !is_int($summary['bucket_count'] ?? null) ||
        $summary['bucket_count'] < 0 ||
        $summary['bucket_count'] > 168 ||
        !isCounter($summary['accepted'] ?? null) ||
        !is_array($summary['dropped'] ?? null) ||
        !is_array($summary['classes'] ?? null)
    ) {
        return false;
    }

    $dropped = $summary['dropped'];
    if (!hasExactKeys($dropped, ['invalid', 'rate_limited', 'storage_failed'])) {
        return false;
    }
    foreach ($dropped as $value) {
        if (!isCounter($value)) {
            return false;
        }
    }

    $classes = $summary['classes'];
    if (!hasExactKeys($classes, ['surface', 'directive', 'blocked_origin'])) {
        return false;
    }
    if (!is_array($classes['surface']) || !is_array($classes['directive']) || !is_array($classes['blocked_origin'])) {
        return false;
    }
    $surfaceTotal = validateCounterMap($classes['surface'], ['app', 'www'], true);
    $directiveTotal = validateCounterMap($classes['directive'], CSP_RECEIPT_DIRECTIVES);
    $originTotal = validateCounterMap($classes['blocked_origin'], CSP_RECEIPT_ORIGIN_CLASSES);
    if ($surfaceTotal === null || $directiveTotal === null || $originTotal === null) {
        return false;
    }

    return $surfaceTotal === $summary['accepted'] &&
        $directiveTotal === $summary['accepted'] &&
        $originTotal === $summary['accepted'];
}

/** @param array<mixed> $receipt */
function validateReceipt(array $receipt, string $expectation): bool
{
    if (!hasExactKeys($receipt, ['schema', 'expectation', 'status', 'config', 'aggregate'])) {
        return false;
    }
    if (
        ($receipt['schema'] ?? null) !== CSP_RECEIPT_SCHEMA ||
        ($receipt['expectation'] ?? null) !== $expectation ||
        ($receipt['status'] ?? null) !== 'passed' ||
        !is_array($receipt['config'] ?? null) ||
        !is_array($receipt['aggregate'] ?? null)
    ) {
        return false;
    }

    $config = $receipt['config'];
    $aggregate = $receipt['aggregate'];
    if (!hasExactKeys($config, ['status', 'sha256']) || !hasExactKeys($aggregate, ['status', 'summary'])) {
        return false;
    }

    if ($expectation === 'active') {
        if (
            ($config['status'] ?? null) !== 'active' ||
            !is_string($config['sha256'] ?? null) ||
            preg_match('/\A[a-f0-9]{64}\z/', $config['sha256']) !== 1 ||
            ($aggregate['status'] ?? null) !== 'valid' ||
            !is_array($aggregate['summary'] ?? null)
        ) {
            return false;
        }
        return validateAggregateSummary($aggregate['summary']);
    }

    if (($config['status'] ?? null) !== 'missing' || ($config['sha256'] ?? null) !== null) {
        return false;
    }
    if (($aggregate['status'] ?? null) === 'missing') {
        return ($aggregate['summary'] ?? null) === null;
    }
    return ($aggregate['status'] ?? null) === 'valid' &&
        is_array($aggregate['summary'] ?? null) &&
        validateAggregateSummary($aggregate['summary']);
}

$expectation = '';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--expect=')) {
        $expectation = substr($argument, strlen('--expect='));
        continue;
    }
    exit(2);
}
if (!in_array($expectation, ['inactive', 'active'], true)) {
    exit(2);
}

$input = stream_get_contents(STDIN, CSP_RECEIPT_MAX_BYTES + 1);
if (!is_string($input) || $input === '' || strlen($input) > CSP_RECEIPT_MAX_BYTES) {
    exit(1);
}

try {
    $receipt = json_decode($input, true, 16, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    exit(1);
}

if (!is_array($receipt) || !validateReceipt($receipt, $expectation)) {
    exit(1);
}

echo json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit(0);
