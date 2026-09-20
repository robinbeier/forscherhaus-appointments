<?php

declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);

defined('BASEPATH') || define('BASEPATH', $repoRoot . '/system/');
defined('APPPATH') || define('APPPATH', $repoRoot . '/application/');

require_once APPPATH . 'core/Csp_report_only.php';
require_once __DIR__ . '/lib/CspReportOnlyProductionContext.php';

const CSP_STATUS_SCHEMA = 'csp_report_only_state.v2';
const CSP_STATUS_MAX_AGGREGATE_BYTES = Csp_report_only::MAX_AGGREGATE_BYTES;

/** @return array{expect:string,config_path:string,aggregate_path:string,release_root:string} */
function parseOptions(array $argv): array
{
    $options = [
        'expect' => '',
        'config_path' => Csp_report_only::CONFIG_PATH,
        'aggregate_path' => Csp_report_only::aggregatePath(),
        'release_root' => $GLOBALS['repoRoot'],
    ];
    $seen = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--expect=')) {
            if (isset($seen['expect'])) {
                throw new InvalidArgumentException('Duplicate option.');
            }
            $seen['expect'] = true;
            $options['expect'] = substr($argument, strlen('--expect='));
            continue;
        }
        if (str_starts_with($argument, '--config-path=')) {
            if (isset($seen['config_path'])) {
                throw new InvalidArgumentException('Duplicate option.');
            }
            $seen['config_path'] = true;
            $options['config_path'] = substr($argument, strlen('--config-path='));
            continue;
        }
        if (str_starts_with($argument, '--aggregate-path=')) {
            if (isset($seen['aggregate_path'])) {
                throw new InvalidArgumentException('Duplicate option.');
            }
            $seen['aggregate_path'] = true;
            $options['aggregate_path'] = substr($argument, strlen('--aggregate-path='));
            continue;
        }
        if (str_starts_with($argument, '--release-root=')) {
            if (isset($seen['release_root'])) {
                throw new InvalidArgumentException('Duplicate option.');
            }
            $seen['release_root'] = true;
            $options['release_root'] = substr($argument, strlen('--release-root='));
            continue;
        }
        throw new InvalidArgumentException('Unknown option.');
    }

    if (!in_array($options['expect'], ['inactive', 'active'], true)) {
        throw new InvalidArgumentException('Invalid expectation.');
    }
    foreach (['config_path', 'aggregate_path', 'release_root'] as $key) {
        if (!canonicalAbsolutePath($options[$key])) {
            throw new InvalidArgumentException('Invalid path.');
        }
    }

    return $options;
}

function canonicalAbsolutePath(string $path): bool
{
    return $path !== '' &&
        $path[0] === '/' &&
        !str_contains($path, "\0") &&
        !str_contains($path, '//') &&
        !in_array('.', explode('/', $path), true) &&
        !in_array('..', explode('/', $path), true);
}

/** @return array{status:string,sha256:?string,result_class:?string,config:?array} */
function inspectActivation(string $path): array
{
    if (@lstat($path) === false) {
        return ['status' => 'inactive', 'sha256' => null, 'result_class' => null, 'config' => null];
    }

    $config = Csp_report_only::load($path);
    if (!is_array($config)) {
        return ['status' => 'invalid', 'sha256' => null, 'result_class' => 'activation_invalid', 'config' => null];
    }

    if (($config['enabled'] ?? false) !== true) {
        return ['status' => 'disabled', 'sha256' => null, 'result_class' => 'activation_disabled', 'config' => $config];
    }

    $hash = @hash_file('sha256', $path);
    if (!is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1) {
        return [
            'status' => 'invalid',
            'sha256' => null,
            'result_class' => 'activation_identity_unavailable',
            'config' => null,
        ];
    }

    return ['status' => 'active', 'sha256' => $hash, 'result_class' => null, 'config' => $config];
}

function safeDirectoryChain(string $directory): bool
{
    $cursor = '';
    foreach (explode('/', trim($directory, '/')) as $part) {
        $cursor .= '/' . $part;
        $identity = @lstat($cursor);
        if (
            !is_array($identity) ||
            (($identity['mode'] ?? 0) & 0170000) !== 0040000 ||
            (($identity['mode'] ?? 0) & 0111) === 0
        ) {
            return false;
        }
    }
    return true;
}

/** @return array{status:string,summary:?array,result_class:?string} */
function inspectAggregate(string $path, ?array $config): array
{
    $directory = dirname($path);
    if (!safeDirectoryChain($directory)) {
        return ['status' => 'failed', 'summary' => null, 'result_class' => 'aggregate_directory_unavailable'];
    }

    $lockPath = $path . '.lock';
    $lockIdentity = @lstat($lockPath);
    if (@lstat($path) === false && !is_array($lockIdentity)) {
        return ['status' => 'missing', 'summary' => null, 'result_class' => null];
    }
    if (!is_array($lockIdentity)) {
        return ['status' => 'failed', 'summary' => null, 'result_class' => 'aggregate_lock_missing'];
    }
    if ((($lockIdentity['mode'] ?? 0) & 0170000) !== 0100000 || (int) ($lockIdentity['nlink'] ?? 0) !== 1) {
        return ['status' => 'failed', 'summary' => null, 'result_class' => 'aggregate_lock_invalid'];
    }
    $lock = @fopen($lockPath, 'rb');
    if (!is_resource($lock)) {
        return ['status' => 'failed', 'summary' => null, 'result_class' => 'aggregate_lock_unreadable'];
    }

    try {
        if (!flock($lock, LOCK_SH)) {
            return ['status' => 'failed', 'summary' => null, 'result_class' => 'aggregate_lock_unavailable'];
        }
        $openedLock = @fstat($lock);
        if (
            !is_array($openedLock) ||
            (int) ($openedLock['dev'] ?? -1) !== (int) ($lockIdentity['dev'] ?? -2) ||
            (int) ($openedLock['ino'] ?? -1) !== (int) ($lockIdentity['ino'] ?? -2) ||
            (int) ($openedLock['nlink'] ?? 0) !== 1
        ) {
            return ['status' => 'failed', 'summary' => null, 'result_class' => 'aggregate_lock_changed'];
        }

        clearstatcache(true, $path);
        $aggregateIdentity = @lstat($path);
        if (!is_array($aggregateIdentity)) {
            return ['status' => 'failed', 'summary' => null, 'result_class' => 'aggregate_missing_with_lock'];
        }
        if (
            (($aggregateIdentity['mode'] ?? 0) & 0170000) !== 0100000 ||
            (int) ($aggregateIdentity['nlink'] ?? 0) !== 1 ||
            (int) ($aggregateIdentity['size'] ?? -1) < 0 ||
            (int) ($aggregateIdentity['size'] ?? 0) > CSP_STATUS_MAX_AGGREGATE_BYTES
        ) {
            return ['status' => 'failed', 'summary' => null, 'result_class' => 'aggregate_identity_invalid'];
        }

        $stream = @fopen($path, 'rb');
        if (!is_resource($stream)) {
            return ['status' => 'failed', 'summary' => null, 'result_class' => 'aggregate_unreadable'];
        }
        try {
            $opened = @fstat($stream);
            $current = @lstat($path);
            if (
                !is_array($opened) ||
                !is_array($current) ||
                (int) ($opened['dev'] ?? -1) !== (int) ($aggregateIdentity['dev'] ?? -2) ||
                (int) ($opened['ino'] ?? -1) !== (int) ($aggregateIdentity['ino'] ?? -2) ||
                (int) ($opened['nlink'] ?? 0) !== 1 ||
                (int) ($current['dev'] ?? -1) !== (int) ($aggregateIdentity['dev'] ?? -2) ||
                (int) ($current['ino'] ?? -1) !== (int) ($aggregateIdentity['ino'] ?? -2) ||
                (int) ($current['nlink'] ?? 0) !== 1
            ) {
                return ['status' => 'failed', 'summary' => null, 'result_class' => 'aggregate_identity_changed'];
            }
            $bytes = stream_get_contents($stream, CSP_STATUS_MAX_AGGREGATE_BYTES + 1);
        } finally {
            fclose($stream);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    if (!is_string($bytes) || strlen($bytes) > CSP_STATUS_MAX_AGGREGATE_BYTES) {
        return ['status' => 'failed', 'summary' => null, 'result_class' => 'aggregate_oversized'];
    }
    $summary = Csp_report_only::summarizeAggregateJson($bytes, $config);
    if (!is_array($summary)) {
        return ['status' => 'failed', 'summary' => null, 'result_class' => 'aggregate_invalid'];
    }

    return ['status' => 'valid', 'summary' => $summary, 'result_class' => null];
}

/** @param array<string,mixed> $receipt */
function emitReceipt(array $receipt, int $exitCode): never
{
    echo json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit($exitCode);
}

/** @return array<string,mixed> */
function failureReceipt(string $expectation, string $resultClass): array
{
    return [
        'schema' => CSP_STATUS_SCHEMA,
        'expectation' => $expectation,
        'status' => 'failed',
        'result_class' => $resultClass,
        'release_binding' => null,
        'activation' => ['status' => 'unknown', 'sha256' => null],
        'aggregate' => ['status' => 'not_checked', 'summary' => null],
    ];
}

function runStatusCli(array $argv): never
{
    try {
        $options = parseOptions($argv);
    } catch (Throwable) {
        emitReceipt(failureReceipt('unknown', 'input_invalid'), 2);
    }

    try {
        $release = CspReportOnlyProductionContext::releaseBinding($options['release_root']);
        if ($release === null) {
            emitReceipt(failureReceipt($options['expect'], 'release_identity_unavailable'), 1);
        }
        $activation = inspectActivation($options['config_path']);
        $aggregate = inspectAggregate($options['aggregate_path'], $activation['config']);

        $resultClass = $activation['result_class'] ?? $aggregate['result_class'];
        if ($resultClass === null) {
            if ($options['expect'] === 'active' && $activation['status'] !== 'active') {
                $resultClass = 'activation_missing';
            } elseif ($options['expect'] === 'inactive' && $activation['status'] !== 'inactive') {
                $resultClass = 'activation_unexpected';
            }
        }
        $passed = $resultClass === null && in_array($aggregate['status'], ['missing', 'valid'], true);

        emitReceipt(
            [
                'schema' => CSP_STATUS_SCHEMA,
                'expectation' => $options['expect'],
                'status' => $passed ? 'passed' : 'failed',
                'result_class' => $passed ? 'state_verified' : $resultClass ?? 'aggregate_unavailable',
                'release_binding' => $release['binding'],
                'activation' => ['status' => $activation['status'], 'sha256' => $activation['sha256']],
                'aggregate' => ['status' => $aggregate['status'], 'summary' => $aggregate['summary']],
            ],
            $passed ? 0 : 1,
        );
    } catch (Throwable) {
        emitReceipt(failureReceipt($options['expect'], 'internal_error'), 2);
    }
}

if (!defined('CSP_STATUS_LOAD_ONLY')) {
    runStatusCli($argv);
}
