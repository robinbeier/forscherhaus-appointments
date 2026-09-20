<?php

declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);

defined('BASEPATH') || define('BASEPATH', $repoRoot . '/system/');
defined('APPPATH') || define('APPPATH', $repoRoot . '/application/');

require_once APPPATH . 'core/Csp_report_only.php';

const CSP_STATUS_SCHEMA = 'csp_report_only_status.v1';
const CSP_STATUS_MAX_AGGREGATE_BYTES = Csp_report_only::MAX_AGGREGATE_BYTES;

/** @return array{expect:string,config_path:string,aggregate_path:string} */
function parseOptions(array $argv): array
{
    $options = [
        'expect' => '',
        'config_path' => Csp_report_only::CONFIG_PATH,
        'aggregate_path' => Csp_report_only::aggregatePath(),
    ];

    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--expect=')) {
            $options['expect'] = substr($argument, strlen('--expect='));
            continue;
        }

        if (str_starts_with($argument, '--config-path=')) {
            $options['config_path'] = substr($argument, strlen('--config-path='));
            continue;
        }

        if (str_starts_with($argument, '--aggregate-path=')) {
            $options['aggregate_path'] = substr($argument, strlen('--aggregate-path='));
            continue;
        }

        throw new InvalidArgumentException('Unknown option.');
    }

    if (!in_array($options['expect'], ['inactive', 'active'], true)) {
        throw new InvalidArgumentException('--expect must be inactive or active.');
    }

    foreach (['config_path', 'aggregate_path'] as $key) {
        if (
            $options[$key] === '' ||
            $options[$key][0] !== '/' ||
            str_contains($options[$key], "\0") ||
            str_contains($options[$key], '//') ||
            in_array('.', explode('/', $options[$key]), true) ||
            in_array('..', explode('/', $options[$key]), true)
        ) {
            throw new InvalidArgumentException('Status paths must be canonical absolute paths.');
        }
    }

    return $options;
}

/** @return string|null */
function readRegularFileSafely(string $path, int $maxBytes): ?string
{
    clearstatcache(true, $path);
    $identity = @lstat($path);
    if (!is_array($identity)) {
        return null;
    }

    if (
        (($identity['mode'] ?? 0) & 0170000) !== 0100000 ||
        (int) ($identity['nlink'] ?? 0) !== 1 ||
        (int) ($identity['size'] ?? -1) < 0 ||
        (int) ($identity['size'] ?? 0) > $maxBytes
    ) {
        throw new RuntimeException('Aggregate identity is invalid.');
    }

    $cursor = dirname($path);
    while ($cursor !== '/') {
        if (is_link($cursor)) {
            throw new RuntimeException('Aggregate path contains a symlink.');
        }
        $next = dirname($cursor);
        if ($next === $cursor) {
            throw new RuntimeException('Aggregate path is invalid.');
        }
        $cursor = $next;
    }

    $stream = @fopen($path, 'rb');
    if (!is_resource($stream)) {
        throw new RuntimeException('Aggregate cannot be opened.');
    }

    try {
        if (!flock($stream, LOCK_SH)) {
            throw new RuntimeException('Aggregate cannot be locked.');
        }
        $opened = fstat($stream);
        if (
            !is_array($opened) ||
            (int) ($opened['dev'] ?? -1) !== (int) ($identity['dev'] ?? -2) ||
            (int) ($opened['ino'] ?? -1) !== (int) ($identity['ino'] ?? -2) ||
            (int) ($opened['nlink'] ?? 0) !== 1
        ) {
            throw new RuntimeException('Aggregate identity changed.');
        }
        $bytes = stream_get_contents($stream, $maxBytes + 1);
        if (!is_string($bytes) || strlen($bytes) > $maxBytes) {
            throw new RuntimeException('Aggregate exceeds the status limit.');
        }
        return $bytes;
    } finally {
        flock($stream, LOCK_UN);
        fclose($stream);
    }
}

/** @param array<string,mixed> $receipt */
function emitReceipt(array $receipt, int $exitCode): never
{
    echo json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit($exitCode);
}

try {
    $options = parseOptions($argv);
    $configExists = @lstat($options['config_path']) !== false;
    $config = Csp_report_only::load($options['config_path']);
    $configStatus = !$configExists
        ? 'missing'
        : ($config === null
            ? 'invalid'
            : ($config['enabled'] ?? false
                ? 'active'
                : 'disabled'));

    $aggregateBytes = readRegularFileSafely($options['aggregate_path'], CSP_STATUS_MAX_AGGREGATE_BYTES);
    $aggregateStatus = $aggregateBytes === null ? 'missing' : 'invalid';
    $aggregateSummary = null;

    if (is_string($aggregateBytes)) {
        $aggregateSummary = Csp_report_only::summarizeAggregateJson($aggregateBytes, $config);
        if (is_array($aggregateSummary)) {
            $aggregateStatus = 'valid';
        }
    }

    $expectedConfigStatus = $options['expect'] === 'active' ? 'active' : 'missing';
    $passed =
        $configStatus === $expectedConfigStatus &&
        ($options['expect'] === 'active'
            ? $aggregateStatus === 'valid'
            : in_array($aggregateStatus, ['missing', 'valid'], true));

    $configHash = null;
    if ($configStatus === 'active') {
        $configHash = hash_file('sha256', $options['config_path']);
        if (!is_string($configHash) || preg_match('/\A[a-f0-9]{64}\z/', $configHash) !== 1) {
            throw new RuntimeException('Configuration identity is unavailable.');
        }
    }

    emitReceipt(
        [
            'schema' => CSP_STATUS_SCHEMA,
            'expectation' => $options['expect'],
            'status' => $passed ? 'passed' : 'failed',
            'config' => [
                'status' => $configStatus,
                'sha256' => $configHash,
            ],
            'aggregate' => [
                'status' => $aggregateStatus,
                'summary' => $aggregateSummary,
            ],
        ],
        $passed ? 0 : 1,
    );
} catch (Throwable) {
    emitReceipt(
        [
            'schema' => CSP_STATUS_SCHEMA,
            'expectation' => 'unknown',
            'status' => 'runtime_failed',
            'config' => ['status' => 'unknown', 'sha256' => null],
            'aggregate' => ['status' => 'unknown', 'summary' => null],
        ],
        2,
    );
}
