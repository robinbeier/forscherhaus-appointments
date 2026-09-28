<?php

declare(strict_types=1);

const CSP_RECEIPT_SCHEMA = 'csp_report_only_state.v2';
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
const CSP_RECEIPT_FAILURE_CLASSES = [
    'activation_invalid',
    'activation_disabled',
    'activation_identity_unavailable',
    'activation_missing',
    'activation_unexpected',
    'activation_expired',
    'aggregate_directory_unavailable',
    'aggregate_lock_missing',
    'aggregate_lock_invalid',
    'aggregate_missing_with_lock',
    'aggregate_identity_invalid',
    'aggregate_lock_unreadable',
    'aggregate_lock_unavailable',
    'aggregate_lock_changed',
    'aggregate_identity_changed',
    'aggregate_unreadable',
    'aggregate_oversized',
    'aggregate_invalid',
    'aggregate_unavailable',
    'release_identity_unavailable',
    'internal_error',
];

function expectedActiveConfigHash(): ?string
{
    $path = __DIR__ . '/config/csp_report_only.production.v1.json';
    $hash = @hash_file('sha256', $path);
    return is_string($hash) && preg_match('/\A[a-f0-9]{64}\z/', $hash) === 1 ? $hash : null;
}

function expectedSegmentConfigHash(int $startsAt, int $expiresAt): ?string
{
    $bytes = @file_get_contents(__DIR__ . '/config/csp_report_only.production.v1.json');
    if (!is_string($bytes)) {
        return null;
    }
    try {
        $config = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($config) || ($config['schema'] ?? null) !== 'csp_report_only_config.v1') {
            return null;
        }
        $config['schema'] = 'csp_report_only_config.v2';
        $config['starts_at_unix'] = $startsAt;
        $config['expires_at_unix'] = $expiresAt;
        return hash('sha256', json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    } catch (Throwable) {
        return null;
    }
}

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
function validateReceipt(array $receipt, string $expectation, ?string $expectedReleaseBinding = null): bool
{
    if (
        !hasExactKeys($receipt, [
            'schema',
            'expectation',
            'status',
            'result_class',
            'release_binding',
            'activation',
            'aggregate',
        ])
    ) {
        return false;
    }
    if (
        ($receipt['schema'] ?? null) !== CSP_RECEIPT_SCHEMA ||
        ($receipt['expectation'] ?? null) !== $expectation ||
        !in_array($receipt['status'] ?? null, ['passed', 'failed'], true) ||
        !is_string($receipt['result_class'] ?? null) ||
        !is_array($receipt['activation'] ?? null) ||
        !is_array($receipt['aggregate'] ?? null)
    ) {
        return false;
    }

    $releaseBinding = $receipt['release_binding'] ?? null;
    if (
        $releaseBinding !== null &&
        (!is_string($releaseBinding) || preg_match('/\A[a-f0-9]{64}\z/', $releaseBinding) !== 1)
    ) {
        return false;
    }
    if (
        $expectedReleaseBinding !== null &&
        (!is_string($releaseBinding) || !hash_equals($expectedReleaseBinding, $releaseBinding))
    ) {
        return false;
    }

    $activation = $receipt['activation'];
    $aggregate = $receipt['aggregate'];
    $segmentExpectation = in_array($expectation, ['segment-active', 'segment-observe', 'segment-expired'], true);
    $aggregateScoped = $segmentExpectation && array_key_exists('scope', $aggregate);
    if ($segmentExpectation && ($receipt['status'] ?? null) === 'passed' && !$aggregateScoped) {
        return false;
    }
    if ($aggregateScoped && $aggregate['scope'] !== 'cumulative_retention_window') {
        return false;
    }
    $segmentReceiptFields =
        $segmentExpectation &&
        (array_key_exists('starts_at_unix', $activation) || array_key_exists('expires_at_unix', $activation));
    if ($segmentExpectation && ($receipt['status'] ?? null) === 'passed' && !$segmentReceiptFields) {
        return false;
    }
    $activationKeys = $segmentReceiptFields
        ? ['status', 'sha256', 'starts_at_unix', 'expires_at_unix']
        : ['status', 'sha256'];
    $aggregateKeys = $aggregateScoped ? ['status', 'summary', 'scope'] : ['status', 'summary'];
    if (!hasExactKeys($activation, $activationKeys) || !hasExactKeys($aggregate, $aggregateKeys)) {
        return false;
    }

    if (
        !in_array(
            $activation['status'] ?? null,
            ['active', 'expired', 'inactive', 'invalid', 'disabled', 'unknown'],
            true,
        )
    ) {
        return false;
    }
    if (in_array($activation['status'] ?? null, ['active', 'expired'], true)) {
        if (
            !is_string($activation['sha256'] ?? null) ||
            preg_match('/\A[a-f0-9]{64}\z/', $activation['sha256']) !== 1
        ) {
            return false;
        }
    } elseif (($activation['sha256'] ?? null) !== null) {
        return false;
    }
    if ($segmentReceiptFields) {
        if (($receipt['status'] ?? null) === 'failed') {
            $startsAt = $activation['starts_at_unix'];
            $expiresAt = $activation['expires_at_unix'];
            if (($startsAt === null) !== ($expiresAt === null)) {
                return false;
            }
            if (
                $startsAt !== null &&
                (!is_int($startsAt) ||
                    !is_int($expiresAt) ||
                    $expiresAt - $startsAt < 900 ||
                    $expiresAt - $startsAt > 14400)
            ) {
                return false;
            }
        } else {
            if (
                !is_int($activation['starts_at_unix'] ?? null) ||
                !is_int($activation['expires_at_unix'] ?? null) ||
                $activation['expires_at_unix'] - $activation['starts_at_unix'] < 900 ||
                $activation['expires_at_unix'] - $activation['starts_at_unix'] > 14400
            ) {
                return false;
            }
            $now = time();
            if (
                in_array($expectation, ['segment-active', 'segment-observe'], true) &&
                !($activation['starts_at_unix'] <= $now && $now < $activation['expires_at_unix'])
            ) {
                return false;
            }
            if ($expectation === 'segment-expired' && $now < $activation['expires_at_unix']) {
                return false;
            }
            $expectedSegmentHash = expectedSegmentConfigHash(
                $activation['starts_at_unix'],
                $activation['expires_at_unix'],
            );
            if (!is_string($expectedSegmentHash) || !hash_equals($expectedSegmentHash, $activation['sha256'])) {
                return false;
            }
        }
    }
    if (!in_array($aggregate['status'] ?? null, ['valid', 'missing', 'failed', 'not_checked'], true)) {
        return false;
    }
    if (($aggregate['status'] ?? null) === 'valid') {
        if (!is_array($aggregate['summary'] ?? null) || !validateAggregateSummary($aggregate['summary'])) {
            return false;
        }
    } elseif (($aggregate['summary'] ?? null) !== null) {
        return false;
    }

    if (($receipt['status'] ?? null) === 'failed') {
        if (!in_array($receipt['result_class'], CSP_RECEIPT_FAILURE_CLASSES, true)) {
            return false;
        }
        return $releaseBinding !== null ||
            in_array($receipt['result_class'], ['release_identity_unavailable', 'internal_error'], true);
    }

    if (
        !is_string($releaseBinding) ||
        ($receipt['result_class'] ?? null) !== 'state_verified' ||
        !in_array($aggregate['status'], ['valid', 'missing'], true)
    ) {
        return false;
    }

    if (in_array($expectation, ['active', 'segment-active', 'segment-observe'], true)) {
        $expectedHash = $segmentExpectation
            ? expectedSegmentConfigHash($activation['starts_at_unix'], $activation['expires_at_unix'])
            : expectedActiveConfigHash();
        if (
            ($activation['status'] ?? null) !== 'active' ||
            !is_string($expectedHash) ||
            !hash_equals($expectedHash, $activation['sha256'])
        ) {
            return false;
        }
        return true;
    }

    if ($expectation === 'segment-expired') {
        return ($activation['status'] ?? null) === 'expired';
    }
    return ($activation['status'] ?? null) === 'inactive' && ($activation['sha256'] ?? null) === null;
}

$expectation = '';
$expectedReleaseBinding = null;
$seen = [];
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--expect=')) {
        if (isset($seen['expect'])) {
            exit(2);
        }
        $seen['expect'] = true;
        $expectation = substr($argument, strlen('--expect='));
        continue;
    }
    if (str_starts_with($argument, '--expected-release-binding=')) {
        if (isset($seen['expected_release_binding'])) {
            exit(2);
        }
        $seen['expected_release_binding'] = true;
        $expectedReleaseBinding = substr($argument, strlen('--expected-release-binding='));
        continue;
    }
    exit(2);
}
if (!in_array($expectation, ['inactive', 'active', 'segment-active', 'segment-observe', 'segment-expired'], true)) {
    exit(2);
}
if ($expectedReleaseBinding !== null && preg_match('/\A[a-f0-9]{64}\z/', $expectedReleaseBinding) !== 1) {
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

if (!is_array($receipt) || !validateReceipt($receipt, $expectation, $expectedReleaseBinding)) {
    exit(1);
}

echo json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit(($receipt['status'] ?? null) === 'passed' ? 0 : 3);
